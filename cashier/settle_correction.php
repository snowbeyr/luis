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

// Validate sender mobile number for GCash / Maya
if ($payment_method === 'GCash' || $payment_method === 'Maya') {
    if (empty($payment_reference)) {
        echo json_encode(['status' => 'error', 'message' => 'Sender mobile number is mandatory when selecting ' . $payment_method . '.']);
        exit();
    }
    if (!preg_match('/^09\d{9}$/', $payment_reference)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Sender Mobile Number. It must be exactly 11 digits and start with "09" (e.g. 09171234567). Letters are not allowed.']);
        exit();
    }
}

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
