<?php
// includes/scheduler.php — booking, moving and the visit steps (Phase 4). One booking function for every way into the
// calendar (scheduler, lead page, later online booking): several treatments per visit, length = their total, no double
// booking (the practitioner and room rows are locked while checking), inside working hours and outside time off.
// Stock reservation, release and use stay in inventory.php; Google Calendar sync in gcal.php.
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/patients.php';
require_once __DIR__ . '/gcal.php';

// Visits still going on (they hold their time slot and their reserved stock)
const APPT_OPEN = ['booked', 'confirmed', 'arrived', 'in_triage', 'with_doctor'];
const APPT_VISIT_TYPES = ['first_visit' => 'First visit', 'follow_up' => 'Follow-up', 'treatment' => 'Treatment session', 'review' => 'Review'];
// Next step => [steps it can follow, time column it stamps, permission needed]
const APPT_STEPS = [
    'confirmed'   => [['booked'], 'confirmed_at', 'visits.checkin'],
    'arrived'     => [['booked', 'confirmed'], 'arrived_at', 'visits.checkin'],
    'in_triage'   => [['arrived'], 'triage_at', 'visits.triage'],
    'with_doctor' => [['arrived', 'in_triage'], 'doctor_in_at', 'visits.triage'],
];

function apptOpenSql(): string { return "('" . implode("','", APPT_OPEN) . "')"; }

/** SQL for the client's name on an appointment (patient, else lead); needs aliases pt (patients) and ld (leads). */
const APPT_CLIENT_SQL = "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', pt.first_name, pt.last_name)), ''), ld.name, 'Client')";

function addApptHistory(PDO $db, int $apptId, ?string $from, string $to, string $note = ''): void {
    $db->prepare("INSERT INTO appointment_status_history (appointment_id, from_status, to_status, note, staff_id) VALUES (?,?,?,?,?)")
       ->execute([$apptId, $from, $to, mb_substr($note, 0, 255) ?: null, currentStaffId() ?: null]);
}

/**
 * Throws StockException with a plain reason when the practitioner or room is busy, or the time is outside the
 * practitioner's working hours / inside time off. Must run inside the booking transaction: it locks the practitioner's
 * staff row and the room row, so two people booking the same slot at the same moment can't both succeed.
 */
function assertSlotFree(PDO $db, ?int $pracId, ?int $roomId, int $branchId, DateTimeImmutable $start, DateTimeImmutable $end, ?int $ignoreId = null, bool $allowOutsideHours = false): void {
    $s = $start->format('Y-m-d H:i:s'); $e = $end->format('Y-m-d H:i:s');
    $busy = "status IN " . apptOpenSql() . " AND scheduled_at < ? AND COALESCE(ends_at, scheduled_at + INTERVAL 30 MINUTE) > ? AND id <> ?";
    if ($pracId) {
        $st = $db->prepare("SELECT name FROM staff WHERE id = ? AND is_active = 1 FOR UPDATE");
        $st->execute([$pracId]);
        $name = $st->fetchColumn();
        if ($name === false) throw new StockException('Choose a practitioner from the list.');
        $q = $db->prepare("SELECT scheduled_at FROM appointments WHERE practitioner_id = ? AND $busy ORDER BY scheduled_at LIMIT 1");
        $q->execute([$pracId, $e, $s, $ignoreId ?? 0]);
        if ($t = $q->fetchColumn()) throw new StockException("$name already has an appointment at " . date('H:i', strtotime($t)) . '. Choose another time or practitioner.');
        if (!$allowOutsideHours) {
            // Working hours are only enforced once they are set up for this practitioner at this branch
            $sched = practitionerSchedule($db, $pracId)[$branchId] ?? null;
            if ($sched !== null) {
                $from = $start->format('H:i'); $to = $end->format('Y-m-d') === $start->format('Y-m-d') ? $end->format('H:i') : '24:00';
                $inside = false;
                foreach ($sched[(int)$start->format('w')] ?? [] as [$a, $b]) if ($from >= $a && $to <= ($b === '23:59' ? '24:00' : $b)) $inside = true;
                if (!$inside) throw new StockException("That is outside {$name}’s working hours at this branch.");
            }
        }
        $b = $db->prepare("SELECT reason FROM schedule_blocks WHERE branch_id = ? AND (staff_id = ? OR (staff_id IS NULL AND room_id IS NULL)) AND starts_at < ? AND ends_at > ? LIMIT 1");
        $b->execute([$branchId, $pracId, $e, $s]);
        if (($r = $b->fetchColumn()) !== false && !$allowOutsideHours) throw new StockException("$name isn’t available then" . ($r !== '' ? " ($r)" : '') . '.');
    }
    if ($roomId) {
        $st = $db->prepare("SELECT name FROM rooms WHERE id = ? AND branch_id = ? AND is_active = 1 FOR UPDATE");
        $st->execute([$roomId, $branchId]);
        $name = $st->fetchColumn();
        if ($name === false) throw new StockException('Choose a room of this branch.');
        $q = $db->prepare("SELECT scheduled_at FROM appointments WHERE room_id = ? AND $busy ORDER BY scheduled_at LIMIT 1");
        $q->execute([$roomId, $e, $s, $ignoreId ?? 0]);
        if ($t = $q->fetchColumn()) throw new StockException("$name is already booked at " . date('H:i', strtotime($t)) . '.');
        $b = $db->prepare("SELECT reason FROM schedule_blocks WHERE room_id = ? AND starts_at < ? AND ends_at > ? LIMIT 1");
        $b->execute([$roomId, $e, $s]);
        if (($r = $b->fetchColumn()) !== false) throw new StockException("$name is blocked then" . ($r !== '' ? " ($r)" : '') . '.');
    }
}

