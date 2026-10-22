-- Phase 7: billing. Invoices (draft → issued → partially paid → paid, or void) with per-line VAT, receipts that mix
-- payment methods and are allocated across invoices, the patient wallet (a ledger: the balance is always its sum),
-- credit notes, packages and their sessions, vouchers, discount rules, day close and commission rules. Issued documents
-- keep a frozen copy of the printed page (document_renditions). Money is DECIMAL(12,2); the code works in fils.
-- The cash price of a service stays services.price; price lists per insurance plan arrive with insurance (Phase 9).

ALTER TABLE services
    ADD COLUMN IF NOT EXISTS name_ar VARCHAR(120) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS vat_rate DECIMAL(5,2) NOT NULL DEFAULT 5.00 AFTER price,
    ADD COLUMN IF NOT EXISTS cpt_code VARCHAR(10) NULL AFTER vat_rate;

ALTER TABLE products
    ADD COLUMN IF NOT EXISTS sell_price DECIMAL(10,2) NULL COMMENT 'retail price per stock unit; empty = not sold',
    ADD COLUMN IF NOT EXISTS vat_rate DECIMAL(5,2) NOT NULL DEFAULT 5.00;

ALTER TABLE appointments ADD COLUMN IF NOT EXISTS patient_package_id INT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(40) NULL,
    branch_id INT UNSIGNED NOT NULL,
    patient_id INT UNSIGNED NOT NULL,
    appointment_id INT UNSIGNED NULL,
    encounter_id INT UNSIGNED NULL,
    type ENUM('cash','insurance') NOT NULL DEFAULT 'cash',
    insurance_plan_id INT UNSIGNED NULL,
    prior_auth_id INT UNSIGNED NULL,
    status ENUM('draft','issued','partially_paid','paid','void') NOT NULL DEFAULT 'draft',
    vat_inclusive TINYINT(1) NOT NULL DEFAULT 0,
    issued_at DATETIME NULL,
    gross DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0,
    net DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'taxable amount',
    vat DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    patient_share DECIMAL(12,2) NOT NULL DEFAULT 0,
    insurance_share DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'from receipt allocations, recalculated',
    credited DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'from credit notes, recalculated',
    refunded DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'paid money given back or moved to the wallet by credit notes',
    void_reason VARCHAR(255) NULL,
    voided_by INT UNSIGNED NULL,
    voided_at DATETIME NULL,
    issued_by INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    notes VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_number (number),
    KEY idx_patient (patient_id, status),
    KEY idx_appointment (appointment_id),
    KEY idx_issued (issued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    kind ENUM('service','package','product','manual') NOT NULL,
    service_id INT UNSIGNED NULL,
    package_id INT UNSIGNED NULL,
    product_id INT UNSIGNED NULL,
    patient_package_id INT UNSIGNED NULL COMMENT 'a session taken from this package',
    practitioner_id INT UNSIGNED NULL,
    description VARCHAR(200) NOT NULL,
    cpt_code VARCHAR(10) NULL,
    qty DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_rule_id INT UNSIGNED NULL,
    taxable DECIMAL(12,2) NOT NULL DEFAULT 0,
    vat_rate DECIMAL(5,2) NOT NULL DEFAULT 5.00,
    vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    patient_share DECIMAL(12,2) NOT NULL DEFAULT 0,
    insurance_share DECIMAL(12,2) NOT NULL DEFAULT 0,
    KEY idx_invoice (invoice_id),
    KEY idx_practitioner (practitioner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS receipts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(40) NOT NULL,
    branch_id INT UNSIGNED NOT NULL,
    patient_id INT UNSIGNED NULL,
    voucher_id INT UNSIGNED NULL COMMENT 'set when the receipt is the sale of a voucher',
    total DECIMAL(12,2) NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    received_by INT UNSIGNED NULL,
    day_close_id INT UNSIGNED NULL,
    status ENUM('valid','void') NOT NULL DEFAULT 'valid',
    void_reason VARCHAR(255) NULL,
    voided_by INT UNSIGNED NULL,
    voided_at DATETIME NULL,
    notes VARCHAR(255) NULL,
    UNIQUE KEY uq_number (number),
    KEY idx_patient (patient_id),
    KEY idx_drawer (branch_id, received_by, day_close_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS receipt_methods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_id INT UNSIGNED NOT NULL,
    method ENUM('cash','card','bank_transfer','cheque','tabby','tamara','stripe','wallet','voucher','loyalty','insurance') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reference VARCHAR(100) NULL,
    cheque_date DATE NULL,
    voucher_id INT UNSIGNED NULL,
    KEY idx_receipt (receipt_id),
    KEY idx_voucher (voucher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS receipt_allocations (
    receipt_id INT UNSIGNED NOT NULL,
    invoice_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    PRIMARY KEY (receipt_id, invoice_id),
    KEY idx_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credit_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(40) NOT NULL,
    invoice_id INT UNSIGNED NOT NULL,
    branch_id INT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL,
    gross DECIMAL(12,2) NOT NULL COMMENT 'taxable amount credited',
    vat DECIMAL(12,2) NOT NULL,
    total DECIMAL(12,2) NOT NULL,
    refund_method ENUM('cash','card','bank_transfer','wallet') NULL,
    refunded DECIMAL(12,2) NOT NULL DEFAULT 0,
    day_close_id INT UNSIGNED NULL,
    issued_by INT UNSIGNED NULL,
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_number (number),
    KEY idx_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credit_note_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credit_note_id INT UNSIGNED NOT NULL,
    invoice_line_id INT UNSIGNED NOT NULL,
    qty DECIMAL(10,2) NOT NULL,
    amount DECIMAL(12,2) NOT NULL COMMENT 'taxable amount',
    vat_amount DECIMAL(12,2) NOT NULL,
    KEY idx_note (credit_note_id),
    KEY idx_line (invoice_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_wallet (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    type ENUM('deposit','allocation','refund','loyalty_earn','loyalty_redeem','adjustment') NOT NULL,
    amount DECIMAL(12,2) NOT NULL COMMENT 'signed: money in is positive',
    method ENUM('cash','card','bank_transfer') NULL COMMENT 'how a refund was paid out',
    receipt_id INT UNSIGNED NULL,
    invoice_id INT UNSIGNED NULL,
    credit_note_id INT UNSIGNED NULL,
    day_close_id INT UNSIGNED NULL,
    branch_id INT UNSIGNED NULL,
    note VARCHAR(255) NULL,
    staff_id INT UNSIGNED NULL,
    at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_patient (patient_id),
    KEY idx_receipt (receipt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS packages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    name_ar VARCHAR(150) NULL,
    price DECIMAL(12,2) NOT NULL,
    vat_rate DECIMAL(5,2) NOT NULL DEFAULT 5.00,
    validity_days SMALLINT UNSIGNED NOT NULL DEFAULT 365,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS package_items (
    package_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    sessions SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (package_id, service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_packages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    package_id INT UNSIGNED NOT NULL,
    invoice_line_id INT UNSIGNED NULL,
    value DECIMAL(12,2) NOT NULL COMMENT 'what was paid for it including VAT (for the liability report)',
    starts_on DATE NOT NULL,
    expires_on DATE NOT NULL,
    status ENUM('active','completed','expired','cancelled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_patient (patient_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_package_sessions (
    patient_package_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    total SMALLINT UNSIGNED NOT NULL,
    used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (patient_package_id, service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS package_usages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_package_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    appointment_id INT UNSIGNED NULL,
    used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    staff_id INT UNSIGNED NULL,
    UNIQUE KEY uq_visit (patient_package_id, service_id, appointment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vouchers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    initial DECIMAL(12,2) NOT NULL,
    expires_on DATE NOT NULL,
    patient_id INT UNSIGNED NULL COMMENT 'who bought it',
    recipient VARCHAR(150) NULL,
    status ENUM('active','void') NOT NULL DEFAULT 'active',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discount_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    kind ENUM('percent','amount') NOT NULL,
    value DECIMAL(12,2) NOT NULL,
    max_amount DECIMAL(12,2) NULL,
    valid_from DATE NULL,
    valid_to DATE NULL,
    service_ids JSON NULL COMMENT 'null = every service',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS day_closes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    staff_id INT UNSIGNED NOT NULL,
    business_date DATE NOT NULL,
    expected JSON NOT NULL,
    counted JSON NOT NULL,
    difference DECIMAL(12,2) NOT NULL,
    note VARCHAR(500) NULL,
    closed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by INT UNSIGNED NULL,
    KEY idx_branch_date (branch_id, business_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commission_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NULL,
    service_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = every service',
    kind ENUM('percent_net','fixed_per_unit') NOT NULL,
    value DECIMAL(12,2) NOT NULL,
    deduct_consumables TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rule (staff_id, service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_renditions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doc_type ENUM('invoice','receipt','credit_note') NOT NULL,
    doc_id INT UNSIGNED NOT NULL,
    layout VARCHAR(40) NOT NULL,
    html MEDIUMTEXT NOT NULL,
    is_original TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_doc (doc_type, doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
