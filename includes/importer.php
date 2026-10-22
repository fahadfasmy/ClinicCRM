<?php
// includes/importer.php — moving a clinic over from ClinicSoft (or any system that exports CSV), so the old system can be
// switched off: patients, money still owed and advances, active packages, future appointments, past visits, past
// invoices and clinical notes. (Scanned documents come from a folder: tools/import_documents.php.)
//   importRead()   reads the CSV (comma, semicolon or tab; UTF-8, UTF-16 or Windows Arabic) and guesses the columns
//   importRun()    runs every row; a dry run does exactly the same inside a transaction and rolls it back, so its report
//                  shows what would happen, with nothing saved
// Patients keep their old file number (patients.legacy_id); everything else finds them by it. Each imported row is
// remembered (import_keys), so the same file can be run again safely. Old invoices are records only ('history'); money
// still owed becomes an 'opening_balance' invoice collected here with a normal receipt. Neither counts as revenue or VAT
// here: it was invoiced and reported in the old system.
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/emr.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/gcal.php';
require_once __DIR__ . '/meta_leads.php';

/** [label, required, header names it is found under] per field, per kind. */
const IMPORT_KINDS = [
    'patients' => ['label' => 'Patients', 'help' => 'One row per patient. Matched to existing patients by Emirates ID, or by mobile and first name; otherwise added.', 'fields' => [
        'legacy_id' => ['File number', true, ['file no', 'file number', 'file #', 'file#', 'mrn', 'patient id', 'patient no', 'patientid', 'pid', 'file_no', 'patient code']],
        'full_name' => ['Full name', false, ['patient name', 'name', 'full name', 'patientname', 'patient']],
        'first_name' => ['First name', false, ['first name', 'firstname', 'given name', 'fname']],
        'last_name' => ['Last name', false, ['last name', 'lastname', 'surname', 'family name', 'lname']],
        'name_ar' => ['Name in Arabic', false, ['arabic name', 'name (arabic)', 'name_ar', 'name arabic', 'الاسم']],
        'gender' => ['Gender', false, ['gender', 'sex']],
        'dob' => ['Date of birth', false, ['dob', 'date of birth', 'birth date', 'birthdate', 'd.o.b']],
        'nationality' => ['Nationality', false, ['nationality', 'country', 'nation']],
        'mobile' => ['Mobile', false, ['mobile', 'mobile no', 'mobile number', 'phone', 'contact no', 'contact number', 'tel', 'telephone', 'cell']],
        'phone2' => ['Other phone', false, ['phone 2', 'phone2', 'alternate phone', 'home phone', 'other phone', 'landline']],
        'email' => ['Email', false, ['email', 'e-mail', 'email address', 'email id']],
        'emirates_id' => ['Emirates ID', false, ['emirates id', 'eid', 'national id', 'id no', 'emirates id no', 'uid']],
        'residency' => ['Residency', false, ['residency', 'resident status', 'patient type', 'residence']],
        'notes' => ['Notes', false, ['notes', 'remarks', 'comments', 'remark']],
        'registered_on' => ['Registered on', false, ['registration date', 'reg date', 'registered on', 'created on', 'created date', 'date registered']],
    ]],
    'balances' => ['label' => 'Balances and advances', 'help' => 'Money each patient still owes (collected here with a normal receipt) and money they paid in advance (their wallet).', 'fields' => [
        'legacy_id' => ['File number', true, ['file no', 'file number', 'mrn', 'patient id', 'patient no', 'pid']],
        'due' => ['Amount owed (AED)', false, ['balance', 'due', 'outstanding', 'amount due', 'balance due', 'receivable', 'pending amount', 'dues']],
        'advance' => ['Advance paid (AED)', false, ['advance', 'deposit', 'credit', 'advance balance', 'advance amount', 'credit balance']],
        'reference' => ['Reference', false, ['invoice no', 'reference', 'ref', 'bill no', 'invoice number']],
    ]],
    'packages' => ['label' => 'Active packages', 'help' => 'Packages with sessions left. The package must exist here with the same name (Billing → Packages).', 'fields' => [
        'legacy_id' => ['File number', true, ['file no', 'file number', 'mrn', 'patient id', 'patient no', 'pid']],
        'package' => ['Package name', true, ['package', 'package name', 'plan', 'course']],
        'remaining' => ['Sessions left', false, ['remaining sessions', 'balance sessions', 'sessions left', 'remaining', 'balance', 'pending sessions']],
        'used' => ['Sessions used', false, ['used', 'used sessions', 'consumed', 'sessions used', 'completed sessions']],
        'value' => ['Amount paid (AED)', false, ['amount', 'paid', 'value', 'price', 'amount paid', 'package amount']],
        'sold_on' => ['Bought on', false, ['date', 'purchase date', 'sold on', 'start date', 'bought on']],
        'expires' => ['Expires on', false, ['expiry', 'expiry date', 'valid till', 'valid until', 'expires', 'end date']],
    ]],
    'appointments' => ['label' => 'Future appointments', 'help' => 'Bookings from today on. The treatment must exist here with the same name; the practitioner is matched by name.', 'fields' => [
        'legacy_id' => ['File number', true, ['file no', 'file number', 'mrn', 'patient id', 'patient no', 'pid']],
        'date' => ['Date', true, ['date', 'appointment date', 'appt date', 'visit date']],
        'time' => ['Time', false, ['time', 'appointment time', 'start time', 'from', 'start']],
        'duration' => ['Minutes', false, ['duration', 'minutes', 'length', 'duration (min)']],
        'treatment' => ['Treatment', true, ['treatment', 'service', 'procedure', 'service name', 'appointment type']],
        'practitioner' => ['Practitioner', false, ['doctor', 'practitioner', 'provider', 'therapist', 'doctor name', 'staff']],
        'branch' => ['Branch', false, ['branch', 'location', 'clinic']],
        'notes' => ['Notes', false, ['notes', 'remarks', 'comments']],
        'legacy_ref' => ['Appointment number', false, ['appointment id', 'appointment no', 'appt no', 'booking id', 'appt id']],
    ]],
    'visits' => ['label' => 'Past visits', 'help' => 'Visit history (attended, cancelled, no-shows). Recorded as it was: no stock is taken.', 'fields' => [
        'legacy_id' => ['File number', true, ['file no', 'file number', 'mrn', 'patient id', 'patient no', 'pid']],
        'date' => ['Date', true, ['date', 'visit date', 'appointment date', 'appt date']],
        'time' => ['Time', false, ['time', 'visit time', 'appointment time', 'start time']],
        'treatment' => ['Treatment', false, ['treatment', 'service', 'procedure', 'service name', 'appointment type']],
        'practitioner' => ['Practitioner', false, ['doctor', 'practitioner', 'provider', 'therapist', 'doctor name']],
        'status' => ['Status', false, ['status', 'visit status', 'appointment status']],
        'notes' => ['Notes', false, ['notes', 'remarks', 'comments']],
        'legacy_ref' => ['Visit number', false, ['visit id', 'visit no', 'appointment id', 'appointment no', 'appt no']],
    ]],
    'invoices' => ['label' => 'Past invoices', 'help' => 'Invoice history, kept as a record. Already reported for VAT in the old system, so not counted again. Import money still owed with Balances.', 'fields' => [
        'legacy_id' => ['File number', true, ['file no', 'file number', 'mrn', 'patient id', 'patient no', 'pid']],
        'number' => ['Invoice number', true, ['invoice no', 'invoice number', 'bill no', 'invoice #', 'bill number', 'inv no']],
        'date' => ['Date', true, ['date', 'invoice date', 'bill date']],
        'net' => ['Before VAT (AED)', false, ['subtotal', 'net', 'amount before vat', 'taxable', 'taxable amount', 'gross']],
        'vat' => ['VAT (AED)', false, ['vat', 'tax', 'vat amount', 'tax amount']],
        'total' => ['Total (AED)', true, ['total', 'net amount', 'grand total', 'invoice total', 'total amount', 'amount']],
        'paid' => ['Paid (AED)', false, ['paid', 'received', 'amount paid', 'paid amount', 'collected']],
        'description' => ['What for', false, ['items', 'services', 'description', 'details', 'treatment']],
        'status' => ['Status', false, ['status', 'invoice status']],
    ]],
    'notes' => ['label' => 'Clinical notes', 'help' => 'Past consultation notes, kept read-only in the patient’s clinical notes (marked as from ClinicSoft).', 'fields' => [
        'legacy_id' => ['File number', true, ['file no', 'file number', 'mrn', 'patient id', 'patient no', 'pid']],
        'date' => ['Date', true, ['date', 'visit date', 'note date', 'consultation date']],
        'practitioner' => ['Practitioner', false, ['doctor', 'practitioner', 'provider', 'doctor name', 'author']],
        'complaint' => ['Complaint', false, ['chief complaint', 'complaint', 'presenting complaint', 'cc']],
        'findings' => ['Examination', false, ['examination', 'findings', 'exam', 'clinical findings']],
        'diagnosis' => ['Diagnosis', false, ['diagnosis', 'assessment', 'impression', 'dx']],
        'plan' => ['Plan', false, ['plan', 'treatment plan', 'advice', 'recommendation']],
        'text' => ['Notes', false, ['notes', 'note', 'clinical notes', 'remarks', 'consultation notes', 'progress notes']],
    ]],
];

