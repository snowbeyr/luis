<?php
session_start();
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cashier') {
    header("Location: ../login.php");
    exit();
}

$cashier_name = $_SESSION['username'] ?? 'Cashier';
$cashier_id = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
$cashier_display_name = (string)($_SESSION['fullname'] ?? $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Cashier');
$cashier_avatar = '';

if ($cashier_id > 0) {
    try {
        $u_stmt = $pdo->prepare("SELECT fullname, username, profile_image FROM users WHERE id = ? LIMIT 1");
        $u_stmt->execute([$cashier_id]);
        $u_row = $u_stmt->fetch(PDO::FETCH_ASSOC);
        if ($u_row) {
            if (!empty($u_row['fullname'])) {
                $cashier_display_name = $u_row['fullname'];
            } elseif (!empty($u_row['username'])) {
                $cashier_display_name = $u_row['username'];
            }
            if (!empty($u_row['profile_image'])) {
                if (stripos($u_row['profile_image'], 'data:image/') === 0) {
                    $cashier_avatar = $u_row['profile_image'];
                } else {
                    $photo_path = __DIR__ . '/../' . $u_row['profile_image'];
                    if (file_exists($photo_path)) {
                        $cashier_avatar = '../' . htmlspecialchars($u_row['profile_image']);
                    }
                }
            }
        }
    } catch (PDOException $e) {
        // fallback
    }
}
$filter_date = $_GET['filter_date'] ?? '';
$search_query = trim($_GET['search'] ?? '');
$req_category_filter = $_GET['req_category'] ?? 'all';

// 1. Fetch POS Transactions
$where_pos = "WHERE 1=1";
$params_pos = [];

if (!empty($filter_date)) {
    $where_pos .= " AND DATE(pt.transaction_date) = ?";
    $params_pos[] = $filter_date;
}

if (!empty($search_query)) {
    $where_pos .= " AND (CAST(pt.id AS TEXT) LIKE ? OR LOWER(pt.cashier_name) LIKE LOWER(?))";
    $params_pos[] = "%$search_query%";
    $params_pos[] = "%$search_query%";
}

$stmt_tx = $pdo->prepare("
    SELECT pt.*,
           (SELECT status FROM pos_correction_requests WHERE transaction_id = pt.id ORDER BY id DESC LIMIT 1) AS latest_correction_status,
           (SELECT id FROM pos_correction_requests WHERE transaction_id = pt.id ORDER BY id DESC LIMIT 1) AS latest_correction_id,
           (SELECT is_late_request FROM pos_correction_requests WHERE transaction_id = pt.id ORDER BY id DESC LIMIT 1) AS latest_is_late
    FROM pos_transaction pt
    $where_pos
    ORDER BY pt.id DESC
    LIMIT 100
");
$stmt_tx->execute($params_pos);
$transactions = $stmt_tx->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch My Correction Requests
$where_my_req = "(pcr.cashier_name = ? OR pcr.cashier_id = ?)";
$params_my_req = [$cashier_name, $cashier_id];

if ($req_category_filter === 'normal') {
    $where_my_req .= " AND (pcr.is_late_request = FALSE OR pcr.is_late_request IS NULL)";
} elseif ($req_category_filter === 'late') {
    $where_my_req .= " AND pcr.is_late_request = TRUE";
}

$stmt_my_req = $pdo->prepare("
    SELECT pcr.*, pt.transaction_date AS original_tx_date
    FROM pos_correction_requests pcr
    JOIN pos_transaction pt ON pcr.transaction_id = pt.id
    WHERE $where_my_req
    ORDER BY pcr.id DESC
");
$stmt_my_req->execute($params_my_req);
$my_requests = $stmt_my_req->fetchAll(PDO::FETCH_ASSOC);

// Metrics for cashier requests
$count_pending = 0;
$count_approved = 0;
$count_completed = 0;
$count_rejected = 0;
$count_late = 0;
foreach ($my_requests as $r) {
    if ($r['status'] === 'Pending Review') $count_pending++;
    elseif (in_array($r['status'], ['Approved', 'Pending Additional Payment', 'Pending Refund'])) $count_approved++;
    elseif ($r['status'] === 'Completed') $count_completed++;
    elseif ($r['status'] === 'Rejected') $count_rejected++;
    if (!empty($r['is_late_request'])) $count_late++;
}

// Online orders notification count
$notif_query = $pdo->query("SELECT COUNT(*) AS total FROM orders WHERE sent_to_cashier = 1");
$notif_data = $notif_query->fetch(PDO::FETCH_ASSOC);
$order_count = $notif_data['total'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sales History & Corrections | Cashier</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<style>
body { background: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
.navbar-custom { background: #051c3c !important; min-height: 72px; padding: 10px 20px; }
.user-dropdown-btn { transition: background .2s ease; cursor: pointer; }
.user-dropdown-btn:hover { background: rgba(255, 255, 255, 0.12) !important; }
.badge-status { font-size: 0.82rem; padding: 6px 10px; font-weight: 600; border-radius: 6px; }
.card-metric { border-radius: 12px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
.receipt-paper {
    width: 100%;
    max-width: 380px;
    margin: auto;
    padding: 16px;
    background: #fff;
    font-family: 'Courier New', Courier, monospace;
    color: #000;
    border: 1px solid #ddd;
    border-radius: 8px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}
.receipt-line { border-top: 1px dashed #444; margin: 10px 0; }
.diff-badge-pos { background: #e8f5e9; color: #2e7d32; font-weight: bold; padding: 4px 8px; border-radius: 4px; }
.diff-badge-neg { background: #ffebee; color: #c62828; font-weight: bold; padding: 4px 8px; border-radius: 4px; }
.diff-badge-zero { background: #f5f5f5; color: #616161; font-weight: bold; padding: 4px 8px; border-radius: 4px; }
.item-table th { background: #e9ecef; font-size: 0.85rem; }
.item-table td { font-size: 0.88rem; vertical-align: middle; }
.deadline-badge-active { background: #d1e7dd; color: #0f5132; font-size: 0.78rem; font-weight: 600; }
.deadline-badge-urgent { background: #fff3cd; color: #664d03; font-size: 0.78rem; font-weight: 600; }
.deadline-badge-expired { background: #f8d7da; color: #842029; font-size: 0.78rem; font-weight: 600; }
</style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-dark navbar-custom sticky-top">
<div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
        <img src="../assets/logo.jpg" style="width:36px;height:36px;border-radius:50%;border:2px solid white;" alt="Logo" onerror="this.src='../assets/no-image.jpg';">
        <h5 class="text-white fw-bold mb-0">R. Taipan Hardware <span class="fw-normal opacity-75 small">| Sales & Corrections</span></h5>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="dashboard.php" class="btn btn-outline-light btn-sm">
            <i class="bi bi-cart4 me-1"></i> POS Register
        </a>
        <a href="onlineOrders.php" class="btn btn-outline-light btn-sm position-relative">
            <i class="bi bi-globe me-1"></i> Online Orders
            <?php if($order_count>0): ?>
            <span class="badge bg-danger position-absolute top-0 start-100 translate-middle"><?= $order_count; ?></span>
            <?php endif; ?>
        </a>
        <div class="dropdown">
            <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle gap-2 px-2 py-1 rounded user-dropdown-btn" id="userMenuDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <?php if (!empty($cashier_avatar)): ?>
                    <img src="<?= htmlspecialchars($cashier_avatar, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile" class="rounded-circle border border-light" style="width: 32px; height: 32px; object-fit: cover;">
                <?php else: ?>
                    <div class="rounded-circle bg-white text-dark d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.95rem;">
                        <i class="bi bi-person-fill text-secondary"></i>
                    </div>
                <?php endif; ?>
                <span class="small fw-semibold text-white">
                    <?= htmlspecialchars($cashier_display_name, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" aria-labelledby="userMenuDropdown" style="border-radius: 12px; min-width: 185px;">
                <li class="px-3 py-2 border-bottom">
                    <span class="d-block small text-muted" style="font-size: 0.75rem;">Signed in as</span>
                    <strong class="small text-dark text-truncate d-block" style="max-width: 180px;">
                        <?= htmlspecialchars((string) ($_SESSION['username'] ?? 'Cashier'), ENT_QUOTES, 'UTF-8'); ?>
                    </strong>
                </li>
                <li>
                    <a class="dropdown-item py-2 small d-flex align-items-center gap-2" href="profile.php">
                        <i class="bi bi-person text-secondary fs-6"></i>
                        <span>My Account</span>
                    </a>
                </li>
                <li>
                    <a class="dropdown-item py-2 small d-flex align-items-center gap-2" href="profile.php?tab=password">
                        <i class="bi bi-shield-lock text-secondary fs-6"></i>
                        <span>Change Password</span>
                    </a>
                </li>
                <li><hr class="dropdown-divider my-1"></li>
                <li>
                    <a class="dropdown-item py-2 small d-flex align-items-center gap-2 text-danger" href="../logout.php" onclick="return confirm('Are you sure you want to log out?');">
                        <i class="bi bi-box-arrow-right"></i>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>
</nav>

<div class="container-fluid py-4 px-lg-4">

    <!-- NAVIGATION TABS -->
    <ul class="nav nav-pills mb-4" id="pills-tab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-bold px-4" id="pills-sales-tab" data-bs-toggle="pill" data-bs-target="#pills-sales" type="button" role="tab">
                <i class="bi bi-receipt me-1"></i> Walk-in Sales History
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold px-4 position-relative" id="pills-corrections-tab" data-bs-toggle="pill" data-bs-target="#pills-corrections" type="button" role="tab">
                <i class="bi bi-arrow-repeat me-1"></i> My Correction Requests
                <?php if ($count_pending > 0 || $count_approved > 0): ?>
                <span class="badge bg-danger ms-1"><?= ($count_pending + $count_approved); ?></span>
                <?php endif; ?>
            </button>
        </li>
    </ul>

    <div class="tab-content" id="pills-tabContent">

        <!-- ================= TAB 1: SALES HISTORY ================= -->
        <div class="tab-pane fade show active" id="pills-sales" role="tabpanel">

            <!-- Filter Card -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">Filter by Date</label>
                            <input type="date" name="filter_date" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">Search Receipt # / Cashier</label>
                            <input type="text" name="search" class="form-control form-control-sm" placeholder="e.g. 104, cashier1" value="<?= htmlspecialchars($search_query); ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-search me-1"></i> Filter
                            </button>
                        </div>
                        <div class="col-md-2">
                            <a href="salesHistory.php" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Transactions Table -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-list-check me-2"></i>Recent POS Transactions</h6>
                        <small class="text-muted">Normal correction requests are allowed within 24 hours of transaction completion.</small>
                    </div>
                    <span class="badge bg-light text-dark border">Showing latest <?= count($transactions); ?> transactions</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Receipt #</th>
                                <th>Date Completed</th>
                                <th>24-Hour Deadline</th>
                                <th>Cashier</th>
                                <th>Total Amount</th>
                                <th>Paid / Change</th>
                                <th>Correction Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($transactions)): ?>
                                <?php foreach ($transactions as $t): ?>
                                    <?php
                                    $is_locked = in_array($t['latest_correction_status'], ['Pending Review', 'Approved', 'Pending Additional Payment', 'Pending Refund']);
                                    
                                    // 24-Hour Deadline Calculation (Asia/Manila)
                                    $tx_ts = strtotime($t['transaction_date']);
                                    $deadline_ts = $tx_ts + (24 * 3600);
                                    $now_ts = time();
                                    $is_expired = ($now_ts > $deadline_ts);
                                    $deadline_str = date('M d, Y h:i A', $deadline_ts);

                                    if (!$is_expired) {
                                        $diff_sec = $deadline_ts - $now_ts;
                                        $hrs_left = floor($diff_sec / 3600);
                                        $mins_left = floor(($diff_sec % 3600) / 60);
                                        $badge_class = ($hrs_left < 3) ? 'deadline-badge-urgent' : 'deadline-badge-active';
                                        $time_text = "{$hrs_left}h {$mins_left}m left";
                                    } else {
                                        $diff_sec = $now_ts - $deadline_ts;
                                        $hrs_ago = floor($diff_sec / 3600);
                                        $mins_ago = floor(($diff_sec % 3600) / 60);
                                        $badge_class = 'deadline-badge-expired';
                                        $time_text = ($hrs_ago > 0) ? "Expired ({$hrs_ago}h ago)" : "Expired ({$mins_ago}m ago)";
                                    }
                                    ?>
                                    <tr>
                                        <td class="fw-bold">#<?= htmlspecialchars($t['id']); ?></td>
                                        <td>
                                            <div><?= date('M d, Y', $tx_ts); ?></div>
                                            <small class="text-muted"><?= date('h:i A', $tx_ts); ?></small>
                                        </td>
                                        <td>
                                            <div class="small fw-semibold text-dark"><?= $deadline_str; ?></div>
                                            <span class="badge <?= $badge_class; ?> rounded-pill px-2 py-1 mt-1">
                                                <i class="bi bi-clock me-1"></i><?= $time_text; ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($t['cashier_name']); ?></td>
                                        <td class="fw-bold text-primary">₱<?= number_format((float)$t['total_amount'], 2); ?></td>
                                        <td class="small text-muted">
                                            Paid: ₱<?= number_format((float)$t['amount_paid'], 2); ?><br>
                                            Change: ₱<?= number_format((float)$t['change_amount'], 2); ?>
                                        </td>
                                        <td>
                                            <?php if ($t['latest_correction_status'] === 'Pending Review'): ?>
                                                <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Pending Review</span>
                                                <?php if (!empty($t['latest_is_late'])): ?>
                                                    <span class="badge bg-danger small ms-1">Late</span>
                                                <?php endif; ?>
                                            <?php elseif ($t['latest_correction_status'] === 'Pending Additional Payment'): ?>
                                                <span class="badge bg-info text-dark"><i class="bi bi-cash me-1"></i>Needs Payment</span>
                                            <?php elseif ($t['latest_correction_status'] === 'Pending Refund'): ?>
                                                <span class="badge bg-danger"><i class="bi bi-arrow-return-left me-1"></i>Needs Refund</span>
                                            <?php elseif ($t['latest_correction_status'] === 'Completed'): ?>
                                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Corrected</span>
                                            <?php elseif ($t['latest_correction_status'] === 'Rejected'): ?>
                                                <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Rejected</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted border">No Corrections</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <button class="btn btn-sm btn-outline-dark me-1" onclick="viewTransactionReceipt(<?= $t['id']; ?>)">
                                                <i class="bi bi-receipt me-1"></i> Receipt
                                            </button>

                                            <?php if ($is_locked): ?>
                                                <button class="btn btn-sm btn-secondary" disabled title="A correction request is currently under review or awaiting settlement for this transaction.">
                                                    <i class="bi bi-lock-fill me-1"></i> Locked
                                                </button>
                                            <?php elseif (!$is_expired): ?>
                                                <button class="btn btn-sm btn-warning fw-semibold" onclick="openCorrectionModal(<?= $t['id']; ?>, false, '<?= date('M d, Y h:i A', $tx_ts); ?>', '<?= $deadline_str; ?>')">
                                                    <i class="bi bi-pencil-square me-1"></i> Request Correction
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-danger fw-semibold" onclick="openCorrectionModal(<?= $t['id']; ?>, true, '<?= date('M d, Y h:i A', $tx_ts); ?>', '<?= $deadline_str; ?>')">
                                                    <i class="bi bi-clock-history me-1"></i> Request Late Correction
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">No POS transactions found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ================= TAB 2: MY CORRECTION REQUESTS ================= -->
        <div class="tab-pane fade" id="pills-corrections" role="tabpanel">

            <!-- Summary Cards -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="card card-metric bg-white p-3 border-start border-warning border-4">
                        <small class="text-muted fw-bold">Pending Review</small>
                        <h3 class="fw-bold mb-0 text-warning"><?= $count_pending; ?></h3>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card card-metric bg-white p-3 border-start border-info border-4">
                        <small class="text-muted fw-bold">Approved / To Settle</small>
                        <h3 class="fw-bold mb-0 text-info"><?= $count_approved; ?></h3>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card card-metric bg-white p-3 border-start border-success border-4">
                        <small class="text-muted fw-bold">Completed Corrections</small>
                        <h3 class="fw-bold mb-0 text-success"><?= $count_completed; ?></h3>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card card-metric bg-white p-3 border-start border-danger border-4">
                        <small class="text-muted fw-bold">Rejected Requests</small>
                        <h3 class="fw-bold mb-0 text-danger"><?= $count_rejected; ?></h3>
                    </div>
                </div>
            </div>

            <!-- Category Filter Pills -->
            <div class="d-flex align-items-center gap-2 mb-3">
                <span class="small fw-bold text-muted me-2">Category Filter:</span>
                <a href="?req_category=all" class="btn btn-sm <?= $req_category_filter === 'all' ? 'btn-dark' : 'btn-outline-secondary'; ?>">All (<?= count($my_requests); ?>)</a>
                <a href="?req_category=normal" class="btn btn-sm <?= $req_category_filter === 'normal' ? 'btn-primary' : 'btn-outline-secondary'; ?>">Normal Requests</a>
                <a href="?req_category=late" class="btn btn-sm <?= $req_category_filter === 'late' ? 'btn-danger' : 'btn-outline-secondary'; ?>">Late Requests (<?= $count_late; ?>)</a>
            </div>

            <!-- Requests Table -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-hourglass-split me-2"></i>My Submitted Correction Requests</h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Request ID</th>
                                <th>Receipt #</th>
                                <th>Category</th>
                                <th>Date Requested</th>
                                <th>Correction Type</th>
                                <th>Original Total</th>
                                <th>Proposed Total</th>
                                <th>Financial Difference</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($my_requests)): ?>
                                <?php foreach ($my_requests as $r): ?>
                                    <?php
                                    $diff = (float)$r['payment_difference'];
                                    $is_late = !empty($r['is_late_request']);
                                    ?>
                                    <tr>
                                        <td class="fw-bold">#REQ-<?= $r['id']; ?></td>
                                        <td>#<?= $r['transaction_id']; ?></td>
                                        <td>
                                            <?php if ($is_late): ?>
                                                <span class="badge bg-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>Late Request</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark border">Normal (24h)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= date('M d, Y h:i A', strtotime($r['created_at'])); ?></td>
                                        <td>
                                            <span class="badge bg-light text-dark border fw-semibold"><?= htmlspecialchars($r['correction_type']); ?></span>
                                            <?php if ($r['correction_type'] === 'Others' && !empty($r['other_details'])): ?>
                                                <small class="d-block text-muted text-truncate" style="max-width: 150px;"><?= htmlspecialchars($r['other_details']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>₱<?= number_format((float)$r['original_total'], 2); ?></td>
                                        <td class="fw-bold text-primary">₱<?= number_format((float)$r['proposed_total'], 2); ?></td>
                                        <td>
                                            <?php if ($diff > 0): ?>
                                                <span class="diff-badge-pos">+₱<?= number_format($diff, 2); ?> (Customer Pays)</span>
                                            <?php elseif ($diff < 0): ?>
                                                <span class="diff-badge-neg">-₱<?= number_format(abs($diff), 2); ?> (Refund Due)</span>
                                            <?php else: ?>
                                                <span class="diff-badge-zero">₱0.00 (No Diff)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($r['status'] === 'Pending Review'): ?>
                                                <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Pending Review</span>
                                            <?php elseif ($r['status'] === 'Pending Additional Payment'): ?>
                                                <span class="badge bg-info text-dark"><i class="bi bi-wallet2 me-1"></i>Approved (Needs Payment)</span>
                                            <?php elseif ($r['status'] === 'Pending Refund'): ?>
                                                <span class="badge bg-danger"><i class="bi bi-arrow-return-left me-1"></i>Approved (Needs Refund)</span>
                                            <?php elseif ($r['status'] === 'Completed'): ?>
                                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Completed</span>
                                            <?php elseif ($r['status'] === 'Rejected'): ?>
                                                <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Rejected</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><?= htmlspecialchars($r['status']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <button class="btn btn-sm btn-outline-primary me-1" onclick="viewCorrectionDetails(<?= $r['id']; ?>)">
                                                <i class="bi bi-eye"></i> Details
                                            </button>
                                            <?php if ($r['status'] === 'Pending Additional Payment'): ?>
                                                <button class="btn btn-sm btn-success" onclick="openSettleModal(<?= $r['id']; ?>, 'payment', <?= $diff; ?>)">
                                                    <i class="bi bi-cash-coin me-1"></i> Collect Payment
                                                </button>
                                            <?php elseif ($r['status'] === 'Pending Refund'): ?>
                                                <button class="btn btn-sm btn-danger" onclick="openSettleModal(<?= $r['id']; ?>, 'refund', <?= abs($diff); ?>)">
                                                    <i class="bi bi-arrow-return-left me-1"></i> Record Refund
                                                </button>
                                            <?php elseif ($r['status'] === 'Completed'): ?>
                                                <button class="btn btn-sm btn-dark" onclick="printCorrectedReceipt(<?= $r['id']; ?>)">
                                                    <i class="bi bi-printer me-1"></i> Corrected Receipt
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="text-center py-4 text-muted">You have not submitted any correction requests under this category yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </div>

</div>

<!-- ================= MODAL: SUBMIT CORRECTION REQUEST ================= -->
<div class="modal fade" id="correctionModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header text-white" id="corrModalHeader">
                <h5 class="modal-title fw-bold" id="corrModalTitle">
                    <i class="bi bi-pencil-square me-2"></i>Submit Transaction Correction Request
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="correctionForm" enctype="multipart/form-data">
                    <input type="hidden" name="transaction_id" id="corr_transaction_id">
                    <input type="hidden" name="is_late_request" id="corr_is_late_request" value="0">
                    <input type="hidden" name="original_items" id="corr_original_items_input">
                    <input type="hidden" name="corrected_items" id="corr_corrected_items_input">

                    <!-- Header Summary -->
                    <div class="alert alert-light border p-3 mb-3">
                        <div class="row g-2">
                            <div class="col-sm-3"><strong>Receipt No:</strong> <span id="corr_receipt_no" class="text-primary fw-bold"></span></div>
                            <div class="col-sm-3"><strong>Completed:</strong> <span id="corr_receipt_date"></span></div>
                            <div class="col-sm-3"><strong>24h Deadline:</strong> <span id="corr_receipt_deadline" class="fw-semibold"></span></div>
                            <div class="col-sm-3"><strong>Original Total:</strong> <span id="corr_receipt_orig_total" class="fw-bold"></span></div>
                        </div>
                    </div>

                    <!-- Late Notice Banner (Shown only if expired) -->
                    <div id="lateRequestNotice" class="alert alert-danger mb-3" style="display: none;">
                        <h6 class="fw-bold mb-1"><i class="bi bi-exclamation-octagon-fill me-2"></i>24-Hour Correction Period Expired</h6>
                        <p class="small mb-0">The 24-hour correction request period has expired. This request will be submitted as a <strong>Late Correction Request</strong> and requires Owner review and override approval. A mandatory explanation for the delay is required below.</p>
                    </div>

                    <!-- Late Reason Explanation (Mandatory if Late) -->
                    <div class="mb-3" id="lateReasonContainer" style="display: none;">
                        <label class="form-label fw-bold text-danger">
                            <i class="bi bi-clock-history me-1"></i> Reason for Late Submission (Beyond 24 Hours) <span class="text-danger">*</span>
                        </label>
                        <textarea name="late_reason" id="corr_late_reason" class="form-control border-danger" rows="2" placeholder="Explain why this correction was not reported within the 24-hour window (e.g. customer returned next day, shift handover discrepancy)..."></textarea>
                    </div>

                    <!-- Correction Type & Reason -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Correction Type <span class="text-danger">*</span></label>
                            <select name="correction_type" id="corr_type_select" class="form-select" required onchange="handleCorrectionTypeChange()">
                                <option value="" disabled selected>-- Select Type of Error --</option>
                                <option value="Wrong Product Input">Wrong Product Input</option>
                                <option value="Incorrect Quantity">Incorrect Quantity</option>
                                <option value="Incorrect Payment Information">Incorrect Payment Information</option>
                                <option value="Others">Others</option>
                            </select>
                            <div class="form-text small" id="typeHelpText">Select the category that best matches the error.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Supporting Evidence (Optional)</label>
                            <input type="file" name="evidence" class="form-control" accept="image/*,.pdf">
                            <small class="text-muted">Attach receipt photo, wrong item photo, customer note, etc.</small>
                        </div>

                        <!-- Specific Field for Others Category -->
                        <div class="col-12" id="othersDetailsContainer" style="display: none;">
                            <div class="card bg-light border p-3">
                                <label class="form-label fw-bold text-dark">
                                    <i class="bi bi-info-circle me-1"></i> Specific Issue Details (Others) <span class="text-danger">*</span>
                                </label>
                                <textarea name="other_details" id="corr_other_details" class="form-control" rows="2" placeholder="Please specify in detail the exact problem and correction needed..."></textarea>
                            </div>
                        </div>

                        <!-- Specific Fields for Incorrect Payment Information Category -->
                        <div class="col-12" id="paymentCorrectionContainer" style="display: none;">
                            <div class="card border-primary p-3" style="background-color: #f0f7ff;">
                                <h6 class="fw-bold text-primary mb-3">
                                    <i class="bi bi-credit-card-2-front me-2"></i>Payment Information Correction Details
                                </h6>
                                <div class="row g-3">
                                    <!-- Original Recorded Paid Amount (Read-only) -->
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold text-muted">Original Recorded Paid Amount</label>
                                        <input type="text" id="corr_orig_paid_amount" class="form-control bg-white fw-bold" readonly>
                                    </div>
                                    <!-- Original Payment Method (Read-only) -->
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold text-muted">Original Payment Method</label>
                                        <input type="text" id="corr_orig_payment_method" class="form-control bg-white" readonly>
                                    </div>
                                    <!-- Original Sender / Ref Number (Read-only) -->
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold text-muted">Original Sender / Ref Number</label>
                                        <input type="text" id="corr_orig_ref_number" class="form-control bg-white" readonly>
                                    </div>

                                    <!-- Proposed Correct Paid Amount -->
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold text-dark">
                                            Proposed Correct Paid Amount <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text">₱</span>
                                            <input type="number" step="0.01" min="0" name="proposed_amount_paid" id="corr_prop_paid_amount" class="form-control fw-bold" placeholder="0.00" oninput="calculatePaymentCorrectionBalance()">
                                        </div>
                                        <small class="text-muted">Amount that should have been recorded as paid.</small>
                                    </div>

                                    <!-- Actual Amount Received -->
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold text-dark">
                                            Actual Amount Received <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text">₱</span>
                                            <input type="number" step="0.01" min="0" name="actual_amount_received" id="corr_actual_amount_received" class="form-control fw-bold" placeholder="0.00" oninput="calculatePaymentCorrectionBalance()">
                                        </div>
                                        <small class="text-muted">Real money or e-wallet actually received from customer.</small>
                                    </div>

                                    <!-- Proposed Payment Method -->
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold text-dark">
                                            Payment Method <span class="text-danger">*</span>
                                        </label>
                                        <select name="proposed_payment_method" id="corr_prop_payment_method" class="form-select" onchange="handlePropPaymentMethodChange()">
                                            <option value="Cash" selected>Cash</option>
                                            <option value="GCash">GCash</option>
                                            <option value="Maya">Maya</option>
                                        </select>
                                    </div>

                                    <!-- Proposed Sender Number (Only visible for GCash / Maya) -->
                                    <div class="col-md-6" id="corr_sender_num_group" style="display: none;">
                                        <label class="form-label small fw-bold text-dark">
                                            Sender Mobile Number (GCash / Maya) <span class="text-danger">*</span>
                                        </label>
                                        <input type="text" name="proposed_reference_number" id="corr_prop_reference_number" class="form-control" placeholder="e.g. 09171234567">
                                        <small class="text-muted">Customer's phone number used as sender.</small>
                                    </div>

                                    <!-- Live Balance / Payment Status Feedback Banner -->
                                    <div class="col-12">
                                        <div id="paymentBalanceStatusBanner" class="alert alert-secondary py-2 mb-0 small">
                                            <!-- Dynamically calculated -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Explanation / Reason for Correction <span class="text-danger">*</span></label>
                            <textarea name="reason" id="corr_reason" class="form-control" rows="2" placeholder="Describe why this correction is needed and what customer agreement was made..." required></textarea>
                        </div>
                    </div>

                    <!-- Items Comparison Editor -->
                    <div class="row g-3">
                        <!-- Left: Original Items (Read-only) -->
                        <div class="col-lg-5">
                            <div class="card h-100 border">
                                <div class="card-header bg-light py-2">
                                    <h6 class="mb-0 fw-bold text-secondary"><i class="bi bi-clock-history me-1"></i>Original Completed Items</h6>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table table-sm item-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th>Price</th>
                                                <th>Qty</th>
                                                <th class="text-end">Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody id="originalItemsTbody">
                                            <!-- Dynamically filled -->
                                        </tbody>
                                        <tfoot class="table-light">
                                            <tr>
                                                <th colspan="3">Original Total:</th>
                                                <th class="text-end fw-bold text-dark" id="origTotalDisplay">₱0.00</th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Corrected Items (Editable) -->
                        <div class="col-lg-7">
                            <div class="card h-100 border">
                                <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-pencil-square me-1"></i>Requested Corrected Items</h6>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="showProductSearchModal()">
                                        <i class="bi bi-plus-circle me-1"></i> Add / Replace Product
                                    </button>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table table-sm item-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th style="width: 110px;">Price (₱)</th>
                                                <th style="width: 120px;">Qty</th>
                                                <th class="text-end">Subtotal</th>
                                                <th style="width: 40px;"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="correctedItemsTbody">
                                            <!-- Dynamically filled -->
                                        </tbody>
                                        <tfoot class="table-light">
                                            <tr>
                                                <th colspan="3">Proposed Total:</th>
                                                <th class="text-end fw-bold text-primary" id="propTotalDisplay">₱0.00</th>
                                                <th></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Financial Difference Summary Banner -->
                    <div class="alert alert-light border mt-4 p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <span class="text-muted me-2">Financial Difference:</span>
                            <span id="diffAmountBadge" class="fs-5 fw-bold">₱0.00</span>
                        </div>
                        <div id="diffExplanationText" class="small text-muted"></div>
                    </div>

                    <div class="mt-4 text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4 fw-bold" id="submitCorrectionBtn">
                            <i class="bi bi-send me-1"></i> Submit Request for Owner Review
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ================= MODAL: PRODUCT SEARCH PICKER ================= -->
<div class="modal fade" id="productSearchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><i class="bi bi-search me-2"></i>Select Product to Add / Replace</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="input-group mb-3">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" id="pickerSearchInput" class="form-control" placeholder="Type product or variant name..." onkeyup="searchPickerProducts()">
                </div>
                <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Product / Variant</th>
                                <th>Price</th>
                                <th>Current Stock</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="pickerResultsTbody">
                            <tr><td colspan="4" class="text-center py-3 text-muted">Type above to search products...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ================= MODAL: SETTLE PAYMENT / REFUND ================= -->
<div class="modal fade" id="settleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold" id="settleModalTitle"><i class="bi bi-wallet2 me-2"></i>Settle Difference</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="settleForm">
                    <input type="hidden" name="request_id" id="settle_request_id">
                    <input type="hidden" name="settle_type" id="settle_type">

                    <div class="alert alert-info py-2" id="settleBanner">
                        <!-- Populated by JS -->
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Settlement Amount</label>
                        <input type="text" id="settle_amount_display" class="form-control fw-bold fs-5 text-primary" readonly>
                        <input type="hidden" name="amount" id="settle_amount_input">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Payment / Payout Method</label>
                        <select name="payment_method" id="settle_payment_method" class="form-select" onchange="handleSettleMethodChange()" required>
                            <option value="Cash" selected>Cash</option>
                            <option value="GCash">GCash</option>
                            <option value="Maya">Maya</option>
                        </select>
                    </div>

                    <div class="mb-3" id="settle_sender_num_group" style="display: none;">
                        <label class="form-label fw-bold">Sender Mobile Number (GCash / Maya) <span class="text-danger" id="settle_sender_req_star" style="display:none;">*</span></label>
                        <input type="text" name="payment_reference" id="settle_payment_reference" class="form-control" placeholder="e.g. 09171234567">
                        <small class="text-muted">Enter the customer's GCash or Maya phone number used for the transaction.</small>
                    </div>

                    <div class="text-end mt-4">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success fw-bold px-4" id="settleSubmitBtn">
                            <i class="bi bi-check-circle me-1"></i> Confirm & Complete Correction
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ================= MODAL: RECEIPT VIEW ================= -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
        <div class="modal-content">
            <div class="modal-header py-2 bg-light">
                <h6 class="modal-title fw-bold mb-0" id="receiptModalTitle"><i class="bi bi-receipt me-1"></i>Official Receipt</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div id="receiptContainer" class="receipt-paper">
                    <!-- Populated by JS -->
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-dark btn-sm" onclick="triggerPrintReceipt()">
                    <i class="bi bi-printer me-1"></i> Print Receipt (58mm)
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ================= MODAL: CORRECTION REQUEST DETAILS ================= -->
<div class="modal fade" id="requestDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold" id="reqDetailsTitle">Correction Request Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="reqDetailsBody">
                <!-- Loaded via fetch -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Thermal Print Frame -->
<iframe id="receipt-print-frame" style="display:none; width:0; height:0;"></iframe>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentCorrectionCart = [];
let currentOriginalCart = [];

const correctionModal = new bootstrap.Modal(document.getElementById('correctionModal'));
const productSearchModal = new bootstrap.Modal(document.getElementById('productSearchModal'));
const settleModal = new bootstrap.Modal(document.getElementById('settleModal'));
const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
const requestDetailsModal = new bootstrap.Modal(document.getElementById('requestDetailsModal'));

// Dynamic Correction Type handling
function handleCorrectionTypeChange() {
    const typeSelect = document.getElementById('corr_type_select');
    const othersContainer = document.getElementById('othersDetailsContainer');
    const othersInput = document.getElementById('corr_other_details');
    const helpText = document.getElementById('typeHelpText');
    const reasonInput = document.getElementById('corr_reason');
    const payContainer = document.getElementById('paymentCorrectionContainer');
    const propPaidInput = document.getElementById('corr_prop_paid_amount');
    const actualRecInput = document.getElementById('corr_actual_amount_received');

    if (typeSelect.value === 'Others') {
        othersContainer.style.display = 'block';
        othersInput.required = true;
        if (payContainer) payContainer.style.display = 'none';
        if (propPaidInput) propPaidInput.required = false;
        if (actualRecInput) actualRecInput.required = false;
        helpText.innerText = "Please provide detailed specifics about the error in the field below.";
        reasonInput.placeholder = "Describe the circumstance that resulted in this error...";
    } else if (typeSelect.value === 'Incorrect Payment Information') {
        othersContainer.style.display = 'none';
        othersInput.required = false;
        if (payContainer) payContainer.style.display = 'block';
        if (propPaidInput) propPaidInput.required = true;
        if (actualRecInput) actualRecInput.required = true;
        helpText.innerText = "Correct recorded paid amount, payment method, sender mobile number, or actual amount received.";
        reasonInput.placeholder = "Explain the payment correction (e.g. wrong paid amount typed, customer paid via GCash/Maya, underpayment/overpayment)...";
        handlePropPaymentMethodChange();
        calculatePaymentCorrectionBalance();
    } else {
        othersContainer.style.display = 'none';
        othersInput.required = false;
        if (payContainer) payContainer.style.display = 'none';
        if (propPaidInput) propPaidInput.required = false;
        if (actualRecInput) actualRecInput.required = false;

        if (typeSelect.value === 'Wrong Product Input') {
            helpText.innerText = "Identify the incorrectly recorded product in the original list and replace it with the intended product.";
            reasonInput.placeholder = "State which product was mistakenly scanned/entered and why the replacement is required...";
        } else if (typeSelect.value === 'Incorrect Quantity') {
            helpText.innerText = "Adjust the quantity of the item(s) to match what the customer actually received.";
            reasonInput.placeholder = "Explain how the quantity error occurred and what the verified quantity is...";
        }
    }
}

function handlePropPaymentMethodChange() {
    let methodEl = document.getElementById('corr_prop_payment_method');
    if (!methodEl) return;
    let method = methodEl.value;
    let group = document.getElementById('corr_sender_num_group');
    let input = document.getElementById('corr_prop_reference_number');
    if (!group || !input) return;

    if (method === 'GCash' || method === 'Maya') {
        group.style.display = 'block';
        input.required = true;
    } else {
        group.style.display = 'none';
        input.required = false;
        input.value = '';
    }
}

function calculatePaymentCorrectionBalance() {
    let origTotal = currentOriginalCart.reduce((acc, i) => acc + i.subtotal, 0);
    let propTotal = currentCorrectionCart.reduce((acc, i) => acc + i.subtotal, 0);
    let targetTotal = propTotal > 0 ? propTotal : origTotal;

    let propPaidInput = document.getElementById('corr_prop_paid_amount');
    let actualRecInput = document.getElementById('corr_actual_amount_received');
    let banner = document.getElementById('paymentBalanceStatusBanner');
    if (!banner) return;

    let propPaid = parseFloat(propPaidInput ? propPaidInput.value : 0) || 0;
    let actualRec = parseFloat(actualRecInput ? actualRecInput.value : 0) || 0;

    let bal = targetTotal - actualRec;
    if (bal > 0.001) {
        banner.className = 'alert alert-warning py-2 mb-0 small';
        banner.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1 text-warning"></i> <b>Underpaid Transaction:</b> Customer owes an outstanding balance of <strong>₱${bal.toFixed(2)}</strong>. Status will remain <strong>Partially Paid</strong> upon approval.`;
    } else if (bal < -0.001) {
        let overpay = Math.abs(bal);
        banner.className = 'alert alert-info py-2 mb-0 small';
        banner.innerHTML = `<i class="bi bi-info-circle-fill me-1 text-info"></i> <b>Overpayment / Change Due:</b> Customer overpaid by <strong>₱${overpay.toFixed(2)}</strong>. Refund/change process will apply upon approval.`;
    } else {
        banner.className = 'alert alert-success py-2 mb-0 small';
        banner.innerHTML = `<i class="bi bi-check-circle-fill me-1 text-success"></i> <b>Fully Paid:</b> Actual money received matches the transaction total of ₱${targetTotal.toFixed(2)}. Remaining balance: ₱0.00.`;
    }
}

// 1. OPEN CORRECTION MODAL & LOAD TRANSACTION ITEMS
function openCorrectionModal(txId, isLate, txDateStr, deadlineStr) {
    document.getElementById('corr_transaction_id').value = txId;
    document.getElementById('corr_receipt_no').innerText = '#' + txId;
    document.getElementById('corr_is_late_request').value = isLate ? "1" : "0";

    // Set UI for Normal vs Late Request
    const header = document.getElementById('corrModalHeader');
    const title = document.getElementById('corrModalTitle');
    const lateNotice = document.getElementById('lateRequestNotice');
    const lateContainer = document.getElementById('lateReasonContainer');
    const lateReasonInput = document.getElementById('corr_late_reason');
    const submitBtn = document.getElementById('submitCorrectionBtn');

    if (isLate) {
        header.className = "modal-header bg-danger text-white";
        title.innerHTML = '<i class="bi bi-exclamation-diamond-fill me-2"></i>Submit Late Correction Request (Owner Override)';
        lateNotice.style.display = 'block';
        lateContainer.style.display = 'block';
        lateReasonInput.required = true;
        submitBtn.className = 'btn btn-danger px-4 fw-bold';
        submitBtn.innerHTML = '<i class="bi bi-clock-history me-1"></i> Submit Late Request for Owner Override';
    } else {
        header.className = "modal-header bg-primary text-white";
        title.innerHTML = '<i class="bi bi-pencil-square me-2"></i>Submit Transaction Correction Request';
        lateNotice.style.display = 'none';
        lateContainer.style.display = 'none';
        lateReasonInput.required = false;
        submitBtn.className = 'btn btn-primary px-4 fw-bold';
        submitBtn.innerHTML = '<i class="bi bi-send me-1"></i> Submit Request for Owner Review';
    }

    // Reset fields
    document.getElementById('corr_type_select').value = '';
    document.getElementById('corr_reason').value = '';
    document.getElementById('corr_other_details').value = '';
    document.getElementById('othersDetailsContainer').style.display = 'none';
    document.getElementById('paymentCorrectionContainer').style.display = 'none';
    document.getElementById('originalItemsTbody').innerHTML = '<tr><td colspan="4" class="text-center py-3 text-muted">Loading...</td></tr>';
    document.getElementById('correctedItemsTbody').innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">Loading...</td></tr>';

    fetch(`../owner/getPOSitems.php?id=${txId}`)
    .then(res => res.json())
    .then(data => {
        let tx = data.transaction || {};
        document.getElementById('corr_receipt_date').innerText = txDateStr || tx.transaction_date || 'N/A';
        document.getElementById('corr_receipt_deadline').innerText = deadlineStr || 'N/A';
        document.getElementById('corr_receipt_orig_total').innerText = '₱' + parseFloat(tx.total_amount || 0).toFixed(2);

        // Populate Payment Correction Original Information
        let origPaid = parseFloat(tx.amount_paid || tx.total_amount || 0);
        let actualRec = parseFloat(tx.actual_amount_received || tx.amount_paid || tx.total_amount || 0);
        let origMethod = tx.payment_method || 'Cash';
        let origRef = tx.reference_number || 'N/A';

        document.getElementById('corr_orig_paid_amount').value = '₱' + origPaid.toFixed(2);
        document.getElementById('corr_orig_payment_method').value = origMethod;
        document.getElementById('corr_orig_ref_number').value = origRef;

        document.getElementById('corr_prop_paid_amount').value = origPaid.toFixed(2);
        document.getElementById('corr_actual_amount_received').value = actualRec.toFixed(2);
        document.getElementById('corr_prop_payment_method').value = (origMethod === 'GCash' || origMethod === 'Maya') ? origMethod : 'Cash';
        document.getElementById('corr_prop_reference_number').value = (tx.reference_number && tx.reference_number !== 'N/A') ? tx.reference_number : '';
        handlePropPaymentMethodChange();
        calculatePaymentCorrectionBalance();

        let items = [];
        if (Array.isArray(data)) items = data;
        else if (data && Array.isArray(data.items)) items = data.items;
        else if (data && Array.isArray(data.cart)) items = data.cart;

        currentOriginalCart = items.map(it => ({
            product_id: it.product_id ? parseInt(it.product_id) : null,
            variant_id: it.variant_id ? parseInt(it.variant_id) : null,
            product_name: it.product_name || it.name || 'Item',
            price: parseFloat(it.price || 0),
            qty: parseInt(it.qty || 1),
            subtotal: parseFloat(it.subtotal || (parseFloat(it.price || 0) * parseInt(it.qty || 1)))
        }));

        currentCorrectionCart = JSON.parse(JSON.stringify(currentOriginalCart));

        renderOriginalItems();
        renderCorrectedItems();
        correctionModal.show();
    })
    .catch(err => {
        alert("Failed to load transaction details: " + err.message);
    });
}

function renderOriginalItems() {
    let tbody = document.getElementById('originalItemsTbody');
    let total = 0;
    let html = '';

    currentOriginalCart.forEach(it => {
        total += it.subtotal;
        html += `
            <tr>
                <td><b>${it.product_name}</b></td>
                <td>₱${it.price.toFixed(2)}</td>
                <td>x${it.qty}</td>
                <td class="text-end fw-bold">₱${it.subtotal.toFixed(2)}</td>
            </tr>
        `;
    });

    tbody.innerHTML = html || '<tr><td colspan="4" class="text-center text-muted">No items</td></tr>';
    document.getElementById('origTotalDisplay').innerText = '₱' + total.toFixed(2);
}

function renderCorrectedItems() {
    let tbody = document.getElementById('correctedItemsTbody');
    let total = 0;
    let html = '';

    currentCorrectionCart.forEach((it, idx) => {
        let subtotal = it.price * it.qty;
        it.subtotal = subtotal;
        total += subtotal;

        html += `
            <tr>
                <td><b>${it.product_name}</b></td>
                <td>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" value="${it.price.toFixed(2)}" onchange="updateItemPrice(${idx}, this.value)">
                </td>
                <td>
                    <div class="input-group input-group-sm">
                        <button type="button" class="btn btn-outline-secondary" onclick="updateItemQty(${idx}, ${it.qty - 1})">-</button>
                        <input type="number" min="1" class="form-control text-center" value="${it.qty}" onchange="updateItemQty(${idx}, this.value)">
                        <button type="button" class="btn btn-outline-secondary" onclick="updateItemQty(${idx}, ${it.qty + 1})">+</button>
                    </div>
                </td>
                <td class="text-end fw-bold align-middle">₱${subtotal.toFixed(2)}</td>
                <td class="text-center align-middle">
                    <button type="button" class="btn btn-sm text-danger p-0" onclick="removeItem(${idx})" title="Remove">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html || '<tr><td colspan="5" class="text-center text-muted py-3">No items. Click "+ Add / Replace Product" to add.</td></tr>';
    document.getElementById('propTotalDisplay').innerText = '₱' + total.toFixed(2);

    let origTotal = currentOriginalCart.reduce((acc, i) => acc + i.subtotal, 0);
    let diff = total - origTotal;
    let diffBadge = document.getElementById('diffAmountBadge');
    let diffText = document.getElementById('diffExplanationText');

    if (diff > 0) {
        diffBadge.className = 'fs-5 fw-bold text-success';
        diffBadge.innerText = '+₱' + diff.toFixed(2);
        diffText.innerText = 'Additional payment of ₱' + diff.toFixed(2) + ' must be collected from customer upon owner approval.';
    } else if (diff < 0) {
        diffBadge.className = 'fs-5 fw-bold text-danger';
        diffBadge.innerText = '-₱' + Math.abs(diff).toFixed(2);
        diffText.innerText = 'A refund of ₱' + Math.abs(diff).toFixed(2) + ' must be issued to customer upon owner approval.';
    } else {
        diffBadge.className = 'fs-5 fw-bold text-secondary';
        diffBadge.innerText = '₱0.00';
        diffText.innerText = 'Even exchange. No payment collection or refund required.';
    }
}

function updateItemQty(idx, newQty) {
    newQty = parseInt(newQty);
    if (isNaN(newQty) || newQty <= 0) newQty = 1;
    currentCorrectionCart[idx].qty = newQty;
    renderCorrectedItems();
}

function updateItemPrice(idx, newPrice) {
    newPrice = parseFloat(newPrice);
    if (isNaN(newPrice) || newPrice < 0) newPrice = 0;
    currentCorrectionCart[idx].price = newPrice;
    renderCorrectedItems();
}

function removeItem(idx) {
    if (confirm("Remove this item from the corrected transaction?")) {
        currentCorrectionCart.splice(idx, 1);
        renderCorrectedItems();
    }
}

// 2. PRODUCT PICKER MODAL
function showProductSearchModal() {
    document.getElementById('pickerSearchInput').value = '';
    searchPickerProducts();
    productSearchModal.show();
}

let pickerTimeout = null;
function searchPickerProducts() {
    clearTimeout(pickerTimeout);
    pickerTimeout = setTimeout(() => {
        let q = document.getElementById('pickerSearchInput').value.trim();
        fetch(`search_products.php?q=${encodeURIComponent(q)}`)
        .then(res => res.json())
        .then(resData => {
            let tbody = document.getElementById('pickerResultsTbody');
            if (resData.status === 'success' && resData.data && resData.data.length > 0) {
                let rows = '';
                resData.data.forEach(p => {
                    let stockBadge = p.stock > 0 ? `<span class="badge bg-success">${p.stock} in stock</span>` : `<span class="badge bg-danger">Out of stock</span>`;
                    let itemJson = JSON.stringify(p).replace(/"/g, '&quot;');
                    rows += `
                        <tr>
                            <td>
                                <b>${p.display_name}</b>
                                ${p.type === 'variant' ? '<span class="badge bg-info text-dark ms-1">Variant</span>' : ''}
                            </td>
                            <td class="fw-bold">₱${p.price.toFixed(2)}</td>
                            <td>${stockBadge}</td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-primary" onclick="addPickerItem(${itemJson})">
                                    <i class="bi bi-plus-lg"></i> Select
                                </button>
                            </td>
                        </tr>
                    `;
                });
                tbody.innerHTML = rows;
            } else {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center py-3 text-muted">No products found.</td></tr>';
            }
        });
    }, 250);
}

function addPickerItem(prod) {
    currentCorrectionCart.push({
        product_id: prod.product_id,
        variant_id: prod.variant_id,
        product_name: prod.display_name,
        price: prod.price,
        qty: 1,
        subtotal: prod.price
    });
    renderCorrectedItems();
    productSearchModal.hide();
}

// 3. SUBMIT CORRECTION REQUEST FORM
document.getElementById('correctionForm').addEventListener('submit', function(e) {
    e.preventDefault();

    if (currentCorrectionCart.length === 0) {
        alert("The corrected transaction must have at least one product item.");
        return;
    }

    let isLate = document.getElementById('corr_is_late_request').value === "1";
    if (isLate) {
        let lateReason = document.getElementById('corr_late_reason').value.trim();
        if (!lateReason) {
            alert("Please provide the mandatory explanation describing why this correction was not reported within 24 hours.");
            document.getElementById('corr_late_reason').focus();
            return;
        }
    }

    let type = document.getElementById('corr_type_select').value;
    if (type === 'Others') {
        let otherDetails = document.getElementById('corr_other_details').value.trim();
        if (!otherDetails) {
            alert("Please provide specific error details for the 'Others' category.");
            document.getElementById('corr_other_details').focus();
            return;
        }
    }

    let btn = document.getElementById('submitCorrectionBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Submitting...';

    document.getElementById('corr_original_items_input').value = JSON.stringify(currentOriginalCart);
    document.getElementById('corr_corrected_items_input').value = JSON.stringify(currentCorrectionCart);

    let formData = new FormData(this);

    fetch('submit_correction.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send me-1"></i> Submit Request for Owner Review';

        if (data.status === 'success') {
            alert(data.message);
            location.reload();
        } else {
            alert("Error: " + data.message);
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send me-1"></i> Submit Request for Owner Review';
        alert("Submission failed: " + err.message);
    });
});

// 4. SETTLE MODAL (COLLECT PAYMENT OR ISSUE REFUND)
function handleSettleMethodChange() {
    let methodEl = document.getElementById('settle_payment_method');
    if (!methodEl) return;
    let method = methodEl.value;
    let group = document.getElementById('settle_sender_num_group');
    let input = document.getElementById('settle_payment_reference');
    let star = document.getElementById('settle_sender_req_star');
    if (!group || !input) return;

    if (method === 'GCash' || method === 'Maya') {
        group.style.display = 'block';
        input.required = true;
        if (star) star.style.display = 'inline';
    } else {
        group.style.display = 'none';
        input.required = false;
        input.value = '';
        if (star) star.style.display = 'none';
    }
}

function openSettleModal(reqId, type, amount) {
    document.getElementById('settle_request_id').value = reqId;
    document.getElementById('settle_type').value = type;
    document.getElementById('settle_amount_input').value = amount.toFixed(2);
    document.getElementById('settle_amount_display').value = '₱' + amount.toFixed(2);
    document.getElementById('settle_payment_method').value = 'Cash';
    handleSettleMethodChange();

    let title = document.getElementById('settleModalTitle');
    let banner = document.getElementById('settleBanner');
    let submitBtn = document.getElementById('settleSubmitBtn');

    if (type === 'payment') {
        title.innerHTML = '<i class="bi bi-cash-coin me-2"></i>Record Additional Payment';
        banner.innerHTML = `<strong>Collection Required:</strong> The customer owes an additional <strong>₱${amount.toFixed(2)}</strong>. Please collect and record the payment details below to complete this transaction correction.`;
        submitBtn.className = 'btn btn-success fw-bold px-4';
        submitBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Confirm Payment & Synchronize Inventory';
    } else {
        title.innerHTML = '<i class="bi bi-arrow-return-left me-2"></i>Record Refund Payout';
        banner.innerHTML = `<strong>Refund Due:</strong> The customer is owed a refund of <strong>₱${amount.toFixed(2)}</strong>. Please record the payout details below to complete this transaction correction.`;
        submitBtn.className = 'btn btn-danger fw-bold px-4';
        submitBtn.innerHTML = '<i class="bi bi-arrow-return-left me-1"></i> Confirm Refund & Synchronize Inventory';
    }

    settleModal.show();
}

document.getElementById('settleForm').addEventListener('submit', function(e) {
    e.preventDefault();
    let btn = document.getElementById('settleSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing Settlement...';

    let formData = new FormData(this);

    fetch('settle_correction.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        if (data.status === 'success') {
            alert(data.message);
            settleModal.hide();
            printCorrectedReceipt(data.request_id);
            setTimeout(() => { location.reload(); }, 1500);
        } else {
            alert("Settlement Error: " + data.message);
        }
    })
    .catch(err => {
        btn.disabled = false;
        alert("Request error: " + err.message);
    });
});

// 5. VIEW STANDARD TRANSACTION RECEIPT
function viewTransactionReceipt(txId) {
    document.getElementById('receiptModalTitle').innerHTML = '<i class="bi bi-receipt me-1"></i>Official Receipt #' + txId;
    let container = document.getElementById('receiptContainer');
    container.innerHTML = '<div class="text-center py-4 text-muted">Loading receipt...</div>';
    receiptModal.show();

    fetch(`../owner/getPOSitems.php?id=${txId}`)
    .then(res => res.json())
    .then(resData => {
        let items = [];
        if (Array.isArray(resData)) items = resData;
        else if (resData && Array.isArray(resData.items)) items = resData.items;
        else if (resData && Array.isArray(resData.cart)) items = resData.cart;

        let tx = resData.transaction || {};
        let total = parseFloat(tx.total_amount || 0);
        let paid = parseFloat(tx.amount_paid || total);
        let change = parseFloat(tx.change_amount || 0);

        let itemsRows = '';
        items.forEach(it => {
            let p = parseFloat(it.price || 0);
            let q = parseInt(it.qty || 1);
            let sub = parseFloat(it.subtotal || (p * q));
            itemsRows += `
                <tr>
                    <td class="py-1"><b>${it.product_name}</b><br><small class="text-muted">${q} x ₱${p.toFixed(2)}</small></td>
                    <td class="text-end align-top py-1 fw-bold">₱${sub.toFixed(2)}</td>
                </tr>
            `;
        });

        let html = `
            <div class="text-center mb-2">
                <h5 class="fw-bold my-0">*** R. TAIPAN HARDWARE ***</h5>
                <small class="d-block">R. Taipan Construction Supply and Services, OPC</small>
                <small class="d-block">190 Cabrera Rd. Sitio Burol Brgy. San Juan, Taytay, Rizal</small>
                <small class="d-block">Contact: 0907 456 5632</small>
                <div class="receipt-line"></div>
                <small><b>*** OFFICIAL RECEIPT ***</b></small>
                <div class="receipt-line"></div>
            </div>
            <p class="small mb-1"><b>Receipt No:</b> #${tx.id || txId}</p>
            <p class="small mb-1"><b>Date:</b> ${tx.transaction_date || 'N/A'}</p>
            <p class="small mb-1"><b>Cashier:</b> ${tx.cashier_name || 'Cashier'}</p>
            <div class="receipt-line"></div>
            <table width="100%" class="small mb-2">
                <tbody>${itemsRows}</tbody>
            </table>
            <div class="receipt-line"></div>
            <div class="d-flex justify-content-between small fw-bold mb-1">
                <span>TOTAL AMOUNT:</span>
                <span>₱${total.toFixed(2)}</span>
            </div>
            <div class="d-flex justify-content-between small text-muted mb-1">
                <span>Payment Method:</span>
                <span>${tx.payment_method || 'Cash'}</span>
            </div>
            ${(tx.payment_method === 'GCash' || tx.payment_method === 'Maya') && tx.reference_number ? `
            <div class="d-flex justify-content-between small text-muted mb-1">
                <span>Sender Mobile No:</span>
                <span>${tx.reference_number}</span>
            </div>` : ''}
            <div class="d-flex justify-content-between small text-muted mb-1">
                <span>Amount Paid:</span>
                <span>₱${paid.toFixed(2)}</span>
            </div>
            ${parseFloat(tx.outstanding_balance || 0) > 0 ? `
            <div class="d-flex justify-content-between small fw-bold text-danger mb-1">
                <span>Remaining Balance:</span>
                <span>₱${parseFloat(tx.outstanding_balance).toFixed(2)} (${tx.payment_status || 'Partially Paid'})</span>
            </div>` : `
            <div class="d-flex justify-content-between small fw-bold text-success mb-1">
                <span>Change:</span>
                <span>₱${change.toFixed(2)}</span>
            </div>`}
            <div class="receipt-line mt-2"></div>
            <div class="text-center mt-3">
                <small class="fw-bold d-block">Thank you for your purchase!</small>
            </div>
        `;
        container.innerHTML = html;
    })
    .catch(err => {
        container.innerHTML = '<div class="text-center text-danger py-3">Failed to load receipt: ' + err.message + '</div>';
    });
}

// 6. PRINT OFFICIAL CORRECTED RECEIPT (58mm Thermal)
function printCorrectedReceipt(reqId) {
    document.getElementById('receiptModalTitle').innerHTML = '<i class="bi bi-patch-check-fill text-success me-1"></i>Corrected Official Receipt';
    let container = document.getElementById('receiptContainer');
    container.innerHTML = '<div class="text-center py-4 text-muted">Loading corrected receipt...</div>';
    receiptModal.show();

    fetch(`../owner/get_correction_details.php?id=${reqId}`)
    .then(res => res.json())
    .then(data => {
        if (data.status !== 'success') {
            container.innerHTML = `<div class="text-center text-danger py-3">${data.message}</div>`;
            return;
        }

        let req = data.request;
        let corrItems = data.corrected_items || [];
        let diff = parseFloat(req.payment_difference || 0);

        let itemsRows = '';
        corrItems.forEach(it => {
            let p = parseFloat(it.price || 0);
            let q = parseInt(it.qty || 1);
            let sub = parseFloat(it.subtotal || (p * q));
            itemsRows += `
                <tr>
                    <td class="py-1"><b>${it.product_name}</b><br><small class="text-muted">${q} x ₱${p.toFixed(2)}</small></td>
                    <td class="text-end align-top py-1 fw-bold">₱${sub.toFixed(2)}</td>
                </tr>
            `;
        });

        let diffSummaryHtml = '';
        if (diff > 0) {
            diffSummaryHtml = `
                <div class="d-flex justify-content-between small fw-bold text-success mb-1">
                    <span>ADDITIONAL PAYMENT:</span>
                    <span>+₱${diff.toFixed(2)}</span>
                </div>
            `;
        } else if (diff < 0) {
            diffSummaryHtml = `
                <div class="d-flex justify-content-between small fw-bold text-danger mb-1">
                    <span>REFUND ISSUED:</span>
                    <span>-₱${Math.abs(diff).toFixed(2)}</span>
                </div>
            `;
        } else {
            diffSummaryHtml = `
                <div class="d-flex justify-content-between small text-muted mb-1">
                    <span>PRICE ADJUSTMENT:</span>
                    <span>₱0.00 (Even)</span>
                </div>
            `;
        }

        let lateBadge = req.is_late_request ? '<div class="badge bg-danger text-uppercase mb-1">Late Correction - Owner Override</div>' : '';

        let html = `
            <div class="text-center mb-2">
                <h5 class="fw-bold my-0">*** R. TAIPAN HARDWARE ***</h5>
                <small class="d-block">R. Taipan Construction Supply and Services, OPC</small>
                <small class="d-block">190 Cabrera Rd. Sitio Burol Brgy. San Juan, Taytay, Rizal</small>
                <small class="d-block">Contact: 0907 456 5632</small>
                <div class="receipt-line"></div>
                <h6 class="fw-bold my-1 text-uppercase text-danger">*** CORRECTED RECEIPT ***</h6>
                ${lateBadge}
                <div class="receipt-line"></div>
            </div>

            <p class="small mb-1"><b>Original Receipt:</b> #${req.transaction_id}</p>
            <p class="small mb-1"><b>Correction Ref No:</b> #REQ-${req.id}</p>
            <p class="small mb-1"><b>Approval Date:</b> ${req.completed_at || req.reviewed_at || 'N/A'}</p>
            <p class="small mb-1"><b>Cashier:</b> ${req.cashier_name}</p>
            <p class="small mb-1"><b>Approved By:</b> ${req.owner_name || req.late_reviewed_by || 'Owner'}</p>
            <p class="small mb-1"><b>Correction Type:</b> ${req.correction_type}</p>
            ${req.other_details ? `<p class="small mb-1"><b>Other Details:</b> ${req.other_details}</p>` : ''}
            <p class="small mb-1"><b>Reason:</b> ${req.reason}</p>
            ${req.is_late_request ? `<p class="small mb-1 text-danger"><b>Late Reason:</b> ${req.late_reason || 'N/A'}</p>` : ''}

            <div class="receipt-line"></div>
            <small class="fw-bold text-uppercase d-block mb-1 text-center">CORRECTED ITEM DETAILS</small>
            <table width="100%" class="small mb-2">
                <tbody>${itemsRows}</tbody>
            </table>

            <div class="receipt-line"></div>
            <div class="d-flex justify-content-between small text-muted mb-1">
                <span>Original Total:</span>
                <span>₱${parseFloat(req.original_total).toFixed(2)}</span>
            </div>
            <div class="d-flex justify-content-between small fw-bold mb-1">
                <span>NEW CORRECTED TOTAL:</span>
                <span>₱${parseFloat(req.proposed_total).toFixed(2)}</span>
            </div>
            ${diffSummaryHtml}
            <div class="d-flex justify-content-between small text-muted mb-1">
                <span>Settlement Method:</span>
                <span>${req.proposed_payment_method || req.payment_method || 'Cash'}</span>
            </div>
            ${(req.proposed_payment_method === 'GCash' || req.proposed_payment_method === 'Maya' || req.payment_method === 'GCash' || req.payment_method === 'Maya') && (req.proposed_reference_number || req.payment_reference) ? `
            <div class="d-flex justify-content-between small text-muted mb-1">
                <span>Sender Mobile No:</span>
                <span>${req.proposed_reference_number || req.payment_reference}</span>
            </div>` : ''}
            ${parseFloat(req.payment_correction_balance || 0) > 0 ? `
            <div class="d-flex justify-content-between small fw-bold text-danger mb-1">
                <span>Remaining Balance:</span>
                <span>₱${parseFloat(req.payment_correction_balance).toFixed(2)} (${req.payment_status || 'Partially Paid'})</span>
            </div>` : ''}

            <div class="receipt-line mt-2"></div>
            <div class="text-center mt-3">
                <small class="fw-bold d-block text-uppercase">*** OFFICIAL CORRECTED COPY ***</small>
                <small class="text-muted d-block">Inventory & Sales Adjusted</small>
            </div>
        `;

        container.innerHTML = html;
    })
    .catch(err => {
        container.innerHTML = '<div class="text-center text-danger py-3">Failed to load corrected receipt: ' + err.message + '</div>';
    });
}

function triggerPrintReceipt() {
    let receiptContent = document.getElementById("receiptContainer").innerHTML;
    let oldFrame = document.getElementById("receipt-print-frame");
    if (oldFrame) oldFrame.remove();

    let iframe = document.createElement("iframe");
    iframe.id = "receipt-print-frame";
    iframe.style.position = "fixed";
    iframe.style.bottom = "0";
    iframe.style.right = "0";
    iframe.style.width = "0";
    iframe.style.height = "0";
    iframe.style.border = "none";
    document.body.appendChild(iframe);

    let doc = iframe.contentWindow.document;
    doc.open();
    doc.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Receipt Print</title>
            <style>
                @page { size: 58mm auto; margin: 0; }
                body {
                    width: 58mm;
                    margin: 0;
                    padding: 8px 4px;
                    font-family: 'Courier New', Courier, monospace;
                    font-size: 11px;
                    color: #000;
                }
                .receipt-line { border-top: 1px dashed #000; margin: 8px 0; }
                .text-center { text-align: center; }
                .text-end { text-align: right; }
                .fw-bold { font-weight: bold; }
                .d-flex { display: flex; }
                .justify-content-between { justify-content: space-between; }
                .mb-1 { margin-bottom: 3px; }
                table { width: 100%; border-collapse: collapse; font-size: 11px; }
                small { font-size: 9.5px; }
            </style>
        </head>
        <body onload="window.print();">
            ${receiptContent}
        </body>
        </html>
    `);
    doc.close();
}

// 7. VIEW CORRECTION DETAILS MODAL
function viewCorrectionDetails(reqId) {
    document.getElementById('reqDetailsTitle').innerText = 'Correction Request #REQ-' + reqId;
    let body = document.getElementById('reqDetailsBody');
    body.innerHTML = '<div class="text-center py-4 text-muted">Loading request details...</div>';
    requestDetailsModal.show();

    fetch(`../owner/get_correction_details.php?id=${reqId}`)
    .then(res => res.json())
    .then(data => {
        if (data.status !== 'success') {
            body.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
            return;
        }

        let r = data.request;
        let origItems = data.original_items || [];
        let corrItems = data.corrected_items || [];
        let diff = parseFloat(r.payment_difference || 0);

        let origRows = origItems.map(i => `<tr><td>${i.product_name}</td><td>₱${parseFloat(i.price).toFixed(2)}</td><td>x${i.qty}</td><td class="text-end fw-bold">₱${parseFloat(i.subtotal).toFixed(2)}</td></tr>`).join('');
        let corrRows = corrItems.map(i => `<tr><td>${i.product_name}</td><td>₱${parseFloat(i.price).toFixed(2)}</td><td>x${i.qty}</td><td class="text-end fw-bold">₱${parseFloat(i.subtotal).toFixed(2)}</td></tr>`).join('');

        let diffBadge = '';
        if (diff > 0) diffBadge = `<span class="badge bg-success">+₱${diff.toFixed(2)} (Additional Payment)</span>`;
        else if (diff < 0) diffBadge = `<span class="badge bg-danger">-₱${Math.abs(diff).toFixed(2)} (Refund Due)</span>`;
        else diffBadge = `<span class="badge bg-secondary">₱0.00 (No Diff)</span>`;

        let categoryBadge = r.is_late_request
            ? `<span class="badge bg-danger"><i class="bi bi-clock-history me-1"></i>Late Request (Owner Override)</span>`
            : `<span class="badge bg-light text-dark border">Normal Request (Within 24h)</span>`;

        let lateAlert = r.is_late_request ? `
            <div class="alert alert-danger mb-3">
                <h6 class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Late Request Explanation:</h6>
                <p class="mb-0">${r.late_reason || 'N/A'}</p>
                ${r.late_review_remarks ? `<div class="mt-2 text-dark small"><strong>Owner Remarks:</strong> ${r.late_review_remarks}</div>` : ''}
            </div>
        ` : '';

        let othersAlert = (r.correction_type === 'Others' && r.other_details) ? `
            <div class="alert alert-secondary mb-3">
                <h6 class="fw-bold mb-1"><i class="bi bi-info-circle me-1"></i>Specific Details (Others):</h6>
                <p class="mb-0">${r.other_details}</p>
            </div>
        ` : '';

        let evidenceHtml = r.evidence_path ? `<div class="mt-2"><a href="../${r.evidence_path}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-paperclip me-1"></i>View Uploaded Evidence</a></div>` : '<span class="text-muted small">None provided</span>';

        let rejectionHtml = r.status === 'Rejected' ? `
            <div class="alert alert-danger mt-3">
                <h6 class="fw-bold mb-1"><i class="bi bi-x-octagon-fill me-1"></i>Rejection Reason from Owner:</h6>
                <p class="mb-0">${r.rejection_reason || 'No specific reason provided.'}</p>
            </div>
        ` : '';

        body.innerHTML = `
            <div class="row g-2 mb-3">
                <div class="col-sm-4"><strong>POS Receipt:</strong> #${r.transaction_id}</div>
                <div class="col-sm-4"><strong>Date Requested:</strong> ${r.created_at}</div>
                <div class="col-sm-4"><strong>Status:</strong> <span class="badge bg-dark">${r.status}</span></div>
                <div class="col-sm-4"><strong>Category:</strong> ${categoryBadge}</div>
                <div class="col-sm-4"><strong>Correction Type:</strong> <span class="badge bg-light text-dark border">${r.correction_type}</span></div>
                <div class="col-sm-4"><strong>Financial Diff:</strong> ${diffBadge}</div>
            </div>

            ${lateAlert}
            ${othersAlert}

            <div class="card bg-light border p-3 mb-3">
                <strong class="small text-muted text-uppercase d-block mb-1">Reason / Explanation:</strong>
                <div>${r.reason}</div>
                <div class="mt-2"><strong class="small text-muted text-uppercase">Supporting Evidence:</strong> ${evidenceHtml}</div>
            </div>

            ${rejectionHtml}

            <div class="row g-3">
                <div class="col-md-6">
                    <h6 class="fw-bold text-secondary">Original Items</h6>
                    <table class="table table-sm border item-table">
                        <thead class="table-light"><tr><th>Product</th><th>Price</th><th>Qty</th><th class="text-end">Total</th></tr></thead>
                        <tbody>${origRows}</tbody>
                        <tfoot class="table-light"><tr><th colspan="3">Total:</th><th class="text-end">₱${parseFloat(r.original_total).toFixed(2)}</th></tr></tfoot>
                    </table>
                </div>
                <div class="col-md-6">
                    <h6 class="fw-bold text-primary">Requested Corrected Items</h6>
                    <table class="table table-sm border item-table">
                        <thead class="table-light"><tr><th>Product</th><th>Price</th><th>Qty</th><th class="text-end">Total</th></tr></thead>
                        <tbody>${corrRows}</tbody>
                        <tfoot class="table-light"><tr><th colspan="3">Total:</th><th class="text-end text-primary">₱${parseFloat(r.proposed_total).toFixed(2)}</th></tr></tfoot>
                    </table>
                </div>
            </div>
        `;
    })
    .catch(err => {
        body.innerHTML = `<div class="alert alert-danger">Error: ${err.message}</div>`;
    });
}
</script>
</body>
</html>
