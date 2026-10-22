-- 0014_compliance: restore drills (a backup restored somewhere safe and checked, recorded for auditors) and nightly backup
-- status is kept in app_meta. Nothing else changes: audit_log, patient_access_log and staff_notifications already exist.

CREATE TABLE IF NOT EXISTS restore_drills (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    drilled_on DATE NOT NULL,
    backup_file VARCHAR(200) NOT NULL,
    result ENUM('pass','fail') NOT NULL,
    tables_count INT UNSIGNED NULL,
    rows_count INT UNSIGNED NULL,
    notes VARCHAR(500) NULL,
    staff_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_restore_drills_on (drilled_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
