-- Phase 5: clinical records (EMR). One encounter per visit (or a walk-in), with vitals, the doctor's note, ICD-10
-- diagnoses, procedures, prescriptions, aesthetic data (injection map, laser settings, skin type) in data JSON, and the
-- FDI dental chart. Allergies and history live with the patient and carry forward. Signing stores a SHA-256 of the
-- content and makes the note read-only; corrections are addenda. Clinic settings: note templates, required sections.

CREATE TABLE IF NOT EXISTS allergies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    allergen VARCHAR(150) NOT NULL,
    type ENUM('drug','food','environmental','other') NOT NULL DEFAULT 'drug',
    reaction VARCHAR(255) NULL,
    severity ENUM('mild','moderate','severe') NOT NULL DEFAULT 'moderate',
    status ENUM('active','resolved') NOT NULL DEFAULT 'active',
    recorded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_patient (patient_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    type ENUM('medical','surgical','family','social','medication') NOT NULL,
    detail TEXT NOT NULL,
    recorded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_patient (patient_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS encounters (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    appointment_id INT UNSIGNED NULL,
    branch_id INT UNSIGNED NULL,
    practitioner_id INT UNSIGNED NULL,
    department_id INT UNSIGNED NULL,
    status ENUM('open','signed','amended') NOT NULL DEFAULT 'open',
    chief_complaint TEXT NULL,
    hpi TEXT NULL,
    examination TEXT NULL,
    assessment TEXT NULL,
    plan TEXT NULL,
    followup_instruction TEXT NULL,
    allergies_reviewed TINYINT(1) NOT NULL DEFAULT 0,
    data JSON NULL,
    created_by INT UNSIGNED NULL,
    signed_by INT UNSIGNED NULL,
    signed_at DATETIME NULL,
    content_hash CHAR(64) NULL,
    locked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_appointment (appointment_id),
    KEY idx_patient (patient_id, created_at),
    KEY idx_practitioner (practitioner_id, created_at),
    KEY idx_open (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vitals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    encounter_id INT UNSIGNED NOT NULL,
    bp_sys SMALLINT UNSIGNED NULL,
    bp_dia SMALLINT UNSIGNED NULL,
    pulse SMALLINT UNSIGNED NULL,
    temp_c DECIMAL(4,1) NULL,
    resp_rate SMALLINT UNSIGNED NULL,
    spo2 TINYINT UNSIGNED NULL,
    weight_kg DECIMAL(5,2) NULL,
    height_cm DECIMAL(5,1) NULL,
    bmi DECIMAL(4,1) NULL,
    pain_score TINYINT UNSIGNED NULL,
    recorded_by INT UNSIGNED NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_encounter (encounter_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ICD-10-CM codes. Multi-clinic mode reads them from the shared reference database; a single clinic keeps its own copy
-- here (both imported with Admin → Clinical settings → ICD-10 import).
CREATE TABLE IF NOT EXISTS icd10_codes (
    code VARCHAR(10) PRIMARY KEY,
    description VARCHAR(255) NOT NULL,
    chapter VARCHAR(10) NULL,
    FULLTEXT KEY ft_description (description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS encounter_diagnoses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    encounter_id INT UNSIGNED NOT NULL,
    icd10_code VARCHAR(10) NOT NULL,
    description VARCHAR(255) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    kind ENUM('provisional','final') NOT NULL DEFAULT 'final',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_encounter (encounter_id),
    KEY idx_code (icd10_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS encounter_procedures (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    encounter_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NULL,
    description VARCHAR(150) NOT NULL,
    cpt_code VARCHAR(10) NULL,
    area VARCHAR(80) NULL,
    tooth VARCHAR(10) NULL,
    qty DECIMAL(8,2) NOT NULL DEFAULT 1,
    performed_by INT UNSIGNED NULL,
    notes VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_encounter (encounter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prescriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    encounter_id INT UNSIGNED NOT NULL,
    patient_id INT UNSIGNED NOT NULL,
    prescribed_by INT UNSIGNED NULL,
    notes VARCHAR(500) NULL,
    printed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_encounter (encounter_id),
    KEY idx_patient (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prescription_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    prescription_id INT UNSIGNED NOT NULL,
    drug VARCHAR(150) NOT NULL,
    strength VARCHAR(60) NULL,
    dose VARCHAR(60) NULL,
    route VARCHAR(40) NULL,
    frequency VARCHAR(60) NULL,
    duration VARCHAR(60) NULL,
    qty VARCHAR(40) NULL,
    instructions VARCHAR(255) NULL,
    KEY idx_rx (prescription_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FDI tooth numbers (11–48 permanent, 51–85 primary). One row per finding / planned / done procedure.
CREATE TABLE IF NOT EXISTS dental_chart (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    tooth VARCHAR(4) NOT NULL,
    surfaces SET('M','O','D','B','L','I') NULL,
    entry_type ENUM('condition','planned','done') NOT NULL DEFAULT 'condition',
    code VARCHAR(60) NOT NULL,
    note VARCHAR(255) NULL,
    encounter_id INT UNSIGNED NULL,
    recorded_by INT UNSIGNED NULL,
    at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_patient_tooth (patient_id, tooth)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Corrections to a signed note (the note itself never changes)
CREATE TABLE IF NOT EXISTS encounter_amendments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    encounter_id INT UNSIGNED NOT NULL,
    staff_id INT UNSIGNED NULL,
    reason VARCHAR(255) NOT NULL,
    text TEXT NOT NULL,
    at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_encounter (encounter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS note_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    department_id INT UNSIGNED NULL,
    service_id INT UNSIGNED NULL,
    fields JSON NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sections that must be filled before a note can be signed, per department (department 0 = the clinic default)
CREATE TABLE IF NOT EXISTS emr_requirements (
    department_id INT UNSIGNED NOT NULL,
    section VARCHAR(30) NOT NULL,
    PRIMARY KEY (department_id, section)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO emr_requirements (department_id, section) VALUES (0, 'vitals'), (0, 'allergies'), (0, 'complaint'), (0, 'diagnosis'), (0, 'procedure'), (0, 'notes');
