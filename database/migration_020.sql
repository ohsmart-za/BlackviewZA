-- migration_020: Widen payment_method columns from ENUM to VARCHAR
-- Allows any code registered in the payment_methods table (e.g. payfast, makro, layby).
-- Safe to re-run — MODIFY COLUMN is idempotent if already VARCHAR(50).

-- invoices header: was ENUM('cash','eft','card') in the original schema
ALTER TABLE invoices
    MODIFY COLUMN payment_method VARCHAR(50) NOT NULL DEFAULT 'cash';

-- invoice_payments: was ENUM('cash','eft','card','credit_note') from migration_005
ALTER TABLE invoice_payments
    MODIFY COLUMN payment_method VARCHAR(50) NOT NULL DEFAULT 'cash';
