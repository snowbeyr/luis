<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/image.php';
require_once __DIR__ . '/../includes/shared_inventory_functions.php';

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

// Ensure shared inventory schema is ready
ensureSharedInventorySchema($pdo);

// Determine active view
$current_view = $_GET['view'] ?? 'products';

// Helper for audit logging
if (!function_exists('logInventoryAction')) {
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
}

// Helpers for deduplicated product & variant display naming
if (!function_exists('collapseDuplicateVariantPrefix')) {
    function collapseDuplicateVariantPrefix(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        // Collapse repeated "X - X - Y" -> "X - Y"
        while (preg_match('/^(.+?)\s*-\s*\1(\s*-\s*.*)?$/i', $name, $m)) {
            $rest = isset($m[2]) ? trim($m[2]) : '';
            $name = trim($m[1]) . ($rest !== '' ? ' ' . $rest : '');
        }
        return trim($name);
    }
}

if (!function_exists('formatUnifiedVariantDisplayName')) {
    function formatUnifiedVariantDisplayName(string $parentName, string $variantName): string
    {
        $parentClean = trim(preg_replace('/\s+/', ' ', $parentName));
        $variantClean = collapseDuplicateVariantPrefix($variantName);

        if ($variantClean === '') {
            return $parentClean;
        }
        if ($parentClean === '') {
            return $variantClean;
        }

        // 1. If full parent name is already present inside variant name, return variant name as-is
        if (stripos($variantClean, $parentClean) !== false) {
            return $variantClean;
        }

        // 2. Strip trailing sizes, parentheticals, spaced dashes, and unit suffixes from parent to get its core product name
        $parentCore = preg_replace('/\s*\([^)]*\)/', '', $parentClean);
        $parentCore = preg_replace('/\s+[—\-]\s+.*$/u', '', $parentCore);
        $parentCore = preg_replace('/\s+\b\d+(?:\.\d+)?\s*(?:inch|mm|cm|m|ft|w|cc|g|kg|kilo|ml|l|liter|liters|sack|sacks|gallon|pail|pc|pcs)\b.*$/i', '', $parentCore);
        $parentCore = trim($parentCore);

        if ($parentCore !== '' && mb_strlen($parentCore) >= 3 && stripos($variantClean, $parentCore) !== false) {
            return $variantClean;
        }

        // 3. Check if any significant non-unit product keyword (len >= 4) from parentCore already appears in variantClean
        $ignoreWords = ['heavy', 'duty', 'with', 'pack', 'size', 'type', 'kilo', 'sack', 'liter', 'gallon', 'pail'];
        $words = preg_split('/[\s\-\/]+/', strtolower($parentCore), -1, PREG_SPLIT_NO_EMPTY);
        $variantLower = strtolower($variantClean);
        foreach ($words as $w) {
            $wClean = preg_replace('/[^a-z0-9]/', '', $w);
            if (strlen($wClean) >= 4 && !in_array($wClean, $ignoreWords, true)) {
                if (preg_match('/\b' . preg_quote($wClean, '/') . '\b/i', $variantLower)) {
                    return $variantClean;
                }
            }
        }

        return $parentClean . ' - ' . $variantClean;
    }
}

// Helper for image upload
if (!function_exists('handleInventoryImageUpload')) {
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
}

// ==========================================
// HANDLE ACTIONS (POST & GET)
// ==========================================

