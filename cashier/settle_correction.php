<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/correction_functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['cashier', 'owner', 'admin'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$user_name = $_SESSION['username'] ?? 'User';
$request_id = (int)($_POST['request_id'] ?? 0);
$payment_method = trim($_POST['payment_method'] ?? 'Cash');
$payment_reference = trim($_POST['payment_reference'] ?? '');
$amount = isset($_POST['amount']) ? (float)$_POST['amount'] : null;

if ($request_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid correction request ID.']);
    exit();
}

// Fetch request
$stmt = $pdo->prepare("SELECT * FROM pos_correction_requests WHERE id = ?");
$stmt->execute([$request_id]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$req) {
    echo json_encode(['status' => 'error', 'message' => 'Correction request not found.']);
    exit();
}

$allowed_statuses = ['Approved', 'Pending Additional Payment', 'Pending Refund'];
if (!in_array($req['status'], $allowed_statuses)) {
    echo json_encode(['status' => 'error', 'message' => 'Correction request is not ready for settlement. Current status: ' . $req['status']]);
    exit();
}

// Backend Enforcement: POS / Walk-in settlements and refunds strictly accept CASH ONLY
if (strcasecmp($payment_method, 'Cash') !== 0 && $payment_method !== 'None (Even Exchange)' && $payment_method !== 'N/A') {
    echo json_encode([
        'status' => 'error', 
        'message' => 'POS / Walk-in settlements and refunds strictly accept CASH ONLY. GCash, Maya, and other online payment methods are not supported.'
    ]);
    exit();
}

$payment_method = ($payment_method === 'None (Even Exchange)' || $payment_method === 'N/A') ? $payment_method : 'Cash';
$payment_reference = null;

// Execute settlement
list($success, $message) = executeCorrectionSettlement($pdo, $request_id, $user_name, $payment_method, $payment_reference, $amount);

if ($success) {
    echo json_encode([
        'status' => 'success',
        'message' => $message,
        'request_id' => $request_id,
        'transaction_id' => $req['transaction_id']
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => $message]);
}
