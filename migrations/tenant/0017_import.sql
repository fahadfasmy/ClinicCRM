-- 0017_import: moving a clinic over from its old system (ClinicSoft). Patients keep their old file number (legacy_id) so
-- balances, packages, visits, invoices, notes and documents can find them; every imported row is remembered once
-- (import_keys), so running the same file again adds nothing twice; each import run keeps its report.
-- Imported invoices are marked: 'history' (issued and taxed in the old system: a record only) or 'opening_balance'
-- (money still owed, collected here with a normal receipt). Neither counts as revenue or VAT in this system.

ALTER TABLE patients ADD COLUMN IF NOT EXISTS legacy_id VARCHAR(40) NULL,
                     ADD UNIQUE INDEX IF NOT EXISTS uq_patient_legacy (legacy_id);
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS imported ENUM('history','opening_balance') NULL,
                     ADD COLUMN IF NOT EXISTS legacy_ref VARCHAR(60) NULL;

CREATE TABLE IF NOT EXISTS import_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(30) NOT NULL,
    file_name VARCHAR(200) NOT NULL,
    file_hash CHAR(64) NOT NULL,
    mapping LONGTEXT NOT NULL,
    status ENUM('dry_run','committed','failed') NOT NULL DEFAULT 'dry_run',
    rows_total INT UNSIGNED NOT NULL DEFAULT 0,
    rows_ok INT UNSIGNED NOT NULL DEFAULT 0,
    rows_skipped INT UNSIGNED NOT NULL DEFAULT 0,
    rows_error INT UNSIGNED NOT NULL DEFAULT 0,
    report LONGTEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    committed_at DATETIME NULL,
    KEY idx_import_runs_hash (file_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_keys (
    kind VARCHAR(30) NOT NULL,
    legacy_key VARCHAR(120) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    run_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (kind, legacy_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
