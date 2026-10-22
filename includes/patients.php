<?php
// includes/patients.php — the master patient record (Phase 3): MRN, phones, Emirates ID / passport (encrypted with the
// clinic's data key), duplicate checks, lead → patient conversion, merging, the one-off backfill from leads, ID scans and
// documents, and the audit / access logs. Pages: patients.php, patient.php, patient_edit.php, patient_merge.php.
require_once __DIR__ . '/clinic_setup.php';   // nextDocumentNumber() for the MRN
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/phone_helpers.php';       // getLeadPhones()
require_once __DIR__ . '/auth.php';                // currentStaffId()

const PATIENT_GENDERS = ['female' => 'Female', 'male' => 'Male', 'other' => 'Other', 'unknown' => 'Not recorded'];
const PATIENT_RESIDENCY = ['resident' => 'UAE resident', 'citizen' => 'UAE national', 'visitor' => 'Visitor / tourist', 'unknown' => 'Not recorded'];
const PATIENT_DOC_TYPES = ['eid_front' => 'Emirates ID (front)', 'eid_back' => 'Emirates ID (back)', 'passport' => 'Passport', 'insurance_card' => 'Insurance card', 'report' => 'Report', 'other' => 'Other'];
const PATIENT_DOC_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
const PATIENT_DOC_MAX = 10 * 1024 * 1024;
// Plain fields a patient form may set (encrypted ID fields and consents are handled separately)
const PATIENT_FIELDS = ['branch_id', 'title', 'first_name', 'middle_name', 'last_name', 'name_ar', 'gender', 'dob', 'nationality', 'residency', 'emirate',
                        'marital_status', 'occupation', 'email', 'eid_expiry', 'emergency_name', 'emergency_phone', 'emergency_relation', 'preferred_language', 'tags', 'notes', 'source_id'];

/** A phone number in E.164 (+971501234567), or null. Local UAE numbers (05x…, 04…) get +971. */
function normalizePhone(string $raw): ?string {
    $d = preg_replace('/\D/', '', $raw);
    if (str_starts_with(trim($raw), '00')) $d = substr($d, 2);
    elseif (preg_match('/^0\d{8,9}$/', $d)) $d = '971' . substr($d, 1);
    elseif (preg_match('/^5\d{8}$/', $d)) $d = '971' . $d;
    return strlen($d) >= 8 && strlen($d) <= 15 ? '+' . $d : null;
}

function patientName(array $p): string {
    return trim(preg_replace('/\s+/', ' ', ($p['title'] ? $p['title'] . ' ' : '') . $p['first_name'] . ' ' . ($p['middle_name'] ?? '') . ' ' . ($p['last_name'] ?? '')));
}

/** Emirates ID: 15 digits, starts 784, last digit a Luhn check digit. Returns the digits, or null when not valid. */
function eidNormalize(string $raw): ?string {
    $d = preg_replace('/\D/', '', $raw);
    if (!preg_match('/^784\d{12}$/', $d)) return null;
    $sum = 0;
    foreach (str_split(strrev($d)) as $i => $c) { $n = (int)$c * ($i % 2 ? 2 : 1); $sum += $n > 9 ? $n - 9 : $n; }
    return $sum % 10 === 0 ? $d : null;
}

function eidFormat(string $d): string { return substr($d, 0, 3) . '-' . substr($d, 3, 4) . '-' . substr($d, 7, 7) . '-' . substr($d, 14); }

/** Keyed hash of an Emirates ID: finds exact duplicates and searches without decrypting every row. */
function eidHash(string $digits): string { return hash_hmac('sha256', 'eid:' . $digits, tenantDataKey()); }

/** Whether this clinic can encrypt ID numbers yet (its data key is set up). */
function encryptionReady(): bool {
    try { tenantDataKey(); return true; } catch (Throwable $e) { return false; }
}

/** Append-only audit entry (never updated or deleted). */
function auditLog(PDO $db, string $entity, int $entityId, string $action, array $changes = []): void {
    $db->prepare("INSERT INTO audit_log (entity, entity_id, action, changes, staff_id, ip) VALUES (?,?,?,?,?,?)")
       ->execute([$entity, $entityId, $action, $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null, currentStaffId() ?: null, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null]);
}

