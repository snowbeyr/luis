<?php
// Centralized functions for Transaction Correction Management
// R. Taipan Hardware POS & Inventory Management System

if (!function_exists('ensureCorrectionPaymentColumns')) {
    /**
     * Ensures required payment correction columns exist in PostgreSQL/Supabase
     */
    function ensureCorrectionPaymentColumns($pdo) {
        static $checked = false;
        if ($checked) return;
        try {
            $pdo->exec("
                ALTER TABLE pos_correction_requests 
                ADD COLUMN IF NOT EXISTS original_amount_paid NUMERIC(12,2) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS proposed_amount_paid NUMERIC(12,2) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS actual_amount_received NUMERIC(12,2) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS original_payment_method VARCHAR(50) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS proposed_payment_method VARCHAR(50) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS original_reference_number VARCHAR(100) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS proposed_reference_number VARCHAR(100) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS payment_correction_balance NUMERIC(12,2) DEFAULT 0,
                ADD COLUMN IF NOT EXISTS payment_status VARCHAR(50) DEFAULT NULL;

                ALTER TABLE pos_transaction 
                ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) DEFAULT 'Cash',
                ADD COLUMN IF NOT EXISTS reference_number VARCHAR(100) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS actual_amount_received NUMERIC(12,2) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS outstanding_balance NUMERIC(12,2) DEFAULT 0,
                ADD COLUMN IF NOT EXISTS payment_status VARCHAR(50) DEFAULT 'Fully Paid';
            ");
            $checked = true;
        } catch (Exception $e) {
            error_log("ensureCorrectionPaymentColumns note: " . $e->getMessage());
        }
    }
}

if (isset($pdo) && $pdo instanceof PDO) {
    ensureCorrectionPaymentColumns($pdo);
}

if (!function_exists('validateCorrectionStock')) {
    /**
     * Checks if replacement items have sufficient inventory stock.
     * Takes into account stock that will be restored from the original items of the same product/variant.
     *
     * @param PDO $pdo
     * @param int $request_id
     * @return array [bool $is_valid, string $error_message, array $details]
     */
    function validateCorrectionStock($pdo, $request_id) {
        // 1. Fetch original items
        $stmt_orig = $pdo->prepare("SELECT * FROM pos_correction_items WHERE request_id = ? AND item_type = 'original'");
        $stmt_orig->execute([$request_id]);
        $orig_items = $stmt_orig->fetchAll(PDO::FETCH_ASSOC);

        // 2. Fetch corrected items
        $stmt_corr = $pdo->prepare("SELECT * FROM pos_correction_items WHERE request_id = ? AND item_type = 'corrected'");
        $stmt_corr->execute([$request_id]);
        $corr_items = $stmt_corr->fetchAll(PDO::FETCH_ASSOC);

        if (empty($corr_items)) {
            return [false, "No replacement items found for correction request #$request_id.", []];
        }

        // Map original item quantities by key: "p_{id}" or "v_{id}"
        $restore_map = [];
        foreach ($orig_items as $item) {
            $key = !empty($item['variant_id']) ? 'v_' . $item['variant_id'] : 'p_' . $item['product_id'];
            $restore_map[$key] = ($restore_map[$key] ?? 0) + (int)$item['qty'];
        }

        // Map corrected item quantities
        $deduct_map = [];
        $item_info = [];
        foreach ($corr_items as $item) {
            $key = !empty($item['variant_id']) ? 'v_' . $item['variant_id'] : 'p_' . $item['product_id'];
            $deduct_map[$key] = ($deduct_map[$key] ?? 0) + (int)$item['qty'];
            $item_info[$key] = $item;
        }

        $details = [];

        // Check availability for each item to be deducted
        foreach ($deduct_map as $key => $needed_qty) {
            $restored_qty = $restore_map[$key] ?? 0;
            $net_needed = $needed_qty - $restored_qty;
            $info = $item_info[$key];

            $current_stock = 0;
            $product_name = $info['product_name'];

            if (!empty($info['variant_id'])) {
                $stmt = $pdo->prepare("SELECT stock, variant_name FROM product_variants WHERE id = ?");
                $stmt->execute([$info['variant_id']]);
                $var_row = $stmt->fetch(PDO::FETCH_ASSOC);
                $current_stock = (int)($var_row['stock'] ?? 0);
            } else {
                $stmt = $pdo->prepare("SELECT stock, product_name FROM products WHERE id = ?");
                $stmt->execute([$info['product_id']]);
                $prod_row = $stmt->fetch(PDO::FETCH_ASSOC);
                $current_stock = (int)($prod_row['stock'] ?? 0);
            }

            $details[] = [
                'item_key' => $key,
                'name' => $product_name,
                'current_stock' => $current_stock,
                'needed_qty' => $needed_qty,
                'restored_qty' => $restored_qty,
                'net_needed' => $net_needed,
                'effective_available' => $current_stock + $restored_qty
            ];

            if ($net_needed > 0 && $current_stock < $net_needed) {
                return [
                    false,
                    "Insufficient stock for \"$product_name\". Current stock is $current_stock, but correction requires a net deduction of $net_needed.",
                    $details
                ];
            }
        }

        return [true, "All replacement items have sufficient stock.", $details];
    }
}

