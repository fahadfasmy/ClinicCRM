<?php
// Phase 4: booking several treatments (length and stock add up), no double booking (practitioner and room, also when two
// processes book the same slot at the same moment), working hours and time off, moving, the visit steps with their
// times, and that cancel / complete keep the stock behaviour. Scratch database clinic_crm_test_sched.
require_once __DIR__ . '/../includes/scheduler.php';

function schedDb(): PDO {
    static $done = false;
    $db = testDb('clinic_crm_test_sched', !$done);
    if (!$done) {
        migrateApply($db, __DIR__ . '/../migrations/tenant', fn($l) => null);
        $db->exec("INSERT INTO products (id, name) VALUES (1, 'Gel'), (2, 'Serum')");
        $db->exec("INSERT INTO services (id, name, duration_min) VALUES (1, 'Facial', 30), (2, 'Peel', 45), (3, 'Consult', 15)");
        $db->exec("INSERT INTO service_items (service_id, product_id, qty) VALUES (1, 1, 1), (2, 1, 2), (2, 2, 1)");
        $db->exec("INSERT INTO staff (id, name, email, password, role, branch_id) VALUES (101, 'Dr One', 'one@example.test', 'x', 'practitioner', 1), (102, 'Dr Two', 'two@example.test', 'x', 'practitioner', 1)");
        $db->exec("INSERT INTO rooms (id, branch_id, name, type) VALUES (1, 1, 'Room A', 'procedure')");
        $db->exec("INSERT INTO patients (id, mrn, first_name, last_name) VALUES (1, 'T-1', 'Pat', 'One'), (2, 'T-2', 'Pat', 'Two')");
        $done = true;
    }
    return $db;
}

/** A date a few days ahead on the given weekday (0 = Sunday), as Y-m-d. */
function futureDay(int $weekday): string {
    $d = new DateTimeImmutable('+7 days', new DateTimeZone(CLINIC_TZ));
    while ((int)$d->format('w') !== $weekday) $d = $d->modify('+1 day');
    return $d->format('Y-m-d');
}

