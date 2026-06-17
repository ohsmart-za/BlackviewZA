-- ============================================================
-- Migration 009 — Invoice Editing + CRM
-- ============================================================

-- 1. Permission flag on users
ALTER TABLE users
    ADD COLUMN can_edit_invoices TINYINT(1) NOT NULL DEFAULT 0 AFTER role;

-- 2. Unlock/edit tracking on invoices
ALTER TABLE invoices
    ADD COLUMN edit_unlocked     TINYINT(1)   NOT NULL DEFAULT 0    AFTER vat_display_mode,
    ADD COLUMN edit_unlocked_by  INT UNSIGNED NULL     DEFAULT NULL  AFTER edit_unlocked,
    ADD COLUMN edit_unlocked_at  DATETIME     NULL     DEFAULT NULL  AFTER edit_unlocked_by,
    ADD COLUMN last_edited_by    INT UNSIGNED NULL     DEFAULT NULL  AFTER edit_unlocked_at,
    ADD COLUMN last_edited_at    DATETIME     NULL     DEFAULT NULL  AFTER last_edited_by;

-- 3. Extend customers table for CRM
ALTER TABLE customers
    ADD COLUMN contact_type  ENUM('individual','business') NOT NULL DEFAULT 'individual' AFTER id_number,
    ADD COLUMN company_name  VARCHAR(150) NULL DEFAULT NULL  AFTER contact_type,
    ADD COLUMN vat_no        VARCHAR(50)  NULL DEFAULT NULL  AFTER company_name,
    ADD COLUMN notes         TEXT         NULL DEFAULT NULL  AFTER vat_no,
    ADD COLUMN created_by    INT UNSIGNED NULL DEFAULT NULL  AFTER notes;

-- 4. Add CRM nav link at top of sidebar (shift existing links down first)
UPDATE nav_links SET display_order = display_order + 10;
INSERT INTO nav_links (label, url, icon_class, role_required, display_order, is_active)
VALUES ('CRM', '/crm/index.php', 'icon-crm', 'user', 1, 1);