if (!function_exists('executeCorrectionSettlement')) {
    /**
     * Executes atomic stock synchronization, updates pos_transaction and pos_transaction_items,
     * settles payment, marks correction as Completed, and writes audit log.
     *
     * @param PDO $pdo
     * @param int $request_id
     * @param string $settled_by
     * @param string|null $payment_method
     * @param string|null $payment_reference
     * @param float|null $amount_settled
     * @return array [bool $success, string $message]
     */
    function executeCorrectionSettlement($pdo, $request_id, $settled_by, $payment_method = null, $payment_reference = null, $amount_settled = null) {
        try {
            $stmt_req = $pdo->prepare("SELECT * FROM pos_correction_requests WHERE id = ? FOR UPDATE");
            $stmt_req->execute([$request_id]);
            $req = $stmt_req->fetch(PDO::FETCH_ASSOC);

            if (!$req) {
                return [false, "Correction request #$request_id not found."];
            }

            if ($req['status'] === 'Completed') {
                return [false, "Correction request #$request_id is already completed."];
            }

            $trans_id = (int)$req['transaction_id'];

            // 1. Validate Stock
            list($is_valid, $stock_msg, $details) = validateCorrectionStock($pdo, $request_id);
            if (!$is_valid) {
                return [false, $stock_msg];
            }

            // Begin atomic transaction
            $own_transaction = !$pdo->inTransaction();
            if ($own_transaction) {
                $pdo->beginTransaction();
            }

            // 2. Fetch original items and restore stock
            $stmt_orig = $pdo->prepare("SELECT * FROM pos_correction_items WHERE request_id = ? AND item_type = 'original'");
            $stmt_orig->execute([$request_id]);
            $orig_items = $stmt_orig->fetchAll(PDO::FETCH_ASSOC);

            foreach ($orig_items as $item) {
                $qty = (int)$item['qty'];
                if (!empty($item['variant_id'])) {
                    $upd = $pdo->prepare("UPDATE product_variants SET stock = stock + ? WHERE id = ?");
                    $upd->execute([$qty, $item['variant_id']]);
                } elseif (!empty($item['product_id'])) {
                    $upd = $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                    $upd->execute([$qty, $item['product_id']]);
                }
            }

            // 3. Fetch corrected items and deduct stock
            $stmt_corr = $pdo->prepare("SELECT * FROM pos_correction_items WHERE request_id = ? AND item_type = 'corrected'");
            $stmt_corr->execute([$request_id]);
            $corr_items = $stmt_corr->fetchAll(PDO::FETCH_ASSOC);

            foreach ($corr_items as $item) {
                $qty = (int)$item['qty'];
                if (!empty($item['variant_id'])) {
                    $upd = $pdo->prepare("UPDATE product_variants SET stock = stock - ? WHERE id = ?");
                    $upd->execute([$qty, $item['variant_id']]);
                } elseif (!empty($item['product_id'])) {
                    $upd = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
                    $upd->execute([$qty, $item['product_id']]);
                }
            }

            // 4. Update pos_transaction_items to reflect the corrected items
            $del_items = $pdo->prepare("DELETE FROM pos_transaction_items WHERE transaction_id = ?");
            $del_items->execute([$trans_id]);

            $ins_item = $pdo->prepare("
                INSERT INTO pos_transaction_items (transaction_id, product_id, variant_id, product_name, price, qty, subtotal)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($corr_items as $c) {
                $prod_id = !empty($c['product_id']) ? (int)$c['product_id'] : null;
                $var_id = !empty($c['variant_id']) ? (int)$c['variant_id'] : null;

                if ($var_id && !$prod_id) {
                    $stmt_find_p = $pdo->prepare("SELECT product_id FROM product_variants WHERE id = ?");
                    $stmt_find_p->execute([$var_id]);
                    $found_p = $stmt_find_p->fetchColumn();
                    if ($found_p) $prod_id = (int)$found_p;
                }

                $ins_item->execute([
                    $trans_id,
                    $prod_id,
                    $var_id,
                    $c['product_name'],
                    $c['price'],
                    $c['qty'],
                    $c['subtotal']
                ]);
            }

            // 5. Update pos_transaction total_amount, payment details, and correction flags
            $prop_total = (float)$req['proposed_total'];
            $prop_paid = (isset($req['proposed_amount_paid']) && $req['proposed_amount_paid'] !== null) 
                ? (float)$req['proposed_amount_paid'] 
                : null;
            $actual_rec = (isset($req['actual_amount_received']) && $req['actual_amount_received'] !== null) 
                ? (float)$req['actual_amount_received'] 
                : null;
            $prop_method = (!empty($payment_method) && $payment_method !== 'None (Even Exchange)' && $payment_method !== 'N/A')
                ? $payment_method
                : (!empty($req['proposed_payment_method']) ? $req['proposed_payment_method'] : null);
            $prop_ref = (!empty($payment_reference) && $payment_reference !== 'N/A')
                ? $payment_reference 
                : (!empty($req['proposed_reference_number']) ? $req['proposed_reference_number'] : null);
            
            // If payment method is Cash, clear reference / sender number
            if ($prop_method === 'Cash') {
                $prop_ref = null;
            }

            $balance = isset($req['payment_correction_balance']) ? (float)$req['payment_correction_balance'] : 0;
            $status_val = !empty($req['payment_status']) ? $req['payment_status'] : 'Fully Paid';

            $upd_trans = $pdo->prepare("
                UPDATE pos_transaction 
                SET total_amount = ?, 
                    amount_paid = COALESCE(?, amount_paid),
                    actual_amount_received = COALESCE(?, actual_amount_received, amount_paid),
                    payment_method = COALESCE(?, payment_method, 'Cash'),
                    reference_number = ?,
                    outstanding_balance = ?,
                    payment_status = ?,
                    correction_status = 'corrected', 
                    is_corrected = TRUE
                WHERE id = ?
            ");
            $upd_trans->execute([
                $prop_total,
                $prop_paid,
                $actual_rec,
                $prop_method,
                $prop_ref,
                $balance,
                $status_val,
                $trans_id
            ]);

            // 6. Mark correction request as Completed
            $settle_val = $amount_settled !== null ? (float)$amount_settled : (float)$req['payment_difference'];
            $method_val = $prop_method ?? $req['payment_method'] ?? 'N/A';
            $ref_val = $prop_ref ?? $req['payment_reference'] ?? '';

            $upd_req = $pdo->prepare("
                UPDATE pos_correction_requests
                SET status = 'Completed',
                    payment_settled = TRUE,
                    completed_at = CURRENT_TIMESTAMP,
                    settled_at = CURRENT_TIMESTAMP,
                    settled_by = ?,
                    payment_method = ?,
                    payment_reference = ?,
                    amount_settled = ?
                WHERE id = ?
            ");
            $upd_req->execute([$settled_by, $method_val, $ref_val, $settle_val, $request_id]);

            // 7. Record in audit_log
            $user_id = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            if ($user_id <= 0) {
                $stmt_u = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $stmt_u->execute([$settled_by]);
                $user_id = (int)$stmt_u->fetchColumn();
                if ($user_id <= 0) {
                    $user_id = (int)$pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;
                }
            }
            $role = $_SESSION['role'] ?? 'system';
            $action = "POS Correction Completed";
            $diff_str = $req['payment_difference'] > 0 ? "+₱" . number_format($req['payment_difference'], 2) : "-₱" . number_format(abs($req['payment_difference']), 2);
            $pay_info_note = "";
            if ($req['correction_type'] === 'Incorrect Payment Information') {
                $pay_info_note = " [Payment Info Adjusted: Recorded Paid ₱" . number_format((float)($req['original_amount_paid'] ?? 0), 2) . " -> ₱" . number_format((float)($req['proposed_amount_paid'] ?? 0), 2) . ", Actual Rec'd: ₱" . number_format((float)($req['actual_amount_received'] ?? 0), 2) . " (" . ($req['payment_status'] ?? 'Fully Paid') . "), Method: " . ($prop_method ?? 'Cash') . ($prop_ref ? " (Sender: $prop_ref)" : "") . "]";
            }
            $details_log = "Completed Transaction Correction #$request_id for POS Receipt #$trans_id. Original: ₱" . number_format($req['original_total'], 2) . ", Corrected: ₱" . number_format($req['proposed_total'], 2) . " (Diff: $diff_str).{$pay_info_note} Stock synchronized and payment settled by $settled_by.";
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'N/A';

            $audit_stmt = $pdo->prepare("INSERT INTO audit_log (user_id, username, role, action, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
            $audit_stmt->execute([$user_id, $settled_by, $role, $action, $details_log, $ip_address]);

            if ($own_transaction) {
                $pdo->commit();
            }

            return [true, "Correction request #$request_id successfully completed and inventory synchronized!"];
        } catch (Exception $e) {
            if (isset($own_transaction) && $own_transaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("executeCorrectionSettlement error: " . $e->getMessage());
            return [false, "Execution failed: " . $e->getMessage()];
        }
    }
}