const IMPORT_MAX_ROWS = 50000;

// ── Reading the file ──

/** The file as [headers, rows] (rows are arrays in header order). Handles BOMs, UTF-16 and Windows Arabic. */
function importRead(string $path): array {
    $raw = (string)file_get_contents($path);
    if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', str_starts_with($raw, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = (string)iconv('WINDOWS-1256', 'UTF-8//IGNORE', $raw);   // Arabic Windows exports
    $first = strtok($raw, "\n") ?: '';
    $counts = ["," => substr_count($first, ','), ";" => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    arsort($counts);
    $delim = array_key_first($counts);
    $h = fopen('php://temp', 'r+'); fwrite($h, $raw); rewind($h);
    $headers = array_map(fn($x) => trim((string)$x), fgetcsv($h, null, $delim) ?: []);
    if (!array_filter($headers)) throw new RuntimeException('The file is empty or has no header row.');
    $rows = [];
    while (($r = fgetcsv($h, null, $delim)) !== false) {
        if ($r === [null] || !array_filter($r, fn($x) => trim((string)$x) !== '')) continue;
        $rows[] = array_map(fn($x) => trim((string)$x), $r);
        if (count($rows) > IMPORT_MAX_ROWS) throw new RuntimeException('More than ' . number_format(IMPORT_MAX_ROWS) . ' rows: split the file.');
    }
    fclose($h);
    return [$headers, $rows];
}

/** Field => column index, guessed from the header names. */
function importGuessMapping(string $kind, array $headers): array {
    $norm = fn($s) => trim(preg_replace('/[^a-z0-9\x{0600}-\x{06FF}#]+/u', ' ', mb_strtolower($s)));
    $hn = array_map($norm, $headers);
    $map = []; $used = [];
    foreach (IMPORT_KINDS[$kind]['fields'] as $f => [, , $aliases]) {
        foreach ($aliases as $a) {
            $i = array_search($norm($a), $hn, true);
            if ($i !== false && !isset($used[$i])) { $map[$f] = $i; $used[$i] = true; break; }
        }
    }
    return $map;
}

// ── Values ──

function impDate(string $v, bool $withTime = false): ?string {
    $v = trim($v);
    if ($v === '') return null;
    if (preg_match('/^\d{5}(\.\d+)?$/', $v)) return date('Y-m-d', (int)round(((float)$v - 25569) * 86400));   // an Excel day number
    $v = preg_replace('/\s+(\d{1,2}:\d{2}(:\d{2})?\s*([AP]M)?)$/i', '', $v);
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y', 'd-M-Y', 'd M Y', 'j-M-y', 'd-M-y', 'Y/m/d', 'M d, Y', 'd F Y'] as $fmt) {
        $d = DateTimeImmutable::createFromFormat('!' . $fmt, $v);
        if (!$d) continue;
        $e = DateTimeImmutable::getLastErrors();   // "31/02/2026" parses with a warning: not a real date
        if ($e === false || (!$e['warning_count'] && !$e['error_count'])) return $d->format('Y-m-d');
    }
    return null;
}

