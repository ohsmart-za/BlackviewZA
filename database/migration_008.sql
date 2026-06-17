-- ============================================================
-- Blackview SA Portal — Migration 008
-- Fix VAT rounding: extend selling_price precision to 4dp
-- Run once.
-- ============================================================

-- Extend column precision so incl→excl→incl round-trips correctly
ALTER TABLE products
    MODIFY selling_price DECIMAL(10,4) NOT NULL DEFAULT 0.0000;

-- Recalculate existing excl. prices from their current 2dp incl. value
-- (rounds the derived incl. to 2dp, then stores excl. at 4dp)
-- After this, ROUND(selling_price * (1 + vat_rate/100), 2) will match the
-- incl. price that was previously displayed.
UPDATE products
SET selling_price = ROUND(
    ROUND(selling_price * (1 + COALESCE(vat_rate, 15) / 100), 2)
    / (1 + COALESCE(vat_rate, 15) / 100)
, 4);
