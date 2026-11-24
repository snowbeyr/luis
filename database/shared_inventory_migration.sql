-- Migration: Shared Inventory / Linked Stock Management for R. Taipan POS & Inventory
-- PostgreSQL compatible

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

-- Unique index to prevent duplicate linking of the same product/variant
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
