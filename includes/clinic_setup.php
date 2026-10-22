<?php
// includes/clinic_setup.php — branches, rooms, practitioner schedules and time blocks (Clinic setup), gapless
// document numbers, and the free-slot finder the scheduler uses. Tables: migrations/tenant/0002_clinic_setup.sql.
require_once __DIR__ . '/db.php';

const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const ROOM_TYPES = ['consultation' => 'Consultation', 'procedure' => 'Procedure', 'laser' => 'Laser', 'dental' => 'Dental chair', 'other' => 'Other'];
const CLINIC_TZ = 'Asia/Dubai';
// Document number codes: PREFIX-INV-2026-000123 (MRN is one clinic-wide sequence: MRN-000123)
const NUMBER_CODES = ['invoice' => 'INV', 'receipt' => 'RCT', 'credit_note' => 'CN', 'claim' => 'CLM', 'mrn' => 'MRN'];

/** Branches as [id => row], active ones only unless $all. */
function branchList(PDO $db, bool $all = false): array {
    $rows = $db->query("SELECT * FROM branches" . ($all ? '' : ' WHERE is_active = 1') . " ORDER BY is_active DESC, name")->fetchAll();
    return array_column($rows, null, 'id');
}

/** Clinic staff who see patients (practitioners and nurses), with their profile when they have one. */
function practitionerList(PDO $db, bool $bookableOnly = false): array {
    return $db->query("SELECT s.id, s.name, s.role, s.is_active, s.branch_id, p.dha_license, p.specialty, p.department_id, p.is_bookable,
                              COALESCE(p.color, '#b8925a') AS color, COALESCE(p.sort_order, 0) AS sort_order
                       FROM staff s LEFT JOIN practitioner_profiles p ON p.staff_id = s.id
                       WHERE s.role IN ('practitioner','nurse') AND s.is_active = 1" . ($bookableOnly ? ' AND COALESCE(p.is_bookable, 1) = 1' : '') . "
                       ORDER BY sort_order, s.name")->fetchAll();
}

/**
 * The next number of a document sequence. MUST run inside the transaction that saves the document: the sequence row
 * stays locked until it commits (two cashiers never get the same number) and a rollback gives the number back (no gaps).
 */
function nextSequenceNo(PDO $db, string $type, int $branchId = 0, int $year = 0): int {
    if (!isset(NUMBER_CODES[$type])) throw new InvalidArgumentException("Unknown number type $type");
    if (!$db->inTransaction()) throw new LogicException('Document numbers must be taken inside the transaction that saves the document.');
    // LAST_INSERT_ID(expr) hands the new value back on this connection only; the upsert takes the row lock
    $db->prepare("INSERT INTO number_sequences (type, branch_id, year, last_no) VALUES (?, ?, ?, LAST_INSERT_ID(1))
                  ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)")->execute([$type, $branchId, $year]);
    return (int)$db->query("SELECT LAST_INSERT_ID()")->fetchColumn();
}

/** A formatted document number for a branch, e.g. SAS-INV-2026-000123 (year in Dubai time). */
function nextDocumentNumber(PDO $db, string $type, int $branchId): string {
    if ($type === 'mrn') return mrnFormat(nextSequenceNo($db, 'mrn'));
    $s = $db->prepare("SELECT invoice_prefix FROM branches WHERE id = ?");
    $s->execute([$branchId]);
    $prefix = $s->fetchColumn();
    if ($prefix === false) throw new RuntimeException('Unknown branch.');
    $year = (int)(new DateTimeImmutable('now', new DateTimeZone(CLINIC_TZ)))->format('Y');
    return sprintf('%s-%s-%d-%06d', $prefix, NUMBER_CODES[$type], $year, nextSequenceNo($db, $type, $branchId, $year));
}

/** Patient file number: the clinic's prefix (Clinic setup, default "MRN-") and the number padded to its length (default 6). */
function mrnFormat(int $n): string {
    $pad = max(4, min(10, (int)tenantSetting('clinic.mrn_padding', '6')));
    return tenantSetting('clinic.mrn_prefix', 'MRN-') . str_pad((string)$n, $pad, '0', STR_PAD_LEFT);
}

/** Weekly schedule of one practitioner: [branch_id => [weekday => [[start 'H:i', end 'H:i'], …]]]. */
function practitionerSchedule(PDO $db, int $staffId): array {
    $s = $db->prepare("SELECT branch_id, weekday, TIME_FORMAT(start_time, '%H:%i') s, TIME_FORMAT(end_time, '%H:%i') e
                       FROM practitioner_schedules WHERE staff_id = ? ORDER BY branch_id, weekday, start_time");
    $s->execute([$staffId]);
    $out = [];
    foreach ($s as $r) $out[(int)$r['branch_id']][(int)$r['weekday']][] = [$r['s'], $r['e']];
    return $out;
}

/**
 * Checks and tidies a weekly schedule typed into the form: rows of [weekday, 'HH:MM', 'HH:MM']. Returns the rows, or
 * throws with a readable reason (end before start, overlapping stretches on the same day).
 */
function validateScheduleRows(array $rows): array {
    $clean = [];
    foreach ($rows as [$day, $from, $to]) {
        if ($from === '' && $to === '') continue;
        if (!isset(WEEKDAYS[(int)$day]) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $from) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/', $to))
            throw new RuntimeException('Times must look like 09:00.');
        if ($to <= $from) throw new RuntimeException(WEEKDAYS[(int)$day] . ": the end ($to) must be after the start ($from).");
        $clean[] = [(int)$day, $from, $to];
    }
    usort($clean, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    for ($i = 1; $i < count($clean); $i++)
        if ($clean[$i][0] === $clean[$i - 1][0] && $clean[$i][1] < $clean[$i - 1][2])
            throw new RuntimeException(WEEKDAYS[$clean[$i][0]] . ': two working times overlap.');
    return $clean;
}

/** Replaces one practitioner's weekly schedule at one branch. */
function savePractitionerSchedule(PDO $db, int $staffId, int $branchId, array $rows): void {
    $rows = validateScheduleRows($rows);
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM practitioner_schedules WHERE staff_id = ? AND branch_id = ?")->execute([$staffId, $branchId]);
        $ins = $db->prepare("INSERT INTO practitioner_schedules (staff_id, branch_id, weekday, start_time, end_time) VALUES (?,?,?,?,?)");
        foreach ($rows as [$d, $f, $t]) $ins->execute([$staffId, $branchId, $d, $f, $t === '24:00' ? '23:59:59' : $t]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Start times (Y-m-d H:i, Dubai time) on $date when the practitioner can see someone for $minutes at $branchId:
 * inside their weekly schedule there, not in the past, and clear of their own blocks and whole-branch closures.
 * Existing appointments are checked by the scheduler when booking (Phase 4), under a row lock.
 */
function freeSlots(PDO $db, int $staffId, int $branchId, string $date, int $minutes, int $step = 15): array {
    $tz = new DateTimeZone(CLINIC_TZ);
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
    if (!$day || $minutes < 5 || $step < 5) return [];
    $sched = practitionerSchedule($db, $staffId)[$branchId][(int)$day->format('w')] ?? [];
    if (!$sched) return [];
    $b = $db->prepare("SELECT starts_at, ends_at FROM schedule_blocks
                       WHERE branch_id = ? AND (staff_id = ? OR (staff_id IS NULL AND room_id IS NULL))
                         AND starts_at < ? AND ends_at > ?");
    $b->execute([$branchId, $staffId, $day->modify('+1 day')->format('Y-m-d H:i:s'), $day->format('Y-m-d H:i:s')]);
    $blocks = array_map(fn($r) => [new DateTimeImmutable($r['starts_at'], $tz), new DateTimeImmutable($r['ends_at'], $tz)], $b->fetchAll());
    $now = new DateTimeImmutable('now', $tz);
    $out = [];
    foreach ($sched as [$from, $to]) {
        $end = new DateTimeImmutable("$date $to", $tz);
        for ($t = new DateTimeImmutable("$date $from", $tz); $t->modify("+$minutes minutes") <= $end; $t = $t->modify("+$step minutes")) {
            if ($t < $now) continue;
            $tEnd = $t->modify("+$minutes minutes");
            foreach ($blocks as [$bs, $be]) if ($t < $be && $tEnd > $bs) continue 2;
            $out[] = $t->format('Y-m-d H:i');
        }
    }
    return $out;
}
