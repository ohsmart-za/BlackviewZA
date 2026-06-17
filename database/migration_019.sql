-- migration_019: Add contact_type / company_name / vat_no to quotes table
-- Also adds customer_id link so quotes can reference an existing customer

ALTER TABLE quotes
    ADD COLUMN IF NOT EXISTS customer_type     ENUM('individual','business') NOT NULL DEFAULT 'individual' AFTER customer_id_number,
    ADD COLUMN IF NOT EXISTS customer_company  VARCHAR(150) NULL DEFAULT NULL AFTER customer_type,
    ADD COLUMN IF NOT EXISTS customer_vat_no   VARCHAR(50)  NULL DEFAULT NULL AFTER customer_company,
    ADD COLUMN IF NOT EXISTS customer_id       INT UNSIGNED NULL DEFAULT NULL AFTER id;

-- Index for customer link
ALTER TABLE quotes
    ADD INDEX IF NOT EXISTS idx_quotes_customer_id (customer_id);
