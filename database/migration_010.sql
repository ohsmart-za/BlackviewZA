-- migration_010.sql
-- Consolidate Scan In / Move Stock / Take Out nav links into one "Stock Operations" entry

-- Remove old separate inventory operation links
DELETE FROM nav_links WHERE url IN (
    '/inventory/scan_in.php',
    '/inventory/move_stock.php',
    '/inventory/take_out.php'
);

-- Insert unified Stock Operations link (display_order 20 puts it after View Stock which was 40 after migration_009 +10 shift)
-- Adjust display_order as needed to suit your nav order
INSERT INTO nav_links (label, url, icon_class, role_required, display_order, is_active)
VALUES ('Stock Operations', '/inventory/stock_operations.php', 'icon-scan', 'user', 20, 1);
