<?php
session_start();
require_once __DIR__ . '/../config/db.php';

// 1. Check login
if (!isset($_SESSION['user_id'])) {
    die("Error: Admin is not logged in.");
}

if (isset($_GET['id']) && isset($_GET['status'])) {

    $id = (int) $_GET['id'];
    $new_status = strtolower(trim((string)$_GET['status']));
    $admin_id = (int) $_SESSION['user_id'];

    require_once __DIR__ . '/../includes/inventory_helpers.php';
    ensureCancellationSchema($pdo);

    // GUARD: Block status changes when a Pending cancellation request exists
    $chkCancel = $pdo->prepare("
        SELECT id FROM customer_requests
        WHERE order_number = ?
          AND LOWER(TRIM(request_type)) = 'cancel'
          AND (LOWER(TRIM(status)) = 'pending' OR status IS NULL)
        LIMIT 1
    ");
    $chkCancel->execute([(string)$id]);
    if ($chkCancel->fetchColumn()) {
        $_SESSION['app_modal'] = [
            'title' => 'Action Blocked',
            'message' => 'This order has a pending cancellation request. Please approve or decline the cancellation request before proceeding with any order status updates.',
            'type' => 'warning'
        ];
        header("Location: orders.php");
        exit();
    }

    // 2. Get order
    $stmtOrd = $pdo->prepare("SELECT status, cart FROM orders WHERE id = ?");
    $stmtOrd->execute([$id]);
    $order = $stmtOrd->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        die("Order not found");
    }

    $old_status = normalizeCancellationOrderStatus($order['status'] ?? '');
    if (in_array($old_status, ['cancelled', 'declined'], true)) {
        $_SESSION['app_modal'] = [
            'title' => 'Order Finalized',
            'message' => "Order #{$id} is already " . strtoupper($old_status) . " and cannot be modified.",
            'type' => 'warning'
        ];
        header("Location: orders.php");
        exit();
    }

    // =========================
    // UPDATE ORDER STATUS
    // =========================
    if ($new_status === 'approved') {
        $pdo->prepare("UPDATE orders SET status = ?, approved_by = ?, approved_at = COALESCE(approved_at, NOW()) WHERE id = ?")
            ->execute([$new_status, $admin_id, $id]);
    } elseif ($new_status === 'declined') {
        $pdo->prepare("UPDATE orders SET status = ?, declined_by = ? WHERE id = ?")
            ->execute([$new_status, $admin_id, $id]);
        restoreOrderInventorySafely($pdo, $id, (string)($_SESSION['username'] ?? 'Admin'), 'Order Declined Restoration');
    } elseif ($new_status === 'cancelled') {
        finalizeOrderCancellation($pdo, $id, [
            'mode' => 'manual',
            'admin_id' => $admin_id,
            'actor_name' => (string)($_SESSION['username'] ?? 'Admin'),
            'actor_role' => 'admin',
            'reason' => 'Cancelled via admin status update',
        ]);
    } else {
        $tsCol = getOrderStepTimestampColumn((string)$new_status);
        if ($tsCol !== null) {
            $pdo->prepare("UPDATE orders SET status = ?, {$tsCol} = NOW() WHERE id = ?")->execute([$new_status, $id]);
        } else {
            $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?")->execute([$new_status, $id]);
        }
    }

    header("Location: orders.php?success=1");
    exit();
} else {
    echo "Invalid request parameters.";
}
?>