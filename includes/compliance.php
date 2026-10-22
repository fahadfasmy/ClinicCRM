<?php
// includes/compliance.php — Phase 13: the staff notification bell, the audit and chart-access log searches, a patient's
// data export and anonymise (PDPL requests), nightly backups and their status, and restore drills.
require_once __DIR__ . '/patients.php';
require_once __DIR__ . '/inventory.php';   // csrfField, requireCsrf, metaGet/metaSet

// ── Notifications (staff_notifications: to one person, or to everyone with a permission) ──

/** The SQL condition (and its argument) for notifications this staff member can see. */
function notifWhere(PDO $db): array {
    static $cache = [];
    $me = currentStaffId();
    if (!isset($cache[$me])) {
        $perms = array_values(array_filter($db->query("SELECT DISTINCT permission FROM staff_notifications WHERE permission IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN), fn($p) => can($p)));
        $in = $perms ? ' OR permission IN (' . implode(',', array_map([$db, 'quote'], $perms)) . ')' : '';
        $cache[$me] = "(staff_id = $me OR (staff_id IS NULL AND (permission IS NULL$in)))";
    }
    return [$cache[$me]];
}

function notifCount(PDO $db): int {
    try { return (int)$db->query("SELECT COUNT(*) FROM staff_notifications WHERE read_at IS NULL AND " . notifWhere($db)[0])->fetchColumn(); }
    catch (PDOException $e) { return 0; }   // table not there yet (before the Phase 10 update)
}

function notifList(PDO $db, bool $unreadOnly, int $limit = 100): array {
    return $db->query("SELECT n.*, s.name AS read_by_name FROM staff_notifications n LEFT JOIN staff s ON s.id = n.read_by
                       WHERE " . notifWhere($db)[0] . ($unreadOnly ? ' AND n.read_at IS NULL' : '') . " ORDER BY n.read_at IS NULL DESC, n.id DESC LIMIT $limit")->fetchAll();
}

/** Marks one (or, with null, all) of the notifications this person can see as done. Shared ones are done for everyone. */
function notifMarkRead(PDO $db, ?int $id): int {
    return $db->exec("UPDATE staff_notifications SET read_at = NOW(), read_by = " . currentStaffId() . " WHERE read_at IS NULL AND " . notifWhere($db)[0] . ($id ? " AND id = $id" : ''));
}

// ── Audit and access logs (read-only views; nothing here changes them) ──

/** audit_log rows matching: entity, entity_id, staff, action (part), from, to. Newest first. */
function auditSearch(PDO $db, array $f, int $limit = 200, int $offset = 0): array {
    [$w, $a] = auditFilters($f, 'a.at', 'a.staff_id');
    if (($f['entity'] ?? '') !== '') { $w[] = 'a.entity = ?'; $a[] = $f['entity']; }
    if ((int)($f['entity_id'] ?? 0)) { $w[] = 'a.entity_id = ?'; $a[] = (int)$f['entity_id']; }
    if (($f['action'] ?? '') !== '') { $w[] = 'a.action LIKE ?'; $a[] = '%' . $f['action'] . '%'; }
    $s = $db->prepare("SELECT a.*, s.name AS staff FROM audit_log a LEFT JOIN staff s ON s.id = a.staff_id" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY a.id DESC LIMIT $limit OFFSET $offset");
    $s->execute($a);
    return $s->fetchAll();
}

/** patient_access_log rows matching: patient (id or MRN), staff, what, from, to. Newest first. */
function accessSearch(PDO $db, array $f, int $limit = 200, int $offset = 0): array {
    [$w, $a] = auditFilters($f, 'l.at', 'l.staff_id');
    $p = trim((string)($f['patient'] ?? ''));
    if ($p !== '') { $w[] = '(l.patient_id = ? OR p.mrn = ?)'; $a[] = ctype_digit($p) ? (int)$p : 0; $a[] = $p; }
    if (($f['what'] ?? '') !== '') { $w[] = 'l.what = ?'; $a[] = $f['what']; }
    $s = $db->prepare("SELECT l.*, s.name AS staff, p.mrn, CONCAT_WS(' ', p.first_name, p.last_name) AS patient FROM patient_access_log l LEFT JOIN staff s ON s.id = l.staff_id
                       LEFT JOIN patients p ON p.id = l.patient_id" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY l.id DESC LIMIT $limit OFFSET $offset");
    $s->execute($a);
    return $s->fetchAll();
}

function auditFilters(array $f, string $atCol, string $staffCol): array {
    $w = []; $a = [];
    if ((int)($f['staff'] ?? 0)) { $w[] = "$staffCol = ?"; $a[] = (int)$f['staff']; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'] ?? '')) { $w[] = "$atCol >= ?"; $a[] = $f['from']; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'] ?? '')) { $w[] = "$atCol < ? + INTERVAL 1 DAY"; $a[] = $f['to']; }
    return [$w, $a];
}

// ── A patient's data: export and anonymise ──

/** Columns never exported (secrets, encrypted or binary values, internal hashes and tokens). */
function exportSkipColumn(string $name, string $type): bool {
    return (bool)preg_match('/(_enc|_hash|token|password|secret)$/', $name) || (bool)preg_match('/blob|binary/i', $type);
}

/**
 * Everything held about a patient, table by table (every table with a patient_id, plus the lead they came from and the
 * lines of their invoices, receipts and prescriptions). Emirates ID and passport are shown as their last digits only.
 */
function patientExport(PDO $db, int $pid): array {
    $p = $db->prepare("SELECT * FROM patients WHERE id = ?"); $p->execute([$pid]);
    $patient = $p->fetch() ?: throw new RuntimeException('Patient not found.');
    $schema = $db->query("SELECT table_name, column_name, data_type FROM information_schema.columns WHERE table_schema = DATABASE() ORDER BY table_name, ordinal_position")->fetchAll();
    $cols = [];
    foreach ($schema as $c) $cols[$c['table_name'] ?? $c['TABLE_NAME']][$c['column_name'] ?? $c['COLUMN_NAME']] = $c['data_type'] ?? $c['DATA_TYPE'];
    $pick = function (string $t, string $where, array $args) use ($db, $cols): array {
        $keep = array_keys(array_filter($cols[$t] ?? [], fn($type, $name) => !exportSkipColumn($name, $type), ARRAY_FILTER_USE_BOTH));
        if (!$keep) return [];
        $s = $db->prepare("SELECT `" . implode('`,`', $keep) . "` FROM `$t` WHERE $where"); $s->execute($args);
        return $s->fetchAll();
    };
    $out = ['exported_at' => date('c'), 'clinic' => clinicName(), 'patient' => $pick('patients', 'id = ?', [$pid])[0]];
    $out['patient']['emirates_id'] = $patient['eid_last4'] ? '…' . $patient['eid_last4'] : null;
    foreach (array_keys($cols) as $t) {
        if ($t === 'patients' || $t === 'patient_access_log' || !isset($cols[$t]['patient_id'])) continue;
        $rows = $pick($t, 'patient_id = ?', [$pid]);
        if ($rows) $out[$t] = $rows;
    }
    $ids = fn(string $t) => array_map('intval', array_column($out[$t] ?? [], 'id'));
    foreach (['invoices' => ['invoice_lines', 'invoice_id'], 'receipts' => ['receipt_methods', 'receipt_id'], 'prescriptions' => ['prescription_items', 'prescription_id'], 'encounters' => ['encounter_diagnoses', 'encounter_id']] as $parent => [$child, $fk])
        if ($ids($parent)) $out[$child] = $pick($child, "$fk IN (" . implode(',', $ids($parent)) . ')', []);
    if ($patient['lead_id']) $out['lead'] = $pick('leads', 'id = ?', [(int)$patient['lead_id']]);
    $out['chart_opened'] = $db->query("SELECT l.at, l.what, s.name AS by_staff FROM patient_access_log l LEFT JOIN staff s ON s.id = l.staff_id WHERE l.patient_id = $pid ORDER BY l.id DESC LIMIT 500")->fetchAll();
    logPatientAccess($db, $pid, 'export');
    auditLog($db, 'patient', $pid, 'exported', ['tables' => count($out) - 3]);
    return array_filter($out, fn($v) => $v !== []);
}

/**
 * Anonymises a patient on request: names, contact details, identity numbers and scans, photo, notes, tags and consents
 * to contact are removed, and the lead they came from is blanked too. Kept (the law needs them): invoices, receipts and
 * credit notes as issued (tax record), clinical notes, prescriptions, consents and clinical photos (medical record),
 * insurance claims. $confirm must be the patient's MRN. Refused while they have future appointments.
 */
function anonymisePatient(PDO $db, int $pid, string $reason, string $confirm): void {
    $s = $db->prepare("SELECT * FROM patients WHERE id = ?"); $s->execute([$pid]);
    $p = $s->fetch() ?: throw new RuntimeException('Patient not found.');
    if ($p['status'] === 'anonymised') throw new RuntimeException('This patient is already anonymised.');
    if ($p['status'] === 'merged') throw new RuntimeException('This record was merged into another; anonymise that one.');
    if (trim($confirm) !== $p['mrn']) throw new RuntimeException('Type the patient’s MRN exactly to confirm.');
    if (mb_strlen(trim($reason)) < 5) throw new RuntimeException('Write down why (for example, “Patient asked by email on 1 Nov”).');
    $f = $db->prepare("SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND scheduled_at > NOW() AND status IN ('booked','confirmed')"); $f->execute([$pid]);
    if ($f->fetchColumn()) throw new RuntimeException('Cancel their future appointments first.');
    $docs = $db->prepare("SELECT id, path FROM patient_documents WHERE patient_id = ? AND type IN ('eid_front','eid_back','passport','insurance_card')"); $docs->execute([$pid]);
    $docs = $docs->fetchAll();
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE patients SET title = NULL, first_name = 'Anonymised', middle_name = NULL, last_name = ?, name_ar = NULL, dob = IF(dob IS NULL, NULL, MAKEDATE(YEAR(dob), 1)),
                      marital_status = NULL, occupation = NULL, email = NULL, eid_enc = NULL, eid_hash = NULL, eid_last4 = NULL, eid_expiry = NULL, passport_enc = NULL,
                      emergency_name = NULL, emergency_phone = NULL, emergency_relation = NULL, referred_by_patient_id = NULL, marketing_consent = 0, whatsapp_opt_in = 0,
                      tags = NULL, notes = NULL, photo_path = NULL, status = 'anonymised' WHERE id = ?")->execute(['#' . $p['mrn'], $pid]);
        $phones = $db->prepare("SELECT phone FROM patient_phones WHERE patient_id = ?"); $phones->execute([$pid]);
        $phones = $phones->fetchAll(PDO::FETCH_COLUMN);
        $db->prepare("DELETE FROM patient_phones WHERE patient_id = ?")->execute([$pid]);
        if ($p['lead_id']) $db->prepare("UPDATE leads SET name = 'Anonymised', phone = '', email = NULL, notes = NULL, referrer_name = NULL, reenquiry_note = NULL WHERE id = ?")->execute([(int)$p['lead_id']]);
        $db->prepare("UPDATE messages SET to_from = '', body = '[anonymised]' WHERE patient_id = ?" . ($p['lead_id'] ? ' OR lead_id = ' . (int)$p['lead_id'] : ''))->execute([$pid]);
        if ($phones) { $in = implode(',', array_map([$db, 'quote'], $phones)); $db->exec("UPDATE messages SET to_from = '', body = '[anonymised]' WHERE to_from IN ($in)"); $db->exec("DELETE FROM otp_codes WHERE phone IN ($in)"); }
        $db->prepare("UPDATE recalls SET status = 'dismissed', note = NULL WHERE patient_id = ? AND status = 'open'")->execute([$pid]);
        $db->prepare("UPDATE feedback SET comment = NULL WHERE patient_id = ?")->execute([$pid]);
        if ($docs) $db->exec("DELETE FROM patient_documents WHERE id IN (" . implode(',', array_map(fn($d) => (int)$d['id'], $docs)) . ')');
        auditLog($db, 'patient', $pid, 'anonymised', ['reason' => mb_substr(trim($reason), 0, 300), 'identity_documents_removed' => count($docs)]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    foreach ($docs as $d) { $file = tenantStoragePath() . '/' . ltrim((string)$d['path'], '/\\'); if (is_file($file)) @unlink($file); }
    if ($p['photo_path']) { $file = tenantStoragePath() . '/' . ltrim((string)$p['photo_path'], '/\\'); if (is_file($file)) @unlink($file); }
    logPatientAccess($db, $pid, 'anonymise');
}

// ── Backups and restore drills ──

/** Nightly backup from cron (once a day after 02:00 clinic time): 14 daily + the first of each month for 12 months. */
function nightlyBackupDue(PDO $db): bool {
    $now = new DateTimeImmutable('now', new DateTimeZone(CLINIC_TZ));
    return (int)$now->format('G') >= 2 && ((json_decode((string)metaGet($db, 'backup_last'), true) ?: [])['day'] ?? '') !== $now->format('Y-m-d');
}

function backupStatus(PDO $db): array {
    $last = json_decode((string)metaGet($db, 'backup_last'), true) ?: [];
    $dbName = $db->query("SELECT DATABASE()")->fetchColumn();
    $files = glob(rtrim(BACKUP_PATH, '/\\') . '/' . $dbName . '_*.sql.gz') ?: [];
    rsort($files);
    return ['last' => $last, 'count' => count($files), 'files' => array_map(fn($f) => ['name' => basename($f), 'size' => filesize($f), 'at' => date('Y-m-d H:i', filemtime($f))], array_slice($files, 0, 20))];
}

function recordRestoreDrill(PDO $db, array $in): int {
    $on = (string)($in['drilled_on'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || $on > date('Y-m-d')) throw new RuntimeException('Enter the day of the drill.');
    $file = trim((string)($in['backup_file'] ?? ''));
    if ($file === '') throw new RuntimeException('Enter which backup file was restored.');
    $res = ($in['result'] ?? '') === 'pass' ? 'pass' : (($in['result'] ?? '') === 'fail' ? 'fail' : throw new RuntimeException('Choose whether the drill passed.'));
    $db->prepare("INSERT INTO restore_drills (drilled_on, backup_file, result, tables_count, rows_count, notes, staff_id) VALUES (?,?,?,?,?,?,?)")
       ->execute([$on, mb_substr($file, 0, 200), $res, (int)($in['tables_count'] ?? 0) ?: null, (int)($in['rows_count'] ?? 0) ?: null, mb_substr(trim((string)($in['notes'] ?? '')), 0, 500) ?: null, currentStaffId() ?: null]);
    $id = (int)$db->lastInsertId();
    auditLog($db, 'restore_drill', $id, 'recorded', ['result' => $res, 'file' => $file]);
    return $id;
}
