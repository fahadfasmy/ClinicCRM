-- Phase 8: document layouts (the drag-and-drop designer). A layout is rows of blocks on a 12-column grid, kept as
-- validated JSON. Publishing makes an immutable version; issued documents store the version they were rendered with,
-- and re-renders add renditions without touching the original. The starter layouts are created by the code
-- (includes/doc_layouts.php) the first time they are needed.

CREATE TABLE IF NOT EXISTS document_layouts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_type ENUM('invoice','receipt','credit_note','prescription','visit_summary') NOT NULL,
    name VARCHAR(100) NOT NULL,
    language ENUM('en','ar','both') NOT NULL DEFAULT 'both',
    invoice_type ENUM('any','cash','insurance') NOT NULL DEFAULT 'any',
    branch_id INT UNSIGNED NULL,
    page_size ENUM('A4','A5','Letter','roll80') NOT NULL DEFAULT 'A4',
    orientation ENUM('portrait','landscape') NOT NULL DEFAULT 'portrait',
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    draft_json MEDIUMTEXT NOT NULL,
    draft_saved_at DATETIME NULL,
    current_version_id INT UNSIGNED NULL,
    starter VARCHAR(20) NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_type (document_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Never updated or deleted
CREATE TABLE IF NOT EXISTS document_layout_versions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    layout_id INT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    layout_json MEDIUMTEXT NOT NULL,
    content_hash CHAR(64) NOT NULL,
    published_by INT UNSIGNED NULL,
    published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_version (layout_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Logo, stamp and signature images used by layouts (files in the clinic's storage folder)
CREATE TABLE IF NOT EXISTS document_layout_assets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    path VARCHAR(255) NOT NULL,
    mime VARCHAR(50) NOT NULL,
    size INT UNSIGNED NOT NULL,
    width SMALLINT UNSIGNED NOT NULL,
    height SMALLINT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE document_renditions
    ADD COLUMN IF NOT EXISTS layout_version_id INT UNSIGNED NULL AFTER layout,
    ADD COLUMN IF NOT EXISTS language ENUM('en','ar','both') NULL AFTER layout_version_id,
    ADD COLUMN IF NOT EXISTS content_hash CHAR(64) NULL AFTER html,
    ADD COLUMN IF NOT EXISTS reason ENUM('issue','rerender','resend') NOT NULL DEFAULT 'issue' AFTER is_original;
