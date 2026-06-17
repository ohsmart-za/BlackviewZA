-- migration_022: Sales invoice interface + POS permissions
-- Adds: can_use_pos on users, draft status, invoice_date/due_date, nav link

-- 1. POS access flag on users (default 1 = everyone keeps access)
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS can_use_pos TINYINT(1) NOT NULL DEFAULT 1 AFTER can_edit_invoices;

-- 2. Add 'draft' to invoices status ENUM
ALTER TABLE invoices
    MODIFY COLUMN status ENUM('draft','active','voided') NOT NULL DEFAULT 'active';

-- 3. Additional columns on invoices for the sales interface
ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS invoice_date   DATE         NULL DEFAULT NULL AFTER created_at,
    ADD COLUMN IF NOT EXISTS due_date       DATE         NULL DEFAULT NULL AFTER invoice_date,
    ADD COLUMN IF NOT EXISTS finalised_at   DATETIME     NULL DEFAULT NULL AFTER due_date,
    ADD COLUMN IF NOT EXISTS finalised_by   INT UNSIGNED NULL DEFAULT NULL AFTER finalised_at;

-- 4. Add nav link for the new Sales Invoice page
INSERT IGNORE INTO nav_links (label, url, icon_class, role_required, display_order, is_active)
VALUES ('New Invoice', '/invoices/create.php', '', 'user', 22, 1);

-- 5. Add nav link for Customer Statement (admin only)
INSERT IGNORE INTO nav_links (label, url, icon_class, role_required, display_order, is_active)
VALUES ('Customer Statement', '/admin/customer_statement.php', '', 'admin', 95, 1);
