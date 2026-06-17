-- ============================================================
-- Migration 011 — Rebuild Nav Links: ordered + Admin group
-- ============================================================

-- 1. Add group_label column (skip if already exists)
ALTER TABLE nav_links
    ADD COLUMN group_label VARCHAR(80) NULL DEFAULT NULL AFTER display_order;

-- 2. Clear old link permissions and links (they'll be re-built)
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE user_nav_permissions;
TRUNCATE TABLE nav_links;
SET FOREIGN_KEY_CHECKS = 1;

-- 3. Insert main nav links (visible to all users, in order)
INSERT INTO nav_links (label, url, icon_class, role_required, display_order, group_label, is_active) VALUES
('Dashboard',        '/dashboard.php',                          'icon-home',       'user',  10,  NULL, 1),
('CRM',              '/crm/index.php',                          'icon-crm',        'user',  20,  NULL, 1),
('POS',              '/pos/index.php',                          'icon-pos',        'user',  30,  NULL, 1),
('Stock Operations', '/inventory/stock_operations.php',         'icon-scan',       'user',  40,  NULL, 1),
('Products',         '/admin/products.php',                     'icon-product',    'user',  50,  NULL, 1),
('Serial Numbers',   '/inventory/serials.php',                  'icon-serial',     'user',  60,  NULL, 1),
('Warehouses',       '/admin/warehouses.php',                   'icon-warehouse',  'user',  70,  NULL, 1),
('Invoices',         '/pos/invoices.php',                       'icon-invoice',    'user',  80,  NULL, 1),
('Quotes',           '/pos/quotes.php',                         'icon-quote',      'user',  90,  NULL, 1),
('Stock Report',     '/reports/stock_executive.php',            'icon-chart',      'admin', 100, NULL, 1),
('Users',            '/admin/users.php',                        'icon-users',      'admin', 110, NULL, 1);

-- 4. Insert Admin-group links (collapsed under Admin button)
INSERT INTO nav_links (label, url, icon_class, role_required, display_order, group_label, is_active) VALUES
('Nav Links',        '/admin/nav_links.php',                    'icon-link',       'admin', 200, 'Admin', 1),
('Audit Log',        '/admin/audit_log.php',                    'icon-log',        'admin', 210, 'Admin', 1),
('Payment Methods',  '/admin/payment_methods.php',              'icon-payment',    'admin', 220, 'Admin', 1),
('Settings',         '/admin/settings.php',                     'icon-settings',   'admin', 230, 'Admin', 1);
