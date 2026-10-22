-- Phase 3: the master patient record, linked to the lead it came from. Emirates ID and passport are stored encrypted
-- with the clinic's data key (eid_hash is a keyed hash for exact duplicate checks and search; eid_last4 for display).
-- Also the append-only audit log and the patient access log. Lead data is never changed here.

CREATE TABLE IF NOT EXISTS patients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mrn VARCHAR(20) NOT NULL,
    lead_id INT UNSIGNED NULL,
    branch_id INT UNSIGNED NULL,
    title VARCHAR(20) NULL,
    first_name VARCHAR(80) NOT NULL,
    middle_name VARCHAR(80) NULL,
    last_name VARCHAR(80) NULL,
    name_ar VARCHAR(160) NULL,
    gender ENUM('female','male','other','unknown') NOT NULL DEFAULT 'unknown',
    dob DATE NULL,
    nationality CHAR(2) NULL,
    residency ENUM('resident','citizen','visitor','unknown') NOT NULL DEFAULT 'unknown',
    emirate VARCHAR(30) NULL,
    marital_status VARCHAR(20) NULL,
    occupation VARCHAR(100) NULL,
    email VARCHAR(190) NULL,
    eid_enc VARBINARY(255) NULL,
    eid_hash CHAR(64) NULL,
    eid_last4 CHAR(4) NULL,
    eid_expiry DATE NULL,
    passport_enc VARBINARY(255) NULL,
    emergency_name VARCHAR(120) NULL,
    emergency_phone VARCHAR(30) NULL,
    emergency_relation VARCHAR(40) NULL,
    referred_by_patient_id INT UNSIGNED NULL,
    source_id INT UNSIGNED NULL,
    campaign_id INT UNSIGNED NULL,
    marketing_consent TINYINT(1) NOT NULL DEFAULT 0,
    marketing_consent_at DATETIME NULL,
    whatsapp_opt_in TINYINT(1) NOT NULL DEFAULT 0,
    whatsapp_opt_in_at DATETIME NULL,
    data_consent_at DATETIME NULL,
    preferred_language ENUM('en','ar') NOT NULL DEFAULT 'en',
    tags VARCHAR(255) NULL,
    status ENUM('active','inactive','deceased','merged','anonymised') NOT NULL DEFAULT 'active',
    merged_into_id INT UNSIGNED NULL,
    notes TEXT NULL,
    photo_path VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mrn (mrn),
    UNIQUE KEY uq_lead (lead_id),
    KEY idx_eid_hash (eid_hash),
    KEY idx_eid_last4 (eid_last4),
    KEY idx_dob (dob),
    KEY idx_status (status),
    KEY idx_branch (branch_id),
    FULLTEXT KEY ft_name (first_name, last_name, name_ar)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phone numbers in E.164 (+9715…); the same number on two patients is allowed (families) but flagged
CREATE TABLE IF NOT EXISTS patient_phones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    phone VARCHAR(20) NOT NULL,
    label VARCHAR(20) NOT NULL DEFAULT 'mobile',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_patient_phone (patient_id, phone),
    KEY idx_phone (phone),
    CONSTRAINT fk_pphone_patient FOREIGN KEY (patient_id) REFERENCES patients (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ID scans, insurance cards, reports. Files live in the clinic's storage folder (outside the web root), served by download.php
CREATE TABLE IF NOT EXISTS patient_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    type ENUM('eid_front','eid_back','passport','insurance_card','report','other') NOT NULL DEFAULT 'other',
    path VARCHAR(255) NOT NULL,
    original_name VARCHAR(190) NOT NULL DEFAULT '',
    mime VARCHAR(60) NOT NULL,
    size INT UNSIGNED NOT NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_patient (patient_id),
    CONSTRAINT fk_pdoc_patient FOREIGN KEY (patient_id) REFERENCES patients (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who changed what (patients now; invoices, notes, settings later). Rows are only ever added, never changed.
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity VARCHAR(40) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL,
    changes JSON NULL,
    staff_id INT UNSIGNED NULL,
    ip VARCHAR(45) NULL,
    at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_entity (entity, entity_id),
    KEY idx_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every opening of a patient's chart, document or photo
CREATE TABLE IF NOT EXISTS patient_access_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    encounter_id INT UNSIGNED NULL,
    what VARCHAR(40) NOT NULL,
    staff_id INT UNSIGNED NULL,
    ip VARCHAR(45) NULL,
    at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_patient_at (patient_id, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE leads ADD COLUMN patient_id INT UNSIGNED NULL, ADD KEY idx_patient (patient_id);

ALTER TABLE appointments ADD COLUMN patient_id INT UNSIGNED NULL, ADD KEY idx_patient (patient_id);
