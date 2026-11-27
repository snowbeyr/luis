<?php
session_start();
require_once __DIR__ . '/../config/db.php';

function resolveSafeCartReturnUrl(?string $candidate, bool $withAddedNotif = false): string
{
    $raw = trim((string)($candidate ?? ''));
    if ($raw === '' && !empty($_SERVER['HTTP_REFERER'])) {
        $raw = trim((string)$_SERVER['HTTP_REFERER']);
    }

    $targetBase = 'dashboard.php';
    $safeQuery = [];

    if ($raw !== '') {
        $parts = @parse_url($raw);
        if (is_array($parts)) {
            $hostOk = empty($parts['host']) || (!empty($_SERVER['HTTP_HOST']) && strcasecmp((string)$parts['host'], (string)$_SERVER['HTTP_HOST']) === 0);
            if ($hostOk) {
                $path = strtolower(str_replace('\\', '/', (string)($parts['path'] ?? '')));
                if ($path === '/login.php' || preg_match('/(?:^|\/)login\.php$/', $path)) {
                    $targetBase = '../login.php';
                } elseif (preg_match('/(?:^|\/)dashboard\.php$/', $path)) {
                    $targetBase = 'dashboard.php';
                }

                if (!empty($parts['query'])) {
                    parse_str($parts['query'], $parsedQuery);
                    if (is_array($parsedQuery)) {
                        foreach (['view', 'category', 'sort', 'q'] as $allowedKey) {
                            if (isset($parsedQuery[$allowedKey]) && $parsedQuery[$allowedKey] !== '') {
                                $safeQuery[$allowedKey] = (string)$parsedQuery[$allowedKey];
                            }
                        }
                    }
                }
            }
        }
    }

    if ($withAddedNotif) {
        $safeQuery['notif'] = 'added';
    }

    return $targetBase . (!empty($safeQuery) ? '?' . http_build_query($safeQuery) : '');
}

$returnCandidate = $_GET['return_url'] ?? ($_POST['return_url'] ?? null);

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: " . resolveSafeCartReturnUrl($returnCandidate, false));
    exit();
}

$id = (int)$_GET['id'];
$qty = isset($_GET['qty']) ? (int)$_GET['qty'] : 1;
if ($qty < 1) $qty = 1;

$variant_id = isset($_GET['variant_id']) ? trim($_GET['variant_id']) : '';

// Kunin ang main product gamit ang PDO prepared statement
$product_stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id AND is_archived = 0");
$product_stmt->execute([':id' => $id]);
$product_row = $product_stmt->fetch(PDO::FETCH_ASSOC);

if (!$product_row) {
    header("Location: " . resolveSafeCartReturnUrl($returnCandidate, false));
    exit();
}

// Default values mula sa Main Product
$final_name = $product_row['product_name'];
$final_price = (float)$product_row['price'];
$final_stock = (int)$product_row['stock'];
$db_variant_id = null;
$db_variant_name = null;
$final_image = $product_row['image'];
$cart_key = $id;

// Kung may Variant
if (!empty($variant_id) && $variant_id !== 'MAIN_PRODUCT') {
    $variant_stmt = $pdo->prepare("SELECT * FROM product_variants WHERE id = :variant_id AND product_id = :product_id AND is_archived = 0");
    $variant_stmt->execute([
        ':variant_id' => $variant_id,
        ':product_id' => $id
    ]);
    $variant_row = $variant_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($variant_row) {
        $db_variant_id = $variant_row['id'];
        $db_variant_name = $variant_row['variant_name'];
        $final_price = (float)$variant_row['price'];
        $final_stock = (int)$variant_row['stock'];
        $final_name = $variant_row['variant_name'];
        $final_image = !empty($variant_row['variant_image']) ? $variant_row['variant_image'] : $product_row['image'];
        $cart_key = $id . "_" . $variant_id;
    }
}

// 1. I-check kung may stock
if ($final_stock <= 0) {
    $_SESSION['app_modal'] = [
        'title' => 'Out of Stock',
        'message' => 'This item is currently out of stock.',
        'type' => 'warning'
    ];
    header("Location: " . resolveSafeCartReturnUrl($returnCandidate, false));
    exit();
}

// 2. I-initialize ang session
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// 3. LOGIC PARA SA CART UPDATE (Ito ang nag-aayos ng quantity)
if (isset($_SESSION['cart'][$cart_key])) {
    // Kung nasa cart na, dagdagan ang existing quantity
    $new_qty = $_SESSION['cart'][$cart_key]['qty'] + $qty;
    $_SESSION['cart'][$cart_key]['qty'] = ($new_qty > $final_stock) ? $final_stock : $new_qty;
} else {
    $_SESSION['cart'][$cart_key] = [
        'id'           => $id,
        'name'         => $final_name,
        'variant_id'   => $db_variant_id,
        'variant_name' => $db_variant_name,
        'price'        => $final_price,
        'qty'          => ($qty > $final_stock) ? $final_stock : $qty,
        'image'        => $final_image
    ];
}

header("Location: " . resolveSafeCartReturnUrl($returnCandidate, true));
exit();
?>