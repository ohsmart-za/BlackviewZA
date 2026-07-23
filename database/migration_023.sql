-- migration_023: Xero integration
-- Links customers/invoices/quotes to Xero, stores OAuth tokens, sync log,
-- and a mirror table for invoices that exist only in Xero.

-- 1. OAuth token storage (single row, id = 1)
CREATE TABLE IF NOT EXISTS xero_oauth_tokens (
    id            TINYINT UNSIGNED PRIMARY KEY,
    access_token  TEXT         NULL,
    refresh_token TEXT         NULL,
    token_type    VARCHAR(20)  NOT NULL DEFAULT 'Bearer',
    expires_at    DATETIME     NULL,
    raw           TEXT         NULL,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Sync log
CREATE TABLE IF NOT EXISTS xero_sync_log (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ts        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    direction ENUM('pull','push') NOT NULL,
    entity    VARCHAR(20)  NOT NULL,
    entity_id INT UNSIGNED NULL,
    xero_id   VARCHAR(64)  NULL,
    action    VARCHAR(20)  NOT NULL,
    status    ENUM('ok','error') NOT NULL DEFAULT 'ok',
    message   VARCHAR(1000) NOT NULL DEFAULT '',
    INDEX idx_xsl_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Link columns on customers
--    updated_at is auto-maintained by MySQL — push when updated_at > xero_synced_at
ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS xero_id        VARCHAR(64) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS xero_synced_at DATETIME    NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS updated_at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ADD INDEX idx_cust_xero (xero_id);

-- 4. Link columns on invoices
ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS xero_id         VARCHAR(64)   NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS xero_status     VARCHAR(30)   NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS xero_amount_due DECIMAL(10,2) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS xero_synced_at  DATETIME      NULL DEFAULT NULL,
    ADD INDEX idx_inv_xero (xero_id);

-- 5. Link columns on quotes
ALTER TABLE quotes
    ADD COLUMN IF NOT EXISTS xero_id        VARCHAR(64) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS xero_synced_at DATETIME    NULL DEFAULT NULL;

-- 6. Mirror table: invoices created directly in Xero (not in the portal)
--    Shown in CRM invoice history so "amount spent" covers both systems.
CREATE TABLE IF NOT EXISTS xero_invoices_mirror (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    xero_id     VARCHAR(64)   NOT NULL,
    customer_id INT UNSIGNED  NULL,
    invoice_no  VARCHAR(50)   NULL,
    reference   VARCHAR(255)  NULL,
    status      VARCHAR(30)   NULL,
    doc_date    DATE          NULL,
    due_date    DATE          NULL,
    subtotal    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    vat_amount  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    amount_due  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_xim_xero (xero_id),
    INDEX idx_xim_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Admin nav link
INSERT IGNORE INTO nav_links (label, url, icon_class, role_required, display_order, is_active)
VALUES ('Xero Sync', '/admin/xero.php', '', 'admin', 96, 1);
