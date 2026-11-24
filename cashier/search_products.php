<?php
session_start();
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['cashier', 'owner', 'admin'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$query = trim($_GET['q'] ?? '');

try {
    $where_main = "p.is_archived = 0";
    $where_var = "p.is_archived = 0";
    $params_main = [];
    $params_var = [];

    if ($query !== '') {
        $where_main .= " AND (LOWER(p.product_name) LIKE LOWER(?))";
        $params_main[] = "%$query%";

        $where_var .= " AND (LOWER(p.product_name) LIKE LOWER(?) OR LOWER(v.variant_name) LIKE LOWER(?))";
        $params_var[] = "%$query%";
        $params_var[] = "%$query%";
    }

    // 1. Main products without variants or standalone products
    $stmt_main = $pdo->prepare("
        SELECT 
            p.id,
            p.product_name,
            '' AS variant_name,
            p.price,
            p.cost_price,
            p.stock,
            'main' AS type
        FROM products p
        WHERE $where_main
        ORDER BY p.product_name ASC
        LIMIT 50
    ");
    $stmt_main->execute($params_main);
    $main_items = $stmt_main->fetchAll(PDO::FETCH_ASSOC);

    // 2. Product variants
    $stmt_var = $pdo->prepare("
        SELECT 
            v.id,
            p.product_name,
            v.variant_name,
            v.price,
            v.cost_price,
            v.stock,
            'variant' AS type,
            v.product_id AS parent_id
        FROM product_variants v
        JOIN products p ON v.product_id = p.id
        WHERE $where_var
        ORDER BY p.product_name ASC, v.variant_name ASC
        LIMIT 50
    ");
    $stmt_var->execute($params_var);
    $variant_items = $stmt_var->fetchAll(PDO::FETCH_ASSOC);

    $results = [];

    foreach ($main_items as $m) {
        $results[] = [
            'item_key' => 'p_' . $m['id'],
            'product_id' => (int)$m['id'],
            'variant_id' => null,
            'display_name' => $m['product_name'],
            'price' => (float)$m['price'],
            'cost_price' => (float)($m['cost_price'] ?? 0),
            'stock' => (int)$m['stock'],
            'type' => 'main'
        ];
    }

    foreach ($variant_items as $v) {
        $results[] = [
            'item_key' => 'v_' . $v['id'],
            'product_id' => (int)$v['parent_id'],
            'variant_id' => (int)$v['id'],
            'display_name' => $v['product_name'] . ' (' . $v['variant_name'] . ')',
            'price' => (float)$v['price'],
            'cost_price' => (float)($v['cost_price'] ?? 0),
            'stock' => (int)$v['stock'],
            'type' => 'variant'
        ];
    }

    // Sort by display_name
    usort($results, fn($a, $b) => strcmp($a['display_name'], $b['display_name']));

    echo json_encode(['status' => 'success', 'data' => array_slice($results, 0, 50)]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