function impTime(string $v): ?string {
    $v = strtoupper(trim($v));
    if ($v === '') return null;
    if (preg_match('/^0?\.\d+$/', $v)) { $m = (int)round((float)$v * 1440); return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); }   // Excel time
    if (!preg_match('/(\d{1,2})[:.](\d{2})(?::\d{2})?\s*(AM|PM)?/', $v, $m)) return null;
    $h = (int)$m[1]; if (($m[3] ?? '') === 'PM' && $h < 12) $h += 12; if (($m[3] ?? '') === 'AM' && $h === 12) $h = 0;
    return $h < 24 && (int)$m[2] < 60 ? sprintf('%02d:%02d', $h, (int)$m[2]) : null;
}

function impMoney(string $v): ?float {
    $v = trim(str_ireplace(['aed', 'dhs', 'dh', 'د.إ', ','], '', $v));
    if ($v === '' || $v === '-') return null;
    $neg = preg_match('/^\((.*)\)$/', $v, $m) ? ($v = $m[1]) !== null : false;
    if (!is_numeric($v)) return null;
    return round(((float)$v) * ($neg ? -1 : 1), 2);
}

function impGender(string $v): string {
    $v = mb_strtolower(trim($v));
    return match (true) { in_array($v, ['m', 'male', 'man', 'ذكر'], true) => 'male', in_array($v, ['f', 'female', 'woman', 'أنثى', 'انثى'], true) => 'female', default => 'unknown' };
}

/** ISO code from a code, a country name or a common nationality word; null if unknown. */
function impNationality(string $v): ?string {
    $v = trim($v);
    if ($v === '') return null;
    if (preg_match('/^[A-Za-z]{2}$/', $v) && isset(COUNTRIES[strtoupper($v)])) return strtoupper($v);
    $l = mb_strtolower($v);
    foreach (COUNTRIES as $cc => $name) if (mb_strtolower($name) === $l) return $cc;
    $words = ['emirati' => 'AE', 'uae' => 'AE', 'u.a.e' => 'AE', 'indian' => 'IN', 'pakistani' => 'PK', 'filipino' => 'PH', 'philippine' => 'PH', 'british' => 'GB', 'uk' => 'GB',
        'american' => 'US', 'usa' => 'US', 'egyptian' => 'EG', 'jordanian' => 'JO', 'lebanese' => 'LB', 'syrian' => 'SY', 'saudi' => 'SA', 'ksa' => 'SA', 'omani' => 'OM',
        'bangladeshi' => 'BD', 'sri lankan' => 'LK', 'nepali' => 'NP', 'nepalese' => 'NP', 'russian' => 'RU', 'iranian' => 'IR', 'iraqi' => 'IQ', 'sudanese' => 'SD',
        'moroccan' => 'MA', 'tunisian' => 'TN', 'algerian' => 'DZ', 'palestinian' => 'PS', 'yemeni' => 'YE', 'kuwaiti' => 'KW', 'qatari' => 'QA', 'bahraini' => 'BH',
        'french' => 'FR', 'german' => 'DE', 'italian' => 'IT', 'spanish' => 'ES', 'chinese' => 'CN', 'canadian' => 'CA', 'australian' => 'AU', 'south african' => 'ZA',
        'nigerian' => 'NG', 'kenyan' => 'KE', 'ethiopian' => 'ET', 'ukrainian' => 'UA', 'turkish' => 'TR', 'afghan' => 'AF', 'kazakh' => 'KZ', 'uzbek' => 'UZ',
        'إماراتي' => 'AE', 'اماراتي' => 'AE', 'هندي' => 'IN', 'مصري' => 'EG', 'سعودي' => 'SA', 'أردني' => 'JO', 'سوري' => 'SY', 'لبناني' => 'LB'];
    return $words[$l] ?? null;
}

