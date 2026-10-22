-- Phase 4: fills the new appointment columns for appointments booked before the scheduler (end time from the
-- treatment's length, the first branch, the treatment as the visit's one service, a "booked" history row).
-- Changes no existing value; every statement only fills what is still empty, so it is safe to run again.

UPDATE appointments a JOIN services s ON s.id = a.service_id
   SET a.duration_min = s.duration_min, a.ends_at = a.scheduled_at + INTERVAL s.duration_min MINUTE
 WHERE a.ends_at IS NULL;

UPDATE appointments SET branch_id = (SELECT MIN(id) FROM branches) WHERE branch_id IS NULL;

INSERT IGNORE INTO appointment_services (appointment_id, service_id) SELECT id, service_id FROM appointments;

INSERT INTO appointment_status_history (appointment_id, from_status, to_status, staff_id, at)
SELECT a.id, NULL, 'booked', a.booked_by, a.created_at FROM appointments a
 WHERE NOT EXISTS (SELECT 1 FROM appointment_status_history h WHERE h.appointment_id = a.id);

INSERT INTO appointment_status_history (appointment_id, from_status, to_status, staff_id, at)
SELECT a.id, 'booked', a.status, COALESCE(a.completed_by, a.cancelled_by), COALESCE(a.completed_at, a.cancelled_at, a.updated_at) FROM appointments a
 WHERE a.status IN ('completed','cancelled','no_show')
   AND NOT EXISTS (SELECT 1 FROM appointment_status_history h WHERE h.appointment_id = a.id AND h.to_status = a.status);
