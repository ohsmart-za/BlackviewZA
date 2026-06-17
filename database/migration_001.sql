-- ============================================================
-- Blackview SA Portal — Migration 001
-- Product pricing, Suppliers, Purchase Orders, Customers,
-- Invoices, and POS support
-- ============================================================

-- Product pricing
ALTER TABLE products
    ADD COLUMN cost_price    DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER description,
    ADD COLUMN selling_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER cost_price,
    ADD COLUMN vat_rate      DECIMAL(5,2)  NOT NULL DEFAULT 15.00 AFTER selling_price;

-- Suppliers
CREATE TABLE IF NOT EXISTS suppliers (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(150) NOT NULL,
    contact_name VARCHAR(100) DEFAULT '',
    email        VARCHAR(150) DEFAULT '',
    phone        VARCHAR(50)  DEFAULT '',
    address      TEXT,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sup_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Purchase Orders
CREATE TABLE IF NOT EXISTS purchase_orders (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    po_number     VARCHAR(50)  NOT NULL,
    supplier_id   INT UNSIGNED NULL,
    warehouse_id  INT UNSIGNED NOT NULL,
    order_date    DATE         NULL,
    expected_date DATE         NULL,
    status        ENUM('draft','ordered','partial','received','cancelled') NOT NULL DEFAULT 'draft',
    notes         TEXT,
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_po_number (po_number),
    INDEX idx_po_status (status),
    CONSTRAINT fk_po_supplier  FOREIGN KEY (supplier_id)  REFERENCES suppliers(id)  ON DELETE SET NULL,
    CONSTRAINT fk_po_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
    CONSTRAINT fk_po_user      FOREIGN KEY (created_by)   REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Purchase Order Line Items
CREATE TABLE IF NOT EXISTS purchase_order_items (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    po_id        INT UNSIGNED NOT NULL,
    product_id   INT UNSIGNED NOT NULL,
    qty_ordered  INT          NOT NULL DEFAULT 0,
    qty_received INT          NOT NULL DEFAULT 0,
    unit_cost    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT fk_poi_po      FOREIGN KEY (po_id)      REFERENCES purchase_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_poi_product FOREIGN KEY (product_id) REFERENCES products(id)        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link stock_items to the PO they were received against
ALTER TABLE stock_items
    ADD COLUMN po_id INT UNSIGNED NULL AFTER warehouse_id,
    ADD CONSTRAINT fk_si_po FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE SET NULL;

-- Customers
CREATE TABLE IF NOT EXISTS customers (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(150) NOT NULL,
    email      VARCHAR(150) DEFAULT '',
    phone      VARCHAR(50)  DEFAULT '',
    address    TEXT,
    id_number  VARCHAR(50)  DEFAULT '',
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cust_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoices (header)
CREATE TABLE IF NOT EXISTS invoices (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_no  VARCHAR(50)  NOT NULL,
    customer_id INT UNSIGNED NULL,
    channel     ENUM('takealot','makro','instore','email','other') NOT NULL DEFAULT 'instore',
    subtotal    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    vat_amount  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    notes       TEXT,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invoice_no (invoice_no),
    INDEX idx_inv_created (created_at),
    CONSTRAINT fk_inv_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_inv_user     FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoice Line Items
CREATE TABLE IF NOT EXISTS invoice_items (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id   INT UNSIGNED  NOT NULL,
    product_id   INT UNSIGNED  NOT NULL,
    serial_no    VARCHAR(100)  NOT NULL DEFAULT '',
    warehouse_id INT UNSIGNED  NULL,
    unit_price   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    vat_rate     DECIMAL(5,2)  NOT NULL DEFAULT 15.00,
    vat_amount   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    line_total   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT fk_ii_invoice   FOREIGN KEY (invoice_id)   REFERENCES invoices(id)   ON DELETE CASCADE,
    CONSTRAINT fk_ii_product   FOREIGN KEY (product_id)   REFERENCES products(id)   ON DELETE RESTRICT,
    CONSTRAINT fk_ii_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: sample supplier
INSERT INTO suppliers (name, contact_name, email, phone) VALUES
('Blackview Global', 'Sales Team', 'sales@blackview.com', '+86 123 456 789');
