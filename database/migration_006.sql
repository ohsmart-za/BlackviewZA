-- ============================================================
-- Blackview SA Portal — Migration 006
-- Invoice VAT Display Mode + Credit Note Stock Returns
-- Run once. Safe to re-run (IF NOT EXISTS / IF EXISTS guards).
-- ============================================================

-- 1. Invoice VAT display mode
--    'incl' = retail (prices shown incl. VAT per line)
--    'excl' = corporate (prices shown excl. VAT per line, VAT in totals)
ALTER TABLE invoices
    ADD COLUMN vat_display_mode ENUM('incl','excl') NOT NULL DEFAULT 'incl'
    AFTER notes;

-- 2. Credit note stock return fields (header level)
ALTER TABLE credit_notes
    ADD COLUMN return_to_stock      TINYINT(1)                        NOT NULL DEFAULT 0          AFTER status,
    ADD COLUMN return_warehouse_id  INT UNSIGNED                      NULL     DEFAULT NULL       AFTER return_to_stock,
    ADD COLUMN return_condition     ENUM('resellable','damaged')       NOT NULL DEFAULT 'resellable' AFTER return_warehouse_id;

-- 3. Credit note items: link back to original product + serial
ALTER TABLE credit_note_items
    ADD COLUMN product_id  INT UNSIGNED    NULL DEFAULT NULL AFTER credit_note_id,
    ADD COLUMN serial_no   VARCHAR(100)    NULL DEFAULT NULL AFTER product_id;
