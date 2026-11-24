<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/correction_functions.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'owner') {
    header("Location: ../login.php");
    exit();
}

$owner_name = $_SESSION['username'] ?? "Owner";

// Filters and Tab
$current_tab = $_GET['tab'] ?? 'pending';
$category_filter = $_GET['category'] ?? 'all'; // 'all', 'normal', 'late'
$search = trim($_GET['search'] ?? '');
$filter_date = $_GET['filter_date'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// 1. Metric Counts for Summary Cards
$counts_query = $pdo->query("
    SELECT
        COUNT(*) FILTER (WHERE status = 'Pending Review') AS total_pending,
        COUNT(*) FILTER (WHERE status IN ('Approved', 'Pending Additional Payment', 'Pending Refund')) AS total_approved,
        COUNT(*) FILTER (WHERE status = 'Rejected') AS total_rejected,
        COUNT(*) FILTER (WHERE status = 'Completed') AS total_completed,
        COUNT(*) FILTER (WHERE is_late_request = TRUE) AS total_late,
        COUNT(*) FILTER (WHERE is_late_request = FALSE OR is_late_request IS NULL) AS total_normal,
        COUNT(*) AS total_all
    FROM pos_correction_requests
");
$counts = $counts_query->fetch(PDO::FETCH_ASSOC);

$total_pending = (int)($counts['total_pending'] ?? 0);
$total_approved = (int)($counts['total_approved'] ?? 0);
$total_rejected = (int)($counts['total_rejected'] ?? 0);
$total_completed = (int)($counts['total_completed'] ?? 0);
$total_late = (int)($counts['total_late'] ?? 0);
$total_normal = (int)($counts['total_normal'] ?? 0);
$total_all = (int)($counts['total_all'] ?? 0);

// 2. Build Query Based on Current Tab & Filters
$where_clauses = ["1=1"];
$params = [];

if ($current_tab === 'pending') {
    $where_clauses[] = "pcr.status = 'Pending Review'";
} elseif ($current_tab === 'approved') {
    $where_clauses[] = "pcr.status IN ('Approved', 'Pending Additional Payment', 'Pending Refund')";
} elseif ($current_tab === 'rejected') {
    $where_clauses[] = "pcr.status = 'Rejected'";
} elseif ($current_tab === 'completed') {
    $where_clauses[] = "pcr.status = 'Completed'";
}

if ($category_filter === 'normal') {
    $where_clauses[] = "(pcr.is_late_request = FALSE OR pcr.is_late_request IS NULL)";
} elseif ($category_filter === 'late') {
    $where_clauses[] = "pcr.is_late_request = TRUE";
}

if (!empty($search)) {
    $where_clauses[] = "(CAST(pcr.id AS TEXT) LIKE ? OR CAST(pcr.transaction_id AS TEXT) LIKE ? OR LOWER(pcr.cashier_name) LIKE LOWER(?) OR LOWER(pcr.correction_type) LIKE LOWER(?))";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($filter_date)) {
    $where_clauses[] = "DATE(pcr.created_at) = ?";
    $params[] = $filter_date;
}

$where_sql = implode(" AND ", $where_clauses);

// Count for pagination
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM pos_correction_requests pcr WHERE $where_sql");
$count_stmt->execute($params);
$total_filtered = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total_filtered / $limit));

