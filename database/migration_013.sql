-- ============================================================
-- Migration 013 — Restore missing nav links
-- ============================================================

INSERT INTO nav_links (label, url, icon_class, role_required, display_order, group_label, is_active) VALUES
('Credit Notes',    '/pos/credit_notes.php',         'icon-credit',    'user',  95,  NULL, 1),
('Daily Cashups',   '/reports/cashup.php',            'icon-cashup',    'user',  115, NULL, 1),
('Stock Movements', '/reports/stock_movements.php',   'icon-move',      'user',  120, NULL, 1),
('View Stock',      '/inventory/view_stock.php',      'icon-box',       'user',  125, NULL, 1),
('Purchase Orders', '/purchasing/orders.php',         'icon-purchase',  'user',  130, NULL, 1),
('Suppliers',       '/purchasing/suppliers.php',      'icon-supplier',  'user',  135, NULL, 1);
