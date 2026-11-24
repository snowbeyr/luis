<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/correction_functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'owner') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Owner access required.']);
    exit();
}

$owner_name = $_SESSION['username'] ?? 'Owner';
$owner_id = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
$request_id = (int)($_POST['request_id'] ?? 0);
$action = trim($_POST['action'] ?? '');

if ($request_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid correction request ID.']);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT * FROM pos_correction_requests WHERE id = ?");
    $stmt->execute([$request_id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        echo json_encode(['status' => 'error', 'message' => 'Correction request not found.']);
        exit();
    }

    if ($action === 'approve') {
        if ($req['status'] !== 'Pending Review') {
            echo json_encode(['status' => 'error', 'message' => 'Request is not in Pending Review status. Current: ' . $req['status']]);
            exit();
        }

        // 1. Validate Stock Availability
        list($is_stock_valid, $stock_msg, $details) = validateCorrectionStock($pdo, $request_id);
        if (!$is_stock_valid) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Stock Validation Failed: ' . $stock_msg,
                'stock_details' => $details
            ]);
            exit();
        }

        $diff = (float)$req['payment_difference'];

        // If no financial difference (diff == 0), automatically synchronize inventory & complete!
        if (abs($diff) < 0.001) {
            list($exec_success, $exec_msg) = executeCorrectionSettlement($pdo, $request_id, $owner_name, 'None (Even Exchange)', 'N/A', 0);
            if ($exec_success) {
                // Record owner review data
                $upd_rev = $pdo->prepare("UPDATE pos_correction_requests SET owner_id = ?, owner_name = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?");
                $upd_rev->execute([$owner_id > 0 ? $owner_id : null, $owner_name, $request_id]);

                echo json_encode([
                    'status' => 'success',
                    'message' => 'Correction request approved and automatically completed! Since there was no financial difference, inventory has been synchronized.',
                    'next_status' => 'Completed'
                ]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Approval settlement failed: ' . $exec_msg]);
            }
            exit();
        }

        // If financial difference exists, determine status
        $new_status = ($diff > 0) ? 'Pending Additional Payment' : 'Pending Refund';

        $stmt_app = $pdo->prepare("
            UPDATE pos_correction_requests 
            SET status = ?, owner_id = ?, owner_name = ?, reviewed_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt_app->execute([$new_status, $owner_id > 0 ? $owner_id : null, $owner_name, $request_id]);

        // Audit Log
        $owner_id = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
        if ($owner_id <= 0) {
            $stmt_u = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $stmt_u->execute([$owner_name]);
            $owner_id = (int)$stmt_u->fetchColumn();
            if ($owner_id <= 0) {
                $owner_id = (int)$pdo->query("SELECT id FROM users WHERE role = 'owner' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;
            }
        }
        $action_log = "POS Correction Approved";
        $diff_fmt = $diff > 0 ? "+₱" . number_format($diff, 2) : "-₱" . number_format(abs($diff), 2);
        $details_log = "Owner $owner_name approved Correction Request #$request_id for POS Receipt #{$req['transaction_id']}. Financial difference: $diff_fmt. Status set to: $new_status.";
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'N/A';

        $stmt_audit = $pdo->prepare("INSERT INTO audit_log (user_id, username, role, action, details, ip_address) VALUES (?, ?, 'owner', ?, ?, ?)");
        $stmt_audit->execute([$owner_id, $owner_name, $action_log, $details_log, $ip]);

        echo json_encode([
            'status' => 'success',
            'message' => "Correction request approved! Status updated to '$new_status'. Payment/refund can now be settled.",
            'next_status' => $new_status
        ]);
        exit();

    } elseif ($action === 'reject') {
        $rejection_reason = trim($_POST['rejection_reason'] ?? '');
        if (empty($rejection_reason)) {
            echo json_encode(['status' => 'error', 'message' => 'A rejection reason is required when rejecting a correction request.']);
            exit();
        }

        $stmt_rej = $pdo->prepare("
            UPDATE pos_correction_requests 
            SET status = 'Rejected', rejection_reason = ?, owner_id = ?, owner_name = ?, reviewed_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt_rej->execute([$rejection_reason, $owner_id > 0 ? $owner_id : null, $owner_name, $request_id]);

        // Unlock transaction
        $stmt_unl = $pdo->prepare("UPDATE pos_transaction SET correction_status = 'none' WHERE id = ?");
        $stmt_unl->execute([$req['transaction_id']]);

        // Audit Log
        $action_log = "POS Correction Rejected";
        $details_log = "Owner $owner_name rejected Correction Request #$request_id for POS Receipt #{$req['transaction_id']}. Reason: $rejection_reason";
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'N/A';

        $owner_id = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
        if ($owner_id <= 0) {
            $stmt_u = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $stmt_u->execute([$owner_name]);
            $owner_id = (int)$stmt_u->fetchColumn();
            if ($owner_id <= 0) {
                $owner_id = (int)$pdo->query("SELECT id FROM users WHERE role = 'owner' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;
            }
        }
        $stmt_audit = $pdo->prepare("INSERT INTO audit_log (user_id, username, role, action, details, ip_address) VALUES (?, ?, 'owner', ?, ?, ?)");
        $stmt_audit->execute([$owner_id, $owner_name, $action_log, $details_log, $ip]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Correction request has been rejected.',
            'next_status' => 'Rejected'
        ]);
        exit();

    } elseif ($action === 'settle') {
        // Owner directly settles
        $payment_method = trim($_POST['payment_method'] ?? 'Cash');
        $payment_reference = trim($_POST['payment_reference'] ?? '');
        $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : null;

        list($exec_success, $exec_msg) = executeCorrectionSettlement($pdo, $request_id, $owner_name, $payment_method, $payment_reference, $amount);
        if ($exec_success) {
            echo json_encode([
                'status' => 'success',
                'message' => $exec_msg,
                'next_status' => 'Completed'
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => $exec_msg]);
        }
        exit();

    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action specified.']);
        exit();
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Processing error: ' . $e->getMessage()]);
}