/** Reserves the recipes of all treatments of a visit (a product used by two treatments is one reservation). */
function reserveVisitRecipes(PDO $db, int $apptId, array $serviceIds): void {
    $in = implode(',', array_map('intval', $serviceIds));
    foreach ($db->query("SELECT product_id, SUM(qty) AS qty FROM service_items WHERE service_id IN ($in) GROUP BY product_id ORDER BY product_id")->fetchAll() as $it) {
        lockProduct($db, (int)$it['product_id']);
        $db->prepare("INSERT INTO appointment_items (appointment_id, product_id, qty_reserved) VALUES (?,?,?)")->execute([$apptId, $it['product_id'], $it['qty']]);
        addMovement($db, ['product_id' => $it['product_id'], 'type' => 'reserve', 'reserved_change' => (float)$it['qty'], 'appointment_id' => $apptId]);
    }
}

function parseApptTime(string $when): DateTimeImmutable {
    $ts = strtotime($when);
    if (!$ts) throw new StockException('Pick a date and time.');
    return new DateTimeImmutable(date('Y-m-d H:i:00', $ts));
}

/**
 * Books a visit. $f: service_ids[], start, practitioner_id?, room_id?, branch_id?, patient_id? and/or lead_id?,
 * visit_type?, booked_via?, source_id?, notes?, duration_min? (default: the treatments' total), allow_outside_hours?.
 * Reserves the recipes, moves a lead to Booked, records the history, queues Google Calendar. Returns [id, stock warnings].
 */
