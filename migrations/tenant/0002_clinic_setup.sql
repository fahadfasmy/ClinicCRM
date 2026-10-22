-- Phase 2: branches, departments, rooms, practitioner profiles and weekly schedules, time blocks, gapless numbering,
-- and three new staff roles (nurse, finance, insurance) plus each person's home branch and language.
-- Every statement is safe to run again; the one ALTER is last, so a stop half-way never leaves it half-done.

CREATE TABLE IF NOT EXISTS branches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    name_ar VARCHAR(120) NULL,
    dha_license VARCHAR(40) NULL,
    trn VARCHAR(20) NULL,
    address VARCHAR(255) NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    working_hours JSON NULL,                    -- {"0":["09:00","21:00"], …} by weekday 0 = Sunday; a missing day = closed
    invoice_prefix VARCHAR(10) NOT NULL,        -- document numbers: PREFIX-INV-2026-000123
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_prefix (invoice_prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS departments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    name_ar VARCHAR(100) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rooms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    type ENUM('consultation','procedure','laser','dental','other') NOT NULL DEFAULT 'consultation',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_branch_name (branch_id, name),
    CONSTRAINT fk_room_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS practitioner_profiles (
    staff_id INT UNSIGNED PRIMARY KEY,
    dha_license VARCHAR(40) NULL,
    specialty VARCHAR(100) NULL,
    department_id INT UNSIGNED NULL,
    color CHAR(7) NOT NULL DEFAULT '#b8925a',
    signature_path VARCHAR(255) NULL,
    is_bookable TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pp_staff FOREIGN KEY (staff_id) REFERENCES staff (id),
    CONSTRAINT fk_pp_department FOREIGN KEY (department_id) REFERENCES departments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Weekly working hours: one row per stretch (two rows on a day = a split shift)
CREATE TABLE IF NOT EXISTS practitioner_schedules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id INT UNSIGNED NOT NULL,
    branch_id INT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,          -- 0 = Sunday … 6 = Saturday
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_staff_day (staff_id, weekday),
    CONSTRAINT fk_ps_staff FOREIGN KEY (staff_id) REFERENCES staff (id),
    CONSTRAINT fk_ps_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Leave, breaks, meetings, room maintenance. staff_id and room_id both empty = the whole branch is closed.
CREATE TABLE IF NOT EXISTS schedule_blocks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id INT UNSIGNED NULL,
    room_id INT UNSIGNED NULL,
    branch_id INT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    reason VARCHAR(150) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_staff_time (staff_id, starts_at),
    KEY idx_branch_time (branch_id, starts_at),
    CONSTRAINT fk_sb_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gapless document numbers: the row is locked by the issuing transaction, so two cashiers never get the same number
-- and a rolled-back invoice gives its number back. branch_id 0 / year 0 = one sequence for the whole clinic (MRN).
CREATE TABLE IF NOT EXISTS number_sequences (
    type ENUM('mrn','invoice','receipt','credit_note','claim') NOT NULL,
    branch_id INT UNSIGNED NOT NULL DEFAULT 0,
    year SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_no INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (type, branch_id, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every clinic starts with one branch, renamed on Clinic setup
INSERT INTO branches (name, invoice_prefix) SELECT 'Main branch', 'MAIN' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM branches);

ALTER TABLE staff
    MODIFY role ENUM('admin','consultant','receptionist','inventory','practitioner','nurse','finance','insurance') NOT NULL DEFAULT 'consultant',
    ADD COLUMN branch_id INT UNSIGNED NULL AFTER role,
    ADD COLUMN language ENUM('en','ar') NOT NULL DEFAULT 'en' AFTER branch_id;
