<?php
// includes/emr.php — clinical records (Phase 5). An encounter is one visit's chart: vitals, the doctor's note, ICD-10
// diagnoses, procedures, prescriptions, aesthetic data (injection map, laser settings, skin type) and dental chart entries.
// Allergies and history belong to the patient and carry forward. Signing stores a SHA-256 of the whole content and makes
// the note read-only; corrections are addenda. Every opening is logged (patient_access_log), every change audited.
require_once __DIR__ . '/scheduler.php';   // patients, appointments, practitioners, clinic setup

const EMR_SECTIONS = ['vitals' => 'Vitals', 'allergies' => 'Allergies reviewed', 'complaint' => 'Chief complaint', 'diagnosis' => 'Diagnosis',
                      'procedure' => 'Procedure', 'notes' => 'Examination / notes', 'plan' => 'Plan', 'prescription' => 'Prescription'];
const EMR_NOTE_FIELDS = ['chief_complaint' => 'Chief complaint', 'hpi' => 'History of present illness', 'examination' => 'Examination',
                         'assessment' => 'Assessment', 'plan' => 'Plan', 'followup_instruction' => 'Follow-up'];
const ALLERGY_TYPES = ['drug' => 'Drug', 'food' => 'Food', 'environmental' => 'Environmental', 'other' => 'Other'];
const ALLERGY_SEVERITY = ['mild' => 'Mild', 'moderate' => 'Moderate', 'severe' => 'Severe'];
const HISTORY_TYPES = ['medical' => 'Medical', 'surgical' => 'Surgical', 'family' => 'Family', 'social' => 'Social', 'medication' => 'Current medication'];
const RX_ROUTES = ['Oral', 'Topical', 'Intradermal', 'Subcutaneous', 'Intramuscular', 'Intravenous', 'Inhaled', 'Nasal', 'Ophthalmic', 'Otic', 'Rectal', 'Sublingual', 'Vaginal'];
const RX_FREQUENCIES = ['Once daily' => 'مرة يومياً', 'Twice daily' => 'مرتين يومياً', 'Three times daily' => 'ثلاث مرات يومياً', 'Four times daily' => 'أربع مرات يومياً',
                        'Every 8 hours' => 'كل 8 ساعات', 'At bedtime' => 'عند النوم', 'In the morning' => 'في الصباح', 'As needed' => 'عند الحاجة', 'Once' => 'مرة واحدة', 'Weekly' => 'أسبوعياً'];
const FITZPATRICK = ['I' => 'I – always burns', 'II' => 'II – usually burns', 'III' => 'III – sometimes burns', 'IV' => 'IV – rarely burns', 'V' => 'V – very rarely burns', 'VI' => 'VI – never burns'];
// Injection map zones (face and neck): key => [label, SVG shape on a 200×260 face]
const FACE_ZONES = [
    'forehead' => ['Forehead', 'M60 40 Q100 18 140 40 L138 62 Q100 50 62 62 Z'],
    'glabella' => ['Glabella (frown lines)', 'M88 64 L112 64 L110 84 L90 84 Z'],
    'crows_l' => ['Crow’s feet (left)', 'M146 82 L166 78 L168 102 L148 104 Z'],
    'crows_r' => ['Crow’s feet (right)', 'M54 82 L34 78 L32 102 L52 104 Z'],
    'tear_l' => ['Under-eye (left)', 'M114 104 L144 104 L142 116 L116 116 Z'],
    'tear_r' => ['Under-eye (right)', 'M86 104 L56 104 L58 116 L84 116 Z'],
    'cheek_l' => ['Cheek (left)', 'M122 122 L156 116 L154 150 L126 152 Z'],
    'cheek_r' => ['Cheek (right)', 'M78 122 L44 116 L46 150 L74 152 Z'],
    'nose' => ['Nose', 'M92 90 L108 90 L114 140 L86 140 Z'],
    'nasolabial_l' => ['Nasolabial fold (left)', 'M116 144 L126 156 L122 178 L112 166 Z'],
    'nasolabial_r' => ['Nasolabial fold (right)', 'M84 144 L74 156 L78 178 L88 166 Z'],
    'lip_upper' => ['Upper lip', 'M86 168 Q100 160 114 168 L112 176 L88 176 Z'],
    'lip_lower' => ['Lower lip', 'M88 178 L112 178 Q100 192 88 178 Z'],
    'marionette_l' => ['Marionette line (left)', 'M114 186 L124 184 L122 204 L112 202 Z'],
    'marionette_r' => ['Marionette line (right)', 'M86 186 L76 184 L78 204 L88 202 Z'],
    'chin' => ['Chin', 'M86 204 L114 204 L110 224 L90 224 Z'],
    'jaw_l' => ['Jawline (left)', 'M126 196 L152 166 L156 178 L130 214 Z'],
    'jaw_r' => ['Jawline (right)', 'M74 196 L48 166 L44 178 L70 214 Z'],
    'neck' => ['Neck', 'M72 236 L128 236 L132 258 L68 258 Z'],
];
const LASER_FIELDS = ['device' => 'Device', 'wavelength' => 'Wavelength (nm)', 'fluence' => 'Fluence (J/cm²)', 'pulse' => 'Pulse (ms)', 'spot' => 'Spot size (mm)', 'passes' => 'Passes'];
const DENTAL_SURFACES = ['M' => 'Mesial', 'O' => 'Occlusal', 'D' => 'Distal', 'B' => 'Buccal', 'L' => 'Lingual', 'I' => 'Incisal'];
const DENTAL_TYPES = ['condition' => 'Finding', 'planned' => 'Planned', 'done' => 'Done'];

