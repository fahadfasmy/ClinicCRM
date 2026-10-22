-- Phase 4: the scheduler. Appointments get an end time, branch and room, several treatments per visit, the visit
-- steps (confirmed → arrived → triage → with doctor → completed) with their times, and can belong to a patient without
-- a lead. Plus the status history (waiting times are worked out from it) and a waiting list.
-- The ALTER is last, so a run that stops half-way can simply run again; existing rows are filled in by 0005.

CREATE TABLE IF NOT EXISTS appointment_services (
    appointment_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    qty TINYINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (appointment_id, service_id),
    KEY idx_service (service_id),
    CONSTRAINT fk_as_appt FOREIGN KEY (appointment_id) REFERENCES appointments (id),
    CONSTRAINT fk_as_service FOREIGN KEY (service_id) REFERENCES services (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every step of a visit, with who did it and when (rows are only ever added)
CREATE TABLE IF NOT EXISTS appointment_status_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT UNSIGNED NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NOT NULL,
    note VARCHAR(255) NULL,
    staff_id INT UNSIGNED NULL,
    at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_appt (appointment_id, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- People who want an earlier or particular slot; resolved when booked or no longer wanted
CREATE TABLE IF NOT EXISTS waiting_list (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    branch_id INT UNSIGNED NULL,
    service_id INT UNSIGNED NULL,
    staff_id INT UNSIGNED NULL,
    preferred_from DATE NULL,
    preferred_to DATE NULL,
    notes VARCHAR(255) NULL,
    resolved_at DATETIME NULL,
    resolved_note VARCHAR(60) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_open (resolved_at, branch_id),
    KEY idx_patient (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE appointments
    MODIFY lead_id INT UNSIGNED NULL,
    MODIFY status ENUM('booked','confirmed','arrived','in_triage','with_doctor','completed','cancelled','no_show','rescheduled') NOT NULL DEFAULT 'booked',
    ADD COLUMN branch_id INT UNSIGNED NULL AFTER patient_id,
    ADD COLUMN room_id INT UNSIGNED NULL AFTER branch_id,
    ADD COLUMN ends_at DATETIME NULL AFTER scheduled_at,
    ADD COLUMN duration_min SMALLINT UNSIGNED NULL AFTER ends_at,
    ADD COLUMN visit_type ENUM('first_visit','follow_up','treatment','review') NOT NULL DEFAULT 'treatment' AFTER duration_min,
    ADD COLUMN booked_via ENUM('staff','online','whatsapp','phone') NOT NULL DEFAULT 'staff' AFTER visit_type,
    ADD COLUMN source_id INT UNSIGNED NULL AFTER booked_via,
    ADD COLUMN reschedule_of_id INT UNSIGNED NULL,
    ADD COLUMN confirmed_at DATETIME NULL,
    ADD COLUMN arrived_at DATETIME NULL,
    ADD COLUMN triage_at DATETIME NULL,
    ADD COLUMN doctor_in_at DATETIME NULL,
    ADD COLUMN doctor_out_at DATETIME NULL,
    ADD KEY idx_practitioner_time (practitioner_id, scheduled_at, ends_at),
    ADD KEY idx_room_time (room_id, scheduled_at),
    ADD KEY idx_branch_time (branch_id, scheduled_at);