function createAppointment(PDO $db, array $f): array {
    $serviceIds = array_values(array_unique(array_filter(array_map('intval', (array)($f['service_ids'] ?? [])))));
    if (!$serviceIds) throw new StockException('Pick at least one treatment.');
    $svcs = $db->query("SELECT id, name, duration_min FROM services WHERE is_active = 1 AND id IN (" . implode(',', $serviceIds) . ")")->fetchAll(PDO::FETCH_UNIQUE);
    if (count($svcs) !== count($serviceIds)) throw new StockException('Pick treatments from the list.');
    $minutes = (int)($f['duration_min'] ?? 0) ?: array_sum(array_map(fn($s) => max(5, (int)$s['duration_min']), $svcs));
    if ($minutes < 5 || $minutes > 720) throw new StockException('The visit length must be between 5 minutes and 12 hours.');
    $start = parseApptTime((string)($f['start'] ?? ''));
    $end = $start->modify("+$minutes minutes");
    $pracId = (int)($f['practitioner_id'] ?? 0) ?: null;
    $roomId = (int)($f['room_id'] ?? 0) ?: null;
    $patientId = (int)($f['patient_id'] ?? 0) ?: null;
    $leadId = (int)($f['lead_id'] ?? 0) ?: null;
    if ($patientId) {
        $p = findPatient($db, $patientId) ?? throw new StockException('Patient not found.');
        $patientId = (int)$p['id'];
        $leadId = $leadId ?: ((int)$p['lead_id'] ?: null);
    } elseif ($leadId) {
        $l = $db->prepare("SELECT patient_id FROM leads WHERE id = ?"); $l->execute([$leadId]);
        $patientId = ((int)$l->fetchColumn()) ?: null;
    }
    if (!$patientId && !$leadId) throw new StockException('Choose the patient.');
    $branchId = (int)($f['branch_id'] ?? 0);
    if (!$branchId && $pracId) { $hb = $db->prepare("SELECT branch_id FROM staff WHERE id = ?"); $hb->execute([$pracId]); $branchId = (int)$hb->fetchColumn(); }
    if (!$branchId) $branchId = (int)$db->query("SELECT MIN(id) FROM branches WHERE is_active = 1")->fetchColumn();
    $visitType = isset(APPT_VISIT_TYPES[$f['visit_type'] ?? '']) ? $f['visit_type'] : 'treatment';
    $via = in_array($f['booked_via'] ?? '', ['staff', 'online', 'whatsapp', 'phone'], true) ? $f['booked_via'] : 'staff';
    $warnings = [];
    foreach ($serviceIds as $sid) $warnings = array_merge($warnings, bookingWarnings($db, $sid, $start->format('Y-m-d H:i:s')));

    $id = stockTx($db, function () use ($db, $f, $serviceIds, $minutes, $start, $end, $pracId, $roomId, $patientId, $leadId, $branchId, $visitType, $via) {
        assertSlotFree($db, $pracId, $roomId, $branchId, $start, $end, null, !empty($f['allow_outside_hours']));
        $db->prepare("INSERT INTO appointments (lead_id, patient_id, service_id, scheduled_at, ends_at, duration_min, practitioner_id, room_id, branch_id,
                                                visit_type, booked_via, source_id, notes, booked_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$leadId, $patientId, $serviceIds[0], $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $minutes, $pracId, $roomId, $branchId,
                      $visitType, $via, (int)($f['source_id'] ?? 0) ?: null, trim((string)($f['notes'] ?? '')) ?: null, currentStaffId() ?: null]);
        $apptId = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO appointment_services (appointment_id, service_id) VALUES (?, ?)");
        foreach ($serviceIds as $sid) $ins->execute([$apptId, $sid]);
        reserveVisitRecipes($db, $apptId, $serviceIds);
        if ($leadId) {
            // The lead follows its appointments: booking brings it to Booked (a converted client stays converted)
            $lead = $db->prepare("SELECT stage FROM leads WHERE id = ? FOR UPDATE");
            $lead->execute([$leadId]);
            $stage = $lead->fetchColumn();
            if ($stage !== false && $stage !== 'booked' && $stage !== 'converted') {
                $db->prepare("UPDATE leads SET stage = 'booked', updated_at = NOW() WHERE id = ?")->execute([$leadId]);
                logActivity(currentStaffId(), 'stage_changed', $leadId, $stage, 'booked');
            }
        }
        addApptHistory($db, $apptId, null, 'booked');
        logActivity(currentStaffId(), 'appointment_booked', $leadId, null, (string)$apptId);
        return $apptId;
    });
    gcalQueue($db, $id);
    if ($leadId) {   // Meta "Schedule" for people who came in as leads (sent by cron when the Conversions API is on)
        try { require_once __DIR__ . '/meta_leads.php'; metaQueueEvent($db, $leadId, 'schedule:' . $id, 'Schedule'); } catch (Throwable $e) { appLog('Meta Schedule event: ' . $e->getMessage()); }
    }
    return [$id, array_values(array_unique($warnings))];
}

