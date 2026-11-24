<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/correction_functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cashier') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$cashier_name = $_SESSION['username'] ?? 'Cashier';
$cashier_id = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
$transaction_id = (int)($_POST['transaction_id'] ?? 0);
$correction_type = trim($_POST['correction_type'] ?? '');
$reason = trim($_POST['reason'] ?? '');
$other_details = trim($_POST['other_details'] ?? '');
$original_items_json = $_POST['original_items'] ?? '';
$corrected_items_json = $_POST['corrected_items'] ?? '';

if ($transaction_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid transaction selected.']);
    exit();
}

// 1. Validate Correction Type - ONLY four allowed choices
$allowed_types = [
    'Wrong Product Input',
    'Incorrect Quantity',
    'Incorrect Payment Information',
    'Others'
];

if (!in_array($correction_type, $allowed_types, true)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid correction type. Allowed options are: Wrong Product Input, Incorrect Quantity, Incorrect Payment Information, or Others.'
    ]);
    exit();
}

$proposed_amount_paid = isset($_POST['proposed_amount_paid']) && $_POST['proposed_amount_paid'] !== '' ? (float)$_POST['proposed_amount_paid'] : null;
$actual_amount_received = isset($_POST['actual_amount_received']) && $_POST['actual_amount_received'] !== '' ? (float)$_POST['actual_amount_received'] : null;
$proposed_payment_method = trim($_POST['proposed_payment_method'] ?? 'Cash');
$proposed_reference_number = trim($_POST['proposed_reference_number'] ?? '');

// Backend Enforcement: POS / Walk-in transactions strictly accept CASH ONLY
if ($proposed_payment_method !== '' && strcasecmp($proposed_payment_method, 'Cash') !== 0) {
    echo json_encode([
        'status' => 'error', 
        'message' => 'POS / Walk-in transactions strictly accept CASH ONLY. Electronic and online payment methods (such as GCash or Maya) are not allowed.'
    ]);
    exit();
}
$proposed_payment_method = 'Cash';
$proposed_reference_number = null;

if ($correction_type === 'Incorrect Payment Information') {
    if ($proposed_amount_paid === null || $proposed_amount_paid < 0) {
        echo json_encode(['status' => 'error', 'message' => 'Please provide a valid proposed paid amount.']);
        exit();
    }
    if ($actual_amount_received === null || $actual_amount_received < 0) {
        echo json_encode(['status' => 'error', 'message' => 'Please provide the actual amount received from the customer.']);
        exit();
    }
}

// 2. Validate Reason
if (empty($reason)) {
    echo json_encode(['status' => 'error', 'message' => 'Please provide a clear reason / explanation for this correction request.']);
    exit();
}

// If "Others", require the specific details field
if ($correction_type === 'Others' && empty($other_details)) {
    echo json_encode(['status' => 'error', 'message' => 'Please provide specific details for the "Others" correction category.']);
    exit();
}

$orig_items = json_decode($original_items_json, true);
$corr_items = json_decode($corrected_items_json, true);

if (!is_array($orig_items) || empty($orig_items)) {
    echo json_encode(['status' => 'error', 'message' => 'Original transaction items are missing.']);
    exit();
}

if (!is_array($corr_items) || empty($corr_items)) {
    echo json_encode(['status' => 'error', 'message' => 'Please specify at least one item in the requested corrected details.']);
    exit();
}