function test_a_visit_with_two_treatments_adds_up_length_and_stock() {
    $db = schedDb();
    $day = futureDay(1);
    [$id] = createAppointment($db, ['patient_id' => 1, 'service_ids' => [1, 2], 'start' => "$day 10:00", 'practitioner_id' => 101, 'room_id' => 1]);
    $a = $db->query("SELECT * FROM appointments WHERE id = $id")->fetch();
    check((int)$a['duration_min'] === 75 && $a['ends_at'] === "$day 11:15:00", 'length is 30 + 45 = 75 minutes, ends 11:15');
    check((int)$a['branch_id'] === 1 && $a['status'] === 'booked' && $a['lead_id'] === null, 'branch set; a patient without a lead can be booked');
    check(array_values(appointmentServices($db, $id)) === ['Facial', 'Peel'], 'both treatments are on the visit');
    $res = $db->query("SELECT product_id, qty_reserved FROM appointment_items WHERE appointment_id = $id ORDER BY product_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    check((float)$res[1] === 3.0 && (float)$res[2] === 1.0, 'a product used by both treatments is reserved once, with the total (gel 1 + 2)');
    check((float)$db->query("SELECT qty_reserved FROM products WHERE id = 1")->fetchColumn() === 3.0, 'and the product’s reserved stock goes up');
    check($db->query("SELECT to_status FROM appointment_status_history WHERE appointment_id = $id")->fetchColumn() === 'booked', 'the booking is in the history');
    checkThrows(fn() => createAppointment($db, ['patient_id' => 1, 'service_ids' => [], 'start' => "$day 13:00"]), 'at least one treatment', 'a visit needs a treatment');
    checkThrows(fn() => createAppointment($db, ['service_ids' => [1], 'start' => "$day 13:00"]), 'patient', 'and a patient');
}

function test_no_double_booking_of_practitioner_or_room() {
    $db = schedDb();
    $day = futureDay(2);
    createAppointment($db, ['patient_id' => 1, 'service_ids' => [1], 'start' => "$day 10:00", 'practitioner_id' => 101, 'room_id' => 1]);
    checkThrows(fn() => createAppointment($db, ['patient_id' => 2, 'service_ids' => [1], 'start' => "$day 10:15", 'practitioner_id' => 101]), 'already has an appointment at 10:00', 'the same practitioner overlapping is refused');
    checkThrows(fn() => createAppointment($db, ['patient_id' => 2, 'service_ids' => [1], 'start' => "$day 09:45", 'practitioner_id' => 102, 'room_id' => 1]), 'Room A is already booked', 'the same room overlapping is refused');
    [$b] = createAppointment($db, ['patient_id' => 2, 'service_ids' => [1], 'start' => "$day 10:30", 'practitioner_id' => 101, 'room_id' => 1]);
    check($b > 0, 'back-to-back (starting when the other ends) is fine');
    [$c] = createAppointment($db, ['patient_id' => 2, 'service_ids' => [3], 'start' => "$day 10:00", 'practitioner_id' => 102]);
    check($c > 0, 'another practitioner at the same time is fine');
    cancelAppointment($db, $c, 'cancelled', 'test');
    [$d] = createAppointment($db, ['patient_id' => 1, 'service_ids' => [3], 'start' => "$day 10:00", 'practitioner_id' => 102]);
    check($d > 0, 'a cancelled visit frees its slot');
}

function test_two_people_booking_the_same_slot_at_once_only_one_wins() {
    schedDb();
    $day = futureDay(3);
    $dir = tempDir('race');
    $go = microtime(true) + 1.5;   // both start at the same moment
    file_put_contents("$dir/w.php", '<?php require ' . var_export(realpath(__DIR__ . '/lib.php'), true) . '; require ' . var_export(realpath(__DIR__ . '/../includes/scheduler.php'), true) . ';
        $db = testDb("clinic_crm_test_sched"); time_sleep_until(' . $go . ');
        try { [$id] = createAppointment($db, ["patient_id" => (int)$argv[1], "service_ids" => [1], "start" => "' . $day . ' 15:00", "practitioner_id" => 101]); echo "OK $id"; }
        catch (Throwable $e) { echo "NO " . $e->getMessage(); }');
    $p = [];
    foreach ([1, 2] as $k) $p[$k] = proc_open([phpBin(), "$dir/w.php", (string)$k], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k]);
    $out = [];
    foreach ([1, 2] as $k) { $out[] = trim(stream_get_contents($pipes[$k][1])); proc_close($p[$k]); }
    $wins = count(array_filter($out, fn($o) => str_starts_with($o, 'OK')));
    check($wins === 1, 'exactly one booking succeeds: ' . implode(' | ', $out));
    check((bool)array_filter($out, fn($o) => str_contains($o, 'already has an appointment')), 'the other gets a friendly message');
    check((int)schedDb()->query("SELECT COUNT(*) FROM appointments WHERE practitioner_id = 101 AND scheduled_at = '$day 15:00:00'")->fetchColumn() === 1, 'one appointment in the database');
    rmTree($dir);
}

function test_working_hours_and_time_off() {
    $db = schedDb();
    $mon = futureDay(1);
    savePractitionerSchedule($db, 102, 1, [[1, '10:00', '14:00']]);
    checkThrows(fn() => createAppointment($db, ['patient_id' => 1, 'service_ids' => [3], 'start' => "$mon 14:00", 'practitioner_id' => 102]), 'outside', 'after their hours is refused');
    checkThrows(fn() => createAppointment($db, ['patient_id' => 1, 'service_ids' => [1], 'start' => "$mon 13:45", 'practitioner_id' => 102]), 'outside', 'a visit running past the end is refused');
    [$ok] = createAppointment($db, ['patient_id' => 1, 'service_ids' => [3], 'start' => "$mon 13:45", 'practitioner_id' => 102]);
    check($ok > 0, 'ending exactly at the end of their hours is fine');
    [$over] = createAppointment($db, ['patient_id' => 1, 'service_ids' => [3], 'start' => "$mon 16:00", 'practitioner_id' => 102, 'allow_outside_hours' => true]);
    check($over > 0, 'an allowed override books outside hours');
    $db->prepare("INSERT INTO schedule_blocks (staff_id, branch_id, starts_at, ends_at, reason) VALUES (102, 1, ?, ?, 'Training')")->execute(["$mon 11:00", "$mon 12:00"]);
    checkThrows(fn() => createAppointment($db, ['patient_id' => 1, 'service_ids' => [3], 'start' => "$mon 11:30", 'practitioner_id' => 102]), 'Training', 'time off is refused with its reason');
    $db->prepare("INSERT INTO schedule_blocks (room_id, branch_id, starts_at, ends_at, reason) VALUES (1, 1, ?, ?, 'Laser service')")->execute(["$mon 16:00", "$mon 17:00"]);
    checkThrows(fn() => createAppointment($db, ['patient_id' => 1, 'service_ids' => [3], 'start' => "$mon 16:30", 'room_id' => 1]), 'Laser service', 'a blocked room is refused');
}

function test_moving_and_the_visit_steps() {
    $db = schedDb();
    $day = futureDay(4);
    [$id] = createAppointment($db, ['patient_id' => 1, 'service_ids' => [1], 'start' => "$day 09:00", 'practitioner_id' => 101]);
    createAppointment($db, ['patient_id' => 2, 'service_ids' => [1], 'start' => "$day 11:00", 'practitioner_id' => 101]);
    checkThrows(fn() => moveAppointment($db, $id, "$day 11:15", 101), 'already has an appointment', 'moving onto another booking is refused');
    moveAppointment($db, $id, "$day 12:00", 101);
    $a = $db->query("SELECT * FROM appointments WHERE id = $id")->fetch();
    check($a['scheduled_at'] === "$day 12:00:00" && $a['ends_at'] === "$day 12:30:00" && (int)$a['practitioner_id'] === 101, 'moved, length kept');
    check(str_contains((string)$db->query("SELECT note FROM appointment_status_history WHERE appointment_id = $id ORDER BY id DESC LIMIT 1")->fetchColumn(), 'Moved from'), 'the move is in the history');
    $GLOBALS['__perm_override'] = true;
    $_SESSION['role'] = 'admin'; $_SESSION['staff_id'] = 101;   // steps need visits.* permissions
    checkThrows(fn() => setAppointmentStep($db, $id, 'in_triage'), 'can’t move', 'triage before arrival is refused');
    setAppointmentStep($db, $id, 'arrived');
    setAppointmentStep($db, $id, 'in_triage');
    setAppointmentStep($db, $id, 'with_doctor');
    $a = $db->query("SELECT * FROM appointments WHERE id = $id")->fetch();
    check($a['status'] === 'with_doctor' && $a['arrived_at'] && $a['confirmed_at'] && $a['triage_at'] && $a['doctor_in_at'], 'each step is stamped (arrival also counts as confirmed)');
    checkThrows(fn() => moveAppointment($db, $id, "$day 15:00", 101), 'haven’t started', 'a visit in progress can’t be moved');
    checkThrows(fn() => cancelAppointment($db, $id, 'no_show'), 'hasn’t arrived', 'no-show is refused once they arrived');
    $items = appointmentItems($db, $id);
    completeAppointment($db, $id, [$items[0]['id'] => ['qty' => '0', 'lot_id' => null, 'reason' => '']]);
    $a = $db->query("SELECT * FROM appointments WHERE id = $id")->fetch();
    check($a['status'] === 'completed' && $a['doctor_out_at'] !== null, 'completing from “with doctor” works and stamps the doctor’s end time');
    $j = appointmentJourney(getAppointment($db, $id));
    check(count(array_filter($j, fn($s) => $s[1])) === 4 && $j[4][0] === 'Invoice' && !$j[4][1], 'the journey has four steps done; Invoice waits for the invoice: ' . implode(', ', array_map(fn($s) => $s[0] . ($s[1] ? '✓' : ''), $j)));
    $steps = $db->query("SELECT to_status FROM appointment_status_history WHERE appointment_id = $id ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    check($steps === ['booked', 'booked', 'arrived', 'in_triage', 'with_doctor', 'completed'], 'history in order: ' . implode(', ', $steps));
    unset($_SESSION['role'], $_SESSION['staff_id']);
    // Tidy the activity lines the booking functions wrote to the local clinic's log (logActivity uses the main connection)
    getDB()->exec("DELETE FROM activity_log WHERE staff_id IN (0, 101) AND lead_id IS NULL AND action IN ('appointment_booked','appointment_rescheduled','appointment_cancelled','appointment_no_show','appointment_completed') AND created_at > NOW() - INTERVAL 1 HOUR");
}

function test_completing_a_scheduler_visit_moves_stock_exactly_as_before() {
    $db = schedDb();
    $db->exec("INSERT INTO stock_lots (product_id, lot_number, expiry_date, qty_received, qty_on_hand) VALUES (2, 'SER-1', '2030-01-01', 10, 10)");
    $lot = (int)$db->lastInsertId();
    $reservedBefore = (float)$db->query("SELECT qty_reserved FROM products WHERE id = 2")->fetchColumn();
    [$id] = createAppointment($db, ['patient_id' => 2, 'service_ids' => [2], 'start' => futureDay(5) . ' 10:00', 'practitioner_id' => 101]);
    check((float)$db->query("SELECT qty_reserved FROM products WHERE id = 2")->fetchColumn() === $reservedBefore + 1, 'booking reserves the serum');
    $used = [];
    foreach (appointmentItems($db, $id) as $it) $used[$it['id']] = ['qty' => $it['product_id'] == 2 ? '1' : '0', 'lot_id' => $it['product_id'] == 2 ? $lot : null, 'reason' => 'test'];
    completeAppointment($db, $id, $used);
    check((float)$db->query("SELECT qty_on_hand FROM stock_lots WHERE id = $lot")->fetchColumn() === 9.0, 'completing takes the amount used out of the chosen batch');
    check((float)$db->query("SELECT qty_reserved FROM products WHERE id = 2")->fetchColumn() === $reservedBefore, 'and releases the reservation');
    check((int)$db->query("SELECT COUNT(*) FROM stock_movements WHERE appointment_id = $id AND type = 'consume' AND lot_id = $lot")->fetchColumn() === 1, 'the use is in the stock ledger with its batch (for recalls)');
    getDB()->exec("DELETE FROM activity_log WHERE staff_id IN (0, 101) AND lead_id IS NULL AND action IN ('appointment_booked','appointment_completed') AND created_at > NOW() - INTERVAL 1 HOUR");
}
