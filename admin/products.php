<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/image.php';

if (file_exists(__DIR__ . '/../config/google_drive.php')) {
    require_once __DIR__ . '/../config/google_drive.php';
}

// Check admin access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$admin_user = $_SESSION['username'] ?? 'Admin';
$admin_role = $_SESSION['role'] ?? 'admin';
$admin_user_id = $_SESSION['user_id'] ?? null;
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '';

// Helper for audit logging
function logInventoryAction($pdo, $user_id, $username, $role, $action, $details, $ip = '')
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_log (user_id, username, role, action, details, ip_address, timestamp)
            VALUES (:user_id, :username, :role, :action, :details, :ip, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':user_id' => $user_id,
            ':username' => $username,
            ':role' => $role,
            ':action' => $action,
            ':details' => $details,
            ':ip' => $ip
        ]);
    } catch (Exception $e) {
        error_log("Inventory audit log error: " . $e->getMessage());
    }
}

// Helper for image upload
function handleInventoryImageUpload($fileArray, $prefix = 'item_')
{
    if (!isset($fileArray['tmp_name']) || $fileArray['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($fileArray['error'] === UPLOAD_ERR_INI_SIZE || $fileArray['error'] === UPLOAD_ERR_FORM_SIZE) {
        throw new Exception("File size exceeds the server limit (Max 20MB). Please choose a smaller image.");
    } elseif ($fileArray['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Image upload failed with error code: " . $fileArray['error']);
    }

    $tmp = $fileArray['tmp_name'];
    $original_name = $fileArray['name'];

    if (empty($tmp) || !file_exists($tmp)) {
        throw new Exception("Uploaded image temporary file is invalid.");
    }

    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    $is_valid_image = false;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmp);
        finfo_close($finfo);
        $is_valid_image = str_starts_with($mime, 'image/') && $mime !== 'image/gif';
    } else {
        $check_image = @getimagesize($tmp);
        $is_valid_image = ($check_image !== false && $check_image[2] !== IMAGETYPE_GIF);
    }

    if (!$is_valid_image || !in_array($ext, $allowed, true)) {
        throw new Exception("Invalid image format! Only JPG, JPEG, PNG, and WEBP photos are allowed (GIF is not allowed).");
    }

    $new_name = uniqid($prefix, true) . '.' . $ext;

    // Try Google Drive if available
    if (function_exists('uploadProductImageToDrive')) {
        try {
            $file_id = uploadProductImageToDrive($tmp, $new_name);
            if (!empty($file_id)) {
                return "https://drive.google.com/uc?export=view&id=" . $file_id;
            }
        } catch (Throwable $e) {
            error_log("Google Drive upload notice: " . $e->getMessage());
        }
    }

    // Fallback: save to local uploads/
    $upload_dir = __DIR__ . '/../uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $target_file = $upload_dir . $new_name;
    if (move_uploaded_file($tmp, $target_file)) {
        return 'uploads/' . $new_name;
    }

    return null;
}

// ==========================================
// HANDLE ACTIONS (POST & GET)
// ==========================================

// 1. ADD VARIANT (POST)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_variant') {
    $parent_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT) ?: 0;
    $v_name = trim($_POST['variant_name'] ?? '');
    $v_desc = trim($_POST['variant_description'] ?? '');
    $v_unit = trim($_POST['unit'] ?? 'pcs');
    $v_cost = (float) ($_POST['cost_price'] ?? 0);
    $v_price = (float) ($_POST['price'] ?? 0);
    $v_stock = (int) ($_POST['stock'] ?? 0);

    if ($v_unit === '') {
        $v_unit = 'pcs';
    }

    if ($parent_id <= 0 || $v_name === '') {
        $_SESSION['error_message'] = "Parent product and variant name are required.";
        header("Location: products.php");
        exit();
    }

    if ($v_cost < 0 || $v_price < 0 || $v_stock < 0) {
        $_SESSION['error_message'] = "Negative values are not allowed.";
        header("Location: products.php");
        exit();
    }

    if ($v_cost > $v_price) {
        $_SESSION['error_message'] = "Cost price cannot be higher than selling price.";
        header("Location: products.php");
        exit();
    }

    try {
        // Check parent product
        $p_check = $pdo->prepare("SELECT product_name FROM products WHERE id = :id");
        $p_check->execute([':id' => $parent_id]);
        $parent = $p_check->fetch(PDO::FETCH_ASSOC);
        if (!$parent) {
            $_SESSION['error_message'] = "Parent product not found.";
            header("Location: products.php");
            exit();
        }

        // Check duplicate variant name
        $dup_check = $pdo->prepare("SELECT id FROM product_variants WHERE product_id = :pid AND LOWER(variant_name) = LOWER(:name)");
        $dup_check->execute([':pid' => $parent_id, ':name' => $v_name]);
        if ($dup_check->fetch()) {
            $_SESSION['error_message'] = "A variant with the name '{$v_name}' already exists for this product.";
            header("Location: products.php");
            exit();
        }

        $image_path = null;
        if (isset($_FILES['variant_image']) && $_FILES['variant_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $image_path = handleInventoryImageUpload($_FILES['variant_image'], 'var_');
        }

        $ins = $pdo->prepare("
            INSERT INTO product_variants (product_id, variant_name, variant_description, unit, cost_price, price, stock, variant_image, is_archived)
            VALUES (:pid, :name, :desc, :unit, :cost, :price, :stock, :img, 0)
        ");
        $ins->execute([
            ':pid' => $parent_id,
            ':name' => $v_name,
            ':desc' => $v_desc,
            ':unit' => $v_unit,
            ':cost' => $v_cost,
            ':price' => $v_price,
            ':stock' => $v_stock,
            ':img' => $image_path
        ]);

        logInventoryAction(
            $pdo,
            $admin_user_id,
            $admin_user,
            $admin_role,
            'Add Variant',
            "Added variant '{$v_name}' for product '{$parent['product_name']}'",
            $ip_address
        );

        $_SESSION['message'] = "Variant '{$v_name}' added successfully!";
        header("Location: products.php");
        exit();

    } catch (Throwable $e) {
        $_SESSION['error_message'] = "Error adding variant: " . $e->getMessage();
        header("Location: products.php");
        exit();
    }
}

// 2. EDIT VARIANT (POST)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_variant') {
    $v_id = filter_input(INPUT_POST, 'variant_id', FILTER_VALIDATE_INT) ?: 0;
    $parent_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT) ?: 0;
    $v_name = trim($_POST['variant_name'] ?? '');
    $v_desc = trim($_POST['variant_description'] ?? '');
    $v_unit = trim($_POST['unit'] ?? 'pcs');
    $v_cost = (float) ($_POST['cost_price'] ?? 0);
    $v_price = (float) ($_POST['price'] ?? 0);
    $v_stock = (int) ($_POST['stock'] ?? 0);

    if ($v_unit === '') {
        $v_unit = 'pcs';
    }

    if ($v_id <= 0 || $v_name === '') {
        $_SESSION['error_message'] = "Valid variant ID and name are required.";
        header("Location: products.php");
        exit();
    }

    if ($v_cost < 0 || $v_price < 0 || $v_stock < 0) {
        $_SESSION['error_message'] = "Negative values are not allowed.";
        header("Location: products.php");
        exit();
    }

    if ($v_cost > $v_price) {
        $_SESSION['error_message'] = "Cost price cannot be higher than selling price.";
        header("Location: products.php");
        exit();
    }

    try {
        // Check existing variant
        $cur_stmt = $pdo->prepare("SELECT pv.*, p.product_name FROM product_variants pv JOIN products p ON p.id = pv.product_id WHERE pv.id = :id");
        $cur_stmt->execute([':id' => $v_id]);
        $current_var = $cur_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$current_var) {
            $_SESSION['error_message'] = "Variant not found.";
            header("Location: products.php");
            exit();
        }

        $effective_pid = $current_var['product_id'];

        // Duplicate check
        $dup_check = $pdo->prepare("SELECT id FROM product_variants WHERE product_id = :pid AND LOWER(variant_name) = LOWER(:name) AND id != :id");
        $dup_check->execute([':pid' => $effective_pid, ':name' => $v_name, ':id' => $v_id]);
        if ($dup_check->fetch()) {
            $_SESSION['error_message'] = "Another variant with the name '{$v_name}' already exists for this product.";
            header("Location: products.php");
            exit();
        }

        $img_sql = "";
        $params = [
            ':id' => $v_id,
            ':name' => $v_name,
            ':desc' => $v_desc,
            ':unit' => $v_unit,
            ':cost' => $v_cost,
            ':price' => $v_price,
            ':stock' => $v_stock
        ];

        if (isset($_FILES['variant_image']) && $_FILES['variant_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $new_img = handleInventoryImageUpload($_FILES['variant_image'], 'var_');
            if ($new_img !== null) {
                $img_sql = ", variant_image = :img";
                $params[':img'] = $new_img;
            }
        }

        $upd = $pdo->prepare("
            UPDATE product_variants
            SET variant_name = :name, variant_description = :desc, unit = :unit, cost_price = :cost, price = :price, stock = :stock $img_sql
            WHERE id = :id
        ");
        $upd->execute($params);

        logInventoryAction(
            $pdo,
            $admin_user_id,
            $admin_user,
            $admin_role,
            'Edit Variant',
            "Updated variant '{$v_name}' for product '{$current_var['product_name']}'",
            $ip_address
        );

        $_SESSION['message'] = "Variant '{$v_name}' updated successfully!";
        header("Location: products.php");
        exit();

    } catch (Throwable $e) {
        $_SESSION['error_message'] = "Error updating variant: " . $e->getMessage();
        header("Location: products.php");
        exit();
    }
}

