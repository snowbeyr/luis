<?php
// includes/shared_inventory_functions.php
// Centralized Shared Inventory / Linked Stock Engine for R. Taipan Construction Supply and Services, OPC

if (!function_exists('ensureSharedInventorySchema')) {
    /**
     * Ensures all required shared inventory tables exist in PostgreSQL
     */
    function ensureSharedInventorySchema($pdo) {
        static $checked = false;
        if ($checked) return;

        $sql = "
            CREATE TABLE IF NOT EXISTS shared_inventory_sources (
                id SERIAL PRIMARY KEY,
                source_name VARCHAR(255) NOT NULL,
                base_unit VARCHAR(50) NOT NULL,
                physical_stock NUMERIC(14,2) NOT NULL DEFAULT 0.00,
                low_stock_threshold NUMERIC(14,2) NOT NULL DEFAULT 0.00,
                is_active SMALLINT NOT NULL DEFAULT 1,
                created_by VARCHAR(100) NULL,
                created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS shared_inventory_variants (
                id SERIAL PRIMARY KEY,
                source_id INTEGER NOT NULL REFERENCES shared_inventory_sources(id) ON DELETE CASCADE,
                product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
                variant_id INTEGER NULL REFERENCES product_variants(id) ON DELETE CASCADE,
                conversion_qty NUMERIC(14,4) NOT NULL,
                selling_unit_label VARCHAR(100) NULL,
                created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
            );

            CREATE UNIQUE INDEX IF NOT EXISTS idx_shared_inv_prod_var 
            ON shared_inventory_variants (product_id, COALESCE(variant_id, 0));

            CREATE TABLE IF NOT EXISTS shared_inventory_movements (
                id SERIAL PRIMARY KEY,
                source_id INTEGER NOT NULL REFERENCES shared_inventory_sources(id) ON DELETE CASCADE,
                product_id INTEGER NULL REFERENCES products(id) ON DELETE SET NULL,
                variant_id INTEGER NULL REFERENCES product_variants(id) ON DELETE SET NULL,
                movement_type VARCHAR(50) NOT NULL,
                qty_in_base_unit NUMERIC(14,4) NOT NULL,
                selling_unit_qty NUMERIC(12,2) NULL,
                conversion_qty_used NUMERIC(14,4) NULL,
                previous_physical_stock NUMERIC(14,2) NOT NULL,
                new_physical_stock NUMERIC(14,2) NOT NULL,
                reference_id VARCHAR(100) NULL,
                user_responsible VARCHAR(100) NULL,
                remarks TEXT NULL,
                idempotency_key VARCHAR(150) NULL UNIQUE,
                created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
            );

            CREATE INDEX IF NOT EXISTS idx_shared_mov_source ON shared_inventory_movements(source_id);
            CREATE INDEX IF NOT EXISTS idx_shared_mov_ref ON shared_inventory_movements(reference_id);
            CREATE INDEX IF NOT EXISTS idx_shared_mov_created ON shared_inventory_movements(created_at);

            CREATE TABLE IF NOT EXISTS shared_inventory_reservations (
                id SERIAL PRIMARY KEY,
                source_id INTEGER NOT NULL REFERENCES shared_inventory_sources(id) ON DELETE CASCADE,
                order_id INTEGER NOT NULL,
                product_id INTEGER NOT NULL,
                variant_id INTEGER NULL,
                selling_qty NUMERIC(12,2) NOT NULL,
                reserved_base_qty NUMERIC(14,4) NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'RESERVED',
                created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
            );

            CREATE INDEX IF NOT EXISTS idx_shared_res_order ON shared_inventory_reservations(order_id);
        ";
        try {
            $pdo->exec($sql);
            $checked = true;
        } catch (Exception $e) {
            error_log("ensureSharedInventorySchema error: " . $e->getMessage());
        }
    }
}

if (!function_exists('calculateSellableUnits')) {
    /**
     * Calculates available complete sellable units: floor(physical_stock / conversion_qty)
     */
    function calculateSellableUnits($physical_stock, $conversion_qty) {
        $conv = (float)$conversion_qty;
        if ($conv <= 0.0) {
            return 0;
        }
        $phys = (float)$physical_stock;
        if ($phys <= 0.0) {
            return 0;
        }
        $units = floor($phys / $conv);
        return max(0, (int)$units);
    }
}

if (!function_exists('getLinkedSourceInfo')) {
    /**
     * Checks if a product or variant is linked to an active shared inventory source.
     * Returns source and link details or false.
     */
    function getLinkedSourceInfo($pdo, $product_id, $variant_id = null) {
        ensureSharedInventorySchema($pdo);
        $prod_id = !empty($product_id) ? (int)$product_id : 0;
        $var_id = !empty($variant_id) ? (int)$variant_id : 0;

        if ($var_id > 0) {
            $stmt = $pdo->prepare("
                SELECT siv.id AS link_id, siv.source_id, siv.product_id, siv.variant_id,
                       siv.conversion_qty, siv.selling_unit_label,
                       sis.source_name, sis.base_unit, sis.physical_stock,
                       sis.low_stock_threshold, sis.is_active
                FROM shared_inventory_variants siv
                JOIN shared_inventory_sources sis ON siv.source_id = sis.id
                WHERE siv.variant_id = ? AND sis.is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$var_id]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($res) return $res;
        }

        if ($prod_id > 0) {
            $stmt = $pdo->prepare("
                SELECT siv.id AS link_id, siv.source_id, siv.product_id, siv.variant_id,
                       siv.conversion_qty, siv.selling_unit_label,
                       sis.source_name, sis.base_unit, sis.physical_stock,
                       sis.low_stock_threshold, sis.is_active
                FROM shared_inventory_variants siv
                JOIN shared_inventory_sources sis ON siv.source_id = sis.id
                WHERE siv.product_id = ? AND (siv.variant_id IS NULL OR siv.variant_id = 0) AND sis.is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$prod_id]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($res) return $res;
        }

        return false;
    }
}

if (!function_exists('syncSharedInventoryVariants')) {
    /**
     * Recalculates and synchronizes sellable stock for all variants linked to a shared source.
     * Updates products.stock or product_variants.stock with floor(physical_stock / conversion_qty).
     */
    function syncSharedInventoryVariants($pdo, $source_id) {
        $src_id = (int)$source_id;
        if ($src_id <= 0) return false;

        $stmt = $pdo->prepare("SELECT physical_stock FROM shared_inventory_sources WHERE id = ?");
        $stmt->execute([$src_id]);
        $physical_stock = $stmt->fetchColumn();
        if ($physical_stock === false) return false;
        $physical_stock = (float)$physical_stock;

        $stmt_vars = $pdo->prepare("
            SELECT id, product_id, variant_id, conversion_qty 
            FROM shared_inventory_variants 
            WHERE source_id = ?
        ");
        $stmt_vars->execute([$src_id]);
        $variants = $stmt_vars->fetchAll(PDO::FETCH_ASSOC);

        foreach ($variants as $v) {
            $sellable = calculateSellableUnits($physical_stock, $v['conversion_qty']);
            if (!empty($v['variant_id'])) {
                $up = $pdo->prepare("UPDATE product_variants SET stock = ? WHERE id = ?");
                $up->execute([$sellable, (int)$v['variant_id']]);
            } else if (!empty($v['product_id'])) {
                $up = $pdo->prepare("UPDATE products SET stock = ? WHERE id = ?");
                $up->execute([$sellable, (int)$v['product_id']]);
            }
        }
        return true;
    }
}

if (!function_exists('deductSharedStock')) {
    /**
     * Deducts physical stock from shared source and records movement.
     * Thread-safe with FOR UPDATE locking and idempotency protection.
     * 
     * @return array [bool $success, string $message, float|null $new_stock]
     */
    function deductSharedStock($pdo, $source_id, $product_id, $variant_id, $selling_qty, $movement_type, $reference_id, $user_responsible, $remarks = '', $idempotency_key = null) {
        $source_id = (int)$source_id;
        $selling_qty = (float)$selling_qty;
        if ($selling_qty <= 0) {
            return [true, "No deduction needed for 0 or negative quantity.", null];
        }

        // Idempotency check
        if (!empty($idempotency_key)) {
            $stmt_chk = $pdo->prepare("SELECT id, new_physical_stock FROM shared_inventory_movements WHERE idempotency_key = ?");
            $stmt_chk->execute([$idempotency_key]);
            $existing = $stmt_chk->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                return [true, "Already deducted (idempotent).", (float)$existing['new_physical_stock']];
            }
        }

        // Get conversion qty
        $var_id = !empty($variant_id) ? (int)$variant_id : null;
        $prod_id = !empty($product_id) ? (int)$product_id : null;

        if ($var_id) {
            $stmt_link = $pdo->prepare("SELECT conversion_qty FROM shared_inventory_variants WHERE source_id = ? AND variant_id = ?");
            $stmt_link->execute([$source_id, $var_id]);
            $conv_qty = $stmt_link->fetchColumn();
        } else {
            $stmt_link = $pdo->prepare("SELECT conversion_qty FROM shared_inventory_variants WHERE source_id = ? AND product_id = ? AND (variant_id IS NULL OR variant_id = 0)");
            $stmt_link->execute([$source_id, $prod_id]);
            $conv_qty = $stmt_link->fetchColumn();
        }

        if ($conv_qty === false || (float)$conv_qty <= 0) {
            return [false, "Conversion requirement not configured for this linked product/variant.", null];
        }
        $conv_qty = (float)$conv_qty;
        $base_qty_needed = $selling_qty * $conv_qty;

        $in_txn = $pdo->inTransaction();
        if (!$in_txn) {
            $pdo->beginTransaction();
        }

        try {
            // Lock source row
            $stmt_src = $pdo->prepare("SELECT source_name, base_unit, physical_stock FROM shared_inventory_sources WHERE id = ? FOR UPDATE");
            $stmt_src->execute([$source_id]);
            $source = $stmt_src->fetch(PDO::FETCH_ASSOC);

            if (!$source) {
                if (!$in_txn) $pdo->rollBack();
                return [false, "Shared inventory source #$source_id not found.", null];
            }

            $current_phys = (float)$source['physical_stock'];
            if ($current_phys < $base_qty_needed) {
                if (!$in_txn) $pdo->rollBack();
                return [false, "Insufficient shared physical stock for '{$source['source_name']}'. Required: " . number_format($base_qty_needed, 2) . " {$source['base_unit']}, Available: " . number_format($current_phys, 2) . " {$source['base_unit']}.", null];
            }

            $new_phys = $current_phys - $base_qty_needed;

            // Update source stock
            $stmt_up = $pdo->prepare("UPDATE shared_inventory_sources SET physical_stock = ?, updated_at = NOW() WHERE id = ?");
            $stmt_up->execute([$new_phys, $source_id]);

            // Record movement
            $stmt_mov = $pdo->prepare("
                INSERT INTO shared_inventory_movements (
                    source_id, product_id, variant_id, movement_type,
                    qty_in_base_unit, selling_unit_qty, conversion_qty_used,
                    previous_physical_stock, new_physical_stock,
                    reference_id, user_responsible, remarks, idempotency_key
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?, ?, ?
                )
            ");
            $stmt_mov->execute([
                $source_id, $prod_id, $var_id, $movement_type,
                -$base_qty_needed, $selling_qty, $conv_qty,
                $current_phys, $new_phys,
                (string)$reference_id, (string)$user_responsible, $remarks, $idempotency_key
            ]);

            // Sync all linked variants
            syncSharedInventoryVariants($pdo, $source_id);

            if (!$in_txn) {
                $pdo->commit();
            }

            return [true, "Shared stock deducted successfully.", $new_phys];
        } catch (Exception $e) {
            if (!$in_txn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [false, "Deduction failed: " . $e->getMessage(), null];
        }
    }
}

if (!function_exists('restoreSharedStock')) {
    /**
     * Restores physical stock to shared source and records movement.
     * Prevents duplicate restoration with unique idempotency_key.
     * 
     * @return array [bool $success, string $message, float|null $new_stock]
     */
    function restoreSharedStock($pdo, $source_id, $product_id, $variant_id, $selling_qty, $movement_type, $reference_id, $user_responsible, $remarks = '', $idempotency_key = null) {
        $source_id = (int)$source_id;
        $selling_qty = (float)$selling_qty;
        if ($selling_qty <= 0) {
            return [true, "No restoration needed for 0 or negative quantity.", null];
        }

        // Idempotency check: Guard against double-restoration!
        if (!empty($idempotency_key)) {
            $stmt_chk = $pdo->prepare("SELECT id, new_physical_stock FROM shared_inventory_movements WHERE idempotency_key = ?");
            $stmt_chk->execute([$idempotency_key]);
            $existing = $stmt_chk->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                return [true, "Restoration already processed previously (idempotent skipped).", (float)$existing['new_physical_stock']];
            }
        }

        $var_id = !empty($variant_id) ? (int)$variant_id : null;
        $prod_id = !empty($product_id) ? (int)$product_id : null;

        if ($var_id) {
            $stmt_link = $pdo->prepare("SELECT conversion_qty FROM shared_inventory_variants WHERE source_id = ? AND variant_id = ?");
            $stmt_link->execute([$source_id, $var_id]);
            $conv_qty = $stmt_link->fetchColumn();
        } else {
            $stmt_link = $pdo->prepare("SELECT conversion_qty FROM shared_inventory_variants WHERE source_id = ? AND product_id = ? AND (variant_id IS NULL OR variant_id = 0)");
            $stmt_link->execute([$source_id, $prod_id]);
            $conv_qty = $stmt_link->fetchColumn();
        }

        if ($conv_qty === false || (float)$conv_qty <= 0) {
            return [false, "Conversion requirement not configured for this linked product/variant.", null];
        }
        $conv_qty = (float)$conv_qty;
        $base_qty_to_restore = $selling_qty * $conv_qty;

        $in_txn = $pdo->inTransaction();
        if (!$in_txn) {
            $pdo->beginTransaction();
        }

        try {
            // Lock source row
            $stmt_src = $pdo->prepare("SELECT source_name, base_unit, physical_stock FROM shared_inventory_sources WHERE id = ? FOR UPDATE");
            $stmt_src->execute([$source_id]);
            $source = $stmt_src->fetch(PDO::FETCH_ASSOC);

            if (!$source) {
                if (!$in_txn) $pdo->rollBack();
                return [false, "Shared inventory source #$source_id not found.", null];
            }

            $current_phys = (float)$source['physical_stock'];
            $new_phys = $current_phys + $base_qty_to_restore;

            // Update source stock
            $stmt_up = $pdo->prepare("UPDATE shared_inventory_sources SET physical_stock = ?, updated_at = NOW() WHERE id = ?");
            $stmt_up->execute([$new_phys, $source_id]);

            // Record movement
            $stmt_mov = $pdo->prepare("
                INSERT INTO shared_inventory_movements (
                    source_id, product_id, variant_id, movement_type,
                    qty_in_base_unit, selling_unit_qty, conversion_qty_used,
                    previous_physical_stock, new_physical_stock,
                    reference_id, user_responsible, remarks, idempotency_key
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?, ?, ?
                )
            ");
            $stmt_mov->execute([
                $source_id, $prod_id, $var_id, $movement_type,
                +$base_qty_to_restore, $selling_qty, $conv_qty,
                $current_phys, $new_phys,
                (string)$reference_id, (string)$user_responsible, $remarks, $idempotency_key
            ]);

            // Sync all linked variants
            syncSharedInventoryVariants($pdo, $source_id);

            if (!$in_txn) {
                $pdo->commit();
            }

            return [true, "Shared stock restored successfully.", $new_phys];
        } catch (Exception $e) {
            if (!$in_txn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [false, "Restoration failed: " . $e->getMessage(), null];
        }
    }
}

if (!function_exists('adjustSharedStock')) {
    /**
     * Manual physical stock adjustment by Owner.
     * Records movement audit and syncs variants.
     */
    function adjustSharedStock($pdo, $source_id, $new_physical_stock, $user_responsible, $reason) {
        $source_id = (int)$source_id;
        $new_physical_stock = (float)$new_physical_stock;
        if ($new_physical_stock < 0) {
            return [false, "Physical stock cannot be negative.", null];
        }

        $in_txn = $pdo->inTransaction();
        if (!$in_txn) {
            $pdo->beginTransaction();
        }

        try {
            $stmt_src = $pdo->prepare("SELECT source_name, base_unit, physical_stock FROM shared_inventory_sources WHERE id = ? FOR UPDATE");
            $stmt_src->execute([$source_id]);
            $source = $stmt_src->fetch(PDO::FETCH_ASSOC);

            if (!$source) {
                if (!$in_txn) $pdo->rollBack();
                return [false, "Source not found.", null];
            }

            $prev_stock = (float)$source['physical_stock'];
            $diff = $new_physical_stock - $prev_stock;

            $stmt_up = $pdo->prepare("UPDATE shared_inventory_sources SET physical_stock = ?, updated_at = NOW() WHERE id = ?");
            $stmt_up->execute([$new_physical_stock, $source_id]);

            $stmt_mov = $pdo->prepare("
                INSERT INTO shared_inventory_movements (
                    source_id, product_id, variant_id, movement_type,
                    qty_in_base_unit, selling_unit_qty, conversion_qty_used,
                    previous_physical_stock, new_physical_stock,
                    reference_id, user_responsible, remarks
                ) VALUES (
                    ?, NULL, NULL, 'Manual Adjustment',
                    ?, NULL, NULL,
                    ?, ?,
                    NULL, ?, ?
                )
            ");
            $stmt_mov->execute([
                $source_id, $diff, $prev_stock, $new_physical_stock,
                (string)$user_responsible, (string)$reason
            ]);

            syncSharedInventoryVariants($pdo, $source_id);

            if (!$in_txn) {
                $pdo->commit();
            }

            return [true, "Physical stock adjusted successfully to " . number_format($new_physical_stock, 2) . " {$source['base_unit']}.", $new_physical_stock];
        } catch (Exception $e) {
            if (!$in_txn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [false, "Adjustment failed: " . $e->getMessage(), null];
        }
    }
}
