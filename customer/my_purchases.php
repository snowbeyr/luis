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
        return in_array($st, ['out_for_delivery', 'to_receive', 'shipping', 'delivery'], true);
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
    </style>
</head>
<body>

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
                        <li><a class="dropdown-item py-2 small d-flex align-items-center gap-2 text-danger" href="../logout.php"><i class="bi bi-box-arrow-right"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Container (Shopee-Style Layout) -->
    <div class="container py-4">
        <div class="row g-4">
            
            <!-- Left Sidebar -->
            <div class="col-lg-3 col-md-4">
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
                        <a href="profile.php" class="sidebar-menu-link">
                            <i class="bi bi-person fs-5"></i>
                            <span>My Account</span>
                        </a>
                        <a href="my_purchases.php" class="sidebar-menu-link active">
                            <i class="bi bi-bag-check fs-5"></i>
                            <span>My Purchase</span>
                        </a>
                        <a href="../logout.php" class="sidebar-menu-link text-danger mt-2">
                            <i class="bi bi-box-arrow-right fs-5"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Right Content Area -->
            <div class="col-lg-9 col-md-8">
                
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
                        } elseif ($rawStatus === 'completed') {
                            $badgeClass = 'badge-completed';
                            $statusLabel = 'COMPLETED';
                        } else {
                            $badgeClass = 'badge-cancelled';
                            $statusLabel = strtoupper($rawStatus);
                        }

                        // Decode cart items
                        $cartItems = json_decode($order['cart'] ?? '[]', true);
                        if (!is_array($cartItems)) {
                            $cartItems = [];
                        }
                        ?>
                        <div class="order-card">
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
                                <?php foreach ($cartItems as $item): ?>
                                    <?php
                                    $pName = $item['name'] ?? ($item['product_name'] ?? 'Hardware Item');
                                    $pQty = (int) ($item['qty'] ?? 1);
                                    $pPrice = (float) ($item['price'] ?? 0);
                                    $pImg = !empty($item['image']) ? getProductImageSrc($item['image']) : '../assets/images/no-image.png';
                                    ?>
                                    <div class="order-item-row">
                                        <img src="<?php echo htmlspecialchars($pImg, ENT_QUOTES, 'UTF-8'); ?>"
                                             alt="<?php echo htmlspecialchars($pName, ENT_QUOTES, 'UTF-8'); ?>"
                                             class="order-item-img"
                                             onerror="this.src='../assets/images/no-image.png';">
                                        
                                        <div class="flex-grow-1 overflow-hidden">
                                            <h6 class="fw-bold mb-1 text-truncate text-dark"><?php echo htmlspecialchars($pName, ENT_QUOTES, 'UTF-8'); ?></h6>
                                            <div class="text-muted small">
                                                Quantity: <span class="fw-semibold text-dark">x<?php echo $pQty; ?></span>
                                            </div>
                                        </div>

                                        <div class="text-end">
                                            <div class="fw-bold text-dark fs-6">₱<?php echo number_format($pPrice * $pQty, 2); ?></div>
                                            <small class="text-muted">₱<?php echo number_format($pPrice, 2); ?> each</small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
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
                                        <?php if ($rawStatus === 'completed' && !$hasEx): ?>
                                            <a href="customerService.php?order_id=<?php echo $order['id']; ?>" class="btn btn-outline-primary btn-sm fw-semibold">
                                                <i class="bi bi-arrow-repeat me-1"></i> Return / Exchange
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($rawStatus === 'pending'): ?>
                                            <a href="customerService.php?order_id=<?php echo $order['id']; ?>" class="btn btn-outline-danger btn-sm fw-semibold">
                                                <i class="bi bi-x-circle me-1"></i> Cancel Request
                                            </a>
                                        <?php endif; ?>

                                        <button type="button" class="btn btn-navy btn-sm" onclick='viewOrderSummary(<?php echo json_encode($order); ?>)'>
                                            <i class="bi bi-receipt me-1"></i> Order Details
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <!-- Order Details Modal -->
    <div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title fw-bold" id="modalOrderTitle">Order Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4" id="modalOrderBody">
                    <!-- Populated dynamically via JS -->
                </div>
                <div class="modal-footer border-top py-2">
                    <button type="button" class="btn btn-secondary btn-sm px-4" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function viewOrderSummary(order) {
            const modalTitle = document.getElementById('modalOrderTitle');
            const modalBody = document.getElementById('modalOrderBody');

            modalTitle.innerText = `Order #${order.id} Summary`;

            let cart = [];
            try {
                cart = typeof order.cart === 'string' ? JSON.parse(order.cart) : order.cart;
            } catch (e) {
                cart = [];
            }

            let itemsHtml = '';
            if (Array.isArray(cart) && cart.length > 0) {
                cart.forEach(item => {
                    const price = parseFloat(item.price || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
                    const total = (parseFloat(item.price || 0) * parseInt(item.qty || 1)).toLocaleString(undefined, {minimumFractionDigits: 2});
                    itemsHtml += `
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <div>
                                <strong class="d-block text-dark">${item.name || item.product_name || 'Item'}</strong>
                                <small class="text-muted">₱${price} x ${item.qty || 1}</small>
                            </div>
                            <span class="fw-bold text-dark">₱${total}</span>
                        </div>
                    `;
                });
            } else {
                itemsHtml = '<p class="text-muted small">No item details available.</p>';
            }

            const totalAmount = parseFloat(order.total_amount || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
            const paidAmount = parseFloat(order.paid_amount || 0).toLocaleString(undefined, {minimumFractionDigits: 2});

            modalBody.innerHTML = `
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3">
                            <span class="text-muted small d-block">Recipient Info</span>
                            <strong class="text-dark">${order.fullname || ''} ${order.surname || ''}</strong>
                            <div class="small text-muted mt-1">📞 ${order.contact_number || 'N/A'}</div>
                            <div class="small text-muted">📧 ${order.email || 'N/A'}</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3">
                            <span class="text-muted small d-block">Delivery Address</span>
                            <strong class="text-dark">${order.full_address || 'N/A'}</strong>
                            <div class="small text-muted mt-1">Landmark: ${order.landmark || 'None'}</div>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <h6 class="fw-bold mb-2">Purchased Items</h6>
                    <div class="p-3 border rounded-3 bg-white">
                        ${itemsHtml}
                    </div>
                </div>

                <div class="p-3 bg-light rounded-3 d-flex justify-content-between align-items-center">
                    <div>
                        <span class="d-block text-muted small">Payment Method: <strong>${(order.payment_method || 'COD').toUpperCase()}</strong></span>
                        ${order.ref_no ? `<span class="d-block text-muted small">Ref No: <strong>${order.ref_no}</strong></span>` : ''}
                    </div>
                    <div class="text-end">
                        <span class="text-muted small d-block">Grand Total</span>
                        <h4 class="fw-bold text-danger mb-0">₱${totalAmount}</h4>
                    </div>
                </div>
            `;

            const modal = new bootstrap.Modal(document.getElementById('orderDetailsModal'));
            modal.show();
        }
    </script>
</body>
</html>
