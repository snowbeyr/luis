<?php
// owner/shared_inventory.php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/shared_inventory_functions.php';

// Auth check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'owner') {
    header("Location: ../login.php");
    exit();
}

$owner_name = $_SESSION['username'] ?? "Owner";
ensureSharedInventorySchema($pdo);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_source') {
        $source_name = trim($_POST['source_name'] ?? '');
        $base_unit = trim($_POST['base_unit'] ?? '');
        $physical_stock = floatval($_POST['physical_stock'] ?? 0);
        $low_stock_threshold = floatval($_POST['low_stock_threshold'] ?? 0);

        if ($source_name === '' || $base_unit === '') {
            $_SESSION['error_msg'] = "Source Name and Base Unit of Measurement are required.";
        } elseif ($physical_stock < 0) {
            $_SESSION['error_msg'] = "Initial physical stock cannot be negative.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO shared_inventory_sources (source_name, base_unit, physical_stock, low_stock_threshold, is_active, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW()) RETURNING id
                ");
                $stmt->execute([$source_name, $base_unit, $physical_stock, $low_stock_threshold, $owner_name]);
                $new_id = $stmt->fetchColumn();

                if ($physical_stock > 0 && $new_id) {
                    $stmt_mov = $pdo->prepare("
                        INSERT INTO shared_inventory_movements (
                            source_id, product_id, variant_id, movement_type,
                            qty_in_base_unit, selling_unit_qty, conversion_qty_used,
                            previous_physical_stock, new_physical_stock,
                            reference_id, user_responsible, remarks
                        ) VALUES (?, NULL, NULL, 'Initial Stock', ?, NULL, NULL, 0, ?, NULL, ?, 'Initial inventory source creation')
                    ");
                    $stmt_mov->execute([$new_id, $physical_stock, $physical_stock, $owner_name]);
                }

                $_SESSION['success_msg'] = "Shared inventory source '{$source_name}' created successfully!";
            } catch (Exception $e) {
                $_SESSION['error_msg'] = "Failed to create source: " . $e->getMessage();
            }
        }
        header("Location: shared_inventory.php");
        exit();
    }

    if ($action === 'edit_source') {
        $source_id = intval($_POST['source_id'] ?? 0);
        $source_name = trim($_POST['source_name'] ?? '');
        $base_unit = trim($_POST['base_unit'] ?? '');
        $low_stock_threshold = floatval($_POST['low_stock_threshold'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($source_id <= 0 || $source_name === '' || $base_unit === '') {
            $_SESSION['error_msg'] = "Invalid source parameters.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE shared_inventory_sources 
                    SET source_name = ?, base_unit = ?, low_stock_threshold = ?, is_active = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$source_name, $base_unit, $low_stock_threshold, $is_active, $source_id]);
                syncSharedInventoryVariants($pdo, $source_id);
                $_SESSION['success_msg'] = "Shared inventory source #{$source_id} updated successfully!";
            } catch (Exception $e) {
                $_SESSION['error_msg'] = "Update failed: " . $e->getMessage();
            }
        }
        header("Location: shared_inventory.php");
        exit();
    }

    if ($action === 'adjust_stock') {
        $source_id = intval($_POST['source_id'] ?? 0);
        $new_stock = floatval($_POST['new_physical_stock'] ?? 0);
        $reason = trim($_POST['adjustment_reason'] ?? '');

        if ($source_id <= 0 || $reason === '') {
            $_SESSION['error_msg'] = "Adjustment reason is mandatory.";
        } else {
            list($success, $msg, $stock) = adjustSharedStock($pdo, $source_id, $new_stock, $owner_name, $reason);
            if ($success) {
                $_SESSION['success_msg'] = $msg;
            } else {
                $_SESSION['error_msg'] = $msg;
            }
        }
        header("Location: shared_inventory.php");
        exit();
    }

    if ($action === 'link_variant') {
        $source_id = intval($_POST['source_id'] ?? 0);
        $target_item = trim($_POST['target_item'] ?? ''); // e.g. "p_12" or "v_34"
        $conversion_qty = floatval($_POST['conversion_qty'] ?? 0);
        $selling_unit_label = trim($_POST['selling_unit_label'] ?? '');

        if ($source_id <= 0 || empty($target_item) || $conversion_qty <= 0) {
            $_SESSION['error_msg'] = "Please choose a valid product/variant and enter a conversion requirement greater than 0.";
        } else {
            $is_var = str_starts_with($target_item, 'v_');
            $item_id = intval(substr($target_item, 2));

            $prod_id = null;
            $var_id = null;

            if ($is_var) {
                $var_id = $item_id;
                // find product_id
                $stmt_p = $pdo->prepare("SELECT product_id FROM product_variants WHERE id = ?");
                $stmt_p->execute([$var_id]);
                $prod_id = $stmt_p->fetchColumn();
            } else {
                $prod_id = $item_id;
                $var_id = null;
            }

            if (!$prod_id) {
                $_SESSION['error_msg'] = "Selected product could not be found.";
            } else {
                try {
                    // Check if already linked elsewhere
                    $stmt_chk = $pdo->prepare("
                        SELECT siv.id, sis.source_name 
                        FROM shared_inventory_variants siv
                        JOIN shared_inventory_sources sis ON siv.source_id = sis.id
                        WHERE siv.product_id = ? AND " . ($var_id ? "siv.variant_id = ?" : "(siv.variant_id IS NULL OR siv.variant_id = 0)") . "
                    ");
                    $stmt_chk->execute($var_id ? [$prod_id, $var_id] : [$prod_id]);
                    $existing = $stmt_chk->fetch(PDO::FETCH_ASSOC);

                    if ($existing) {
                        $_SESSION['error_msg'] = "This item is already linked to shared source '{$existing['source_name']}'. Please disconnect it first if you want to link it to a different source.";
                    } else {
                        $stmt_ins = $pdo->prepare("
                            INSERT INTO shared_inventory_variants (source_id, product_id, variant_id, conversion_qty, selling_unit_label, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                        ");
                        $stmt_ins->execute([$source_id, $prod_id, $var_id, $conversion_qty, $selling_unit_label]);
                        syncSharedInventoryVariants($pdo, $source_id);
                        $_SESSION['success_msg'] = "Item successfully linked to shared inventory source!";
                    }
                } catch (Exception $e) {
                    $_SESSION['error_msg'] = "Failed to link item: " . $e->getMessage();
                }
            }
        }
        header("Location: shared_inventory.php");
        exit();
    }

    if ($action === 'disconnect_variant') {
        $link_id = intval($_POST['link_id'] ?? 0);
        if ($link_id <= 0) {
            $_SESSION['error_msg'] = "Invalid link ID.";
        } else {
            try {
                $stmt_get = $pdo->prepare("SELECT source_id, product_id, variant_id FROM shared_inventory_variants WHERE id = ?");
                $stmt_get->execute([$link_id]);
                $link = $stmt_get->fetch(PDO::FETCH_ASSOC);

                if ($link) {
                    $source_id = $link['source_id'];
                    $stmt_del = $pdo->prepare("DELETE FROM shared_inventory_variants WHERE id = ?");
                    $stmt_del->execute([$link_id]);
                    syncSharedInventoryVariants($pdo, $source_id);
                    $_SESSION['success_msg'] = "Variant disconnected from shared source. Stock is now independent.";
                } else {
                    $_SESSION['error_msg'] = "Link record not found.";
                }
            } catch (Exception $e) {
                $_SESSION['error_msg'] = "Failed to disconnect: " . $e->getMessage();
            }
        }
        header("Location: shared_inventory.php");
        exit();
    }
}

// Fetch all sources with their connected variants
$sources_stmt = $pdo->query("
    SELECT sis.*,
           COUNT(siv.id) AS connected_variants_count
    FROM shared_inventory_sources sis
    LEFT JOIN shared_inventory_variants siv ON sis.id = siv.source_id
    GROUP BY sis.id
    ORDER BY sis.is_active DESC, sis.id ASC
");
$sources = $sources_stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$total_sources = count($sources);
$active_sources = 0;
$low_stock_sources = 0;
$total_connected_variants = 0;

foreach ($sources as $s) {
    if ($s['is_active'] == 1) $active_sources++;
    if ($s['physical_stock'] <= $s['low_stock_threshold']) $low_stock_sources++;
    $total_connected_variants += (int)$s['connected_variants_count'];
}

// Get all linked variants with details
$linked_variants_stmt = $pdo->query("
    SELECT siv.*, 
           sis.source_name, sis.base_unit, sis.physical_stock,
           p.product_name, p.price AS prod_price, p.stock AS prod_stock,
           pv.variant_name, pv.price AS var_price, pv.stock AS var_stock
    FROM shared_inventory_variants siv
    JOIN shared_inventory_sources sis ON siv.source_id = sis.id
    JOIN products p ON siv.product_id = p.id
    LEFT JOIN product_variants pv ON siv.variant_id = pv.id
    ORDER BY siv.source_id ASC, siv.id ASC
");
$all_links = $linked_variants_stmt->fetchAll(PDO::FETCH_ASSOC);

$links_by_source = [];
foreach ($all_links as $l) {
    $links_by_source[$l['source_id']][] = $l;
}

// Get available products & variants not yet linked to any source
$unlinked_items = [];
$stmt_unlinked_prod = $pdo->query("
    SELECT p.id, p.product_name, p.price, p.stock
    FROM products p
    WHERE p.id NOT IN (
        SELECT product_id FROM shared_inventory_variants WHERE variant_id IS NULL OR variant_id = 0
    ) AND p.is_archived = 0
    ORDER BY p.product_name ASC
");
$unlinked_prods = $stmt_unlinked_prod->fetchAll(PDO::FETCH_ASSOC);
foreach ($unlinked_prods as $p) {
    // Check if product has variants; if it has variants, we typically link the variants, but allow linking parent if desired
    $stmt_has_var = $pdo->prepare("SELECT COUNT(*) FROM product_variants WHERE product_id = ?");
    $stmt_has_var->execute([$p['id']]);
    $var_count = (int)$stmt_has_var->fetchColumn();

    $unlinked_items[] = [
        'val' => 'p_' . $p['id'],
        'label' => '[Product] ' . $p['product_name'] . ($var_count > 0 ? " (Base Product)" : " (Standalone)") . " - ₱" . number_format($p['price'], 2) . " (Stock: {$p['stock']})",
        'name' => $p['product_name']
    ];
}

$stmt_unlinked_var = $pdo->query("
    SELECT pv.id, pv.variant_name, pv.price, pv.stock, p.product_name
    FROM product_variants pv
    JOIN products p ON pv.product_id = p.id
    WHERE pv.id NOT IN (
        SELECT variant_id FROM shared_inventory_variants WHERE variant_id IS NOT NULL AND variant_id > 0
    ) AND p.is_archived = 0
    ORDER BY p.product_name ASC, pv.variant_name ASC
");
$unlinked_vars = $stmt_unlinked_var->fetchAll(PDO::FETCH_ASSOC);
foreach ($unlinked_vars as $v) {
    $unlinked_items[] = [
        'val' => 'v_' . $v['id'],
        'label' => '[Variant] ' . $v['product_name'] . ' (' . $v['variant_name'] . ') - ₱' . number_format($v['price'], 2) . " (Stock: {$v['stock']})",
        'name' => $v['product_name'] . ' - ' . $v['variant_name']
    ];
}

// Fetch recent movements for audit trail display
$movements_stmt = $pdo->query("
    SELECT m.*, sis.source_name, sis.base_unit, p.product_name, pv.variant_name
    FROM shared_inventory_movements m
    JOIN shared_inventory_sources sis ON m.source_id = sis.id
    LEFT JOIN products p ON m.product_id = p.id
    LEFT JOIN product_variants pv ON m.variant_id = pv.id
    ORDER BY m.id DESC
    LIMIT 100
");
$recent_movements = $movements_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shared Stock Management - Owner Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f4f6f9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .wrapper {
            display: flex;
            flex: 1;
            height: calc(100vh - 56px);
            overflow: hidden;
        }
        .sidebar {
            width: 250px;
            background-color: #0d2238;
            color: #fff;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        .sidebar-link {
            padding: 12px 20px;
            color: #b0c4de;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
            font-size: 0.95rem;
        }
        .sidebar-link:hover, .sidebar-link.active {
            color: #fff;
            background-color: #1a3a5c;
            border-left: 4px solid #00c0ef;
        }
        .logout-section {
            margin-top: auto;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        .main-content {
            flex-grow: 1;
            padding: 24px;
            overflow-y: auto;
            background-color: #f8fafc;
        }
        .stat-card {
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #fff;
            padding: 18px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            transition: transform 0.15s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .source-card {
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #fff;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            overflow: hidden;
        }
        .source-header {
            padding: 16px 20px;
            background-color: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        .badge-soft-success {
            background-color: #d1fae5;
            color: #065f46;
            font-weight: 600;
        }
        .badge-soft-warning {
            background-color: #fef3c7;
            color: #92400e;
            font-weight: 600;
        }
        .badge-soft-danger {
            background-color: #fee2e2;
            color: #991b1b;
            font-weight: 600;
        }
        .badge-soft-info {
            background-color: #e0f2fe;
            color: #075985;
            font-weight: 600;
        }
    </style>
</head>
<body>

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

<div class="wrapper">
    <!-- Sidebar -->
    <div class="sidebar" id="appSidebar">
        <a href="dashboard.php" class="sidebar-link"><i class="bi bi-graph-up-arrow"></i> Dashboard</a>
        <a href="sales.php" class="sidebar-link"><i class="bi bi-cash-stack"></i> Sales</a>
        <a href="inventory.php" class="sidebar-link"><i class="bi bi-box-seam"></i> Inventory Report</a>
        <a href="shared_inventory.php" class="sidebar-link active"><i class="bi bi-diagram-3"></i> Shared Stock</a>
        <a href="orders.php" class="sidebar-link"><i class="bi bi-clipboard-check"></i> Order Mgt. Monitoring</a>
        <a href="corrections.php" class="sidebar-link"><i class="bi bi-arrow-repeat"></i> Corrections</a>
        <a href="history.php" class="sidebar-link"><i class="bi bi-clock-history"></i> History</a>
        <a href="auditTrail.php" class="sidebar-link"><i class="bi bi-shield-lock"></i> Audit Trail</a>
        <a href="accountManagement.php" class="sidebar-link"><i class="bi bi-person-gear"></i> Account Management</a>
        <div class="logout-section">
            <a href="../logout.php" class="sidebar-link text-danger" onclick="return confirm('Are you sure you want to log out?')">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid p-0">

            <!-- Flash alerts -->
            <?php if (!empty($_SESSION['success_msg'])): ?>
                <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
                    <i class="bi bi-check-circle-fill fs-5"></i>
                    <div><?= htmlspecialchars($_SESSION['success_msg']); ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success_msg']); ?>
            <?php endif; ?>

            <?php if (!empty($_SESSION['error_msg'])): ?>
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                    <div><?= htmlspecialchars($_SESSION['error_msg']); ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error_msg']); ?>
            <?php endif; ?>

            <!-- Header -->
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
                <div>
                    <h2 class="fw-bold text-dark m-0"><i class="bi bi-diagram-3 text-primary"></i> Linked Inventory / Shared Stock</h2>
                    <small class="text-muted">Manage products & variants sharing a physical raw inventory source (e.g., Gravel, Sand, Steel).</small>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#movementsAuditModal">
                        <i class="bi bi-clock-history"></i> Global Movement Audit
                    </button>
                    <button class="btn btn-primary btn-sm fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#createSourceModal">
                        <i class="bi bi-plus-circle"></i> Create Shared Source
                    </button>
                </div>
            </div>

            <!-- Metric Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <small class="text-muted text-uppercase fw-semibold">Shared Sources</small>
                                <h3 class="fw-bold mb-0 text-dark"><?= $total_sources; ?></h3>
                                <small class="text-success"><i class="bi bi-check-circle"></i> <?= $active_sources; ?> Active</small>
                            </div>
                            <div class="p-3 bg-primary-subtle text-primary rounded-circle">
                                <i class="bi bi-diagram-3 fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <small class="text-muted text-uppercase fw-semibold">Linked Variants</small>
                                <h3 class="fw-bold mb-0 text-dark"><?= $total_connected_variants; ?></h3>
                                <small class="text-muted">Selling variations</small>
                            </div>
                            <div class="p-3 bg-info-subtle text-info rounded-circle">
                                <i class="bi bi-link-45deg fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <small class="text-muted text-uppercase fw-semibold">Low Stock Sources</small>
                                <h3 class="fw-bold mb-0 <?= $low_stock_sources > 0 ? 'text-danger' : 'text-dark'; ?>"><?= $low_stock_sources; ?></h3>
                                <small class="<?= $low_stock_sources > 0 ? 'text-danger' : 'text-muted'; ?>">Below threshold</small>
                            </div>
                            <div class="p-3 bg-warning-subtle text-warning rounded-circle">
                                <i class="bi bi-exclamation-triangle fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <small class="text-muted text-uppercase fw-semibold">Movement Trail</small>
                                <h3 class="fw-bold mb-0 text-dark"><?= count($recent_movements); ?></h3>
                                <small class="text-muted">Logged transactions</small>
                            </div>
                            <div class="p-3 bg-secondary-subtle text-secondary rounded-circle">
                                <i class="bi bi-journal-text fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sources List -->
            <h5 class="fw-bold text-dark mb-3"><i class="bi bi-boxes"></i> Physical Inventory Sources</h5>

            <?php if (empty($sources)): ?>
                <div class="card p-5 text-center text-muted border-0 shadow-sm rounded-4">
                    <i class="bi bi-inbox fs-1 mb-2 text-secondary"></i>
                    <h5 class="fw-bold">No Shared Inventory Sources Configured</h5>
                    <p class="mb-3">Create your first physical stock source (e.g., Gravel, White Sand, Bistay) to share inventory across different selling sizes.</p>
                    <div>
                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createSourceModal">
                            <i class="bi bi-plus-circle"></i> Create Shared Source
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($sources as $source): 
                    $s_id = $source['id'];
                    $cur_stock = floatval($source['physical_stock']);
                    $threshold = floatval($source['low_stock_threshold']);
                    $is_low = $cur_stock <= $threshold && $cur_stock > 0;
                    $is_out = $cur_stock <= 0;
                    $variants = $links_by_source[$s_id] ?? [];
                ?>
                <div class="source-card">
                    <div class="source-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                        <div class="d-flex align-items-center gap-3">
                            <div>
                                <h5 class="fw-bold text-dark mb-0">
                                    <i class="bi bi-box-fill text-primary"></i> <?= htmlspecialchars($source['source_name']); ?>
                                </h5>
                                <small class="text-muted">
                                    Source ID: <strong>#<?= $s_id; ?></strong> | Base Unit: <strong><?= htmlspecialchars($source['base_unit']); ?></strong> | Created: <?= date('M d, Y', strtotime($source['created_at'])); ?>
                                </small>
                            </div>
                            <div>
                                <?php if ($source['is_active'] == 1): ?>
                                    <span class="badge badge-soft-success"><i class="bi bi-check-circle"></i> Active</span>
                                <?php else: ?>
                                    <span class="badge badge-soft-danger"><i class="bi bi-x-circle"></i> Inactive</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-2">
                            <div class="text-end me-3">
                                <small class="text-muted d-block">Physical Stock Balance</small>
                                <span class="fs-5 fw-bold <?= $is_out ? 'text-danger' : ($is_low ? 'text-warning' : 'text-success'); ?>">
                                    <?= number_format($cur_stock, 2); ?> <?= htmlspecialchars($source['base_unit']); ?>
                                </span>
                                <?php if ($is_out): ?>
                                    <span class="badge badge-soft-danger ms-1">Out of Stock</span>
                                <?php elseif ($is_low): ?>
                                    <span class="badge badge-soft-warning ms-1">Low Stock (≤ <?= number_format($threshold, 2); ?>)</span>
                                <?php endif; ?>
                            </div>

                            <button class="btn btn-outline-primary btn-sm fw-semibold" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#linkModal<?= $s_id; ?>">
                                <i class="bi bi-link-45deg"></i> Link Variant
                            </button>
                            <button class="btn btn-outline-warning btn-sm fw-semibold" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#adjustModal<?= $s_id; ?>">
                                <i class="bi bi-sliders"></i> Adjust Stock
                            </button>
                            <button class="btn btn-outline-secondary btn-sm" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#editModal<?= $s_id; ?>">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                        </div>
                    </div>

                    <!-- Connected Variants Table -->
                    <div class="p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold text-secondary small text-uppercase">
                                <i class="bi bi-diagram-2"></i> Connected Selling Variants (<?= count($variants); ?>)
                            </span>
                            <small class="text-muted">
                                Formula: <code>Available Units = floor(Physical Stock / Conversion Factor)</code>
                            </small>
                        </div>

                        <?php if (empty($variants)): ?>
                            <div class="p-3 bg-light rounded text-center text-muted small">
                                No products or variants linked to this source yet. Click <strong>"Link Variant"</strong> above to connect selling variants.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0 small">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Selling Item / Variant</th>
                                            <th>Item Type</th>
                                            <th>Conversion Requirement</th>
                                            <th>Selling Price</th>
                                            <th>Available Sellable Units</th>
                                            <th>Availability Status</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($variants as $v): 
                                            $is_variant_row = !empty($v['variant_id']);
                                            $item_title = $is_variant_row 
                                                ? htmlspecialchars($v['product_name']) . " (" . htmlspecialchars($v['variant_name']) . ")" 
                                                : htmlspecialchars($v['product_name']);
                                            $item_price = $is_variant_row ? $v['var_price'] : $v['prod_price'];
                                            $current_sellable = calculateSellableUnits($cur_stock, $v['conversion_qty']);
                                            $conv_val = floatval($v['conversion_qty']);
                                        ?>
                                        <tr>
                                            <td class="fw-bold text-dark">
                                                <i class="bi bi-box-seam text-secondary me-1"></i>
                                                <?= $item_title; ?>
                                            </td>
                                            <td>
                                                <span class="badge <?= $is_variant_row ? 'bg-info-subtle text-info border border-info' : 'bg-primary-subtle text-primary border border-primary'; ?>">
                                                    <?= $is_variant_row ? 'Variant Item' : 'Main Product'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <strong><?= number_format($conv_val, 2); ?> <?= htmlspecialchars($source['base_unit']); ?></strong> 
                                                per <?= htmlspecialchars($v['selling_unit_label'] ?: 'unit'); ?>
                                            </td>
                                            <td class="fw-semibold text-dark">
                                                ₱<?= number_format(floatval($item_price), 2); ?>
                                            </td>
                                            <td>
                                                <span class="fs-6 fw-bold <?= $current_sellable == 0 ? 'text-danger' : 'text-primary'; ?>">
                                                    <?= number_format($current_sellable); ?> <?= htmlspecialchars($v['selling_unit_label'] ?: 'units'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($current_sellable == 0): ?>
                                                    <span class="badge badge-soft-danger"><i class="bi bi-x-circle"></i> Out of Stock</span>
                                                <?php elseif ($current_sellable <= 5): ?>
                                                    <span class="badge badge-soft-warning"><i class="bi bi-exclamation-circle"></i> Low Stock</span>
                                                <?php else: ?>
                                                    <span class="badge badge-soft-success"><i class="bi bi-check-circle"></i> Available</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <form method="POST" action="shared_inventory.php" onsubmit="return confirm('Are you sure you want to disconnect this item? It will revert to independent stock management.');" style="display:inline;">
                                                    <input type="hidden" name="action" value="disconnect_variant">
                                                    <input type="hidden" name="link_id" value="<?= $v['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" title="Disconnect from Shared Source">
                                                        <i class="bi bi-link-45deg-slash"></i> Disconnect
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Modal: Link Variant for this Source -->
                <div class="modal fade" id="linkModal<?= $s_id; ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="shared_inventory.php">
                                <input type="hidden" name="action" value="link_variant">
                                <input type="hidden" name="source_id" value="<?= $s_id; ?>">
                                <div class="modal-header bg-primary text-white">
                                    <h5 class="modal-title fw-bold"><i class="bi bi-link-45deg"></i> Link Variant to <?= htmlspecialchars($source['source_name']); ?></h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="alert alert-info py-2 small mb-3">
                                        <i class="bi bi-info-circle"></i> When linked, this product/variant will automatically derive its sellable quantity from <strong><?= htmlspecialchars($source['source_name']); ?></strong>'s physical stock.
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold small">Select Product / Variant</label>
                                        <select name="target_item" class="form-select form-select-sm" required>
                                            <option value="">-- Choose Item --</option>
                                            <?php foreach ($unlinked_items as $item): ?>
                                                <option value="<?= $item['val']; ?>"><?= htmlspecialchars($item['label']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold small">
                                            Conversion Requirement (in <?= htmlspecialchars($source['base_unit']); ?> per 1 selling unit)
                                        </label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" step="0.0001" min="0.0001" name="conversion_qty" class="form-control" placeholder="e.g. 50 (for 50 kg/sack)" required>
                                            <span class="input-group-text"><?= htmlspecialchars($source['base_unit']); ?></span>
                                        </div>
                                        <small class="text-muted">How many <?= htmlspecialchars($source['base_unit']); ?> are consumed when 1 selling unit of this variant is sold.</small>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold small">Selling Unit Label</label>
                                        <input type="text" name="selling_unit_label" class="form-control form-control-sm" placeholder="e.g. Sack, Half Elf, 1 Elf, Piece">
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary btn-sm fw-bold">Link Variant</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Modal: Adjust Physical Stock -->
                <div class="modal fade" id="adjustModal<?= $s_id; ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="shared_inventory.php">
                                <input type="hidden" name="action" value="adjust_stock">
                                <input type="hidden" name="source_id" value="<?= $s_id; ?>">
                                <div class="modal-header bg-warning text-dark">
                                    <h5 class="modal-title fw-bold"><i class="bi bi-sliders"></i> Adjust Physical Stock: <?= htmlspecialchars($source['source_name']); ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold small">Current Physical Stock</label>
                                        <div class="input-group input-group-sm">
                                            <input type="text" class="form-control bg-light" value="<?= number_format($cur_stock, 2); ?>" readonly>
                                            <span class="input-group-text"><?= htmlspecialchars($source['base_unit']); ?></span>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold small">New Physical Stock Count</label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" step="0.01" min="0" name="new_physical_stock" class="form-control" value="<?= $cur_stock; ?>" required>
                                            <span class="input-group-text"><?= htmlspecialchars($source['base_unit']); ?></span>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold small">Adjustment Reason / Remarks <span class="text-danger">*</span></label>
                                        <textarea name="adjustment_reason" class="form-control form-control-sm" rows="3" placeholder="Provide reason for physical adjustment (e.g. Warehouse restock delivery, physical inventory count variance, damaged stock)..." required></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-warning btn-sm fw-bold">Confirm Adjustment</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Modal: Edit Source -->
                <div class="modal fade" id="editModal<?= $s_id; ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="shared_inventory.php">
                                <input type="hidden" name="action" value="edit_source">
                                <input type="hidden" name="source_id" value="<?= $s_id; ?>">
                                <div class="modal-header bg-dark text-white">
                                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square"></i> Edit Source #<?= $s_id; ?></h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold small">Source Name</label>
                                        <input type="text" name="source_name" class="form-control form-control-sm" value="<?= htmlspecialchars($source['source_name']); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold small">Base Unit of Measurement</label>
                                        <input type="text" name="base_unit" class="form-control form-control-sm" value="<?= htmlspecialchars($source['base_unit']); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold small">Low Stock Alert Threshold</label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" step="0.01" min="0" name="low_stock_threshold" class="form-control" value="<?= $threshold; ?>" required>
                                            <span class="input-group-text"><?= htmlspecialchars($source['base_unit']); ?></span>
                                        </div>
                                    </div>
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="activeSwitch<?= $s_id; ?>" <?= $source['is_active'] == 1 ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold small" for="activeSwitch<?= $s_id; ?>">Active Status</label>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-dark btn-sm fw-bold">Save Changes</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <?php endforeach; ?>
            <?php endif; ?>

        </div>
    </div>
</div>

<!-- Modal: Create Source -->
<div class="modal fade" id="createSourceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="shared_inventory.php">
                <input type="hidden" name="action" value="create_source">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle"></i> Create Shared Inventory Source</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Source Name <span class="text-danger">*</span></label>
                        <input type="text" name="source_name" class="form-control form-control-sm" placeholder="e.g. Gravel, White Sand, Bistay, Steel Bar" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Base Unit of Measurement <span class="text-danger">*</span></label>
                        <input type="text" name="base_unit" class="form-control form-control-sm" placeholder="e.g. kg, meters, liters, pcs" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Initial Physical Stock Count</label>
                        <input type="number" step="0.01" min="0" name="physical_stock" class="form-control form-control-sm" value="0.00" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Low Stock Alert Threshold</label>
                        <input type="number" step="0.01" min="0" name="low_stock_threshold" class="form-control form-control-sm" value="100.00" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm fw-bold">Create Source</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Movements Audit Trail -->
<div class="modal fade" id="movementsAuditModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-clock-history"></i> Shared Stock Movement History (Audit Trail)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                    <table class="table table-hover table-striped align-middle mb-0 small">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Timestamp</th>
                                <th>Source</th>
                                <th>Movement Type</th>
                                <th>Affected Item</th>
                                <th>Qty Changed</th>
                                <th>Physical Balance</th>
                                <th>User</th>
                                <th>Reference</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_movements)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">No inventory movements recorded yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent_movements as $m): 
                                    $qty_chg = floatval($m['qty_in_base_unit']);
                                    $item_name = !empty($m['variant_name']) 
                                        ? htmlspecialchars($m['product_name']) . " (" . htmlspecialchars($m['variant_name']) . ")" 
                                        : (!empty($m['product_name']) ? htmlspecialchars($m['product_name']) : '-');
                                ?>
                                <tr>
                                    <td class="text-muted"><?= date('M d, Y h:i A', strtotime($m['created_at'])); ?></td>
                                    <td class="fw-bold"><?= htmlspecialchars($m['source_name']); ?></td>
                                    <td>
                                        <span class="badge bg-secondary"><?= htmlspecialchars($m['movement_type']); ?></span>
                                    </td>
                                    <td><?= $item_name; ?></td>
                                    <td class="fw-bold <?= $qty_chg < 0 ? 'text-danger' : 'text-success'; ?>">
                                        <?= $qty_chg > 0 ? '+' : ''; ?><?= number_format($qty_chg, 2); ?> <?= htmlspecialchars($m['base_unit']); ?>
                                        <?php if (!empty($m['selling_unit_qty'])): ?>
                                            <div class="text-muted fw-normal" style="font-size: 0.75rem;">
                                                (<?= floatval($m['selling_unit_qty']); ?> selling units)
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= number_format(floatval($m['previous_physical_stock']), 2); ?></small>
                                        &rarr; <strong><?= number_format(floatval($m['new_physical_stock']), 2); ?></strong>
                                    </td>
                                    <td><?= htmlspecialchars($m['user_responsible'] ?: 'System'); ?></td>
                                    <td><?= htmlspecialchars($m['reference_id'] ?: '-'); ?></td>
                                    <td class="text-muted"><?= htmlspecialchars($m['remarks'] ?: '-'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const sidebarToggle = document.getElementById('sidebarToggle');
    const appSidebar = document.getElementById('appSidebar');
    if (sidebarToggle && appSidebar) {
        sidebarToggle.addEventListener('click', () => {
            appSidebar.classList.toggle('d-none');
        });
    }
</script>
</body>
</html>