/** The ICD-10 table: the shared reference database in multi-clinic mode, the clinic's own copy otherwise. */
function referenceDb(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (multiTenant() && defined('REFERENCE_DB_NAME')) {
        $t = currentTenant();   // the clinic's own user, which may only read the reference database
        return $pdo = dbConnect(REFERENCE_DB_NAME, $t['db_user'], $t['db_pass'], $t['db_host']);
    }
    return $pdo = getDB();
}

/** ICD-10 codes matching a code prefix or words of the description. */
function icdSearch(string $q, int $limit = 20): array {
    $q = trim($q);
    if (mb_strlen($q) < 2) return [];
    $db = referenceDb();
    if (preg_match('/^[A-Za-z]\d[0-9A-Za-z.]*$/', $q)) {
        $s = $db->prepare("SELECT code, description FROM icd10_codes WHERE code LIKE ? ORDER BY LENGTH(code), code LIMIT $limit");
        $s->execute([strtoupper(str_replace('.', '', $q)) . '%']);
        return $s->fetchAll();
    }
    $words = array_filter(preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $q)), fn($w) => mb_strlen($w) >= 2);
    if (!$words) return [];
    $s = $db->prepare("SELECT code, description FROM icd10_codes WHERE " . implode(' AND ', array_fill(0, count($words), 'description LIKE ?')) . " ORDER BY LENGTH(code), code LIMIT $limit");
    $s->execute(array_map(fn($w) => "%$w%", array_values($words)));
    return $s->fetchAll();
}

function icdLookup(string $code): ?array {
    $s = referenceDb()->prepare("SELECT code, description FROM icd10_codes WHERE code = ?");
    $s->execute([strtoupper(str_replace('.', '', trim($code)))]);
    return $s->fetch() ?: null;
}

/** ICD-10-CM codes are stored without the dot and shown with it (L70.0). */
function icdFormat(string $code): string { return strlen($code) > 3 ? substr($code, 0, 3) . '.' . substr($code, 3) : $code; }

