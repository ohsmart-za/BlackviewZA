-- ============================================================
-- Blackview SA Portal — Migration 002
-- Settings table + product image/featured columns
-- ============================================================

-- Settings table
CREATE TABLE IF NOT EXISTS settings (
  `key`       VARCHAR(100) NOT NULL,
  `value`     TEXT,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (`key`, `value`) VALUES
('company_name',    'Blackview SA'),
('company_tagline', 'Authorised Blackview Distributor'),
('company_vat_no',  ''),
('company_address', ''),
('company_email',   ''),
('company_phone',   ''),
('logo_path',       '')
ON DUPLICATE KEY UPDATE `key` = `key`;

-- Product image + featured flag
ALTER TABLE products
  ADD COLUMN image_path  VARCHAR(255) NULL AFTER vat_rate,
  ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0 AFTER image_path;

-- Nav link for Settings page (run only if nav_links table exists)
INSERT IGNORE INTO nav_links (label, url, icon, min_role, sort_order)
VALUES ('Settings', '/admin/settings.php', 'settings', 'superuser', 90);
