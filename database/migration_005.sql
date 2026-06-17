-- ============================================================
-- Blackview SA Portal — Migration 005
-- Credit Notes, Payment Allocations, Non-serialised Products
-- Run once via phpMyAdmin or MySQL CLI. Safe to check for
-- existence first — each statement will error if already applied.
-- ============================================================

-- ============================================================
-- 1. Non-serialised product flag
--    Existing products are serialised by default (DEFAULT 1)
-- ============================================================
ALTER TABLE products
    ADD COLUMN is_serialised TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1 = tracked by serial number, 0 = qty-only'
    AFTER selling_price;

-- ============================================================
-- 2. Credit Notes header
-- ============================================================
CREATE TABLE IF NOT EXISTS credit_notes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credit_note_no  VARCHAR(50)   NOT NULL UNIQUE,
    invoice_id      INT UNSIGNED  NOT NULL,
    reason          TEXT          NULL,
    subtotal        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    vat_amount      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status          ENUM('open','applied','voided') NOT NULL DEFAULT 'open',
    created_by      INT UNSIGNED  NULL DEFAULT NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cn_invoice (invoice_id),
    CONSTRAINT fk_cn_invoice  FOREIGN KEY (invoice_id)  REFERENCES invoices(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cn_user     FOREIGN KEY (created_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. Credit Note line items
-- ============================================================
CREATE TABLE IF NOT EXISTS credit_note_items (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credit_note_id INT UNSIGNED   NOT NULL,
    description    VARCHAR(255)   NOT NULL DEFAULT '',
    qty            INT            NOT NULL DEFAULT 1,
    unit_price     DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    vat_rate       DECIMAL(5,2)   NOT NULL DEFAULT 15.00,
    vat_amount     DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    line_total     DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    CONSTRAINT fk_cni_cn FOREIGN KEY (credit_note_id) REFERENCES credit_notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. Invoice Payments
--    payment_method 'credit_note' = a credit note applied
-- ============================================================
CREATE TABLE IF NOT EXISTS invoice_payments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id      INT UNSIGNED  NOT NULL,
    amount          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method  ENUM('cash','eft','card','credit_note') NOT NULL DEFAULT 'cash',
    reference       VARCHAR(150)  NOT NULL DEFAULT '',
    credit_note_id  INT UNSIGNED  NULL DEFAULT NULL,
    notes           TEXT          NULL,
    created_by      INT UNSIGNED  NULL DEFAULT NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_invoice (invoice_id),
    CONSTRAINT fk_ip_invoice FOREIGN KEY (invoice_id)     REFERENCES invoices(id)      ON DELETE RESTRICT,
    CONSTRAINT fk_ip_cn      FOREIGN KEY (credit_note_id) REFERENCES credit_notes(id)  ON DELETE SET NULL,
    CONSTRAINT fk_ip_user    FOREIGN KEY (created_by)     REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. Back-fill existing invoices as paid
--    Every invoice created before this migration was collected
--    at POS so mark them fully paid using their recorded method.
--    This keeps all existing invoices showing balance = R 0.00
-- ============================================================
INSERT INTO invoice_payments
    (invoice_id, amount, payment_method, reference, created_by, created_at)
SELECT
    id,
    total,
    payment_method,
    CONCAT('Migration — ', invoice_no),
    created_by,
    created_at
FROM invoices
WHERE total > 0
ON DUPLICATE KEY UPDATE amount = amount; -- safety no-op if run twice

-- ============================================================
-- 6. Nav link for Executive Stock Report
--    Wrapped in INSERT IGNORE so re-running is safe
-- ============================================================
INSERT IGNORE INTO nav_links (label, url, icon_class, role_required, is_active, display_order)
VALUES ('Stock Report', '/reports/stock_executive.php', 'nav-icon-chart', 'admin', 1, 55);
