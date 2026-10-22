-- Phase 11: CRM upgrades. Campaigns (source + campaign + ad set + ad, with a budget) that leads and patients point to,
-- recall rules and the recall list, broadcasts to consented patients, one-time codes for the online booking page, and
-- which treatments can be booked online. Meta "Schedule" / "Purchase" events reuse meta_capi_events (its value column
-- is added by includes/meta_leads.php, which owns that table).

CREATE TABLE IF NOT EXISTS campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id INT UNSIGNED NULL,
    source VARCHAR(50) NULL COMMENT 'lead source slug',
    name VARCHAR(150) NOT NULL,
    meta_campaign_id VARCHAR(40) NULL,
    meta_adset_id VARCHAR(40) NULL,
    meta_ad_id VARCHAR(40) NULL,
    budget DECIMAL(12,2) NULL,
    starts_on DATE NULL,
    ends_on DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_source_name (source, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lead sources were a fixed list (ENUM), so custom sources from the Lead Sources page (and "online_booking") were lost
ALTER TABLE leads MODIFY source VARCHAR(60) NOT NULL DEFAULT 'other';
ALTER TABLE leads ADD COLUMN IF NOT EXISTS campaign_id INT UNSIGNED NULL, ADD INDEX IF NOT EXISTS idx_campaign (campaign_id);

CREATE TABLE IF NOT EXISTS recall_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    kind ENUM('not_visited','service_interval','package_left') NOT NULL,
    service_id INT UNSIGNED NULL,
    days_after SMALLINT UNSIGNED NOT NULL,
    send_message TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recalls (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    rule_id INT UNSIGNED NOT NULL,
    due_on DATE NOT NULL,
    status ENUM('open','contacted','booked','dismissed') NOT NULL DEFAULT 'open',
    assigned_to INT UNSIGNED NULL,
    note VARCHAR(255) NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_open (status, assigned_to),
    KEY idx_patient_rule (patient_id, rule_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS broadcasts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    template_id INT UNSIGNED NOT NULL,
    segment JSON NOT NULL,
    status ENUM('draft','sending','sent','cancelled') NOT NULL DEFAULT 'draft',
    recipients INT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    finished_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS otp_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(20) NOT NULL,
    purpose VARCHAR(20) NOT NULL,
    code_hash CHAR(64) NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_phone (phone, purpose, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE services ADD COLUMN IF NOT EXISTS is_online_bookable TINYINT(1) NOT NULL DEFAULT 0;