// 3. EDIT PRODUCT (POST)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_product') {
    $p_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT) ?: 0;
    $p_name = trim($_POST['product_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $p_unit = trim($_POST['unit'] ?? 'pcs');
    $cost_price = (float) ($_POST['cost_price'] ?? 0);
    $price = (float) ($_POST['price'] ?? 0);
    $stock = (int) ($_POST['stock'] ?? 0);
    $desc = trim($_POST['product_description'] ?? '');

    if ($p_unit === '') {
        $p_unit = 'pcs';
    }

    if ($p_id <= 0 || $p_name === '') {
        $_SESSION['error_message'] = "Valid product ID and name are required.";
        header("Location: products.php");
        exit();
    }

    if ($cost_price < 0 || $price < 0 || $stock < 0) {
        $_SESSION['error_message'] = "Negative values are not allowed.";
        header("Location: products.php");
        exit();
    }

    if ($cost_price > $price) {
        $_SESSION['error_message'] = "Cost price cannot be higher than selling price.";
        header("Location: products.php");
        exit();
    }

    try {
        $dup_check = $pdo->prepare("SELECT id FROM products WHERE LOWER(product_name) = LOWER(:name) AND id != :id");
        $dup_check->execute([':name' => $p_name, ':id' => $p_id]);
        if ($dup_check->fetch()) {
            $_SESSION['error_message'] = "Another product with the name '{$p_name}' already exists.";
            header("Location: products.php");
            exit();
        }

        $img_sql = "";
        $params = [
            ':id' => $p_id,
            ':name' => $p_name,
            ':cat' => $category,
            ':unit' => $p_unit,
            ':cost' => $cost_price,
            ':price' => $price,
            ':stock' => $stock,
            ':desc' => $desc
        ];

        if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $new_img = handleInventoryImageUpload($_FILES['product_image'], 'prod_');
            if ($new_img !== null) {
                $img_sql = ", image = :img";
                $params[':img'] = $new_img;
            }
        }

        $upd = $pdo->prepare("
            UPDATE products
            SET product_name = :name, category = :cat, unit = :unit, cost_price = :cost, price = :price, stock = :stock, product_description = :desc $img_sql
            WHERE id = :id
        ");
        $upd->execute($params);

        logInventoryAction(
            $pdo,
            $admin_user_id,
            $admin_user,
            $admin_role,
            'Edit Product',
            "Updated product details: '{$p_name}'",
            $ip_address
        );

        $_SESSION['message'] = "Product '{$p_name}' updated successfully!";
        header("Location: products.php");
        exit();

    } catch (Throwable $e) {
        $_SESSION['error_message'] = "Error updating product: " . $e->getMessage();
        header("Location: products.php");
        exit();
    }
}

// 4. ARCHIVE / UNARCHIVE (PRODUCT & VARIANT)
$archive_type = $_GET['type'] ?? ($_POST['type'] ?? '');
$target_id = filter_var($_GET['id'] ?? ($_POST['id'] ?? null), FILTER_VALIDATE_INT);
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// Support legacy GET params: archive_id, unarchive_id
if (isset($_GET['archive_id'])) {
    $action = 'archive_product';
    $target_id = filter_var($_GET['archive_id'], FILTER_VALIDATE_INT);
} elseif (isset($_GET['unarchive_id'])) {
    $action = 'unarchive_product';
    $target_id = filter_var($_GET['unarchive_id'], FILTER_VALIDATE_INT);
}

if (in_array($action, ['archive_product', 'unarchive_product', 'archive_variant', 'unarchive_variant'], true) && $target_id > 0) {
    try {
        if ($action === 'archive_product' || $action === 'unarchive_product') {
            $is_archive = ($action === 'archive_product');
            $status_val = $is_archive ? 1 : 0;

            $stmt = $pdo->prepare("SELECT product_name FROM products WHERE id = :id");
            $stmt->execute([':id' => $target_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            $item_name = $item['product_name'] ?? 'Product #' . $target_id;

            $upd = $pdo->prepare("UPDATE products SET is_archived = :status WHERE id = :id");
            $upd->execute([':status' => $status_val, ':id' => $target_id]);

            logInventoryAction(
                $pdo,
                $admin_user_id,
                $admin_user,
                $admin_role,
                $is_archive ? 'Archive Product' : 'Unarchive Product',
                ($is_archive ? "Archived product: " : "Unarchived product: ") . $item_name,
                $ip_address
            );

            $_SESSION['message'] = $is_archive ? "Product '{$item_name}' archived successfully!" : "Product '{$item_name}' unarchived successfully!";

        } elseif ($action === 'archive_variant' || $action === 'unarchive_variant') {
            $is_archive = ($action === 'archive_variant');
            $status_val = $is_archive ? 1 : 0;

            $stmt = $pdo->prepare("SELECT variant_name FROM product_variants WHERE id = :id");
            $stmt->execute([':id' => $target_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            $item_name = $item['variant_name'] ?? 'Variant #' . $target_id;

            $upd = $pdo->prepare("UPDATE product_variants SET is_archived = :status WHERE id = :id");
            $upd->execute([':status' => $status_val, ':id' => $target_id]);

            logInventoryAction(
                $pdo,
                $admin_user_id,
                $admin_user,
                $admin_role,
                $is_archive ? 'Archive Variant' : 'Unarchive Variant',
                ($is_archive ? "Archived variant: " : "Unarchived variant: ") . $item_name,
                $ip_address
            );

            $_SESSION['message'] = $is_archive ? "Variant '{$item_name}' archived successfully!" : "Variant '{$item_name}' unarchived successfully!";
        }

        // Redirect back preserving current search/status
        $redirect_status = $_GET['status'] ?? '';
        header("Location: products.php" . ($redirect_status ? "?status=" . urlencode($redirect_status) : ""));
        exit();

    } catch (PDOException $e) {
        error_log("Archive/Unarchive error: " . $e->getMessage());
        $_SESSION['error_message'] = "Unable to update archive status.";
        header("Location: products.php");
        exit();
    }
}

// ==========================================
// SEARCH, FILTERS, SORTING & PAGINATION
// ==========================================
$search = trim($_GET['search'] ?? '');
$category_filter = trim($_GET['category'] ?? '');
$status_filter = trim($_GET['status'] ?? ($_GET['filter'] ?? '')); // support filter=low_stock / out_of_stock
$sort = trim($_GET['sort'] ?? 'newest');
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$per_page = 15;

// Fetch all distinct categories for dropdown
try {
    $cat_stmt = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND TRIM(category) != '' ORDER BY category ASC");
    $categories_list = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $categories_list = [];
}

// Build query conditions
$where_clauses = [];
$params = [];

// Status / Archive condition
if ($status_filter === 'archived') {
    $where_clauses[] = "p.is_archived = 1";
} else {
    $where_clauses[] = "p.is_archived = 0";

    if ($status_filter === 'in_stock') {
        $where_clauses[] = "(p.stock > 10 OR EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.is_archived = 0 AND v.stock > 10))";
    } elseif ($status_filter === 'low_stock') {
        $where_clauses[] = "((p.stock > 0 AND p.stock <= 10) OR EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.is_archived = 0 AND v.stock > 0 AND v.stock <= 10))";
    } elseif ($status_filter === 'out_of_stock') {
        $where_clauses[] = "(p.stock <= 0 OR EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.is_archived = 0 AND v.stock <= 0))";
    }
}

// Category filter
if ($category_filter !== '') {
    $where_clauses[] = "p.category = :category";
    $params[':category'] = $category_filter;
}

// Search filter (Product name, category, or any variant name)
if ($search !== '') {
    $where_clauses[] = "(
        p.product_name ILIKE :search_pname
        OR p.category ILIKE :search_cat
        OR EXISTS (
            SELECT 1 FROM product_variants v 
            WHERE v.product_id = p.id 
              AND v.is_archived = 0 
              AND v.variant_name ILIKE :search_vname
        )
    )";
    $search_param = '%' . $search . '%';
    $params[':search_pname'] = $search_param;
    $params[':search_cat'] = $search_param;
    $params[':search_vname'] = $search_param;
}

$where_sql = implode(' AND ', $where_clauses);

// Sorting
$order_sql = "ORDER BY p.id DESC"; // default newest
if ($sort === 'oldest') {
    $order_sql = "ORDER BY p.id ASC";
} elseif ($sort === 'az') {
    $order_sql = "ORDER BY p.product_name ASC";
} elseif ($sort === 'za') {
    $order_sql = "ORDER BY p.product_name DESC";
}

try {
    // 1. Count total matching parent products
    $count_sql = "SELECT COUNT(DISTINCT p.id) FROM products p WHERE $where_sql";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_products = (int) $count_stmt->fetchColumn();

    // 2. Pagination calculation
    $total_pages = max(1, (int) ceil($total_products / $per_page));
    $page = max(1, min($page, $total_pages));
    $offset = ($page - 1) * $per_page;

    // 3. Fetch parent products with variant aggregation
    $products_sql = "
        SELECT 
            p.*,
            COUNT(v.id) FILTER (WHERE v.is_archived = 0) AS active_variants_count,
            MIN(v.price) FILTER (WHERE v.is_archived = 0) AS min_var_price,
            MAX(v.price) FILTER (WHERE v.is_archived = 0) AS max_var_price
        FROM products p
        LEFT JOIN product_variants v ON p.id = v.product_id
        WHERE $where_sql
        GROUP BY p.id
        $order_sql
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($products_sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Fetch variants for all products on this page
    $variants_by_product = [];
    if (!empty($products)) {
        $p_ids = array_column($products, 'id');
        $in_clause = implode(',', array_map('intval', $p_ids));
        $var_archive_filter = ($status_filter === 'archived') ? "" : "AND v.is_archived = 0";

        $variants_sql = "
            SELECT v.* 
            FROM product_variants v
            WHERE v.product_id IN ($in_clause)
            $var_archive_filter
            ORDER BY v.id ASC
        ";
        $v_stmt = $pdo->query($variants_sql);
        while ($v_row = $v_stmt->fetch(PDO::FETCH_ASSOC)) {
            $variants_by_product[$v_row['product_id']][] = $v_row;
        }
    }

} catch (PDOException $e) {
    error_log("Products query error: " . $e->getMessage());
    $products = [];
    $total_products = 0;
    $total_pages = 1;
}

// Function to build query string for pagination / filter preserving
function getQueryUrl($params_to_update = [])
{
    $current = $_GET;
    foreach ($params_to_update as $k => $v) {
        if ($v === null || $v === '') {
            unset($current[$k]);
        } else {
            $current[$k] = $v;
        }
    }
    return 'products.php?' . http_build_query($current);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management | Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

    <style>
        :root {
            --navy-header: #051c3c;
            --navy-header-hover: #0a2d5e;
            --sidebar-bg: #001f4d;
            --accent-blue: #0d6efd;
            --light-row-bg: #f8fafc;
            --border-color: #e2e8f0;
        }

        body {
            background: #f4f6f9;
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            overflow-x: hidden;
            color: #212529;
        }

        /* Sidebar Styling */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: var(--sidebar-bg);
            padding: 20px;
            overflow-y: auto;
            z-index: 1040;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease;
        }

        .sidebar h2 {
            color: #ffffff;
            text-align: center;
            font-weight: 700;
            margin-bottom: 35px;
            line-height: 1.25;
            font-size: 1.45rem;
            letter-spacing: -0.5px;
        }

        .sidebar a {
            color: #ffffff;
            text-decoration: none;
            padding: 11px 14px;
            border-radius: 8px;
            margin-bottom: 6px;
            transition: all 0.2s ease;
            font-size: 14.5px;
            display: flex;
            align-items: center;
            font-weight: 500;
        }

        .sidebar a i {
            margin-right: 10px;
            font-size: 1.1rem;
        }

        .sidebar a:hover {
            background: var(--accent-blue);
            padding-left: 18px;
        }

        .sidebar .active {
            background: var(--accent-blue);
            font-weight: 600;
        }

        .logout-section {
            margin-top: auto;
            padding-top: 15px;
            border-top: 1px solid rgba(255, 255, 255, 0.15);
        }

        /* Main Content */
        .main-content {
            margin-left: 260px;
            padding: 28px;
            min-height: 100vh;
            transition: margin 0.3s ease;
        }

        .main-container {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
            padding: 26px;
            border: 1px solid var(--border-color);
        }

        /* Page Header */
        .page-title {
            font-size: 1.6rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 2px;
            letter-spacing: -0.4px;
        }

        .page-subtitle {
            font-size: 0.92rem;
            color: #64748b;
            margin-bottom: 0;
        }

        /* Filter Controls */
        .filter-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 22px;
        }

        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            font-size: 0.92rem;
            padding: 7px 12px;
        }

        .form-control:focus, .form-select:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        /* Table Styling */
        .inventory-table {
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            overflow: hidden;
        }

        .inventory-table thead th {
            background-color: var(--navy-header) !important;
            color: #ffffff !important;
            font-weight: 600;
            font-size: 0.88rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 13px 14px;
            vertical-align: middle;
            border: none;
            white-space: nowrap;
        }

        .inventory-table tbody tr.main-row {
            background-color: #ffffff;
            transition: background-color 0.15s ease;
        }

        .inventory-table tbody tr.main-row:nth-child(even of .main-row) {
            background-color: #fbfcfd;
        }

        .inventory-table tbody tr.main-row:hover {
            background-color: #f1f5f9;
        }

        .inventory-table tbody tr.variant-row {
            background-color: #f8fafc;
            border-left: 3px solid #0d6efd;
            transition: background-color 0.15s ease;
        }

        .inventory-table tbody tr.variant-row:hover {
            background-color: #edf2f7;
        }

        .inventory-table td {
            padding: 11px 14px;
            vertical-align: middle;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.92rem;
        }

        /* Product Image Thumbnail */
        .prod-thumb {
            width: 44px;
            height: 44px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            background: #fff;
            cursor: zoom-in;
            transition: transform 0.2s ease;
        }

        .prod-thumb:hover {
            transform: scale(1.08);
        }

        /* Accordion Toggle Chevron */
        .accordion-chevron-btn {
            background: transparent;
            border: none;
            padding: 2px 5px;
            color: #0d6efd;
            font-size: 1.1rem;
            cursor: pointer;
            border-radius: 4px;
            transition: all 0.2s ease;
            line-height: 1;
        }

        .accordion-chevron-btn:hover {
            background: rgba(13, 110, 253, 0.1);
        }

        /* Badges */
        .badge-status {
            font-weight: 600;
            font-size: 0.8rem;
            padding: 5px 10px;
            border-radius: 50rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }

        .badge-in-stock {
            background-color: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }

        .badge-low-stock {
            background-color: #fef9c3;
            color: #854d0e;
            border: 1px solid #fef08a;
        }

        .badge-out-of-stock {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        /* Action Buttons */
        .btn-action-add {
            background-color: #0d6efd;
            border-color: #0d6efd;
            color: #ffffff;
            font-weight: 500;
            font-size: 0.82rem;
            padding: 5px 12px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s ease;
        }

        .btn-action-add:hover {
            background-color: #0b5ed7;
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(13, 110, 253, 0.3);
        }

        .btn-icon-edit {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #3b82f6;
            background-color: #eff6ff;
            color: #1d4ed8;
            font-size: 0.85rem;
            transition: all 0.2s ease;
        }

        .btn-icon-edit:hover {
            background-color: #3b82f6;
            color: #ffffff;
        }

        .btn-icon-archive {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #ef4444;
            background-color: #fef2f2;
            color: #b91c1c;
            font-size: 0.85rem;
            transition: all 0.2s ease;
        }

        .btn-icon-archive:hover {
            background-color: #ef4444;
            color: #ffffff;
        }

        .btn-icon-restore {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #10b981;
            background-color: #ecfdf5;
            color: #047857;
            font-size: 0.85rem;
            transition: all 0.2s ease;
        }

        .btn-icon-restore:hover {
            background-color: #10b981;
            color: #ffffff;
        }

        /* Mobile Responsive */
        @media (max-width: 991.98px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.show {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
                padding: 16px;
            }
        }
    </style>
</head>

<body>

    <!-- Mobile Navbar -->
    <nav class="navbar navbar-dark bg-dark d-lg-none px-3">
        <span class="navbar-brand fw-bold fs-6"><i class="bi bi-box-seam me-2"></i> Inventory</span>
        <button class="btn btn-outline-light btn-sm" type="button" id="sidebarToggle">
            <i class="bi bi-list fs-4"></i>
        </button>
    </nav>

    <!-- Sidebar -->
    <div class="sidebar" id="appSidebar">
        <h2>Admin<br>Dashboard</h2>
        <a href="dashboard.php"><i class="bi bi-grid"></i> Dashboard</a>
        <a href="products.php" class="active"><i class="bi bi-box-seam"></i> Inventory</a>
        <a href="shared_inventory.php"><i class="bi bi-diagram-3"></i> Shared Stock</a>
        <a href="orders.php"><i class="bi bi-receipt"></i> Orders</a>
        <a href="ItemsRequest.php"><i class="bi bi-arrow-return-left"></i> Customer Service request</a>
        <a href="damageItems.php"><i class="bi bi-exclamation-triangle"></i> Damaged Items</a>
        <a href="profile.php"><i class="bi bi-person-badge"></i> My Profile</a>
        <div class="logout-section">
            <a href="../logout.php" class="text-danger" onclick="return confirm('Are you sure you want to logout?')">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="main-content">
        <div class="main-container">

            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                <div>
                    <h1 class="page-title">Inventory Management</h1>
                    <p class="page-subtitle">Manage your products and variants</p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="shared_inventory.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold btn-sm d-inline-flex align-items-center gap-2">
                        <i class="bi bi-diagram-3"></i> Shared Stock
                    </a>
                    <a href="addProduct.php" class="btn btn-primary rounded-pill px-3 py-2 fw-semibold btn-sm d-inline-flex align-items-center gap-2 shadow-sm">
                        <i class="bi bi-plus-lg"></i> Add Product
                    </a>
                </div>
            </div>

            <!-- Flash Alerts -->
            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?= htmlspecialchars($_SESSION['message']); unset($_SESSION['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                    <i class="bi bi-exclamation-octagon-fill me-2"></i>
                    <?= htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Search and Filters Form -->
            <div class="filter-card">
                <form method="GET" action="products.php" id="filterForm" class="row g-2 align-items-center">
                    <!-- Search Input -->
                    <div class="col-12 col-md-4">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" name="search" class="form-control border-start-0" 
                                placeholder="Search by product or variant name..." 
                                value="<?= htmlspecialchars($search); ?>">
                        </div>
                    </div>

                    <!-- Category Filter -->
                    <div class="col-6 col-md-3">
                        <select name="category" class="form-select" onchange="this.form.submit()">
                            <option value="">All Categories</option>
                            <?php foreach ($categories_list as $cat): ?>
                                <option value="<?= htmlspecialchars($cat); ?>" <?= ($category_filter === $cat) ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Status Filter -->
                    <div class="col-6 col-md-2">
                        <select name="status" class="form-select" onchange="this.form.submit()">
                            <option value="" <?= ($status_filter === '') ? 'selected' : ''; ?>>All Status</option>
                            <option value="in_stock" <?= ($status_filter === 'in_stock') ? 'selected' : ''; ?>>In Stock</option>
                            <option value="low_stock" <?= ($status_filter === 'low_stock') ? 'selected' : ''; ?>>Low Stock</option>
                            <option value="out_of_stock" <?= ($status_filter === 'out_of_stock') ? 'selected' : ''; ?>>Out of Stock</option>
                            <option value="archived" <?= ($status_filter === 'archived') ? 'selected' : ''; ?>>Archived</option>
                        </select>
                    </div>

                    <!-- Sort Filter -->
                    <div class="col-6 col-md-2">
                        <select name="sort" class="form-select" onchange="this.form.submit()">
                            <option value="newest" <?= ($sort === 'newest') ? 'selected' : ''; ?>>Newest to Oldest</option>
                            <option value="oldest" <?= ($sort === 'oldest') ? 'selected' : ''; ?>>Oldest to Newest</option>
                            <option value="az" <?= ($sort === 'az') ? 'selected' : ''; ?>>A-Z</option>
                            <option value="za" <?= ($sort === 'za') ? 'selected' : ''; ?>>Z-A</option>
                        </select>
                    </div>

                    <!-- Filter Actions -->
                    <div class="col-6 col-md-1 d-flex gap-1">
                        <button type="submit" class="btn btn-primary w-100 btn-sm py-2" title="Apply Filter">
                            <i class="bi bi-funnel-fill"></i>
                        </button>
                        <?php if ($search !== '' || $category_filter !== '' || $status_filter !== '' || $sort !== 'newest'): ?>
                            <a href="products.php" class="btn btn-outline-secondary btn-sm py-2" title="Reset Filters">
                                <i class="bi bi-x-lg"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Inventory Table -->
            <div class="table-responsive">
                <table class="inventory-table align-middle">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 70px;">#</th>
                            <th style="min-width: 250px;">Product / Variant</th>
                            <th style="width: 140px;">Category</th>
                            <th class="text-center" style="width: 90px;">Unit</th>
                            <th class="text-end" style="width: 150px;">Price</th>
                            <th class="text-center" style="width: 100px;">Stock</th>
                            <th class="text-center" style="width: 130px;">Status</th>
                            <th class="text-center" style="width: 170px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="inventoryTableBody">
                        <?php if (!empty($products)): ?>
                            <?php foreach ($products as $idx => $p): 
                                $p_id = (int) $p['id'];
                                $display_num = $offset + $idx + 1;
                                $p_variants = $variants_by_product[$p_id] ?? [];
                                $has_variants = !empty($p_variants);
                                $is_archived = (int) ($p['is_archived'] ?? 0);

                                // Main Product Price Display
                                if ($has_variants) {
                                    $min_p = (float) ($p['min_var_price'] ?? $p['price']);
                                    $max_p = (float) ($p['max_var_price'] ?? $p['price']);
                                    if ($min_p != $max_p) {
                                        $price_text = "₱" . number_format($min_p, 2) . " - ₱" . number_format($max_p, 2);
                                    } else {
                                        $price_text = "₱" . number_format($min_p, 2);
                                    }
                                } else {
                                    $price_text = "₱" . number_format((float) $p['price'], 2);
                                }

                                // Main Product Stock Status
                                $p_stock = (int) $p['stock'];
                                if ($p_stock <= 0) {
                                    $status_badge = '<span class="badge-status badge-out-of-stock"><i class="bi bi-x-circle-fill"></i> Out of Stock</span>';
                                } elseif ($p_stock <= 10) {
                                    $status_badge = '<span class="badge-status badge-low-stock"><i class="bi bi-exclamation-triangle-fill"></i> Low Stock</span>';
                                } else {
                                    $status_badge = '<span class="badge-status badge-in-stock"><i class="bi bi-check-circle-fill"></i> In Stock</span>';
                                }

                                // Check if this parent should be auto-expanded because of search
                                $auto_expand = false;
                                if ($search !== '') {
                                    foreach ($p_variants as $v_check) {
                                        if (stripos($v_check['variant_name'], $search) !== false) {
                                            $auto_expand = true;
                                            break;
                                        }
                                    }
                                }

                                $img_src = getProductImageSrc($p['image']);
                            ?>
                                <!-- ================= MAIN PRODUCT ROW ================= -->
                                <tr class="main-row" id="product-row-<?= $p_id; ?>">
                                    <!-- 1. # Column with Accordion Chevron -->
                                    <td class="text-center">
                                        <div class="d-flex align-items-center justify-content-center gap-1">
                                            <span class="fw-bold text-dark"><?= $display_num; ?></span>
                                            <?php if ($has_variants): ?>
                                                <button type="button" class="accordion-chevron-btn" 
                                                    onclick="toggleVariants(<?= $p_id; ?>)" 
                                                    title="Toggle variants"
                                                    aria-label="Toggle variants for <?= htmlspecialchars($p['product_name']); ?>">
                                                    <i id="chevron-<?= $p_id; ?>" class="bi <?= $auto_expand ? 'bi-chevron-up' : 'bi-chevron-down'; ?> fw-bold"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- 2. Product / Variant -->
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <img src="<?= htmlspecialchars($img_src, ENT_QUOTES, 'UTF-8'); ?>" 
                                                 alt="<?= htmlspecialchars($p['product_name']); ?>" 
                                                 class="prod-thumb" 
                                                 onclick="openProductImageZoom(this.src)" 
                                                 title="Click to view large image"
                                                 onerror="this.onerror=null; this.src='../assets/images/no-image.png';">
                                            <div>
                                                <span class="fw-bold text-dark d-block"><?= htmlspecialchars($p['product_name']); ?></span>
                                                <?php if ($has_variants): ?>
                                                    <span class="badge bg-secondary-subtle text-secondary border px-2 py-0" style="font-size: 0.75rem;">
                                                        <?= count($p_variants); ?> variants
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- 3. Category -->
                                    <td>
                                        <span class="badge bg-light text-dark border px-2 py-1">
                                            <?= htmlspecialchars($p['category'] ?: 'Uncategorized'); ?>
                                        </span>
                                    </td>

                                    <!-- 4. Unit -->
                                    <td class="text-center fw-medium text-secondary">
                                        <?= htmlspecialchars($p['unit'] ?: 'pcs'); ?>
                                    </td>

                                    <!-- 5. Price -->
                                    <td class="text-end fw-bold text-success">
                                        <?= $price_text; ?>
                                    </td>

                                    <!-- 6. Stock -->
                                    <td class="text-center fw-bold text-dark">
                                        <?= $p_stock; ?>
                                    </td>

                                    <!-- 7. Status -->
                                    <td class="text-center">
                                        <?= $status_badge; ?>
                                    </td>

                                    <!-- 8. Actions (ONLY: Add Variant, Edit, Archive) -->
                                    <td class="text-center">
                                        <div class="d-flex align-items-center justify-content-center gap-1">
                                            <?php if ($is_archived === 0): ?>
                                                <!-- Action A: Add Variant -->
                                                <button type="button" class="btn-action-add" 
                                                    onclick="openAddVariantModal(<?= htmlspecialchars(json_encode([
                                                        'id' => $p['id'],
                                                        'name' => $p['product_name'],
                                                        'unit' => $p['unit'] ?: 'pcs'
                                                    ])); ?>)" 
                                                    title="Add Variant for this product">
                                                    <i class="bi bi-plus-lg"></i> Add Variant
                                                </button>

                                                <!-- Action B: Edit Product -->
                                                <button type="button" class="btn-icon-edit" 
                                                    onclick="openEditProductModal(<?= htmlspecialchars(json_encode([
                                                        'id' => $p['id'],
                                                        'name' => $p['product_name'],
                                                        'category' => $p['category'],
                                                        'unit' => $p['unit'] ?: 'pcs',
                                                        'cost_price' => (float)$p['cost_price'],
                                                        'price' => (float)$p['price'],
                                                        'stock' => (int)$p['stock'],
                                                        'description' => $p['product_description'] ?? '',
                                                        'image_url' => $img_src
                                                    ])); ?>)" 
                                                    title="Edit Product">
                                                    <i class="bi bi-pencil-fill"></i>
                                                </button>

                                                <!-- Action C: Archive Product -->
                                                <button type="button" class="btn-icon-archive" 
                                                    onclick="openArchiveModal('product', <?= $p['id']; ?>, '<?= addslashes(htmlspecialchars($p['product_name'])); ?>')" 
                                                    title="Archive Product">
                                                    <i class="bi bi-archive-fill"></i>
                                                </button>
                                            <?php else: ?>
                                                <!-- Unarchive Action for Archived Product -->
                                                <button type="button" class="btn-icon-restore" 
                                                    onclick="openUnarchiveModal('product', <?= $p['id']; ?>, '<?= addslashes(htmlspecialchars($p['product_name'])); ?>')" 
                                                    title="Restore Product">
                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>

                                <!-- ================= VARIANT ROWS (CHILDREN) ================= -->
                                <?php if ($has_variants): ?>
                                    <?php foreach ($p_variants as $v_idx => $v): 
                                        $v_id = (int) $v['id'];
                                        $sub_num = $display_num . '.' . ($v_idx + 1);
                                        $v_stock = (int) $v['stock'];
                                        $v_is_archived = (int) ($v['is_archived'] ?? 0);

                                        if ($v_stock <= 0) {
                                            $v_status = '<span class="badge-status badge-out-of-stock"><i class="bi bi-x-circle-fill"></i> Out of Stock</span>';
                                        } elseif ($v_stock <= 10) {
                                            $v_status = '<span class="badge-status badge-low-stock"><i class="bi bi-exclamation-triangle-fill"></i> Low Stock</span>';
                                        } else {
                                            $v_status = '<span class="badge-status badge-in-stock"><i class="bi bi-check-circle-fill"></i> In Stock</span>';
                                        }

                                        $v_img = !empty($v['variant_image']) ? getProductImageSrc($v['variant_image']) : '';
                                    ?>
                                        <tr class="variant-row variant-row-<?= $p_id; ?> <?= $auto_expand ? '' : 'd-none'; ?>" id="variant-row-<?= $v_id; ?>">
                                            <!-- Hierarchical Number (e.g. 1.1) -->
                                            <td class="text-center">
                                                <span class="text-muted fw-semibold" style="font-size: 0.85rem;"><?= $sub_num; ?></span>
                                            </td>

                                            <!-- Variant Name & Connector -->
                                            <td>
                                                <div class="d-flex align-items-center ps-3">
                                                    <i class="bi bi-arrow-return-right text-primary me-2"></i>
                                                    <?php if ($v_img): ?>
                                                        <img src="<?= htmlspecialchars($v_img, ENT_QUOTES, 'UTF-8'); ?>" 
                                                             alt="<?= htmlspecialchars($v['variant_name']); ?>" 
                                                             class="prod-thumb me-2" 
                                                             style="width: 32px; height: 32px;"
                                                             onclick="openProductImageZoom(this.src)"
                                                             onerror="this.onerror=null; this.src='../assets/images/no-image.png';">
                                                    <?php endif; ?>
                                                    <div>
                                                        <span class="fw-semibold text-dark"><?= htmlspecialchars($v['variant_name']); ?></span>
                                                        <span class="badge bg-info-subtle text-info border border-info-subtle ms-1" style="font-size: 0.72rem;">Variant</span>
                                                    </div>
                                                </div>
                                            </td>

                                            <!-- Category -->
                                            <td>
                                                <span class="text-muted small"><?= htmlspecialchars($p['category'] ?: 'Uncategorized'); ?></span>
                                            </td>

                                            <!-- Unit -->
                                            <td class="text-center text-muted small">
                                                <?= htmlspecialchars($v['unit'] ?: ($p['unit'] ?: 'pcs')); ?>
                                            </td>

                                            <!-- Price -->
                                            <td class="text-end fw-semibold text-success">
                                                ₱<?= number_format((float) $v['price'], 2); ?>
                                            </td>

                                            <!-- Stock -->
                                            <td class="text-center fw-semibold text-dark">
                                                <?= $v_stock; ?>
                                            </td>

                                            <!-- Status -->
                                            <td class="text-center">
                                                <?= $v_status; ?>
                                            </td>

                                            <!-- Variant Actions (ONLY: Edit, Archive) -->
                                            <td class="text-center">
                                                <div class="d-flex align-items-center justify-content-center gap-1">
                                                    <?php if ($v_is_archived === 0): ?>
                                                        <!-- Action A: Edit Variant -->
                                                        <button type="button" class="btn-icon-edit" 
                                                            onclick="openEditVariantModal(<?= htmlspecialchars(json_encode([
                                                                'id' => $v['id'],
                                                                'product_id' => $p['id'],
                                                                'parent_name' => $p['product_name'],
                                                                'name' => $v['variant_name'],
                                                                'unit' => $v['unit'] ?: ($p['unit'] ?: 'pcs'),
                                                                'cost_price' => (float)$v['cost_price'],
                                                                'price' => (float)$v['price'],
                                                                'stock' => (int)$v['stock'],
                                                                'description' => $v['variant_description'] ?? '',
                                                                'image_url' => $v_img
                                                            ])); ?>)" 
                                                            title="Edit Variant">
                                                            <i class="bi bi-pencil-fill"></i>
                                                        </button>

                                                        <!-- Action B: Archive Variant -->
                                                        <button type="button" class="btn-icon-archive" 
                                                            onclick="openArchiveModal('variant', <?= $v['id']; ?>, '<?= addslashes(htmlspecialchars($v['variant_name'])); ?>')" 
                                                            title="Archive Variant">
                                                            <i class="bi bi-archive-fill"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <!-- Restore Variant -->
                                                        <button type="button" class="btn-icon-restore" 
                                                            onclick="openUnarchiveModal('variant', <?= $v['id']; ?>, '<?= addslashes(htmlspecialchars($v['variant_name'])); ?>')" 
                                                            title="Restore Variant">
                                                            <i class="bi bi-arrow-counterclockwise"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="bi bi-box-seam display-4 d-block mb-2 text-secondary opacity-50"></i>
                                    <h5 class="fw-semibold">No products found</h5>
                                    <p class="small text-muted mb-0">Try adjusting your search or filter options to find what you are looking for.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination and Summary Footer -->
            <div class="d-flex justify-content-between align-items-center mt-4 flex-wrap gap-3">
                <div class="text-muted small">
                    <?php if ($total_products > 0): ?>
                        Showing <span class="fw-semibold text-dark"><?= $offset + 1; ?></span> to 
                        <span class="fw-semibold text-dark"><?= min($offset + $per_page, $total_products); ?></span> of 
                        <span class="fw-semibold text-dark"><?= $total_products; ?></span> products
                    <?php else: ?>
                        Showing 0 to 0 of 0 products
                    <?php endif; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Inventory Pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <!-- Previous Page -->
                            <li class="page-item <?= ($page <= 1) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?= getQueryUrl(['page' => $page - 1]); ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo; Prev</span>
                                </a>
                            </li>

                            <!-- Page Numbers -->
                            <?php 
                            $start_p = max(1, $page - 2);
                            $end_p = min($total_pages, $page + 2);
                            for ($i = $start_p; $i <= $end_p; $i++): 
                            ?>
                                <li class="page-item <?= ($i === $page) ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?= getQueryUrl(['page' => $i]); ?>"><?= $i; ?></a>
                                </li>
                            <?php endfor; ?>

                            <!-- Next Page -->
                            <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?= getQueryUrl(['page' => $page + 1]); ?>" aria-label="Next">
                                    <span aria-hidden="true">Next &raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- ======================================================== -->
    <!-- MODALS -->
    <!-- ======================================================== -->

    <!-- 1. ADD VARIANT MODAL -->
    <div class="modal fade" id="addVariantModal" tabindex="-1" aria-labelledby="addVariantModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <form method="POST" action="products.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_variant">
                    <input type="hidden" name="product_id" id="addVar_productId">

                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title fw-bold" id="addVariantModalLabel">
                            <i class="bi bi-plus-circle me-1"></i> Add New Variant
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label text-muted small mb-1">Parent Product</label>
                            <input type="text" id="addVar_parentName" class="form-control fw-bold bg-light" readonly>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Variant Name <span class="text-danger">*</span></label>
                            <input type="text" name="variant_name" class="form-control" placeholder="e.g. 1 Sack, Half Elf, 200cc" required>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Unit of Measure</label>
                                <input type="text" name="unit" id="addVar_unit" class="form-control" placeholder="pcs, kg, sack, elf" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">Initial Stock <span class="text-danger">*</span></label>
                                <input type="number" name="stock" class="form-control" min="0" value="0" required>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Cost Price (₱)</label>
                                <input type="number" name="cost_price" step="0.01" min="0" class="form-control" value="0.00" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">Selling Price (₱) <span class="text-danger">*</span></label>
                                <input type="number" name="price" step="0.01" min="0" class="form-control" placeholder="0.00" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Variant Description</label>
                            <textarea name="variant_description" class="form-control" rows="2" placeholder="Optional notes about this variant..."></textarea>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-semibold">Variant Image (Optional)</label>
                            <input type="file" name="variant_image" class="form-control" accept="image/jpeg,image/png,image/webp">
                            <small class="text-muted">JPG, PNG, or WEBP (Max 20MB)</small>
                        </div>
                    </div>

                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                            <i class="bi bi-save me-1"></i> Save Variant
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 2. EDIT PRODUCT MODAL -->
    <div class="modal fade" id="editProductModal" tabindex="-1" aria-labelledby="editProductModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <form method="POST" action="products.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="edit_product">
                    <input type="hidden" name="product_id" id="editProd_id">

                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title fw-bold" id="editProductModalLabel">
                            <i class="bi bi-pencil-square me-1"></i> Edit Product
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
                            <input type="text" name="product_name" id="editProd_name" class="form-control" required>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Category</label>
                                <input type="text" name="category" id="editProd_category" class="form-control" list="categoryOptions">
                                <datalist id="categoryOptions">
                                    <?php foreach ($categories_list as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">Unit of Measure</label>
                                <input type="text" name="unit" id="editProd_unit" class="form-control" placeholder="pcs, bag, kg" required>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Cost Price (₱)</label>
                                <input type="number" name="cost_price" id="editProd_costPrice" step="0.01" min="0" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">Selling Price (₱) <span class="text-danger">*</span></label>
                                <input type="number" name="price" id="editProd_price" step="0.01" min="0" class="form-control" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Main Stock Level <span class="text-danger">*</span></label>
                            <input type="number" name="stock" id="editProd_stock" min="0" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Product Description</label>
                            <textarea name="product_description" id="editProd_desc" class="form-control" rows="2"></textarea>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-semibold">Replace Image (Optional)</label>
                            <input type="file" name="product_image" class="form-control" accept="image/jpeg,image/png,image/webp">
                            <small class="text-muted">Leave empty to keep existing image</small>
                        </div>
                    </div>

                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                            <i class="bi bi-save me-1"></i> Update Product
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 3. EDIT VARIANT MODAL -->
    <div class="modal fade" id="editVariantModal" tabindex="-1" aria-labelledby="editVariantModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <form method="POST" action="products.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="edit_variant">
                    <input type="hidden" name="variant_id" id="editVar_id">
                    <input type="hidden" name="product_id" id="editVar_productId">

                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title fw-bold" id="editVariantModalLabel">
                            <i class="bi bi-pencil-square me-1"></i> Edit Variant
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label text-muted small mb-1">Parent Product</label>
                            <input type="text" id="editVar_parentName" class="form-control fw-bold bg-light" readonly>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Variant Name <span class="text-danger">*</span></label>
                            <input type="text" name="variant_name" id="editVar_name" class="form-control" required>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Unit of Measure</label>
                                <input type="text" name="unit" id="editVar_unit" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">Available Stock <span class="text-danger">*</span></label>
                                <input type="number" name="stock" id="editVar_stock" min="0" class="form-control" required>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Cost Price (₱)</label>
                                <input type="number" name="cost_price" id="editVar_costPrice" step="0.01" min="0" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">Selling Price (₱) <span class="text-danger">*</span></label>
                                <input type="number" name="price" id="editVar_price" step="0.01" min="0" class="form-control" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Variant Description</label>
                            <textarea name="variant_description" id="editVar_desc" class="form-control" rows="2"></textarea>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-semibold">Replace Variant Image (Optional)</label>
                            <input type="file" name="variant_image" class="form-control" accept="image/jpeg,image/png,image/webp">
                            <small class="text-muted">Leave empty to keep existing image</small>
                        </div>
                    </div>

                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                            <i class="bi bi-save me-1"></i> Update Variant
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 4. ARCHIVE CONFIRMATION MODAL -->
    <div class="modal fade" id="archiveConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <form method="POST" action="products.php">
                    <input type="hidden" name="action" id="archive_action">
                    <input type="hidden" name="id" id="archive_targetId">

                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title fw-bold">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i> Confirm Archive
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body p-4">
                        <p class="mb-2">Are you sure you want to archive <span id="archive_itemType">this item</span>:</p>
                        <h5 class="fw-bold text-danger mb-3" id="archive_itemName"></h5>
                        <div class="alert alert-warning py-2 mb-0" style="font-size: 0.88rem;">
                            <i class="bi bi-info-circle-fill me-1"></i>
                            This will hide the item from active inventory, cashier POS, and customer sales. It will <strong>NOT</strong> permanently delete records.
                        </div>
                    </div>

                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger btn-sm px-3 fw-semibold">
                            <i class="bi bi-archive-fill me-1"></i> Archive Now
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 5. UNARCHIVE CONFIRMATION MODAL -->
    <div class="modal fade" id="unarchiveConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <form method="POST" action="products.php">
                    <input type="hidden" name="action" id="unarchive_action">
                    <input type="hidden" name="id" id="unarchive_targetId">

                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title fw-bold">
                            <i class="bi bi-arrow-counterclockwise me-2"></i> Confirm Restore
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body p-4">
                        <p class="mb-2">Are you sure you want to restore <span id="unarchive_itemType">this item</span> to active inventory:</p>
                        <h5 class="fw-bold text-success mb-3" id="unarchive_itemName"></h5>
                        <div class="alert alert-info py-2 mb-0" style="font-size: 0.88rem;">
                            <i class="bi bi-info-circle-fill me-1"></i>
                            This item will become visible again in the active inventory list.
                        </div>
                    </div>

                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success btn-sm px-3 fw-semibold">
                            <i class="bi bi-check-circle-fill me-1"></i> Restore Item
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 6. IMAGE ZOOM MODAL -->
    <div class="modal fade" id="imageZoomModal" tabindex="-1" aria-hidden="true" style="z-index: 2060;">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-transparent border-0">
                <div class="text-center">
                    <img id="zoomImage" src="" class="img-fluid rounded shadow" style="max-height: 82vh; border: 3px solid #fff;">
                </div>
                <div class="text-center mt-3">
                    <button type="button" class="btn px-4 py-1 fw-bold shadow text-white" style="background: rgba(0,0,0,0.7); border: 1px solid rgba(255,255,255,0.3);" data-bs-dismiss="modal">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Handlers -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Collapsible Accordion Logic
        function toggleVariants(productId) {
            const rows = document.querySelectorAll('.variant-row-' + productId);
            const icon = document.getElementById('chevron-' + productId);
            if (!rows.length) return;

            let willShow = false;
            rows.forEach(row => {
                if (row.classList.contains('d-none')) {
                    row.classList.remove('d-none');
                    willShow = true;
                } else {
                    row.classList.add('d-none');
                    willShow = false;
                }
            });

            if (icon) {
                if (willShow) {
                    icon.classList.remove('bi-chevron-down');
                    icon.classList.add('bi-chevron-up');
                } else {
                    icon.classList.remove('bi-chevron-up');
                    icon.classList.add('bi-chevron-down');
                }
            }
        }

        // Image Zoom
        function openProductImageZoom(imgSrc) {
            document.getElementById('zoomImage').src = imgSrc;
            const modal = new bootstrap.Modal(document.getElementById('imageZoomModal'));
            modal.show();
        }

        // Open Add Variant Modal
        function openAddVariantModal(prodData) {
            document.getElementById('addVar_productId').value = prodData.id;
            document.getElementById('addVar_parentName').value = prodData.name;
            document.getElementById('addVar_unit').value = prodData.unit || 'pcs';
            const modal = new bootstrap.Modal(document.getElementById('addVariantModal'));
            modal.show();
        }

        // Open Edit Product Modal
        function openEditProductModal(prodData) {
            document.getElementById('editProd_id').value = prodData.id;
            document.getElementById('editProd_name').value = prodData.name;
            document.getElementById('editProd_category').value = prodData.category || '';
            document.getElementById('editProd_unit').value = prodData.unit || 'pcs';
            document.getElementById('editProd_costPrice').value = parseFloat(prodData.cost_price || 0).toFixed(2);
            document.getElementById('editProd_price').value = parseFloat(prodData.price || 0).toFixed(2);
            document.getElementById('editProd_stock').value = parseInt(prodData.stock || 0);
            document.getElementById('editProd_desc').value = prodData.description || '';
            const modal = new bootstrap.Modal(document.getElementById('editProductModal'));
            modal.show();
        }

        // Open Edit Variant Modal
        function openEditVariantModal(varData, parentName) {
            document.getElementById('editVar_id').value = varData.id;
            document.getElementById('editVar_productId').value = varData.product_id;
            document.getElementById('editVar_parentName').value = parentName || varData.parent_name || '';
            document.getElementById('editVar_name').value = varData.name;
            document.getElementById('editVar_unit').value = varData.unit || 'pcs';
            document.getElementById('editVar_costPrice').value = parseFloat(varData.cost_price || 0).toFixed(2);
            document.getElementById('editVar_price').value = parseFloat(varData.price || 0).toFixed(2);
            document.getElementById('editVar_stock').value = parseInt(varData.stock || 0);
            document.getElementById('editVar_desc').value = varData.description || '';
            const modal = new bootstrap.Modal(document.getElementById('editVariantModal'));
            modal.show();
        }

        // Open Archive Modal
        function openArchiveModal(type, id, name) {
            document.getElementById('archive_action').value = (type === 'variant') ? 'archive_variant' : 'archive_product';
            document.getElementById('archive_targetId').value = id;
            document.getElementById('archive_itemType').textContent = (type === 'variant') ? 'variant' : 'main product';
            document.getElementById('archive_itemName').textContent = name;
            const modal = new bootstrap.Modal(document.getElementById('archiveConfirmModal'));
            modal.show();
        }

        // Open Unarchive Modal
        function openUnarchiveModal(type, id, name) {
            document.getElementById('unarchive_action').value = (type === 'variant') ? 'unarchive_variant' : 'unarchive_product';
            document.getElementById('unarchive_targetId').value = id;
            document.getElementById('unarchive_itemType').textContent = (type === 'variant') ? 'variant' : 'main product';
            document.getElementById('unarchive_itemName').textContent = name;
            const modal = new bootstrap.Modal(document.getElementById('unarchiveConfirmModal'));
            modal.show();
        }

        // Sidebar Toggle for Mobile
        document.addEventListener("DOMContentLoaded", function () {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const appSidebar = document.getElementById('appSidebar');
            if (sidebarToggle && appSidebar) {
                sidebarToggle.addEventListener('click', function () {
                    appSidebar.classList.toggle('show');
                });
                document.addEventListener('click', function (event) {
                    if (window.innerWidth < 992) {
                        if (!appSidebar.contains(event.target) && !sidebarToggle.contains(event.target)) {
                            appSidebar.classList.remove('show');
                        }
                    }
                });
            }
        });
    </script>
</body>

</html>
