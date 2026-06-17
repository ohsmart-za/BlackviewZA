-- ============================================================
-- Migration 016 — Void status for invoices
-- ============================================================

-- Add status column to invoices (active | voided)
ALTER TABLE invoices
    ADD COLUMN status ENUM('active','voided') NOT NULL DEFAULT 'active',
    ADD COLUMN voided_by INT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN voided_at DATETIME NULL DEFAULT NULL,
    ADD COLUMN void_reason TEXT NULL DEFAULT NULL;
