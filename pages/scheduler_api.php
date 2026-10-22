<?php
// pages/scheduler_api.php — JSON for the scheduler, visit board and waiting list. Every request checks its permission;
// every write checks the CSRF token (X-CSRF-Token header). Practitioners see only their own appointments.
//   GET  ?action=resources&branch=&date=        practitioners (hours, time off), rooms, opening hours of that day
//   GET  ?action=events&branch=&start=&end=     appointments in the range
//   GET  ?action=search&q=                      patients and open leads to book for
//   GET  ?action=services                       bookable treatments
//   GET  ?action=appointment&id=                one visit with its journey and history
//   POST action=book | move | step | block | waitlist_add | waitlist_resolve
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/scheduler.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$db = getDB();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$out = function (array $data, int $code = 200) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; };
$need = function (string $perm) use ($out) { if (!can($perm)) $out(['error' => 'You don’t have permission for this.'], 403); };
$ownOnly = currentRole() === 'practitioner';   // doctors see their own column unless an admin gives them more
$branch = (int)($_GET['branch'] ?? $_POST['branch'] ?? 0);
$branches = branchList($db);
if (!isset($branches[$branch])) $branch = (int)array_key_first($branches);
$tz = new DateTimeZone(CLINIC_TZ);
$client = APPT_CLIENT_SQL;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
        if ($action === 'book') {
            $need('scheduler.book');
            $patientId = (int)($_POST['patient_id'] ?? 0);
            $leadId = (int)($_POST['lead_id'] ?? 0);
            if (!$patientId && $leadId) {
                // Booking a lead makes it a patient first (its stage moves to Booked with the booking, not to Converted)
                $l = $db->prepare("SELECT assigned_to FROM leads WHERE id = ?"); $l->execute([$leadId]);
                $owner = $l->fetchColumn();
                if ($owner === false || (!can('leads.all') && $owner && (int)$owner !== currentStaffId())) $out(['error' => 'You can’t book for this lead.'], 403);
                $db->beginTransaction();
                try { [$patientId] = patientFromLead($db, $leadId); $db->commit(); } catch (Throwable $e) { $db->rollBack(); throw $e; }
            }
            [$id, $warn] = createAppointment($db, [
                'patient_id' => $patientId, 'lead_id' => $leadId ?: null, 'service_ids' => (array)($_POST['service_ids'] ?? []),
                'start' => trim(($_POST['date'] ?? '') . ' ' . ($_POST['time'] ?? '')), 'practitioner_id' => (int)($_POST['practitioner_id'] ?? 0),
                'room_id' => (int)($_POST['room_id'] ?? 0), 'branch_id' => $branch, 'visit_type' => $_POST['visit_type'] ?? '',
                'source_id' => (int)($_POST['source_id'] ?? 0), 'notes' => mb_substr((string)($_POST['notes'] ?? ''), 0, 1000),
                'duration_min' => (int)($_POST['duration_min'] ?? 0), 'allow_outside_hours' => !empty($_POST['allow_outside_hours']) && can('scheduler.block_slots'),
            ]);
            if (!empty($_POST['waitlist_id'])) $db->prepare("UPDATE waiting_list SET resolved_at = NOW(), resolved_note = 'booked' WHERE id = ? AND resolved_at IS NULL")->execute([(int)$_POST['waitlist_id']]);
            $out(['ok' => true, 'id' => $id, 'warnings' => $warn]);
        }
        if ($action === 'move') {
            $need('scheduler.book');
            $warn = moveAppointment($db, (int)$_POST['id'], trim(($_POST['date'] ?? '') . ' ' . ($_POST['time'] ?? '')), (int)($_POST['practitioner_id'] ?? 0) ?: null,
                (int)($_POST['room_id'] ?? 0) ?: null, !array_key_exists('room_id', $_POST), !empty($_POST['allow_outside_hours']) && can('scheduler.block_slots'));
            $out(['ok' => true, 'warnings' => $warn]);
        }
        if ($action === 'step') {
            setAppointmentStep($db, (int)$_POST['id'], (string)($_POST['to'] ?? ''));   // checks the step's own permission
            $out(['ok' => true]);
        }
        if ($action === 'block') {
            $need('scheduler.block_slots');
            $from = DateTimeImmutable::createFromFormat('Y-m-d H:i', ($_POST['date'] ?? '') . ' ' . ($_POST['from'] ?? ''), $tz);
            $to = DateTimeImmutable::createFromFormat('Y-m-d H:i', ($_POST['date'] ?? '') . ' ' . ($_POST['to'] ?? ''), $tz);
            if (!$from || !$to || $to <= $from) $out(['error' => 'Choose a start and an end time (the end after the start).'], 422);
            $staff = (int)($_POST['staff_id'] ?? 0) ?: null; $room = (int)($_POST['room_id'] ?? 0) ?: null;
            if ($room) { $r = $db->prepare("SELECT COUNT(*) FROM rooms WHERE id = ? AND branch_id = ?"); $r->execute([$room, $branch]); if (!$r->fetchColumn()) $out(['error' => 'Choose a room of this branch.'], 422); }
            $db->prepare("INSERT INTO schedule_blocks (staff_id, room_id, branch_id, starts_at, ends_at, reason, created_by) VALUES (?,?,?,?,?,?,?)")
               ->execute([$staff, $room, $branch, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'), mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 150), currentStaffId()]);
            logActivity(currentStaffId(), 'schedule_block_added', null, null, $from->format('d M H:i') . '–' . $to->format('H:i'));
            $out(['ok' => true]);
        }
        if ($action === 'waitlist_add') {
            $need('scheduler.book');
            $p = findPatient($db, (int)($_POST['patient_id'] ?? 0)) ?? throw new StockException('Choose the patient.');
            $date = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null;
            $db->prepare("INSERT INTO waiting_list (patient_id, branch_id, service_id, staff_id, preferred_from, preferred_to, notes, created_by) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$p['id'], $branch, (int)($_POST['service_id'] ?? 0) ?: null, (int)($_POST['staff_id'] ?? 0) ?: null, $date('preferred_from'), $date('preferred_to'),
                          mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255) ?: null, currentStaffId()]);
            $out(['ok' => true]);
        }
        if ($action === 'waitlist_resolve') {
            $need('scheduler.book');
            $db->prepare("UPDATE waiting_list SET resolved_at = NOW(), resolved_note = ? WHERE id = ? AND resolved_at IS NULL")
               ->execute([in_array($_POST['note'] ?? '', ['booked', 'removed'], true) ? $_POST['note'] : 'removed', (int)($_POST['id'] ?? 0)]);
            $out(['ok' => true]);
        }
        $out(['error' => 'Unknown action'], 400);
    }

    if ($action === 'resources') {
        $need('scheduler.view');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
        $wd = (int)(new DateTimeImmutable($date, $tz))->format('w');
        $hours = json_decode($branches[$branch]['working_hours'] ?? '{}', true)[$wd] ?? null;
        $blocks = $db->prepare("SELECT staff_id, room_id, DATE_FORMAT(starts_at, '%Y-%m-%dT%H:%i') s, DATE_FORMAT(ends_at, '%Y-%m-%dT%H:%i') e, reason
                                FROM schedule_blocks WHERE branch_id = ? AND starts_at < ? + INTERVAL 1 DAY AND ends_at > ?");
        $blocks->execute([$branch, $date, $date]);
        $blocks = $blocks->fetchAll();
        $pracs = [];
        foreach (practitionerList($db, true) as $p) {
            if ($ownOnly && (int)$p['id'] !== currentStaffId()) continue;
            $h = practitionerSchedule($db, (int)$p['id']);
            $pracs[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'color' => $p['color'], 'role' => $p['role'],
                        'hasSchedule' => isset($h[$branch]), 'hours' => $h[$branch][$wd] ?? [],
                        'blocks' => array_values(array_map(fn($b) => [$b['s'], $b['e'], $b['reason']], array_filter($blocks, fn($b) => (int)$b['staff_id'] === (int)$p['id'] || (!$b['staff_id'] && !$b['room_id']))))];
        }
        $rooms = $db->prepare("SELECT id, name, type FROM rooms WHERE branch_id = ? AND is_active = 1 ORDER BY name");
        $rooms->execute([$branch]);
        $rooms = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'type' => ROOM_TYPES[$r['type']],
            'blocks' => array_values(array_map(fn($b) => [$b['s'], $b['e'], $b['reason']], array_filter($blocks, fn($b) => (int)$b['room_id'] === (int)$r['id'] || (!$b['staff_id'] && !$b['room_id']))))], $rooms->fetchAll());
        $out(['date' => $date, 'open' => $hours ?: ['09:00', '22:00'], 'closed' => !$hours && !empty($branches[$branch]['working_hours']) && $branches[$branch]['working_hours'] !== '{}',
              'practitioners' => $pracs, 'rooms' => $rooms]);
    }

    if ($action === 'events') {
        $need('scheduler.view');
        $start = date('Y-m-d H:i:s', strtotime((string)($_GET['start'] ?? 'today')) ?: time());
        $end = date('Y-m-d H:i:s', strtotime((string)($_GET['end'] ?? 'tomorrow')) ?: time() + 86400);
        $s = $db->prepare("SELECT a.id, a.scheduled_at, COALESCE(a.ends_at, a.scheduled_at + INTERVAL 30 MINUTE) AS ends_at, a.status, a.practitioner_id, a.room_id,
                                  a.patient_id, a.lead_id, a.visit_type, $client AS client, COALESCE(pp.color, '#b8925a') AS color, st.name AS prac_name, rm.name AS room_name,
                                  (SELECT GROUP_CONCAT(sv.name ORDER BY sv.name SEPARATOR ', ') FROM appointment_services x JOIN services sv ON sv.id = x.service_id WHERE x.appointment_id = a.id) AS services
                           FROM appointments a LEFT JOIN patients pt ON pt.id = a.patient_id LEFT JOIN leads ld ON ld.id = a.lead_id
                           LEFT JOIN practitioner_profiles pp ON pp.staff_id = a.practitioner_id LEFT JOIN staff st ON st.id = a.practitioner_id LEFT JOIN rooms rm ON rm.id = a.room_id
                           WHERE a.branch_id = ? AND a.scheduled_at < ? AND COALESCE(a.ends_at, a.scheduled_at) > ? AND a.status NOT IN ('cancelled','rescheduled')"
                           . ($ownOnly ? ' AND a.practitioner_id = ' . currentStaffId() : '') . " ORDER BY a.scheduled_at LIMIT 2000");
        $s->execute([$branch, $end, $start]);
        $canMove = can('scheduler.book');
        $out(array_map(fn($a) => [
            'id' => (int)$a['id'], 'title' => $a['client'] . ($a['services'] ? ' · ' . $a['services'] : ''), 'client' => $a['client'], 'services' => (string)$a['services'],
            'start' => str_replace(' ', 'T', substr($a['scheduled_at'], 0, 16)), 'end' => str_replace(' ', 'T', substr($a['ends_at'], 0, 16)),
            'practitionerId' => $a['practitioner_id'] ? (int)$a['practitioner_id'] : null, 'roomId' => $a['room_id'] ? (int)$a['room_id'] : null,
            'practitioner' => $a['prac_name'], 'room' => $a['room_name'], 'status' => $a['status'], 'statusLabel' => APPT_STATUS[$a['status']][0] ?? $a['status'],
            'color' => $a['color'], 'editable' => $canMove && in_array($a['status'], ['booked', 'confirmed'], true),
        ], $s->fetchAll()));
    }

    if ($action === 'search') {
        $need('scheduler.book');
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) $out([]);
        $res = [];
        $ph = normalizePhone($q);
        $s = $db->prepare("SELECT p.id, p.mrn, p.first_name, p.middle_name, p.last_name, p.title, (SELECT phone FROM patient_phones x WHERE x.patient_id = p.id ORDER BY is_primary DESC, id LIMIT 1) AS phone
                           FROM patients p WHERE p.status = 'active' AND (p.mrn = ? OR CONCAT_WS(' ', p.first_name, p.last_name) LIKE ? OR p.name_ar LIKE ?"
                           . ($ph && strlen(preg_replace('/\D/', '', $q)) >= 7 ? " OR p.id IN (SELECT patient_id FROM patient_phones WHERE phone LIKE ?)" : '') . ") ORDER BY p.id DESC LIMIT 8");
        $args = [$q, "%$q%", "%$q%"]; if ($ph && strlen(preg_replace('/\D/', '', $q)) >= 7) $args[] = '%' . substr($ph, -9);
        $s->execute($args);
        foreach ($s as $p) $res[] = ['kind' => 'patient', 'id' => (int)$p['id'], 'label' => patientName($p), 'sub' => $p['mrn'] . ($p['phone'] ? ' · ' . $p['phone'] : '')];
        // Open leads without a patient record yet (booking one creates it)
        $own = can('leads.all') ? '' : ' AND (l.assigned_to = ' . currentStaffId() . ' OR l.assigned_to IS NULL)';
        $s = $db->prepare("SELECT l.id, l.name, l.phone, l.stage FROM leads l WHERE l.patient_id IS NULL AND l.stage NOT IN ('lost','cold')$own
                           AND (l.name LIKE ? OR l.phone LIKE ? OR l.id IN (SELECT lead_id FROM lead_phones WHERE phone LIKE ?)) ORDER BY l.id DESC LIMIT 6");
        $digits = preg_replace('/\D/', '', $q);
        $s->execute(["%$q%", strlen($digits) >= 6 ? '%' . substr($digits, -8) . '%' : "%$q%", strlen($digits) >= 6 ? '%' . substr($digits, -8) . '%' : "%$q%"]);
        foreach ($s as $l) $res[] = ['kind' => 'lead', 'id' => (int)$l['id'], 'label' => $l['name'], 'sub' => 'Lead · ' . $l['phone'] . ' · becomes a patient when booked'];
        $out($res);
    }

    if ($action === 'services') {
        $need('scheduler.view');
        $out(array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name'], 'category' => $s['category'] ?: 'Other', 'mins' => (int)$s['duration_min'], 'price' => (float)$s['price']],
            $db->query("SELECT id, name, category, duration_min, price FROM services WHERE is_active = 1 ORDER BY category, sort_order, name")->fetchAll()));
    }

    if ($action === 'appointment') {
        $need('appointments.view');
        $a = getAppointment($db, (int)($_GET['id'] ?? 0));
        if (!$a || ($ownOnly && (int)$a['practitioner_id'] !== currentStaffId())) $out(['error' => 'Not found'], 404);
        $h = $db->prepare("SELECT h.to_status, h.note, h.at, s.name FROM appointment_status_history h LEFT JOIN staff s ON s.id = h.staff_id WHERE h.appointment_id = ? ORDER BY h.at, h.id");
        $h->execute([$a['id']]);
        $next = [];
        foreach (APPT_STEPS as $to => [$from, , $perm]) if (in_array($a['status'], $from, true) && can($perm)) $next[] = ['to' => $to, 'label' => APPT_STATUS[$to][0]];
        $out(['id' => (int)$a['id'], 'client' => $a['lead_name'], 'mrn' => $a['mrn'], 'phone' => $a['lead_phone'], 'services' => array_values(appointmentServices($db, (int)$a['id'])),
              'start' => $a['scheduled_at'], 'end' => $a['ends_at'], 'practitioner' => $a['practitioner_name'], 'room' => $a['room_name'], 'branch' => $a['branch_name'],
              'practitionerId' => $a['practitioner_id'] ? (int)$a['practitioner_id'] : null, 'roomId' => $a['room_id'] ? (int)$a['room_id'] : null,
              'status' => $a['status'], 'statusLabel' => APPT_STATUS[$a['status']][0] ?? $a['status'], 'visitType' => APPT_VISIT_TYPES[$a['visit_type']] ?? '',
              'patientId' => $a['patient_id'] ? (int)$a['patient_id'] : null, 'leadId' => $a['lead_id'] ? (int)$a['lead_id'] : null, 'notes' => (string)$a['notes'],
              'journey' => array_map(fn($j) => ['step' => $j[0], 'at' => $j[1], 'by' => $j[2]], appointmentJourney($a)),
              'waited' => $a['arrived_at'] ? minutesBetween($a['arrived_at'], $a['doctor_in_at']) : null,
              'history' => $h->fetchAll(), 'next' => $next, 'canMove' => can('scheduler.book') && in_array($a['status'], ['booked', 'confirmed'], true)]);
    }
    $out(['error' => 'Unknown action'], 400);
} catch (StockException | RuntimeException $e) {
    $out(['error' => $e->getMessage()], 422);
}
