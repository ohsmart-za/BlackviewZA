-- ============================================================
-- Migration 012 — Add barcode column to products
-- ============================================================

-- 1. Add barcode column after sku
ALTER TABLE products
    ADD COLUMN barcode VARCHAR(100) NULL DEFAULT NULL AFTER sku;

-- 2. Index for fast barcode lookups
CREATE INDEX idx_products_barcode ON products (barcode);
