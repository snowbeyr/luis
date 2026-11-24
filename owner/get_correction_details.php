<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/correction_functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['owner', 'cashier', 'admin'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request ID']);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT * FROM pos_correction_requests WHERE id = ?");
    $stmt->execute([$id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        echo json_encode(['status' => 'error', 'message' => 'Correction request not found']);
        exit();
    }

    $stmt_orig = $pdo->prepare("SELECT * FROM pos_correction_items WHERE request_id = ? AND item_type = 'original' ORDER BY id ASC");
    $stmt_orig->execute([$id]);
    $orig_items = $stmt_orig->fetchAll(PDO::FETCH_ASSOC);

    $stmt_corr = $pdo->prepare("SELECT * FROM pos_correction_items WHERE request_id = ? AND item_type = 'corrected' ORDER BY id ASC");
    $stmt_corr->execute([$id]);
    $corr_items = $stmt_corr->fetchAll(PDO::FETCH_ASSOC);

    // Run stock validation check
    list($is_stock_valid, $stock_message, $stock_details) = validateCorrectionStock($pdo, $id);

    echo json_encode([
        'status' => 'success',
        'request' => $req,
        'original_items' => $orig_items,
        'corrected_items' => $corr_items,
        'stock_validation' => [
            'is_valid' => $is_stock_valid,
            'message' => $stock_message,
            'details' => $stock_details
        ]
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