// ── Running ──

/**
 * Runs every row of a file. $map = field => column index. A dry run ($commit false) does everything inside a
 * transaction and rolls it back. Returns ['ok' => n, 'skipped' => n, 'error' => n, 'rows' => [[line, result, message], …]].
 */
function importRun(PDO $db, string $kind, array $headers, array $rows, array $map, bool $commit, ?int $runId = null): array {
    if (!isset(IMPORT_KINDS[$kind])) throw new RuntimeException('Unknown kind of import.');
    $missing = array_keys(array_filter(IMPORT_KINDS[$kind]['fields'], fn($f, $k) => $f[1] && !isset($map[$k]), ARRAY_FILTER_USE_BOTH));
    if ($missing) throw new RuntimeException('Match these columns first: ' . implode(', ', array_map(fn($k) => IMPORT_KINDS[$kind]['fields'][$k][0], $missing)) . '.');
    if ($kind === 'patients' && !isset($map['full_name']) && !isset($map['first_name'])) throw new RuntimeException('Match the name column (full name, or first name).');
    if ($kind === 'notes' && !array_intersect(['complaint', 'findings', 'diagnosis', 'plan', 'text'], array_keys($map))) throw new RuntimeException('Match at least one note column.');
    @set_time_limit(900);
    $out = ['ok' => 0, 'skipped' => 0, 'error' => 0, 'rows' => []];
    $ctx = importContext($db);
    // MySQL commits silently on any CREATE/ALTER TABLE: the lazy "make sure the table exists" checks run now, before the
    // transaction, so a check run can never save anything half-way
    foreach (['ensureInventorySchema', 'ensureGcalSchema', 'ensureMetaSchema', 'ensureMailSchema', 'ensurePermissionSchema'] as $fn) if (function_exists($fn)) $fn($db);
    $db->beginTransaction();
    try {
        foreach ($rows as $n => $r) {
            $v = [];
            foreach ($map as $f => $i) $v[$f] = (string)($r[$i] ?? '');
            $db->exec('SAVEPOINT import_row');
            try {
                [$result, $msg] = importRow($db, $kind, $v, $ctx, $runId);
                $db->exec('RELEASE SAVEPOINT import_row');
            } catch (Throwable $e) {
                try { $db->exec('ROLLBACK TO SAVEPOINT import_row'); }
                catch (PDOException $lost) { throw new RuntimeException('The import stopped: the database ended its transaction unexpectedly (row ' . ($n + 2) . '). Nothing more was changed; please tell support.'); }
                [$result, $msg] = ['error', $e->getMessage()];
            }
            $out[$result === 'error' ? 'error' : ($result === 'skipped' ? 'skipped' : 'ok')]++;
            if ($result !== 'added' || $msg !== '') $out['rows'][] = [$n + 2, $result, $msg];   // line 1 is the header
        }
        $commit ? $db->commit() : $db->rollBack();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return $out;
}

/** Lookups shared by all rows (treatments, staff, branches, packages by lower-case name). */
function importContext(PDO $db): array {
    $low = fn(array $rows) => array_change_key_case(array_column($rows, 'id', 'name'), CASE_LOWER);
    return [
        'services' => $low($db->query("SELECT id, name FROM services WHERE is_active = 1")->fetchAll()),
        'staff' => $db->query("SELECT id, name FROM staff WHERE is_active = 1 AND COALESCE(is_support, 0) = 0")->fetchAll(),
        'branches' => $low($db->query("SELECT id, name FROM branches WHERE is_active = 1")->fetchAll()),
        'branch' => (int)$db->query("SELECT id FROM branches WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn(),
        'packages' => $low($db->query("SELECT id, name FROM packages WHERE is_active = 1")->fetchAll()),
    ];
}

function importKeySeen(PDO $db, string $kind, string $key): ?int {
    $s = $db->prepare("SELECT entity_id FROM import_keys WHERE kind = ? AND legacy_key = ?"); $s->execute([$kind, mb_substr($key, 0, 120)]);
    $id = $s->fetchColumn();
    return $id === false ? null : (int)$id;
}
function importKeyRemember(PDO $db, string $kind, string $key, int $id, ?int $runId): void {
    $db->prepare("INSERT INTO import_keys (kind, legacy_key, entity_id, run_id) VALUES (?,?,?,?)")->execute([$kind, mb_substr($key, 0, 120), $id, $runId]);
}

function importPatient(PDO $db, string $legacy): int {
    $s = $db->prepare("SELECT id FROM patients WHERE legacy_id = ? AND status <> 'merged'"); $s->execute([trim($legacy)]);
    return (int)($s->fetchColumn() ?: throw new RuntimeException("No patient with file number “{$legacy}”: import the patients first."));
}

/** A staff member by name ("Dr Sara Ali" finds "Sara Ali"); null when not found. */
function importStaff(array $ctx, string $name): ?int {
    $n = mb_strtolower(trim(preg_replace('/^(dr\.?|doctor|د\.)\s*/iu', '', $name)));
    if ($n === '') return null;
    foreach ($ctx['staff'] as $s) { $sn = mb_strtolower(preg_replace('/^(dr\.?|doctor)\s*/i', '', $s['name'])); if ($sn === $n || str_contains($sn, $n) || str_contains($n, $sn)) return (int)$s['id']; }
    return null;
}

/** One row. Returns [added|linked|skipped|error, message]. Throws for a row that can't be imported. */
function importRow(PDO $db, string $kind, array $v, array $ctx, ?int $runId): array {
    $legacy = trim($v['legacy_id'] ?? '');
    if ($legacy === '') throw new RuntimeException('No file number.');
    switch ($kind) {
        case 'patients':
            $s = $db->prepare("SELECT id FROM patients WHERE legacy_id = ?"); $s->execute([$legacy]);
            if ($s->fetchColumn()) return ['skipped', 'Already imported.'];
            $first = trim($v['first_name'] ?? ''); $last = trim($v['last_name'] ?? '');
            if ($first === '' && trim($v['full_name'] ?? '') !== '') { $parts = preg_split('/\s+/u', trim($v['full_name'])); $first = array_shift($parts); $last = implode(' ', $parts); }
            if ($first === '') throw new RuntimeException('No name.');
            $notes = [];
            $dob = impDate($v['dob'] ?? '');
            if (trim($v['dob'] ?? '') !== '' && !$dob) $notes[] = 'date of birth “' . $v['dob'] . '” not understood (left empty)';
            if ($dob && ($dob > date('Y-m-d') || $dob < '1900-01-01')) { $notes[] = "date of birth $dob is impossible (left empty)"; $dob = null; }
            $nat = impNationality($v['nationality'] ?? '');
            if (trim($v['nationality'] ?? '') !== '' && !$nat) $notes[] = 'nationality “' . $v['nationality'] . '” not recognised (left empty)';
            $phones = []; foreach (['mobile', 'phone2'] as $k) if (trim($v[$k] ?? '') !== '') { if ($ph = normalizePhone($v[$k])) $phones[] = $ph; else $notes[] = "phone “{$v[$k]}” not understood (left out)"; }
            $email = strtolower(trim($v['email'] ?? '')); if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $notes[] = "email “{$email}” not valid (left out)"; $email = ''; }
            $eid = preg_replace('/\D/', '', $v['emirates_id'] ?? '');
            if ($eid !== '' && (!eidNormalize($eid) || !encryptionReady())) { $notes[] = encryptionReady() ? 'Emirates ID not valid (left out)' : 'Emirates ID left out: encryption isn’t set up'; $eid = ''; }
            $res = mb_strtolower(trim($v['residency'] ?? ''));
            $residency = str_contains($res, 'visit') || str_contains($res, 'tour') ? 'visitor' : (str_contains($res, 'citizen') || str_contains($res, 'national') ? 'citizen' : (str_contains($res, 'resident') ? 'resident' : 'unknown'));
            // Already here (e.g. a converted lead)? Match by Emirates ID, or by mobile + first name
            $match = null;
            if ($eid !== '' && ($d = findPatientDuplicates($db, [], eidNormalize($eid), 0)['eid'])) $match = (int)$d['id'];
            if (!$match && $phones) {
                $q = $db->prepare("SELECT p.id FROM patients p JOIN patient_phones ph ON ph.patient_id = p.id WHERE ph.phone = ? AND LOWER(p.first_name) = LOWER(?) AND p.legacy_id IS NULL AND p.status = 'active' LIMIT 1");
                $q->execute([$phones[0], $first]); $match = (int)$q->fetchColumn() ?: null;
            }
            if ($match) {
                $db->prepare("UPDATE patients SET legacy_id = ?, dob = COALESCE(dob, ?), nationality = COALESCE(nationality, ?), email = COALESCE(email, ?), name_ar = COALESCE(name_ar, ?),
                              gender = IF(gender = 'unknown', ?, gender) WHERE id = ?")->execute([$legacy, $dob, $nat, $email ?: null, trim($v['name_ar'] ?? '') ?: null, impGender($v['gender'] ?? ''), $match]);
                $ins = $db->prepare("INSERT IGNORE INTO patient_phones (patient_id, phone, is_primary) VALUES (?, ?, 0)");
                foreach ($phones as $ph) $ins->execute([$match, $ph]);
                auditLog($db, 'patient', $match, 'linked_import', ['legacy_id' => $legacy]);
                return ['linked', 'Matched to an existing patient and linked' . ($notes ? '; ' . implode('; ', $notes) : '') . '.'];
            }
            $pid = savePatient($db, ['first_name' => $first, 'last_name' => $last, 'name_ar' => $v['name_ar'] ?? '', 'gender' => impGender($v['gender'] ?? ''), 'dob' => $dob, 'nationality' => $nat,
                'email' => $email, 'residency' => $residency, 'notes' => $v['notes'] ?? '', 'branch_id' => $ctx['branch'], 'eid' => $eid], $phones);
            $reg = impDate($v['registered_on'] ?? '');
            $db->prepare("UPDATE patients SET legacy_id = ?" . ($reg ? ", created_at = ?" : '') . " WHERE id = ?")->execute(array_merge([$legacy], $reg ? [$reg . ' 09:00:00'] : [], [$pid]));
            return ['added', $notes ? ucfirst(implode('; ', $notes)) . '.' : ''];

        case 'balances':
            $pid = importPatient($db, $legacy);
            if (importKeySeen($db, 'balance', $legacy) !== null) return ['skipped', 'Already imported.'];
            $due = impMoney($v['due'] ?? '') ?? 0.0; $adv = impMoney($v['advance'] ?? '') ?? 0.0;
            if ($due < 0) { $adv += -$due; $due = 0; }   // a negative balance is money the clinic holds for the patient
            if ($due == 0 && $adv == 0) return ['skipped', 'Nothing owed and no advance.'];
            $msg = [];
            if ($due > 0) {
                $db->prepare("INSERT INTO invoices (number, branch_id, patient_id, type, status, issued_at, gross, net, vat, total, patient_share, imported, legacy_ref, notes, issued_by, created_by)
                              VALUES (?, ?, ?, 'cash', 'issued', NOW(), ?, ?, 0, ?, ?, 'opening_balance', ?, 'Balance carried over from ClinicSoft', ?, ?)")
                   ->execute(['OB-' . $legacy, $ctx['branch'], $pid, $due, $due, $due, $due, mb_substr(trim($v['reference'] ?? ''), 0, 60) ?: null, currentStaffId() ?: null, currentStaffId() ?: null]);
                $inv = (int)$db->lastInsertId();
                $db->prepare("INSERT INTO invoice_lines (invoice_id, kind, description, qty, unit_price, taxable, vat_rate, vat_amount, line_total, patient_share) VALUES (?, 'manual', ?, 1, ?, ?, 0, 0, ?, ?)")
                   ->execute([$inv, 'Opening balance from ClinicSoft' . (trim($v['reference'] ?? '') !== '' ? ' (' . trim($v['reference']) . ')' : ''), $due, $due, $due, $due]);
                $msg[] = 'owes AED ' . number_format($due, 2);
            }
            if ($adv > 0) { walletEntry($db, $pid, 'adjustment', fils($adv), ['branch_id' => $ctx['branch'], 'note' => 'Advance carried over from ClinicSoft']); $msg[] = 'advance AED ' . number_format($adv, 2); }
            importKeyRemember($db, 'balance', $legacy, $pid, $runId);
            auditLog($db, 'patient', $pid, 'opening_balance_imported', ['due' => $due, 'advance' => $adv]);
            return ['added', ucfirst(implode(', ', $msg)) . '.'];

        case 'packages':
            $pid = importPatient($db, $legacy);
            $pkg = $ctx['packages'][mb_strtolower(trim($v['package'] ?? ''))] ?? throw new RuntimeException('No package called “' . ($v['package'] ?? '') . '” here: add it in Billing → Packages with the same name.');
            $sold = impDate($v['sold_on'] ?? '') ?? date('Y-m-d');
            $key = "$legacy|$pkg|$sold";
            if (importKeySeen($db, 'package', $key) !== null) return ['skipped', 'Already imported.'];
            $items = $db->query("SELECT service_id, sessions FROM package_items WHERE package_id = " . (int)$pkg)->fetchAll();
            if (!$items) throw new RuntimeException('That package has no treatments set up.');
            $total = array_sum(array_column($items, 'sessions'));
            $remaining = trim($v['remaining'] ?? '') !== '' ? (int)$v['remaining'] : (trim($v['used'] ?? '') !== '' ? $total - (int)$v['used'] : $total);
            if ($remaining <= 0) return ['skipped', 'No sessions left.'];
            if ($remaining > $total) throw new RuntimeException("$remaining sessions left, but the package has only $total.");
            if (count($items) > 1 && $remaining !== $total) throw new RuntimeException('This package covers several treatments and some sessions were used: add it by hand on the patient’s Packages tab.');
            $p = $db->query("SELECT * FROM packages WHERE id = " . (int)$pkg)->fetch();
            $exp = impDate($v['expires'] ?? '') ?? date('Y-m-d', strtotime("$sold +" . max(1, (int)$p['validity_days']) . ' days'));
            if ($exp < date('Y-m-d')) return ['skipped', "Expired on $exp."];
            $value = impMoney($v['value'] ?? '') ?? (float)$p['price'];
            $db->prepare("INSERT INTO patient_packages (patient_id, package_id, value, starts_on, expires_on, status) VALUES (?,?,?,?,?, 'active')")->execute([$pid, $pkg, $value, $sold, $exp]);
            $pp = (int)$db->lastInsertId();
            $ins = $db->prepare("INSERT INTO patient_package_sessions (patient_package_id, service_id, total, used) VALUES (?,?,?,?)");
            foreach ($items as $it) $ins->execute([$pp, $it['service_id'], $it['sessions'], count($items) === 1 ? $total - $remaining : 0]);
            importKeyRemember($db, 'package', $key, $pp, $runId);
            return ['added', "$remaining of $total sessions left, until $exp."];

        case 'appointments':
        case 'visits':
            $pid = importPatient($db, $legacy);
            $date = impDate($v['date'] ?? '') ?? throw new RuntimeException('Date “' . ($v['date'] ?? '') . '” not understood.');
            $time = impTime($v['time'] ?? '') ?? ($kind === 'visits' ? '09:00' : throw new RuntimeException('Time “' . ($v['time'] ?? '') . '” not understood.'));
            $start = "$date $time";
            $key = trim($v['legacy_ref'] ?? '') !== '' ? trim($v['legacy_ref']) : "$legacy|$start";
            if (importKeySeen($db, $kind, $key) !== null) return ['skipped', 'Already imported.'];
            $svc = trim($v['treatment'] ?? '') !== '' ? ($ctx['services'][mb_strtolower(trim($v['treatment']))] ?? null) : null;
            $prac = importStaff($ctx, $v['practitioner'] ?? '');
            $branch = $ctx['branches'][mb_strtolower(trim($v['branch'] ?? ''))] ?? $ctx['branch'];
            $note = trim('Imported from ClinicSoft. ' . ($v['notes'] ?? ''));
            $warn = [];
            if (trim($v['practitioner'] ?? '') !== '' && !$prac) $warn[] = 'practitioner “' . $v['practitioner'] . '” not found (left empty)';
            if ($kind === 'appointments') {
                if ($start < date('Y-m-d H:i')) throw new RuntimeException("$start is in the past: import it with Past visits.");
                if (!$svc) throw new RuntimeException('No treatment called “' . ($v['treatment'] ?? '') . '” here: add it to the Treatment menu with the same name.');
                [$apptId] = createAppointment($db, ['service_ids' => [$svc], 'start' => $start, 'duration_min' => (int)($v['duration'] ?? 0) ?: null, 'patient_id' => $pid, 'practitioner_id' => $prac,
                    'branch_id' => $branch, 'booked_via' => 'staff', 'notes' => $note, 'allow_outside_hours' => true]);
            } else {
                $st = mb_strtolower($v['status'] ?? '');
                $status = str_contains($st, 'cancel') ? 'cancelled' : (str_contains($st, 'no') && str_contains($st, 'show') || str_contains($st, 'absent') ? 'no_show' : 'completed');
                if ($start > date('Y-m-d H:i')) throw new RuntimeException("$start is in the future: import it with Future appointments.");
                if (!$svc && trim($v['treatment'] ?? '') !== '') $note .= ' Treatment: ' . trim($v['treatment']) . '.';
                if (!$svc) {   // a treatment that isn't offered any more: a hidden placeholder, the old name is in the notes
                    $svc = (int)$db->query("SELECT id FROM services WHERE name = 'Visit (from ClinicSoft)'")->fetchColumn();
                    if (!$svc) { $db->exec("INSERT INTO services (name, price, vat_rate, duration_min, is_active) VALUES ('Visit (from ClinicSoft)', 0, 0, 30, 0)"); $svc = (int)$db->lastInsertId(); }
                }
                $db->prepare("INSERT INTO appointments (patient_id, service_id, scheduled_at, ends_at, duration_min, practitioner_id, branch_id, status, booked_via, notes, completed_at, created_at)
                              VALUES (?,?,?, ? + INTERVAL 30 MINUTE, 30, ?,?,?, 'staff', ?, ?, ?)")
                   ->execute([$pid, $svc, $start, $start, $prac, $branch, $status, $note, $status === 'completed' ? $start : null, $start]);
                $apptId = (int)$db->lastInsertId();
                if ($svc) $db->prepare("INSERT INTO appointment_services (appointment_id, service_id, qty) VALUES (?, ?, 1)")->execute([$apptId, $svc]);
            }
            importKeyRemember($db, $kind, $key, (int)$apptId, $runId);
            return ['added', $warn ? ucfirst(implode('; ', $warn)) . '.' : ''];

        case 'invoices':
            $pid = importPatient($db, $legacy);
            $num = trim($v['number'] ?? '') ?: throw new RuntimeException('No invoice number.');
            if (importKeySeen($db, 'invoice', $num) !== null) return ['skipped', 'Already imported.'];
            $date = impDate($v['date'] ?? '') ?? throw new RuntimeException('Date “' . ($v['date'] ?? '') . '” not understood.');
            $total = impMoney($v['total'] ?? '') ?? throw new RuntimeException('Total “' . ($v['total'] ?? '') . '” not understood.');
            $vat = impMoney($v['vat'] ?? '') ?? 0.0;
            $net = impMoney($v['net'] ?? '') ?? round($total - $vat, 2);
            if (abs($net + $vat - $total) > 0.05) throw new RuntimeException("Before VAT $net + VAT $vat ≠ total $total.");
            $paid = impMoney($v['paid'] ?? '') ?? $total;
            $void = (bool)preg_match('/void|cancel/i', $v['status'] ?? '');
            $db->prepare("INSERT INTO invoices (number, branch_id, patient_id, type, status, issued_at, gross, net, vat, total, patient_share, paid, imported, legacy_ref, notes, issued_by, created_by)
                          VALUES (?, ?, ?, 'cash', ?, ?, ?, ?, ?, ?, ?, ?, 'history', ?, 'Invoice from ClinicSoft (record only)', ?, ?)")
               ->execute(['CS-' . $num, $ctx['branch'], $pid, $void ? 'void' : 'paid', "$date 12:00:00", $net, $net, $vat, $total, $total, min($paid, $total), $num, currentStaffId() ?: null, currentStaffId() ?: null]);
            $inv = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO invoice_lines (invoice_id, kind, description, qty, unit_price, taxable, vat_rate, vat_amount, line_total, patient_share) VALUES (?, 'manual', ?, 1, ?, ?, ?, ?, ?, ?)")
               ->execute([$inv, mb_substr(trim($v['description'] ?? '') ?: 'Treatment (ClinicSoft invoice ' . $num . ')', 0, 200), $net, $net, $net > 0 ? round($vat / $net * 100, 2) : 0, $vat, $total, $total]);
            importKeyRemember($db, 'invoice', $num, $inv, $runId);
            return ['added', !$void && $paid < $total - 0.005 ? 'AED ' . number_format($total - $paid, 2) . ' was still owed: bring it over with Balances.' : ''];

        case 'notes':
            $pid = importPatient($db, $legacy);
            $date = impDate($v['date'] ?? '') ?? throw new RuntimeException('Date “' . ($v['date'] ?? '') . '” not understood.');
            $parts = array_map(fn($k) => trim($v[$k] ?? ''), ['complaint' => 'complaint', 'findings' => 'findings', 'diagnosis' => 'diagnosis', 'plan' => 'plan', 'text' => 'text']);
            if (!array_filter($parts)) return ['skipped', 'Empty note.'];
            $key = "$legacy|$date|" . substr(hash('sha256', implode('|', $parts)), 0, 16);
            if (importKeySeen($db, 'note', $key) !== null) return ['skipped', 'Already imported.'];
            $prac = importStaff($ctx, $v['practitioner'] ?? '');
            $assess = trim($parts['diagnosis'] . ($parts['diagnosis'] !== '' && $parts['text'] !== '' ? "\n\n" : '') . $parts['text']);
            $db->prepare("INSERT INTO encounters (patient_id, branch_id, practitioner_id, status, chief_complaint, examination, assessment, plan, data, signed_at, locked_at, created_at)
                          VALUES (?, ?, ?, 'signed', ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$pid, $ctx['branch'], $prac, $parts['complaint'] ?: null, $parts['findings'] ?: null, $assess ?: null, $parts['plan'] ?: null,
                          json_encode(['source' => 'clinicsoft', 'author' => trim($v['practitioner'] ?? '')], JSON_UNESCAPED_UNICODE), "$date 12:00:00", "$date 12:00:00", "$date 12:00:00"]);
            $eid = (int)$db->lastInsertId();
            $db->prepare("UPDATE encounters SET content_hash = ? WHERE id = ?")->execute([encounterHash($db, $eid), $eid]);
            importKeyRemember($db, 'note', $key, $eid, $runId);
            return ['added', ''];
    }
    throw new RuntimeException('Unknown kind of import.');
}

/** A CSV of the rows that need attention (for the person fixing the file). */
function importReportCsv(array $report): string {
    $h = fopen('php://temp', 'r+'); fwrite($h, "\xEF\xBB\xBF");
    fputcsv($h, ['Line in the file', 'Result', 'What to do']);
    foreach ($report['rows'] as $r) fputcsv($h, $r);
    rewind($h); $s = stream_get_contents($h); fclose($h);
    return $s;
}

/** An empty CSV with the columns we recognise first, for clinics exporting by hand. */
function importTemplateCsv(string $kind): string {
    return "\xEF\xBB\xBF" . implode(',', array_map(fn($f) => '"' . $f[0] . '"', IMPORT_KINDS[$kind]['fields'])) . "\r\n";
}