function findEncounter(PDO $db, int $id): ?array {
    $s = $db->prepare("SELECT e.*, s.name AS practitioner_name, pp.dha_license, pp.specialty, sg.name AS signed_by_name, d.name AS department_name,
                              a.scheduled_at, b.name AS branch_name, b.name_ar AS branch_name_ar
                       FROM encounters e LEFT JOIN staff s ON s.id = e.practitioner_id LEFT JOIN practitioner_profiles pp ON pp.staff_id = e.practitioner_id
                       LEFT JOIN staff sg ON sg.id = e.signed_by LEFT JOIN departments d ON d.id = e.department_id
                       LEFT JOIN appointments a ON a.id = e.appointment_id LEFT JOIN branches b ON b.id = e.branch_id WHERE e.id = ?");
    $s->execute([$id]);
    $e = $s->fetch();
    if ($e) $e['data'] = json_decode((string)$e['data'], true) ?: [];
    return $e ?: null;
}

/**
 * Whether this person may open this chart. Needs emr.view; then every chart with emr.all, otherwise the charts they are
 * the practitioner of or started, and today's visits (the nurse taking vitals). Clinical data never reaches other roles.
 */
function canSeeEncounter(array $e): bool {
    if (!can('emr.view')) return false;
    if (can('emr.all')) return true;
    $me = currentStaffId();
    if ((int)$e['practitioner_id'] === $me || (int)$e['created_by'] === $me) return true;
    return substr((string)($e['scheduled_at'] ?: $e['created_at']), 0, 10) === date('Y-m-d');
}

function encounterEditable(array $e): bool { return $e['status'] === 'open' && !$e['locked_at']; }

function assertEditable(array $e): void {
    if ($e['status'] !== 'open') throw new RuntimeException('This note is signed. Add an addendum instead of changing it.');
    if ($e['locked_at']) throw new RuntimeException('This note was locked automatically after ' . emrAutoLockHours() . ' hours. It can still be signed; corrections go in an addendum.');
    if (!can('emr.write')) throw new RuntimeException('You don’t have permission to write clinical notes.');
}

/** The encounter of an appointment, made on first opening (one per visit). Walk-in: no appointment. Returns its id. */
function openEncounter(PDO $db, int $patientId, ?int $apptId = null): int {
    if ($apptId) {
        $s = $db->prepare("SELECT id FROM encounters WHERE appointment_id = ?"); $s->execute([$apptId]);
        if ($id = (int)$s->fetchColumn()) return $id;
        $a = $db->prepare("SELECT patient_id, branch_id, practitioner_id FROM appointments WHERE id = ?"); $a->execute([$apptId]);
        $a = $a->fetch() ?: throw new RuntimeException('Appointment not found.');
        if (!$a['patient_id']) throw new RuntimeException('This appointment has no patient record yet. Convert the lead to a patient first.');
        [$patientId, $branch, $prac] = [(int)$a['patient_id'], $a['branch_id'], $a['practitioner_id']];
    } else {
        if (!findPatient($db, $patientId)) throw new RuntimeException('Patient not found.');
        $hb = $db->prepare("SELECT branch_id FROM staff WHERE id = ?"); $hb->execute([currentStaffId()]);
        [$branch, $prac] = [$hb->fetchColumn() ?: null, in_array(currentRole(), ['practitioner'], true) ? currentStaffId() : null];
    }
    $dep = null;
    if ($prac) { $d = $db->prepare("SELECT department_id FROM practitioner_profiles WHERE staff_id = ?"); $d->execute([$prac]); $dep = $d->fetchColumn() ?: null; }
    try {
        $db->prepare("INSERT INTO encounters (patient_id, appointment_id, branch_id, practitioner_id, department_id, created_by) VALUES (?,?,?,?,?,?)")
           ->execute([$patientId, $apptId, $branch, $prac, $dep, currentStaffId() ?: null]);
    } catch (PDOException $e) {
        if ($apptId && $e->getCode() === '23000') { $s = $db->prepare("SELECT id FROM encounters WHERE appointment_id = ?"); $s->execute([$apptId]); return (int)$s->fetchColumn(); }   // opened at the same moment elsewhere
        throw $e;
    }
    $id = (int)$db->lastInsertId();
    auditLog($db, 'encounter', $id, 'created', ['patient_id' => $patientId, 'appointment_id' => $apptId]);
    return $id;
}

/** One set of vitals (BMI worked out from weight and height). Ranges stop typing mistakes, not unusual patients. */
function saveVitals(PDO $db, array $e, array $v): void {
    assertEditable($e);
    $ranges = ['bp_sys' => [50, 260], 'bp_dia' => [30, 160], 'pulse' => [20, 250], 'temp_c' => [30, 45], 'resp_rate' => [4, 80], 'spo2' => [50, 100],
               'weight_kg' => [0.5, 400], 'height_cm' => [30, 250], 'pain_score' => [0, 10]];
    $row = [];
    foreach ($ranges as $k => [$lo, $hi]) {
        $x = trim((string)($v[$k] ?? ''));
        if ($x === '') { $row[$k] = null; continue; }
        if (!is_numeric($x) || $x < $lo || $x > $hi) throw new RuntimeException('Check the ' . str_replace(['_c', '_kg', '_cm', '_'], ['', '', '', ' '], $k) . " value ($x).");
        $row[$k] = $x + 0;
    }
    if (!array_filter($row, fn($x) => $x !== null)) throw new RuntimeException('Enter at least one vital sign.');
    $row['bmi'] = $row['weight_kg'] && $row['height_cm'] ? round($row['weight_kg'] / (($row['height_cm'] / 100) ** 2), 1) : null;
    $db->prepare("INSERT INTO vitals (encounter_id, " . implode(',', array_keys($row)) . ", recorded_by) VALUES (?" . str_repeat(',?', count($row)) . ",?)")
       ->execute([$e['id'], ...array_values($row), currentStaffId() ?: null]);
    auditLog($db, 'encounter', (int)$e['id'], 'vitals', array_filter($row, fn($x) => $x !== null));
}

/** The note's text sections, the allergy check and the aesthetic fields. Only what was sent is changed. */
function saveNote(PDO $db, array $e, array $f): void {
    assertEditable($e);
    $set = []; $diff = [];
    foreach (array_keys(EMR_NOTE_FIELDS) as $k) if (array_key_exists($k, $f)) {
        $v = trim((string)$f[$k]) === '' ? null : mb_substr(trim((string)$f[$k]), 0, 20000);
        if ($v !== $e[$k]) { $set[$k] = $v; $diff[] = $k; }
    }
    if (array_key_exists('allergies_reviewed', $f) && (int)(bool)$f['allergies_reviewed'] !== (int)$e['allergies_reviewed']) { $set['allergies_reviewed'] = (int)(bool)$f['allergies_reviewed']; $diff[] = 'allergies_reviewed'; }
    if (array_key_exists('data', $f)) {
        $data = $e['data'];
        foreach ((array)$f['data'] as $k => $v) $data[$k] = $v;
        $set['data'] = json_encode($data, JSON_UNESCAPED_UNICODE);
        $diff[] = 'data:' . implode(',', array_keys((array)$f['data']));
    }
    if (!$set) return;
    $db->prepare("UPDATE encounters SET " . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . " WHERE id = ? AND status = 'open'")->execute([...array_values($set), $e['id']]);
    auditLog($db, 'encounter', (int)$e['id'], 'note_saved', ['fields' => $diff]);
}

/** Cleans the injection map input: zone => [units, product, note] for known zones with something entered. */
function cleanInjections(array $in): array {
    $out = [];
    foreach (FACE_ZONES as $z => $_) {
        $r = $in[$z] ?? [];
        $units = trim((string)($r['units'] ?? ''));
        if ($units === '' && trim((string)($r['product'] ?? '')) === '') continue;
        if ($units !== '' && (!is_numeric($units) || $units < 0 || $units > 500)) throw new RuntimeException('Check the amount for ' . FACE_ZONES[$z][0] . '.');
        $out[$z] = ['units' => $units === '' ? null : $units + 0, 'product' => mb_substr(trim((string)($r['product'] ?? '')), 0, 80), 'note' => mb_substr(trim((string)($r['note'] ?? '')), 0, 120)];
    }
    return $out;
}

function addDiagnosis(PDO $db, array $e, string $code, bool $primary, string $kind): void {
    assertEditable($e);
    $icd = icdLookup($code) ?? throw new RuntimeException('Choose a diagnosis from the ICD-10 list.');
    $db->beginTransaction();
    try {
        if ($primary) $db->prepare("UPDATE encounter_diagnoses SET is_primary = 0 WHERE encounter_id = ?")->execute([$e['id']]);
        $first = !$db->query("SELECT COUNT(*) FROM encounter_diagnoses WHERE encounter_id = " . (int)$e['id'])->fetchColumn();
        $db->prepare("INSERT INTO encounter_diagnoses (encounter_id, icd10_code, description, is_primary, kind) VALUES (?,?,?,?,?)")
           ->execute([$e['id'], $icd['code'], $icd['description'], ($primary || $first) ? 1 : 0, $kind === 'provisional' ? 'provisional' : 'final']);
        auditLog($db, 'encounter', (int)$e['id'], 'diagnosis_added', ['code' => $icd['code']]);
        $db->commit();
    } catch (Throwable $x) { $db->rollBack(); throw $x; }
}

function addProcedure(PDO $db, array $e, array $f): void {
    assertEditable($e);
    $desc = mb_substr(trim((string)($f['description'] ?? '')), 0, 150);
    $svc = (int)($f['service_id'] ?? 0) ?: null;
    if ($svc) { $s = $db->prepare("SELECT name FROM services WHERE id = ?"); $s->execute([$svc]); $desc = $desc ?: (string)$s->fetchColumn(); }
    if ($desc === '') throw new RuntimeException('Choose the treatment or describe the procedure.');
    $qty = trim((string)($f['qty'] ?? '1'));
    if (!is_numeric($qty) || $qty <= 0 || $qty > 9999) throw new RuntimeException('Check the quantity.');
    $tooth = trim((string)($f['tooth'] ?? ''));
    if ($tooth !== '' && !preg_match('/^[1-8][1-8]$/', $tooth)) throw new RuntimeException('Tooth numbers are FDI, e.g. 16 or 51.');
    $db->prepare("INSERT INTO encounter_procedures (encounter_id, service_id, description, cpt_code, area, tooth, qty, performed_by, notes) VALUES (?,?,?,?,?,?,?,?,?)")
       ->execute([$e['id'], $svc, $desc, mb_substr(trim((string)($f['cpt_code'] ?? '')), 0, 10) ?: null, mb_substr(trim((string)($f['area'] ?? '')), 0, 80) ?: null,
                  $tooth ?: null, $qty, (int)($f['performed_by'] ?? 0) ?: (currentStaffId() ?: null), mb_substr(trim((string)($f['notes'] ?? '')), 0, 255) ?: null]);
    auditLog($db, 'encounter', (int)$e['id'], 'procedure_added', ['description' => $desc]);
}

/** Removes a diagnosis or procedure line of an open note. */
function removeEncounterLine(PDO $db, array $e, string $table, int $lineId): void {
    assertEditable($e);
    if (!in_array($table, ['encounter_diagnoses', 'encounter_procedures'], true)) throw new RuntimeException('Unknown line.');
    $db->prepare("DELETE FROM $table WHERE id = ? AND encounter_id = ?")->execute([$lineId, $e['id']]);
    auditLog($db, 'encounter', (int)$e['id'], 'line_removed', ['table' => $table, 'id' => $lineId]);
}

/** A prescription of one or more medicines. Items: [drug, strength, dose, route, frequency, duration, qty, instructions]. */
function savePrescription(PDO $db, array $e, array $items, string $notes = ''): int {
    assertEditable($e);
    if (!can('emr.prescribe')) throw new RuntimeException('You don’t have permission to prescribe.');
    $keys = ['drug' => 150, 'strength' => 60, 'dose' => 60, 'route' => 40, 'frequency' => 60, 'duration' => 60, 'qty' => 40, 'instructions' => 255];
    $rows = [];
    foreach ($items as $it) {
        $r = [];
        foreach ($keys as $k => $max) $r[$k] = mb_substr(trim((string)($it[$k] ?? '')), 0, $max);
        if ($r['drug'] === '') continue;
        if ($r['dose'] === '' || $r['frequency'] === '') throw new RuntimeException("{$r['drug']}: enter the dose and how often.");
        $rows[] = $r;
    }
    if (!$rows) throw new RuntimeException('Add at least one medicine.');
    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO prescriptions (encounter_id, patient_id, prescribed_by, notes) VALUES (?,?,?,?)")->execute([$e['id'], $e['patient_id'], currentStaffId() ?: null, mb_substr(trim($notes), 0, 500) ?: null]);
        $rx = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO prescription_items (prescription_id, drug, strength, dose, route, frequency, duration, qty, instructions) VALUES (?,?,?,?,?,?,?,?,?)");
        foreach ($rows as $r) $ins->execute([$rx, ...array_map(fn($v) => $v === '' ? null : $v, array_values($r))]);
        auditLog($db, 'encounter', (int)$e['id'], 'prescription_added', ['prescription_id' => $rx, 'drugs' => array_column($rows, 'drug')]);
        $db->commit();
        return $rx;
    } catch (Throwable $x) { $db->rollBack(); throw $x; }
}

