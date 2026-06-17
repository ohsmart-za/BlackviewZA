-- ============================================================
-- Blackview SA Portal — Database Schema
-- ============================================================

CREATE DATABASE IF NOT EXISTS blackview_portal
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE blackview_portal;

-- ============================================================
-- USERS
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120)  NOT NULL,
    email         VARCHAR(180)  NOT NULL UNIQUE,
    password_hash VARCHAR(255)  NOT NULL DEFAULT '',
    role          ENUM('user','admin','superuser') NOT NULL DEFAULT 'user',
    google_id     VARCHAR(120)  NULL DEFAULT NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login    DATETIME     NULL DEFAULT NULL,
    INDEX idx_users_email  (email),
    INDEX idx_users_role   (role),
    INDEX idx_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- WAREHOUSES
-- ============================================================
CREATE TABLE IF NOT EXISTS warehouses (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    location   VARCHAR(255) NOT NULL DEFAULT '',
    is_active  TINYINT(1)  NOT NULL DEFAULT 1,
    created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_warehouses_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PRODUCTS
-- ============================================================
CREATE TABLE IF NOT EXISTS products (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku         VARCHAR(80)  NOT NULL UNIQUE,
    name        VARCHAR(180) NOT NULL,
    brand       VARCHAR(80)  NOT NULL DEFAULT 'Blackview',
    category    VARCHAR(80)  NOT NULL DEFAULT '',
    description TEXT         NULL,
    is_active   TINYINT(1)  NOT NULL DEFAULT 1,
    created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_products_sku      (sku),
    INDEX idx_products_brand    (brand),
    INDEX idx_products_active   (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- INVENTORY STOCK  (aggregate qty per product/warehouse)
-- ============================================================
CREATE TABLE IF NOT EXISTS inventory_stock (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id   INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    qty          INT          NOT NULL DEFAULT 0,
    updated_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stock_product_warehouse (product_id, warehouse_id),
    CONSTRAINT fk_inv_product   FOREIGN KEY (product_id)   REFERENCES products(id)   ON DELETE RESTRICT,
    CONSTRAINT fk_inv_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- STOCK ITEMS  (individual serialised units)
-- ============================================================
CREATE TABLE IF NOT EXISTS stock_items (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id   INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    serial_no    VARCHAR(120) NOT NULL,
    status       ENUM('in_stock','moved','sold') NOT NULL DEFAULT 'in_stock',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_serial (serial_no),
    INDEX idx_si_product   (product_id),
    INDEX idx_si_warehouse (warehouse_id),
    INDEX idx_si_status    (status),
    CONSTRAINT fk_si_product   FOREIGN KEY (product_id)   REFERENCES products(id)   ON DELETE RESTRICT,
    CONSTRAINT fk_si_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- STOCK MOVEMENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS stock_movements (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id        INT UNSIGNED NOT NULL,
    from_warehouse_id INT UNSIGNED NULL DEFAULT NULL,
    to_warehouse_id   INT UNSIGNED NULL DEFAULT NULL,
    qty               INT         NOT NULL DEFAULT 0,
    moved_by          INT UNSIGNED NOT NULL,
    invoice_no        VARCHAR(100) NOT NULL DEFAULT '',
    channel           ENUM('takealot','makro','instore','email','transfer','received') NOT NULL DEFAULT 'transfer',
    notes             TEXT        NULL,
    moved_at          DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sm_product     (product_id),
    INDEX idx_sm_from_wh     (from_warehouse_id),
    INDEX idx_sm_to_wh       (to_warehouse_id),
    INDEX idx_sm_moved_by    (moved_by),
    INDEX idx_sm_channel     (channel),
    INDEX idx_sm_moved_at    (moved_at),
    CONSTRAINT fk_sm_product  FOREIGN KEY (product_id)        REFERENCES products(id)   ON DELETE RESTRICT,
    CONSTRAINT fk_sm_from_wh  FOREIGN KEY (from_warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sm_to_wh    FOREIGN KEY (to_warehouse_id)   REFERENCES warehouses(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sm_user     FOREIGN KEY (moved_by)           REFERENCES users(id)      ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- MOVEMENT SERIALS  (serials linked to a movement)
-- ============================================================
CREATE TABLE IF NOT EXISTS movement_serials (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    movement_id INT UNSIGNED NOT NULL,
    serial_no   VARCHAR(120) NOT NULL,
    INDEX idx_ms_movement (movement_id),
    INDEX idx_ms_serial   (serial_no),
    CONSTRAINT fk_ms_movement FOREIGN KEY (movement_id) REFERENCES stock_movements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- AUDIT LOG
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_log (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NULL DEFAULT NULL,
    action     VARCHAR(80)  NOT NULL,
    entity     VARCHAR(80)  NOT NULL DEFAULT '',
    entity_id  INT UNSIGNED NULL DEFAULT NULL,
    details    TEXT         NULL,
    ip_address VARCHAR(45)  NOT NULL DEFAULT '',
    created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_al_user      (user_id),
    INDEX idx_al_action    (action),
    INDEX idx_al_entity    (entity),
    INDEX idx_al_created   (created_at),
    CONSTRAINT fk_al_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- NAV LINKS
-- ============================================================
CREATE TABLE IF NOT EXISTS nav_links (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label         VARCHAR(80)  NOT NULL,
    url           VARCHAR(255) NOT NULL,
    icon_class    VARCHAR(80)  NOT NULL DEFAULT '',
    role_required ENUM('user','admin','superuser') NOT NULL DEFAULT 'user',
    display_order INT         NOT NULL DEFAULT 0,
    is_active     TINYINT(1)  NOT NULL DEFAULT 1,
    created_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_nl_role   (role_required),
    INDEX idx_nl_order  (display_order),
    INDEX idx_nl_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED DATA
-- ============================================================

-- Superuser  (password: Admin@1234)
INSERT INTO users (name, email, password_hash, role, is_active)
VALUES (
    'Super Admin',
    'admin@blackview.co.za',
    '$2y$12$HRfd2yttMkmQgoPpeIjUz.OlwbgdfjdHVyR0se7ieka4RKmV1.EqO',  -- bcrypt of Admin@1234
    'superuser',
    1
);

-- Warehouses
INSERT INTO warehouses (name, location, is_active) VALUES
('Head Office',          'Cape Town, Western Cape',  1),
('Takealot Warehouse',   'Johannesburg, Gauteng',    1),
('Makro Cape Town',      'Cape Town, Western Cape',  1);

-- Products
INSERT INTO products (sku, name, brand, category, description, is_active) VALUES
('BV-A95-256',  'Blackview A95',          'Blackview', 'Smartphone', 'Blackview A95 — 8GB RAM, 256GB ROM, MediaTek Helio G85', 1),
('BV-BV9900P',  'Blackview BV9900 Pro',   'Blackview', 'Rugged Phone', 'Rugged smartphone with thermal camera, 8+256GB', 1),
('BV-TAB15',    'Blackview Tab 15',       'Blackview', 'Tablet', '10.51-inch FHD tablet, 8GB RAM, 128GB ROM, Android 13', 1),
('BV-OSCAL-C80','Oscal C80',              'Oscal',     'Smartphone', 'Budget smartphone 6.6-inch, 4GB+128GB', 1),
('BV-BL8800PRO','Blackview BL8800 Pro',   'Blackview', 'Rugged Phone', 'Rugged 5G phone, night-vision camera, 8+128GB', 1);

-- Nav links
INSERT INTO nav_links (label, url, icon_class, role_required, display_order, is_active) VALUES
('Dashboard',       '/dashboard.php',               'icon-home',        'user',       1,  1),
('Scan In Stock',   '/inventory/scan_in.php',       'icon-scan',        'user',       2,  1),
('Move Stock',      '/inventory/move_stock.php',    'icon-move',        'user',       3,  1),
('View Stock',      '/inventory/view_stock.php',    'icon-box',         'user',       4,  1),
('Stock Report',    '/reports/stock_movements.php', 'icon-chart',       'user',       5,  1),
('Users',           '/admin/users.php',             'icon-users',       'admin',      10, 1),
('Warehouses',      '/admin/warehouses.php',        'icon-warehouse',   'admin',      11, 1),
('Nav Links',       '/admin/nav_links.php',         'icon-link',        'admin',      12, 1),
('Audit Log',       '/admin/audit_log.php',         'icon-log',         'admin',      13, 1);
