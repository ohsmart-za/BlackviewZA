-- ============================================================
-- Blackview SA Portal — Migration 004
-- Discount support on invoices
-- ============================================================

ALTER TABLE invoices ADD COLUMN discount_pct    DECIMAL(5,2)  NOT NULL DEFAULT 0.00 AFTER payment_method;
ALTER TABLE invoices ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_pct;