function removePrescription(PDO $db, array $e, int $rxId): void {
    assertEditable($e);
    $db->beginTransaction();
    try {
        $s = $db->prepare("SELECT id FROM prescriptions WHERE id = ? AND encounter_id = ?"); $s->execute([$rxId, $e['id']]);
        if (!$s->fetchColumn()) throw new RuntimeException('Prescription not found.');
        $db->prepare("DELETE FROM prescription_items WHERE prescription_id = ?")->execute([$rxId]);
        $db->prepare("DELETE FROM prescriptions WHERE id = ?")->execute([$rxId]);
        auditLog($db, 'encounter', (int)$e['id'], 'prescription_removed', ['prescription_id' => $rxId]);
        $db->commit();
    } catch (Throwable $x) { $db->rollBack(); throw $x; }
}

// ── Patient-level: allergies, history, dental chart (they outlive one visit) ──

function addAllergy(PDO $db, int $patientId, array $f): void {
    if (!can('emr.write')) throw new RuntimeException('You don’t have permission to write clinical notes.');
    $allergen = mb_substr(trim((string)($f['allergen'] ?? '')), 0, 150);
    if ($allergen === '') throw new RuntimeException('Enter what they are allergic to.');
    $db->prepare("INSERT INTO allergies (patient_id, allergen, type, reaction, severity, recorded_by) VALUES (?,?,?,?,?,?)")
       ->execute([$patientId, $allergen, isset(ALLERGY_TYPES[$f['type'] ?? '']) ? $f['type'] : 'drug', mb_substr(trim((string)($f['reaction'] ?? '')), 0, 255) ?: null,
                  isset(ALLERGY_SEVERITY[$f['severity'] ?? '']) ? $f['severity'] : 'moderate', currentStaffId() ?: null]);
    auditLog($db, 'patient', $patientId, 'allergy_added', ['allergen' => $allergen]);
}

