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
                ADD COLUMN IF NOT EXISTS completed_at TIMESTAMP NULL
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
            ];
        }
        unset($order);
    }
}