// 1. ADD VARIANT (POST)
if ((($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') && isset($_POST['action']) && $_POST['action'] === 'add_variant') {
    $parent_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT) ?: 0;
    $v_name = collapseDuplicateVariantPrefix((string) ($_POST['variant_name'] ?? ''));
    $v_desc = trim($_POST['variant_description'] ?? '');
    $v_cost = (float) ($_POST['cost_price'] ?? 0);
    $v_price = (float) ($_POST['price'] ?? 0);
    $v_stock = (int) ($_POST['stock'] ?? 0);

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
        $p_check = $pdo->prepare("SELECT product_name, unit, is_archived FROM products WHERE id = :id");
        $p_check->execute([':id' => $parent_id]);
        $parent = $p_check->fetch(PDO::FETCH_ASSOC);
        if (!$parent) {
            $_SESSION['error_message'] = "Parent product not found.";
            header("Location: products.php");
            exit();
        }
        if ((int)($parent['is_archived'] ?? 0) === 1) {
            $_SESSION['error_message'] = "Cannot add a variant to an archived main product. Please restore '{$parent['product_name']}' first.";
            header("Location: products.php");
            exit();
        }

        $v_unit = trim((string)($parent['unit'] ?? '')) ?: 'pcs';

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
if ((($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') && isset($_POST['action']) && $_POST['action'] === 'edit_variant') {
    $v_id = filter_input(INPUT_POST, 'variant_id', FILTER_VALIDATE_INT) ?: 0;
    $v_name = collapseDuplicateVariantPrefix((string) ($_POST['variant_name'] ?? ''));
    $v_desc = trim($_POST['variant_description'] ?? '');
    $v_cost = (float) ($_POST['cost_price'] ?? 0);
    $v_price = (float) ($_POST['price'] ?? 0);
    $v_stock = (int) ($_POST['stock'] ?? 0);

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
        $cur_stmt = $pdo->prepare("SELECT pv.*, p.product_name FROM product_variants pv JOIN products p ON p.id = pv.product_id WHERE pv.id = :id");
        $cur_stmt->execute([':id' => $v_id]);
        $current_var = $cur_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$current_var) {
            $_SESSION['error_message'] = "Variant not found.";
            header("Location: products.php");
            exit();
        }

        $effective_pid = $current_var['product_id'];

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
            SET variant_name = :name, variant_description = :desc, cost_price = :cost, price = :price, stock = :stock $img_sql
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
if ((($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') && isset($_POST['action']) && $_POST['action'] === 'edit_product') {
    $p_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT) ?: 0;
    $p_name = trim($_POST['product_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $cost_price = (float) ($_POST['cost_price'] ?? 0);
    $price = (float) ($_POST['price'] ?? 0);
    $stock = (int) ($_POST['stock'] ?? 0);
    $desc = trim($_POST['product_description'] ?? '');

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
            SET product_name = :name, category = :cat, cost_price = :cost, price = :price, stock = :stock, product_description = :desc $img_sql
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

// 4. SHARED STOCK ACTIONS (POST)
if ((($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Create Shared Source
    if ($action === 'create_source') {
        $source_name = trim($_POST['source_name'] ?? '');
        $base_unit = trim($_POST['base_unit'] ?? '');
        $physical_stock = floatval($_POST['physical_stock'] ?? 0);
        $low_stock_threshold = floatval($_POST['low_stock_threshold'] ?? 0);

        if ($source_name === '' || $base_unit === '') {
            $_SESSION['error_message'] = "Source Name and Base Unit of Measurement are required.";
        } elseif ($physical_stock < 0) {
            $_SESSION['error_message'] = "Initial physical stock cannot be negative.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO shared_inventory_sources (source_name, base_unit, physical_stock, low_stock_threshold, is_active, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW()) RETURNING id
                ");
                $stmt->execute([$source_name, $base_unit, $physical_stock, $low_stock_threshold, $admin_user]);
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
                    $stmt_mov->execute([$new_id, $physical_stock, $physical_stock, $admin_user]);
                }

                $_SESSION['message'] = "Shared inventory source '{$source_name}' created successfully!";
            } catch (Exception $e) {
                $_SESSION['error_message'] = "Failed to create source: " . $e->getMessage();
            }
        }
        header("Location: products.php?view=shared_stock");
        exit();
    }

    // Edit Shared Source
    if ($action === 'edit_source') {
        $source_id = intval($_POST['source_id'] ?? 0);
        $source_name = trim($_POST['source_name'] ?? '');
        $base_unit = trim($_POST['base_unit'] ?? '');
        $low_stock_threshold = floatval($_POST['low_stock_threshold'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($source_id <= 0 || $source_name === '' || $base_unit === '') {
            $_SESSION['error_message'] = "Invalid source parameters.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE shared_inventory_sources 
                    SET source_name = ?, base_unit = ?, low_stock_threshold = ?, is_active = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$source_name, $base_unit, $low_stock_threshold, $is_active, $source_id]);
                syncSharedInventoryVariants($pdo, $source_id);
                $_SESSION['message'] = "Shared inventory source #{$source_id} updated successfully!";
            } catch (Exception $e) {
                $_SESSION['error_message'] = "Update failed: " . $e->getMessage();
            }
        }
        header("Location: products.php?view=shared_stock");
        exit();
    }

    // Adjust Shared Stock
    if ($action === 'adjust_stock') {
        $source_id = intval($_POST['source_id'] ?? 0);
        $new_stock = floatval($_POST['new_physical_stock'] ?? 0);
        $reason = trim($_POST['adjustment_reason'] ?? '');

        if ($source_id <= 0 || $reason === '') {
            $_SESSION['error_message'] = "Adjustment reason is mandatory.";
        } else {
            list($success, $msg, $stock) = adjustSharedStock($pdo, $source_id, $new_stock, $admin_user, $reason);
            if ($success) {
                $_SESSION['message'] = $msg;
            } else {
                $_SESSION['error_message'] = $msg;
            }
        }
        header("Location: products.php?view=shared_stock");
        exit();
    }

    // Link Variant to Shared Source
    if ($action === 'link_variant') {
        $source_id = intval($_POST['source_id'] ?? 0);
        $target_item = trim($_POST['target_item'] ?? '');
        $conversion_qty = floatval($_POST['conversion_qty'] ?? 0);
        $selling_unit_label = trim($_POST['selling_unit_label'] ?? '');

        if ($source_id <= 0 || empty($target_item) || $conversion_qty <= 0) {
            $_SESSION['error_message'] = "Please choose a valid product/variant and enter a conversion requirement greater than 0.";
        } else {
            $is_var = str_starts_with($target_item, 'v_');
            $item_id = intval(substr($target_item, 2));

            $prod_id = null;
            $var_id = null;

            if ($is_var) {
                $var_id = $item_id;
                $stmt_p = $pdo->prepare("SELECT product_id FROM product_variants WHERE id = ?");
                $stmt_p->execute([$var_id]);
                $prod_id = $stmt_p->fetchColumn();
            } else {
                $prod_id = $item_id;
                $var_id = null;
            }

            if (!$prod_id) {
                $_SESSION['error_message'] = "Selected product could not be found.";
            } else {
                try {
                    $stmt_chk = $pdo->prepare("
                        SELECT siv.id, sis.source_name 
                        FROM shared_inventory_variants siv
                        JOIN shared_inventory_sources sis ON siv.source_id = sis.id
                        WHERE siv.product_id = ? AND " . ($var_id ? "siv.variant_id = ?" : "(siv.variant_id IS NULL OR siv.variant_id = 0)") . "
                    ");
                    $stmt_chk->execute($var_id ? [$prod_id, $var_id] : [$prod_id]);
                    $existing = $stmt_chk->fetch(PDO::FETCH_ASSOC);

                    if ($existing) {
                        $_SESSION['error_message'] = "This item is already linked to shared source '{$existing['source_name']}'. Please disconnect it first if you want to link it to a different source.";
                    } else {
                        $stmt_ins = $pdo->prepare("
                            INSERT INTO shared_inventory_variants (source_id, product_id, variant_id, conversion_qty, selling_unit_label, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                        ");
                        $stmt_ins->execute([$source_id, $prod_id, $var_id, $conversion_qty, $selling_unit_label]);
                        syncSharedInventoryVariants($pdo, $source_id);
                        $_SESSION['message'] = "Item successfully linked to shared inventory source!";
                    }
                } catch (Exception $e) {
                    $_SESSION['error_message'] = "Failed to link item: " . $e->getMessage();
                }
            }
        }
        header("Location: products.php?view=shared_stock");
        exit();
    }

    // Disconnect Variant from Shared Source
    if ($action === 'disconnect_variant') {
        $link_id = intval($_POST['link_id'] ?? 0);
        if ($link_id <= 0) {
            $_SESSION['error_message'] = "Invalid link ID.";
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
                    $_SESSION['message'] = "Variant disconnected from shared source. Stock is now independent.";
                } else {
                    $_SESSION['error_message'] = "Link record not found.";
                }
            } catch (Exception $e) {
                $_SESSION['error_message'] = "Failed to disconnect: " . $e->getMessage();
            }
        }
        header("Location: products.php?view=shared_stock");
        exit();
    }
}

// 5. ARCHIVE / UNARCHIVE (PRODUCT & VARIANT)
$target_id = filter_var($_GET['id'] ?? ($_POST['id'] ?? null), FILTER_VALIDATE_INT);
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if (isset($_GET['archive_id'])) {
    $action = 'archive_product';
    $target_id = filter_var($_GET['archive_id'], FILTER_VALIDATE_INT);
} elseif (isset($_GET['unarchive_id'])) {
    $action = 'unarchive_product';
    $target_id = filter_var($_GET['unarchive_id'], FILTER_VALIDATE_INT);
}

if (in_array($action, ['archive_product', 'unarchive_product', 'archive_variant', 'unarchive_variant'], true) && $target_id > 0) {
    try {
        $pdo->beginTransaction();

        if ($action === 'archive_product' || $action === 'unarchive_product') {
            $is_archive = ($action === 'archive_product');
            $status_val = $is_archive ? 1 : 0;

            $stmt = $pdo->prepare("SELECT product_name FROM products WHERE id = :id");
            $stmt->execute([':id' => $target_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            $item_name = $item['product_name'] ?? 'Product #' . $target_id;

            $upd = $pdo->prepare("UPDATE products SET is_archived = :status WHERE id = :id");
            $upd->execute([':status' => $status_val, ':id' => $target_id]);

            $affected_variants = 0;
            if ($is_archive && (($_POST['archive_variants'] ?? '0') === '1')) {
                $upd_vars = $pdo->prepare("UPDATE product_variants SET is_archived = 1 WHERE product_id = :id AND is_archived = 0");
                $upd_vars->execute([':id' => $target_id]);
                $affected_variants = $upd_vars->rowCount();
            } elseif (!$is_archive && (($_POST['restore_variants'] ?? '0') === '1')) {
                $upd_vars = $pdo->prepare("UPDATE product_variants SET is_archived = 0 WHERE product_id = :id AND is_archived = 1");
                $upd_vars->execute([':id' => $target_id]);
                $affected_variants = $upd_vars->rowCount();
            }

            $var_note = $affected_variants > 0 ? " along with {$affected_variants} variant(s)" : "";
            logInventoryAction(
                $pdo,
                $admin_user_id,
                $admin_user,
                $admin_role,
                $is_archive ? 'Archive Product' : 'Unarchive Product',
                ($is_archive ? "Archived product: " : "Unarchived product: ") . $item_name . $var_note,
                $ip_address
            );

            $_SESSION['message'] = $is_archive
                ? "Product '{$item_name}'{$var_note} archived successfully!"
                : "Product '{$item_name}'{$var_note} restored successfully!";

        } elseif ($action === 'archive_variant' || $action === 'unarchive_variant') {
            $is_archive = ($action === 'archive_variant');
            $status_val = $is_archive ? 1 : 0;

            $stmt = $pdo->prepare("
                SELECT pv.variant_name, pv.product_id, p.product_name, p.is_archived AS parent_archived
                FROM product_variants pv
                JOIN products p ON p.id = pv.product_id
                WHERE pv.id = :id
            ");
            $stmt->execute([':id' => $target_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            $item_name = $item['variant_name'] ?? 'Variant #' . $target_id;

            // If restoring a variant whose parent product is archived, automatically restore parent product as well so it isn't orphaned
            $parent_restored_note = '';
            if (!$is_archive && $item && (int)$item['parent_archived'] === 1) {
                $upd_parent = $pdo->prepare("UPDATE products SET is_archived = 0 WHERE id = :pid");
                $upd_parent->execute([':pid' => $item['product_id']]);
                $parent_restored_note = " (Parent product '{$item['product_name']}' was also restored so this variant is active.)";
            }

            $upd = $pdo->prepare("UPDATE product_variants SET is_archived = :status WHERE id = :id");
            $upd->execute([':status' => $status_val, ':id' => $target_id]);

            logInventoryAction(
                $pdo,
                $admin_user_id,
                $admin_user,
                $admin_role,
                $is_archive ? 'Archive Variant' : 'Unarchive Variant',
                ($is_archive ? "Archived variant: " : "Unarchived variant: ") . $item_name . $parent_restored_note,
                $ip_address
            );

            $_SESSION['message'] = $is_archive
                ? "Variant '{$item_name}' archived successfully!"
                : "Variant '{$item_name}' restored successfully!{$parent_restored_note}";
        }

        $pdo->commit();

        $redirect_status = $_POST['redirect_status'] ?? ($_GET['status'] ?? '');
        header("Location: products.php" . ($redirect_status ? "?status=" . urlencode($redirect_status) : ""));
        exit();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Archive/Unarchive error: " . $e->getMessage());
        $_SESSION['error_message'] = "Unable to update archive status.";
        header("Location: products.php");
        exit();
    }
}

// ==========================================
// DATA LOADING FOR PRODUCTS & SHARED STOCK
// ==========================================

if ($current_view === 'shared_stock') {
    // --- LOAD SHARED STOCK DATA ---
    $sources_stmt = $pdo->query("
        SELECT sis.*,
               COUNT(siv.id) AS connected_variants_count
        FROM shared_inventory_sources sis
        LEFT JOIN shared_inventory_variants siv ON sis.id = siv.source_id
        GROUP BY sis.id
        ORDER BY sis.is_active DESC, sis.id ASC
    ");
    $sources = $sources_stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_sources = count($sources);
    $active_sources = 0;
    $low_stock_sources = 0;
    $total_connected_variants = 0;

    foreach ($sources as $s) {
        if ($s['is_active'] == 1) $active_sources++;
        if ($s['physical_stock'] <= $s['low_stock_threshold']) $low_stock_sources++;
        $total_connected_variants += (int)$s['connected_variants_count'];
    }

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

    // Get unlinked products & variants
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
        $stmt_has_var = $pdo->prepare("SELECT COUNT(*) FROM product_variants WHERE product_id = ?");
        $stmt_has_var->execute([$p['id']]);
        $var_count = (int)$stmt_has_var->fetchColumn();

        $unlinked_items[] = [
            'type' => 'product',
            'value' => 'p_' . $p['id'],
            'label' => "[Product] " . $p['product_name'] . ($var_count > 0 ? " (Base Product)" : " (Standalone)") . " - ₱" . number_format($p['price'], 2) . " (Stock: {$p['stock']})"
        ];
    }

    $stmt_unlinked_var = $pdo->query("
        SELECT pv.id, pv.product_id, pv.variant_name, pv.price, pv.stock, p.product_name
        FROM product_variants pv
        JOIN products p ON pv.product_id = p.id
        WHERE pv.id NOT IN (
            SELECT variant_id FROM shared_inventory_variants WHERE variant_id IS NOT NULL AND variant_id > 0
        ) AND p.is_archived = 0 AND pv.is_archived = 0
        ORDER BY pv.variant_name ASC
    ");
    $unlinked_vars = $stmt_unlinked_var->fetchAll(PDO::FETCH_ASSOC);
    foreach ($unlinked_vars as $v) {
        $unlinked_items[] = [
            'type' => 'variant',
            'value' => 'v_' . $v['id'],
            'label' => "[Variant] " . collapseDuplicateVariantPrefix($v['variant_name']) . " - ₱" . number_format($v['price'], 2) . " (Stock: {$v['stock']})"
        ];
    }

} else {
    // --- LOAD UNIFIED PRODUCTS & VARIANTS DATA ---
    $low_stock_threshold = 10;
    $search = trim($_GET['search'] ?? '');
    $category_filter = trim($_GET['category'] ?? '');
    $type_filter = trim($_GET['type'] ?? '');
    $status_filter = trim($_GET['status'] ?? ($_GET['filter'] ?? ''));
    $sort = trim($_GET['sort'] ?? 'newest');
    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
    $per_page = 20;

    try {
        $cat_stmt = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND TRIM(category) != '' ORDER BY category ASC");
        $categories_list = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $categories_list = [];
    }

    // Active main products ONLY for Add Variant searchable dropdown (unique, no variants)
    try {
        $active_mp_stmt = $pdo->query("
            SELECT DISTINCT ON (LOWER(TRIM(product_name))) id, product_name
            FROM products
            WHERE is_archived = 0
            ORDER BY LOWER(TRIM(product_name)) ASC, id ASC
        ");
        $active_main_products = $active_mp_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $active_main_products = [];
    }

    $base_union_sql = "
        SELECT
            p.id AS id,
            p.id AS product_id,
            NULL::integer AS variant_id,
            p.product_name AS item_name,
            ''::varchar AS parent_name,
            p.category AS category,
            p.cost_price AS cost_price,
            p.price AS price,
            p.stock AS stock,
            p.product_description AS description,
            p.image AS image,
            p.is_archived AS is_archived,
            0 AS parent_is_archived,
            'Main' AS item_type,
            p.id AS parent_sort_id,
            0 AS is_variant_order,
            (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id = p.id AND pv.is_archived = 0) AS active_variants_count,
            (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id = p.id AND pv.is_archived = 1) AS archived_variants_count
        FROM products p

        UNION ALL

        SELECT
            v.id AS id,
            v.product_id AS product_id,
            v.id AS variant_id,
            v.variant_name AS item_name,
            p.product_name AS parent_name,
            p.category AS category,
            v.cost_price AS cost_price,
            v.price AS price,
            v.stock AS stock,
            v.variant_description AS description,
            COALESCE(NULLIF(v.variant_image, ''), p.image) AS image,
            CASE WHEN p.is_archived = 1 OR v.is_archived = 1 THEN 1 ELSE 0 END AS is_archived,
            p.is_archived AS parent_is_archived,
            'Variant' AS item_type,
            p.id AS parent_sort_id,
            1 AS is_variant_order,
            0 AS active_variants_count,
            0 AS archived_variants_count
        FROM product_variants v
        JOIN products p ON v.product_id = p.id
    ";

    $where_clauses = [];
    $params = [];

    if ($status_filter === 'archived') {
        $where_clauses[] = "inv.is_archived = 1";
    } else {
        $where_clauses[] = "inv.is_archived = 0";

        if ($status_filter === 'in_stock') {
            $where_clauses[] = "inv.stock > {$low_stock_threshold}";
        } elseif ($status_filter === 'low_stock') {
            $where_clauses[] = "(inv.stock > 0 AND inv.stock <= {$low_stock_threshold})";
        } elseif ($status_filter === 'out_of_stock') {
            $where_clauses[] = "(inv.stock <= 0 AND NOT (inv.item_type = 'Main' AND inv.active_variants_count > 0))";
        }
    }

    if ($type_filter === 'main') {
        $where_clauses[] = "inv.item_type = 'Main'";
    } elseif ($type_filter === 'variant') {
        $where_clauses[] = "inv.item_type = 'Variant'";
    }

    if ($category_filter !== '') {
        $where_clauses[] = "inv.category = :category";
        $params[':category'] = $category_filter;
    }

    if ($search !== '') {
        $where_clauses[] = "(
            inv.item_name ILIKE :search_name
            OR inv.parent_name ILIKE :search_parent
            OR inv.category ILIKE :search_cat
            OR CAST(inv.id AS TEXT) = :search_exact
        )";
        $search_param = '%' . $search . '%';
        $params[':search_name'] = $search_param;
        $params[':search_parent'] = $search_param;
        $params[':search_cat'] = $search_param;
        $params[':search_exact'] = $search;
    }

    $where_sql = implode(' AND ', $where_clauses);

    $order_sql = "ORDER BY inv.parent_sort_id DESC, inv.is_variant_order ASC, inv.id ASC";
    if ($sort === 'oldest') {
        $order_sql = "ORDER BY inv.parent_sort_id ASC, inv.is_variant_order ASC, inv.id ASC";
    } elseif ($sort === 'az') {
        $order_sql = "ORDER BY inv.item_name ASC, inv.is_variant_order ASC";
    } elseif ($sort === 'za') {
        $order_sql = "ORDER BY inv.item_name DESC, inv.is_variant_order ASC";
    } elseif ($sort === 'stock_asc') {
        $order_sql = "ORDER BY inv.stock ASC, inv.id ASC";
    } elseif ($sort === 'stock_desc') {
        $order_sql = "ORDER BY inv.stock DESC, inv.id DESC";
    }

    try {
        $count_sql = "SELECT COUNT(*) FROM ($base_union_sql) AS inv WHERE $where_sql";
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute($params);
        $total_items = (int) $count_stmt->fetchColumn();

        $total_pages = max(1, (int) ceil($total_items / $per_page));
        $page = max(1, min($page, $total_pages));
        $offset = ($page - 1) * $per_page;

        $items_sql = "
            SELECT inv.*
            FROM ($base_union_sql) AS inv
            WHERE $where_sql
            $order_sql
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $pdo->prepare($items_sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $inventory_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Unified inventory query error: " . $e->getMessage());
        $inventory_items = [];
        $total_items = 0;
        $total_pages = 1;
    }
}

if (!function_exists('getQueryUrl')) {
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

        /* Table Styling (Matched to Reference Layout) */
        .inventory-table {
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
            background: #ffffff;
        }

        .inventory-table thead th {
            background-color: #f8fafc !important;
            color: #475569 !important;
            font-weight: 700;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 13px 14px;
            vertical-align: middle;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }

        .inventory-table tbody tr.main-row {
            background-color: #ffffff;
            transition: background-color 0.15s ease;
        }

        .inventory-table tbody tr.main-row:hover {
            background-color: #f8fafc;
        }

        .inventory-table tbody tr.variant-row {
            background-color: #fcfdff;
            border-left: 3px solid #8b5cf6;
            transition: background-color 0.15s ease;
        }

        .inventory-table tbody tr.variant-row:hover {
            background-color: #f3f6ff;
        }

        .inventory-table td {
            padding: 11px 14px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.9rem;
        }

        /* Item Image Thumbnail */
        .item-img {
            width: 44px;
            height: 44px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #fff;
            cursor: zoom-in;
            transition: transform 0.2s ease;
            flex-shrink: 0;
        }

        .item-img:hover {
            transform: scale(1.1);
        }

        /* Searchable Main Product Dropdown inside Add Variant Modal */
        .searchable-select-menu {
            max-height: 260px;
            overflow-y: auto;
        }
        .searchable-select-option {
            cursor: pointer;
            font-size: 0.9rem;
            padding: 7px 12px;
        }
        .searchable-select-option:hover,
        .searchable-select-option.active {
            background-color: #eff6ff;
            color: #1d4ed8;
            font-weight: 600;
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

        .badge-has-variants {
            background-color: #e0e7ff;
            color: #3730a3;
            border: 1px solid #c7d2fe;
        }

        .badge-archived {
            background-color: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
        }

        .badge-type {
            font-weight: 600;
            font-size: 0.76rem;
            padding: 4px 9px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }

        .badge-type-main {
            background-color: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
        }

        .badge-type-variant {
            background-color: #ecfeff;
            color: #0e7490;
            border: 1px solid #a5f3fc;
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

        /* Shared Stock Cards */
        .stat-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 18px 20px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
            height: 100%;
        }

        .source-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            margin-bottom: 24px;
            overflow: hidden;
        }

        .source-card-header {
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            padding: 16px 20px;
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

    <!-- Sidebar (Shared Stock link removed - integrated inside Inventory) -->
    <div class="sidebar" id="appSidebar">
        <h2>Admin<br>Dashboard</h2>
        <a href="dashboard.php"><i class="bi bi-grid"></i> Dashboard</a>
        <a href="products.php" class="active"><i class="bi bi-box-seam"></i> Inventory</a>
        <a href="orders.php"><i class="bi bi-receipt"></i> Orders</a>
        <a href="ItemsRequest.php"><i class="bi bi-arrow-return-left"></i> Customer Service request</a>
        <a href="damageItems.php"><i class="bi bi-exclamation-triangle"></i> Damaged Items</a>
        <a href="profile.php" class="sidebar-link"><i class="bi bi-person-badge"></i> My Profile</a>
        <div class="logout-section">
            <a href="../logout.php" class="text-danger" onclick="return confirm('Are you sure you want to logout?')">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="main-content">
        <div class="main-container">

            <!-- Page Header with View Switcher -->
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                <div>
                    <h1 class="page-title">Inventory Management</h1>
                    <p class="page-subtitle">
                        <?= ($current_view === 'shared_stock') ? 'Manage physical bulk stock and linked product variants' : 'Manage your products and variants'; ?>
                    </p>
                </div>
                <div class="d-flex gap-2 flex-wrap align-items-center">
                    <!-- View Toggle Pills -->
                    <div class="btn-group p-1 bg-light border rounded-pill shadow-sm" role="group">
                        <a href="products.php" class="btn btn-sm rounded-pill px-3 py-1.5 <?= ($current_view !== 'shared_stock') ? 'btn-primary text-white shadow-sm fw-semibold' : 'btn-light text-muted'; ?>">
                            <i class="bi bi-box-seam me-1"></i> Products & Variants
                        </a>
                        <a href="products.php?view=shared_stock" class="btn btn-sm rounded-pill px-3 py-1.5 <?= ($current_view === 'shared_stock') ? 'btn-primary text-white shadow-sm fw-semibold' : 'btn-light text-muted'; ?>">
                            <i class="bi bi-diagram-3 me-1"></i> Shared Stock
                        </a>
                    </div>

                    <?php if ($current_view !== 'shared_stock'): ?>
                        <a href="addProduct.php" class="btn btn-primary rounded-pill px-3 py-2 fw-semibold btn-sm d-inline-flex align-items-center gap-2 shadow-sm">
                            <i class="bi bi-plus-lg"></i> Add Product
                        </a>
                        <button type="button" class="btn btn-outline-primary rounded-pill px-3 py-2 fw-semibold btn-sm d-inline-flex align-items-center gap-2 shadow-sm" onclick="openAddVariantModal()">
                            <i class="bi bi-diagram-2-fill"></i> Add Variant
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn btn-primary rounded-pill px-3 py-2 fw-semibold btn-sm d-inline-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#createSourceModal">
                            <i class="bi bi-plus-circle"></i> Create Shared Source
                        </button>
                    <?php endif; ?>
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

            <!-- ======================================================== -->
            <!-- VIEW: SHARED STOCK MANAGEMENT                            -->
            <!-- ======================================================== -->
            <?php if ($current_view === 'shared_stock'): ?>

                <!-- Metric Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-4">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <small class="text-muted text-uppercase fw-semibold" style="font-size: 0.78rem;">Shared Sources</small>
                                    <h3 class="fw-bold mb-0 text-dark"><?= $total_sources; ?></h3>
                                    <small class="text-success"><i class="bi bi-check-circle"></i> <?= $active_sources; ?> Active</small>
                                </div>
                                <div class="p-3 bg-primary-subtle text-primary rounded-circle">
                                    <i class="bi bi-diagram-3 fs-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <small class="text-muted text-uppercase fw-semibold" style="font-size: 0.78rem;">Linked Variants</small>
                                    <h3 class="fw-bold mb-0 text-dark"><?= $total_connected_variants; ?></h3>
                                    <small class="text-muted">Selling variations</small>
                                </div>
                                <div class="p-3 bg-info-subtle text-info rounded-circle">
                                    <i class="bi bi-link-45deg fs-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <small class="text-muted text-uppercase fw-semibold" style="font-size: 0.78rem;">Low Stock Sources</small>
                                    <h3 class="fw-bold mb-0 <?= $low_stock_sources > 0 ? 'text-danger' : 'text-dark'; ?>"><?= $low_stock_sources; ?></h3>
                                    <small class="<?= $low_stock_sources > 0 ? 'text-danger' : 'text-muted'; ?>">Below threshold</small>
                                </div>
                                <div class="p-3 bg-warning-subtle text-warning rounded-circle">
                                    <i class="bi bi-exclamation-triangle fs-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Shared Sources List -->
                <?php if (empty($sources)): ?>
                    <div class="text-center py-5 bg-white rounded-3 border">
                        <i class="bi bi-diagram-3 display-4 text-secondary opacity-50 mb-3 d-block"></i>
                        <h4 class="fw-bold text-dark">No Shared Inventory Sources Configured</h4>
                        <p class="text-muted mb-3">Create your first physical inventory source (e.g. Gravel, Sand, Aggregates) and link multiple selling variants to it.</p>
                        <button class="btn btn-primary px-4 rounded-pill" data-bs-toggle="modal" data-bs-target="#createSourceModal">
                            <i class="bi bi-plus-circle me-1"></i> Create First Shared Source
                        </button>
                    </div>
                <?php else: ?>
                    <?php foreach ($sources as $source): 
                        $s_id = $source['id'];
                        $cur_stock = floatval($source['physical_stock']);
                        $thresh = floatval($source['low_stock_threshold']);
                        $is_low = ($cur_stock <= $thresh);
                        $is_out = ($cur_stock <= 0);
                        $connected_links = $links_by_source[$s_id] ?? [];
                    ?>
                        <div class="source-card">
                            <!-- Card Header -->
                            <div class="source-card-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                                <div>
                                    <div class="d-flex align-items-center gap-2">
                                        <h4 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($source['source_name']); ?></h4>
                                        <?php if ($source['is_active'] == 1): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2.5 py-1">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border rounded-pill px-2.5 py-1">Inactive</span>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted">
                                        Source ID: <strong>#<?= $s_id; ?></strong> | Base Unit: <strong><?= htmlspecialchars($source['base_unit']); ?></strong> | Created: <?= date('M d, Y', strtotime($source['created_at'])); ?>
                                    </small>
                                </div>

                                <div class="d-flex gap-2 flex-wrap">
                                    <button class="btn btn-sm btn-primary rounded-pill px-3 py-1.5 shadow-sm" onclick="openLinkModal(<?= $s_id; ?>, '<?= addslashes(htmlspecialchars($source['source_name'])); ?>', '<?= addslashes(htmlspecialchars($source['base_unit'])); ?>')">
                                        <i class="bi bi-link-45deg me-1"></i> Link Variant
                                    </button>
                                    <button class="btn btn-sm btn-outline-warning text-dark rounded-pill px-3 py-1.5" onclick="openAdjustModal(<?= $s_id; ?>, '<?= addslashes(htmlspecialchars($source['source_name'])); ?>', <?= $cur_stock; ?>, '<?= addslashes(htmlspecialchars($source['base_unit'])); ?>')">
                                        <i class="bi bi-sliders me-1"></i> Adjust Stock
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1.5" onclick="openEditSourceModal(<?= $s_id; ?>, '<?= addslashes(htmlspecialchars($source['source_name'])); ?>', '<?= addslashes(htmlspecialchars($source['base_unit'])); ?>', <?= $thresh; ?>, <?= $source['is_active']; ?>)">
                                        <i class="bi bi-pencil me-1"></i> Edit Source
                                    </button>
                                </div>
                            </div>

                            <!-- Card Body -->
                            <div class="p-4">
                                <!-- Physical Stock Banner -->
                                <div class="row g-3 mb-4">
                                    <div class="col-md-6 col-lg-4">
                                        <div class="p-3 rounded-3 border <?= $is_out ? 'bg-danger-subtle border-danger' : ($is_low ? 'bg-warning-subtle border-warning' : 'bg-light border-secondary-subtle'); ?>">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <span class="text-muted small text-uppercase fw-semibold d-block">Available Physical Stock</span>
                                                    <span class="fs-3 fw-bold <?= $is_out ? 'text-danger' : ($is_low ? 'text-warning-emphasis' : 'text-primary'); ?>">
                                                        <?= number_format($cur_stock, 2); ?> <?= htmlspecialchars($source['base_unit']); ?>
                                                    </span>
                                                </div>
                                                <div>
                                                    <?php if ($is_out): ?>
                                                        <span class="badge bg-danger rounded-pill px-2.5 py-1.5"><i class="bi bi-x-circle me-1"></i> Out of Stock</span>
                                                    <?php elseif ($is_low): ?>
                                                        <span class="badge bg-warning text-dark rounded-pill px-2.5 py-1.5"><i class="bi bi-exclamation-triangle me-1"></i> Low Stock Alert</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success rounded-pill px-2.5 py-1.5"><i class="bi bi-check-circle me-1"></i> Normal Level</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="mt-2 text-muted small">
                                                Low stock alert triggers when physical stock reaches <strong><?= number_format($thresh, 2); ?> <?= htmlspecialchars($source['base_unit']); ?></strong> or below.
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 col-lg-8">
                                        <div class="p-3 rounded-3 border bg-light h-100 d-flex flex-column justify-content-center">
                                            <h6 class="fw-bold text-dark mb-1"><i class="bi bi-info-circle-fill text-primary me-1"></i> Shared Stock Calculation Rule</h6>
                                            <p class="small text-muted mb-0">
                                                All linked variants derive their sellable stock directly from this <strong><?= htmlspecialchars($source['source_name']); ?></strong> bulk supply. Selling or refunding any variant automatically converts to <strong><?= htmlspecialchars($source['base_unit']); ?></strong> and updates this physical stock in real time across POS, Inventory, and Customer ordering.
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Connected Variants Table -->
                                <h6 class="fw-bold text-dark mb-2 d-flex align-items-center gap-2">
                                    <i class="bi bi-diagram-2 text-primary"></i> Linked Products & Selling Variants (<?= count($connected_links); ?>)
                                </h6>

                                <?php if (empty($connected_links)): ?>
                                    <div class="text-center py-4 bg-light rounded-3 border text-muted">
                                        <p class="mb-2">No products or variants linked to this shared source yet.</p>
                                        <button class="btn btn-outline-primary btn-sm rounded-pill" onclick="openLinkModal(<?= $s_id; ?>, '<?= addslashes(htmlspecialchars($source['source_name'])); ?>', '<?= addslashes(htmlspecialchars($source['base_unit'])); ?>')">
                                            <i class="bi bi-link-45deg me-1"></i> Link Variant Now
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle border mb-0" style="font-size: 0.9rem;">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Item Type</th>
                                                    <th>Product / Variant Name</th>
                                                    <th>Unit Label</th>
                                                    <th>Selling Price</th>
                                                    <th class="text-center">Available Sellable Units</th>
                                                    <th>Conversion Requirement</th>
                                                    <th class="text-center">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($connected_links as $v): 
                                                    $is_var = !empty($v['variant_id']);
                                                    $item_name = $is_var ? $v['variant_name'] : $v['product_name'];
                                                    $sub_label = $is_var ? "Variant of: " . $v['product_name'] : "Main Product";
                                                    $price = $is_var ? $v['var_price'] : $v['prod_price'];
                                                    $conv_val = floatval($v['conversion_qty']);
                                                    $current_sellable = calculateSellableUnits($cur_stock, $v['conversion_qty']);
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <?php if ($is_var): ?>
                                                                <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill">Variant</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">Product</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="fw-bold text-dark"><?= htmlspecialchars($item_name); ?></div>
                                                            <small class="text-muted"><?= htmlspecialchars($sub_label); ?></small>
                                                        </td>
                                                        <td><?= htmlspecialchars($v['selling_unit_label'] ?: 'unit'); ?></td>
                                                        <td class="fw-bold text-success">₱<?= number_format(floatval($price), 2); ?></td>
                                                        <td class="text-center">
                                                            <span class="badge <?= $current_sellable > 0 ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-danger-subtle text-danger border border-danger-subtle'; ?> rounded-pill px-3 py-1.5 fs-6 fw-bold">
                                                                <?= $current_sellable; ?> <?= htmlspecialchars($v['selling_unit_label'] ?: 'units'); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            1 <?= htmlspecialchars($v['selling_unit_label'] ?: 'unit'); ?> = 
                                                            <strong><?= number_format($conv_val, 2); ?> <?= htmlspecialchars($source['base_unit']); ?></strong> 
                                                            <small class="text-muted d-block">Deducted from physical stock per sale</small>
                                                        </td>
                                                        <td class="text-center">
                                                            <form method="POST" action="products.php?view=shared_stock" onsubmit="return confirm('Are you sure you want to disconnect this item? It will revert to independent stock management.');" style="display:inline;">
                                                                <input type="hidden" name="action" value="disconnect_variant">
                                                                <input type="hidden" name="link_id" value="<?= $v['id']; ?>">
                                                                <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-2.5" title="Disconnect from Shared Stock">
                                                                    <i class="bi bi-trash"></i> Disconnect
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
                    <?php endforeach; ?>
                <?php endif; ?>

            <!-- ======================================================== -->
            <!-- VIEW: PRODUCTS & VARIANTS TABLE                          -->
            <!-- ======================================================== -->
            <?php else: ?>

                <!-- Search and Filters Form -->
                <div class="filter-card">
                    <form method="GET" action="products.php" id="filterForm" class="row g-2 align-items-center">
                        <div class="col-12 col-md-3">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                                <input type="text" name="search" class="form-control border-start-0" 
                                    placeholder="Search product, variant, or ID..." 
                                    value="<?= htmlspecialchars($search); ?>">
                            </div>
                        </div>

                        <div class="col-6 col-md-2">
                            <select name="type" class="form-select" onchange="this.form.submit()">
                                <option value="" <?= ($type_filter === '') ? 'selected' : ''; ?>>All Types</option>
                                <option value="main" <?= ($type_filter === 'main') ? 'selected' : ''; ?>>Main Only</option>
                                <option value="variant" <?= ($type_filter === 'variant') ? 'selected' : ''; ?>>Variant Only</option>
                            </select>
                        </div>

                        <div class="col-6 col-md-2">
                            <select name="category" class="form-select" onchange="this.form.submit()">
                                <option value="">All Categories</option>
                                <?php foreach ($categories_list as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat); ?>" <?= ($category_filter === $cat) ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($cat); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-2">
                            <select name="status" class="form-select" onchange="this.form.submit()">
                                <option value="" <?= ($status_filter === '') ? 'selected' : ''; ?>>All Status</option>
                                <option value="in_stock" <?= ($status_filter === 'in_stock') ? 'selected' : ''; ?>>Good Stock</option>
                                <option value="low_stock" <?= ($status_filter === 'low_stock') ? 'selected' : ''; ?>>Low Stock</option>
                                <option value="out_of_stock" <?= ($status_filter === 'out_of_stock') ? 'selected' : ''; ?>>Out of Stock</option>
                                <option value="archived" <?= ($status_filter === 'archived') ? 'selected' : ''; ?>>Archived</option>
                            </select>
                        </div>

                        <div class="col-6 col-md-2">
                            <select name="sort" class="form-select" onchange="this.form.submit()">
                                <option value="newest" <?= ($sort === 'newest') ? 'selected' : ''; ?>>Newest to Oldest</option>
                                <option value="oldest" <?= ($sort === 'oldest') ? 'selected' : ''; ?>>Oldest to Newest</option>
                                <option value="az" <?= ($sort === 'az') ? 'selected' : ''; ?>>Name: A-Z</option>
                                <option value="za" <?= ($sort === 'za') ? 'selected' : ''; ?>>Name: Z-A</option>
                                <option value="stock_asc" <?= ($sort === 'stock_asc') ? 'selected' : ''; ?>>Stock: Low to High</option>
                                <option value="stock_desc" <?= ($sort === 'stock_desc') ? 'selected' : ''; ?>>Stock: High to Low</option>
                            </select>
                        </div>

                        <div class="col-12 col-md-1 d-flex gap-1">
                            <button type="submit" class="btn btn-primary w-100 btn-sm py-2" title="Apply Filter">
                                <i class="bi bi-funnel-fill"></i>
                            </button>
                            <?php if ($search !== '' || $category_filter !== '' || $type_filter !== '' || $status_filter !== '' || $sort !== 'newest'): ?>
                                <a href="products.php" class="btn btn-outline-secondary btn-sm py-2" title="Reset Filters">
                                    <i class="bi bi-x-lg"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Unified Inventory Table -->
                <div class="table-responsive">
                    <table class="inventory-table align-middle">
                        <thead>
                            <tr>
                                <th style="width: 85px;">Item ID</th>
                                <th class="text-center" style="width: 75px;">Image</th>
                                <th style="min-width: 240px;">Product Name</th>
                                <th style="width: 110px;">Type</th>
                                <th style="width: 140px;">Category</th>
                                <th class="text-end" style="width: 120px;">Price</th>
                                <th class="text-center" style="width: 90px;">Stock</th>
                                <th class="text-center" style="width: 150px;">Status</th>
                                <th class="text-center" style="width: 120px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="inventoryTableBody">
                            <?php if (!empty($inventory_items)): ?>
                                <?php foreach ($inventory_items as $row): 
                                    $is_variant = ($row['item_type'] === 'Variant');
                                    $item_id = (int) $row['id'];
                                    $prod_id = (int) $row['product_id'];
                                    $item_stock = (int) $row['stock'];
                                    $is_archived = (int) ($row['is_archived'] ?? 0);
                                    $parent_is_archived = (int) ($row['parent_is_archived'] ?? 0);
                                    $active_vars_cnt = (int) ($row['active_variants_count'] ?? 0);
                                    $archived_vars_cnt = (int) ($row['archived_variants_count'] ?? 0);
                                    $has_active_variants = (!$is_variant && $active_vars_cnt > 0);

                                    $img_src = getProductImageSrc($row['image']);
                                    $display_name = $is_variant
                                        ? formatUnifiedVariantDisplayName((string)$row['parent_name'], (string)$row['item_name'])
                                        : trim((string)$row['item_name']);

                                    // Stock & Status display
                                    if ($is_archived === 1) {
                                        if ($is_variant && $parent_is_archived === 1) {
                                            $status_badge = '<span class="badge-status badge-archived" title="Parent main product is archived"><i class="bi bi-archive-fill"></i> Archived (Parent)</span>';
                                        } else {
                                            $status_badge = '<span class="badge-status badge-archived"><i class="bi bi-archive-fill"></i> Archived</span>';
                                        }
                                        $stock_display = $has_active_variants
                                            ? '<span class="text-muted fw-semibold">—</span>'
                                            : '<span class="fw-bold text-muted">' . $item_stock . '</span>';
                                    } elseif ($has_active_variants && $item_stock <= 0) {
                                        // Main product acting purely as variant container (stock = 0)
                                        $stock_display = '<span class="text-muted fw-bold" title="Stock tracked per variant">—</span>';
                                        $status_badge = '<span class="badge-status badge-has-variants"><i class="bi bi-layers-fill"></i> Has Variants</span>';
                                    } else {
                                        $stock_display = '<span class="fw-bold text-dark">' . $item_stock . '</span>';
                                        if ($item_stock <= 0) {
                                            $status_badge = '<span class="badge-status badge-out-of-stock"><i class="bi bi-x-circle-fill"></i> Out of Stock</span>';
                                        } elseif ($item_stock <= $low_stock_threshold) {
                                            $status_badge = '<span class="badge-status badge-low-stock"><i class="bi bi-exclamation-triangle-fill"></i> Low Stock</span>';
                                        } else {
                                            $status_badge = '<span class="badge-status badge-in-stock"><i class="bi bi-check-circle-fill"></i> Good Stock</span>';
                                        }
                                        if ($has_active_variants) {
                                            $status_badge .= '<div class="mt-1"><span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.7rem;">Has ' . $active_vars_cnt . ' Variant' . ($active_vars_cnt === 1 ? '' : 's') . '</span></div>';
                                        }
                                    }
                                ?>
                                    <tr class="<?= $is_variant ? 'variant-row' : 'main-row'; ?>" id="<?= $is_variant ? 'variant-row-' . $item_id : 'product-row-' . $item_id; ?>">
                                        <td class="text-muted font-monospace fw-semibold">
                                            #<?= $item_id; ?>
                                        </td>

                                        <td class="text-center">
                                            <img src="<?= htmlspecialchars($img_src, ENT_QUOTES, 'UTF-8'); ?>"
                                                 alt="<?= htmlspecialchars($display_name); ?>"
                                                 class="item-img"
                                                 loading="lazy"
                                                 onclick="openProductImageZoom(this.src)"
                                                 title="Click to view large image"
                                                 onerror="this.onerror=null; this.src='../assets/images/no-image.png';">
                                        </td>

                                        <td>
                                            <?php if ($is_variant): ?>
                                                <div class="d-flex align-items-center ps-3">
                                                    <i class="bi bi-arrow-return-right text-secondary me-2 flex-shrink-0"></i>
                                                    <span class="fw-semibold text-dark"><?= htmlspecialchars($display_name); ?></span>
                                                </div>
                                            <?php else: ?>
                                                <span class="fw-bold text-dark"><?= htmlspecialchars($display_name); ?></span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php if ($is_variant): ?>
                                                <span class="badge fw-semibold px-2 py-1" style="background:#f3e8ff; color:#6b21a8; border:1px solid #e9d5ff;">
                                                    <i class="bi bi-layers-half me-1"></i>Variant
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-semibold px-2 py-1">
                                                    <i class="bi bi-box me-1"></i>Main
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <span class="badge bg-light text-dark border px-2 py-1">
                                                <?= htmlspecialchars($row['category'] ?: 'Uncategorized'); ?>
                                            </span>
                                        </td>

                                        <td class="text-end fw-semibold text-dark">
                                            ₱<?= number_format((float) $row['price'], 2); ?>
                                        </td>

                                        <td class="text-center">
                                            <?= $stock_display; ?>
                                        </td>

                                        <td class="text-center">
                                            <?= $status_badge; ?>
                                        </td>

                                        <td class="text-center">
                                            <div class="d-flex align-items-center justify-content-center gap-1">
                                                <?php if ($is_archived === 0): ?>
                                                    <?php if ($is_variant): ?>
                                                        <button type="button" class="btn-icon-edit" 
                                                            onclick="openEditVariantModal(<?= htmlspecialchars(json_encode([
                                                                'id' => $item_id,
                                                                'product_id' => $prod_id,
                                                                'parent_name' => $row['parent_name'],
                                                                'name' => $row['item_name'],
                                                                'cost_price' => (float)$row['cost_price'],
                                                                'price' => (float)$row['price'],
                                                                'stock' => $item_stock,
                                                                'description' => $row['description'] ?? '',
                                                                'image_url' => $img_src
                                                            ])); ?>)" 
                                                            title="Edit Variant">
                                                            <i class="bi bi-pencil-fill"></i>
                                                        </button>

                                                        <button type="button" class="btn-icon-archive" 
                                                            onclick="openArchiveModal('variant', <?= $item_id; ?>, '<?= addslashes(htmlspecialchars($display_name)); ?>', 0)" 
                                                            title="Archive Variant">
                                                            <i class="bi bi-archive-fill"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" class="btn-icon-edit" 
                                                            onclick="openEditProductModal(<?= htmlspecialchars(json_encode([
                                                                'id' => $item_id,
                                                                'name' => $row['item_name'],
                                                                'category' => $row['category'],
                                                                'cost_price' => (float)$row['cost_price'],
                                                                'price' => (float)$row['price'],
                                                                'stock' => $item_stock,
                                                                'description' => $row['description'] ?? '',
                                                                'image_url' => $img_src
                                                            ])); ?>)" 
                                                            title="Edit Main Product">
                                                            <i class="bi bi-pencil-fill"></i>
                                                        </button>

                                                        <button type="button" class="btn-icon-archive" 
                                                            onclick="openArchiveModal('product', <?= $item_id; ?>, '<?= addslashes(htmlspecialchars($display_name)); ?>', <?= $active_vars_cnt; ?>)" 
                                                            title="Archive Main Product">
                                                            <i class="bi bi-archive-fill"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <button type="button" class="btn-icon-restore" 
                                                        onclick="openUnarchiveModal('<?= $is_variant ? 'variant' : 'product'; ?>', <?= $item_id; ?>, '<?= addslashes(htmlspecialchars($display_name)); ?>', <?= $is_variant ? 0 : $archived_vars_cnt; ?>)" 
                                                        title="Restore <?= $is_variant ? 'Variant' : 'Main Product'; ?>">
                                                        <i class="bi bi-arrow-counterclockwise"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">
                                        <i class="bi bi-box-seam display-4 d-block mb-2 text-secondary opacity-50"></i>
                                        <h5 class="fw-semibold">No products found</h5>
                                        <p class="small text-muted mb-0">Try adjusting your search or filter options to find what you are looking for.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div class="d-flex justify-content-between align-items-center mt-4 flex-wrap gap-3">
                    <div class="text-muted small">
                        <?php if ($total_items > 0): ?>
                            Showing <span class="fw-semibold text-dark"><?= $offset + 1; ?></span> to 
                            <span class="fw-semibold text-dark"><?= min($offset + $per_page, $total_items); ?></span> of 
                            <span class="fw-semibold text-dark"><?= $total_items; ?></span> items
                        <?php else: ?>
                            Showing 0 to 0 of 0 items
                        <?php endif; ?>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Inventory Pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= ($page <= 1) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?= getQueryUrl(['page' => $page - 1]); ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo; Prev</span>
                                    </a>
                                </li>

                                <?php 
                                $start_p = max(1, $page - 2);
                                $end_p = min($total_pages, $page + 2);
                                for ($i = $start_p; $i <= $end_p; $i++): 
                                ?>
                                    <li class="page-item <?= ($i === $page) ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?= getQueryUrl(['page' => $i]); ?>"><?= $i; ?></a>
                                    </li>
                                <?php endfor; ?>

                                <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?= getQueryUrl(['page' => $page + 1]); ?>" aria-label="Next">
                                        <span aria-hidden="true">Next &raquo;</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

        </div>
    </div>

    <!-- ======================================================== -->
    <!-- MODALS                                                   -->
    <!-- ======================================================== -->

    <?php if ($current_view === 'shared_stock'): ?>

        <!-- CREATE SHARED SOURCE MODAL -->
        <div class="modal fade" id="createSourceModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <form method="POST" action="products.php?view=shared_stock">
                        <input type="hidden" name="action" value="create_source">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-1"></i> Create Shared Inventory Source</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Source Name <span class="text-danger">*</span></label>
                                <input type="text" name="source_name" class="form-control" placeholder="e.g. Gravel, White Sand, Bistay" required>
                                <small class="text-muted">A distinct name representing the common physical supply pool.</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Base Unit of Measurement <span class="text-danger">*</span></label>
                                <input type="text" name="base_unit" class="form-control" placeholder="e.g. Kilograms, Sacks, Cubic Meters" required>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label fw-semibold">Initial Physical Stock</label>
                                    <input type="number" step="0.01" min="0" name="physical_stock" class="form-control" value="0.00" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-semibold">Low Stock Threshold</label>
                                    <input type="number" step="0.01" min="0" name="low_stock_threshold" class="form-control" value="50.00" required>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                                <i class="bi bi-save me-1"></i> Save Source
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- LINK VARIANT MODAL WITH LIVE SEARCH -->
        <div class="modal fade" id="linkVariantModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <form method="POST" action="products.php?view=shared_stock">
                        <input type="hidden" name="action" value="link_variant">
                        <input type="hidden" name="source_id" id="link_source_id">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title fw-bold"><i class="bi bi-link-45deg me-1"></i> Link Variant to <span id="link_source_name"></span></h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="alert alert-info py-2" style="font-size: 0.88rem;">
                                <i class="bi bi-info-circle me-1"></i> When linked, this product/variant will automatically derive its sellable quantity from this physical stock.
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Search and Select Product / Variant <span class="text-danger">*</span></label>
                                <div class="input-group input-group-sm mb-2">
                                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                                    <input type="text" id="modalItemSearch" class="form-control" placeholder="Type to filter product or variant name..." oninput="filterModalItems()">
                                </div>
                                <select name="target_item" id="target_item_select" class="form-select" size="8" required style="font-size: 0.88rem;">
                                    <option value="" disabled selected>-- Choose Item Below --</option>
                                    <?php foreach ($unlinked_items as $item): ?>
                                        <option value="<?= $item['value']; ?>" data-label="<?= strtolower(htmlspecialchars($item['label'])); ?>">
                                            <?= htmlspecialchars($item['label']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted d-block mt-1" id="filterCountNotice">Showing available unlinked items.</small>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-7">
                                    <label class="form-label fw-semibold">Conversion Requirement <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" min="0.01" name="conversion_qty" class="form-control" placeholder="e.g. 50" required>
                                    <small class="text-muted" style="font-size: 0.78rem;">Base units needed for 1 selling unit.</small>
                                </div>
                                <div class="col-5">
                                    <label class="form-label fw-semibold">Selling Unit Label</label>
                                    <input type="text" name="selling_unit_label" class="form-control" placeholder="e.g. Sack, Elf">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                                <i class="bi bi-check-lg me-1"></i> Link Item
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ADJUST PHYSICAL STOCK MODAL -->
        <div class="modal fade" id="adjustStockModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <form method="POST" action="products.php?view=shared_stock">
                        <input type="hidden" name="action" value="adjust_stock">
                        <input type="hidden" name="source_id" id="adjust_source_id">
                        <div class="modal-header bg-warning text-dark">
                            <h5 class="modal-title fw-bold"><i class="bi bi-sliders me-1"></i> Adjust Physical Stock</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="mb-3">
                                <label class="form-label text-muted small mb-1">Source Name</label>
                                <input type="text" id="adjust_source_name" class="form-control bg-light fw-bold" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Current Physical Stock</label>
                                <div class="input-group">
                                    <input type="text" id="adjust_current_stock" class="form-control bg-light" readonly>
                                    <span class="input-group-text bg-light" id="adjust_base_unit_span"></span>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">New Accurate Physical Stock <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0" name="new_physical_stock" class="form-control" required>
                                <small class="text-muted">Enter the actual count or weight physically present in storage.</small>
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-semibold">Mandatory Adjustment Reason <span class="text-danger">*</span></label>
                                <textarea name="adjustment_reason" class="form-control" rows="2" placeholder="e.g. Delivered new shipment, Physical audit discrepancy, Spillage" required></textarea>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-warning btn-sm px-3 fw-bold text-dark">
                                <i class="bi bi-check-circle me-1"></i> Apply Adjustment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- EDIT SHARED SOURCE MODAL -->
        <div class="modal fade" id="editSourceModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <form method="POST" action="products.php?view=shared_stock">
                        <input type="hidden" name="action" value="edit_source">
                        <input type="hidden" name="source_id" id="edit_source_id">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-1"></i> Edit Shared Inventory Source</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Source Name <span class="text-danger">*</span></label>
                                <input type="text" name="source_name" id="edit_source_name" class="form-control" required>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label fw-semibold">Base Unit <span class="text-danger">*</span></label>
                                    <input type="text" name="base_unit" id="edit_base_unit" class="form-control" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-semibold">Low Stock Alert</label>
                                    <input type="number" step="0.01" min="0" name="low_stock_threshold" id="edit_low_threshold" class="form-control" required>
                                </div>
                            </div>
                            <div class="form-check form-switch mt-3">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active" value="1">
                                <label class="form-check-label fw-semibold" for="edit_is_active">Source is Active</label>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                                <i class="bi bi-save me-1"></i> Update Source
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <?php else: ?>

        <!-- 1. ADD VARIANT MODAL -->
        <div class="modal fade" id="addVariantModal" tabindex="-1" aria-labelledby="addVariantModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <form method="POST" action="products.php" enctype="multipart/form-data" onsubmit="return validateAddVariantForm()">
                        <input type="hidden" name="action" value="add_variant">
                        <input type="hidden" name="product_id" id="addVar_productId">

                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title fw-bold" id="addVariantModalLabel">
                                <i class="bi bi-diagram-2-fill me-1"></i> Add New Variant
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>

                        <div class="modal-body p-4">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Main Product Selection <span class="text-danger">*</span></label>
                                <div class="dropdown w-100" id="addVar_dropdownWrapper">
                                    <button class="form-select text-start d-flex justify-content-between align-items-center" type="button" id="addVar_dropdownBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                        <span id="addVar_selectedLabel" class="text-muted">-- Select Main Product --</span>
                                    </button>
                                    <div class="dropdown-menu w-100 p-2 shadow-sm border" aria-labelledby="addVar_dropdownBtn">
                                        <div class="input-group input-group-sm mb-2">
                                            <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                                            <input type="text" id="addVar_productSearch" class="form-control" placeholder="Search main product..." oninput="filterAddVarParents()" autocomplete="off">
                                        </div>
                                        <div class="searchable-select-menu list-group list-group-flush" id="addVar_optionsList">
                                            <?php foreach ($active_main_products as $mp): ?>
                                                <button type="button" class="list-group-item list-group-item-action border-0 rounded searchable-select-option"
                                                    data-id="<?= (int)$mp['id']; ?>"
                                                    data-name="<?= htmlspecialchars($mp['product_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-search="<?= htmlspecialchars(strtolower(trim($mp['product_name'])), ENT_QUOTES, 'UTF-8'); ?>"
                                                    onclick="selectAddVarParent(<?= (int)$mp['id']; ?>, this.getAttribute('data-name'))">
                                                    <?= htmlspecialchars($mp['product_name']); ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                        <div id="addVar_noMatches" class="text-center text-muted small py-2 d-none">No matching active main products found.</div>
                                    </div>
                                </div>
                                <small class="text-muted d-block mt-1">Only active main products are listed (variants excluded).</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Variant Name <span class="text-danger">*</span></label>
                                <input type="text" name="variant_name" id="addVar_name" class="form-control" placeholder="e.g. 1 Elf, 1/2 Sack, 200cc" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Initial Stock <span class="text-danger">*</span></label>
                                <input type="number" name="stock" class="form-control" min="0" value="0" required>
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

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Category</label>
                                <input type="text" name="category" id="editProd_category" class="form-control" list="categoryOptions">
                                <datalist id="categoryOptions">
                                    <?php foreach ($categories_list as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat); ?>">
                                    <?php endforeach; ?>
                                </datalist>
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

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Available Stock <span class="text-danger">*</span></label>
                                <input type="number" name="stock" id="editVar_stock" min="0" class="form-control" required>
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
                        <input type="hidden" name="redirect_status" value="<?= htmlspecialchars($status_filter ?? ''); ?>">

                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title fw-bold">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i> Confirm Archive
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>

                        <div class="modal-body p-4">
                            <p class="mb-2">Are you sure you want to archive <span id="archive_itemType">this item</span>:</p>
                            <h5 class="fw-bold text-danger mb-3" id="archive_itemName"></h5>

                            <div id="archive_variantsWarning" class="alert alert-danger py-2 mb-3 d-none" style="font-size: 0.88rem;">
                                <div class="fw-bold mb-1">
                                    <i class="bi bi-layers-fill me-1"></i>
                                    This main product has <span id="archive_variantCount">0</span> active associated variant(s).
                                </div>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="archive_variants" id="archive_variants_checkbox" value="1" checked>
                                    <label class="form-check-label fw-semibold text-dark" for="archive_variants_checkbox">
                                        Also archive all associated variant(s) (Recommended to prevent orphaned variants)
                                    </label>
                                </div>
                            </div>

                            <div class="alert alert-warning py-2 mb-0" style="font-size: 0.88rem;">
                                <i class="bi bi-info-circle-fill me-1"></i>
                                This will hide the item from active inventory, cashier POS, and customer sales. Historical sales and order records are preserved and it will <strong>NOT</strong> permanently delete records.
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
                        <input type="hidden" name="redirect_status" value="<?= htmlspecialchars($status_filter ?? ''); ?>">

                        <div class="modal-header bg-success text-white">
                            <h5 class="modal-title fw-bold">
                                <i class="bi bi-arrow-counterclockwise me-2"></i> Confirm Restore
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>

                        <div class="modal-body p-4">
                            <p class="mb-2">Are you sure you want to restore <span id="unarchive_itemType">this item</span> to active inventory:</p>
                            <h5 class="fw-bold text-success mb-3" id="unarchive_itemName"></h5>

                            <div id="unarchive_variantsBox" class="alert alert-success py-2 mb-3 d-none" style="font-size: 0.88rem;">
                                <div class="fw-bold mb-1">
                                    <i class="bi bi-layers-fill me-1"></i>
                                    This main product has <span id="unarchive_variantCount">0</span> archived variant(s).
                                </div>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="restore_variants" id="unarchive_variants_checkbox" value="1" checked>
                                    <label class="form-check-label fw-semibold text-dark" for="unarchive_variants_checkbox">
                                        Also restore all archived variant(s) belonging to this product
                                    </label>
                                </div>
                            </div>

                            <div class="alert alert-info py-2 mb-0" style="font-size: 0.88rem;">
                                <i class="bi bi-info-circle-fill me-1"></i>
                                This item will become visible again in active inventory, POS, and online ordering.
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

    <?php endif; ?>

    <!-- IMAGE ZOOM MODAL (Available in both views) -->
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
        // Image Zoom
        function openProductImageZoom(imgSrc) {
            document.getElementById('zoomImage').src = imgSrc;
            const modal = new bootstrap.Modal(document.getElementById('imageZoomModal'));
            modal.show();
        }

        // Filter Main Products inside Add Variant Searchable Dropdown
        function filterAddVarParents() {
            const searchInput = document.getElementById('addVar_productSearch');
            const optionsList = document.getElementById('addVar_optionsList');
            const noMatches = document.getElementById('addVar_noMatches');
            if (!searchInput || !optionsList) return;

            const query = searchInput.value.toLowerCase().trim();
            let visibleCount = 0;

            optionsList.querySelectorAll('.searchable-select-option').forEach(btn => {
                const searchStr = btn.getAttribute('data-search') || btn.textContent.toLowerCase();
                if (query === '' || searchStr.includes(query)) {
                    btn.classList.remove('d-none');
                    visibleCount++;
                } else {
                    btn.classList.add('d-none');
                }
            });

            if (noMatches) {
                if (visibleCount === 0) {
                    noMatches.classList.remove('d-none');
                } else {
                    noMatches.classList.add('d-none');
                }
            }
        }

        function selectAddVarParent(prodId, prodName) {
            const idInput = document.getElementById('addVar_productId');
            const labelSpan = document.getElementById('addVar_selectedLabel');
            const optionsList = document.getElementById('addVar_optionsList');
            const dropdownBtn = document.getElementById('addVar_dropdownBtn');

            if (idInput) idInput.value = String(prodId);
            if (labelSpan) {
                labelSpan.textContent = prodName;
                labelSpan.classList.remove('text-muted');
                labelSpan.classList.add('text-dark', 'fw-semibold');
            }
            if (optionsList) {
                optionsList.querySelectorAll('.searchable-select-option').forEach(btn => {
                    btn.classList.toggle('active', btn.getAttribute('data-id') === String(prodId));
                });
            }
            if (dropdownBtn) {
                const dd = bootstrap.Dropdown.getOrCreateInstance(dropdownBtn);
                dd.hide();
            }
        }

        function validateAddVariantForm() {
            const idInput = document.getElementById('addVar_productId');
            if (!idInput || !idInput.value || parseInt(idInput.value, 10) <= 0) {
                alert('Please select a Main Product first.');
                const dropdownBtn = document.getElementById('addVar_dropdownBtn');
                if (dropdownBtn) {
                    bootstrap.Dropdown.getOrCreateInstance(dropdownBtn).show();
                }
                return false;
            }
            return true;
        }

        // Open Add Variant Modal
        function openAddVariantModal() {
            const searchInput = document.getElementById('addVar_productSearch');
            const idInput = document.getElementById('addVar_productId');
            const labelSpan = document.getElementById('addVar_selectedLabel');
            const optionsList = document.getElementById('addVar_optionsList');
            const nameInput = document.getElementById('addVar_name');

            if (searchInput) {
                searchInput.value = '';
                filterAddVarParents();
            }
            if (idInput) idInput.value = '';
            if (labelSpan) {
                labelSpan.textContent = '-- Select Main Product --';
                labelSpan.classList.add('text-muted');
                labelSpan.classList.remove('text-dark', 'fw-semibold');
            }
            if (optionsList) {
                optionsList.querySelectorAll('.searchable-select-option').forEach(btn => btn.classList.remove('active'));
            }
            if (nameInput) nameInput.value = '';

            const modal = new bootstrap.Modal(document.getElementById('addVariantModal'));
            modal.show();
        }

        // Open Edit Product Modal
        function openEditProductModal(prodData) {
            document.getElementById('editProd_id').value = prodData.id;
            document.getElementById('editProd_name').value = prodData.name;
            document.getElementById('editProd_category').value = prodData.category || '';
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
            document.getElementById('editVar_costPrice').value = parseFloat(varData.cost_price || 0).toFixed(2);
            document.getElementById('editVar_price').value = parseFloat(varData.price || 0).toFixed(2);
            document.getElementById('editVar_stock').value = parseInt(varData.stock || 0);
            document.getElementById('editVar_desc').value = varData.description || '';
            const modal = new bootstrap.Modal(document.getElementById('editVariantModal'));
            modal.show();
        }

        // Open Archive Modal
        function openArchiveModal(type, id, name, variantCount = 0) {
            document.getElementById('archive_action').value = (type === 'variant') ? 'archive_variant' : 'archive_product';
            document.getElementById('archive_targetId').value = id;
            document.getElementById('archive_itemType').textContent = (type === 'variant') ? 'variant' : 'main product';
            document.getElementById('archive_itemName').textContent = name;

            const varWarn = document.getElementById('archive_variantsWarning');
            const varCountSpan = document.getElementById('archive_variantCount');
            const varCheck = document.getElementById('archive_variants_checkbox');
            if (varWarn && varCountSpan && varCheck) {
                if (type === 'product' && parseInt(variantCount || 0) > 0) {
                    varCountSpan.textContent = variantCount;
                    varCheck.checked = true;
                    varWarn.classList.remove('d-none');
                } else {
                    varCheck.checked = false;
                    varWarn.classList.add('d-none');
                }
            }

            const modal = new bootstrap.Modal(document.getElementById('archiveConfirmModal'));
            modal.show();
        }

        // Open Unarchive Modal
        function openUnarchiveModal(type, id, name, variantCount = 0) {
            document.getElementById('unarchive_action').value = (type === 'variant') ? 'unarchive_variant' : 'unarchive_product';
            document.getElementById('unarchive_targetId').value = id;
            document.getElementById('unarchive_itemType').textContent = (type === 'variant') ? 'variant' : 'main product';
            document.getElementById('unarchive_itemName').textContent = name;

            const varBox = document.getElementById('unarchive_variantsBox');
            const varCountSpan = document.getElementById('unarchive_variantCount');
            const varCheck = document.getElementById('unarchive_variants_checkbox');
            if (varBox && varCountSpan && varCheck) {
                if (type === 'product' && parseInt(variantCount || 0) > 0) {
                    varCountSpan.textContent = variantCount;
                    varCheck.checked = true;
                    varBox.classList.remove('d-none');
                } else {
                    varCheck.checked = false;
                    varBox.classList.add('d-none');
                }
            }

            const modal = new bootstrap.Modal(document.getElementById('unarchiveConfirmModal'));
            modal.show();
        }

        // --- SHARED STOCK MODAL HANDLERS ---
        function openLinkModal(sourceId, sourceName, baseUnit) {
            document.getElementById('link_source_id').value = sourceId;
            document.getElementById('link_source_name').textContent = sourceName;
            const searchInput = document.getElementById('modalItemSearch');
            if (searchInput) {
                searchInput.value = '';
                filterModalItems();
            }
            const modal = new bootstrap.Modal(document.getElementById('linkVariantModal'));
            modal.show();
        }

        function filterModalItems() {
            const query = (document.getElementById('modalItemSearch').value || '').toLowerCase().trim();
            const select = document.getElementById('target_item_select');
            if (!select) return;

            let visibleCount = 0;
            const options = select.querySelectorAll('option');
            options.forEach(opt => {
                if (opt.value === '') return;
                const label = opt.getAttribute('data-label') || opt.textContent.toLowerCase();
                if (query === '' || label.includes(query)) {
                    opt.style.display = '';
                    visibleCount++;
                } else {
                    opt.style.display = 'none';
                }
            });

            const notice = document.getElementById('filterCountNotice');
            if (notice) {
                if (query === '') {
                    notice.textContent = "Showing available unlinked items.";
                } else {
                    notice.textContent = `Found ${visibleCount} matching item(s).`;
                }
            }
        }

        function openAdjustModal(sourceId, sourceName, currentStock, baseUnit) {
            document.getElementById('adjust_source_id').value = sourceId;
            document.getElementById('adjust_source_name').value = sourceName;
            document.getElementById('adjust_current_stock').value = currentStock.toFixed(2);
            document.getElementById('adjust_base_unit_span').textContent = baseUnit;
            const modal = new bootstrap.Modal(document.getElementById('adjustStockModal'));
            modal.show();
        }

        function openEditSourceModal(sourceId, sourceName, baseUnit, lowThreshold, isActive) {
            document.getElementById('edit_source_id').value = sourceId;
            document.getElementById('edit_source_name').value = sourceName;
            document.getElementById('edit_base_unit').value = baseUnit;
            document.getElementById('edit_low_threshold').value = lowThreshold;
            document.getElementById('edit_is_active').checked = (isActive == 1);
            const modal = new bootstrap.Modal(document.getElementById('editSourceModal'));
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