function setAllergyStatus(PDO $db, int $patientId, int $id, string $status): void {
    if (!can('emr.write')) throw new RuntimeException('You don’t have permission to write clinical notes.');
    $db->prepare("UPDATE allergies SET status = ? WHERE id = ? AND patient_id = ?")->execute([$status === 'resolved' ? 'resolved' : 'active', $id, $patientId]);
    auditLog($db, 'patient', $patientId, 'allergy_' . ($status === 'resolved' ? 'resolved' : 'reactivated'), ['id' => $id]);
}

function addHistory(PDO $db, int $patientId, string $type, string $detail): void {
    if (!can('emr.write')) throw new RuntimeException('You don’t have permission to write clinical notes.');
    $detail = mb_substr(trim($detail), 0, 2000);
    if (!isset(HISTORY_TYPES[$type]) || $detail === '') throw new RuntimeException('Choose the kind of history and write it.');
    $db->prepare("INSERT INTO patient_history (patient_id, type, detail, recorded_by) VALUES (?,?,?,?)")->execute([$patientId, $type, $detail, currentStaffId() ?: null]);
    auditLog($db, 'patient', $patientId, 'history_added', ['type' => $type]);
}

function removeHistory(PDO $db, int $patientId, int $id): void {
    if (!can('emr.write')) throw new RuntimeException('You don’t have permission to write clinical notes.');
    $db->prepare("DELETE FROM patient_history WHERE id = ? AND patient_id = ?")->execute([$id, $patientId]);
    auditLog($db, 'patient', $patientId, 'history_removed', ['id' => $id]);
}