/** Moves a visit that hasn't started (booked or confirmed) to a new time, practitioner and/or room. Returns stock warnings. */
function moveAppointment(PDO $db, int $apptId, string $when, ?int $pracId, ?int $roomId = null, bool $keepRoom = true, bool $allowOutsideHours = false): array {
    $start = parseApptTime($when);
    $from = stockTx($db, function () use ($db, $apptId, $start, $pracId, $roomId, $keepRoom, $allowOutsideHours) {
        $s = $db->prepare("SELECT a.*, p.name AS prac_name FROM appointments a LEFT JOIN staff p ON p.id = a.practitioner_id WHERE a.id = ? FOR UPDATE");
        $s->execute([$apptId]);
        $a = $s->fetch();
        if (!$a || !in_array($a['status'], ['booked', 'confirmed'], true)) throw new StockException('Only visits that haven’t started can be moved.');
        $minutes = (int)($a['duration_min'] ?: 30);
        $end = $start->modify("+$minutes minutes");
        $room = $keepRoom ? ((int)$a['room_id'] ?: null) : $roomId;
        assertSlotFree($db, $pracId, $room, (int)$a['branch_id'], $start, $end, $apptId, $allowOutsideHours);
        $db->prepare("UPDATE appointments SET scheduled_at = ?, ends_at = ?, practitioner_id = ?, room_id = ? WHERE id = ?")
           ->execute([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $pracId, $room, $apptId]);
        $pn = $db->prepare("SELECT name FROM staff WHERE id = ?"); $pn->execute([$pracId ?? 0]);
        addApptHistory($db, $apptId, $a['status'], $a['status'], 'Moved from ' . date('D d M H:i', strtotime($a['scheduled_at'])) . ($a['prac_name'] ? " ({$a['prac_name']})" : '')
            . ' to ' . $start->format('D d M H:i') . (($n = $pn->fetchColumn()) ? " ($n)" : ''));
        logActivity(currentStaffId(), 'appointment_rescheduled', $a['lead_id'] ? (int)$a['lead_id'] : null, $a['scheduled_at'], $start->format('Y-m-d H:i:s'));
        return $a;
    });
    gcalQueue($db, $apptId);
    $warnings = [];
    $svcs = $db->prepare("SELECT service_id FROM appointment_services WHERE appointment_id = ?"); $svcs->execute([$apptId]);
    foreach ($svcs->fetchAll(PDO::FETCH_COLUMN) as $sid) $warnings = array_merge($warnings, bookingWarnings($db, (int)$sid, $start->format('Y-m-d H:i:s'), $apptId));
    return array_values(array_unique($warnings));
}

/** The next step of a visit (confirmed, arrived, in_triage, with_doctor), stamped with the time and who did it. */
function setAppointmentStep(PDO $db, int $apptId, string $to): void {
    if (!isset(APPT_STEPS[$to])) throw new StockException('Unknown step.');
    [$from, $col, $perm] = APPT_STEPS[$to];
    if (!can($perm)) throw new StockException('You don’t have permission for this step.');
    stockTx($db, function () use ($db, $apptId, $to, $from, $col) {
        $s = $db->prepare("SELECT status FROM appointments WHERE id = ? FOR UPDATE");
        $s->execute([$apptId]);
        $cur = $s->fetchColumn();
        if ($cur === false) throw new StockException('Appointment not found.');
        if (!in_array($cur, $from, true)) throw new StockException('This visit is ' . strtolower(APPT_STATUS[$cur][0] ?? $cur) . ', so it can’t move to “' . APPT_STATUS[$to][0] . '”.');
        $db->prepare("UPDATE appointments SET status = ?, $col = NOW()" . ($to === 'arrived' ? ", confirmed_at = COALESCE(confirmed_at, NOW())" : '') . " WHERE id = ?")->execute([$to, $apptId]);
        addApptHistory($db, $apptId, $cur, $to);
    });
    gcalQueue($db, $apptId);
}

/** Minutes between two stamps (or until now), or null. */
function minutesBetween(?string $from, ?string $to = null): ?int {
    if (!$from) return null;
    return max(0, (int)round(((($to ? strtotime($to) : time())) - strtotime($from)) / 60));
}

/** The five steps of a visit with their times: Booking → Front desk → Nurse → Doctor → Invoice. */
function appointmentJourney(array $a): array {
    return [
        ['Booking', $a['created_at'], $a['booked_by_name'] ?? null],
        ['Front desk', $a['arrived_at'], null],
        ['Nurse', $a['triage_at'], null],
        ['Doctor', $a['doctor_in_at'], $a['doctor_out_at'] ? 'until ' . date('H:i', strtotime($a['doctor_out_at'])) : null],
        ['Invoice', $a['invoiced_at'] ?? null, null],
    ];
}

/** The treatments of a visit as [id => name]. */
function appointmentServices(PDO $db, int $apptId): array {
    $s = $db->prepare("SELECT s.id, s.name FROM appointment_services x JOIN services s ON s.id = x.service_id WHERE x.appointment_id = ? ORDER BY s.name");
    $s->execute([$apptId]);
    return $s->fetchAll(PDO::FETCH_KEY_PAIR);
}
