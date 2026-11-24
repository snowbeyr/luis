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
$is_late_requested = !empty($_POST['is_late_request']) && ($_POST['is_late_request'] === '1' || $_POST['is_late_request'] === 'true');
$late_reason = trim($_POST['late_reason'] ?? '');
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

    // Enforce 24-Hour Rule Server-Side
    if ($is_past_deadline) {
        if (!$is_late_requested) {
            echo json_encode([
                'status' => 'error',
                'code' => 'DEADLINE_EXPIRED',
                'message' => 'The 24-hour correction request period has expired. Please submit a Late Correction Request for Owner review.'
            ]);
            exit();
        }

        if (empty($late_reason)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'A mandatory explanation describing why the correction was not reported within 24 hours is required for late correction requests.'
            ]);
            exit();
        }

        $is_late = true;
    } else {
        $is_late = false;
        $late_reason = null; // Normal request does not need late reason
    }

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

    $prop_total = 0;
    foreach ($corr_items as $item) {
        $p = (float)($item['price'] ?? 0);
        $q = (int)($item['qty'] ?? 1);
        $prop_total += ($p * $q);
    }

    $payment_diff = $prop_total - $orig_total;
    $settlement_type = 'none';
    if ($payment_diff > 0) {
        $settlement_type = 'additional_payment';
    } elseif ($payment_diff < 0) {
        $settlement_type = 'refund';
    }

    $pdo->beginTransaction();

    // 7. Insert into pos_correction_requests
    $stmt_req = $pdo->prepare("
        INSERT INTO pos_correction_requests (
            transaction_id, cashier_name, cashier_id, correction_type, reason,
            other_details, is_late_request, correction_deadline, late_reason,
            evidence_path, original_total, proposed_total, payment_difference,
            status, settlement_type, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Review', ?, CURRENT_TIMESTAMP)
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
        $settlement_type
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

    $action = $is_late ? "POS Late Correction Submitted" : "POS Correction Submitted";
    $diff_fmt = $payment_diff > 0 ? "+₱" . number_format($payment_diff, 2) : "-₱" . number_format(abs($payment_diff), 2);
    $late_note = $is_late ? " [LATE REQUEST - Deadline expired on $deadline_datetime_str. Reason: $late_reason]" : "";
    $details = "Cashier $cashier_name submitted {$action} #$request_id for POS Transaction #$transaction_id. Type: $correction_type. Proposed Total: ₱" . number_format($prop_total, 2) . " (Diff: $diff_fmt). Reason: $reason{$late_note}";
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'N/A';

    $stmt_audit = $pdo->prepare("INSERT INTO audit_log (user_id, username, role, action, details, ip_address) VALUES (?, ?, 'cashier', ?, ?, ?)");
    $stmt_audit->execute([$user_audit_id, $cashier_name, $action, $details, $ip]);

    $pdo->commit();

    $success_msg = $is_late
        ? "Late correction request submitted successfully! Because this transaction was completed more than 24 hours ago, it has been submitted as a Late Correction for Owner review and override."
        : "Correction request submitted successfully! Your request has been queued for Owner review.";

    echo json_encode([
        'status' => 'success',
        'message' => $success_msg,
        'request_id' => $request_id,
        'is_late' => $is_late
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => 'Submission failed: ' . $e->getMessage()]);
}
