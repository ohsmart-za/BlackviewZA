-- ============================================================
-- Migration 017 — Company Documents library
-- ============================================================

CREATE TABLE IF NOT EXISTS company_documents (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(200)  NOT NULL,
    category    VARCHAR(100)  NOT NULL DEFAULT 'General',
    description TEXT          NULL,
    file_path   VARCHAR(500)  NOT NULL,
    file_name   VARCHAR(255)  NOT NULL,
    file_size   INT UNSIGNED  NOT NULL DEFAULT 0,
    mime_type   VARCHAR(120)  NOT NULL DEFAULT '',
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  INT          NOT NULL DEFAULT 0,
    uploaded_by INT UNSIGNED  NULL,
    uploaded_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_docs_category (category),
    INDEX idx_docs_active   (is_active),
    CONSTRAINT fk_docs_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nav link for all users
INSERT INTO nav_links (label, url, icon_class, role_required, display_order, is_active)
VALUES ('Company Docs', '/docs/index.php', 'icon-docs', 'user', 20, 1);
