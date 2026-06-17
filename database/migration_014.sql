-- ============================================================
-- Migration 014 — Auth method + Google SSO support
-- ============================================================

-- Add auth_method and google_id columns to users
ALTER TABLE users
    ADD COLUMN auth_method ENUM('local','google') NOT NULL DEFAULT 'local' AFTER role,
    ADD COLUMN google_id   VARCHAR(100)            NULL     DEFAULT NULL   AFTER auth_method;

CREATE INDEX idx_users_google_id ON users (google_id);
