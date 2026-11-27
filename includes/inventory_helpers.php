<?php
// includes/inventory_helpers.php
// Shared naming normalization and duplicate item name validation across Admin Inventory

if (!function_exists('collapseDuplicateVariantPrefix')) {
    function collapseDuplicateVariantPrefix(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
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

        if (stripos($variantClean, $parentClean) !== false) {
            return $variantClean;
        }

        $parentCore = preg_replace('/\s*\([^)]*\)/', '', $parentClean);
        $parentCore = preg_replace('/\s+[—\-]\s+.*$/u', '', $parentCore);
        $parentCore = preg_replace('/\s+\b\d+(?:\.\d+)?\s*(?:inch|mm|cm|m|ft|w|cc|g|kg|kilo|ml|l|liter|liters|sack|sacks|gallon|pail|pc|pcs)\b.*$/i', '', $parentCore);
        $parentCore = trim($parentCore);

        if ($parentCore !== '' && mb_strlen($parentCore) >= 3 && stripos($variantClean, $parentCore) !== false) {
            return $variantClean;
        }

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

if (!function_exists('checkInventoryItemNameExists')) {
    /**
     * Checks globally across both products and product_variants whether an item name already exists.
     * Returns an error message string if a duplicate exists, or null if the name is unique.
     */
    function checkInventoryItemNameExists(PDO $pdo, string $candidateName, string $itemType = 'product', int $excludeId = 0, int $parentId = 0): ?string
    {
        $clean = collapseDuplicateVariantPrefix($candidateName);
        if ($clean === '') {
            return null;
        }
        $targetLower = mb_strtolower($clean);
        $displayLower = $targetLower;

        if ($itemType === 'variant' && $parentId > 0) {
            $pStmt = $pdo->prepare("SELECT product_name FROM products WHERE id = :pid LIMIT 1");
            $pStmt->execute([':pid' => $parentId]);
            $pName = $pStmt->fetchColumn();
            if ($pName !== false && $pName !== null) {
                $displayLower = mb_strtolower(formatUnifiedVariantDisplayName((string)$pName, $clean));
            }
        }

        // 1. Check against all Main Products
        $pSql = "
            SELECT id, product_name, is_archived
            FROM products
            WHERE LOWER(TRIM(REGEXP_REPLACE(product_name, '\\s+', ' ', 'g'))) IN (:n1, :n2)
        ";
        $pParams = [':n1' => $targetLower, ':n2' => $displayLower];
        if ($itemType === 'product' && $excludeId > 0) {
            $pSql .= " AND id != :ex_id";
            $pParams[':ex_id'] = $excludeId;
        }
        $pSql .= " LIMIT 1";
        $stmtP = $pdo->prepare($pSql);
        $stmtP->execute($pParams);
        if ($dupP = $stmtP->fetch(PDO::FETCH_ASSOC)) {
            $archTag = ((int)$dupP['is_archived'] === 1) ? ' (Archived)' : '';
            return "Duplicate item name: '{$dupP['product_name']}' already exists as a Main Product{$archTag}.";
        }

        // 2. Check against all Variant Products (raw name and formatted display name)
        $vStmt = $pdo->query("
            SELECT v.id, v.product_id, v.variant_name, v.is_archived, p.product_name
            FROM product_variants v
            JOIN products p ON p.id = v.product_id
        ");
        while ($dupV = $vStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($itemType === 'variant' && $excludeId > 0 && (int)$dupV['id'] === $excludeId) {
                continue;
            }
            $vRawLower = mb_strtolower(collapseDuplicateVariantPrefix((string)$dupV['variant_name']));
            $vDispLower = mb_strtolower(formatUnifiedVariantDisplayName((string)$dupV['product_name'], (string)$dupV['variant_name']));

            if (
                $vRawLower === $targetLower
                || $vDispLower === $targetLower
                || ($itemType === 'variant' && ($vRawLower === $displayLower || $vDispLower === $displayLower))
            ) {
                $archTag = ((int)$dupV['is_archived'] === 1) ? ' (Archived)' : '';
                return "Duplicate item name: '{$dupV['variant_name']}' already exists as a Variant{$archTag}.";
            }
        }

        return null;
    }
}

if (!function_exists('getAllInventoryItemNamesForValidation')) {
    function getAllInventoryItemNamesForValidation(PDO $pdo): array
    {
        $items = [];
        try {
            $ps = $pdo->query("SELECT id, product_name FROM products");
            while ($r = $ps->fetch(PDO::FETCH_ASSOC)) {
                $norm = mb_strtolower(collapseDuplicateVariantPrefix((string)$r['product_name']));
                $items[] = [
                    'type' => 'product',
                    'id' => (int)$r['id'],
                    'name' => $norm,
                    'display_name' => $norm
                ];
            }

            $vs = $pdo->query("SELECT v.id, v.product_id, v.variant_name, p.product_name FROM product_variants v JOIN products p ON p.id = v.product_id");
            while ($r = $vs->fetch(PDO::FETCH_ASSOC)) {
                $items[] = [
                    'type' => 'variant',
                    'id' => (int)$r['id'],
                    'product_id' => (int)$r['product_id'],
                    'name' => mb_strtolower(collapseDuplicateVariantPrefix((string)$r['variant_name'])),
                    'display_name' => mb_strtolower(formatUnifiedVariantDisplayName((string)$r['product_name'], (string)$r['variant_name']))
                ];
            }
        } catch (Throwable $e) {
            error_log('getAllInventoryItemNamesForValidation error: ' . $e->getMessage());
        }
        return $items;
    }
}

if (!function_exists('formatSharedUnitLabel')) {
    /**
     * Strips redundant leading "1 " from unit labels (e.g. "1 elf" -> "elf").
     */
    function formatSharedUnitLabel(?string $rawLabel, string $fallback = 'unit'): string
    {
        $label = trim((string)($rawLabel ?? ''));
        if ($label === '') {
            return $fallback;
        }
        $stripped = trim((string)preg_replace('/^1\s+/u', '', $label));
        return $stripped !== '' ? $stripped : $fallback;
    }
}

if (!function_exists('formatSharedQuantityWithUnit')) {
    /**
     * Formats quantity + unit label cleanly without duplicated numbers (e.g. "4 elf").
     */
    function formatSharedQuantityWithUnit($qty, ?string $rawLabel, string $fallbackUnit = 'units'): string
    {
        $unit = formatSharedUnitLabel($rawLabel, $fallbackUnit);
        $numStr = is_int($qty) ? (string)$qty : rtrim(rtrim(number_format((float)$qty, 2, '.', ','), '0'), '.');
        if ($numStr === '') {
            $numStr = '0';
        }
        if (preg_match('/^\d/', $unit)) {
            return $numStr . ' × ' . $unit;
        }
        return $numStr . ' ' . $unit;
    }
}

if (!function_exists('formatSharedConversionRate')) {
    /**
     * Formats conversion rule cleanly (e.g. "1 elf = 10,000.00 kg").
     */
    function formatSharedConversionRate($conversionQty, string $baseUnit, ?string $rawLabel): string
    {
        $lhs = formatSharedQuantityWithUnit(1, $rawLabel, 'unit');
        $rhs = number_format((float)$conversionQty, 2) . ' ' . trim($baseUnit);
        return $lhs . ' = ' . $rhs;
    }
}

if (!function_exists('hydrateCheckoutItemsWithLiveDb')) {
    /**
     * Re-fetches live product/variant records, prices, units, and stock (including shared sources)
     * for checkout items. Used before Review, on Review render, and inside Final Confirmation tx.
     */
    function hydrateCheckoutItemsWithLiveDb(PDO $pdo, array $rawItems, bool $lockForUpdate = false): array
    {
        $hydrated = [];
        $issues = [];
        $priceChanges = [];
        $grandTotal = 0.0;
        $totalQty = 0;

        $sharedDemand = [];
        $sharedSourcesSeen = [];
        $lockClause = $lockForUpdate ? ' FOR UPDATE' : '';

        $prodStmt = $pdo->prepare(
            "SELECT id, product_name, price, image, stock, is_archived, unit FROM products WHERE id = :id LIMIT 1" . $lockClause
        );
        $varStmt = $pdo->prepare(
            "SELECT id, product_id, variant_name, price, stock, variant_image, unit, is_archived FROM product_variants WHERE id = :vid AND product_id = :pid LIMIT 1" . $lockClause
        );
        $srcLockStmt = $lockForUpdate
            ? $pdo->prepare("SELECT id, source_name, base_unit, physical_stock, is_active FROM shared_inventory_sources WHERE id = :sid LIMIT 1 FOR UPDATE")
            : null;

        foreach ($rawItems as $cartKey => $item) {
            $productId = (int)($item['id'] ?? 0);
            $variantId = !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
            $qty = max(1, (int)($item['qty'] ?? 1));
            $sessionPrice = isset($item['price']) ? (float)$item['price'] : 0.0;
            $fallbackName = trim((string)($item['name'] ?? 'Product'));

            if ($productId <= 0) {
                $issues[] = "Invalid product entry ('" . htmlspecialchars($fallbackName, ENT_QUOTES, 'UTF-8') . "') in cart.";
                continue;
            }

            $prodStmt->execute([':id' => $productId]);
            $prodRow = $prodStmt->fetch(PDO::FETCH_ASSOC);

            if (!$prodRow) {
                $issues[] = "'{$fallbackName}' is no longer available in the catalog.";
                continue;
            }

            $productTitle = trim((string)$prodRow['product_name']);
            $isProdArchived = ((int)($prodRow['is_archived'] ?? 0) === 1);

            $liveVariantName = null;
            $livePrice = (float)$prodRow['price'];
            $liveImage = (string)($prodRow['image'] ?? '');
            $liveUnit = trim((string)($prodRow['unit'] ?? ''));
            $liveStock = (int)($prodRow['stock'] ?? 0);
            $isItemActive = !$isProdArchived;
            $displayLabel = $productTitle;

            if ($variantId !== null) {
                $varStmt->execute([':vid' => $variantId, ':pid' => $productId]);
                $varRow = $varStmt->fetch(PDO::FETCH_ASSOC);

                if (!$varRow) {
                    $issues[] = "The selected variant for '{$productTitle}' is no longer available.";
                    $isItemActive = false;
                } else {
                    $liveVariantName = trim((string)$varRow['variant_name']);
                    $livePrice = (float)$varRow['price'];
                    if (!empty($varRow['variant_image'])) {
                        $liveImage = (string)$varRow['variant_image'];
                    }
                    $varUnit = trim((string)($varRow['unit'] ?? ''));
                    if ($varUnit !== '') {
                        $liveUnit = $varUnit;
                    }
                    $liveStock = (int)($varRow['stock'] ?? 0);
                    $isVarArchived = ((int)($varRow['is_archived'] ?? 0) === 1);
                    if ($isProdArchived || $isVarArchived) {
                        $isItemActive = false;
                    }
                    $displayLabel = formatUnifiedVariantDisplayName($productTitle, $liveVariantName);
                }
            }

            if (!$isItemActive) {
                $issues[] = "'{$displayLabel}' is currently unavailable or archived. Please remove it from your cart.";
            }

            // Shared Inventory lookup & conversion check
            $linkedSource = false;
            if (function_exists('getLinkedSourceInfo')) {
                $linkedSource = getLinkedSourceInfo($pdo, $productId, $variantId);
            }
            $conversionQty = null;
            $baseUnit = '';
            $sharedSourceId = null;

            if ($linkedSource) {
                $sharedSourceId = (int)$linkedSource['source_id'];
                if ($srcLockStmt) {
                    $srcLockStmt->execute([':sid' => $sharedSourceId]);
                    $lockedSrc = $srcLockStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$lockedSrc || (int)$lockedSrc['is_active'] !== 1) {
                        $issues[] = "Bulk inventory source for '{$displayLabel}' is currently inactive.";
                        $liveStock = 0;
                    } else {
                        $linkedSource['physical_stock'] = $lockedSrc['physical_stock'];
                    }
                }
                $conversionQty = (float)$linkedSource['conversion_qty'];
                $baseUnit = trim((string)$linkedSource['base_unit']);
                if (!empty($linkedSource['selling_unit_label'])) {
                    $liveUnit = trim((string)$linkedSource['selling_unit_label']);
                }
                $liveStock = function_exists('calculateSellableUnits')
                    ? calculateSellableUnits($linkedSource['physical_stock'], $conversionQty)
                    : 0;

                if ($conversionQty <= 0) {
                    $issues[] = "Stock conversion rule for '{$displayLabel}' is not configured properly.";
                } else {
                    if (!isset($sharedDemand[$sharedSourceId])) {
                        $sharedDemand[$sharedSourceId] = 0.0;
                        $sharedSourcesSeen[$sharedSourceId] = [
                            'source_name' => (string)$linkedSource['source_name'],
                            'base_unit' => $baseUnit,
                            'physical_stock' => (float)$linkedSource['physical_stock'],
                        ];
                    }
                    $sharedDemand[$sharedSourceId] += ($qty * $conversionQty);
                }
            }

            if ($liveUnit === '') {
                $liveUnit = 'Piece';
            }

            if ($isItemActive) {
                if ($liveStock <= 0) {
                    $issues[] = "'{$displayLabel}' is currently out of stock.";
                } elseif ($qty > $liveStock) {
                    $issues[] = "Insufficient stock for '{$displayLabel}': requested {$qty}, only {$liveStock} available.";
                }
            }

            if (abs($livePrice - $sessionPrice) > 0.009) {
                $priceChanges[] = "Price updated for '{$displayLabel}': ₱" . number_format($sessionPrice, 2) . " → ₱" . number_format($livePrice, 2);
            }

            $subtotal = round($livePrice * $qty, 2);
            $grandTotal += $subtotal;
            $totalQty += $qty;

            $hydrated[$cartKey] = [
                'cart_key' => $cartKey,
                'id' => $productId,
                'product_title' => $productTitle,
                'name' => ($variantId !== null && $liveVariantName !== null) ? $liveVariantName : $productTitle,
                'variant_id' => $variantId,
                'variant_name' => $liveVariantName,
                'display_name' => $displayLabel,
                'unit' => $liveUnit,
                'price' => $livePrice,
                'qty' => $qty,
                'subtotal' => $subtotal,
                'image' => $liveImage,
                'available_stock' => $liveStock,
                'is_active' => $isItemActive,
                'shared_source_id' => $sharedSourceId,
                'conversion_qty' => $conversionQty,
                'base_unit' => $baseUnit,
            ];
        }

        // Validate aggregate shared bulk stock across all items in the same order
        foreach ($sharedDemand as $sid => $neededBaseQty) {
            $srcInfo = $sharedSourcesSeen[$sid];
            if ($neededBaseQty > $srcInfo['physical_stock'] + 0.0001) {
                $issues[] = "Insufficient combined bulk stock for '{$srcInfo['source_name']}': your order requires "
                    . number_format($neededBaseQty, 2) . " {$srcInfo['base_unit']}, but only "
                    . number_format($srcInfo['physical_stock'], 2) . " {$srcInfo['base_unit']} is available.";
            }
        }

        return [
            'items' => $hydrated,
            'grand_total' => round($grandTotal, 2),
            'total_qty' => $totalQty,
            'issues' => array_values(array_unique($issues)),
            'price_changes' => array_values(array_unique($priceChanges)),
            'can_checkout' => empty($issues) && !empty($hydrated),
        ];
    }
}

if (!function_exists('ensureOrderTimelineColumns')) {
    /**
     * Ensures per-step status timestamp columns exist on the orders table.
     */
    function ensureOrderTimelineColumns(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        try {
            $pdo->exec("
                ALTER TABLE orders
                ADD COLUMN IF NOT EXISTS approved_at TIMESTAMP NULL,
                ADD COLUMN IF NOT EXISTS preparing_at TIMESTAMP NULL,
                ADD COLUMN IF NOT EXISTS out_for_delivery_at TIMESTAMP NULL,
                ADD COLUMN IF NOT EXISTS delivered_at TIMESTAMP NULL,
                ADD COLUMN IF NOT EXISTS completed_at TIMESTAMP NULL,
                ADD COLUMN IF NOT EXISTS cancelled_at TIMESTAMP NULL
            ");
            $checked = true;
        } catch (Throwable $e) {
            error_log('ensureOrderTimelineColumns error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('getOrderStepTimestampColumn')) {
    /**
     * Maps normalized order status to its corresponding timestamp column on orders.
     */
    function getOrderStepTimestampColumn(string $status): ?string
    {
        $st = strtolower(trim($status));
        $map = [
            'approved' => 'approved_at',
            'preparing' => 'preparing_at',
            'out_for_delivery' => 'out_for_delivery_at',
            'delivered' => 'delivered_at',
            'completed' => 'completed_at',
            'cancelled' => 'cancelled_at',
        ];
        return $map[$st] ?? null;
    }
}

if (!function_exists('formatTimelineStepDateTime')) {
    /**
     * Converts a PostgreSQL UTC timestamp string into Asia/Manila (+08:00) date + time strings.
     */
    function formatTimelineStepDateTime(?string $rawTs): ?array
    {
        $raw = trim((string)($rawTs ?? ''));
        if ($raw === '') {
            return null;
        }
        try {
            $utcTz = new DateTimeZone('UTC');
            $manilaTz = new DateTimeZone('Asia/Manila');
            // Respect explicit timezone offsets if present; otherwise treat naive PG timestamps as UTC
            $hasExplicitOffset = (bool)preg_match('/(?:Z|[+-]\d{2}(?::?\d{2})?)$/i', $raw);
            $dt = $hasExplicitOffset
                ? new DateTimeImmutable($raw)
                : new DateTimeImmutable($raw, $utcTz);
            $manilaDt = $dt->setTimezone($manilaTz);
            return [
                'raw' => $manilaDt->format('Y-m-d H:i:s'),
                'date' => $manilaDt->format('M d, Y'),
                'time' => $manilaDt->format('h:i A'),
                'full' => $manilaDt->format('M d, Y h:i A'),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('hydrateOrderTimelineTimestamps')) {
    /**
     * Populates formatted timeline timestamps for Placed, Approved, Preparing, Out for Delivery,
     * Delivered, and Completed steps using order columns with audit_log fallback for historical orders.
     */
    function hydrateOrderTimelineTimestamps(PDO $pdo, array &$orders): void
    {
        if (empty($orders)) {
            return;
        }

        ensureOrderTimelineColumns($pdo);

        // Fetch relevant audit_log entries once for historical backfill where columns are still NULL
        $auditMap = [];
        try {
            $orderIds = array_map(fn($o) => (int)$o['id'], $orders);
            $idLookup = array_flip($orderIds);

            $stmt = $pdo->query("
                SELECT action, details, \"timestamp\"
                FROM audit_log
                WHERE action IN ('Order Approved', 'Order Preparing', 'Order Out for Delivery', 'Order Delivered', 'Order Completed', 'Order Received')
                ORDER BY id ASC
            ");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (preg_match('/Order\s+#(\d+)/i', (string)($row['details'] ?? ''), $m)) {
                    $oid = (int)$m[1];
                    if (!isset($idLookup[$oid])) {
                        continue;
                    }
                    $act = strtolower(trim((string)$row['action']));
                    $stepKey = null;
                    if ($act === 'order approved') {
                        $stepKey = 'confirmed';
                    } elseif ($act === 'order preparing') {
                        $stepKey = 'preparing';
                    } elseif ($act === 'order out for delivery') {
                        $stepKey = 'shipping';
                    } elseif ($act === 'order delivered') {
                        $stepKey = 'delivered';
                    } elseif ($act === 'order completed' || $act === 'order received') {
                        $stepKey = 'completed';
                    }
                    if ($stepKey !== null && empty($auditMap[$oid][$stepKey])) {
                        $auditMap[$oid][$stepKey] = (string)$row['timestamp'];
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('hydrateOrderTimelineTimestamps audit fallback error: ' . $e->getMessage());
        }

        foreach ($orders as &$order) {
            $oid = (int)$order['id'];
            $status = strtolower(trim((string)($order['status'] ?? 'pending')));

            $placedRaw = !empty($order['order_date']) ? (string)$order['order_date'] : null;
            $confirmedRaw = !empty($order['approved_at']) ? (string)$order['approved_at'] : ($auditMap[$oid]['confirmed'] ?? null);
            $preparingRaw = !empty($order['preparing_at']) ? (string)$order['preparing_at'] : ($auditMap[$oid]['preparing'] ?? null);
            $shippingRaw = !empty($order['out_for_delivery_at']) ? (string)$order['out_for_delivery_at'] : ($auditMap[$oid]['shipping'] ?? null);
            $deliveredRaw = !empty($order['delivered_at']) ? (string)$order['delivered_at'] : ($auditMap[$oid]['delivered'] ?? null);
            $completedRaw = !empty($order['completed_at']) ? (string)$order['completed_at'] : ($auditMap[$oid]['completed'] ?? null);
            $cancelledRaw = !empty($order['cancelled_at']) ? (string)$order['cancelled_at'] : null;

            // Auto-completed orders before completed_at column existed: fallback to delivered_at + 72h if no audit row
            if ($status === 'completed' && empty($completedRaw) && !empty($deliveredRaw)) {
                $delInfo = formatTimelineStepDateTime($deliveredRaw);
                if ($delInfo !== null) {
                    try {
                        $manilaTz = new DateTimeZone('Asia/Manila');
                        $compDt = (new DateTimeImmutable($delInfo['raw'], $manilaTz))->modify('+72 hours');
                        $completedRaw = $compDt->format('Y-m-d H:i:sP');
                    } catch (Throwable $e) {}
                }
            }

            $placedInfo = formatTimelineStepDateTime($placedRaw);
            $order['order_date_display'] = $placedInfo ? $placedInfo['full'] : ($placedRaw ?? '');
            $order['timeline'] = [
                'placed' => $placedInfo,
                'confirmed' => formatTimelineStepDateTime($confirmedRaw),
                'preparing' => formatTimelineStepDateTime($preparingRaw),
                'shipping' => formatTimelineStepDateTime($shippingRaw),
                'delivered' => formatTimelineStepDateTime($deliveredRaw),
                'completed' => formatTimelineStepDateTime($completedRaw),
                'cancelled' => formatTimelineStepDateTime($cancelledRaw),
            ];
        }
        unset($order);
    }
}

if (!function_exists('ensureCancellationSchema')) {
    /**
     * Ensures cancellation metadata, refund status tracking, idempotent inventory restoration flags,
     * and the in-app notifications table exist in PostgreSQL.
     */
    function ensureCancellationSchema(PDO $pdo): void
    {
        static $schemaReady = false;
        if ($schemaReady) {
            return;
        }

        ensureOrderTimelineColumns($pdo);

        try {
            $pdo->exec("
                ALTER TABLE orders
                ADD COLUMN IF NOT EXISTS cancellation_reason TEXT NULL,
                ADD COLUMN IF NOT EXISTS cancellation_mode VARCHAR(30) NULL,
                ADD COLUMN IF NOT EXISTS inventory_restored SMALLINT DEFAULT 0,
                ADD COLUMN IF NOT EXISTS refund_status VARCHAR(40) NULL
            ");
            $pdo->exec("
                ALTER TABLE customer_requests
                ADD COLUMN IF NOT EXISTS customer_id INTEGER NULL,
                ADD COLUMN IF NOT EXISTS cancellation_mode VARCHAR(30) NULL,
                ADD COLUMN IF NOT EXISTS processed_by INTEGER NULL,
                ADD COLUMN IF NOT EXISTS processed_at TIMESTAMP NULL,
                ADD COLUMN IF NOT EXISTS auto_approved SMALLINT DEFAULT 0
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS notifications (
                    id SERIAL PRIMARY KEY,
                    recipient_role VARCHAR(30) NOT NULL,
                    recipient_email VARCHAR(255) NULL,
                    order_id INTEGER NULL,
                    request_id INTEGER NULL,
                    title VARCHAR(255) NOT NULL,
                    message TEXT NOT NULL,
                    type VARCHAR(30) DEFAULT 'info',
                    is_read SMALLINT DEFAULT 0,
                    unique_key VARCHAR(150) UNIQUE NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $schemaReady = true;
        } catch (Throwable $e) {
            error_log('ensureCancellationSchema error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('logSystemAudit')) {
    /**
     * Inserts an entry into audit_log using PDO inside a SAVEPOINT when in an active
     * PostgreSQL transaction so auxiliary logging never aborts the caller transaction.
     */
    function logSystemAudit(PDO $pdo, ?int $userId, string $username, string $role, string $action, string $details): void
    {
        $inTxn = $pdo->inTransaction();
        $spName = 'sp_audit_' . mt_rand(1000, 999999);
        try {
            if ($inTxn) {
                $pdo->exec("SAVEPOINT {$spName}");
            }
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $stmt = $pdo->prepare("
                INSERT INTO audit_log (user_id, username, role, action, details, ip_address, \"timestamp\")
                VALUES (:uid, :uname, :role, :action, :details, :ip, NOW())
            ");
            $stmt->execute([
                ':uid' => ($userId !== null && $userId > 0) ? $userId : 0,
                ':uname' => $username !== '' ? $username : 'System',
                ':role' => $role !== '' ? $role : 'system',
                ':action' => $action,
                ':details' => $details,
                ':ip' => $ip,
            ]);
            if ($inTxn) {
                $pdo->exec("RELEASE SAVEPOINT {$spName}");
            }
        } catch (Throwable $e) {
            if ($inTxn) {
                try {
                    $pdo->exec("ROLLBACK TO SAVEPOINT {$spName}");
                } catch (Throwable $ignored) {}
            }
            error_log('logSystemAudit error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('createSystemNotification')) {
    /**
     * Persists an idempotent in-app notification record (duplicates prevented via unique_key).
     */
    function createSystemNotification(PDO $pdo, array $data): bool
    {
        ensureCancellationSchema($pdo);
        $role = trim((string)($data['recipient_role'] ?? 'customer'));
        $email = isset($data['recipient_email']) && $data['recipient_email'] !== '' ? trim((string)$data['recipient_email']) : null;
        $orderId = !empty($data['order_id']) ? (int)$data['order_id'] : null;
        $requestId = !empty($data['request_id']) ? (int)$data['request_id'] : null;
        $title = trim((string)($data['title'] ?? 'Order Update'));
        $message = trim((string)($data['message'] ?? ''));
        $type = trim((string)($data['type'] ?? 'info'));
        $uniqueKey = !empty($data['unique_key']) ? trim((string)$data['unique_key']) : null;

        if ($message === '') {
            return false;
        }

        $inTxn = $pdo->inTransaction();
        $spName = 'sp_notif_' . mt_rand(1000, 999999);
        try {
            if ($inTxn) {
                $pdo->exec("SAVEPOINT {$spName}");
            }
            $stmt = $pdo->prepare("
                INSERT INTO notifications (recipient_role, recipient_email, order_id, request_id, title, message, type, unique_key)
                VALUES (:role, :email, :oid, :rid, :title, :msg, :type, :ukey)
                ON CONFLICT (unique_key) DO NOTHING
            ");
            $stmt->execute([
                ':role' => $role,
                ':email' => $email,
                ':oid' => $orderId,
                ':rid' => $requestId,
                ':title' => $title,
                ':msg' => $message,
                ':type' => $type,
                ':ukey' => $uniqueKey,
            ]);
            $inserted = $stmt->rowCount() > 0;
            if ($inTxn) {
                $pdo->exec("RELEASE SAVEPOINT {$spName}");
            }
            return $inserted;
        } catch (Throwable $e) {
            if ($inTxn) {
                try {
                    $pdo->exec("ROLLBACK TO SAVEPOINT {$spName}");
                } catch (Throwable $ignored) {}
            }
            error_log('createSystemNotification error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('normalizeCancellationOrderStatus')) {
    /**
     * Normalizes order status labels so variations like "Order Placed", "pending", "out for delivery" compare reliably.
     */
    function normalizeCancellationOrderStatus(?string $status): string
    {
        $raw = strtolower(trim((string)$status));
        $raw = preg_replace('/[\s\-]+/', '_', $raw);
        if ($raw === 'order_placed' || $raw === 'placed') {
            return 'pending';
        }
        if ($raw === 'canceled') {
            return 'cancelled';
        }
        return $raw;
    }
}

if (!function_exists('restoreOrderInventorySafely')) {
    /**
     * Restores/releases inventory once for a cancelled or declined order inside a row-locked transaction.
     * Prevents duplicate restoration across shared stock sources, product variants, and main products.
     */
    function restoreOrderInventorySafely(PDO $pdo, int $orderId, string $actorName = 'System', string $movementLabel = 'Online Order Cancellation Restoration'): array
    {
        ensureCancellationSchema($pdo);
        require_once __DIR__ . '/shared_inventory_functions.php';

        $startedTxn = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTxn = true;
        }

        try {
            $stmtOrd = $pdo->prepare("SELECT id, status, cart, inventory_restored FROM orders WHERE id = ? FOR UPDATE");
            $stmtOrd->execute([$orderId]);
            $orderRow = $stmtOrd->fetch(PDO::FETCH_ASSOC);

            if (!$orderRow) {
                if ($startedTxn) {
                    $pdo->rollBack();
                }
                return ['restored' => false, 'already_restored' => false, 'items_restored' => 0, 'error' => 'Order not found'];
            }

            if ((int)($orderRow['inventory_restored'] ?? 0) === 1) {
                if ($startedTxn) {
                    $pdo->commit();
                }
                return ['restored' => false, 'already_restored' => true, 'items_restored' => 0, 'error' => null];
            }

            $stmtItems = $pdo->prepare("SELECT product_id, variant_id, qty, product_name FROM order_items WHERE order_id = ? ORDER BY id ASC");
            $stmtItems->execute([$orderId]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            if (empty($items) && !empty($orderRow['cart'])) {
                $decodedCart = json_decode((string)$orderRow['cart'], true);
                if (is_array($decodedCart)) {
                    foreach ($decodedCart as $cItem) {
                        $items[] = [
                            'product_id' => $cItem['id'] ?? ($cItem['product_id'] ?? 0),
                            'variant_id' => $cItem['variant_id'] ?? null,
                            'qty' => $cItem['qty'] ?? 0,
                            'product_name' => $cItem['name'] ?? ($cItem['product_name'] ?? 'Item'),
                        ];
                    }
                }
            }

            $itemsRestored = 0;
            $seenKeys = [];

            foreach ($items as $item) {
                $prodId = (int)($item['product_id'] ?? 0);
                $varId = !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
                $qty = (int)($item['qty'] ?? 0);

                if ($prodId <= 0 || $qty <= 0) {
                    continue;
                }

                $linkedSource = getLinkedSourceInfo($pdo, $prodId, $varId);
                if ($linkedSource) {
                    $itemKey = $varId ? "v_{$varId}" : "p_{$prodId}";
                    $seq = ($seenKeys[$itemKey] ?? 0) + 1;
                    $seenKeys[$itemKey] = $seq;
                    $suffix = $seq > 1 ? "_s{$seq}" : "";

                    // Guard against historical partial restorations logged prior to inventory_restored column
                    $stmtHist = $pdo->prepare("
                        SELECT id
                        FROM shared_inventory_movements
                        WHERE reference_id = ?
                          AND source_id = ?
                          AND qty_in_base_unit > 0
                          AND (
                                LOWER(movement_type) LIKE '%cancel%'
                             OR LOWER(movement_type) LIKE '%declin%'
                             OR LOWER(movement_type) LIKE '%restor%'
                          )
                          AND (
                                (?::INTEGER IS NOT NULL AND variant_id = ?::INTEGER)
                             OR (?::INTEGER IS NULL AND product_id = ?::INTEGER AND (variant_id IS NULL OR variant_id = 0))
                          )
                        LIMIT 1
                    ");
                    $stmtHist->execute([
                        (string)$orderId,
                        (int)$linkedSource['source_id'],
                        $varId, $varId,
                        $varId, $prodId,
                    ]);

                    if (!$stmtHist->fetchColumn()) {
                        $idempKey = "cancel_restore_{$orderId}_{$itemKey}{$suffix}";
                        list($resOk, $resMsg) = restoreSharedStock(
                            $pdo,
                            (int)$linkedSource['source_id'],
                            $prodId,
                            $varId,
                            $qty,
                            $movementLabel,
                            $orderId,
                            $actorName,
                            "Inventory restored for Order #{$orderId} ({$linkedSource['source_name']})",
                            $idempKey
                        );
                        if (!$resOk) {
                            throw new RuntimeException("Shared stock restore failed for Order #{$orderId}: {$resMsg}");
                        }
                        $itemsRestored++;
                    }
                } else {
                    if ($varId !== null) {
                        $lk = $pdo->prepare("SELECT id FROM product_variants WHERE id = ? FOR UPDATE");
                        $lk->execute([$varId]);
                        if ($lk->fetchColumn()) {
                            $up = $pdo->prepare("UPDATE product_variants SET stock = stock + ? WHERE id = ?");
                            $up->execute([$qty, $varId]);
                            $itemsRestored++;
                        }
                    } else {
                        $lk = $pdo->prepare("SELECT id FROM products WHERE id = ? FOR UPDATE");
                        $lk->execute([$prodId]);
                        if ($lk->fetchColumn()) {
                            $up = $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                            $up->execute([$qty, $prodId]);
                            $itemsRestored++;
                        }
                    }
                }
            }

            // Release any active shared_inventory_reservations tied to this order
            $relRes = $pdo->prepare("
                UPDATE shared_inventory_reservations
                SET status = 'RELEASED', updated_at = NOW()
                WHERE order_id = ? AND UPPER(status) = 'RESERVED'
            ");
            $relRes->execute([$orderId]);

            $markOrd = $pdo->prepare("UPDATE orders SET inventory_restored = 1 WHERE id = ?");
            $markOrd->execute([$orderId]);

            if ($startedTxn) {
                $pdo->commit();
            }

            return [
                'restored' => true,
                'already_restored' => false,
                'items_restored' => $itemsRestored,
                'error' => null,
            ];
        } catch (Throwable $e) {
            if ($startedTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('determineOrderPaymentRefundStatus')) {
    /**
     * Checks whether payment was collected for an order so refund status is tracked separately
     * without auto-marking a cancelled order as Refunded.
     */
    function determineOrderPaymentRefundStatus(array $orderRow): array
    {
        $method = strtoupper(trim((string)($orderRow['payment_method'] ?? 'COD')));
        $scheme = strtolower(trim((string)($orderRow['payment_scheme'] ?? '')));
        $paidAmount = (float)($orderRow['paid_amount'] ?? 0);
        $hasProof = !empty(trim((string)($orderRow['proof_of_payment'] ?? ''))) || !empty(trim((string)($orderRow['ref_no'] ?? '')));
        $existingRefund = trim((string)($orderRow['refund_status'] ?? ''));

        $isPaidOnline = in_array($method, ['GCASH', 'MAYA'], true)
            || $paidAmount > 0
            || $hasProof
            || str_contains($scheme, '50')
            || str_contains($scheme, 'down');

        if ($existingRefund === 'Refunded') {
            return ['requires_refund' => $isPaidOnline, 'refund_status' => 'Refunded'];
        }

        if ($isPaidOnline) {
            return ['requires_refund' => true, 'refund_status' => 'Pending Refund'];
        }

        return ['requires_refund' => false, 'refund_status' => 'Not Applicable'];
    }
}

if (!function_exists('finalizeOrderCancellation')) {
    /**
     * Shared atomic order cancellation transition used by both Automatic Cancellation (Placed/Pending/Approved)
     * and Admin-Approved Cancellation (Preparing). Handles locking, inventory restoration, payment preservation,
     * timestamps, audit logs, and duplicate-safe notifications.
     */
    function finalizeOrderCancellation(PDO $pdo, int $orderId, array $opts = []): array
    {
        ensureCancellationSchema($pdo);

        $mode = ($opts['mode'] ?? 'automatic') === 'manual' ? 'manual' : 'automatic';
        $requestId = !empty($opts['request_id']) ? (int)$opts['request_id'] : null;
        $adminId = !empty($opts['admin_id']) ? (int)$opts['admin_id'] : null;
        $actorName = trim((string)($opts['actor_name'] ?? ($mode === 'automatic' ? 'System (Auto-Cancel)' : 'Admin')));
        $actorRole = trim((string)($opts['actor_role'] ?? ($mode === 'automatic' ? 'system' : 'admin')));
        $reason = trim((string)($opts['reason'] ?? 'Customer Cancellation Request'));

        $startedTxn = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTxn = true;
        }

        try {
            $stmtOrd = $pdo->prepare("SELECT * FROM orders WHERE id = ? FOR UPDATE");
            $stmtOrd->execute([$orderId]);
            $orderRow = $stmtOrd->fetch(PDO::FETCH_ASSOC);

            if (!$orderRow) {
                throw new RuntimeException("Order #{$orderId} not found.");
            }

            $prevStatus = normalizeCancellationOrderStatus($orderRow['status'] ?? '');
            $payInfo = determineOrderPaymentRefundStatus($orderRow);

            $restoreResult = restoreOrderInventorySafely(
                $pdo,
                $orderId,
                $actorName,
                $mode === 'automatic' ? 'Automatic Order Cancellation' : 'Admin-Approved Cancellation'
            );

            $upOrd = $pdo->prepare("
                UPDATE orders
                SET status = 'cancelled',
                    sent_to_cashier = 0,
                    cancelled_at = COALESCE(cancelled_at, NOW()),
                    cancelled_by = COALESCE(:cancelled_by, cancelled_by),
                    cancellation_reason = CASE
                        WHEN COALESCE(TRIM(cancellation_reason), '') = '' THEN :reason
                        ELSE cancellation_reason
                    END,
                    cancellation_mode = COALESCE(:cmode, cancellation_mode),
                    refund_status = CASE
                        WHEN refund_status = 'Refunded' THEN 'Refunded'
                        ELSE :ref_status
                    END
                WHERE id = :oid
            ");
            $upOrd->execute([
                ':cancelled_by' => $adminId,
                ':reason' => $reason,
                ':cmode' => $mode,
                ':ref_status' => $payInfo['refund_status'],
                ':oid' => $orderId,
            ]);

            $reqFinalStatus = !empty($payInfo['requires_refund']) ? 'Approved' : 'Completed';

            if ($requestId !== null) {
                $autoFlag = $mode === 'automatic' ? 1 : 0;
                $upReq = $pdo->prepare("
                    UPDATE customer_requests
                    SET status = :req_status,
                        cancellation_mode = :cmode,
                        auto_approved = :auto_flag,
                        processed_by = :pby,
                        processed_at = COALESCE(processed_at, NOW()),
                        refund_status = CASE
                            WHEN refund_status = 'Refunded' THEN 'Refunded'
                            ELSE :ref_status
                        END
                    WHERE id = :rid
                ");
                $upReq->execute([
                    ':req_status' => $reqFinalStatus,
                    ':cmode' => $mode,
                    ':auto_flag' => $autoFlag,
                    ':pby' => $adminId,
                    ':ref_status' => $payInfo['refund_status'],
                    ':rid' => $requestId,
                ]);
            }

            $auditAction = $mode === 'automatic' ? 'Order Auto-Cancelled' : 'Cancellation Approved';
            $auditDetails = ($mode === 'automatic'
                ? "Automatically cancelled Order #{$orderId} from status '" . strtoupper($prevStatus) . "'"
                : "Approved cancellation request" . ($requestId ? " #{$requestId}" : "") . " for Order #{$orderId} from status '" . strtoupper($prevStatus) . "'")
                . ". Reason: {$reason}";
            logSystemAudit($pdo, $adminId, $actorName, $actorRole, $auditAction, $auditDetails);

            $custEmail = trim((string)($orderRow['email'] ?? ''));
            $custMsg = $mode === 'automatic'
                ? "Your cancellation request for Order #{$orderId} has been automatically approved because your order has not yet entered the preparation stage. Your order is now cancelled."
                : "Your cancellation request for Order #{$orderId} has been approved. Your order is now cancelled.";

            if ($custEmail !== '') {
                createSystemNotification($pdo, [
                    'recipient_role' => 'customer',
                    'recipient_email' => $custEmail,
                    'order_id' => $orderId,
                    'request_id' => $requestId,
                    'title' => "Order #{$orderId} Cancelled",
                    'message' => $custMsg,
                    'type' => 'info',
                    'unique_key' => "cancel_cust_{$orderId}_" . ($requestId ?? 0),
                ]);
            }

            if ($startedTxn) {
                $pdo->commit();
            }

            return [
                'order_id' => $orderId,
                'previous_status' => $prevStatus,
                'mode' => $mode,
                'request_status' => $reqFinalStatus,
                'requires_refund' => $payInfo['requires_refund'],
                'refund_status' => $payInfo['refund_status'],
                'inventory_restored' => $restoreResult,
            ];
        } catch (Throwable $e) {
            if ($startedTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('processCustomerTicketAction')) {
    /**
     * Unified transactional handler for Admin approval, decline, or completion of customer tickets
     * (Cancellation Requests and Exchanges/Returns). Supports nested transactions cleanly.
     */
    function processCustomerTicketAction(PDO $pdo, int $reqId, string $status, string $declineReason, ?int $adminId, string $adminName, bool $sendEmail = true): array
    {
        ensureCancellationSchema($pdo);

        $status = trim($status);
        $declineReason = trim($declineReason);

        if (!in_array($status, ['Approved', 'Declined', 'Completed'], true)) {
            return ['success' => false, 'message' => '❌ Invalid ticket action requested.', 'pane' => '#cancel-pane'];
        }

        $paneTarget = '#exchange-pane';
        $notifyPayload = null;
        $startedTxn = false;

        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTxn = true;
            }

            $stmtLock = $pdo->prepare("
                SELECT cr.*, o.id AS ord_id, o.status AS ord_status, o.cart, o.payment_method, o.payment_scheme, o.paid_amount, o.email, o.fullname, o.surname
                FROM customer_requests cr
                JOIN orders o ON CAST(cr.order_number AS INTEGER) = o.id
                WHERE cr.id = ?
                FOR UPDATE OF cr, o
            ");
            $stmtLock->execute([$reqId]);
            $ticket = $stmtLock->fetch(PDO::FETCH_ASSOC);

            if (!$ticket) {
                if ($startedTxn && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return ['success' => false, 'message' => '❌ Ticket or linked order not found.', 'pane' => '#cancel-pane'];
            }

            $requestType = strtolower(trim((string)($ticket['request_type'] ?? '')));
            $paneTarget = ($requestType === 'cancel') ? '#cancel-pane' : '#exchange-pane';
            $orderId = (int)$ticket['ord_id'];
            $curReqStatus = trim((string)($ticket['status'] ?? 'Pending'));

            if ($requestType === 'cancel') {
                if (in_array($status, ['Approved', 'Declined'], true) && strcasecmp($curReqStatus, 'Pending') !== 0) {
                    if ($startedTxn && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    return [
                        'success' => false,
                        'message' => "⚠️ Cancellation ticket #{$reqId} has already been processed (Status: {$curReqStatus}).",
                        'pane' => $paneTarget,
                    ];
                }

                if ($status === 'Declined' && $declineReason === '') {
                    if ($startedTxn && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    return [
                        'success' => false,
                        'message' => '❌ A decline reason is required when declining a cancellation request.',
                        'pane' => $paneTarget,
                    ];
                }

                if ($status === 'Approved') {
                    $cancelReason = trim((string)($ticket['reason'] ?? 'Customer Cancellation Request'));
                    if (!empty($ticket['details'])) {
                        $cancelReason .= ' (' . trim((string)$ticket['details']) . ')';
                    }

                    $cancelMeta = finalizeOrderCancellation($pdo, $orderId, [
                        'mode' => 'manual',
                        'request_id' => $reqId,
                        'admin_id' => $adminId,
                        'actor_name' => $adminName,
                        'actor_role' => 'admin',
                        'reason' => $cancelReason,
                    ]);

                    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE recipient_role = 'admin' AND request_id = ?")
                        ->execute([$reqId]);

                    $custFull = trim(($ticket['fullname'] ?? '') . ' ' . ($ticket['surname'] ?? '')) ?: 'Customer';
                    $refundNote = !empty($cancelMeta['requires_refund'])
                        ? "<p style='background:#fff3cd;padding:10px;border-radius:5px;color:#856404;'><strong>Payment Note:</strong> Your e-wallet payment record has been marked as <strong>Pending Refund</strong>. Our team will upload your refund receipt separately.</p>"
                        : '';
                    $notifyPayload = [
                        'email' => $ticket['email'] ?? '',
                        'name' => $custFull,
                        'subject' => "Cancellation Approved: Order #{$orderId}",
                        'body' => "
                            <h3>Hi " . htmlspecialchars($custFull) . ",</h3>
                            <p>Your cancellation request for Order <strong>#{$orderId}</strong> has been <strong>approved</strong> by our team.</p>
                            <p>Your order is now <strong>CANCELLED</strong>.</p>
                            {$refundNote}
                            <br>
                            <h4>Best regards,<br>R. Taipan Construction Supply and Services, OPC</h4>
                        ",
                        'email_status' => $cancelMeta['request_status'] ?? 'Approved',
                    ];
                } elseif ($status === 'Declined') {
                    $upReq = $pdo->prepare("
                        UPDATE customer_requests
                        SET status = 'Declined',
                            admin_notes = ?,
                            cancellation_mode = COALESCE(cancellation_mode, 'manual'),
                            processed_by = ?,
                            processed_at = NOW()
                        WHERE id = ?
                    ");
                    $upReq->execute([$declineReason, $adminId, $reqId]);

                    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE recipient_role = 'admin' AND request_id = ?")
                        ->execute([$reqId]);

                    logSystemAudit(
                        $pdo,
                        $adminId,
                        $adminName,
                        'admin',
                        'Cancellation Declined',
                        "Declined cancellation request #{$reqId} for Order #{$orderId}. Reason: {$declineReason}"
                    );

                    $custEmail = trim((string)($ticket['email'] ?? ''));
                    if ($custEmail !== '') {
                        createSystemNotification($pdo, [
                            'recipient_role' => 'customer',
                            'recipient_email' => $custEmail,
                            'order_id' => $orderId,
                            'request_id' => $reqId,
                            'title' => "Cancellation Declined (#{$orderId})",
                            'message' => "Your cancellation request for Order #{$orderId} was declined. Reason: {$declineReason}",
                            'type' => 'warning',
                            'unique_key' => "cancel_declined_{$reqId}",
                        ]);
                    }

                    $custFull = trim(($ticket['fullname'] ?? '') . ' ' . ($ticket['surname'] ?? '')) ?: 'Customer';
                    $notifyPayload = [
                        'email' => $ticket['email'] ?? '',
                        'name' => $custFull,
                        'subject' => "Cancellation Request Update: Order #{$orderId}",
                        'body' => "
                            <h3>Hi " . htmlspecialchars($custFull) . ",</h3>
                            <p>Your cancellation request for Order <strong>#{$orderId}</strong> has been <strong>declined</strong>.</p>
                            <div style='background:#f8d7da;padding:12px;border-left:4px solid #dc3545;margin:12px 0;'>
                                <strong>Reason provided by Admin:</strong><br>" . nl2br(htmlspecialchars($declineReason)) . "
                            </div>
                            <p>Your order remains in <strong>" . strtoupper((string)$ticket['ord_status']) . "</strong> status and processing will continue.</p>
                            <br>
                            <h4>Best regards,<br>R. Taipan Construction Supply and Services, OPC</h4>
                        ",
                        'email_status' => 'Declined',
                    ];
                } elseif ($status === 'Completed') {
                    if (normalizeCancellationOrderStatus($ticket['ord_status'] ?? '') !== 'cancelled') {
                        finalizeOrderCancellation($pdo, $orderId, [
                            'mode' => 'manual',
                            'request_id' => $reqId,
                            'admin_id' => $adminId,
                            'actor_name' => $adminName,
                            'actor_role' => 'admin',
                            'reason' => trim((string)($ticket['reason'] ?? 'Customer Cancellation Request')),
                        ]);
                    }
                    $pdo->prepare("
                        UPDATE customer_requests
                        SET status = 'Completed',
                            email_status = 'Completed',
                            processed_by = COALESCE(processed_by, ?),
                            processed_at = COALESCE(processed_at, NOW())
                        WHERE id = ?
                    ")->execute([$adminId, $reqId]);
                }
            } else {
                $sqlEx = "UPDATE customer_requests SET status = ?, processed_by = COALESCE(processed_by, ?), processed_at = COALESCE(processed_at, NOW())"
                    . ($status === 'Completed' ? ", email_status = 'Completed'" : "")
                    . ($status === 'Declined' && $declineReason !== '' ? ", admin_notes = ?" : "")
                    . " WHERE id = ?";
                $paramsEx = ($status === 'Declined' && $declineReason !== '')
                    ? [$status, $adminId, $declineReason, $reqId]
                    : [$status, $adminId, $reqId];
                $pdo->prepare($sqlEx)->execute($paramsEx);

                if (in_array($status, ['Approved', 'Completed'], true) && strpos(strtolower((string)($ticket['reason'] ?? '')), 'damaged') !== false) {
                    $checkDmg = $pdo->prepare("SELECT id FROM damaged_items WHERE order_number = ? AND account = ? LIMIT 1");
                    $checkDmg->execute([(string)$orderId, $ticket['account']]);
                    if ($checkDmg->rowCount() == 0) {
                        $itemDetails = !empty($ticket['item_to_replace']) ? $ticket['item_to_replace'] : $ticket['reason'];
                        $itemDetails = (string)preg_replace('/\s*[—\-]+\s*Exchange Credit:[^\r\n]*/iu', ' — Same-Item Replacement', (string)$itemDetails);
                        if (!empty($ticket['details'])) {
                            $itemDetails .= "\nDetails: " . $ticket['details'];
                        }
                        $pdo->prepare("INSERT INTO damaged_items (order_number, account, product_image, item_details, date_logged) VALUES (?, ?, ?, ?, NOW())")
                            ->execute([(string)$orderId, $ticket['account'], $ticket['product_image'], $itemDetails]);
                    }
                }

                // Notify customer in-app for exchange/replacement decisions (same-item replacement only, no cash refund)
                $custEmailEx = trim((string)($ticket['email'] ?? ''));
                if ($custEmailEx !== '' && $requestType === 'exchange') {
                    if ($status === 'Approved') {
                        createSystemNotification($pdo, [
                            'recipient_role' => 'customer',
                            'recipient_email' => $custEmailEx,
                            'order_id' => $orderId,
                            'request_id' => $reqId,
                            'title' => "Exchange Request Approved (#{$orderId})",
                            'message' => "Your same-item exchange request for Order #{$orderId} is approved. No cash refund applies — bring the item(s) to R. Taipan Hardware for a brand-new same-item replacement.",
                            'type' => 'success',
                            'unique_key' => "exchange_approved_{$reqId}",
                        ]);
                    } elseif ($status === 'Completed') {
                        createSystemNotification($pdo, [
                            'recipient_role' => 'customer',
                            'recipient_email' => $custEmailEx,
                            'order_id' => $orderId,
                            'request_id' => $reqId,
                            'title' => "Exchange Request Completed (#{$orderId})",
                            'message' => "Your same-item exchange for Order #{$orderId} is now completed with a replacement of the same item.",
                            'type' => 'success',
                            'unique_key' => "exchange_completed_{$reqId}",
                        ]);
                    } elseif ($status === 'Declined') {
                        createSystemNotification($pdo, [
                            'recipient_role' => 'customer',
                            'recipient_email' => $custEmailEx,
                            'order_id' => $orderId,
                            'request_id' => $reqId,
                            'title' => "Exchange Request Declined (#{$orderId})",
                            'message' => "Your exchange request for Order #{$orderId} was declined." . ($declineReason !== '' ? " Reason: {$declineReason}" : ''),
                            'type' => 'warning',
                            'unique_key' => "exchange_declined_{$reqId}",
                        ]);
                    }
                }
            }

            if ($startedTxn && $pdo->inTransaction()) {
                $pdo->commit();
            }

            if ($sendEmail && $notifyPayload !== null && !empty($notifyPayload['email']) && ($ticket['email_status'] ?? '') !== $notifyPayload['email_status']) {
                try {
                    if (function_exists('sendSystemEmail')) {
                        $mailRes = sendSystemEmail(
                            $notifyPayload['email'],
                            $notifyPayload['name'],
                            $notifyPayload['subject'],
                            $notifyPayload['body']
                        );
                        if (!empty($mailRes['success'])) {
                            $pdo->prepare("UPDATE customer_requests SET email_status = ? WHERE id = ?")
                                ->execute([$notifyPayload['email_status'], $reqId]);
                        }
                    }
                } catch (Throwable $mailEx) {
                    error_log("Cancellation decision email error: " . $mailEx->getMessage());
                }
            }

            if ($requestType === 'cancel' && $status === 'Approved') {
                $finalCancelMsg = !empty($cancelMeta['requires_refund'])
                    ? "✅ Cancellation request #{$reqId} approved. Order #{$orderId} is now CANCELLED (Pending Refund) and inventory has been restored."
                    : "✅ Cancellation request #{$reqId} approved and marked Completed (COD). Order #{$orderId} is now CANCELLED and inventory has been restored.";
                return ['success' => true, 'message' => $finalCancelMsg, 'pane' => $paneTarget];
            }
            if ($requestType === 'cancel' && $status === 'Declined') {
                return ['success' => true, 'message' => "ℹ️ Cancellation request #{$reqId} declined. Order #{$orderId} remains in PREPARING status.", 'pane' => $paneTarget];
            }
            return ['success' => true, 'message' => "🎉 Ticket #{$reqId} status successfully updated to {$status}!", 'pane' => $paneTarget];
        } catch (Throwable $e) {
            if ($startedTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'message' => '❌ Failed to update status: ' . $e->getMessage(), 'pane' => $paneTarget];
        }
    }
}
