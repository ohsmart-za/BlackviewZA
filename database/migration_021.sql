-- migration_021: Add product_type to products table
-- 'physical' = tracked inventory item (serial / bulk qty)
-- 'service'  = no stock, no warehouse, just a price line on an invoice

ALTER TABLE products
    ADD COLUMN IF NOT EXISTS product_type ENUM('physical','service') NOT NULL DEFAULT 'physical' AFTER category;
