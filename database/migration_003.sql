-- ============================================================
-- Blackview SA Portal — Migration 003
-- Quick Quotes: quotes + quote_items tables, nav links
-- ============================================================

CREATE TABLE IF NOT EXISTS quotes (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quote_no           VARCHAR(50)  NOT NULL,
    customer_name      VARCHAR(150) NOT NULL DEFAULT '',
    customer_email     VARCHAR(150) NOT NULL DEFAULT '',
    customer_phone     VARCHAR(50)  NOT NULL DEFAULT '',
    customer_address   TEXT,
    customer_id_number VARCHAR(50)  DEFAULT '',
    channel            ENUM('takealot','makro','instore','email','other') NOT NULL DEFAULT 'instore',
    subtotal           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    vat_amount         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status             ENUM('draft','sent','accepted','declined','expired') NOT NULL DEFAULT 'draft',
    valid_until        DATE          NULL,
    notes              TEXT,
    created_by         INT UNSIGNED  NULL,
    created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quote_no (quote_no),
    INDEX idx_quote_status (status),
    INDEX idx_quote_created (created_at),
    CONSTRAINT fk_quote_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_items (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quote_id     INT UNSIGNED  NOT NULL,
    product_id   INT UNSIGNED  NULL,
    description  VARCHAR(255)  NOT NULL DEFAULT '',
    qty          INT           NOT NULL DEFAULT 1,
    unit_price   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    vat_rate     DECIMAL(5,2)  NOT NULL DEFAULT 15.00,
    vat_amount   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    line_total   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT fk_qi_quote   FOREIGN KEY (quote_id)   REFERENCES quotes(id)   ON DELETE CASCADE,
    CONSTRAINT fk_qi_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO nav_links (label, url, icon_class, role_required, display_order, is_active)
VALUES
  ('Invoice History', '/pos/invoices.php', 'icon-invoice', 'user', 57, 1),
  ('Quotes', '/pos/quotes.php', 'icon-quote', 'user', 58, 1);