function patientAllergies(PDO $db, int $patientId, bool $activeOnly = true): array {
    $s = $db->prepare("SELECT * FROM allergies WHERE patient_id = ?" . ($activeOnly ? " AND status = 'active'" : '') . " ORDER BY status, FIELD(severity,'severe','moderate','mild'), allergen");
    $s->execute([$patientId]);
    return $s->fetchAll();
}

function patientHistory(PDO $db, int $patientId): array {
    $s = $db->prepare("SELECT * FROM patient_history WHERE patient_id = ? ORDER BY FIELD(type,'medical','surgical','medication','family','social'), id");
    $s->execute([$patientId]);
    return $s->fetchAll();
}

/** FDI teeth: permanent 11–48, primary 51–85, in chart order (upper right → upper left, lower left → lower right). */
function fdiTeeth(bool $primary = false): array {
    $q = $primary ? [5, 6, 7, 8] : [1, 2, 3, 4]; $n = $primary ? 5 : 8;
    return [array_merge(array_map(fn($i) => $q[0] * 10 + $i, range($n, 1)), array_map(fn($i) => $q[1] * 10 + $i, range(1, $n))),
            array_merge(array_map(fn($i) => $q[3] * 10 + $i, range($n, 1)), array_map(fn($i) => $q[2] * 10 + $i, range(1, $n)))];
}

function addDentalEntry(PDO $db, array $e, array $f): void {
    assertEditable($e);
    $tooth = trim((string)($f['tooth'] ?? ''));
    if (!in_array((int)$tooth, array_merge(...fdiTeeth(), ...fdiTeeth(true)), true)) throw new RuntimeException('Choose a tooth on the chart.');
    $code = mb_substr(trim((string)($f['code'] ?? '')), 0, 60);
    if ($code === '') throw new RuntimeException('Describe the finding or procedure (e.g. Caries, Composite filling).');
    $surfaces = array_values(array_intersect(array_keys(DENTAL_SURFACES), (array)($f['surfaces'] ?? [])));
    $db->prepare("INSERT INTO dental_chart (patient_id, tooth, surfaces, entry_type, code, note, encounter_id, recorded_by) VALUES (?,?,?,?,?,?,?,?)")
       ->execute([$e['patient_id'], $tooth, $surfaces ? implode(',', $surfaces) : null, isset(DENTAL_TYPES[$f['entry_type'] ?? '']) ? $f['entry_type'] : 'condition',
                  $code, mb_substr(trim((string)($f['note'] ?? '')), 0, 255) ?: null, $e['id'], currentStaffId() ?: null]);
    auditLog($db, 'encounter', (int)$e['id'], 'dental_entry', ['tooth' => $tooth, 'code' => $code]);
}

function removeDentalEntry(PDO $db, array $e, int $id): void {
    assertEditable($e);
    $db->prepare("DELETE FROM dental_chart WHERE id = ? AND encounter_id = ?")->execute([$id, $e['id']]);   // only entries made in this (open) visit
    auditLog($db, 'encounter', (int)$e['id'], 'dental_entry_removed', ['id' => $id]);
}

// ── Required sections, signing, addenda, auto-lock, templates ──

/** The sections this encounter's department requires (department 0 = the clinic default). */
function requiredSections(PDO $db, array $e): array {
    $s = $db->prepare("SELECT section FROM emr_requirements WHERE department_id = ?");
    $s->execute([(int)$e['department_id']]);
    $req = $s->fetchAll(PDO::FETCH_COLUMN);
    if (!$req && $e['department_id']) { $s->execute([0]); $req = $s->fetchAll(PDO::FETCH_COLUMN); }
    return array_values(array_intersect(array_keys(EMR_SECTIONS), $req));
}

/** Required sections still empty: [section => label]. */
function missingSections(PDO $db, array $e): array {
    $id = (int)$e['id'];
    $has = [
        'vitals' => (bool)$db->query("SELECT COUNT(*) FROM vitals WHERE encounter_id = $id")->fetchColumn(),
        'allergies' => (bool)$e['allergies_reviewed'],
        'complaint' => trim((string)$e['chief_complaint']) !== '',
        'diagnosis' => (bool)$db->query("SELECT COUNT(*) FROM encounter_diagnoses WHERE encounter_id = $id")->fetchColumn(),
        'procedure' => (bool)$db->query("SELECT COUNT(*) FROM encounter_procedures WHERE encounter_id = $id")->fetchColumn(),
        'notes' => trim((string)$e['examination']) !== '' || trim((string)$e['hpi']) !== '',
        'plan' => trim((string)$e['plan']) !== '',
        'prescription' => (bool)$db->query("SELECT COUNT(*) FROM prescriptions WHERE encounter_id = $id")->fetchColumn(),
    ];
    $out = [];
    foreach (requiredSections($db, $e) as $sec) if (!$has[$sec]) $out[$sec] = EMR_SECTIONS[$sec];
    return $out;
}

