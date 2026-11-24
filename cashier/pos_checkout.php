<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/shared_inventory_functions.php';
header("Content-Type: application/json");

if (!isset($_SESSION['role']) || $_SESSION['role'] != "cashier") {
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);
if (!$data || !isset($data['cart'])) {
    echo json_encode(["status" => "error", "message" => "Invalid request"]);
    exit();
}

$cart = $data['cart'];
$total = (float)$data['total'];
$paid = (float)$data['paid'];
$change = (float)$data['change'];
$payment_method = trim($data['payment_method'] ?? 'Cash');

// Backend Enforcement: POS / Walk-in transactions strictly accept CASH ONLY
if (strcasecmp($payment_method, 'Cash') !== 0) {
    echo json_encode([
        "status" => "error", 
        "message" => "POS / Walk-in transactions strictly accept CASH ONLY. Electronic and online payment methods (such as GCash or Maya) are not supported."
    ]);
    exit();
}

$cashier = $_SESSION['username'] ?? 'Cashier';
$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
$role = $_SESSION['role'];
$ip_address = $_SERVER['REMOTE_ADDR'] ?? 'N/A';

try {
    // Simulan ang Database Transaction para ligtas ang inventory at sales records
    $pdo->beginTransaction();

    // 1. I-save ang transaction sa pos_transaction table gamit ang PDO prepared statement (Strictly Cash)
    $stmt = $pdo->prepare("
        INSERT INTO pos_transaction (cashier_name, total_amount, amount_paid, change_amount, payment_method, actual_amount_received, payment_status) 
        VALUES (?, ?, ?, ?, 'Cash', ?, 'Fully Paid')
    ");
    $stmt->execute([$cashier, $total, $paid, $change, $total]);
    $transaction_id = $pdo->lastInsertId();

    // 2. I-loop ang cart para sa bawas stock at pag-save ng items
    foreach ($cart as $item) {
        $raw_id = $item['id'];
        $type = substr($raw_id, 0, 2);
        $id = (int)substr($raw_id, 2);
        $qty = (int)$item['qty'];
        $price = (float)$item['price'];
        $subtotal = $qty * $price;
        $name = $item['name'];

        if ($type == 'v_') {
            $var_id = $id;
            $stmt_p = $pdo->prepare("SELECT product_id FROM product_variants WHERE id = ?");
            $stmt_p->execute([$var_id]);
            $prod_id = $stmt_p->fetchColumn() ?: null;

            // Check if linked to shared inventory source
            $linked_source = getLinkedSourceInfo($pdo, $prod_id, $var_id);
            if ($linked_source) {
                $idemp = "pos_sale_{$transaction_id}_v_{$var_id}";
                list($ded_ok, $ded_msg, $new_stk) = deductSharedStock(
                    $pdo,
                    $linked_source['source_id'],
                    $prod_id,
                    $var_id,
                    $qty,
                    'POS Deduction',
                    $transaction_id,
                    $cashier,
                    "POS Sale #$transaction_id for {$linked_source['source_name']}",
                    $idemp
                );
                if (!$ded_ok) {
                    throw new Exception($ded_msg);
                }
            } else {
                // Standalone variant
                $updateStock = $pdo->prepare("UPDATE product_variants SET stock = stock - ? WHERE id = ?");
                $updateStock->execute([$qty, $id]);
            }

            // Insert POS transaction items para sa variant
            $insertItem = $pdo->prepare("INSERT INTO pos_transaction_items (transaction_id, variant_id, product_id, product_name, price, qty, subtotal) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insertItem->execute([$transaction_id, $id, $prod_id, $name, $price, $qty, $subtotal]);
        } else {
            $prod_id = $id;
            $linked_source = getLinkedSourceInfo($pdo, $prod_id, null);
            if ($linked_source) {
                $idemp = "pos_sale_{$transaction_id}_p_{$prod_id}";
                list($ded_ok, $ded_msg, $new_stk) = deductSharedStock(
                    $pdo,
                    $linked_source['source_id'],
                    $prod_id,
                    null,
                    $qty,
                    'POS Deduction',
                    $transaction_id,
                    $cashier,
                    "POS Sale #$transaction_id for {$linked_source['source_name']}",
                    $idemp
                );
                if (!$ded_ok) {
                    throw new Exception($ded_msg);
                }
            } else {
                // Standalone product
                $updateStock = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
                $updateStock->execute([$qty, $id]);
            }

            // Insert POS transaction items para sa main product
            $insertItem = $pdo->prepare("INSERT INTO pos_transaction_items (transaction_id, product_id, product_name, price, qty, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
            $insertItem->execute([$transaction_id, $id, $name, $price, $qty, $subtotal]);
        }
    }

    // 3. I-log ang audit trail (isinama ang user_id para hindi mag-error sa NOT NULL constraint)
    $action = "POS Checkout";
    $details = "Processed walk-in transaction #" . $transaction_id . ". Total Amount: ₱" . number_format($total, 2) . ", Paid: ₱" . number_format($paid, 2) . ", Change: ₱" . number_format($change, 2);
    
    $audit_stmt = $pdo->prepare("INSERT INTO audit_log (user_id, username, role, action, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    $audit_stmt->execute([$user_id > 0 ? $user_id : null, $cashier, $role, $action, $details, $ip_address]);

    // Kung walang naganap na error, i-commit ang lahat ng pagbabago sa database
    $pdo->commit();

    echo json_encode(["status" => "success", "transaction_id" => $transaction_id]);

} catch (Exception $e) {
    // Kung sakaling magka-error, ibalik sa dati ang database state
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>