/** Records that someone opened a patient's chart, ID number or document. */
function logPatientAccess(PDO $db, int $patientId, string $what, ?int $encounterId = null): void {
    $db->prepare("INSERT INTO patient_access_log (patient_id, encounter_id, what, staff_id, ip) VALUES (?,?,?,?,?)")
       ->execute([$patientId, $encounterId, $what, currentStaffId() ?: null, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null]);
}

/** The patient row, following a merge to the record it was merged into. */
function findPatient(PDO $db, int $id): ?array {
    for ($hops = 0; $hops < 5 && $id; $hops++) {
        $s = $db->prepare("SELECT * FROM patients WHERE id = ?");
        $s->execute([$id]);
        $p = $s->fetch();
        if (!$p || $p['status'] !== 'merged' || !$p['merged_into_id']) return $p ?: null;
        $id = (int)$p['merged_into_id'];
    }
    return null;
}

function patientPhones(PDO $db, int $patientId): array {
    $s = $db->prepare("SELECT * FROM patient_phones WHERE patient_id = ? ORDER BY is_primary DESC, id");
    $s->execute([$patientId]);
    return $s->fetchAll();
}

/** Other patients sharing any of these phones, and the patient with this Emirates ID: ['phone' => [rows], 'eid' => row|null]. */
function findPatientDuplicates(PDO $db, array $phones, ?string $eidDigits, int $excludeId = 0): array {
    $out = ['phone' => [], 'eid' => null];
    $phones = array_values(array_unique(array_filter(array_map('normalizePhone', $phones))));
    if ($phones) {
        $s = $db->prepare("SELECT DISTINCT p.id, p.mrn, p.first_name, p.last_name, p.title, p.middle_name, pp.phone FROM patient_phones pp JOIN patients p ON p.id = pp.patient_id
                           WHERE pp.phone IN (" . implode(',', array_fill(0, count($phones), '?')) . ") AND p.id <> ? AND p.status <> 'merged'");
        $s->execute([...$phones, $excludeId]);
        $out['phone'] = $s->fetchAll();
    }
    if ($eidDigits) {
        $s = $db->prepare("SELECT id, mrn, first_name, last_name, title, middle_name FROM patients WHERE eid_hash = ? AND id <> ? AND status <> 'merged' LIMIT 1");
        $s->execute([eidHash($eidDigits), $excludeId]);
        $out['eid'] = $s->fetch() ?: null;
    }
    return $out;
}

/**
 * Creates ($id = 0) or updates a patient. $f holds PATIENT_FIELDS plus optional 'eid', 'passport', 'marketing_consent',
 * 'whatsapp_opt_in', 'data_consent' (an empty eid/passport leaves the stored one unchanged; 'eid_clear' removes it).
 * An Emirates ID already on another patient is refused; shared phone numbers are allowed (families) — check those
 * with findPatientDuplicates() first. Returns the patient id. Throws RuntimeException with a readable reason.
 */
function savePatient(PDO $db, array $f, array $phones, int $id = 0): int {
    $v = [];
    foreach (PATIENT_FIELDS as $k) { $x = trim((string)($f[$k] ?? '')); $v[$k] = $x === '' ? null : $x; }
    if ($v['first_name'] === null) throw new RuntimeException('Enter the first name.');
    foreach (['first_name' => 80, 'middle_name' => 80, 'last_name' => 80, 'name_ar' => 160, 'title' => 20, 'occupation' => 100, 'emergency_name' => 120, 'tags' => 255] as $k => $max)
        if ($v[$k] !== null) $v[$k] = mb_substr($v[$k], 0, $max);
    $v['gender'] = isset(PATIENT_GENDERS[$v['gender'] ?? '']) ? $v['gender'] : 'unknown';
    $v['residency'] = isset(PATIENT_RESIDENCY[$v['residency'] ?? '']) ? $v['residency'] : 'unknown';
    $v['preferred_language'] = $v['preferred_language'] === 'ar' ? 'ar' : 'en';
    if ($v['email'] !== null && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address, or leave it empty.');
    foreach (['dob' => 'date of birth', 'eid_expiry' => 'Emirates ID expiry'] as $k => $what)
        if ($v[$k] !== null && (!($d = DateTimeImmutable::createFromFormat('!Y-m-d', $v[$k])) || $d->format('Y-m-d') !== $v[$k])) throw new RuntimeException("The $what isn’t a valid date.");
    if ($v['dob'] !== null && ($v['dob'] > date('Y-m-d') || $v['dob'] < '1900-01-01')) throw new RuntimeException('The date of birth must be in the past.');
    if ($v['nationality'] !== null && !preg_match('/^[A-Z]{2}$/', $v['nationality'] = strtoupper($v['nationality']))) throw new RuntimeException('Choose a nationality from the list.');
    if ($v['emergency_phone'] !== null) $v['emergency_phone'] = normalizePhone($v['emergency_phone']) ?? throw new RuntimeException('The emergency contact phone doesn’t look right.');
    foreach (['branch_id' => 'branches', 'source_id' => 'lead_sources'] as $k => $table) if ($v[$k] !== null) {
        $c = $db->prepare("SELECT COUNT(*) FROM $table WHERE id = ?"); $c->execute([(int)$v[$k]]);
        $v[$k] = $c->fetchColumn() ? (int)$v[$k] : null;
    }
    $norm = [];
    foreach ($phones as $ph) { if (trim((string)$ph) === '') continue; $n = normalizePhone((string)$ph) ?? throw new RuntimeException("The phone number “{$ph}” doesn’t look right."); $norm[$n] = true; }
    $norm = array_keys($norm);

    $eid = null;
    if (trim((string)($f['eid'] ?? '')) !== '') {
        if (!encryptionReady()) throw new RuntimeException('Emirates ID numbers can’t be stored until encryption is set up for this clinic.');
        $eid = eidNormalize((string)$f['eid']) ?? throw new RuntimeException('That Emirates ID number isn’t valid (15 digits starting 784, e.g. 784-1990-1234567-1).');
        if ($dup = findPatientDuplicates($db, [], $eid, $id)['eid']) throw new RuntimeException('This Emirates ID is already on patient ' . $dup['mrn'] . ' (' . patientName($dup) . ').');
    }
    $passport = trim((string)($f['passport'] ?? ''));
    if ($passport !== '') {
        if (!encryptionReady()) throw new RuntimeException('Passport numbers can’t be stored until encryption is set up for this clinic.');
        if (!preg_match('/^[A-Z0-9]{5,15}$/i', $passport)) throw new RuntimeException('The passport number is 5–15 letters and digits.');
    }

    $own = $db->inTransaction() ? false : $db->beginTransaction();
    try {
        $before = null;
        if ($id) {
            $s = $db->prepare("SELECT * FROM patients WHERE id = ? FOR UPDATE"); $s->execute([$id]);
            $before = $s->fetch() ?: throw new RuntimeException('Patient not found.');
            if ($before['status'] === 'merged') throw new RuntimeException('This record was merged into another patient.');
        }
        $now = date('Y-m-d H:i:s');
        $set = $v;
        foreach (['marketing_consent' => 'marketing_consent_at', 'whatsapp_opt_in' => 'whatsapp_opt_in_at'] as $k => $at) {
            if (!array_key_exists($k, $f)) continue;
            $set[$k] = $f[$k] ? 1 : 0;
            if (!$before || (int)$before[$k] !== $set[$k]) $set[$at] = $set[$k] ? $now : null;
        }
        if (!empty($f['data_consent']) && empty($before['data_consent_at'])) $set['data_consent_at'] = $now;
        if ($eid) { $set['eid_enc'] = tenantEncrypt($eid); $set['eid_hash'] = eidHash($eid); $set['eid_last4'] = substr($eid, -4); }
        elseif (!empty($f['eid_clear'])) { $set['eid_enc'] = $set['eid_hash'] = $set['eid_last4'] = null; }
        if ($passport !== '') $set['passport_enc'] = tenantEncrypt(strtoupper($passport));
        if (!$id) {
            $set['mrn'] = nextDocumentNumber($db, 'mrn', 0);
            $set['lead_id'] = isset($f['lead_id']) ? (int)$f['lead_id'] : null;
            $set['created_by'] = currentStaffId() ?: null;
            $db->prepare("INSERT INTO patients (" . implode(',', array_keys($set)) . ") VALUES (" . implode(',', array_fill(0, count($set), '?')) . ")")->execute(array_values($set));
            $id = (int)$db->lastInsertId();
        } else {
            $db->prepare("UPDATE patients SET " . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . " WHERE id = ?")->execute([...array_values($set), $id]);
        }
        // Phones: replace the list, first one is the main number
        $oldPhones = $before ? array_column(patientPhones($db, $id), 'phone') : [];
        if ($norm || $before) {
            $db->prepare("DELETE FROM patient_phones WHERE patient_id = ?")->execute([$id]);
            $ins = $db->prepare("INSERT INTO patient_phones (patient_id, phone, is_primary) VALUES (?,?,?)");
            foreach ($norm as $i => $n) $ins->execute([$id, $n, $i === 0 ? 1 : 0]);
        }
        // Audit: what changed (ID numbers only as "changed", never their values)
        $diff = [];
        foreach ($set as $k => $val) {
            if (in_array($k, ['eid_enc', 'eid_hash', 'passport_enc', 'mrn', 'created_by'], true) || str_ends_with($k, '_at')) continue;
            $old = $before[$k] ?? null;
            if ((string)$old !== (string)$val) $diff[$k] = $k === 'eid_last4' ? ['from' => $old ? '…' . $old : null, 'to' => $val ? '…' . $val : null] : ['from' => $old, 'to' => $val];
        }
        if ($passport !== '') $diff['passport'] = 'changed';
        if ($oldPhones !== $norm) $diff['phones'] = ['from' => $oldPhones, 'to' => $norm];
        if (!$before || $diff) auditLog($db, 'patient', $id, $before ? 'updated' : 'created', $diff);
        if ($own) $db->commit();
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return $id;
}

/** First / last name from a lead's single name field. Arabic-script names also fill the Arabic name. */
function splitLeadName(string $name): array {
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '' || preg_match('/^(meta lead|whatsapp)\b/i', $name) || preg_match('/^[\d\s+\-().?]+$/', $name)) return ['first_name' => 'Unknown', 'last_name' => null, 'name_ar' => null];
    $parts = explode(' ', $name, 2);
    return ['first_name' => mb_substr($parts[0], 0, 80), 'last_name' => isset($parts[1]) ? mb_substr($parts[1], 0, 80) : null,
            'name_ar' => preg_match('/\p{Arabic}/u', $name) ? mb_substr($name, 0, 160) : null];
}

/**
 * Makes a patient from a lead without touching the lead's own data (only leads.patient_id) and links the lead's
 * appointments. Phone numbers already on another patient are still copied and reported in $collisions.
 * Returns [patient id, created?]. Call inside a transaction.
 */
function patientFromLead(PDO $db, int $leadId, array &$collisions = []): array {
    $s = $db->prepare("SELECT * FROM leads WHERE id = ? FOR UPDATE");
    $s->execute([$leadId]);
    $lead = $s->fetch() ?: throw new RuntimeException('Lead not found.');
    if ($lead['patient_id']) return [(int)$lead['patient_id'], false];
    $s = $db->prepare("SELECT id FROM patients WHERE lead_id = ?");
    $s->execute([$leadId]);
    if ($pid = (int)$s->fetchColumn()) { $db->prepare("UPDATE leads SET patient_id = ?, updated_at = updated_at WHERE id = ?")->execute([$pid, $leadId]); return [$pid, false]; }

    $phones = array_column(getLeadPhones($leadId, $db), 'phone');
    $dups = findPatientDuplicates($db, $phones, null);
    foreach ($dups['phone'] as $d) $collisions[] = ['lead_id' => $leadId, 'lead' => $lead['name'], 'phone' => $d['phone'], 'patient' => $d['mrn'] . ' ' . patientName($d)];
    $src = $db->prepare("SELECT id FROM lead_sources WHERE slug = ?");
    $src->execute([(string)$lead['source']]);
    $staffBranch = $db->prepare("SELECT branch_id FROM staff WHERE id = ?");
    $staffBranch->execute([(int)($lead['assigned_to'] ?: currentStaffId())]);
    $f = splitLeadName((string)$lead['name']) + [
        'email' => filter_var($lead['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null,
        'gender' => in_array($lead['gender'] ?? '', ['male', 'female', 'other'], true) ? $lead['gender'] : 'unknown',
        'source_id' => $src->fetchColumn() ?: null,
        'branch_id' => $staffBranch->fetchColumn() ?: $db->query("SELECT MIN(id) FROM branches WHERE is_active = 1")->fetchColumn(),
        'lead_id' => $leadId,
    ];
    $pid = savePatient($db, $f, array_filter($phones, fn($p) => normalizePhone((string)$p) !== null));
    $db->prepare("UPDATE leads SET patient_id = ?, updated_at = updated_at WHERE id = ?")->execute([$pid, $leadId]);
    $db->prepare("UPDATE appointments SET patient_id = ?, updated_at = updated_at WHERE lead_id = ? AND patient_id IS NULL")->execute([$pid, $leadId]);
    return [$pid, true];
}

/** "Convert lead to patient" on the lead page: makes (or finds) the patient and moves the lead to Converted. */
function convertLeadToPatient(PDO $db, int $leadId): int {
    $db->beginTransaction();
    try {
        $old = $db->prepare("SELECT stage FROM leads WHERE id = ?"); $old->execute([$leadId]);
        $oldStage = (string)$old->fetchColumn();
        [$pid, $created] = patientFromLead($db, $leadId);
        if ($oldStage !== 'converted') {
            $db->prepare("UPDATE leads SET stage = 'converted', updated_at = NOW() WHERE id = ?")->execute([$leadId]);
            setConvertedAt($leadId, 'converted', $oldStage, $db);
            logActivity(currentStaffId(), 'stage_changed', $leadId, $oldStage, 'converted');
        }
        if ($created) logActivity(currentStaffId(), 'converted_to_patient', $leadId, null, (string)$pid);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    try { updateLeadScore($leadId, $db); } catch (Throwable $e) {}
    return $pid;
}

/**
 * One-off backfill (Phase 3): a patient for every lead that has an appointment or is Converted and has no patient yet.
 * Copies phones, links the lead and its appointments; lead data itself is never edited. $dryRun runs everything and
 * rolls it back, so the dry-run report and the real run report the same numbers. Safe to run again (it skips linked leads).
 */
function backfillPatients(PDO $db, bool $dryRun): array {
    $r = ['eligible' => 0, 'created' => 0, 'linked_existing' => 0, 'phones' => 0, 'appointments' => 0, 'collisions' => [], 'errors' => []];
    $ids = $db->query("SELECT l.id FROM leads l WHERE l.patient_id IS NULL
                       AND (l.stage = 'converted' OR EXISTS (SELECT 1 FROM appointments a WHERE a.lead_id = l.id)) ORDER BY l.id")->fetchAll(PDO::FETCH_COLUMN);
    $r['eligible'] = count($ids);
    $db->beginTransaction();
    try {
        foreach ($ids as $lid) {
            $db->exec("SAVEPOINT one_lead");
            try {
                [$pid, $created] = patientFromLead($db, (int)$lid, $r['collisions']);
                $created ? $r['created']++ : $r['linked_existing']++;
                $r['phones'] += (int)$db->query("SELECT COUNT(*) FROM patient_phones WHERE patient_id = $pid")->fetchColumn();
                $r['appointments'] += (int)$db->query("SELECT COUNT(*) FROM appointments WHERE patient_id = $pid AND lead_id = " . (int)$lid)->fetchColumn();
                $db->exec("RELEASE SAVEPOINT one_lead");
            } catch (Throwable $e) {
                $db->exec("ROLLBACK TO SAVEPOINT one_lead");
                $r['errors'][] = "Lead #$lid: " . $e->getMessage();
            }
        }
        if (!$dryRun && $r['created']) auditLog($db, 'patient', 0, 'backfill', ['created' => $r['created'], 'linked' => $r['linked_existing'], 'phones' => $r['phones'], 'appointments' => $r['appointments']]);
        $dryRun ? $db->rollBack() : $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return $r;
}

/**
 * Merges $loserId into $winnerId: every record pointing at the loser (any table with a patient_id column, now and in
 * later phases) moves to the winner, empty details on the winner are filled from the loser, and the loser is marked
 * merged. Refuses two different Emirates IDs (different people). Returns [table => rows moved].
 */
function mergePatients(PDO $db, int $winnerId, int $loserId): array {
    if ($winnerId === $loserId) throw new RuntimeException('Choose two different patients.');
    $db->beginTransaction();
    try {
        $s = $db->prepare("SELECT * FROM patients WHERE id IN (?, ?) ORDER BY id FOR UPDATE");
        $s->execute([$winnerId, $loserId]);
        $rows = array_column($s->fetchAll(), null, 'id');
        [$w, $l] = [$rows[$winnerId] ?? null, $rows[$loserId] ?? null];
        if (!$w || !$l) throw new RuntimeException('Patient not found.');
        if ($w['status'] === 'merged' || $l['status'] === 'merged') throw new RuntimeException('One of these records was already merged.');
        if ($w['eid_hash'] && $l['eid_hash'] && $w['eid_hash'] !== $l['eid_hash']) throw new RuntimeException('These patients have different Emirates IDs, so they are different people.');

        $moved = [];
        $tables = $db->query("SELECT c.table_name, EXISTS (SELECT 1 FROM information_schema.columns u WHERE u.table_schema = c.table_schema AND u.table_name = c.table_name AND u.column_name = 'updated_at') AS has_updated
                              FROM information_schema.columns c WHERE c.table_schema = DATABASE() AND c.column_name = 'patient_id' AND c.table_name <> 'patients'")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($tables as $t => $hasUpdated) {
            $t = preg_replace('/[^A-Za-z0-9_]/', '', $t);
            // IGNORE: a row the winner already has (e.g. the same phone number) stays behind and is removed below.
            // updated_at stays as it was: re-pointing a lead or appointment is not a change to it (reports date leads by it)
            $u = $db->prepare("UPDATE IGNORE `$t` SET patient_id = ?" . ($hasUpdated ? ', updated_at = updated_at' : '') . " WHERE patient_id = ?");
            $u->execute([$winnerId, $loserId]);
            if ($u->rowCount()) $moved[$t] = $u->rowCount();
        }
        $db->prepare("DELETE FROM patient_phones WHERE patient_id = ?")->execute([$loserId]);
        $u = $db->prepare("UPDATE patients SET referred_by_patient_id = ? WHERE referred_by_patient_id = ?");
        $u->execute([$winnerId, $loserId]);
        if ($u->rowCount()) $moved['referrals'] = $u->rowCount();

        // Fill the winner's empty details from the loser
        $fill = [];
        foreach (['title', 'middle_name', 'last_name', 'name_ar', 'dob', 'nationality', 'emirate', 'marital_status', 'occupation', 'email', 'eid_enc', 'eid_hash', 'eid_last4', 'eid_expiry',
                  'passport_enc', 'emergency_name', 'emergency_phone', 'emergency_relation', 'source_id', 'campaign_id', 'branch_id', 'data_consent_at', 'photo_path', 'lead_id'] as $k)
            if (($w[$k] === null || $w[$k] === '') && $l[$k] !== null && $l[$k] !== '') $fill[$k] = $l[$k];
        foreach (['gender' => 'unknown', 'residency' => 'unknown'] as $k => $empty) if ($w[$k] === $empty && $l[$k] !== $empty) $fill[$k] = $l[$k];
        if ($l['notes']) $fill['notes'] = trim(($w['notes'] ?? '') . "\n\n[From merged record " . $l['mrn'] . "]\n" . $l['notes']);
        // The loser gives up its lead link and ID numbers first (lead_id is unique; ID numbers live on one record only)
        $db->prepare("UPDATE patients SET status = 'merged', merged_into_id = ?, lead_id = NULL, eid_enc = NULL, eid_hash = NULL, eid_last4 = NULL, passport_enc = NULL WHERE id = ?")->execute([$winnerId, $loserId]);
        if ($fill) $db->prepare("UPDATE patients SET " . implode(', ', array_map(fn($k) => "$k = ?", array_keys($fill))) . " WHERE id = ?")->execute([...array_values($fill), $winnerId]);
        $db->prepare("UPDATE patients SET merged_into_id = ? WHERE merged_into_id = ?")->execute([$winnerId, $loserId]);   // older merges follow along

        $detail = ['from' => $l['mrn'], 'into' => $w['mrn'], 'moved' => $moved, 'filled' => array_values(array_diff(array_keys($fill), ['eid_enc', 'eid_hash', 'passport_enc']))];
        auditLog($db, 'patient', $winnerId, 'merged_in', $detail);
        auditLog($db, 'patient', $loserId, 'merged_into', $detail);
        $db->commit();
        return $moved;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** Removes location and camera data from a JPEG (APP1/APP13 segments) or PNG (eXIf/text chunks) without re-encoding it. */
function stripImageMetadata(string $bytes, string $mime): string {
    if ($mime === 'image/jpeg' && str_starts_with($bytes, "\xFF\xD8")) {
        $out = "\xFF\xD8"; $i = 2; $n = strlen($bytes);
        while ($i + 4 <= $n && $bytes[$i] === "\xFF") {
            $marker = ord($bytes[$i + 1]);
            if ($marker === 0xDA) break;                                  // start of image data: copy the rest as is
            $len = unpack('n', substr($bytes, $i + 2, 2))[1];
            if (!in_array($marker, [0xE1, 0xED], true)) $out .= substr($bytes, $i, $len + 2);   // drop EXIF/XMP and IPTC
            $i += $len + 2;
        }
        return $out . substr($bytes, $i);
    }
    if ($mime === 'image/png' && str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
        $out = substr($bytes, 0, 8); $i = 8; $n = strlen($bytes);
        while ($i + 12 <= $n) {
            $len = unpack('N', substr($bytes, $i, 4))[1];
            $type = substr($bytes, $i + 4, 4);
            if (!in_array($type, ['eXIf', 'tEXt', 'zTXt', 'iTXt'], true)) $out .= substr($bytes, $i, $len + 12);
            $i += $len + 12;
        }
        return $out;
    }
    return $bytes;
}

/** Saves bytes in the patient's folder (outside the web root) under a random name. The type is read from the content,
 *  never the name; images lose their location/camera data. Returns [relative path, mime, stored bytes]. */
function storePatientFile(int $patientId, string $bytes, array $mimes): array {
    if (strlen($bytes) === 0 || strlen($bytes) > PATIENT_DOC_MAX) throw new RuntimeException('The file is empty or larger than 10 MB.');
    planCheck(getDB(), 'storage_gb', strlen($bytes));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    if (!isset($mimes[$mime])) { $l = array_map('strtoupper', array_values(array_unique($mimes)));
        throw new RuntimeException('Only ' . (count($l) > 1 ? implode(', ', array_slice($l, 0, -1)) . ' or ' : '') . end($l) . ' files can be uploaded.'); }
    $bytes = stripImageMetadata($bytes, $mime);
    $rel = 'patients/' . $patientId . '/' . bin2hex(random_bytes(16)) . '.' . $mimes[$mime];
    $full = tenantStoragePath() . '/' . $rel;
    if (!is_dir(dirname($full)) && !mkdir(dirname($full), 0750, true)) throw new RuntimeException("Can't create the patient's folder.");
    if (file_put_contents($full, $bytes) === false) throw new RuntimeException('Saving the file failed.');
    return [$rel, $mime, $bytes];
}

/** The full path of a stored patient file, or null if it isn't inside this clinic's folder (no ../ tricks, no other clinic's files). */
function patientFilePath(?string $rel): ?string {
    $base = realpath(tenantStoragePath());
    $file = $rel ? realpath($base . '/' . $rel) : false;
    return $file && str_starts_with($file, $base . DIRECTORY_SEPARATOR) && is_file($file) ? $file : null;
}

/** Stores an uploaded file (from $_FILES) for a patient. Returns the document id. */
function storePatientDocument(PDO $db, int $patientId, string $type, array $file): int {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        if (($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('The file is too large (10 MB at most).');
        throw new RuntimeException('Choose a file to upload.');
    }
    return savePatientDocumentBytes($db, $patientId, $type, (string)file_get_contents($file['tmp_name']), (string)($file['name'] ?? ''));
}

/** The storage part of storePatientDocument() (also used by tests). */
function savePatientDocumentBytes(PDO $db, int $patientId, string $type, string $bytes, string $originalName): int {
    if (!isset(PATIENT_DOC_TYPES[$type])) $type = 'other';
    [$rel, $mime, $bytes] = storePatientFile($patientId, $bytes, PATIENT_DOC_MIMES);
    $db->prepare("INSERT INTO patient_documents (patient_id, type, path, original_name, mime, size, uploaded_by) VALUES (?,?,?,?,?,?,?)")
       ->execute([$patientId, $type, $rel, mb_substr(basename($originalName), 0, 190), $mime, strlen($bytes), currentStaffId() ?: null]);
    $docId = (int)$db->lastInsertId();
    auditLog($db, 'patient', $patientId, 'document_added', ['type' => $type, 'document_id' => $docId]);
    return $docId;
}

/** MRN, gender and age for one line under a name. */
function patientSummary(array $p): string {
    $bits = [$p['mrn']];
    if ($p['gender'] !== 'unknown') $bits[] = PATIENT_GENDERS[$p['gender']];
    if ($p['dob']) $bits[] = (new DateTimeImmutable($p['dob']))->diff(new DateTimeImmutable('today'))->y . ' yrs';
    return implode(' · ', $bits);
}