/** Everything that makes up the signed note, in a fixed order, so its hash proves it hasn't changed since. */
function encounterContent(PDO $db, int $id): array {
    $q = fn(string $sql) => $db->query($sql)->fetchAll();
    $e = $db->query("SELECT patient_id, appointment_id, practitioner_id, chief_complaint, hpi, examination, assessment, plan, followup_instruction, allergies_reviewed, data FROM encounters WHERE id = $id")->fetch();
    if ($e) $e['data'] = json_decode((string)$e['data'], true);
    return ['encounter' => $e,
            'vitals' => $q("SELECT bp_sys, bp_dia, pulse, temp_c, resp_rate, spo2, weight_kg, height_cm, bmi, pain_score, recorded_by, recorded_at FROM vitals WHERE encounter_id = $id ORDER BY id"),
            'diagnoses' => $q("SELECT icd10_code, description, is_primary, kind FROM encounter_diagnoses WHERE encounter_id = $id ORDER BY id"),
            'procedures' => $q("SELECT service_id, description, cpt_code, area, tooth, qty, performed_by, notes FROM encounter_procedures WHERE encounter_id = $id ORDER BY id"),
            'prescriptions' => $q("SELECT p.id, i.drug, i.strength, i.dose, i.route, i.frequency, i.duration, i.qty, i.instructions FROM prescriptions p JOIN prescription_items i ON i.prescription_id = p.id WHERE p.encounter_id = $id ORDER BY p.id, i.id"),
            'dental' => $q("SELECT tooth, surfaces, entry_type, code, note FROM dental_chart WHERE encounter_id = $id ORDER BY id")];
}

