<?php
session_start();

// Enable error handling, standard JSON output
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Safe include database config ($pdo) & Google Drive helper
if (file_exists(__DIR__ . '/../config/db.php')) {
    require_once __DIR__ . '/../config/db.php';
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Config db.php file missing.']);
    exit;
}

if (file_exists(__DIR__ . '/../config/google_drive.php')) {
    require_once __DIR__ . '/../config/google_drive.php';
}

if (file_exists(__DIR__ . '/../config/email.php')) {
    require_once __DIR__ . '/../config/email.php';
}

require_once __DIR__ . '/../includes/inventory_helpers.php';
ensureCancellationSchema($pdo);

$message = "";
$message_type = "";

// Pre-fill parameters when arriving directly from My Purchases
$prefill_order_id = !empty($_GET['order_id']) ? trim($_GET['order_id']) : '';
$prefill_type = !empty($_GET['type']) ? strtolower(trim($_GET['type'])) : '';
$prefill_email = !empty($_GET['email']) ? trim($_GET['email']) : (!empty($_SESSION['email']) ? trim($_SESSION['email']) : '');

if (empty($prefill_email) && !empty($_SESSION['user_id'])) {
    try {
        $u_stmt = $pdo->prepare("SELECT email FROM users WHERE id = :uid LIMIT 1");
        $u_stmt->execute([':uid' => (int) $_SESSION['user_id']]);
        $fetched_email = $u_stmt->fetchColumn();
        if (!empty($fetched_email)) {
            $prefill_email = trim((string) $fetched_email);
        }
    } catch (Exception $e) {}
}

$isDirectMode = (!empty($prefill_order_id) && !empty($prefill_email));

// Helper: Normalize item name without duplicates (e.g. avoid "Gravel (1 Elf) (Gravel (1 Elf))")
if (!function_exists('resolveCleanItemName')) {
    function resolveCleanItemName(?string $rawName, ?string $rawVariant, ?string $mainName, ?int $vId = null): string {
        $dedupe = function(string $s): string {
            $s = trim($s);
            while (preg_match('/^(.+?)\s*\(\s*\1\s*\)$/i', $s, $m)) {
                $s = trim($m[1]);
            }
            while (preg_match('/^(.{3,}?)\s+\1$/i', $s, $m)) {
                $s = trim($m[1]);
            }
            while (preg_match('/^(.+?\(([^)]+)\))\s*\(\s*\2\s*\)$/i', $s, $m)) {
                $s = trim($m[1]);
            }
            return $s;
        };

        $rawName = $dedupe((string)$rawName);
        $rawVariant = $dedupe((string)$rawVariant);
        $mainName = $dedupe((string)$mainName);
        $isVariant = (!empty($vId) || $rawVariant !== '');

        if (!$isVariant) {
            return $rawName !== '' ? $rawName : ($mainName !== '' ? $mainName : 'Hardware Item');
        }

        if ($rawVariant === '') {
            return $rawName !== '' ? $rawName : 'Hardware Item';
        }

        if ($rawName === '') {
            return $rawVariant;
        }

        if (strcasecmp($rawName, $rawVariant) === 0) {
            return $rawVariant;
        }

        if (stripos($rawName, $rawVariant) !== false) {
            return $rawName;
        }

        if (!empty($mainName) && stripos($rawVariant, $mainName) !== false) {
            return $rawVariant;
        }

        $cleanedRaw = preg_replace('/\s*\([^)]*\)$/', '', $rawName);
        if (stripos($rawVariant, $cleanedRaw) !== false) {
            return $rawVariant;
        }

        return $dedupe($rawName . ' (' . $rawVariant . ')');
    }
}

// ========================================================
// API ENDPOINT: LIVE FETCH OF ITEMS & ORDER INFO (PDO)
// ========================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_items') {
    if (ob_get_length()) {
        ob_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($pdo) || !$pdo) {
        echo json_encode(['success' => false, 'message' => 'Database PDO connection failed.']);
        exit;
    }

    try {
        $order_id = trim($_GET['order_id'] ?? '');
        $email = trim($_GET['email'] ?? '');

        if (empty($order_id) || empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Please enter both Order ID and Email.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :order_id AND email = :email LIMIT 1");
        $stmt->execute([
            ':order_id' => $order_id,
            ':email' => $email
        ]);
        
        $order_data = $stmt->fetch();

        // Fallback: If not in online orders, check pos_transaction for in-store purchases
        if (!$order_data) {
            $num_order_id = is_numeric($order_id) ? (int)$order_id : 0;
            $stmt_pos = $pdo->prepare("SELECT * FROM pos_transaction WHERE id = :num_id LIMIT 1");
            $stmt_pos->execute([':num_id' => $num_order_id]);
            $pos_row = $stmt_pos->fetch();
            if ($pos_row) {
                $order_data = [
                    'id' => $pos_row['id'],
                    'fullname' => $pos_row['customer_name'] ?? 'Walk-in Customer',
                    'surname' => '',
                    'order_date' => $pos_row['created_at'],
                    'status' => 'completed',
                    'payment_method' => $pos_row['payment_method'] ?? 'Cash',
                    'payment_scheme' => 'fullpayment',
                    'total_amount' => $pos_row['total_amount'],
                    'contact_number' => 'N/A',
                    'full_address' => 'In-Store Purchase',
                    'landmark' => '',
                    'ref_no' => $pos_row['reference_number'] ?? '',
                    'paid_amount' => $pos_row['amount_paid'] ?? $pos_row['total_amount'],
                    'is_pos' => true
                ];
            }
        }

        if ($order_data) {
            $cust_name = $order_data['fullname'] ?? ($order_data['customer_name'] ?? ($order_data['name'] ?? 'N/A'));
            $cust_surname = $order_data['surname'] ?? '';
            $ord_date = $order_data['order_date'] ?? ($order_data['created_at'] ?? 'N/A');
            $ord_status = $order_data['status'] ?? 'Pending';
            $pay_method = $order_data['payment_method'] ?? ($order_data['mode_of_payment'] ?? 'COD');
            $pay_scheme = $order_data['payment_scheme'] ?? 'fullpayment';
            $total_amount = $order_data['total_amount'] ?? 0;
            $contact_number = $order_data['contact_number'] ?? 'N/A';
            $full_address = $order_data['full_address'] ?? 'N/A';
            $landmark = $order_data['landmark'] ?? 'N/A';
            $ref_no = $order_data['ref_no'] ?? '';
            $paid_amount = $order_data['paid_amount'] ?? 0;

            // Check latest cancellation request status
            $is_cancel_completed = false;
            $latest_cancel = null;
            $stmt_cancel = $pdo->prepare("SELECT id, status, reason, admin_notes, cancellation_mode, auto_approved, processed_at, created_at FROM customer_requests WHERE order_number = :order_id AND LOWER(TRIM(request_type)) = 'cancel' ORDER BY id DESC LIMIT 1");
            $stmt_cancel->execute([':order_id' => $order_id]);
            $cancel_row = $stmt_cancel->fetch(PDO::FETCH_ASSOC);

            if ($cancel_row) {
                $latest_cancel = $cancel_row;
                if (in_array(strtolower(trim($cancel_row['status'] ?? '')), ['approved', 'completed'], true)) {
                    $is_cancel_completed = true;
                }
            }

            $norm_status = normalizeCancellationOrderStatus($ord_status);
            $can_cancel = in_array($norm_status, ['pending', 'approved', 'preparing'], true);
            $cancellation_mode = in_array($norm_status, ['pending', 'approved'], true)
                ? 'automatic'
                : ($norm_status === 'preparing' ? 'manual' : 'disallowed');

            // CHECK FOR EXISTING PENDING REQUEST (CASE-INSENSITIVE ANTI-SPAM CHECK)
            $has_pending_request = false;
            $pending_req_type = '';
            $stmt_pending = $pdo->prepare("SELECT request_type FROM customer_requests WHERE order_number = :order_id AND (LOWER(TRIM(status)) = 'pending' OR status IS NULL) ORDER BY id DESC LIMIT 1");
            $stmt_pending->execute([':order_id' => $order_id]);
            $pending_row = $stmt_pending->fetch();

            if ($pending_row) {
                $has_pending_request = true;
                $pending_req_type = strtoupper($pending_row['request_type']);
                $can_cancel = false;
            }

            // Parse items: Check pos_transaction_items or order_items
            $items = [];
            if (!empty($order_data['is_pos'])) {
                $pos_items_stmt = $pdo->prepare("SELECT * FROM pos_transaction_items WHERE transaction_id = ?");
                $pos_items_stmt->execute([$order_data['id']]);
                $db_pos_items = $pos_items_stmt->fetchAll();
                foreach ($db_pos_items as $pi) {
                    $p_price = floatval($pi['price'] ?? 0);
                    $p_qty = intval($pi['qty'] ?? 1);
                    $items[] = [
                        'product_name' => $pi['product_name'] ?? 'Item',
                        'price' => $p_price,
                        'qty' => $p_qty,
                        'subtotal' => floatval($pi['subtotal'] ?? ($p_price * $p_qty))
                    ];
                }
            } else {
                $oi_stmt = $pdo->prepare("
                    SELECT oi.*, 
                           p.product_name AS main_product_name,
                           pv.variant_name AS pv_variant_name
                    FROM order_items oi
                    LEFT JOIN products p ON oi.product_id = p.id
                    LEFT JOIN product_variants pv ON oi.variant_id = pv.id
                    WHERE oi.order_id = :order_id
                    ORDER BY oi.id ASC
                ");
                $oi_stmt->execute([':order_id' => $order_data['id']]);
                $db_order_items = $oi_stmt->fetchAll();

                if (!empty($db_order_items)) {
                    foreach ($db_order_items as $oi) {
                        $vId = !empty($oi['variant_id']) ? (int)$oi['variant_id'] : null;
                        $rawVName = trim((string)($oi['variant_name'] ?? ($oi['pv_variant_name'] ?? '')));
                        $cleanedName = resolveCleanItemName(
                            $oi['product_name'] ?? '',
                            $rawVName,
                            $oi['main_product_name'] ?? '',
                            $vId
                        );
                        $p_price = floatval($oi['price'] ?? 0);
                        $p_qty = intval($oi['qty'] ?? 1);
                        $items[] = [
                            'product_name' => $cleanedName,
                            'price' => $p_price,
                            'qty' => $p_qty,
                            'subtotal' => floatval($oi['subtotal'] ?? ($p_price * $p_qty))
                        ];
                    }
                } else {
                    // Fallback to cart JSON
                    $cart_raw = $order_data['cart'] ?? '';
                    $cart_obj = is_string($cart_raw) ? json_decode($cart_raw, true) : $cart_raw;
                    if (is_array($cart_obj)) {
                        foreach ($cart_obj as $cart_item) {
                            $vId = !empty($cart_item['variant_id']) ? (int)$cart_item['variant_id'] : null;
                            $rawVName = trim((string)($cart_item['variant_name'] ?? ''));
                            $cleanedName = resolveCleanItemName(
                                $cart_item['name'] ?? ($cart_item['product_name'] ?? 'Item'),
                                $rawVName,
                                '',
                                $vId
                            );
                            $p_price = floatval($cart_item['price'] ?? 0);
                            $p_qty = intval($cart_item['qty'] ?? 1);
                            $items[] = [
                                'product_name' => $cleanedName,
                                'price' => $p_price,
                                'qty' => $p_qty,
                                'subtotal' => $p_price * $p_qty
                            ];
                        }
                    }
                }
            }

            echo json_encode([
                'success' => true, 
                'order_info' => [
                    'id' => $order_data['id'],
                    'customer_name' => $cust_name,
                    'surname' => $cust_surname,
                    'order_date' => $ord_date,
                    'status' => $ord_status,
                    'payment_method' => $pay_method,
                    'payment_scheme' => $pay_scheme,
                    'total_amount' => $total_amount,
                    'contact_number' => $contact_number,
                    'full_address' => $full_address,
                    'landmark' => $landmark,
                    'ref_no' => $ref_no,
                    'paid_amount' => $paid_amount,
                    'normalized_status' => $norm_status,
                    'can_cancel' => $can_cancel,
                    'cancellation_mode' => $cancellation_mode,
                    'latest_cancel' => $latest_cancel,
                    'is_cancel_completed' => $is_cancel_completed,
                    'has_pending_request' => $has_pending_request,
                    'pending_req_type' => $pending_req_type
                ],
                'items' => $items
            ]);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'Order not found with matching email.']);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
        exit;
    }
}

