<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/image.php';

if (empty($_SESSION['user_id'])) {
    header("Location: ../login.php?auth=1");
    exit();
}

$user_id = (int) $_SESSION['user_id'];

// Fetch current user details
try {
    $user_stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $user_stmt->execute([':id' => $user_id]);
    $currentUser = $user_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentUser) {
        session_destroy();
        header("Location: ../login.php?auth=1");
        exit();
    }
} catch (PDOException $e) {
    error_log("My Purchases user fetch error: " . $e->getMessage());
    die("Database error occurred.");
}

$customerEmail = trim((string) ($currentUser['email'] ?? ''));
$customerName  = trim((string) ($currentUser['fullname'] ?? $currentUser['username']));
$profileImage  = $currentUser['profile_image'] ?? '';

// Ensure delivered_at column exists in orders table
try {
    $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivered_at TIMESTAMP NULL");
} catch (Throwable $e) {
    error_log("My Purchases ensure delivered_at error: " . $e->getMessage());
}

// 72-hour (3 days) Auto-Complete: Automatically mark delivered orders as completed if older than 72 hours
try {
    $autoCompleteOrdersStmt = $pdo->prepare("
        UPDATE orders 
        SET status = 'completed' 
        WHERE LOWER(TRIM(status)) = 'delivered' 
          AND delivered_at IS NOT NULL 
          AND delivered_at <= (NOW() - INTERVAL '72 hours')
    ");
    $autoCompleteOrdersStmt->execute();
} catch (Throwable $e) {
    error_log("My Purchases Auto-complete error: " . $e->getMessage());
}

// Handle Customer Order Confirmation: "Order Received" -> Status becomes "completed"
$flashMessage = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_order_received') {
    $confirmOrderId = (int) ($_POST['order_id'] ?? 0);
    if ($confirmOrderId > 0) {
        $chkOrder = $pdo->prepare("
            SELECT id, status, fullname 
            FROM orders 
            WHERE id = ? AND LOWER(TRIM(email)) = LOWER(?)
            LIMIT 1
        ");
        $chkOrder->execute([$confirmOrderId, $customerEmail]);
        $orderToConfirm = $chkOrder->fetch(PDO::FETCH_ASSOC);

        if ($orderToConfirm) {
            $currSt = strtolower(trim((string)($orderToConfirm['status'] ?? '')));
            if ($currSt === 'delivered') {
                $updStmt = $pdo->prepare("UPDATE orders SET status = 'completed' WHERE id = ?");
                $updStmt->execute([$confirmOrderId]);

                try {
                    $auditStmt = $pdo->prepare("
                        INSERT INTO audit_log (user_id, username, role, action, details, ip_address) 
                        VALUES (?, ?, 'customer', 'Order Received', ?, ?)
                    ");
                    $auditStmt->execute([
                        $user_id,
                        $customerName,
                        "Customer confirmed receipt for Order #$confirmOrderId. Status marked as Completed.",
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]);
                } catch (Throwable $e) {}

                $_SESSION['flash_msg'] = "Thank you! Order #$confirmOrderId has been confirmed as received and marked as Completed.";
                $_SESSION['flash_type'] = "success";
                header("Location: my_purchases.php?tab=completed#order-" . $confirmOrderId);
                exit();
            } else {
                $_SESSION['flash_msg'] = "Order #$confirmOrderId cannot be marked as received because its status is " . strtoupper($currSt) . ".";
                $_SESSION['flash_type'] = "warning";
                header("Location: my_purchases.php#order-" . $confirmOrderId);
                exit();
            }
        }
    }
}

if (!empty($_SESSION['flash_msg'])) {
    $flashMessage = $_SESSION['flash_msg'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// Active Tab Filter (default: all)
$activeTab = strtolower(trim((string) ($_GET['tab'] ?? 'all')));
$allowedTabs = ['all', 'to_ship', 'to_receive', 'completed', 'cancelled', 'exchange'];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'all';
}

// Fetch all orders of this customer
$orders = [];
$exchangeRequests = [];
try {
    // Orders query by email
    $order_stmt = $pdo->prepare("
        SELECT * FROM orders 
        WHERE LOWER(TRIM(email)) = LOWER(:email)
        ORDER BY id DESC
    ");
    $order_stmt->execute([':email' => $customerEmail]);
    $allOrders = $order_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch exchange/return requests by customer email
    $req_stmt = $pdo->prepare("
        SELECT * FROM customer_requests 
        WHERE LOWER(TRIM(account)) = LOWER(:email)
        ORDER BY id DESC
    ");
    $req_stmt->execute([':email' => $customerEmail]);
    $allRequests = $req_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Map exchange requests by order_number
    $exchangeOrderIds = [];
    foreach ($allRequests as $req) {
        $type = strtolower(trim($req['request_type'] ?? ''));
        if (in_array($type, ['exchange', 'return'], true)) {
            $exchangeRequests[(int) $req['order_number']][] = $req;
            $exchangeOrderIds[] = (int) $req['order_number'];
        }
    }

    // Helper: Normalize item name & photo based on whether it is a main item or a variant
    if (!function_exists('formatOrderItemDetails')) {
        function formatOrderItemDetails(
            ?string $rawProductName,
            ?string $rawVariantName,
            ?string $mainProductName,
            ?string $mainProductImage,
            ?string $variantImage,
            ?int $variantId = null
        ): array {
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

            $rawProductName = $dedupe((string)$rawProductName);
            $rawVariantName = $dedupe((string)$rawVariantName);
            $mainProductName = $dedupe((string)$mainProductName);
            $mainProductImage = trim((string)$mainProductImage);
            $variantImage = trim((string)$variantImage);

            $isVariant = (!empty($variantId) || $rawVariantName !== '' || $variantImage !== '');

            // 1. Resolve Image:
            // Kapag variant item, gamitin ang variant_image kung available; kung wala, fallback sa main product image.
            // Kapag main item, main product image ang gagamitin.
            $imageRaw = '';
            if ($isVariant && !empty($variantImage)) {
                $imageRaw = $variantImage;
            } elseif (!empty($mainProductImage)) {
                $imageRaw = $mainProductImage;
            }

            $finalImage = !empty($imageRaw) ? getProductImageSrc($imageRaw) : '../assets/images/no-image.png';

            // 2. Resolve Clean Name:
            if (!$isVariant) {
                // Main item: ipakita ang main item name
                $finalName = $rawProductName !== '' ? $rawProductName : ($mainProductName !== '' ? $mainProductName : 'Hardware Item');
            } else {
                // Variant item: alisin ang duplicate formatting gaya ng "Gravel (1 Elf) (Gravel (1 Elf))"
                if ($rawVariantName === '') {
                    $finalName = $rawProductName !== '' ? $rawProductName : 'Hardware Item';
                } else {
                    if ($rawProductName === '') {
                        $finalName = $rawVariantName;
                    } elseif (strcasecmp($rawProductName, $rawVariantName) === 0) {
                        $finalName = $rawVariantName;
                    } elseif (stripos($rawProductName, $rawVariantName) !== false) {
                        $finalName = $rawProductName;
                    } elseif (!empty($mainProductName) && stripos($rawVariantName, $mainProductName) !== false) {
                        $finalName = $rawVariantName;
                    } else {
                        $cleanedRaw = preg_replace('/\s*\([^)]*\)$/', '', $rawProductName);
                        if (stripos($rawVariantName, $cleanedRaw) !== false) {
                            $finalName = $rawVariantName;
                        } else {
                            $finalName = $rawProductName . ' (' . $rawVariantName . ')';
                        }
                    }
                }
            }

            $finalName = $dedupe($finalName);

            return [
                'name' => $finalName,
                'image' => $finalImage
            ];
        }
    }

    // Fetch all items from order_items table for these orders
    $orderIds = array_column($allOrders, 'id');
    $dbItemsByOrder = [];
    if (!empty($orderIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));
        $oiStmt = $pdo->prepare("
            SELECT oi.*, 
                   p.image AS product_image,
                   p.product_name AS main_product_name,
                   pv.variant_image,
                   pv.variant_name AS pv_variant_name
            FROM order_items oi 
            LEFT JOIN products p ON oi.product_id = p.id 
            LEFT JOIN product_variants pv ON oi.variant_id = pv.id
            WHERE oi.order_id IN ($inPlaceholders)
            ORDER BY oi.id ASC
        ");
        $oiStmt->execute($orderIds);
        while ($oiRow = $oiStmt->fetch(PDO::FETCH_ASSOC)) {
            $dbItemsByOrder[(int)$oiRow['order_id']][] = $oiRow;
        }
    }

    // Pre-process and normalize order items
    foreach ($allOrders as &$orderRef) {
        $oid = (int) $orderRef['id'];
        $parsedItems = [];

        // 1. Check order_items table first
        if (!empty($dbItemsByOrder[$oid])) {
            foreach ($dbItemsByOrder[$oid] as $dbItem) {
                $vId = !empty($dbItem['variant_id']) ? (int)$dbItem['variant_id'] : null;
                $rawVName = trim((string)($dbItem['variant_name'] ?? ($dbItem['pv_variant_name'] ?? '')));

                $details = formatOrderItemDetails(
                    $dbItem['product_name'] ?? '',
                    $rawVName,
                    $dbItem['main_product_name'] ?? '',
                    $dbItem['product_image'] ?? '',
                    $dbItem['variant_image'] ?? '',
                    $vId
                );

                $pName = $details['name'];
                $pImg  = $details['image'];
                $pQty = (int) ($dbItem['qty'] ?? 1);
                if ($pQty <= 0) $pQty = 1;
                $pPrice = (float) ($dbItem['price'] ?? 0);
                $pSubtotal = (float) ($dbItem['subtotal'] ?? ($pPrice * $pQty));

                $parsedItems[] = [
                    'name' => $pName,
                    'qty' => $pQty,
                    'price' => $pPrice,
                    'price_formatted' => number_format($pPrice, 2),
                    'subtotal' => $pSubtotal,
                    'subtotal_formatted' => number_format($pSubtotal, 2),
                    'image' => $pImg
                ];
            }
        }

        // 2. If order_items was empty, parse cart JSON
        if (empty($parsedItems)) {
            $cartRaw = $orderRef['cart'] ?? '';
            $cartObj = is_string($cartRaw) ? json_decode($cartRaw, true) : $cartRaw;
            if (is_string($cartObj)) {
                $cartObj = json_decode($cartObj, true);
            }
            if (is_array($cartObj)) {
                foreach ($cartObj as $cItem) {
                    if (!is_array($cItem)) continue;
                    $vId = !empty($cItem['variant_id']) ? (int)$cItem['variant_id'] : null;
                    $rawVName = trim((string)($cItem['variant_name'] ?? ''));
                    $cImg = trim((string)($cItem['image'] ?? ($cItem['product_image'] ?? '')));

                    $details = formatOrderItemDetails(
                        $cItem['name'] ?? ($cItem['product_name'] ?? ($cItem['title'] ?? 'Hardware Item')),
                        $rawVName,
                        '',
                        $cImg,
                        $cImg,
                        $vId
                    );

                    $pName = $details['name'];
                    $pImg  = $details['image'];
                    $pQty = (int) ($cItem['qty'] ?? ($cItem['quantity'] ?? 1));
                    if ($pQty <= 0) $pQty = 1;
                    $pPrice = (float) ($cItem['price'] ?? ($cItem['unit_price'] ?? 0));
                    $pSubtotal = $pPrice * $pQty;

                    $parsedItems[] = [
                        'name' => $pName,
                        'qty' => $pQty,
                        'price' => $pPrice,
                        'price_formatted' => number_format($pPrice, 2),
                        'subtotal' => $pSubtotal,
                        'subtotal_formatted' => number_format($pSubtotal, 2),
                        'image' => $pImg
                    ];
                }
            }
        }

        $orderRef['has_exchange'] = isset($exchangeRequests[(int) $orderRef['id']]);
        $orderRef['parsed_items'] = $parsedItems;
        $orderRef['proof_resolved'] = !empty($orderRef['proof_of_payment']) ? getProductImageSrc($orderRef['proof_of_payment']) : '';
    }
    unset($orderRef);
} catch (PDOException $e) {
    error_log("My Purchases load error: " . $e->getMessage());
    $allOrders = [];
}

// Helper: categorize order
function isOrderInTab(array $order, string $tab, bool $hasExchange): bool {
    $st = strtolower(trim((string)($order['status'] ?? '')));
    if ($tab === 'all') {
        return true;
    }
    if ($tab === 'to_ship') {
        return in_array($st, ['pending', 'approved', 'preparing'], true);
    }
    if ($tab === 'to_receive') {
        return in_array($st, ['out_for_delivery', 'delivered', 'to_receive', 'shipping', 'delivery'], true);
    }
    if ($tab === 'completed') {
        return ($st === 'completed');
    }
    if ($tab === 'cancelled') {
        return in_array($st, ['cancelled', 'canceled', 'declined'], true);
    }
    if ($tab === 'exchange') {
        return $hasExchange || in_array($st, ['exchange', 'return', 'returned', 'refund'], true);
    }
    return false;
}

// Compute tab counts
$tabCounts = [
    'all'        => count($allOrders),
    'to_ship'    => 0,
    'to_receive' => 0,
    'completed'  => 0,
    'cancelled'  => 0,
    'exchange'   => 0
];

foreach ($allOrders as $o) {
    $hasEx = isset($exchangeRequests[(int) $o['id']]);
    foreach (['to_ship', 'to_receive', 'completed', 'cancelled', 'exchange'] as $tabKey) {
        if (isOrderInTab($o, $tabKey, $hasEx)) {
            $tabCounts[$tabKey]++;
        }
    }
}

// Filter orders based on active tab
$filteredOrders = [];
foreach ($allOrders as $o) {
    $hasEx = isset($exchangeRequests[(int) $o['id']]);
    if (isOrderInTab($o, $activeTab, $hasEx)) {
        $filteredOrders[] = $o;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Purchases - R. Taipan Hardware</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f6f7fb;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        .navbar-custom {
            background: #051c3c;
            padding: 14px 0;
        }

        /* Shopee-style Sidebar */
        .sidebar-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #eef0f4;
            padding: 24px 20px;
        }

        .user-avatar-sm {
            width: 48px;
            height: 48px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #e2e8f0;
        }

        .sidebar-menu-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 8px;
            color: #4b5563;
            text-decoration: none;
            font-size: 0.92rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .sidebar-menu-link:hover {
            background-color: #f1f5f9;
            color: #051c3c;
        }

        .sidebar-menu-link.active {
            background-color: #051c3c;
            color: #fff;
            font-weight: 600;
        }

        .sidebar-menu-link.active i {
            color: #ffc107;
        }

        /* Category Tabs */
        .purchase-tabs-container {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #eef0f4;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .purchase-nav {
            display: flex;
            overflow-x: auto;
            white-space: nowrap;
            margin: 0;
            padding: 0;
            list-style: none;
            border-bottom: 2px solid #f1f5f9;
            scrollbar-width: thin;
        }

        .purchase-nav-item {
            flex: 1;
            text-align: center;
        }

        .purchase-nav-link {
            display: block;
            padding: 15px 18px;
            color: #64748b;
            text-decoration: none;
            font-size: 0.92rem;
            font-weight: 600;
            position: relative;
            transition: all 0.2s ease;
        }

        .purchase-nav-link:hover {
            color: #051c3c;
            background: #f8fafc;
        }

        .purchase-nav-link.active {
            color: #051c3c;
        }

        .purchase-nav-link.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 3px;
            background: #051c3c;
            border-radius: 3px 3px 0 0;
        }

        /* Order Card */
        .order-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #eef0f4;
            margin-bottom: 18px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .order-card:hover {
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
        }

        .order-card-header {
            background: #fafbfc;
            padding: 14px 20px;
            border-bottom: 1px solid #edf0f5;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .order-item-row {
            padding: 16px 20px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .order-item-img {
            width: 72px;
            height: 72px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: #fff;
            flex-shrink: 0;
        }

        .order-card-footer {
            padding: 16px 20px;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
        }

        /* Status Badges */
        .badge-to-ship {
            background: #fef3c7;
            color: #92400e;
            font-weight: 600;
        }

        .badge-to-receive {
            background: #e0f2fe;
            color: #0369a1;
            font-weight: 600;
        }

        .badge-delivered {
            background: #dbeafe;
            color: #1e40af;
            font-weight: 600;
        }

        .badge-completed {
            background: #dcfce7;
            color: #15803d;
            font-weight: 600;
        }

        .badge-cancelled {
            background: #fee2e2;
            color: #b91c1c;
            font-weight: 600;
        }

        .badge-exchange {
            background: #ede9fe;
            color: #6d28d9;
            font-weight: 600;
        }

        .btn-navy {
            background: #051c3c;
            color: #fff;
            font-weight: 600;
        }

        .btn-navy:hover {
            background: #0b3267;
            color: #fff;
        }

        /* Shopee-Style Order Stepper */
        .order-stepper {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            position: relative;
            margin: 15px 0 20px 0;
            padding: 0 10px;
        }

        .stepper-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 2;
            flex: 1;
        }

        .stepper-icon {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #f1f5f9;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            margin-bottom: 6px;
            border: 3px solid #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }

        .stepper-step.completed .stepper-icon {
            background: #10b981;
            color: #fff;
        }

        .stepper-step.active .stepper-icon {
            background: #051c3c;
            color: #ffc107;
            box-shadow: 0 0 0 4px rgba(5, 28, 60, 0.15);
        }

        .stepper-label {
            font-size: 0.72rem;
            color: #64748b;
            font-weight: 500;
            text-align: center;
            line-height: 1.25;
            max-width: 80px;
        }

        .stepper-step.completed .stepper-label,
        .stepper-step.active .stepper-label {
            color: #051c3c;
            font-weight: 700;
        }

        .stepper-track {
            position: absolute;
            top: 19px;
            left: 8.33%;
            right: 8.33%;
            height: 3px;
            background: #e2e8f0;
            z-index: 1;
        }

        .stepper-track-fill {
            height: 100%;
            background: #10b981;
            transition: width 0.3s ease;
        }

        /* Shopee Order Details Cards */
        .order-detail-box {
            background: #fff;
            border: 1px solid #eaedf1;
            border-radius: 12px;
            padding: 16px 18px;
            margin-bottom: 14px;
        }

        .delivery-ribbon-box {
            border-left: 3px solid #ef4444;
            background: #fffbfb;
        }

        .receipt-item-row {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 0;
            border-bottom: 1px dashed #e2e8f0;
        }

        .receipt-item-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .receipt-item-img {
            width: 58px;
            height: 58px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #fff;
            flex-shrink: 0;
        }

        /* Official POS Thermal Receipt Preview */
        .thermal-preview {
            width: 80mm;
            max-width: 100%;
            margin: auto;
            padding: 18px 14px;
            background: #fff;
            font-family: 'Courier New', Courier, monospace;
            border: 1px solid #eee;
            color: #000;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
            line-height: 1.35;
        }

        .thermal-preview .line {
            border-top: 1px dashed #000;
            margin: 8px 0;
        }

        @media print {
            body * {
                visibility: hidden;
            }
            #orderDetailsModal, #orderDetailsModal *,
            #receiptModal, #receiptModal * {
                visibility: visible;
            }
            #orderDetailsModal, #receiptModal {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                margin: 0;
                padding: 0;
            }
            .modal-footer, .btn-close, .modal-backdrop {
                display: none !important;
            }
        }

        /* Shopee-style Desktop Layout: Fixed Sidebar & Right-Side Only Scrollable */
        @media (min-width: 768px) {
            html, body {
                height: 100%;
                margin: 0;
                overflow: hidden;
            }

            .purchases-page-wrapper {
                display: flex;
                flex-direction: column;
                height: 100vh;
                height: 100dvh;
                overflow: hidden;
            }

            .navbar-custom {
                flex-shrink: 0;
            }

            .purchases-main-container {
                flex: 1 1 auto;
                height: calc(100vh - 66px);
                height: calc(100dvh - 66px);
                overflow: hidden;
                display: flex;
                flex-direction: column;
                padding-top: 1.25rem !important;
                padding-bottom: 0 !important;
            }

            .purchases-row {
                flex: 1 1 auto;
                height: 100%;
                overflow: hidden;
            }

            .purchases-sidebar-col {
                height: 100%;
                overflow-y: auto;
                flex-shrink: 0;
            }

            .purchases-sidebar-col .sidebar-card {
                position: sticky;
                top: 0;
            }

            .purchases-content-col {
                height: 100%;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                padding-right: 10px;
                padding-bottom: 45px;
            }

            .purchase-tabs-container {
                position: sticky;
                top: 0;
                z-index: 20;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            }
        }

        /* Custom subtle scrollbar para sa right content panel */
        .custom-scroll::-webkit-scrollbar {
            width: 7px;
        }
        .custom-scroll::-webkit-scrollbar-track {
            background: transparent;
        }
        .custom-scroll::-webkit-scrollbar-thumb {
            background-color: #cbd5e1;
            border-radius: 10px;
        }
        .custom-scroll::-webkit-scrollbar-thumb:hover {
            background-color: #94a3b8;
        }
    </style>
</head>
<body class="bg-light">

<div class="purchases-page-wrapper">
    <!-- Header Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="../login.php">
                <img src="../assets/logo.jpg" style="width:36px; height:36px; border-radius:50%; border:2px solid white;">
                <strong>R. Taipan Hardware</strong>
            </a>

            <div class="ms-auto d-flex align-items-center gap-3">
                <a href="../login.php" class="btn btn-outline-light btn-sm fw-semibold">
                    <i class="bi bi-arrow-left me-1"></i> Back to Shop
                </a>
                <a href="cart.php" class="btn btn-warning btn-sm position-relative fw-semibold">
                    🛒 Cart
                    <?php
                    $cartCount = isset($_SESSION['cart']) ? count($_SESSION['cart']) : 0;
                    if ($cartCount > 0) {
                        echo '<span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">' . $cartCount . '</span>';
                    }
                    ?>
                </a>

                <!-- User Dropdown Menu -->
                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle gap-2"
                        id="userNavMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if (!empty($profileImage)): ?>
                            <img src="<?php echo htmlspecialchars($profileImage, ENT_QUOTES, 'UTF-8'); ?>"
                                alt="Avatar" class="rounded-circle border border-light" style="width: 30px; height: 30px; object-fit: cover;">
                        <?php else: ?>
                            <div class="rounded-circle bg-white text-dark d-flex align-items-center justify-content-center"
                                style="width: 30px; height: 30px; font-size: 0.85rem;">
                                <i class="bi bi-person-fill text-secondary"></i>
                            </div>
                        <?php endif; ?>
                        <span class="small fw-semibold d-none d-sm-inline"><?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" aria-labelledby="userNavMenu" style="border-radius: 12px; min-width: 175px;">
                        <li><a class="dropdown-item py-2 small d-flex align-items-center gap-2" href="profile.php"><i class="bi bi-person text-secondary"></i>My Account</a></li>
                        <li><a class="dropdown-item py-2 small d-flex align-items-center gap-2 active bg-light text-dark fw-bold" href="my_purchases.php"><i class="bi bi-bag-check text-primary"></i>My Purchase</a></li>
                        <li><hr class="dropdown-divider my-1"></li>
                        <li><a class="dropdown-item py-2 small d-flex align-items-center gap-2 text-danger" href="../logout.php" onclick="return confirm('Are you sure you want to logout?');"><i class="bi bi-box-arrow-right"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Container (Shopee-Style Layout: Fixed Sidebar & Right Content Scrollable) -->
    <div class="container purchases-main-container py-3 py-md-4">
        <div class="row g-4 purchases-row">
            
            <!-- Left Sidebar (Fixed) -->
            <div class="col-lg-3 col-md-4 purchases-sidebar-col">
                <div class="sidebar-card">
                    <!-- User Profile Info -->
                    <div class="d-flex align-items-center gap-3 pb-3 mb-3 border-bottom">
                        <?php if (!empty($profileImage)): ?>
                            <img src="<?php echo htmlspecialchars($profileImage, ENT_QUOTES, 'UTF-8'); ?>"
                                alt="Avatar" class="user-avatar-sm">
                        <?php else: ?>
                            <div class="user-avatar-sm bg-light text-secondary d-flex align-items-center justify-content-center fs-4">
                                <i class="bi bi-person-fill"></i>
                            </div>
                        <?php endif; ?>

                        <div class="overflow-hidden">
                            <h6 class="fw-bold mb-0 text-truncate"><?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?></h6>
                            <a href="profile.php" class="text-muted small text-decoration-none">
                                <i class="bi bi-pencil-square me-1"></i>Edit Profile
                            </a>
                        </div>
                    </div>

                    <!-- Navigation Links -->
                    <div class="d-flex flex-column gap-1">
                        <a href="profile.php?tab=profile" class="sidebar-menu-link">
                            <i class="bi bi-person fs-5"></i>
                            <span>My Profile</span>
                        </a>
                        <a href="profile.php?tab=password" class="sidebar-menu-link">
                            <i class="bi bi-shield-lock fs-5"></i>
                            <span>Change Password</span>
                        </a>
                        <a href="profile.php?tab=address" class="sidebar-menu-link">
                            <i class="bi bi-geo-alt fs-5"></i>
                            <span>My Addresses</span>
                        </a>
                        <a href="my_purchases.php" class="sidebar-menu-link active">
                            <i class="bi bi-bag-check fs-5"></i>
                            <span>My Purchase</span>
                        </a>
                        <a href="../logout.php" class="sidebar-menu-link text-danger mt-2" onclick="return confirm('Are you sure you want to logout?');">
                            <i class="bi bi-box-arrow-right fs-5"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Right Content Area (Scrollable Orders Feed) -->
            <div class="col-lg-9 col-md-8 purchases-content-col custom-scroll">
                
                <!-- Category Tabs (All, To Ship, To Receive, Completed, Cancelled, Exchange Items) -->
                <div class="purchase-tabs-container">
                    <ul class="purchase-nav">
                        <li class="purchase-nav-item">
                            <a href="?tab=all" class="purchase-nav-link <?php echo $activeTab === 'all' ? 'active' : ''; ?>">
                                All <?php echo $tabCounts['all'] > 0 ? "({$tabCounts['all']})" : ''; ?>
                            </a>
                        </li>
                        <li class="purchase-nav-item">
                            <a href="?tab=to_ship" class="purchase-nav-link <?php echo $activeTab === 'to_ship' ? 'active' : ''; ?>">
                                To Ship <?php echo $tabCounts['to_ship'] > 0 ? "({$tabCounts['to_ship']})" : ''; ?>
                            </a>
                        </li>
                        <li class="purchase-nav-item">
                            <a href="?tab=to_receive" class="purchase-nav-link <?php echo $activeTab === 'to_receive' ? 'active' : ''; ?>">
                                To Receive <?php echo $tabCounts['to_receive'] > 0 ? "({$tabCounts['to_receive']})" : ''; ?>
                            </a>
                        </li>
                        <li class="purchase-nav-item">
                            <a href="?tab=completed" class="purchase-nav-link <?php echo $activeTab === 'completed' ? 'active' : ''; ?>">
                                Completed <?php echo $tabCounts['completed'] > 0 ? "({$tabCounts['completed']})" : ''; ?>
                            </a>
                        </li>
                        <li class="purchase-nav-item">
                            <a href="?tab=cancelled" class="purchase-nav-link <?php echo $activeTab === 'cancelled' ? 'active' : ''; ?>">
                                Cancelled <?php echo $tabCounts['cancelled'] > 0 ? "({$tabCounts['cancelled']})" : ''; ?>
                            </a>
                        </li>
                        <li class="purchase-nav-item">
                            <a href="?tab=exchange" class="purchase-nav-link <?php echo $activeTab === 'exchange' ? 'active' : ''; ?>">
                                Exchange Items <?php echo $tabCounts['exchange'] > 0 ? "({$tabCounts['exchange']})" : ''; ?>
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Flash message alert -->
                <?php if (!empty($flashMessage)): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($flashType); ?> alert-dismissible fade show mb-3 shadow-sm rounded-3" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($flashMessage); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Orders List -->
                <?php if (empty($filteredOrders)): ?>
                    <div class="text-center py-5 bg-white rounded-4 border p-4">
                        <div class="mb-3 text-secondary" style="font-size: 3.5rem;">
                            <i class="bi bi-bag-x"></i>
                        </div>
                        <h5 class="fw-bold text-dark">No orders found</h5>
                        <p class="text-muted small mb-4">You have no purchases under this category yet.</p>
                        <a href="../login.php" class="btn btn-navy px-4 py-2">
                            <i class="bi bi-cart-plus me-1"></i> Start Shopping
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($filteredOrders as $order): ?>
                        <?php
                        $rawStatus = strtolower(trim($order['status'] ?? 'pending'));
                        $hasEx = isset($exchangeRequests[(int) $order['id']]);

                        // Determine Badge Details
                        if ($hasEx) {
                            $badgeClass = 'badge-exchange';
                            $statusLabel = 'EXCHANGE REQUESTED';
                        } elseif (in_array($rawStatus, ['pending', 'approved', 'preparing'], true)) {
                            $badgeClass = 'badge-to-ship';
                            $statusLabel = $rawStatus === 'preparing' ? 'PREPARING ORDER' : ($rawStatus === 'approved' ? 'ORDER APPROVED' : 'PENDING APPROVAL');
                        } elseif ($rawStatus === 'out_for_delivery') {
                            $badgeClass = 'badge-to-receive';
                            $statusLabel = 'OUT FOR DELIVERY';
                        } elseif ($rawStatus === 'delivered') {
                            $badgeClass = 'badge-delivered';
                            $statusLabel = 'DELIVERED';
                        } elseif ($rawStatus === 'completed') {
                            $badgeClass = 'badge-completed';
                            $statusLabel = 'COMPLETED';
                        } else {
                            $badgeClass = 'badge-cancelled';
                            $statusLabel = strtoupper($rawStatus);
                        }

                        // Items parsed already
                        $itemsList = $order['parsed_items'] ?? [];
                        ?>
                        <div class="order-card" id="order-<?php echo $order['id']; ?>">
                            <!-- Card Header -->
                            <div class="order-card-header">
                                <div class="d-flex align-items-center gap-2">
                                    <strong class="text-dark">Order #<?php echo $order['id']; ?></strong>
                                    <span class="text-muted small">•</span>
                                    <span class="text-muted small">
                                        <i class="bi bi-clock me-1"></i><?php echo date('M d, Y h:i A', strtotime($order['order_date'])); ?>
                                    </span>
                                </div>
                                <span class="badge <?php echo $badgeClass; ?> px-3 py-2 rounded-pill small">
                                    <?php echo $statusLabel; ?>
                                </span>
                            </div>

                            <!-- Cart Items List -->
                            <div>
                                <?php if (!empty($itemsList)): ?>
                                    <?php foreach ($itemsList as $item): ?>
                                        <div class="order-item-row">
                                            <img src="<?php echo htmlspecialchars($item['image'], ENT_QUOTES, 'UTF-8'); ?>"
                                                 alt="<?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                 class="order-item-img"
                                                 onerror="this.src='../assets/images/no-image.png';">
                                            
                                            <div class="flex-grow-1 overflow-hidden">
                                                <h6 class="fw-bold mb-1 text-truncate text-dark"><?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?></h6>
                                                <div class="text-muted small">
                                                    Quantity: <span class="fw-semibold text-dark">x<?php echo $item['qty']; ?></span>
                                                </div>
                                            </div>

                                            <div class="text-end">
                                                <div class="fw-bold text-dark fs-6">₱<?php echo $item['subtotal_formatted']; ?></div>
                                                <small class="text-muted">₱<?php echo $item['price_formatted']; ?> each</small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="p-3 text-muted small">Hardware Items Order</div>
                                <?php endif; ?>
                            </div>

                            <!-- Card Footer -->
                            <div class="order-card-footer">
                                <div>
                                    <div class="small text-muted mb-1">
                                        Payment: <strong class="text-dark"><?php echo htmlspecialchars(strtoupper($order['payment_method'] ?? 'COD'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <?php if (!empty($order['payment_scheme'])): ?>
                                            (<?php echo htmlspecialchars(ucwords($order['payment_scheme']), ENT_QUOTES, 'UTF-8'); ?>)
                                        <?php endif; ?>
                                    </div>
                                    <div class="small text-muted">
                                        Delivery to: <span class="text-dark"><?php echo htmlspecialchars($order['full_address'] ?? 'Customer Address', ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                </div>

                                <div class="text-end d-flex align-items-center gap-3">
                                    <div>
                                        <span class="small text-muted">Order Total: </span>
                                        <span class="fw-bold text-danger fs-5">₱<?php echo number_format((float) ($order['total_amount'] ?? 0), 2); ?></span>
                                    </div>

                                    <!-- Action Buttons -->
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if ($rawStatus === 'delivered'): ?>
                                            <button type="button" class="btn btn-success btn-sm fw-semibold shadow-sm" onclick="openConfirmReceivedModal(<?php echo (int)$order['id']; ?>)">
                                                <i class="bi bi-box-seam me-1"></i> Order Received
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($rawStatus === 'completed' && !$hasEx): ?>
                                            <a href="customerService.php?order_id=<?php echo $order['id']; ?>&type=exchange&email=<?php echo urlencode($customerEmail); ?>" class="btn btn-outline-primary btn-sm fw-semibold">
                                                <i class="bi bi-arrow-repeat me-1"></i> Return / Exchange
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($rawStatus === 'pending'): ?>
                                            <a href="customerService.php?order_id=<?php echo $order['id']; ?>&type=cancel&email=<?php echo urlencode($customerEmail); ?>" class="btn btn-outline-danger btn-sm fw-semibold">
                                                <i class="bi bi-x-circle me-1"></i> Cancel Request
                                            </a>
                                        <?php endif; ?>

                                        <!-- Safe JSON payload container for zero escaping bugs -->
                                        <script type="application/json" id="order-data-<?php echo $order['id']; ?>">
                                            <?php echo json_encode($order, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>
                                        </script>
                                        <button type="button" class="btn btn-navy btn-sm" onclick="openOrderModal(<?php echo (int)$order['id']; ?>)">
                                            <i class="bi bi-info-circle me-1"></i> Order Details
                                        </button>
        
                                    </div>
                                </div>
                            </div>

                            <?php if ($rawStatus === 'delivered'): ?>
                                <div class="px-4 pb-3">
                                    <div class="alert alert-primary py-2 px-3 mb-0 d-flex align-items-center justify-content-between flex-wrap gap-2 small rounded-3 border-0 bg-primary-subtle text-primary-emphasis">
                                        <div>
                                            <i class="bi bi-box-seam-fill me-1 text-primary"></i>
                                            <strong>Package Delivered:</strong> Please click <strong>Order Received</strong> to confirm your package.
                                            <div class="text-secondary mt-1" style="font-size: 11.5px;">
                                                <i class="bi bi-clock-history me-1 text-primary"></i>Note: If not confirmed within 3 days (72 hours), this order will automatically be marked as Completed.
                                            </div>
                                        </div>
                                        <div>
                                            <button type="button" class="btn btn-success btn-sm fw-bold px-3 py-1 shadow-sm" onclick="openConfirmReceivedModal(<?php echo (int)$order['id']; ?>)">
                                                <i class="bi bi-check2-circle me-1"></i> Order Received
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

    <!-- Confirm Order Received Modal -->
    <div class="modal fade" id="confirmReceivedModal" tabindex="-1" aria-labelledby="confirmReceivedModalLabel" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 460px;">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header border-0 bg-success text-white py-3 px-4">
                    <h5 class="modal-title fw-bold fs-6 d-flex align-items-center gap-2" id="confirmReceivedModalLabel">
                        <i class="bi bi-check2-circle fs-5"></i> Confirm Order Received
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 text-center bg-white">
                    <div class="mb-3">
                        <div class="d-inline-flex align-items-center justify-content-center bg-success-subtle text-success rounded-circle shadow-sm" style="width: 65px; height: 65px;">
                            <i class="bi bi-box-seam-fill fs-2"></i>
                        </div>
                    </div>
                    <h6 class="fw-bold text-dark mb-2">Order <span id="confirmReceivedDisplayId" class="text-primary"></span></h6>
                    <p class="text-secondary mb-3" style="font-size: 15px; line-height: 1.5;">
                        Are you sure you have received your order? Once confirmed, your order status will be marked as Completed.
                    </p>
                    <div class="p-2 px-3 bg-light rounded-3 text-start small border">
                        <div class="text-muted" style="font-size: 12px;">
                            <i class="bi bi-info-circle-fill text-primary me-1"></i>
                            <strong>Note:</strong> If not confirmed within 3 days (72 hours), this order will automatically be marked as Completed.
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light py-3 px-4 d-flex justify-content-end gap-2">
                    <form method="POST" id="confirmReceivedForm" class="d-flex align-items-center gap-2 w-100 justify-content-end">
                        <input type="hidden" name="action" value="confirm_order_received">
                        <input type="hidden" name="order_id" id="confirmReceivedOrderId" value="">
                        <button type="button" class="btn btn-secondary btn-sm px-3 fw-semibold" data-bs-dismiss="modal">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-success btn-sm px-3 fw-bold shadow-sm">
                            <i class="bi bi-check-lg me-1"></i> Yes I Received My Order
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Shopee-Style Order Details Modal -->
    <div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
                <!-- Header with Brand Accent -->
                <div class="modal-header border-0 text-white p-3 px-4" style="background: #051c3c;">
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-receipt-cutoff text-warning fs-5"></i>
                            <h5 class="modal-title fw-bold text-white mb-0">Order #<span id="mdlOrderId"></span> Details</h5>
                        </div>
                        <small class="text-white-50" id="mdlOrderSubtitle"></small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4 pt-3" id="modalOrderBody">
                    <!-- Progress Stepper (Shopee Style) -->
                    <div id="mdlStepperContainer" class="mb-4">
                        <div class="order-stepper">
                            <div class="stepper-track">
                                <div class="stepper-track-fill" id="mdlStepperFill" style="width: 0%;"></div>
                            </div>
                            <div class="stepper-step" id="step-placed">
                                <div class="stepper-icon"><i class="bi bi-bag"></i></div>
                                <div class="stepper-label">Order Placed</div>
                            </div>
                            <div class="stepper-step" id="step-confirmed">
                                <div class="stepper-icon"><i class="bi bi-wallet2"></i></div>
                                <div class="stepper-label">Payment Confirmed</div>
                            </div>
                            <div class="stepper-step" id="step-preparing">
                                <div class="stepper-icon"><i class="bi bi-box-seam"></i></div>
                                <div class="stepper-label">Preparing</div>
                            </div>
                            <div class="stepper-step" id="step-shipping">
                                <div class="stepper-icon"><i class="bi bi-truck"></i></div>
                                <div class="stepper-label">Out for Delivery</div>
                            </div>
                            <div class="stepper-step" id="step-delivered">
                                <div class="stepper-icon"><i class="bi bi-box-seam"></i></div>
                                <div class="stepper-label">Delivered</div>
                            </div>
                            <div class="stepper-step" id="step-completed">
                                <div class="stepper-icon"><i class="bi bi-check2-circle"></i></div>
                                <div class="stepper-label">Completed</div>
                            </div>
                        </div>
                        <div id="mdlStatusAlert" class="text-center small py-2 px-3 rounded-3 mt-2 fw-semibold"></div>
                    </div>

                    <!-- Shopee-Style Delivery Address -->
                    <div class="order-detail-box delivery-ribbon-box mb-3">
                        <div class="d-flex align-items-center gap-2 text-danger fw-bold mb-2 small text-uppercase">
                            <i class="bi bi-geo-alt-fill fs-6"></i> Delivery Address
                        </div>
                        <div class="delivery-address-content">
                            <div class="d-flex align-items-center gap-2">
                                <strong class="text-dark fs-6" id="mdlCustomerName"></strong>
                                <span class="text-muted small">•</span>
                                <span class="text-secondary small fw-semibold" id="mdlContactNumber"></span>
                            </div>
                            <div class="text-dark small mt-1" id="mdlFullAddress"></div>
                            <div class="text-muted small mt-1" id="mdlLandmarkContainer">
                                <i class="bi bi-pin-map me-1 text-secondary"></i>Landmark: <span id="mdlLandmark"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Purchased Items (Shopee Shop List Style) -->
                    <div class="order-detail-box mb-3 p-0 overflow-hidden">
                        <div class="p-3 bg-light border-bottom d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-shop text-primary fs-6"></i>
                                <strong class="text-dark small">R. Taipan Hardware Official Store</strong>
                            </div>
                            <span class="badge bg-white text-secondary border small fw-normal">Standard Delivery</span>
                        </div>
                        <div class="p-3" id="mdlItemsContainer">
                            <!-- Items injected dynamically -->
                        </div>
                    </div>

                    <!-- Payment Breakdown (Invoice Style) -->
                    <div class="order-detail-box mb-0">
                        <div class="row g-3">
                            <div class="col-md-6" id="mdlPaymentInfoCol">
                                <div class="text-muted small text-uppercase fw-bold mb-2">Payment Information</div>
                                <div class="small mb-1">
                                    Method: <strong class="text-dark" id="mdlPaymentMethod"></strong>
                                    <span id="mdlPaymentSchemeBadge"></span>
                                </div>
                                <div class="small mb-1" id="mdlRefNoRow">
                                    Ref No: <span class="fw-semibold text-primary" id="mdlRefNo"></span>
                                </div>
                                <div class="small" id="mdlProofRow"></div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small text-uppercase fw-bold mb-2 text-md-end">Payment Summary</div>
                                <table class="table table-sm table-borderless mb-0 small">
                                    <tbody>
                                        <tr>
                                            <td class="text-muted">Merchandise Subtotal:</td>
                                            <td class="text-end fw-semibold text-dark">₱<span id="mdlSubtotal">0.00</span></td>
                                        </tr>
                                        <tr class="border-top">
                                            <td class="fw-bold text-dark pt-2">Order Total:</td>
                                            <td class="text-end pt-2">
                                                <span class="fw-bold text-danger fs-5">₱<span id="mdlTotalAmount">0.00</span></span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Footer with View Receipt & Close -->
                <div class="modal-footer bg-light border-top py-2 px-4 d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-warning btn-sm fw-semibold d-flex align-items-center gap-2 text-dark shadow-sm" onclick="viewThermalReceipt()">
                            <i class="bi bi-receipt fs-6"></i> View Receipt
                        </button>
                        <a href="#" id="mdlDirectActionBtn" class="btn btn-sm fw-semibold d-none"></a>
                    </div>
                    <button type="button" class="btn btn-navy btn-sm px-4" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Official Thermal Receipt Modal -->
    <div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header border-0 bg-white py-3 px-4 d-flex justify-content-between align-items-center">
                    <h5 class="modal-title fw-bold text-dark mb-0">Order Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body bg-dark py-4 text-center">
                    <div class="thermal-preview text-start" id="thermalReceiptContent">
                        <!-- Populated dynamically via JS -->
                    </div>
                </div>
                <div class="modal-footer bg-white border-top py-2 px-3 d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-success btn-sm fw-semibold d-flex align-items-center gap-1 shadow-sm" id="btnDownloadReceipt" onclick="downloadThermalReceipt()">
                            <i class="bi bi-download"></i> Download
                        </button>
                    
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <script>
        let currentActiveOrder = null;

        function openOrderModal(orderId) {
            const jsonEl = document.getElementById('order-data-' + orderId);
            if (!jsonEl) return;
            let order;
            try {
                order = JSON.parse(jsonEl.textContent);
            } catch(e) {
                console.error("Failed to parse order JSON", e);
                return;
            }

            currentActiveOrder = order;

            // Header info
            document.getElementById('mdlOrderId').textContent = order.id;
            if (order.order_date) {
                const d = new Date(order.order_date.replace(' ', 'T'));
                const dateStr = !isNaN(d.getTime()) ? d.toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit'}) : order.order_date;
                document.getElementById('mdlOrderSubtitle').textContent = `Placed on ${dateStr}`;
            } else {
                document.getElementById('mdlOrderSubtitle').textContent = '';
            }

            // Stepper logic
            const status = (order.status || 'pending').toLowerCase().trim();
            const steps = ['placed', 'confirmed', 'preparing', 'shipping', 'delivered', 'completed'];
            steps.forEach(s => {
                const el = document.getElementById('step-' + s);
                if (el) el.className = 'stepper-step';
            });
            
            const stepperFill = document.getElementById('mdlStepperFill');
            const alertBox = document.getElementById('mdlStatusAlert');

            if (status === 'completed') {
                stepperFill.style.width = '100%';
                steps.forEach(s => {
                    const el = document.getElementById('step-' + s);
                    if (el) el.classList.add('completed');
                });
                alertBox.className = 'text-center small py-2 px-3 rounded-3 mt-2 fw-semibold bg-success-subtle text-success border border-success-subtle';
                alertBox.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Order successfully delivered and completed!';
            } else if (status === 'delivered') {
                stepperFill.style.width = '80%';
                ['placed', 'confirmed', 'preparing', 'shipping'].forEach(s => {
                    const el = document.getElementById('step-' + s);
                    if (el) el.classList.add('completed');
                });
                const elDelivered = document.getElementById('step-delivered');
                if (elDelivered) elDelivered.classList.add('active');
                alertBox.className = 'text-center small py-2 px-3 rounded-3 mt-2 fw-semibold bg-primary-subtle text-primary border border-primary-subtle';
                alertBox.innerHTML = '<i class="bi bi-box-seam me-1"></i> Your package has been delivered! Please inspect items and click "Order Received".';
            } else if (status === 'out_for_delivery') {
                stepperFill.style.width = '60%';
                ['placed', 'confirmed', 'preparing'].forEach(s => {
                    const el = document.getElementById('step-' + s);
                    if (el) el.classList.add('completed');
                });
                const elShipping = document.getElementById('step-shipping');
                if (elShipping) elShipping.classList.add('active');
                alertBox.className = 'text-center small py-2 px-3 rounded-3 mt-2 fw-semibold bg-info-subtle text-primary border border-info-subtle';
                alertBox.innerHTML = '<i class="bi bi-truck me-1"></i> Your parcel is out for delivery! Please keep your lines open.';
            } else if (status === 'preparing') {
                stepperFill.style.width = '40%';
                ['placed', 'confirmed'].forEach(s => {
                    const el = document.getElementById('step-' + s);
                    if (el) el.classList.add('completed');
                });
                const elPrep = document.getElementById('step-preparing');
                if (elPrep) elPrep.classList.add('active');
                alertBox.className = 'text-center small py-2 px-3 rounded-3 mt-2 fw-semibold bg-warning-subtle text-warning-emphasis border border-warning-subtle';
                alertBox.innerHTML = '<i class="bi bi-box-seam me-1"></i> The hardware store is currently preparing your ordered items.';
            } else if (status === 'approved') {
                stepperFill.style.width = '20%';
                const elPlaced = document.getElementById('step-placed');
                if (elPlaced) elPlaced.classList.add('completed');
                const elConfirmed = document.getElementById('step-confirmed');
                if (elConfirmed) elConfirmed.classList.add('active');
                alertBox.className = 'text-center small py-2 px-3 rounded-3 mt-2 fw-semibold bg-warning-subtle text-warning-emphasis border border-warning-subtle';
                alertBox.innerHTML = '<i class="bi bi-check2 me-1"></i> Order approved! Scheduled for preparation.';
            } else if (status === 'cancelled' || status === 'declined') {
                stepperFill.style.width = '0%';
                alertBox.className = 'text-center small py-2 px-3 rounded-3 mt-2 fw-semibold bg-danger-subtle text-danger border border-danger-subtle';
                alertBox.innerHTML = `<i class="bi bi-x-circle-fill me-1"></i> This order was ${status.toUpperCase()}.`;
            } else {
                // Pending
                stepperFill.style.width = '4%';
                const elPlaced = document.getElementById('step-placed');
                if (elPlaced) elPlaced.classList.add('active');
                alertBox.className = 'text-center small py-2 px-3 rounded-3 mt-2 fw-semibold bg-light text-secondary border';
                alertBox.innerHTML = '<i class="bi bi-clock-history me-1"></i> Order placed. Waiting for store confirmation.';
            }

            // Customer Delivery Address
            const fullName = `${order.fullname || ''} ${order.surname || ''}`.trim() || 'Customer';
            document.getElementById('mdlCustomerName').textContent = fullName;
            document.getElementById('mdlContactNumber').textContent = order.contact_number ? `(+63) ${order.contact_number}` : 'N/A';
            document.getElementById('mdlFullAddress').textContent = order.full_address || 'No delivery address provided';
            
            if (order.landmark && order.landmark.trim() !== '') {
                document.getElementById('mdlLandmarkContainer').style.display = 'block';
                document.getElementById('mdlLandmark').textContent = order.landmark;
            } else {
                document.getElementById('mdlLandmarkContainer').style.display = 'none';
            }

            // Items list
            const itemsContainer = document.getElementById('mdlItemsContainer');
            itemsContainer.innerHTML = '';

            const items = Array.isArray(order.parsed_items) ? order.parsed_items : [];
            if (items.length > 0) {
                items.forEach(it => {
                    const row = document.createElement('div');
                    row.className = 'receipt-item-row';
                    row.innerHTML = `
                        <img src="${it.image || '../assets/images/no-image.png'}" 
                             class="receipt-item-img" 
                             alt="${it.name || 'Item'}" 
                             onerror="this.src='../assets/images/no-image.png';">
                        <div class="flex-grow-1 overflow-hidden">
                            <h6 class="fw-bold mb-1 text-truncate text-dark" style="font-size: 0.95rem;">${it.name || 'Hardware Item'}</h6>
                            <div class="text-muted small">
                                Unit Price: ₱${it.price_formatted} &nbsp;•&nbsp; 
                                <span class="badge bg-light text-dark border">x${it.qty}</span>
                            </div>
                        </div>
                        <div class="text-end">
                            <strong class="text-dark fs-6">₱${it.subtotal_formatted}</strong>
                        </div>
                    `;
                    itemsContainer.appendChild(row);
                });
            } else {
                itemsContainer.innerHTML = '<div class="text-muted small py-3 text-center">No individual item details recorded.</div>';
            }

            // Payment details
            const method = (order.payment_method || 'COD').toUpperCase();
            document.getElementById('mdlPaymentMethod').textContent = method;
            
            const schemeBadge = document.getElementById('mdlPaymentSchemeBadge');
            if (order.payment_scheme) {
                schemeBadge.innerHTML = `<span class="badge bg-secondary ms-1 small">${order.payment_scheme}</span>`;
            } else {
                schemeBadge.innerHTML = '';
            }

            const refRow = document.getElementById('mdlRefNoRow');
            if (order.ref_no && order.ref_no.trim() !== '') {
                refRow.style.display = 'block';
                document.getElementById('mdlRefNo').textContent = order.ref_no;
            } else {
                refRow.style.display = 'none';
            }

            const proofRow = document.getElementById('mdlProofRow');
            if (order.proof_resolved && order.proof_resolved.trim() !== '') {
                proofRow.style.display = 'block';
                proofRow.innerHTML = `
                    <a href="${order.proof_resolved}" target="_blank" class="btn btn-outline-primary btn-sm mt-2">
                        <i class="bi bi-image me-1"></i> View Payment Receipt Proof
                    </a>
                `;
            } else {
                proofRow.style.display = 'none';
                proofRow.innerHTML = '';
            }

            // Totals
            const totalNum = parseFloat(order.total_amount || 0);
            const totalFormatted = totalNum.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('mdlSubtotal').textContent = totalFormatted;
            document.getElementById('mdlTotalAmount').textContent = totalFormatted;

            // Direct Action Button in modal footer
            const directBtn = document.getElementById('mdlDirectActionBtn');
            if (directBtn) {
                const custEmail = <?php echo json_encode($customerEmail); ?>;
                if (status === 'delivered') {
                    directBtn.className = 'btn btn-success btn-sm fw-semibold';
                    directBtn.href = '#';
                    directBtn.onclick = function(e) {
                        e.preventDefault();
                        const curModal = bootstrap.Modal.getInstance(document.getElementById('orderDetailsModal'));
                        if (curModal) curModal.hide();
                        openConfirmReceivedModal(order.id);
                    };
                    directBtn.innerHTML = '<i class="bi bi-box-seam me-1"></i> Order Received';
                    directBtn.classList.remove('d-none');
                } else if (status === 'pending') {
                    directBtn.className = 'btn btn-outline-danger btn-sm fw-semibold';
                    directBtn.href = `customerService.php?order_id=${order.id}&type=cancel&email=${encodeURIComponent(custEmail)}`;
                    directBtn.onclick = null;
                    directBtn.innerHTML = '<i class="bi bi-x-circle me-1"></i> Cancel Request';
                    directBtn.classList.remove('d-none');
                } else if (status === 'completed' && !order.has_exchange) {
                    directBtn.className = 'btn btn-outline-primary btn-sm fw-semibold';
                    directBtn.href = `customerService.php?order_id=${order.id}&type=exchange&email=${encodeURIComponent(custEmail)}`;
                    directBtn.onclick = null;
                    directBtn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> Return / Exchange';
                    directBtn.classList.remove('d-none');
                } else {
                    directBtn.classList.add('d-none');
                }
            }

            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('orderDetailsModal'));
            modal.show();
        }

        // Open Confirm Order Received Modal
        function openConfirmReceivedModal(orderId) {
            document.getElementById('confirmReceivedOrderId').value = orderId;
            document.getElementById('confirmReceivedDisplayId').textContent = '#' + orderId;
            const modalEl = document.getElementById('confirmReceivedModal');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        }

        // Generate Thermal Receipt matching POS design
        function generateThermalReceipt(order) {
            const orderStatus = (order.status || 'pending').toUpperCase();
            const items = Array.isArray(order.parsed_items) ? order.parsed_items : [];

            let itemsHtml = '';
            items.forEach(it => {
                itemsHtml += `
                    <tr>
                        <td style="padding: 2px 0; vertical-align: top;">${it.name} (x${it.qty})</td>
                        <td align="right" style="padding: 2px 0; vertical-align: top; white-space: nowrap;">₱${it.subtotal_formatted}</td>
                    </tr>
                `;
            });

            const paymentMethodName = (order.payment_method || 'COD').toUpperCase();
            const paymentScheme = (order.payment_scheme || '').toLowerCase();
            const totalAmt = parseFloat(order.total_amount || 0);
            let paymentBreakdownHtml = '';

            if (paymentMethodName === 'COD' || paymentMethodName === 'CASH ON DELIVERY (COD)') {
                paymentBreakdownHtml = `
                    <div class="d-flex justify-content-between small fw-bold mb-1">
                        <span>TOTAL AMOUNT:</span>
                        <span>₱${totalAmt.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                    </div>
                `;
            } else if (totalAmt >= 5000 && paymentScheme.includes('50')) {
                const downpayment = parseFloat(order.paid_amount || (totalAmt * 0.5));
                const balance = totalAmt - downpayment;
                paymentBreakdownHtml = `
                    <div class="d-flex justify-content-between small fw-bold mb-1">
                        <span>TOTAL AMOUNT:</span>
                        <span>₱${totalAmt.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                    </div>
                    <div class="d-flex justify-content-between small fw-bold mb-1">
                        <span>PAID VIA ${paymentMethodName}:</span>
                        <span>₱${downpayment.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                    </div>
                    <div class="line"></div>
                    <div class="d-flex justify-content-between small fw-bold">
                        <span>PAY UPON DELIVERY:</span>
                        <span>₱${balance.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                    </div>
                `;
            } else if (paymentMethodName === 'GCASH' || paymentMethodName === 'MAYA') {
                const paidAmount = parseFloat(order.paid_amount || totalAmt);
                paymentBreakdownHtml = `
                    <div class="d-flex justify-content-between small fw-bold mb-1">
                        <span>TOTAL AMOUNT:</span>
                        <span>₱${totalAmt.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                    </div>
                    <div class="d-flex justify-content-between small fw-bold mb-1">
                        <span>PAID VIA ${paymentMethodName}:</span>
                        <span>₱${paidAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                    </div>
                `;
                if (order.ref_no) {
                    paymentBreakdownHtml += `<div class="small mt-1">Ref No: <b>${order.ref_no}</b></div>`;
                }
            } else {
                paymentBreakdownHtml = `
                    <div class="d-flex justify-content-between small fw-bold">
                        <span>TOTAL AMOUNT:</span>
                        <span>₱${totalAmt.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                    </div>
                `;
            }

            const customerName = `${order.fullname || ''} ${order.surname || ''}`.trim() || 'Customer';

            const fullReceiptHtml = `
                <div class="text-center mb-2">
                    <h5 class="fw-bold my-0 text-uppercase" style="letter-spacing: 1px;">R. TAIPAN HARDWARE</h5>
                    <small>190 Cabrera Rd. Sitio Burol Brgy.</small><br>
                    <small>San Juan Taytay, Rizal</small><br>
                    <small>Contact: 0907 456 5632</small>
                    <div class="line"></div>
                    <small><b>OFFICIAL RECEIPT (ONLINE ORDER)</b></small>
                    <div class="line"></div>
                </div>

                <p class="small mb-1"><b>Order ID:</b> #${order.id}</p>
                <p class="small mb-1"><b>Date:</b> ${order.order_date}</p>
                <p class="small mb-1"><b>Customer:</b> ${customerName}</p>
                <p class="small mb-1"><b>Contact:</b> ${order.contact_number || 'N/A'}</p>
                <p class="small mb-1"><b>Address:</b> ${order.full_address || 'N/A'}</p>
                <p class="small mb-2"><b>Landmark:</b> ${order.landmark || 'N/A'}</p>

                <div class="line"></div>

                <table width="100%" class="small mb-2">
                    <tbody>
                        ${itemsHtml}
                    </tbody>
                </table>

                <div class="line"></div>

                ${paymentBreakdownHtml}

                <div class="line mt-2"></div>

                <div class="text-start small"><b>Mode of Payment:</b> ${paymentMethodName}</div>

                <div class="text-center mt-3">
                    <small class="fw-bold">STATUS: ${orderStatus}</small>
                </div>
            `;

            document.getElementById('thermalReceiptContent').innerHTML = fullReceiptHtml;
        }

        // Open Receipt from Order Details Modal
        function viewThermalReceipt() {
            if (!currentActiveOrder) return;
            generateThermalReceipt(currentActiveOrder);

            const orderModalEl = document.getElementById('orderDetailsModal');
            const orderModal = bootstrap.Modal.getInstance(orderModalEl);
            if (orderModal) {
                orderModal.hide();
            }

            const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
            receiptModal.show();
        }

        // Open Receipt directly from order card
        function openReceiptDirectly(orderId) {
            const jsonEl = document.getElementById('order-data-' + orderId);
            if (!jsonEl) return;
            let order;
            try {
                order = JSON.parse(jsonEl.textContent);
            } catch(e) {
                console.error("Failed to parse order JSON", e);
                return;
            }

            currentActiveOrder = order;
            generateThermalReceipt(order);

            const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
            receiptModal.show();
        }

        // Download receipt as PNG image
        function downloadThermalReceipt() {
            const receiptElement = document.getElementById('thermalReceiptContent');
            if (!receiptElement) return;

            const downloadBtn = document.getElementById('btnDownloadReceipt');
            const originalContent = downloadBtn.innerHTML;
            downloadBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Generating...';
            downloadBtn.disabled = true;

            html2canvas(receiptElement, {
                scale: 3,
                backgroundColor: '#ffffff',
                useCORS: true,
                logging: false
            }).then(canvas => {
                const orderId = (currentActiveOrder && currentActiveOrder.id) ? currentActiveOrder.id : 'order';
                const link = document.createElement('a');
                link.download = `RTaipan-Receipt-Order-#${orderId}.png`;
                link.href = canvas.toDataURL('image/png');
                link.click();

                downloadBtn.innerHTML = originalContent;
                downloadBtn.disabled = false;
            }).catch(err => {
                console.error("Receipt download failed:", err);
                downloadBtn.innerHTML = originalContent;
                downloadBtn.disabled = false;
                alert("Generating image failed. Opening print/save dialog instead.");
                printThermalReceipt();
            });
        }

        // Print thermal receipt in 80mm format
        function printThermalReceipt() {
            const receiptElement = document.getElementById('thermalReceiptContent');
            if (!receiptElement) return;
            
            const printWindow = window.open('', '_blank', 'width=450,height=650');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Official Receipt - Order #${currentActiveOrder ? currentActiveOrder.id : ''}</title>
                    <style>
                        @page { margin: 0; size: 80mm auto; }
                        body {
                            margin: 0;
                            padding: 8px;
                            background: #fff;
                            color: #000;
                            font-family: 'Courier New', Courier, monospace;
                            width: 78mm;
                            margin: auto;
                        }
                        .thermal-preview { width: 100% !important; border: none !important; padding: 0 !important; box-shadow: none !important; }
                        .line { border-top: 1px dashed #000; margin: 8px 0; }
                        .text-center { text-align: center; }
                        .text-start { text-align: left; }
                        .text-end { text-align: right; }
                        .fw-bold { font-weight: bold; }
                        .small { font-size: 12px; }
                        table { width: 100%; font-size: 12px; border-collapse: collapse; }
                        td { padding: 2px 0; }
                    </style>
                </head>
                <body>
                    ${receiptElement.outerHTML}
                    <script>
                        window.onload = function() {
                            window.print();
                            setTimeout(() => window.close(), 500);
                        };
                    <\/script>
                </body>
                </html>
            `);
            printWindow.document.close();
        }
    </script>
</body>
</html>