function encounterHash(PDO $db, int $id): string {
    return hash('sha256', json_encode(encounterContent($db, $id), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
}

/** Signs the note: required sections must be filled; stores who, when and the content hash; the note becomes read-only. */
function signEncounter(PDO $db, int $id): void {
    if (!can('emr.sign')) throw new RuntimeException('Only the treating doctor can sign notes.');
    $db->beginTransaction();
    try {
        $s = $db->prepare("SELECT * FROM encounters WHERE id = ? FOR UPDATE"); $s->execute([$id]);
        $e = $s->fetch() ?: throw new RuntimeException('Note not found.');
        if ($e['status'] !== 'open') throw new RuntimeException('This note is already signed.');
        if ($e['practitioner_id'] && (int)$e['practitioner_id'] !== currentStaffId() && !can('emr.all')) throw new RuntimeException('Only the treating doctor can sign this note.');
        if ($missing = missingSections($db, $e)) throw new RuntimeException('Fill these sections before signing: ' . implode(', ', $missing) . '.');
        $hash = encounterHash($db, $id);
        $db->prepare("UPDATE encounters SET status = 'signed', signed_by = ?, signed_at = NOW(), content_hash = ?, practitioner_id = COALESCE(practitioner_id, ?) WHERE id = ?")
           ->execute([currentStaffId(), $hash, currentStaffId(), $id]);
        auditLog($db, 'encounter', $id, 'signed', ['hash' => $hash]);
        $db->commit();
    } catch (Throwable $x) { $db->rollBack(); throw $x; }
    // Follow-up with a date becomes a waiting-list entry for reception to book
    $f = findEncounter($db, $id);
    if (!empty($f['data']['followup_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['data']['followup_date'])) {
        $db->prepare("INSERT INTO waiting_list (patient_id, branch_id, staff_id, preferred_from, notes, created_by) VALUES (?,?,?,?,?,?)")
           ->execute([$f['patient_id'], $f['branch_id'], $f['practitioner_id'], $f['data']['followup_date'], mb_substr('Follow-up: ' . ($f['followup_instruction'] ?? ''), 0, 255), currentStaffId() ?: null]);
    }
}

/** Whether the signed content is still exactly what was signed. */
function encounterIntact(PDO $db, array $e): ?bool {
    return $e['content_hash'] ? hash_equals($e['content_hash'], encounterHash($db, (int)$e['id'])) : null;
}

function addAmendment(PDO $db, int $id, string $reason, string $text): void {
    if (!can('emr.amend')) throw new RuntimeException('You don’t have permission to add addenda.');
    $reason = mb_substr(trim($reason), 0, 255); $text = mb_substr(trim($text), 0, 20000);
    if ($reason === '' || $text === '') throw new RuntimeException('Write the reason and the correction.');
    $s = $db->prepare("SELECT status FROM encounters WHERE id = ?"); $s->execute([$id]);
    if (!in_array($s->fetchColumn(), ['signed', 'amended'], true)) throw new RuntimeException('Addenda are for signed notes; an open note can simply be edited.');
    $db->prepare("INSERT INTO encounter_amendments (encounter_id, staff_id, reason, text) VALUES (?,?,?,?)")->execute([$id, currentStaffId() ?: null, $reason, $text]);
    $db->prepare("UPDATE encounters SET status = 'amended' WHERE id = ?")->execute([$id]);
    auditLog($db, 'encounter', $id, 'amended', ['reason' => $reason]);
}

function emrAutoLockHours(): int { return max(1, min(720, (int)tenantSetting('clinic.emr_autolock_hours', '24'))); }

/** Locks open notes older than the auto-lock time (cron). Returns how many. */
function emrAutoLock(PDO $db): int {
    return $db->exec("UPDATE encounters SET locked_at = NOW() WHERE status = 'open' AND locked_at IS NULL AND created_at < NOW() - INTERVAL " . emrAutoLockHours() . " HOUR");
}

/** Templates for this note: the department's and the visit's treatments', then general ones. */
function templatesFor(PDO $db, array $e): array {
    $svc = $e['appointment_id'] ? array_keys(appointmentServices($db, (int)$e['appointment_id'])) : [];
    $s = $db->prepare("SELECT * FROM note_templates WHERE is_active = 1 AND (department_id IS NULL OR department_id = ?) AND (service_id IS NULL"
                      . ($svc ? " OR service_id IN (" . implode(',', array_map('intval', $svc)) . ")" : '') . ") ORDER BY service_id IS NULL, department_id IS NULL, name");
    $s->execute([(int)$e['department_id']]);
    return $s->fetchAll();
}

/** Fills the empty text sections of an open note from a template (never overwrites what was written). */
function applyTemplate(PDO $db, array $e, int $templateId): int {
    assertEditable($e);
    $s = $db->prepare("SELECT fields FROM note_templates WHERE id = ? AND is_active = 1"); $s->execute([$templateId]);
    $fields = json_decode((string)$s->fetchColumn(), true) ?: throw new RuntimeException('Template not found.');
    $fill = [];
    foreach (array_keys(EMR_NOTE_FIELDS) as $k) if (trim((string)($fields[$k] ?? '')) !== '' && trim((string)$e[$k]) === '') $fill[$k] = $fields[$k];
    if ($fill) saveNote($db, $e, $fill);
    return count($fill);
}

/**
 * Imports ICD-10-CM codes from the CMS file (icd10cm-codes-YYYY.txt: "A000    Cholera due to …", or the "order" file
 * with its fixed columns) or a CSV of code,description. Existing codes get the newer description. Returns [read, saved].
 */
function icdImportFile(PDO $db, string $path): array {
    $fh = fopen($path, 'rb');
    if (!$fh) throw new RuntimeException('Can’t read the file.');
    $read = 0; $saved = 0; $batch = [];
    $flush = function () use ($db, &$batch, &$saved) {
        if (!$batch) return;
        $db->prepare("INSERT INTO icd10_codes (code, description, chapter) VALUES " . implode(',', array_fill(0, count($batch), '(?,?,?)'))
                     . " ON DUPLICATE KEY UPDATE description = VALUES(description)")->execute(array_merge(...$batch));
        $saved += count($batch); $batch = [];
    };
    while (($line = fgets($fh)) !== false) {
        $line = rtrim(preg_replace('/^\xEF\xBB\xBF/', '', $line), "\r\n");
        if ($line === '') continue;
        // ICD-10 codes: a letter, a digit, then up to 5 more (a header row like "code,description" never matches)
        if (preg_match('/^\d{5} ([A-Z]\d[0-9A-Z]{1,5})\s+[01] (.{60}) (.+)$/', $line, $m)) [$code, $desc] = [$m[1], $m[3]];   // order file: long description
        elseif (preg_match('/^([A-Z]\d[0-9A-Z]{1,5})\s+(.+)$/', $line, $m)) [$code, $desc] = [$m[1], $m[2]];
        elseif (($c = str_getcsv($line)) && count($c) >= 2 && preg_match('/^[A-Z]\d[0-9A-Z.]{1,6}$/i', trim($c[0]))) [$code, $desc] = [strtoupper(str_replace('.', '', trim($c[0]))), $c[1]];
        else continue;
        $read++;
        $batch[] = [$code, mb_substr(trim($desc), 0, 255), $code[0]];
        if (count($batch) >= 500) $flush();
    }
    fclose($fh);
    $flush();
    if (!$read) throw new RuntimeException('No ICD-10 codes found in that file. Use the CMS “icd10cm-codes” text file, or a CSV of code,description.');
    return [$read, $saved];
}