// ========================================================
// POST HANDLER: SUBMISSION OF REQUESTS (PDO Version)
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    $order_id = trim($_POST['order_id'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $request_type = strtolower(trim($_POST['request_type'] ?? ''));
    $reason = trim($_POST['reason'] ?? '');
    $user_comments = trim($_POST['details'] ?? '');

    if ($order_id === '' || !ctype_digit($order_id) || $email === '') {
        $message = "❌ Invalid Order ID or Email address.";
        $message_type = "danger";
    } elseif (!in_array($request_type, ['cancel', 'exchange', 'return', 'others'], true)) {
        $message = "❌ Invalid request type selected.";
        $message_type = "danger";
    } elseif ($reason === '') {
        $message = "❌ Please select a reason for your request.";
        $message_type = "danger";
    } else {
        $order_id_int = (int)$order_id;
        $filename = null;

        // Upload optional proof photo before entering the row-locked DB transaction
        if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['product_image']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION));
            $new_filename = "REQ_" . $order_id_int . "_" . time() . "_" . rand(1000, 9999) . "." . $ext;
            $CUSTOMER_SERVICE_FOLDER_ID = "1Gu8SOPjnIDEwoMhRFhZkxHqPsUL7MKNS";

            try {
                if (function_exists('uploadProductImageToDrive')) {
                    $file_id = uploadProductImageToDrive($file_tmp, $new_filename, $CUSTOMER_SERVICE_FOLDER_ID);
                    if (!empty($file_id)) {
                        $filename = "https://drive.google.com/uc?export=view&id=" . $file_id;
                    }
                }
            } catch (Throwable $e) {
                error_log("CUSTOMER SERVICE GOOGLE DRIVE UPLOAD ERROR: " . $e->getMessage());
            }

            if (empty($filename)) {
                $target_dir = "../uploads/";
                if (!is_dir($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }
                if (move_uploaded_file($file_tmp, $target_dir . $new_filename)) {
                    $filename = $new_filename;
                }
            }
        }

        try {
            $pdo->beginTransaction();

            // Lock the order row to avoid concurrent status transitions / duplicate cancel submissions
            $stmt_ver = $pdo->prepare("SELECT * FROM orders WHERE id = :order_id AND LOWER(TRIM(email)) = LOWER(:email) LIMIT 1 FOR UPDATE");
            $stmt_ver->execute([':order_id' => $order_id_int, ':email' => $email]);
            $v_data = $stmt_ver->fetch(PDO::FETCH_ASSOC);

            if (!$v_data) {
                $pdo->rollBack();
                $message = "❌ Verification error. Order not found for that email.";
                $message_type = "danger";
            } else {
                $v_status = normalizeCancellationOrderStatus($v_data['status'] ?? '');
                $v_ord_date_str = $v_data['order_date'] ?? ($v_data['created_at'] ?? 'now');

                // Backend Anti-Spam Check: Lock & check active Pending requests (and active cancel requests)
                $stmt_check_pending = $pdo->prepare("
                    SELECT request_type, status
                    FROM customer_requests
                    WHERE order_number = :order_id
                      AND (
                            LOWER(TRIM(status)) = 'pending'
                         OR status IS NULL
                         OR (LOWER(TRIM(request_type)) = 'cancel' AND LOWER(TRIM(status)) IN ('approved', 'completed'))
                      )
                    LIMIT 1
                    FOR UPDATE
                ");
                $stmt_check_pending->execute([':order_id' => (string)$order_id_int]);
                $existing_pending = $stmt_check_pending->fetch(PDO::FETCH_ASSOC);

                if ($existing_pending) {
                    $pdo->rollBack();
                    $exType = strtoupper($existing_pending['request_type'] ?? 'REQUEST');
                    $exStat = strtoupper($existing_pending['status'] ?? 'PENDING');
                    $message = "❌ Submission Blocked: Order #{$order_id_int} already has an active {$exType} request (Status: {$exStat}). Duplicate requests are not allowed.";
                    $message_type = "danger";
                } elseif ($v_status === 'cancelled' || $v_status === 'declined') {
                    $pdo->rollBack();
                    $message = "❌ Cancellation is no longer available. Order #{$order_id_int} has already been " . strtoupper($v_status) . ".";
                    $message_type = "danger";
                } elseif ($request_type === 'cancel' && $v_status === 'delivered') {
                    $pdo->rollBack();
                    $message = "❌ Cancellation is no longer available because Order #{$order_id_int} has already been DELIVERED. For delivery problems or wrong/damaged items, please confirm receipt and use the Return / Exchange or delivery dispute process.";
                    $message_type = "danger";
                } elseif ($request_type === 'cancel' && $v_status === 'out_for_delivery') {
                    $pdo->rollBack();
                    $message = "❌ Cancellation is no longer available because Order #{$order_id_int} is already OUT FOR DELIVERY.";
                    $message_type = "danger";
                } elseif ($request_type === 'cancel' && $v_status === 'completed') {
                    $pdo->rollBack();
                    $message = "❌ Cancellation is no longer available because Order #{$order_id_int} has already been COMPLETED. You may submit a Return / Exchange request within 24 hours if eligible.";
                    $message_type = "danger";
                } elseif ($request_type === 'exchange' && $v_status !== 'completed') {
                    $pdo->rollBack();
                    $message = "❌ Cannot request exchange. Item Exchange is only allowed for COMPLETED orders.";
                    $message_type = "danger";
                } elseif ($request_type === 'exchange' && ((time() - strtotime($v_ord_date_str)) > 86400)) {
                    $pdo->rollBack();
                    $message = "❌ Item Exchange requests are only allowed within 24 hours (1 day) after order completion/date.";
                    $message_type = "danger";
                } elseif ($request_type !== 'cancel' && in_array($v_status, ['out_for_delivery', 'delivered'], true)) {
                    $pdo->rollBack();
                    $message = "❌ Submitting requests for this order status is locked.";
                    $message_type = "danger";
                } elseif ($request_type === 'cancel') {
                    // Determine Customer ID if signed in or registered
                    $cust_id = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
                    if ($cust_id === null) {
                        $cuStmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(TRIM(email)) = LOWER(:em) LIMIT 1");
                        $cuStmt->execute([':em' => $email]);
                        $foundCid = $cuStmt->fetchColumn();
                        if ($foundCid) {
                            $cust_id = (int)$foundCid;
                        }
                    }

                    $payInfo = determineOrderPaymentRefundStatus($v_data);

                    if (in_array($v_status, ['pending', 'approved'], true)) {
                        // POLICY A & B: Placed/Pending & Approved -> AUTOMATIC CANCELLATION
                        $stmt_ins = $pdo->prepare("
                            INSERT INTO customer_requests
                            (order_number, account, customer_id, request_type, reason, details, item_to_replace, product_image, status, cancellation_mode, auto_approved, processed_at, refund_status, email_status)
                            VALUES
                            (:order_id, :email, :cust_id, 'cancel', :reason, :details, NULL, :product_image, 'Approved', 'automatic', 1, NOW(), :refund_status, 'Approved')
                            RETURNING id
                        ");
                        $stmt_ins->execute([
                            ':order_id' => (string)$order_id_int,
                            ':email' => $email,
                            ':cust_id' => $cust_id,
                            ':reason' => $reason,
                            ':details' => $user_comments,
                            ':product_image' => $filename,
                            ':refund_status' => $payInfo['refund_status'],
                        ]);
                        $newReqId = (int)$stmt_ins->fetchColumn();

                        $cancelMeta = finalizeOrderCancellation($pdo, $order_id_int, [
                            'mode' => 'automatic',
                            'request_id' => $newReqId,
                            'admin_id' => null,
                            'actor_name' => $email,
                            'actor_role' => 'customer',
                            'reason' => $reason . ($user_comments !== '' ? " ({$user_comments})" : ''),
                        ]);

                        $pdo->commit();

                        // Send non-blocking email notification once
                        if (function_exists('sendSystemEmail') && !empty($v_data['email'])) {
                            try {
                                $custFull = trim(($v_data['fullname'] ?? '') . ' ' . ($v_data['surname'] ?? '')) ?: 'Customer';
                                $refundNote = !empty($cancelMeta['requires_refund'])
                                    ? "<p style='background:#fff3cd;padding:10px;border-radius:5px;color:#856404;'><strong>Payment Note:</strong> Your e-wallet payment record has been preserved and marked as <strong>Pending Refund</strong>. Our admin will upload your refund proof shortly.</p>"
                                    : "";
                                $mailHtml = "
                                    <h3>Hi " . htmlspecialchars($custFull) . ",</h3>
                                    <p>Your cancellation request for Order <strong>#{$order_id_int}</strong> has been <strong>automatically approved</strong> because your order has not yet entered the preparation stage.</p>
                                    <p><strong>Reason:</strong> " . htmlspecialchars($reason) . "</p>
                                    <p><strong>Order Status:</strong> CANCELLED</p>
                                    {$refundNote}
                                    <br>
                                    <h4>Best regards,<br>R. Taipan Construction Supply and Services, OPC</h4>
                                ";
                                sendSystemEmail($v_data['email'], $custFull, "Order #{$order_id_int} Automatically Cancelled", $mailHtml);
                            } catch (Throwable $mailErr) {
                                error_log("Auto-cancel email error: " . $mailErr->getMessage());
                            }
                        }

                        $message = "Your cancellation request has been automatically approved because your order has not yet entered the preparation stage. Your order has been successfully cancelled.";
                        $message_type = "success";
                    } elseif ($v_status === 'preparing') {
                        // POLICY C: Preparing -> REQUIRES ADMIN APPROVAL
                        $stmt_ins = $pdo->prepare("
                            INSERT INTO customer_requests
                            (order_number, account, customer_id, request_type, reason, details, item_to_replace, product_image, status, cancellation_mode, auto_approved, refund_status)
                            VALUES
                            (:order_id, :email, :cust_id, 'cancel', :reason, :details, NULL, :product_image, 'Pending', 'manual', 0, :refund_status)
                            RETURNING id
                        ");
                        $stmt_ins->execute([
                            ':order_id' => (string)$order_id_int,
                            ':email' => $email,
                            ':cust_id' => $cust_id,
                            ':reason' => $reason,
                            ':details' => $user_comments,
                            ':product_image' => $filename,
                            ':refund_status' => $payInfo['refund_status'],
                        ]);
                        $newReqId = (int)$stmt_ins->fetchColumn();

                        logSystemAudit(
                            $pdo,
                            $cust_id,
                            $email,
                            'customer',
                            'Cancellation Request Submitted',
                            "Submitted cancellation request #{$newReqId} for Order #{$order_id_int} (Status: PREPARING). Reason: {$reason}"
                        );

                        createSystemNotification($pdo, [
                            'recipient_role' => 'admin',
                            'recipient_email' => null,
                            'order_id' => $order_id_int,
                            'request_id' => $newReqId,
                            'title' => "Cancellation Request: Order #{$order_id_int}",
                            'message' => "Customer {$email} requested to cancel Order #{$order_id_int} (currently Preparing). Reason: {$reason}. Admin approval or decline is required before any order status updates.",
                            'type' => 'warning',
                            'unique_key' => "admin_cancel_pending_{$newReqId}",
                        ]);

                        createSystemNotification($pdo, [
                            'recipient_role' => 'customer',
                            'recipient_email' => $email,
                            'order_id' => $order_id_int,
                            'request_id' => $newReqId,
                            'title' => "Cancellation Request Submitted (#{$order_id_int})",
                            'message' => "Your cancellation request for Order #{$order_id_int} has been submitted and is pending Admin review.",
                            'type' => 'info',
                            'unique_key' => "cust_cancel_pending_{$newReqId}",
                        ]);

                        $pdo->commit();

                        $message = "Your cancellation request has been submitted successfully. Our team will review your request and notify you of the decision.";
                        $message_type = "success";
                    } else {
                        $pdo->rollBack();
                        $message = "❌ Cancellation is not permitted for the current order status (" . strtoupper($v_data['status']) . ").";
                        $message_type = "danger";
                    }
                } else {
                    // Existing Exchange / Other Ticket Submission Logic
                    $total_ordered_qty_map = [];
                    $total_ordered_price_map = [];
                    try {
                        $oi_chk = $pdo->prepare("SELECT product_name, price, qty FROM order_items WHERE order_id = ?");
                        $oi_chk->execute([$order_id_int]);
                        $db_oi = $oi_chk->fetchAll(PDO::FETCH_ASSOC);
                        if (!empty($db_oi)) {
                            foreach ($db_oi as $it) {
                                $p_name = $it['product_name'];
                                $total_ordered_qty_map[$p_name] = (int)($it['qty'] ?? 1);
                                $total_ordered_price_map[$p_name] = (float)($it['price'] ?? 0);
                            }
                        } else {
                            $cart_raw = $v_data['cart'] ?? '';
                            $cart_obj = is_string($cart_raw) ? json_decode($cart_raw, true) : $cart_raw;
                            if (is_array($cart_obj)) {
                                foreach ($cart_obj as $it) {
                                    $p_name = $it['name'] ?? ($it['product_name'] ?? 'Item');
                                    $p_qty = intval($it['qty'] ?? 1);
                                    $total_ordered_qty_map[$p_name] = $p_qty;
                                    $total_ordered_price_map[$p_name] = (float)($it['price'] ?? 0);
                                }
                            }
                        }
                    } catch (Exception $e) {}

                    if ($request_type === 'exchange') {
                        $exchange_items = $_POST['exchange_items'] ?? [];
                        $exchange_qtys = $_POST['exchange_qtys'] ?? [];
                        $is_valid_qty = !empty($exchange_items);
                        $submitted_counts = [];

                        for ($i = 0; $i < count($exchange_items); $i++) {
                            $item_name = $exchange_items[$i];
                            $item_qty = intval($exchange_qtys[$i] ?? 1);
                            if (!isset($total_ordered_qty_map[$item_name]) || $item_qty <= 0) {
                                $is_valid_qty = false;
                                break;
                            }
                            $submitted_counts[$item_name] = ($submitted_counts[$item_name] ?? 0) + $item_qty;
                            if ($submitted_counts[$item_name] > $total_ordered_qty_map[$item_name]) {
                                $is_valid_qty = false;
                                break;
                            }
                        }

                        if (!$is_valid_qty) {
                            $pdo->rollBack();
                            $message = "❌ Security Error: The quantity of items requested for exchange is invalid or exceeds your original order.";
                            $message_type = "danger";
                        } else {
                            $readable_items_list = "";
                            for ($i = 0; $i < count($exchange_items); $i++) {
                                $item_name = $exchange_items[$i];
                                $item_qty = intval($exchange_qtys[$i] ?? 1);
                                $unit_price = (float)($total_ordered_price_map[$item_name] ?? 0);
                                $credit = round($unit_price * $item_qty, 2);
                                $credit_info = $unit_price > 0 ? " — Exchange Credit: ₱" . number_format($credit, 2) . " (₱" . number_format($unit_price, 2) . "/unit)" : "";
                                $readable_items_list .= "• {$item_name} (Qty: {$item_qty}x){$credit_info}\n";
                            }

                            $stmt_ins = $pdo->prepare("
                                INSERT INTO customer_requests
                                (order_number, account, request_type, reason, details, item_to_replace, product_image, status)
                                VALUES
                                (:order_id, :email, :request_type, :reason, :details, :item_to_replace, :product_image, 'Pending')
                            ");
                            $ins = $stmt_ins->execute([
                                ':order_id' => (string)$order_id_int,
                                ':email' => $email,
                                ':request_type' => $request_type,
                                ':reason' => $reason,
                                ':details' => $user_comments,
                                ':item_to_replace' => trim($readable_items_list),
                                ':product_image' => $filename,
                            ]);

                            if ($ins) {
                                $pdo->commit();
                                $message = "🎉 Your ticket request (" . strtoupper($request_type) . ") has been submitted successfully! Admin will review it.";
                                $message_type = "success";
                            } else {
                                $pdo->rollBack();
                                $message = "❌ Failed to submit request. Please try again.";
                                $message_type = "danger";
                            }
                        }
                    } else {
                        $stmt_ins = $pdo->prepare("
                            INSERT INTO customer_requests
                            (order_number, account, request_type, reason, details, item_to_replace, product_image, status)
                            VALUES
                            (:order_id, :email, :request_type, :reason, :details, NULL, :product_image, 'Pending')
                        ");
                        $ins = $stmt_ins->execute([
                            ':order_id' => (string)$order_id_int,
                            ':email' => $email,
                            ':request_type' => $request_type,
                            ':reason' => $reason,
                            ':details' => $user_comments,
                            ':product_image' => $filename,
                        ]);

                        if ($ins) {
                            $pdo->commit();
                            $message = "🎉 Your ticket request (" . strtoupper($request_type) . ") has been submitted successfully! Admin will review it.";
                            $message_type = "success";
                        } else {
                            $pdo->rollBack();
                            $message = "❌ Failed to submit request. Please try again.";
                            $message_type = "danger";
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Customer service submit error: " . $e->getMessage());
            $message = "❌ Failed to process request: " . htmlspecialchars($e->getMessage());
            $message_type = "danger";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Service Help Center</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f6f9; }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .thermal-preview {
            width: 100%;
            max-width: 380px;
            margin: auto;
            padding: 15px;
            background: #fff;
            font-family: 'Courier New', Courier, monospace;
            border: 1px solid #ddd;
            color: #000;
            border-radius: 8px;
        }
        .line {
            border-top: 1px dashed #000;
            margin: 10px 0;
        }
    </style>
</head>
<body>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-9">
            
            <div class="text-center mb-4">
                <h2 class="fw-bold text-dark"><i class="bi bi-headset text-primary"></i> Customer Service Help Center</h2>
            </div>

            <?php if(!empty($message)): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show card-custom p-3" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="card card-custom p-4 bg-white mb-4">
                <form action="customerService.php" method="POST" enctype="multipart/form-data" id="mainRequestForm">
                    <div class="mb-4">
                        <label class="form-label fw-semibold">For Other Questions, Please Contact Us:</label>
                        <div class="small mt-2">
                            <a href="https://www.facebook.com/share/1Acq7mfhEZ/?mibextid=wwXIfr" class="text-info" target="_blank">View Facebook Page</a><br>
                            0907 456 5632<br>
                            <a href="https://mail.google.com/mail/?view=cm&fs=1&to=rtaipancssopc@gmail.com&su=Customer%20Service%20Inquiry" class="text-info" target="_blank">rtaipancssopc@gmail.com</a>
                        </div>
                    </div>
                    
                    <?php if ($isDirectMode): ?>
                        <!-- Direct Action from My Purchases: Inputs and Verify button are removed from UI -->
                        <input type="hidden" name="order_id" id="order_id" value="<?php echo htmlspecialchars($prefill_order_id, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="email" id="email" value="<?php echo htmlspecialchars($prefill_email, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="button" id="btnVerify" class="d-none"></button>
                        
                        <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary fs-6 px-3 py-2">
                                    <i class="bi bi-receipt me-1"></i> Order #<?php echo htmlspecialchars($prefill_order_id, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span class="text-muted small">Account: <strong><?php echo htmlspecialchars($prefill_email, ENT_QUOTES, 'UTF-8'); ?></strong></span>
                            </div>
                            <a href="my_purchases.php" class="btn btn-secondary btn-sm px-3">
                                <i class="bi bi-arrow-left me-1"></i> Back to Purchases
                            </a>
                        </div>
                        <div id="directLoadingState" class="text-center py-4 text-muted">
                            <div class="spinner-border text-primary spinner-border-sm me-2" role="status"></div>
                            Loading verified order & receipt preview...
                        </div>
                    <?php else: ?>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Order ID / Number</label>
                                <input type="number" name="order_id" id="order_id" class="form-control" placeholder="e.g. 1024"
                                       value="<?php echo htmlspecialchars($prefill_order_id, ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Account Email Address</label>
                                <input type="email" name="email" id="email" class="form-control" placeholder="yourname@email.com"
                                       value="<?php echo htmlspecialchars($prefill_email, ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="col-12 text-end">
                                <button type="button" id="btnVerify" class="btn btn-primary px-4"><i class="bi bi-shield-check"></i> Verify Order</button>
                                <button type="button" class="btn btn-secondary px-4" onclick="window.location.href='my_purchases.php';"><i class="bi bi-arrow-left"></i> Back to Purchases</button>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div id="errorWrapper" class="alert alert-danger d-none card-custom mb-4"></div>

                    <div id="formBodyWrapper" class="d-none">
                        
                        <div class="card p-3 bg-light card-custom mb-4">
                            <h6 class="fw-bold text-primary mb-3"><i class="bi bi-receipt"></i> Verified Order & Receipt Preview</h6>
                            <div class="bg-dark p-3 rounded d-flex justify-content-center">
                                <div class="thermal-preview" id="liveReceiptContainer"></div>
                            </div>
                        </div>

                        <div id="requestActionsBox" class="border-top pt-4 mb-4">
                            <label class="form-label fw-bold fs-5 text-dark">Select Request Action</label>
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <input type="radio" class="btn-check" name="request_type" id="req_exchange" value="exchange" checked>
                                    <label class="btn btn-outline-primary w-100 py-3 h-100 rounded-3 d-flex flex-column align-items-center justify-content-center" for="req_exchange" id="lbl_req_exchange">
                                        <i class="bi bi-arrow-left-right fs-4 mb-1"></i> Exchange Items
                                    </label>
                                </div>
                                <div class="col-md-4">
                                    <input type="radio" class="btn-check" name="request_type" id="req_cancel" value="cancel">
                                    <label class="btn btn-outline-dark w-100 py-3 h-100 rounded-3 d-flex flex-column align-items-center justify-content-center" for="req_cancel" id="lbl_req_cancel">
                                        <i class="bi bi-x-circle fs-4 mb-1"></i> Cancel Order
                                    </label>
                                </div>
                            </div>
                            <div id="cancelPolicyBanner" class="alert d-none small mt-3 mb-0"></div>
                        </div>

                        <div id="formFieldsContainer">
                            <div class="mb-4" id="reasonFieldWrapper">
                                <label class="form-label fw-semibold">Reason for Request</label>
                                <select name="reason" id="requestReason" class="form-select"></select>
                            </div>

                            <div id="exchangeCalculatorWrapper" class="card p-3 bg-light card-custom mb-4 d-none">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="fw-bold text-dark m-0"><i class="bi bi-arrow-repeat"></i> Same Item Exchange Policy</h6>
                                    <button type="button" id="btnAddExchangeItem" class="btn btn-sm btn-primary d-none"><i class="bi bi-plus-circle"></i> Add More Item</button>
                                </div>
                                
                                <div id="exchangeItemsContainer"></div>

                                <div class="alert alert-warning small mb-0 mt-3">
                                    ℹ️ <strong>Same Item Exchange Only:</strong> Exchanges are only allowed for the same item. Replacement with a different item is not permitted.
                                </div>
                            </div>

                            <div class="mb-4 d-none" id="photoUploadSection">
                                <label class="form-label fw-semibold">Upload Image Proof / Photo of the Item(s)</label>
                                <input type="file" name="product_image" id="product_image" class="form-control" accept="image/*">
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold" id="lbl_details_desc">Additional Comments / Specific Details</label>
                                <textarea name="details" id="requestDetails" class="form-control" rows="4" placeholder="Explain your concern comprehensively..."></textarea>
                            </div>

                            <div class="text-end">
                                <button type="submit" name="submit_request" id="btnSubmitForm" class="btn btn-success px-5 fw-semibold py-2">Submit Ticket Request</button>
                            </div>
                        </div>

                    </div>
                </form>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let verifiedItemsData = [];
let currentOrderInfo = {};

document.getElementById('btnVerify').addEventListener('click', function() {
    const orderId = document.getElementById('order_id').value.trim();
    const email = document.getElementById('email').value.trim();
    const errorWrapper = document.getElementById('errorWrapper');
    const formBodyWrapper = document.getElementById('formBodyWrapper');

    if(!orderId || !email) {
        showAppAlert("Please enter both Order ID and Email address.", { title: 'Missing Information', type: 'warning' });
        return;
    }

    fetch(`customerService.php?action=get_items&order_id=${orderId}&email=${email}`)
    .then(res => res.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            const directLoading = document.getElementById('directLoadingState');
            if (directLoading) directLoading.remove();

            if(data.success) {
                errorWrapper.classList.add('d-none');
                formBodyWrapper.classList.remove('d-none');
                
                verifiedItemsData = data.items;
                currentOrderInfo = data.order_info;
                
                renderLiveReceiptPreview();
                handleFormTypeSetup(currentOrderInfo.status);

                // Auto-select requested action type from URL if specified
                const urlParams = new URLSearchParams(window.location.search);
                const reqTypeParam = urlParams.get('type');
                if (reqTypeParam === 'cancel') {
                    const reqCancel = document.getElementById('req_cancel');
                    if (reqCancel && !reqCancel.disabled) {
                        reqCancel.checked = true;
                        reqCancel.dispatchEvent(new Event('change'));
                    }
                } else if (reqTypeParam === 'exchange') {
                    const reqExchange = document.getElementById('req_exchange');
                    if (reqExchange && !reqExchange.disabled) {
                        reqExchange.checked = true;
                        reqExchange.dispatchEvent(new Event('change'));
                    }
                }

                // Smooth scroll to actions box only for manual lookup (not direct mode where receipt preview is at the top)
                const isDirectHidden = document.getElementById('order_id')?.type === 'hidden';
                if (!isDirectHidden) {
                    setTimeout(() => {
                        const targetSection = document.getElementById('requestActionsBox');
                        if (targetSection) {
                            targetSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    }, 200);
                }
            } else {
                formBodyWrapper.classList.add('d-none');
                errorWrapper.classList.remove('d-none');
                errorWrapper.innerHTML = `<strong>Error:</strong> ${data.message}`;
            }
        } catch(e) {
            const directLoading = document.getElementById('directLoadingState');
            if (directLoading) directLoading.remove();
            console.error("Raw response:", text);
            formBodyWrapper.classList.add('d-none');
            errorWrapper.classList.remove('d-none');
            errorWrapper.innerHTML = `<strong>Server Error / JSON Parse Exception:</strong> Check console for response details.`;
        }
    })
    .catch(err => {
        console.error(err);
        showAppAlert("An error occurred during verification lookup. Please try again.", { title: 'Verification Error', type: 'danger' });
    });
});

function renderLiveReceiptPreview() {
    const container = document.getElementById('liveReceiptContainer');
    
    let itemsHtml = '';
    verifiedItemsData.forEach(item => {
        let price = parseFloat(item.price || 0);
        let qty = parseInt(item.qty || 0);
        let subtotal = price * qty;

        itemsHtml += `
            <tr>
                <td>${item.product_name} (x${qty})</td>
                <td align="right">₱${subtotal.toFixed(2)}</td>
            </tr>
        `;
    });

    let paymentMethodName = (currentOrderInfo.payment_method || 'COD').toUpperCase();
    let paymentScheme = (currentOrderInfo.payment_scheme || '').toLowerCase();
    let totalAmt = parseFloat(currentOrderInfo.total_amount || 0);
    let orderStatus = (currentOrderInfo.status || 'pending').toUpperCase();

    let paymentBreakdownHtml = '';

    if (paymentMethodName === 'COD') {
        paymentBreakdownHtml = `
            <div class="d-flex justify-content-between small fw-bold mb-2">
                <span>TOTAL AMOUNT:</span>
                <span>₱${totalAmt.toFixed(2)}</span>
            </div>
            <div class="text-muted small">
                Cash on Delivery (Pay upon delivery)
            </div>
        `;
    }
    else if (totalAmt >= 5000 && paymentScheme.includes('50')) {
        let downpayment = parseFloat(currentOrderInfo.paid_amount || (totalAmt * 0.5));
        let balance = totalAmt - downpayment;

        paymentBreakdownHtml = `
            <div class="d-flex justify-content-between small fw-bold mb-1">
                <span>TOTAL AMOUNT:</span>
                <span>₱${totalAmt.toFixed(2)}</span>
            </div>
            <div class="d-flex justify-content-between small text-success fw-bold mb-1">
                <span>PAID VIA ${paymentMethodName}:</span>
                <span>₱${downpayment.toFixed(2)}</span>
            </div>
            <div class="line"></div>
            <div class="d-flex justify-content-between small fw-bold text-danger">
                <span>PAY UPON DELIVERY:</span>
                <span>₱${balance.toFixed(2)}</span>
            </div>
        `;
    }
    else if (paymentMethodName === 'GCASH' || paymentMethodName === 'MAYA') {
        let paidAmount = parseFloat(currentOrderInfo.paid_amount || totalAmt);

        paymentBreakdownHtml = `
            <div class="d-flex justify-content-between small fw-bold mb-1">
                <span>TOTAL AMOUNT:</span>
                <span>₱${totalAmt.toFixed(2)}</span>
            </div>
            <div class="d-flex justify-content-between small text-success fw-bold mb-1">
                <span>PAID VIA ${paymentMethodName}:</span>
                <span>₱${paidAmount.toFixed(2)}</span>
            </div>
        `;

        if (currentOrderInfo.ref_no) {
            paymentBreakdownHtml += `
                <div class="small text-muted">
                    Reference No: ${currentOrderInfo.ref_no}
                </div>
            `;
        }
    }
    else {
        paymentBreakdownHtml = `
            <div class="d-flex justify-content-between small fw-bold">
                <span>TOTAL AMOUNT:</span>
                <span>₱${totalAmt.toFixed(2)}</span>
            </div>
        `;
    }

    let fullReceiptHtml = `
        <div class="text-center mb-2">
            <h5 class="fw-bold my-0 text-uppercase">R. Taipan Hardware</h5>
            <small>190 Cabrera Rd. Sitio Burol Brgy. San Juan Taytay, Rizal</small><br>
            <small>Contact: 0907 456 5632</small>
            <div class="line"></div>
            <small><b>OFFICIAL RECEIPT (ONLINE ORDER)</b></small>
            <div class="line"></div>
        </div>

        <p class="small mb-1"><b>Order ID:</b> #${currentOrderInfo.id}</p>
        <p class="small mb-1"><b>Date:</b> ${currentOrderInfo.order_date}</p>
        <p class="small mb-1"><b>Customer:</b> ${currentOrderInfo.customer_name} ${currentOrderInfo.surname}</p>
        <p class="small mb-1"><b>Contact:</b> ${currentOrderInfo.contact_number}</p>
        <p class="small mb-1"><b>Address:</b> ${currentOrderInfo.full_address}</p>
        <p class="small mb-2"><b>Landmark:</b> ${currentOrderInfo.landmark || 'N/A'}</p>

        <div class="line"></div>

        <table width="100%" class="small mb-2">
            ${itemsHtml}
        </table>

        <div class="line"></div>

        ${paymentBreakdownHtml}

        <div class="line mt-2"></div>

        <div class="text-start small">
            <b>Mode of Payment:</b> ${paymentMethodName}
        </div>

        <div class="text-center mt-2">
            <small class="fw-bold">STATUS: ${orderStatus}</small>
        </div>
    `;
    
    container.innerHTML = fullReceiptHtml;
}

function handleFormTypeSetup(status) {
    const reqCancel = document.getElementById('req_cancel');
    const reqExchange = document.getElementById('req_exchange');
    const lblExchange = document.getElementById('lbl_req_exchange');
    const lblCancel = document.getElementById('lbl_req_cancel');
    const errorWrapper = document.getElementById('errorWrapper');
    const btnSubmitForm = document.getElementById('btnSubmitForm');
    const requestActionsBox = document.getElementById('requestActionsBox');
    const formFieldsContainer = document.getElementById('formFieldsContainer');
    const cancelPolicyBanner = document.getElementById('cancelPolicyBanner');
    
    let currentStatus = (currentOrderInfo.normalized_status || (status ? status.toLowerCase().trim().replace(/[\s-]+/g, '_') : ''));
    if (currentStatus === 'placed' || currentStatus === 'order_placed') currentStatus = 'pending';
    if (currentStatus === 'canceled') currentStatus = 'cancelled';
    
    errorWrapper.classList.add('d-none');
    errorWrapper.innerHTML = '';
    
    // Default reset states
    btnSubmitForm.disabled = false;
    btnSubmitForm.classList.remove('d-none');
    requestActionsBox.classList.remove('d-none');
    formFieldsContainer.classList.remove('d-none');
    if (cancelPolicyBanner) {
        cancelPolicyBanner.className = 'alert d-none small mt-3 mb-0';
        cancelPolicyBanner.innerHTML = '';
    }
    reqCancel.disabled = false;
    reqExchange.disabled = false;
    lblCancel.classList.remove('disabled', 'btn-outline-secondary');
    lblCancel.classList.add('btn-outline-dark');
    lblExchange.classList.remove('disabled', 'btn-outline-secondary');
    lblExchange.classList.add('btn-outline-primary');

    // 1. STRICT ANTI-SPAM LOCK: Kapag may umiiral na PENDING request, I-HIDE ANG BUONG ACTION & FORM FIELDS
    if (currentOrderInfo.has_pending_request) {
        reqCancel.disabled = true;
        reqExchange.disabled = true;
        btnSubmitForm.disabled = true;
        
        // Itago ang mismong selector at form inputs para hindi makapag-submit si customer
        requestActionsBox.classList.add('d-none');
        formFieldsContainer.classList.add('d-none');

        errorWrapper.classList.remove('d-none');
        errorWrapper.innerHTML = `⚠️ <strong>Pending Request Exists:</strong> Order #${currentOrderInfo.id} already has an active <strong>${currentOrderInfo.pending_req_type}</strong> request under review by the admin. You cannot submit another request until it is processed.`;
        return;
    }

    // Check if order date exceeds 24 hours (1 day)
    let isExceeded1Day = false;
    if (currentOrderInfo.order_date) {
        const orderTimestamp = new Date(currentOrderInfo.order_date).getTime();
        const nowTimestamp = new Date().getTime();
        const hoursPassed = (nowTimestamp - orderTimestamp) / (1000 * 60 * 60);
        if (hoursPassed > 24) {
            isExceeded1Day = true;
        }
    }

    // 2. If order is already cancelled/declined
    if (currentStatus === 'cancelled' || currentStatus === 'declined' || currentOrderInfo.is_cancel_completed) {
        reqCancel.disabled = true;
        lblCancel.classList.add('disabled', 'btn-outline-secondary');
        lblCancel.classList.remove('btn-outline-dark');
        btnSubmitForm.disabled = true;
        formFieldsContainer.classList.add('d-none');
        
        errorWrapper.classList.remove('d-none');
        errorWrapper.innerHTML = `⚠️ <strong>Cancellation Not Available:</strong> This order has already been <strong>${currentStatus.toUpperCase()}</strong> or has an approved cancellation request. Submitting a new ticket is disabled.`;
    }

    // 3. Policy banners for cancellable statuses (Placed/Pending, Approved, Preparing)
    if (currentStatus === 'pending' || currentStatus === 'approved') {
        reqCancel.checked = true;
        if (cancelPolicyBanner) {
            cancelPolicyBanner.className = 'alert alert-success small mt-3 mb-0';
            cancelPolicyBanner.innerHTML = `<i class="bi bi-lightning-charge-fill me-1"></i> <strong>Automatic Cancellation Eligible:</strong> Since your order is still <strong>${currentStatus === 'approved' ? 'Approved' : 'Placed / Pending'}</strong> and has not entered preparation, your cancellation request will be <strong>automatically approved immediately</strong>.`;
        }
    } else if (currentStatus === 'preparing') {
        reqCancel.checked = true;
        if (cancelPolicyBanner) {
            cancelPolicyBanner.className = 'alert alert-warning small mt-3 mb-0';
            const prevDecline = (currentOrderInfo.latest_cancel && String(currentOrderInfo.latest_cancel.status || '').toLowerCase() === 'declined')
                ? `<div class="mt-2 pt-2 border-top border-warning-subtle text-danger"><strong>Previous Cancellation Declined:</strong> ${String(currentOrderInfo.latest_cancel.admin_notes || 'No reason provided')}</div>`
                : '';
            cancelPolicyBanner.innerHTML = `<i class="bi bi-hourglass-split me-1"></i> <strong>Admin Approval Required:</strong> Your order is currently being <strong>Prepared</strong>. Your cancellation request will be reviewed by our Admin before any order cancellation occurs.${prevDecline}`;
        }
    }

    // 4. Lock Cancellation for Delivery/Delivered/Completed states
    if (currentStatus === 'out_for_delivery' || currentStatus === 'delivered' || currentStatus === 'completed') {
        reqCancel.disabled = true;
        lblCancel.classList.add('disabled', 'btn-outline-secondary');
        lblCancel.classList.remove('btn-outline-dark');
        
        if (reqCancel.checked) {
            reqCancel.checked = false;
            if (currentStatus === 'completed' && !isExceeded1Day) {
                reqExchange.checked = true;
            }
        }
        
        if (currentStatus === 'out_for_delivery') {
            errorWrapper.classList.remove('d-none');
            errorWrapper.innerHTML = `ℹ️ <strong>Cancellation Unavailable:</strong> Your order is already <strong>OUT FOR DELIVERY</strong>. Cancellation is no longer available.`;
        } else if (currentStatus === 'delivered') {
            errorWrapper.classList.remove('d-none');
            errorWrapper.innerHTML = `ℹ️ <strong>Cancellation Unavailable:</strong> This order has already been <strong>DELIVERED</strong>. For delivery issues or damaged/missing items, please confirm <strong>Order Received</strong> in My Purchases and submit a <strong>Return / Exchange</strong> or Customer Service dispute instead.`;
        }
    }

    // 5. Lock whole form if status is Out for Delivery or Delivered
    if (currentStatus === 'out_for_delivery' || currentStatus === 'delivered') {
        btnSubmitForm.disabled = true;
        reqExchange.disabled = true;
        lblExchange.classList.add('disabled', 'btn-outline-secondary');
        lblExchange.classList.remove('btn-outline-primary');
        formFieldsContainer.classList.add('d-none');
    }
    // 6. Exchange Policy (Completed Status + 1-Day Limit Validation)
    else if (currentStatus === 'completed' && !currentOrderInfo.is_cancel_completed) {
        if (isExceeded1Day) {
            reqExchange.disabled = true;
            lblExchange.classList.add('disabled', 'btn-outline-secondary');
            lblExchange.classList.remove('btn-outline-primary');
            reqExchange.checked = false;
            btnSubmitForm.disabled = true;
            formFieldsContainer.classList.add('d-none');

            errorWrapper.classList.remove('d-none');
            errorWrapper.innerHTML = `⚠️ <strong>Notice:</strong> Regular cancellation is not allowed for completed orders, and item exchange requests are strictly allowed within <strong>1 day (24 hours)</strong> after order completion.`;
        } else {
            reqExchange.disabled = false;
            lblExchange.classList.remove('disabled', 'btn-outline-secondary');
            lblExchange.classList.add('btn-outline-primary');
            reqExchange.checked = true;
            errorWrapper.classList.remove('d-none');
            errorWrapper.innerHTML = `ℹ️ <strong>Completed Order:</strong> Regular cancellation is no longer available, but you may request an item Return / Exchange within 24 hours.`;
        }
    } else {
        reqExchange.disabled = true;
        lblExchange.classList.add('disabled', 'btn-outline-secondary');
        lblExchange.classList.remove('btn-outline-primary');
        reqExchange.checked = false;
    }

    renderReasonOptions();
    resetExchangeItemsList();
}

function renderReasonOptions() {
    const reasonSelect = document.getElementById('requestReason');
    const selectedRadio = document.querySelector('input[name="request_type"]:checked');
    const selectedType = selectedRadio ? selectedRadio.value : 'cancel';
    const reasonFieldWrapper = document.getElementById('reasonFieldWrapper');
    const photoUploadSection = document.getElementById('photoUploadSection');
    const exchangeCalculatorWrapper = document.getElementById('exchangeCalculatorWrapper');
    const lblDetailsDesc = document.getElementById('lbl_details_desc');
    const txtDetails = document.getElementById('requestDetails');
    const productImageInput = document.getElementById('product_image');
    const exchangeRows = document.querySelectorAll('.exchange-item-row');

    reasonSelect.innerHTML = "";
    let options = [];

    reasonFieldWrapper.classList.remove('d-none');
    photoUploadSection.classList.add('d-none');
    exchangeCalculatorWrapper.classList.add('d-none');
    productImageInput.removeAttribute('required');
    lblDetailsDesc.textContent = "Additional Comments / Specific Details";
    txtDetails.placeholder = "Explain your concern comprehensively...";

    exchangeRows.forEach(row => {
        const select = row.querySelector('.exch-item-select');
        const qty = row.querySelector('.exch-qty-input');

        if (select) {
            select.removeAttribute('required');
            select.disabled = true;
        }

        if (qty) {
            qty.removeAttribute('required');
            qty.disabled = true;
        }
    });

    if (selectedType === 'exchange') {
        options = [
            "Wrong size/variant selected",
            "Received Damaged / Broken Item",
            "Wrong item delivered",
            "Other"
        ];

        photoUploadSection.classList.remove('d-none');
        exchangeCalculatorWrapper.classList.remove('d-none');
        productImageInput.setAttribute('required', 'required');

        exchangeRows.forEach(row => {
            const select = row.querySelector('.exch-item-select');
            const qty = row.querySelector('.exch-qty-input');

            if (select) {
                select.setAttribute('required', 'required');
                select.disabled = false;
            }

            if (qty) {
                qty.setAttribute('required', 'required');
            }

            if (select && select.value) {
                if (qty) qty.disabled = false;
            } else {
                if (qty) qty.disabled = true;
            }
        });
    } else if (selectedType === 'cancel') {
        options = [
            "Found mistake in order details",
            "Change of mind",
            "Accidental order placement",
            "Other"
        ];
    }

    options.forEach(opt => {
        const el = document.createElement('option');
        el.value = opt;
        el.textContent = opt;
        reasonSelect.appendChild(el);
    });

    updateExchangeItemControls();
}

document.querySelectorAll('input[name="request_type"]').forEach(radio => {
    radio.addEventListener('change', function() {
        renderReasonOptions();
    });
});

function resetExchangeItemsList() {
    const container = document.getElementById('exchangeItemsContainer');
    const btnAddMore = document.getElementById('btnAddExchangeItem');

    container.innerHTML = "";
    btnAddMore.classList.add('d-none');
    btnAddMore.disabled = true;

    if (verifiedItemsData.length > 0) {
        addExchangeItemRow(true);
    }

    updateExchangeItemControls();
}

document.getElementById('btnAddExchangeItem').addEventListener('click', function() {
    if (this.disabled) return;
    if (!canAddAnotherExchangeRow()) return;

    addExchangeItemRow(false);
    updateExchangeItemControls();
});

function getUsedQuantities() {
    const used = {};

    document.querySelectorAll('.exchange-item-row').forEach(row => {
        const select = row.querySelector('.exch-item-select');
        const qty = row.querySelector('.exch-qty-input');

        if (!select || !qty || !select.value) return;

        const itemName = select.value;
        const itemQty = parseInt(qty.value) || 0;

        used[itemName] = (used[itemName] || 0) + itemQty;
    });

    return used;
}

function getItemMaxQty(itemName) {
    const item = verifiedItemsData.find(i => i.product_name === itemName);
    return item ? parseInt(item.qty) || 0 : 0;
}

function getRemainingQty(itemName, currentRow = null) {
    const maxQty = getItemMaxQty(itemName);
    let usedQty = 0;

    document.querySelectorAll('.exchange-item-row').forEach(row => {
        if (row === currentRow) return;

        const select = row.querySelector('.exch-item-select');
        const qty = row.querySelector('.exch-qty-input');

        if (select && qty && select.value === itemName) {
            usedQty += parseInt(qty.value) || 0;
        }
    });

    return Math.max(0, maxQty - usedQty);
}

function canAddAnotherExchangeRow() {
    const used = getUsedQuantities();

    return verifiedItemsData.some(item => {
        const maxQty = parseInt(item.qty) || 0;
        const usedQty = used[item.product_name] || 0;

        return usedQty < maxQty;
    });
}

function addExchangeItemRow(isFirstRow = false) {
    const container = document.getElementById('exchangeItemsContainer');

    const rowDiv = document.createElement('div');
    rowDiv.className = 'row g-3 align-items-center mb-3 exchange-item-row border-bottom pb-3';

    const rowId = 'exch_row_' + Date.now() + Math.floor(Math.random() * 1000);
    rowDiv.id = rowId;

    const colClass = isFirstRow ? 'col-md-9' : 'col-md-7';

    let deleteButtonHtml = '';

    if (!isFirstRow) {
        deleteButtonHtml = `
            <div class="col-md-2 d-flex align-items-end">
                <button type="button" class="btn btn-outline-danger w-100 btn-remove-row mt-4 mt-md-0">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        `;
    }

    rowDiv.innerHTML = `
        <div class="${colClass}">
            <label class="small fw-semibold text-dark">Select Item to Exchange/Replace</label>
            <select name="exchange_items[]" class="form-select exch-item-select">
                <option value="" selected disabled hidden>Select Item</option>
            </select>
            <div class="small text-primary mt-1 exch-credit-display d-none">
                <i class="bi bi-tag-fill me-1"></i>Eligible Exchange Credit: <strong class="exch-credit-amt">₱0.00</strong>
                <span class="text-muted" style="font-size:0.75rem;">(Based strictly on item selling price; cash tendered is not exchange credit)</span>
            </div>
        </div>
        <div class="col-md-3">
            <label class="small fw-semibold text-dark">Quantity</label>
            <input type="number" name="exchange_qtys[]" class="form-control exch-qty-input" value="1" min="1" disabled>
        </div>
        ${deleteButtonHtml}
    `;

    container.appendChild(rowDiv);

    const selectEl = rowDiv.querySelector('.exch-item-select');
    const qtyInput = rowDiv.querySelector('.exch-qty-input');
    const creditBox = rowDiv.querySelector('.exch-credit-display');
    const creditAmt = rowDiv.querySelector('.exch-credit-amt');

    function updateRowCredit() {
        if (!creditBox || !creditAmt) return;
        const val = selectEl.value;
        if (!val) {
            creditBox.classList.add('d-none');
            return;
        }
        const item = verifiedItemsData.find(i => i.product_name === val);
        const price = item ? parseFloat(item.price || 0) : 0;
        const qty = parseInt(qtyInput.value) || 1;
        const totalCredit = price * qty;
        creditAmt.textContent = `₱${totalCredit.toFixed(2)}`;
        creditBox.classList.remove('d-none');
    }

    verifiedItemsData.forEach(item => {
        const option = document.createElement('option');
        option.value = item.product_name;
        option.textContent = `${item.product_name} (Bought @ ₱${parseFloat(item.price || 0).toFixed(2)} - Max Qty: ${parseInt(item.qty) || 0})`;
        option.dataset.maxQty = parseInt(item.qty) || 0;
        selectEl.appendChild(option);
    });

    selectEl.selectedIndex = 0;
    selectEl.value = "";
    qtyInput.disabled = true;
    qtyInput.value = 1;
    qtyInput.removeAttribute('max');

    selectEl.addEventListener('change', function() {
        const selectedItem = this.value;

        if (!selectedItem) {
            qtyInput.disabled = true;
            qtyInput.value = 1;
            qtyInput.removeAttribute('max');
            updateRowCredit();
            updateExchangeItemControls();
            return;
        }

        const remainingQty = getRemainingQty(selectedItem, rowDiv);

        if (remainingQty <= 0) {
            showAppAlert("You have already reached the maximum quantity for this item.", { title: 'Maximum Quantity Reached', type: 'warning' });
            this.value = "";
            this.selectedIndex = 0;
            qtyInput.disabled = true;
            qtyInput.value = 1;
            qtyInput.removeAttribute('max');
            updateRowCredit();
            updateExchangeItemControls();
            return;
        }

        qtyInput.disabled = false;
        qtyInput.min = 1;
        qtyInput.max = remainingQty;

        let currentQty = parseInt(qtyInput.value) || 1;

        if (currentQty > remainingQty) currentQty = remainingQty;
        if (currentQty < 1) currentQty = 1;

        qtyInput.value = currentQty;
        updateRowCredit();
        updateExchangeItemControls();
    });

    qtyInput.addEventListener('input', function() {
        const selectedItem = selectEl.value;

        if (!selectedItem) {
            this.value = 1;
            this.disabled = true;
            updateRowCredit();
            return;
        }

        const remainingQty = getRemainingQty(selectedItem, rowDiv);
        let value = parseInt(this.value) || 1;

        if (value < 1) value = 1;
        if (value > remainingQty) value = remainingQty;

        this.value = value;
        updateRowCredit();
        updateExchangeItemControls();
    });

    if (!isFirstRow) {
        const removeButton = rowDiv.querySelector('.btn-remove-row');

        removeButton.addEventListener('click', function() {
            rowDiv.remove();
            updateExchangeItemControls();
        });
    }

    updateExchangeItemControls();
}

function updateExchangeItemControls() {
    const rows = document.querySelectorAll('.exchange-item-row');
    const btnAddMore = document.getElementById('btnAddExchangeItem');
    const selectedRadio = document.querySelector('input[name="request_type"]:checked');
    const isExchange = selectedRadio && selectedRadio.value === 'exchange';

    rows.forEach(currentRow => {
        const select = currentRow.querySelector('.exch-item-select');
        const qtyInput = currentRow.querySelector('.exch-qty-input');

        if (!select || !qtyInput) return;

        if (!isExchange) {
            select.removeAttribute('required');
            select.disabled = true;
            qtyInput.removeAttribute('required');
            qtyInput.disabled = true;
            return;
        }

        select.setAttribute('required', 'required');

        Array.from(select.options).forEach(option => {
            if (!option.value) return;

            const itemName = option.value;
            const maxQty = getItemMaxQty(itemName);
            let usedByOtherRows = 0;

            rows.forEach(otherRow => {
                if (otherRow === currentRow) return;

                const otherSelect = otherRow.querySelector('.exch-item-select');
                const otherQty = otherRow.querySelector('.exch-qty-input');

                if (otherSelect && otherQty && otherSelect.value === itemName) {
                    usedByOtherRows += parseInt(otherQty.value) || 0;
                }
            });

            const remainingQty = maxQty - usedByOtherRows;

            option.disabled = remainingQty <= 0;

            if (select.value === itemName) {
                option.disabled = false;
                qtyInput.max = Math.max(1, remainingQty);

                if (parseInt(qtyInput.value) > remainingQty) {
                    qtyInput.value = remainingQty;
                }
            }
        });

        if (!select.value) {
            select.selectedIndex = 0;
            select.value = "";
            qtyInput.removeAttribute('required');
            qtyInput.disabled = true;
            qtyInput.value = 1;
            qtyInput.removeAttribute('max');
        } else {
            qtyInput.setAttribute('required', 'required');
            qtyInput.disabled = false;
        }
    });

    if (!isExchange) {
        btnAddMore.classList.add('d-none');
        btnAddMore.disabled = true;
        btnAddMore.classList.add('disabled');
        return;
    }

    if (canAddAnotherExchangeRow()) {
        btnAddMore.classList.remove('d-none');
        btnAddMore.disabled = false;
        btnAddMore.classList.remove('disabled');
    } else {
        btnAddMore.classList.add('d-none');
        btnAddMore.disabled = true;
        btnAddMore.classList.add('disabled');
    }
}

// Auto-verify if order ID and email are pre-filled (e.g. clicked direct from My Purchases)
document.addEventListener('DOMContentLoaded', function() {
    const oIdInput = document.getElementById('order_id');
    const emInput = document.getElementById('email');
    if (oIdInput && emInput && oIdInput.value.trim() !== '' && emInput.value.trim() !== '') {
        document.getElementById('btnVerify')?.click();
    }
});
</script>
<?php require_once __DIR__ . '/../includes/app_modal.php'; ?>
</body>
</html>