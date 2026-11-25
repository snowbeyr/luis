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
