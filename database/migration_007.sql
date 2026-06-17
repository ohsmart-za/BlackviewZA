-- ============================================================
-- Blackview SA Portal — Migration 007
-- Dynamic Payment Methods
-- Run once. Safe to re-run (IF NOT EXISTS / INSERT IGNORE).
-- ============================================================

CREATE TABLE IF NOT EXISTS payment_methods (
    id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(50)     NOT NULL UNIQUE,
    name        VARCHAR(100)    NOT NULL,
    icon        VARCHAR(20)     NOT NULL DEFAULT '💳',
    is_active   TINYINT(1)      NOT NULL DEFAULT 1,
    sort_order  INT             NOT NULL DEFAULT 0,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Seed the three built-in methods (safe to re-run)
INSERT IGNORE INTO payment_methods (code, name, icon, sort_order) VALUES
    ('cash', 'Cash', '💵', 1),
    ('eft',  'EFT',  '🏦', 2),
    ('card', 'Card', '💳', 3);

-- Nav link (run once)
INSERT IGNORE INTO nav_links (label, url, icon, parent_label, sort_order, roles)
VALUES ('Payment Methods', '/admin/payment_methods.php', '💳', 'Admin', 60, 'admin');
