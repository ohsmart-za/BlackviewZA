-- ============================================================
-- Migration 018 — Payment Links (Yoco + PayFast)
-- ============================================================

CREATE TABLE IF NOT EXISTS payment_links (
    id           INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    invoice_id   INT UNSIGNED     NOT NULL,
    provider     ENUM('yoco','payfast') NOT NULL,
    external_id  VARCHAR(255)     NULL DEFAULT NULL,   -- Yoco checkout ID / PayFast m_payment_id
    amount       DECIMAL(10,2)    NOT NULL,
    currency     CHAR(3)          NOT NULL DEFAULT 'ZAR',
    status       ENUM('pending','paid','cancelled','expired') NOT NULL DEFAULT 'pending',
    payment_url  TEXT             NOT NULL,             -- URL shared with customer
    token        VARCHAR(64)      NOT NULL,             -- secure token for our callback pages
    created_by   INT UNSIGNED     NULL DEFAULT NULL,
    created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at   DATETIME         NULL DEFAULT NULL,
    paid_at      DATETIME         NULL DEFAULT NULL,
    INDEX  idx_pl_invoice (invoice_id),
    UNIQUE KEY uq_pl_token (token),
    CONSTRAINT fk_pl_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_pl_user    FOREIGN KEY (created_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