try {
    // 3. Fetch Transaction and Check 24-Hour Deadline
    $stmt_tx = $pdo->prepare("SELECT * FROM pos_transaction WHERE id = ?");
    $stmt_tx->execute([$transaction_id]);
    $transaction = $stmt_tx->fetch(PDO::FETCH_ASSOC);

    if (!$transaction) {
        echo json_encode(['status' => 'error', 'message' => 'Transaction not found.']);
        exit();
    }

    $tx_timestamp = strtotime($transaction['transaction_date']);
    $deadline_timestamp = $tx_timestamp + (24 * 3600);
    $now = time();
    $is_past_deadline = ($now > $deadline_timestamp);
    $deadline_datetime_str = date('Y-m-d H:i:s', $deadline_timestamp);

    // Enforce 24-Hour Rule Server-Side strictly
    if ($is_past_deadline) {
        echo json_encode([
            'status' => 'error',
            'code' => 'DEADLINE_EXPIRED',
            'message' => 'The 24-hour correction request period has expired. Corrections are strictly no longer permitted for this transaction.'
        ]);
        exit();
    }

    $is_late = false;
    $late_reason = null;

    // 4. Lock check: Disallow duplicate requests while one is pending review or settlement
    $stmt_lock = $pdo->prepare("
        SELECT id, status, is_late_request FROM pos_correction_requests 
        WHERE transaction_id = ? 
          AND status IN ('Pending Review', 'Approved', 'Pending Additional Payment', 'Pending Refund')
        LIMIT 1
    ");
    $stmt_lock->execute([$transaction_id]);
    $existing_pending = $stmt_lock->fetch(PDO::FETCH_ASSOC);

    if ($existing_pending) {
        $type_label = !empty($existing_pending['is_late_request']) ? 'Late Correction' : 'Correction';
        echo json_encode([
            'status' => 'error',
            'message' => "Transaction #$transaction_id already has an active $type_label Request (#{$existing_pending['id']} - {$existing_pending['status']}). Multiple concurrent requests are locked."
        ]);
        exit();
    }

    // 5. Handle File Upload (Supporting Evidence)
    $evidence_path = null;
    if (isset($_FILES['evidence']) && $_FILES['evidence']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['evidence']['tmp_name'];
        $file_name = $_FILES['evidence']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];

        if (in_array($file_ext, $allowed)) {
            $upload_dir = __DIR__ . '/../uploads/corrections/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $new_file_name = 'corr_' . $transaction_id . '_' . time() . '_' . uniqid() . '.' . $file_ext;
            $dest_path = $upload_dir . $new_file_name;
            if (move_uploaded_file($file_tmp, $dest_path)) {
                $evidence_path = 'uploads/corrections/' . $new_file_name;
            }
        }
    }

    // 6. Calculate Totals and Financial Difference
    $orig_total = 0;
    foreach ($orig_items as $item) {
        $p = (float)($item['price'] ?? 0);
        $q = (int)($item['qty'] ?? 1);
        $orig_total += ($p * $q);
    }
    $orig_total = round($orig_total, 2);

    $prop_total = 0;
    foreach ($corr_items as $item) {
        $p = (float)($item['price'] ?? 0);
        $q = (int)($item['qty'] ?? 1);
        $prop_total += ($p * $q);
    }
    $prop_total = round($prop_total, 2);

    // Payment fields and cash tendered separation
    $orig_amount_paid = (float)($transaction['amount_paid'] ?? $transaction['total_amount'] ?? 0);
    $orig_payment_method = $transaction['payment_method'] ?? 'Cash';
    $orig_reference_number = $transaction['reference_number'] ?? '';
    $orig_change = isset($transaction['change_amount']) && $transaction['change_amount'] !== null
        ? (float)$transaction['change_amount']
        : max(0, round($orig_amount_paid - $orig_total, 2));

    if ($correction_type === 'Incorrect Payment Information') {
        // Discrepancy calculations:
        // Cash Tendered Difference = Correct Cash Tendered - Original Cash Tendered
        $cash_discrepancy = round($proposed_amount_paid - $orig_amount_paid, 2);
        $prop_change = max(0, round($proposed_amount_paid - $prop_total, 2));
        $change_discrepancy = round($prop_change - $orig_change, 2);

        // Financial difference for payment correction is the cash discrepancy
        $payment_diff = $cash_discrepancy;

        $settlement_type = 'none';
        if ($payment_diff > 0) {
            $settlement_type = 'payment_adjustment';
        } elseif ($payment_diff < 0) {
            $settlement_type = 'refund';
        }

        $payment_correction_balance = 0;
        $payment_status = 'Fully Paid';
        if ($actual_amount_received !== null) {
            if ($actual_amount_received < $prop_total) {
                $payment_correction_balance = round($prop_total - $actual_amount_received, 2);
                $payment_status = 'Partially Paid';
            } elseif ($actual_amount_received > $prop_total) {
                $payment_status = 'Overpaid';
            } else {
                $payment_status = 'Fully Paid';
            }
        }

        $orig_cash_tendered = $orig_amount_paid;
        $prop_cash_tendered = $proposed_amount_paid;
        $orig_change_amount = $orig_change;
        $prop_change_amount = $prop_change;
        $net_payment_applied = min($prop_total, $proposed_amount_paid);
        $eligible_exchange_credit = $prop_total;
    } else {
        $payment_diff = round($prop_total - $orig_total, 2);
        $settlement_type = 'none';
        if ($payment_diff > 0) {
            $settlement_type = 'additional_payment';
        } elseif ($payment_diff < 0) {
            $settlement_type = 'refund';
        }

        $proposed_amount_paid = $orig_amount_paid;
        $actual_amount_received = (float)($transaction['actual_amount_received'] ?? $orig_amount_paid);
        $proposed_payment_method = $orig_payment_method;
        $proposed_reference_number = $orig_reference_number;
        $payment_correction_balance = (float)($transaction['outstanding_balance'] ?? 0);
        $payment_status = $transaction['payment_status'] ?? 'Fully Paid';

        $orig_cash_tendered = $orig_amount_paid;
        $prop_cash_tendered = $orig_amount_paid;
        $orig_change_amount = $orig_change;
        $prop_change_amount = $orig_change;
        $cash_discrepancy = 0;
        $net_payment_applied = min($prop_total, $orig_amount_paid);
        $eligible_exchange_credit = $prop_total;
    }

    $pdo->beginTransaction();

    // 7. Insert into pos_correction_requests
    $stmt_req = $pdo->prepare("
        INSERT INTO pos_correction_requests (
            transaction_id, cashier_name, cashier_id, correction_type, reason,
            other_details, is_late_request, correction_deadline, late_reason,
            evidence_path, original_total, proposed_total, payment_difference,
            status, settlement_type,
            original_amount_paid, proposed_amount_paid, actual_amount_received,
            original_payment_method, proposed_payment_method,
            original_reference_number, proposed_reference_number,
            payment_correction_balance, payment_status,
            original_cash_tendered, proposed_cash_tendered,
            original_change_amount, proposed_change_amount,
            cash_discrepancy, net_payment_applied, eligible_exchange_credit,
            created_at
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            'Pending Review', ?,
            ?, ?, ?,
            ?, ?,
            ?, ?,
            ?, ?,
            ?, ?,
            ?, ?,
            ?, ?, ?,
            CURRENT_TIMESTAMP
        )
    ");
    $stmt_req->execute([
        $transaction_id,
        $cashier_name,
        $cashier_id > 0 ? $cashier_id : null,
        $correction_type,
        $reason,
        !empty($other_details) ? $other_details : null,
        $is_late ? 1 : 0,
        $deadline_datetime_str,
        $late_reason,
        $evidence_path,
        $orig_total,
        $prop_total,
        $payment_diff,
        $settlement_type,
        $orig_amount_paid,
        $proposed_amount_paid,
        $actual_amount_received,
        $orig_payment_method,
        $proposed_payment_method,
        $orig_reference_number,
        $proposed_reference_number,
        $payment_correction_balance,
        $payment_status,
        $orig_cash_tendered,
        $prop_cash_tendered,
        $orig_change_amount,
        $prop_change_amount,
        $cash_discrepancy,
        $net_payment_applied,
        $eligible_exchange_credit
    ]);
    $request_id = $pdo->lastInsertId();

    // 8. Insert items into pos_correction_items
    $stmt_item = $pdo->prepare("
        INSERT INTO pos_correction_items (
            request_id, item_type, product_id, variant_id, product_name, price, qty, subtotal
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    // Insert original items
    foreach ($orig_items as $o) {
        $p = (float)($o['price'] ?? 0);
        $q = (int)($o['qty'] ?? 1);
        $sub = $p * $q;
        $prod_id = !empty($o['product_id']) ? (int)$o['product_id'] : null;
        $var_id = !empty($o['variant_id']) ? (int)$o['variant_id'] : null;
        $stmt_item->execute([
            $request_id, 'original', $prod_id, $var_id, $o['product_name'] ?? 'Item', $p, $q, $sub
        ]);
    }

    // Insert corrected items
    foreach ($corr_items as $c) {
        $p = (float)($c['price'] ?? 0);
        $q = (int)($c['qty'] ?? 1);
        $sub = $p * $q;
        $prod_id = !empty($c['product_id']) ? (int)$c['product_id'] : null;
        $var_id = !empty($c['variant_id']) ? (int)$c['variant_id'] : null;
        $stmt_item->execute([
            $request_id, 'corrected', $prod_id, $var_id, $c['product_name'] ?? 'Item', $p, $q, $sub
        ]);
    }

    // 9. Update transaction status
    $stmt_lock_tx = $pdo->prepare("UPDATE pos_transaction SET correction_status = 'pending' WHERE id = ?");
    $stmt_lock_tx->execute([$transaction_id]);

    // 10. Audit Log
    $user_audit_id = $cashier_id;
    if ($user_audit_id <= 0) {
        $stmt_u = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt_u->execute([$cashier_name]);
        $user_audit_id = (int)$stmt_u->fetchColumn();
        if ($user_audit_id <= 0) {
            $user_audit_id = (int)$pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;
        }
    }

    $action = "POS Correction Submitted";
    $diff_fmt = $payment_diff > 0 ? "+₱" . number_format($payment_diff, 2) : "-₱" . number_format(abs($payment_diff), 2);
    $payment_note = ($correction_type === 'Incorrect Payment Information') 
        ? " [Payment Info: Paid ₱" . number_format($orig_amount_paid, 2) . " -> ₱" . number_format($proposed_amount_paid, 2) . ", Actual Rec'd: ₱" . number_format($actual_amount_received, 2) . " ($payment_status), Method: $proposed_payment_method" . (!empty($proposed_reference_number) ? " (Sender: $proposed_reference_number)" : "") . "]"
        : "";
    $details = "Cashier $cashier_name submitted {$action} #$request_id for POS Transaction #$transaction_id. Type: $correction_type. Proposed Total: ₱" . number_format($prop_total, 2) . " (Diff: $diff_fmt).{$payment_note} Reason: $reason";
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'N/A';

    $stmt_audit = $pdo->prepare("INSERT INTO audit_log (user_id, username, role, action, details, ip_address) VALUES (?, ?, 'cashier', ?, ?, ?)");
    $stmt_audit->execute([$user_audit_id, $cashier_name, $action, $details, $ip]);

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'Correction request submitted successfully! Your request has been queued for Owner review.',
        'request_id' => $request_id
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => 'Submission failed: ' . $e->getMessage()]);
}
