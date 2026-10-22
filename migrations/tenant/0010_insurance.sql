-- Phase 9: insurance. Insurance companies (payers and TPAs) → plans (co-pay %, deductible, cap, services needing
-- approval) → each plan's price list. Patient insurance cards. Prior approvals. Insurance invoices split every line
-- into patient share and insurer share. Claims (one per insurance invoice), batches exported as eClaimLink XML / CSV,
-- remittances recorded as insurer receipts, denial codes. Cash prices stay services.price.

CREATE TABLE IF NOT EXISTS insurance_companies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    type ENUM('payer','tpa') NOT NULL DEFAULT 'payer',
    payer_id VARCHAR(30) NULL COMMENT 'eClaimLink payer licence (e.g. INS026)',
    receiver_id VARCHAR(30) NULL COMMENT 'eClaimLink receiver (the TPA when one is used)',
    phone VARCHAR(40) NULL,
    email VARCHAR(150) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS insurance_plans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    network VARCHAR(80) NULL,
    copay_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    deductible DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'paid by the patient first, per visit',
    max_copay DECIMAL(12,2) NULL COMMENT 'most the patient pays per visit',
    preauth_service_ids JSON NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A plan's own prices (anything not listed is charged at the cash price)
CREATE TABLE IF NOT EXISTS price_lists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    insurance_plan_id INT UNSIGNED NULL,
    valid_from DATE NULL,
    valid_to DATE NULL,
    UNIQUE KEY uq_plan (insurance_plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS price_list_items (
    price_list_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    cpt_code VARCHAR(10) NULL,
    PRIMARY KEY (price_list_id, service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_insurance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    plan_id INT UNSIGNED NOT NULL,
    member_no VARCHAR(60) NOT NULL,
    policy_no VARCHAR(60) NULL,
    valid_from DATE NULL,
    valid_to DATE NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 1,
    card_front_doc_id INT UNSIGNED NULL,
    card_back_doc_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_patient (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prior_auths (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    patient_insurance_id INT UNSIGNED NULL,
    plan_id INT UNSIGNED NOT NULL,
    appointment_id INT UNSIGNED NULL,
    services JSON NOT NULL,
    diagnosis_codes JSON NULL,
    status ENUM('requested','approved','partially_approved','rejected','expired','cancelled') NOT NULL DEFAULT 'requested',
    approval_no VARCHAR(60) NULL,
    approved_amount DECIMAL(12,2) NULL,
    valid_until DATE NULL,
    requested_by INT UNSIGNED NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME NULL,
    notes VARCHAR(500) NULL,
    KEY idx_patient (patient_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS claim_batches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    exported_at DATETIME NULL,
    submitted_at DATETIME NULL,
    submission_ref VARCHAR(100) NULL,
    file_path VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS claims (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(40) NOT NULL,
    invoice_id INT UNSIGNED NOT NULL,
    plan_id INT UNSIGNED NOT NULL,
    patient_insurance_id INT UNSIGNED NULL,
    batch_id INT UNSIGNED NULL,
    status ENUM('draft','ready','submitted','accepted','rejected','partially_paid','paid','resubmitted','written_off') NOT NULL DEFAULT 'draft',
    claimed DECIMAL(12,2) NOT NULL,
    approved DECIMAL(12,2) NULL,
    paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    denial_code VARCHAR(20) NULL,
    submitted_at DATETIME NULL,
    submission_ref VARCHAR(100) NULL,
    remitted_at DATETIME NULL,
    notes VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_number (number),
    UNIQUE KEY uq_invoice (invoice_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS claim_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    claim_id INT UNSIGNED NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NOT NULL,
    amount DECIMAL(12,2) NULL,
    note VARCHAR(500) NULL,
    staff_id INT UNSIGNED NULL,
    at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_claim (claim_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS denial_codes (
    code VARCHAR(20) NOT NULL PRIMARY KEY,
    description VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS patient_insurance_id INT UNSIGNED NULL AFTER insurance_plan_id,
    ADD COLUMN IF NOT EXISTS insurer_paid DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'part of paid that came from the insurer (remittances)' AFTER paid;

ALTER TABLE invoice_lines ADD COLUMN IF NOT EXISTS covered TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'insurance invoices: 0 = the patient pays this line in full';

ALTER TABLE appointments
    ADD COLUMN IF NOT EXISTS eligibility_ref VARCHAR(60) NULL,
    ADD COLUMN IF NOT EXISTS prior_auth_id INT UNSIGNED NULL;

ALTER TABLE services ADD COLUMN IF NOT EXISTS requires_prior_auth TINYINT(1) NOT NULL DEFAULT 0;
