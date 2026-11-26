<?php
require_once __DIR__ . '/../config/db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

$order_no = $_POST['order_no'];
$email = $_POST['email'];
$service = $_POST['service_type'];

// 1. CHECK ORDER
$query = mysqli_query($conn, "
    SELECT * FROM orders 
    WHERE id='$order_no' AND customer_email='$email'
");

if(mysqli_num_rows($query) == 0){
    echo json_encode([
        "success" => false,
        "message" => "Order not found or email does not match."
    ]);
    exit;
}

$order = mysqli_fetch_assoc($query);
$status = strtolower(trim($order['status']));
$payment_method = strtoupper(trim($order['payment_method']));
$payment_scheme = strtolower(trim($order['payment_scheme'] ?? ''));
$total_amount = floatval($order['total_amount'] ?? 0);

// Harangin kung ang order ay cancelled na
if ($status === 'cancelled') {
    echo json_encode([
        "success" => false,
        "message" => "This order has already been cancelled and cannot accept further requests."
    ]);
    exit;
}

// 2. VALIDATE SERVICE TYPE BASED ON STATUS
$status_lower = strtolower(trim($order['status']));
if ($status_lower === 'placed' || $status_lower === 'order placed') {
    $status_lower = 'pending';
}

if ($service == "Cancel") {
    $allowed_cancel_statuses = ['pending', 'approved', 'preparing'];

    if (!in_array($status_lower, $allowed_cancel_statuses)) {
        $reasonMsg = ($status_lower === 'delivered')
            ? "Cancellation is no longer available because your order has already been Delivered. Please use Return / Exchange or Customer Service for delivery issues."
            : "Cancellation is not allowed once the order reaches its current status: " . ucwords($order['status']) . ".";
        echo json_encode([
            "success" => false,
            "message" => $reasonMsg
        ]);
        exit;
    }
}

// 3. GET ORDER ITEMS
$items_query = mysqli_query($conn, "
    SELECT * FROM order_items 
    WHERE order_id='$order_no'
");

$html = "";

while($item = mysqli_fetch_assoc($items_query)){
    $html .= "
    <div class='border p-2 mb-2 rounded'>
        <strong>{$item['product_name']}</strong><br>
        Quantity: {$item['quantity']}<br>

        <label>Select Quantity</label>
        <input type='number' 
               name='items[{$item['id']}]' 
               min='0' 
               max='{$item['quantity']}' 
               class='form-control'>
    </div>
    ";
}

// 4. RETURN RESPONSE
echo json_encode([
    "success" => true,
    "order_id" => $order_no,
    "itemsHtml" => $html
]);