// Fetch records
$data_sql = "
    SELECT pcr.*, pt.transaction_date AS orig_tx_date
    FROM pos_correction_requests pcr
    JOIN pos_transaction pt ON pcr.transaction_id = pt.id
    WHERE $where_sql
    ORDER BY pcr.id DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($data_sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Transaction Correction Management | Owner Panel</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<style>
body {
    margin: 0;
    overflow-x: hidden;
    background: #f8f9fa;
    padding-top: 56px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}
.navbar {
    height: 56px;
    z-index: 1100;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
}
.sidebar {
    position: fixed;
    top: 56px;
    left: 0;
    width: 260px;
    height: calc(100vh - 56px);
    background: #001f4d;
    padding: 20px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    box-shadow: 4px 0 15px rgba(0,0,0,0.15);
    transition: transform 0.3s ease;
    z-index: 1040;
}
.sidebar-link {
    color: #adb5bd;
    text-decoration: none;
    padding: 12px 14px;
    border-radius: 8px;
    margin-bottom: 8px;
    font-size: 15px;
    font-weight: 500;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: 0.3s;
}
.sidebar-link-content {
    display: flex;
    align-items: center;
}
.sidebar-link i {
    margin-right: 8px;
    font-size: 1.1rem;
}
.sidebar-link:hover {
    background: #0d6efd;
    color: #fff;
    padding-left: 20px;
}
.sidebar-link.active {
    background: #0d6efd;
    color: #fff;
}
.logout-section {
    margin-top: auto;
    padding-top: 15px;
    border-top: 1px solid rgba(255,255,255,0.2);
}
.main-content {
    margin-left: 260px;
    padding: 24px;
    min-height: calc(100vh - 56px);
}
.sidebar::-webkit-scrollbar { width: 5px; }
.sidebar::-webkit-scrollbar-thumb { background: #0d6efd; border-radius: 10px; }
.stat-card {
    border-radius: 12px;
    border: none;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(0,0,0,0.08);
}
.stat-pending { border-left: 5px solid #ffc107; }
.stat-approved { border-left: 5px solid #0dcaf0; }
.stat-completed { border-left: 5px solid #198754; }
.stat-rejected { border-left: 5px solid #dc3545; }
.stat-late { border-left: 5px solid #fd7e14; }
.diff-pos { color: #198754; font-weight: bold; }
.diff-neg { color: #dc3545; font-weight: bold; }
.diff-zero { color: #6c757d; font-weight: bold; }
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
}
.receipt-line { border-top: 1px dashed #444; margin: 10px 0; }
@media (max-width: 991.98px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.show { transform: translateX(0); }
    .main-content { margin-left: 0; }
}
</style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-dark px-3" style="background-color:#051c3c;">
    <div class="d-flex align-items-center w-100 justify-content-between">
        <div class="d-flex align-items-center gap-2">
            <span class="navbar-brand fw-bold mb-0">Owner Panel</span>
        </div>
        <div class="d-flex align-items-center text-white gap-3">
            <span>Welcome, <strong><?= htmlspecialchars($owner_name); ?></strong></span>
            <button class="btn btn-outline-light btn-sm d-lg-none" type="button" id="sidebarToggle" aria-label="Toggle sidebar">
                <i class="bi bi-list fs-5"></i>
            </button>
        </div>
    </div>
</nav>

<!-- SIDEBAR -->
<div class="sidebar" id="appSidebar">
    <a href="dashboard.php" class="sidebar-link">
        <div class="sidebar-link-content"><i class="bi bi-graph-up-arrow"></i> Dashboard</div>
    </a>
    <a href="sales.php" class="sidebar-link">
        <div class="sidebar-link-content"><i class="bi bi-cash-stack"></i> Sales</div>
    </a>
    <a href="inventory.php" class="sidebar-link">
        <div class="sidebar-link-content"><i class="bi bi-box-seam"></i> Inventory</div>
    </a>
    <a href="orders.php" class="sidebar-link">
        <div class="sidebar-link-content"><i class="bi bi-clipboard-check"></i> Order Mgt. Monitoring</div>
    </a>
    <a href="corrections.php" class="sidebar-link active">
        <div class="sidebar-link-content"><i class="bi bi-arrow-repeat"></i> Corrections</div>
        <?php if ($total_pending > 0): ?>
        <span class="badge bg-warning text-dark"><?= $total_pending; ?></span>
        <?php endif; ?>
    </a>
    <a href="history.php" class="sidebar-link">
        <div class="sidebar-link-content"><i class="bi bi-clock-history"></i> History</div>
    </a>
    <a href="auditTrail.php" class="sidebar-link">
        <div class="sidebar-link-content"><i class="bi bi-shield-lock"></i> Audit Trail</div>
    </a>
    <a href="accountManagement.php" class="sidebar-link">
        <div class="sidebar-link-content"><i class="bi bi-person-gear"></i> Account Management</div>
    </a>
    <div class="logout-section">
        <a href="../logout.php" class="sidebar-link text-danger" onclick="return confirm('Are you sure you want to log out?')">
            <div class="sidebar-link-content"><i class="bi bi-box-arrow-right"></i> Logout</div>
        </a>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-content">

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h3 class="fw-bold mb-0 text-dark">Transaction Correction Management</h3>
            <p class="text-muted small mb-0">Centralized review, stock validation, and financial settlement for cashier correction requests.</p>
        </div>
    </div>

    <!-- SUMMARY CARDS -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card stat-card stat-pending shadow-sm h-100 bg-white">
                <div class="card-body p-3">
                    <span class="text-muted small fw-bold text-uppercase">Total Pending Requests</span>
                    <h2 class="fw-bold text-warning mb-0 mt-1"><?= $total_pending; ?></h2>
                    <small class="text-muted">Awaiting your approval</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stat-card stat-approved shadow-sm h-100 bg-white">
                <div class="card-body p-3">
                    <span class="text-muted small fw-bold text-uppercase">Total Approved Requests</span>
                    <h2 class="fw-bold text-info mb-0 mt-1"><?= $total_approved; ?></h2>
                    <small class="text-muted">Approved & pending settlement</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stat-card stat-completed shadow-sm h-100 bg-white">
                <div class="card-body p-3">
                    <span class="text-muted small fw-bold text-uppercase">Total Completed Corrections</span>
                    <h2 class="fw-bold text-success mb-0 mt-1"><?= $total_completed; ?></h2>
                    <small class="text-muted">Synchronized & settled</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stat-card stat-rejected shadow-sm h-100 bg-white">
                <div class="card-body p-3">
                    <span class="text-muted small fw-bold text-uppercase">Total Rejected Requests</span>
                    <h2 class="fw-bold text-danger mb-0 mt-1"><?= $total_rejected; ?></h2>
                    <small class="text-muted">Declined requests</small>
                </div>
            </div>
        </div>
    </div>

    <!-- TABS NAVIGATION -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white p-0 border-bottom">
            <ul class="nav nav-tabs border-0" id="correctionTabs">
                <li class="nav-item">
                    <a class="nav-link <?= $current_tab === 'pending' ? 'active fw-bold' : 'text-muted'; ?>" href="?tab=pending&category=<?= urlencode($category_filter); ?>">
                        <i class="bi bi-hourglass-split text-warning me-1"></i> Pending Requests
                        <?php if ($total_pending > 0): ?>
                        <span class="badge bg-warning text-dark ms-1"><?= $total_pending; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_tab === 'approved' ? 'active fw-bold' : 'text-muted'; ?>" href="?tab=approved&category=<?= urlencode($category_filter); ?>">
                        <i class="bi bi-check-circle text-info me-1"></i> Approved Requests
                        <?php if ($total_approved > 0): ?>
                        <span class="badge bg-info text-dark ms-1"><?= $total_approved; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_tab === 'completed' ? 'active fw-bold' : 'text-muted'; ?>" href="?tab=completed&category=<?= urlencode($category_filter); ?>">
                        <i class="bi bi-patch-check text-success me-1"></i> Completed Corrections
                        <?php if ($total_completed > 0): ?>
                        <span class="badge bg-success ms-1"><?= $total_completed; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_tab === 'rejected' ? 'active fw-bold' : 'text-muted'; ?>" href="?tab=rejected&category=<?= urlencode($category_filter); ?>">
                        <i class="bi bi-x-circle text-danger me-1"></i> Rejected Requests
                        <?php if ($total_rejected > 0): ?>
                        <span class="badge bg-danger ms-1"><?= $total_rejected; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_tab === 'all' ? 'active fw-bold' : 'text-muted'; ?>" href="?tab=all&category=<?= urlencode($category_filter); ?>">
                        <i class="bi bi-collection text-secondary me-1"></i> All Requests
                        <span class="badge bg-secondary ms-1"><?= $total_all; ?></span>
                    </a>
                </li>
            </ul>
        </div>

        <div class="card-body">

            <!-- Category Filter Sub-Pills -->
            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <span class="small fw-bold text-muted me-2"><i class="bi bi-funnel me-1"></i>Request Window:</span>
                <a href="?tab=<?= urlencode($current_tab); ?>&category=all&search=<?= urlencode($search); ?>&filter_date=<?= urlencode($filter_date); ?>"
                   class="btn btn-sm <?= $category_filter === 'all' ? 'btn-dark' : 'btn-outline-secondary'; ?>">
                    All (<?= $total_all; ?>)
                </a>
                <a href="?tab=<?= urlencode($current_tab); ?>&category=normal&search=<?= urlencode($search); ?>&filter_date=<?= urlencode($filter_date); ?>"
                   class="btn btn-sm <?= $category_filter === 'normal' ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                    <i class="bi bi-check-circle me-1"></i>Normal Requests (<?= $total_normal; ?>)
                </a>
                <a href="?tab=<?= urlencode($current_tab); ?>&category=late&search=<?= urlencode($search); ?>&filter_date=<?= urlencode($filter_date); ?>"
                   class="btn btn-sm <?= $category_filter === 'late' ? 'btn-danger' : 'btn-outline-danger'; ?>">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>Late Correction Requests (<?= $total_late; ?>)
                </a>
            </div>

            <!-- Filter & Search Bar -->
            <form method="GET" class="row g-2 mb-3">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($current_tab); ?>">
                <input type="hidden" name="category" value="<?= htmlspecialchars($category_filter); ?>">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Search Request #, Receipt #, Cashier..." value="<?= htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <input type="date" name="filter_date" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-filter"></i> Apply Filter</button>
                </div>
                <div class="col-md-2">
                    <a href="?tab=<?= htmlspecialchars($current_tab); ?>" class="btn btn-outline-secondary btn-sm w-100">Clear</a>
                </div>
            </form>

            <!-- Table of Correction Requests -->
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Request ID</th>
                            <th>POS Receipt #</th>
                            <th>Category</th>
                            <th>Cashier Name</th>
                            <th>Tx Completed</th>
                            <th>24h Deadline</th>
                            <th>Request Date</th>
                            <th>Correction Type</th>
                            <th>Original Amount</th>
                            <th>Proposed Amount</th>
                            <th>Financial Diff</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($requests)): ?>
                            <?php foreach ($requests as $r): ?>
                                <?php
                                $diff = (float)$r['payment_difference'];
                                $is_late = !empty($r['is_late_request']);
                                $tx_ts = strtotime($r['orig_tx_date']);
                                $deadline_ts = !empty($r['correction_deadline']) ? strtotime($r['correction_deadline']) : ($tx_ts + 24 * 3600);
                                $req_ts = strtotime($r['created_at']);
                                $diff_from_deadline = $req_ts - $deadline_ts;
                                ?>
                                <tr>
                                    <td class="fw-bold">#REQ-<?= $r['id']; ?></td>
                                    <td>
                                        <a href="javascript:void(0)" class="fw-bold text-decoration-none" onclick="viewTransactionReceipt(<?= $r['transaction_id']; ?>)">
                                            #<?= $r['transaction_id']; ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php if ($is_late): ?>
                                            <span class="badge bg-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>Late Request</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark border">Normal</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($r['cashier_name']); ?></td>
                                    <td class="small text-muted"><?= date('M d, Y h:i A', $tx_ts); ?></td>
                                    <td class="small">
                                        <div><?= date('M d, Y h:i A', $deadline_ts); ?></div>
                                        <?php if ($is_late): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size: 0.72rem;">
                                                Late by <?= floor($diff_from_deadline / 3600); ?>h
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.72rem;">
                                                Within 24h
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><?= date('M d, Y h:i A', $req_ts); ?></td>
                                    <td>
                                        <span class="badge bg-light text-dark border fw-semibold"><?= htmlspecialchars($r['correction_type']); ?></span>
                                        <?php if ($r['correction_type'] === 'Others' && !empty($r['other_details'])): ?>
                                            <small class="d-block text-muted text-truncate" style="max-width: 140px;"><?= htmlspecialchars($r['other_details']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>₱<?= number_format((float)$r['original_total'], 2); ?></td>
                                    <td class="fw-bold text-primary">₱<?= number_format((float)$r['proposed_total'], 2); ?></td>
                                    <td>
                                        <?php if ($diff > 0): ?>
                                            <span class="diff-pos">+₱<?= number_format($diff, 2); ?></span>
                                        <?php elseif ($diff < 0): ?>
                                            <span class="diff-neg">-₱<?= number_format(abs($diff), 2); ?></span>
                                        <?php else: ?>
                                            <span class="diff-zero">₱0.00</span>
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
                                        <button class="btn btn-sm btn-primary" onclick="openDetailsModal(<?= $r['id']; ?>)">
                                            <i class="bi bi-eye me-1"></i> View Details
                                        </button>
                                        <?php if ($r['status'] === 'Completed'): ?>
                                        <button class="btn btn-sm btn-outline-dark ms-1" onclick="printCorrectedReceipt(<?= $r['id']; ?>)">
                                            <i class="bi bi-printer"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="13" class="text-center py-4 text-muted">No correction requests found matching the filter.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="d-flex justify-content-between align-items-center mt-3">
                <small class="text-muted">Showing <?= min($total_filtered, $offset + 1); ?> - <?= min($total_filtered, $offset + $limit); ?> of <?= $total_filtered; ?> records</small>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?= $page === $i ? 'active' : ''; ?>">
                            <a class="page-link" href="?tab=<?= urlencode($current_tab); ?>&category=<?= urlencode($category_filter); ?>&search=<?= urlencode($search); ?>&filter_date=<?= urlencode($filter_date); ?>&page=<?= $i; ?>"><?= $i; ?></a>
                        </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>

        </div>
    </div>

</div>

<!-- ================= MODAL: CORRECTION REQUEST DETAILS (BEFORE & AFTER COMPARISON) ================= -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold" id="detailsModalTitle"><i class="bi bi-card-checklist me-2"></i>Correction Request Comparison & Review</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="detailsModalBody">
                <div class="text-center py-5 text-muted"><div class="spinner-border text-primary me-2"></div> Loading comparison and validating stock...</div>
            </div>
            <div class="modal-footer d-flex justify-content-between" id="detailsModalFooter">
                <div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
                <div id="actionButtonsContainer">
                    <!-- Populated dynamically: Approve / Reject / Settle buttons -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ================= MODAL: APPROVE CONFIRMATION WITH REMARKS ================= -->
<div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold" id="approveModalTitle"><i class="bi bi-check-circle me-2"></i>Approve Correction Request</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="approveForm">
                    <input type="hidden" id="approve_request_id">
                    <div id="approveLateNotice" class="alert alert-warning mb-3" style="display: none;">
                        <h6 class="fw-bold mb-1"><i class="bi bi-shield-exclamation me-1"></i>Owner Override Required</h6>
                        <small>This request was submitted beyond the normal 24-hour deadline. By approving, you are issuing an official Owner Override.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Owner Approval Remarks (Optional)</label>
                        <textarea id="approval_remarks_text" class="form-control" rows="2" placeholder="e.g. Approved per customer receipt verification and manager inspection..."></textarea>
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success fw-bold" id="confirmApproveBtn">
                            <i class="bi bi-check-circle me-1"></i> Confirm Approval
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ================= MODAL: REJECT REASON ================= -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-x-octagon-fill me-2"></i>Reject Correction Request</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="rejectForm">
                    <input type="hidden" id="reject_request_id">
                    <p class="small text-muted mb-2">Please specify the exact reason why this correction request is being rejected. The cashier will be notified with this message.</p>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Rejection Reason <span class="text-danger">*</span></label>
                        <textarea id="rejection_reason_text" class="form-control" rows="3" placeholder="e.g. Products do not match returned items, customer receipt does not qualify, etc." required></textarea>
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger fw-bold" id="confirmRejectBtn">
                            <i class="bi bi-x-circle me-1"></i> Confirm Rejection
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
                <h6 class="modal-title fw-bold mb-0" id="receiptModalTitle">Receipt</h6>
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
                    <i class="bi bi-printer me-1"></i> Print (58mm)
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Thermal Print Frame -->
<iframe id="receipt-print-frame" style="display:none; width:0; height:0;"></iframe>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Sidebar Toggle
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebar = document.getElementById('appSidebar');
if (sidebarToggle && sidebar) {
    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.toggle('show');
    });
}

const detailsModal = new bootstrap.Modal(document.getElementById('detailsModal'));
const approveModal = new bootstrap.Modal(document.getElementById('approveModal'));
const rejectModal = new bootstrap.Modal(document.getElementById('rejectModal'));
const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));

let currentOpenRequestId = null;
let currentRequestData = null;

// 1. OPEN CORRECTION DETAILS MODAL (SIDE-BY-SIDE BEFORE & AFTER)
function openDetailsModal(reqId) {
    currentOpenRequestId = reqId;
    let body = document.getElementById('detailsModalBody');
    let footer = document.getElementById('actionButtonsContainer');

    body.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border text-primary me-2"></div> Loading comparison and validating stock...</div>';
    footer.innerHTML = '';
    detailsModal.show();

    fetch(`get_correction_details.php?id=${reqId}`)
    .then(res => res.json())
    .then(data => {
        if (data.status !== 'success') {
            body.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
            return;
        }

        currentRequestData = data.request;
        let r = data.request;
        let origItems = data.original_items || [];
        let corrItems = data.corrected_items || [];
        let stockVal = data.stock_validation || {};
        let diff = parseFloat(r.payment_difference || 0);
        let isLate = !!r.is_late_request;

        // Build Original Items Rows
        let origRows = origItems.map(i => `
            <tr>
                <td><b>${i.product_name}</b></td>
                <td>₱${parseFloat(i.price).toFixed(2)}</td>
                <td>x${i.qty}</td>
                <td class="text-end fw-bold">₱${parseFloat(i.subtotal).toFixed(2)}</td>
            </tr>
        `).join('');

        // Build Corrected Items Rows
        let corrRows = corrItems.map(i => `
            <tr>
                <td><b>${i.product_name}</b></td>
                <td>₱${parseFloat(i.price).toFixed(2)}</td>
                <td>x${i.qty}</td>
                <td class="text-end fw-bold">₱${parseFloat(i.subtotal).toFixed(2)}</td>
            </tr>
        `).join('');

        // Stock impact cards
        let stockDetailsHtml = '';
        if (stockVal.details && stockVal.details.length > 0) {
            stockDetailsHtml = stockVal.details.map(d => {
                let badge = (d.net_needed <= 0 || d.current_stock >= d.net_needed)
                    ? `<span class="badge bg-success">In Stock (Available: ${d.current_stock})</span>`
                    : `<span class="badge bg-danger">Insufficient (Available: ${d.current_stock}, Needs: ${d.net_needed})</span>`;
                return `
                    <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                        <span class="small"><b>${d.name}</b> <span class="text-muted">(${d.needed_qty} requested)</span></span>
                        <div>${badge}</div>
                    </div>
                `;
            }).join('');
        }

        let stockAlert = '';
        if (!stockVal.is_valid) {
            stockAlert = `
                <div class="alert alert-danger mb-3">
                    <h6 class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Stock Validation Warning:</h6>
                    <div>${stockVal.message}</div>
                    <small class="text-muted d-block mt-1">Approval is blocked until inventory stock for replacement items is resolved.</small>
                </div>
            `;
        } else {
            stockAlert = `
                <div class="alert alert-success py-2 mb-3 small">
                    <i class="bi bi-check-circle-fill me-1"></i> <b>Stock Verified:</b> Sufficient inventory exists for all replacement items.
                </div>
            `;
        }

        // Late Request Alert Banner
        let lateBanner = '';
        if (isLate) {
            lateBanner = `
                <div class="alert alert-danger mb-3 border-danger">
                    <div class="d-flex align-items-center mb-1">
                        <i class="bi bi-exclamation-octagon-fill fs-5 me-2 text-danger"></i>
                        <h6 class="fw-bold mb-0 text-danger">LATE CORRECTION REQUEST – BEYOND 24-HOUR DEADLINE</h6>
                    </div>
                    <div class="small mb-2">
                        <strong>Transaction Completed:</strong> ${r.orig_tx_date || 'N/A'} | 
                        <strong>24h Deadline:</strong> ${r.correction_deadline || 'N/A'} | 
                        <strong>Requested At:</strong> ${r.created_at}
                    </div>
                    <div class="bg-white p-3 rounded border">
                        <strong class="text-danger small text-uppercase d-block mb-1">Mandatory Cashier Explanation for Delay:</strong>
                        <p class="mb-0 text-dark">${r.late_reason || 'No explanation provided.'}</p>
                    </div>
                    ${r.late_reviewed_by ? `
                    <div class="mt-2 small text-muted">
                        <strong>Reviewed By:</strong> ${r.late_reviewed_by} on ${r.late_reviewed_at} 
                        ${r.late_review_remarks ? `<br><strong>Remarks:</strong> ${r.late_review_remarks}` : ''}
                    </div>` : ''}
                </div>
            `;
        }

        // Others Details Banner
        let othersBanner = (r.correction_type === 'Others' && r.other_details) ? `
            <div class="alert alert-secondary mb-3">
                <strong class="small text-uppercase text-muted d-block mb-1">Specific Issue Details (Others):</strong>
                <p class="mb-0">${r.other_details}</p>
            </div>
        ` : '';

        // Difference Badge
        let diffBanner = '';
        if (diff > 0) {
            diffBanner = `
                <div class="alert alert-warning mb-3 d-flex justify-content-between align-items-center">
                    <div>
                        <strong class="d-block text-dark"><i class="bi bi-wallet2 me-1"></i> Additional Payment Required:</strong>
                        <span class="small">The customer must pay an additional difference before transaction is completed.</span>
                    </div>
                    <div class="fs-4 fw-bold text-success">+₱${diff.toFixed(2)}</div>
                </div>
            `;
        } else if (diff < 0) {
            diffBanner = `
                <div class="alert alert-info mb-3 d-flex justify-content-between align-items-center">
                    <div>
                        <strong class="d-block text-dark"><i class="bi bi-arrow-return-left me-1"></i> Customer Refund Due:</strong>
                        <span class="small">The store owes a refund to the customer before transaction is completed.</span>
                    </div>
                    <div class="fs-4 fw-bold text-danger">-₱${Math.abs(diff).toFixed(2)}</div>
                </div>
            `;
        } else {
            diffBanner = `
                <div class="alert alert-secondary mb-3 d-flex justify-content-between align-items-center">
                    <div>
                        <strong class="d-block text-dark"><i class="bi bi-arrow-left-right me-1"></i> Even Transaction (No Financial Difference):</strong>
                        <span class="small">Original and corrected amounts are equal. Upon approval, inventory will immediately synchronize.</span>
                    </div>
                    <div class="fs-4 fw-bold text-muted">₱0.00</div>
                </div>
            `;
        }

        let evidenceHtml = r.evidence_path
            ? `<a href="../${r.evidence_path}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-paperclip me-1"></i>View Uploaded Evidence</a>`
            : '<span class="text-muted small">No file attached</span>';

        let rejectionHtml = r.status === 'Rejected' ? `
            <div class="alert alert-danger mb-3">
                <h6 class="fw-bold mb-1"><i class="bi bi-x-octagon-fill me-1"></i>Rejection Reason:</h6>
                <p class="mb-0">${r.rejection_reason || 'N/A'}</p>
            </div>
        ` : '';

        let remarksHtml = r.owner_remarks ? `
            <div class="alert alert-light border mb-3">
                <strong class="small text-muted text-uppercase d-block mb-1">Owner Remarks:</strong>
                <p class="mb-0">${r.owner_remarks}</p>
            </div>
        ` : '';

        body.innerHTML = `
            <!-- Top Metadata -->
            <div class="card bg-light border p-3 mb-3">
                <div class="row g-2">
                    <div class="col-sm-3"><strong>Request ID:</strong> #REQ-${r.id}</div>
                    <div class="col-sm-3"><strong>POS Receipt:</strong> <a href="javascript:void(0)" onclick="viewTransactionReceipt(${r.transaction_id})">#${r.transaction_id}</a></div>
                    <div class="col-sm-3"><strong>Cashier:</strong> ${r.cashier_name}</div>
                    <div class="col-sm-3"><strong>Status:</strong> <span class="badge bg-dark">${r.status}</span></div>
                    <div class="col-sm-3"><strong>Correction Type:</strong> <span class="badge bg-light text-dark border fw-bold">${r.correction_type}</span></div>
                    <div class="col-sm-3"><strong>Request Date:</strong> ${r.created_at}</div>
                    <div class="col-sm-3"><strong>Category:</strong> ${isLate ? '<span class="badge bg-danger">Late Request</span>' : '<span class="badge bg-light text-dark border">Normal (24h)</span>'}</div>
                    <div class="col-sm-3"><strong>Reviewed By:</strong> ${r.owner_name || r.late_reviewed_by || 'Pending'}</div>
                </div>
            </div>

            ${lateBanner}
            ${othersBanner}
            ${rejectionHtml}
            ${remarksHtml}
            ${stockAlert}
            ${diffBanner}

            <!-- Reason & Evidence -->
            <div class="card border p-3 mb-3">
                <strong class="small text-muted text-uppercase d-block mb-1">Cashier's Explanation:</strong>
                <p class="mb-2">${r.reason}</p>
                <div><strong class="small text-muted text-uppercase me-2">Supporting Evidence:</strong> ${evidenceHtml}</div>
            </div>

            <!-- BEFORE & AFTER COMPARISON -->
            <div class="row g-3 mb-3">
                <!-- ORIGINAL TRANSACTION -->
                <div class="col-lg-6">
                    <div class="card h-100 border">
                        <div class="card-header bg-light py-2">
                            <h6 class="mb-0 fw-bold text-secondary"><i class="bi bi-clock-history me-1"></i>ORIGINAL TRANSACTION</h6>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-sm mb-0">
                                <thead class="table-light"><tr><th>Product</th><th>Price</th><th>Qty</th><th class="text-end">Subtotal</th></tr></thead>
                                <tbody>${origRows}</tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="3">Original Total:</th>
                                        <th class="text-end fw-bold">₱${parseFloat(r.original_total).toFixed(2)}</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- REQUESTED CORRECTION -->
                <div class="col-lg-6">
                    <div class="card h-100 border">
                        <div class="card-header bg-light py-2">
                            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-pencil-square me-1"></i>REQUESTED CORRECTION</h6>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-sm mb-0">
                                <thead class="table-light"><tr><th>Product</th><th>Price</th><th>Qty</th><th class="text-end">Subtotal</th></tr></thead>
                                <tbody>${corrRows}</tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="3">Corrected Total:</th>
                                        <th class="text-end fw-bold text-primary">₱${parseFloat(r.proposed_total).toFixed(2)}</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- INVENTORY IMPACT BREAKDOWN -->
            <div class="card border p-3">
                <h6 class="fw-bold mb-2 text-dark"><i class="bi bi-boxes me-2"></i>Inventory Synchronization Impact</h6>
                <div class="small text-muted mb-2">When this correction is completed, original items will be automatically returned to stock and replacement items will be deducted.</div>
                ${stockDetailsHtml}
            </div>
        `;

        // Action Buttons Setup
        let btns = '';
        if (r.status === 'Pending Review') {
            let approveDisabled = !stockVal.is_valid ? 'disabled title="Cannot approve: insufficient replacement stock"' : '';
            let approveLabel = isLate ? '<i class="bi bi-shield-check me-1"></i> Approve Late Request (Owner Override)' : '<i class="bi bi-check-circle me-1"></i> Approve Request';
            let approveClass = isLate ? 'btn btn-warning fw-bold px-3' : 'btn btn-success fw-bold px-4';

            btns = `
                <button type="button" class="btn btn-outline-danger me-2" onclick="promptRejectModal(${r.id})">
                    <i class="bi bi-x-circle me-1"></i> Reject Request
                </button>
                <button type="button" class="${approveClass}" ${approveDisabled} onclick="promptApproveModal(${r.id}, ${isLate ? 1 : 0})">
                    ${approveLabel}
                </button>
            `;
        } else if (r.status === 'Completed') {
            btns = `
                <button type="button" class="btn btn-dark fw-bold" onclick="printCorrectedReceipt(${r.id})">
                    <i class="bi bi-printer me-1"></i> Print Corrected Receipt
                </button>
            `;
        } else if (r.status === 'Pending Additional Payment' || r.status === 'Pending Refund') {
            btns = `
                <span class="text-muted small me-3">Approved on ${r.reviewed_at || ''}. Awaiting settlement.</span>
                <button type="button" class="btn btn-success fw-bold" onclick="ownerSettle(${r.id}, '${r.status}', ${Math.abs(diff)})">
                    <i class="bi bi-wallet2 me-1"></i> Settle Now as Owner
                </button>
            `;
        }
        footer.innerHTML = btns;
    })
    .catch(err => {
        body.innerHTML = `<div class="alert alert-danger">Failed to load request: ${err.message}</div>`;
    });
}

// 2. APPROVE ACTION WITH OPTIONAL REMARKS
function promptApproveModal(reqId, isLate) {
    document.getElementById('approve_request_id').value = reqId;
    document.getElementById('approval_remarks_text').value = '';
    
    const title = document.getElementById('approveModalTitle');
    const lateNotice = document.getElementById('approveLateNotice');
    const btn = document.getElementById('confirmApproveBtn');

    if (isLate) {
        title.innerHTML = '<i class="bi bi-shield-exclamation me-2"></i>Approve Late Correction (Owner Override)';
        lateNotice.style.display = 'block';
        btn.className = 'btn btn-warning fw-bold';
        btn.innerHTML = '<i class="bi bi-shield-check me-1"></i> Confirm Owner Override Approval';
    } else {
        title.innerHTML = '<i class="bi bi-check-circle me-2"></i>Approve Correction Request';
        lateNotice.style.display = 'none';
        btn.className = 'btn btn-success fw-bold';
        btn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Confirm Approval';
    }

    approveModal.show();
}

document.getElementById('approveForm').addEventListener('submit', function(e) {
    e.preventDefault();
    let reqId = document.getElementById('approve_request_id').value;
    let remarks = document.getElementById('approval_remarks_text').value.trim();

    let btn = document.getElementById('confirmApproveBtn');
    btn.disabled = true;

    fetch('handle_correction_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=approve&request_id=${reqId}&approval_remarks=${encodeURIComponent(remarks)}`
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        if (data.status === 'success') {
            alert(data.message);
            approveModal.hide();
            detailsModal.hide();
            location.reload();
        } else {
            alert("Approval Failed: " + data.message);
        }
    })
    .catch(err => {
        btn.disabled = false;
        alert("Request error: " + err.message);
    });
});

// 3. REJECT ACTION
function promptRejectModal(reqId) {
    document.getElementById('reject_request_id').value = reqId;
    document.getElementById('rejection_reason_text').value = '';
    rejectModal.show();
}

document.getElementById('rejectForm').addEventListener('submit', function(e) {
    e.preventDefault();
    let reqId = document.getElementById('reject_request_id').value;
    let reason = document.getElementById('rejection_reason_text').value.trim();

    if (!reason) {
        alert("Please provide a mandatory reason for rejection.");
        return;
    }

    let btn = document.getElementById('confirmRejectBtn');
    btn.disabled = true;

    fetch('handle_correction_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=reject&request_id=${reqId}&rejection_reason=${encodeURIComponent(reason)}`
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        if (data.status === 'success') {
            alert(data.message);
            rejectModal.hide();
            detailsModal.hide();
            location.reload();
        } else {
            alert("Rejection Error: " + data.message);
        }
    })
    .catch(err => {
        btn.disabled = false;
        alert("Request error: " + err.message);
    });
});

// 4. OWNER SETTLE ACTION
function ownerSettle(reqId, status, amount) {
    let method = prompt("Enter payment method for settlement (Cash / GCash / Maya):", "Cash");
    if (!method) return;

    let ref = "";
    if (method.toLowerCase() !== "cash") {
        ref = prompt("Enter reference number for " + method + ":", "");
    }

    fetch('handle_correction_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=settle&request_id=${reqId}&payment_method=${encodeURIComponent(method)}&payment_reference=${encodeURIComponent(ref)}&amount=${amount}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            alert(data.message);
            location.reload();
        } else {
            alert("Settlement Error: " + data.message);
        }
    })
    .catch(err => alert("Error: " + err.message));
}

// 5. VIEW STANDARD POS RECEIPT
function viewTransactionReceipt(txId) {
    document.getElementById('receiptModalTitle').innerText = 'Official Receipt #' + txId;
    let container = document.getElementById('receiptContainer');
    container.innerHTML = '<div class="text-center py-4 text-muted">Loading receipt...</div>';
    receiptModal.show();

    fetch(`getPOSitems.php?id=${txId}`)
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

        let itemsRows = items.map(it => {
            let p = parseFloat(it.price || 0);
            let q = parseInt(it.qty || 1);
            let sub = parseFloat(it.subtotal || (p * q));
            return `
                <tr>
                    <td class="py-1"><b>${it.product_name}</b><br><small class="text-muted">${q} x ₱${p.toFixed(2)}</small></td>
                    <td class="text-end align-top py-1 fw-bold">₱${sub.toFixed(2)}</td>
                </tr>
            `;
        }).join('');

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
            <table width="100%" class="small mb-2"><tbody>${itemsRows}</tbody></table>
            <div class="receipt-line"></div>
            <div class="d-flex justify-content-between small fw-bold mb-1">
                <span>TOTAL AMOUNT:</span><span>₱${total.toFixed(2)}</span>
            </div>
            <div class="d-flex justify-content-between small text-muted mb-1">
                <span>Amount Paid:</span><span>₱${paid.toFixed(2)}</span>
            </div>
            <div class="d-flex justify-content-between small fw-bold text-success mb-1">
                <span>Change:</span><span>₱${change.toFixed(2)}</span>
            </div>
            <div class="receipt-line mt-2"></div>
            <div class="text-center mt-3"><small class="fw-bold d-block">Thank you for your purchase!</small></div>
        `;
        container.innerHTML = html;
    })
    .catch(err => {
        container.innerHTML = `<div class="text-center text-danger py-3">Error loading receipt: ${err.message}</div>`;
    });
}

// 6. PRINT OFFICIAL CORRECTED RECEIPT
function printCorrectedReceipt(reqId) {
    document.getElementById('receiptModalTitle').innerHTML = '<span class="text-danger fw-bold">*** CORRECTED RECEIPT ***</span>';
    let container = document.getElementById('receiptContainer');
    container.innerHTML = '<div class="text-center py-4 text-muted">Loading corrected receipt...</div>';
    receiptModal.show();

    fetch(`get_correction_details.php?id=${reqId}`)
    .then(res => res.json())
    .then(data => {
        if (data.status !== 'success') {
            container.innerHTML = `<div class="text-center text-danger py-3">${data.message}</div>`;
            return;
        }

        let req = data.request;
        let corrItems = data.corrected_items || [];
        let diff = parseFloat(req.payment_difference || 0);

        let itemsRows = corrItems.map(it => {
            let p = parseFloat(it.price || 0);
            let q = parseInt(it.qty || 1);
            let sub = parseFloat(it.subtotal || (p * q));
            return `
                <tr>
                    <td class="py-1"><b>${it.product_name}</b><br><small class="text-muted">${q} x ₱${p.toFixed(2)}</small></td>
                    <td class="text-end align-top py-1 fw-bold">₱${sub.toFixed(2)}</td>
                </tr>
            `;
        }).join('');

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
            <table width="100%" class="small mb-2"><tbody>${itemsRows}</tbody></table>

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
                <span>${req.payment_method || 'Cash'}</span>
            </div>
            ${req.payment_reference ? `
            <div class="d-flex justify-content-between small text-muted mb-1">
                <span>Ref Number:</span>
                <span>${req.payment_reference}</span>
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
        container.innerHTML = `<div class="text-center text-danger py-3">Error loading receipt: ${err.message}</div>`;
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
</script>
</body>
</html>
