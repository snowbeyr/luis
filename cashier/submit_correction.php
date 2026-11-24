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
$original_items_json = $_POST['original_items'] ?? '';
$corrected_items_json = $_POST['corrected_items'] ?? '';

if ($transaction_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid transaction selected.']);
    exit();
}

if (empty($correction_type)) {
    echo json_encode(['status' => 'error', 'message' => 'Please select a correction type.']);
    exit();
}

if (empty($reason)) {
    echo json_encode(['status' => 'error', 'message' => 'Please provide a clear reason/explanation for this correction request.']);
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
    // 1. Check if transaction exists and belongs to cashier (or is a valid POS transaction)
    $stmt_tx = $pdo->prepare("SELECT * FROM pos_transaction WHERE id = ?");
    $stmt_tx->execute([$transaction_id]);
    $transaction = $stmt_tx->fetch(PDO::FETCH_ASSOC);

    if (!$transaction) {
        echo json_encode(['status' => 'error', 'message' => 'Transaction not found.']);
        exit();
    }

    // 2. Lock check: Disallow duplicate requests while one is pending review or settlement
    $stmt_lock = $pdo->prepare("
        SELECT id, status FROM pos_correction_requests 
        WHERE transaction_id = ? 
          AND status IN ('Pending Review', 'Approved', 'Pending Additional Payment', 'Pending Refund')
        LIMIT 1
    ");
    $stmt_lock->execute([$transaction_id]);
    $existing_pending = $stmt_lock->fetch(PDO::FETCH_ASSOC);

    if ($existing_pending) {
        echo json_encode([
            'status' => 'error',
            'message' => "Transaction #$transaction_id already has an active correction request (#{$existing_pending['id']} - {$existing_pending['status']}). Multiple concurrent requests are locked."
        ]);
        exit();
    }

    // 3. Handle File Upload (Supporting Evidence)
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

    // 4. Calculate Totals and Financial Difference
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

    // 5. Insert into pos_correction_requests
    $stmt_req = $pdo->prepare("
        INSERT INTO pos_correction_requests (
            transaction_id, cashier_name, cashier_id, correction_type, reason,
            evidence_path, original_total, proposed_total, payment_difference,
            status, settlement_type, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Review', ?, CURRENT_TIMESTAMP)
    ");
    $stmt_req->execute([
        $transaction_id,
        $cashier_name,
        $cashier_id > 0 ? $cashier_id : null,
        $correction_type,
        $reason,
        $evidence_path,
        $orig_total,
        $prop_total,
        $payment_diff,
        $settlement_type
    ]);
    $request_id = $pdo->lastInsertId();

    // 6. Insert items into pos_correction_items
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

    // 7. Update transaction status
    $stmt_lock_tx = $pdo->prepare("UPDATE pos_transaction SET correction_status = 'pending' WHERE id = ?");
    $stmt_lock_tx->execute([$transaction_id]);

    // 8. Audit Log
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
    $details = "Cashier $cashier_name submitted Correction Request #$request_id for POS Transaction #$transaction_id. Type: $correction_type. Proposed Total: ₱" . number_format($prop_total, 2) . " (Diff: $diff_fmt). Reason: $reason";
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
