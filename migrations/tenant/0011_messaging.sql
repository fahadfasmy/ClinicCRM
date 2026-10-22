-- Phase 10: messaging and automations. Message templates (WhatsApp / SMS / email, English and Arabic; a template that
-- carries health information needs the clinic owner's acknowledgement), every message in and out (one row each, with an
-- idempotency key so an automation never sends the same thing twice), the automations' switches and timing, patient
-- self-service links (confirm / cancel / ask to reschedule), feedback, staff notifications and a rate-limit counter for
-- the public pages. The default templates are created by the code the first time they are needed.

CREATE TABLE IF NOT EXISTS message_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel ENUM('whatsapp','sms','email') NOT NULL,
    purpose VARCHAR(30) NOT NULL,
    name VARCHAR(100) NOT NULL,
    language ENUM('en','ar') NOT NULL DEFAULT 'en',
    subject VARCHAR(150) NULL,
    body TEXT NOT NULL,
    variables JSON NULL,
    wa_template_name VARCHAR(100) NULL,
    wa_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
    contains_health_info TINYINT(1) NOT NULL DEFAULT 0,
    health_ack_by INT UNSIGNED NULL,
    health_ack_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_purpose (purpose, channel, language)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel ENUM('whatsapp','sms','email') NOT NULL,
    direction ENUM('in','out') NOT NULL,
    patient_id INT UNSIGNED NULL,
    lead_id INT UNSIGNED NULL,
    appointment_id INT UNSIGNED NULL,
    to_from VARCHAR(150) NOT NULL,
    template_id INT UNSIGNED NULL,
    body TEXT NOT NULL,
    status ENUM('queued','sent','delivered','read','failed','skipped','received') NOT NULL DEFAULT 'queued',
    provider_id VARCHAR(120) NULL,
    error VARCHAR(500) NULL,
    automation_key VARCHAR(40) NULL,
    idempotency_key VARCHAR(120) NULL,
    staff_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    status_at DATETIME NULL,
    UNIQUE KEY uq_provider (provider_id),
    UNIQUE KEY uq_idem (idempotency_key),
    KEY idx_patient (patient_id, created_at),
    KEY idx_lead (lead_id, created_at),
    KEY idx_to_from (to_from, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automations (
    `key` VARCHAR(40) NOT NULL PRIMARY KEY,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    config JSON NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feedback (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT UNSIGNED NOT NULL,
    patient_id INT UNSIGNED NULL,
    token_hash CHAR(64) NOT NULL,
    score TINYINT UNSIGNED NULL,
    comment VARCHAR(1000) NULL,
    submitted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_appointment (appointment_id),
    UNIQUE KEY uq_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id INT UNSIGNED NULL COMMENT 'null = everyone with the permission below',
    permission VARCHAR(40) NULL,
    kind VARCHAR(30) NOT NULL,
    title VARCHAR(200) NOT NULL,
    body VARCHAR(500) NULL,
    link VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    read_by INT UNSIGNED NULL,
    KEY idx_open (read_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    bucket VARCHAR(120) NOT NULL PRIMARY KEY,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    window_start DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE appointments
    ADD COLUMN IF NOT EXISTS self_token_hash CHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS reschedule_requested_at DATETIME NULL;
