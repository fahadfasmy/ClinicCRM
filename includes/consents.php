<?php
// includes/consents.php — consent forms and clinical photos.
// Consents: templates (English/Arabic plain text with {patient_name} {procedure} {date} {practitioner}; editing the
// text raises the version), the hand-over to a tablet (a one-use token valid 15 minutes), signing (signature PNGs stored
// with the patient's files plus a frozen HTML copy of the exact text shown, IP, device and time), revoking, and which
// required consents a visit is still missing. Photos: before/after/progress pictures stored outside the web root.
require_once __DIR__ . '/patients.php';

const CONSENT_HANDOFF_MIN = 15;
const CONSENT_SIG_MAX = 400 * 1024;
const PHOTO_STAGES = ['before' => 'Before', 'after' => 'After', 'progress' => 'Progress'];
const PHOTO_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

function consentTemplates(PDO $db, bool $activeOnly = true): array {
    return $db->query("SELECT t.*, (SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ') FROM services s WHERE s.requires_consent_template_id = t.id) AS required_for
                       FROM consent_templates t" . ($activeOnly ? " WHERE t.is_active = 1" : '') . " ORDER BY t.is_active DESC, t.name")->fetchAll();
}

function findConsentTemplate(PDO $db, int $id): ?array {
    $s = $db->prepare("SELECT * FROM consent_templates WHERE id = ?");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** Creates or updates a template. Changing the name or text makes a new version (signed copies keep their own text).
 *  $in['services'] = the service ids that require this consent. Returns the template id. */
function saveConsentTemplate(PDO $db, int $id, array $in): int {
    $f = ['name' => trim((string)($in['name'] ?? '')), 'name_ar' => trim((string)($in['name_ar'] ?? '')) ?: null,
          'body_en' => trim(str_replace("\r\n", "\n", (string)($in['body_en'] ?? ''))), 'body_ar' => trim(str_replace("\r\n", "\n", (string)($in['body_ar'] ?? ''))) ?: null,
          'is_active' => empty($in['is_active']) ? 0 : 1];
    if ($f['name'] === '' || $f['body_en'] === '') throw new RuntimeException('Give the consent a name and its English text.');
    if (mb_strlen($f['name']) > 150 || mb_strlen((string)$f['name_ar']) > 150) throw new RuntimeException('The name is too long (150 characters at most).');
    $db->beginTransaction();
    try {
        if ($id) {
            $old = findConsentTemplate($db, $id);
            if (!$old) throw new RuntimeException('Consent template not found.');
            $changed = $old['name'] !== $f['name'] || $old['name_ar'] !== $f['name_ar'] || $old['body_en'] !== $f['body_en'] || $old['body_ar'] !== $f['body_ar'];
            $db->prepare("UPDATE consent_templates SET name = ?, name_ar = ?, body_en = ?, body_ar = ?, is_active = ?, version = version + ?, updated_by = ? WHERE id = ?")
               ->execute([...array_values($f), $changed ? 1 : 0, currentStaffId() ?: null, $id]);
        } else {
            $db->prepare("INSERT INTO consent_templates (name, name_ar, body_en, body_ar, is_active, updated_by) VALUES (?,?,?,?,?,?)")->execute([...array_values($f), currentStaffId() ?: null]);
            $id = (int)$db->lastInsertId();
        }
        $svc = array_values(array_filter(array_map('intval', (array)($in['services'] ?? []))));
        $db->prepare("UPDATE services SET requires_consent_template_id = NULL WHERE requires_consent_template_id = ?" . ($svc ? ' AND id NOT IN (' . implode(',', $svc) . ')' : ''))->execute([$id]);
        if ($svc) $db->prepare("UPDATE services SET requires_consent_template_id = ? WHERE id IN (" . implode(',', $svc) . ")")->execute([$id]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return $id;
}

/** The text as safe HTML: escaped first, then the {variables} are filled in (also escaped), paragraphs kept. */
function consentText(string $body, array $vars): string {
    $html = sanitize($body);
    foreach ($vars as $k => $v) $html = str_replace('{' . $k . '}', '<strong>' . sanitize((string)$v) . '</strong>', $html);
    return implode('', array_map(fn($p) => '<p>' . nl2br(trim($p)) . '</p>', preg_split('/\n\s*\n/', $html)));
}

/** {patient_name} {procedure} {date} {practitioner} for a patient and (optionally) a visit. */
function consentVars(PDO $db, array $patient, ?int $apptId, ?int $encId): array {
    $proc = $prac = '';
    if ($encId) {
        $s = $db->prepare("SELECT GROUP_CONCAT(p.description ORDER BY p.id SEPARATOR ', '), (SELECT name FROM staff WHERE id = e.practitioner_id), e.appointment_id
                           FROM encounters e LEFT JOIN encounter_procedures p ON p.encounter_id = e.id WHERE e.id = ? GROUP BY e.id");
        $s->execute([$encId]);
        [$proc, $prac, $encAppt] = $s->fetch(PDO::FETCH_NUM) ?: ['', '', null];
        $apptId = $apptId ?: ($encAppt ? (int)$encAppt : null);
    }
    if ($apptId && (!$proc || !$prac)) {
        $s = $db->prepare("SELECT (SELECT GROUP_CONCAT(sv.name ORDER BY sv.name SEPARATOR ', ') FROM appointment_services x JOIN services sv ON sv.id = x.service_id WHERE x.appointment_id = a.id),
                                  sv0.name, st.name FROM appointments a LEFT JOIN services sv0 ON sv0.id = a.service_id LEFT JOIN staff st ON st.id = a.practitioner_id WHERE a.id = ?");
        $s->execute([$apptId]);
        [$many, $one, $p] = $s->fetch(PDO::FETCH_NUM) ?: ['', '', ''];
        $proc = $proc ?: ($many ?: (string)$one);
        $prac = $prac ?: (string)$p;
    }
    return ['patient_name' => patientName($patient), 'procedure' => $proc ?: 'the treatment discussed with me', 'date' => date('d M Y'), 'practitioner' => $prac ?: 'the clinic’s practitioner'];
}

/** The frozen copy stored with a signed consent: exactly the title and text the patient saw, in both languages. */
function consentSnapshot(array $tpl, array $vars): string {
    $h = '<section lang="en" dir="ltr"><h2>' . sanitize($tpl['name']) . '</h2>' . consentText($tpl['body_en'], $vars) . '</section>';
    if ($tpl['body_ar']) $h .= '<section lang="ar" dir="rtl"><h2>' . sanitize($tpl['name_ar'] ?: $tpl['name']) . '</h2>' . consentText($tpl['body_ar'], $vars) . '</section>';
    return $h;
}

/** Staff start a hand-over: returns the one-use token for consent_sign.php (valid CONSENT_HANDOFF_MIN minutes). */
function startConsentHandoff(PDO $db, int $patientId, int $templateId, ?int $apptId = null, ?int $encId = null): string {
    $p = findPatient($db, $patientId);
    if (!$p || $p['status'] !== 'active' || (int)$p['id'] !== $patientId) throw new RuntimeException('Patient not found.');
    $t = findConsentTemplate($db, $templateId);
    if (!$t || !$t['is_active']) throw new RuntimeException('Choose a consent form.');
    foreach (['appointments' => $apptId, 'encounters' => $encId] as $table => $ref) if ($ref) {
        $s = $db->prepare("SELECT patient_id FROM $table WHERE id = ?");
        $s->execute([$ref]);
        if ((int)$s->fetchColumn() !== $patientId) throw new RuntimeException('That visit belongs to another patient.');
    }
    $token = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO consent_handoffs (token_hash, patient_id, template_id, appointment_id, encounter_id, created_by, expires_at) VALUES (?,?,?,?,?,?, NOW() + INTERVAL " . CONSENT_HANDOFF_MIN . " MINUTE)")
       ->execute([hash('sha256', $token), $patientId, $templateId, $apptId ?: null, $encId ?: null, currentStaffId()]);
    return $token;
}

/** The hand-over behind a token, if it is still unused and not expired (with the template and patient). */
function handoffFromToken(PDO $db, string $token, bool $forUpdate = false): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $s = $db->prepare("SELECT * FROM consent_handoffs WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()" . ($forUpdate ? ' FOR UPDATE' : ''));
    $s->execute([hash('sha256', $token)]);
    $h = $s->fetch();
    if (!$h) return null;
    $h['template'] = findConsentTemplate($db, (int)$h['template_id']);
    $h['patient'] = findPatient($db, (int)$h['patient_id']);
    return $h['template'] && $h['patient'] ? $h : null;
}

/** A canvas signature sent as a data: URL → PNG bytes (refuses anything else). */
function signaturePng(string $dataUrl): string {
    if (!str_starts_with($dataUrl, 'data:image/png;base64,')) throw new RuntimeException('Please sign in the box.');
    $bytes = base64_decode(substr($dataUrl, 22), true);
    if ($bytes === false || strlen($bytes) < 60 || strlen($bytes) > CONSENT_SIG_MAX) throw new RuntimeException('The signature could not be read. Please sign again.');
    return $bytes;
}

/** The patient signs on the tablet. Returns the consent id. The token can't be used again. */
function signConsent(PDO $db, string $token, string $signature, string $witnessName = '', string $witnessSignature = ''): int {
    $sig = signaturePng($signature);
    $wit = $witnessSignature !== '' ? signaturePng($witnessSignature) : null;
    $witnessName = mb_substr(trim($witnessName), 0, 150);
    if ($wit && $witnessName === '') throw new RuntimeException('Write the witness’s name.');
    $db->beginTransaction();
    $files = [];
    try {
        $h = handoffFromToken($db, $token, true);
        if (!$h) throw new RuntimeException('This consent link has expired or was already used. Ask the staff to start again.');
        $pid = (int)$h['patient_id'];
        $files[] = $sigRel = storePatientFile($pid, $sig, ['image/png' => 'png'])[0];
        if ($wit) $files[] = $witRel = storePatientFile($pid, $wit, ['image/png' => 'png'])[0];
        $snapshot = consentSnapshot($h['template'], consentVars($db, $h['patient'], $h['appointment_id'] ? (int)$h['appointment_id'] : null, $h['encounter_id'] ? (int)$h['encounter_id'] : null));
        $db->prepare("INSERT INTO patient_consents (patient_id, template_id, template_version, encounter_id, appointment_id, snapshot_html, signature_path, witness_name, witness_signature_path, ip, user_agent, collected_by)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$pid, $h['template_id'], $h['template']['version'], $h['encounter_id'], $h['appointment_id'], $snapshot, $sigRel, $witnessName ?: null, $witRel ?? null,
                      substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null, mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null, $h['created_by']]);
        $cid = (int)$db->lastInsertId();
        $db->prepare("UPDATE consent_handoffs SET used_at = NOW(), consent_id = ? WHERE id = ?")->execute([$cid, $h['id']]);
        auditLog($db, 'patient', $pid, 'consent_signed', ['consent' => $h['template']['name'], 'version' => (int)$h['template']['version'], 'consent_id' => $cid]);
        $db->commit();
        return $cid;
    } catch (Throwable $e) {
        $db->rollBack();
        foreach ($files as $f) if ($full = patientFilePath($f)) @unlink($full);   // nothing half-saved
        throw $e;
    }
}

function revokeConsent(PDO $db, int $consentId, string $reason): void {
    $reason = trim($reason);
    if ($reason === '') throw new RuntimeException('Say why the consent is withdrawn.');
    $s = $db->prepare("UPDATE patient_consents SET revoked_at = NOW(), revoked_by = ?, revoke_reason = ? WHERE id = ? AND revoked_at IS NULL");
    $s->execute([currentStaffId() ?: null, mb_substr($reason, 0, 255), $consentId]);
    if (!$s->rowCount()) throw new RuntimeException('Consent not found or already withdrawn.');
    $pid = (int)$db->query("SELECT patient_id FROM patient_consents WHERE id = " . $consentId)->fetchColumn();
    auditLog($db, 'patient', $pid, 'consent_withdrawn', ['consent_id' => $consentId, 'reason' => $reason]);
}

function findConsent(PDO $db, int $id): ?array {
    $s = $db->prepare("SELECT c.*, t.name, t.name_ar, s.name AS collected_by_name, r.name AS revoked_by_name FROM patient_consents c JOIN consent_templates t ON t.id = c.template_id
                       LEFT JOIN staff s ON s.id = c.collected_by LEFT JOIN staff r ON r.id = c.revoked_by WHERE c.id = ?");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function patientConsents(PDO $db, int $patientId): array {
    $s = $db->prepare("SELECT c.id, c.template_id, c.template_version, c.signed_at, c.revoked_at, c.revoke_reason, c.witness_name, t.name, s.name AS collected_by_name
                       FROM patient_consents c JOIN consent_templates t ON t.id = c.template_id LEFT JOIN staff s ON s.id = c.collected_by WHERE c.patient_id = ? ORDER BY c.signed_at DESC");
    $s->execute([$patientId]);
    return $s->fetchAll();
}

/** The services of a visit: the appointment's services plus the procedures recorded on its note. */
function visitServiceIds(PDO $db, ?int $apptId, ?int $encId): array {
    $ids = [];
    if ($apptId) { $s = $db->prepare("SELECT service_id FROM appointment_services WHERE appointment_id = ? UNION SELECT service_id FROM appointments WHERE id = ?"); $s->execute([$apptId, $apptId]); $ids = $s->fetchAll(PDO::FETCH_COLUMN); }
    if ($encId) { $s = $db->prepare("SELECT service_id FROM encounter_procedures WHERE encounter_id = ?"); $s->execute([$encId]); $ids = [...$ids, ...$s->fetchAll(PDO::FETCH_COLUMN)]; }
    return array_values(array_unique(array_filter(array_map('intval', $ids))));
}

/** Consent forms these services require that the patient hasn't signed (or withdrew): [template id => [name, service names]]. */
function missingConsents(PDO $db, int $patientId, array $serviceIds): array {
    $serviceIds = array_values(array_filter(array_map('intval', $serviceIds)));
    if (!$serviceIds) return [];
    $s = $db->prepare("SELECT t.id, t.name, GROUP_CONCAT(sv.name ORDER BY sv.name SEPARATOR ', ') AS services FROM services sv JOIN consent_templates t ON t.id = sv.requires_consent_template_id AND t.is_active = 1
                       WHERE sv.id IN (" . implode(',', $serviceIds) . ")
                         AND NOT EXISTS (SELECT 1 FROM patient_consents c WHERE c.patient_id = ? AND c.template_id = t.id AND c.revoked_at IS NULL)
                       GROUP BY t.id, t.name ORDER BY t.name");
    $s->execute([$patientId]);
    $out = [];
    foreach ($s as $r) $out[(int)$r['id']] = [$r['name'], $r['services']];
    return $out;
}

// ── Photos ──

/** Stores a clinical photo. $in: area (required), stage, taken_at (Y-m-d, default today), encounter_id, consent_id, notes. */
function savePatientPhoto(PDO $db, int $patientId, string $bytes, array $in): int {
    $area = trim((string)($in['area'] ?? ''));
    if ($area === '' || mb_strlen($area) > 80) throw new RuntimeException('Say which area the photo shows.');
    $stage = isset(PHOTO_STAGES[$in['stage'] ?? '']) ? $in['stage'] : 'before';
    $taken = (string)($in['taken_at'] ?? '') ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $taken) || $taken > date('Y-m-d')) throw new RuntimeException('The photo date is not valid.');
    foreach (['encounter_id' => 'encounters', 'consent_id' => 'patient_consents'] as $k => $table) {
        $in[$k] = (int)($in[$k] ?? 0) ?: null;
        if ($in[$k]) { $s = $db->prepare("SELECT patient_id FROM $table WHERE id = ?"); $s->execute([$in[$k]]); if ((int)$s->fetchColumn() !== $patientId) throw new RuntimeException('That visit or consent belongs to another patient.'); }
    }
    [$rel, $mime, $bytes] = storePatientFile($patientId, $bytes, PHOTO_MIMES);
    $db->prepare("INSERT INTO patient_photos (patient_id, encounter_id, area, stage, path, mime, size, taken_at, consent_id, notes, uploaded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$patientId, $in['encounter_id'], $area, $stage, $rel, $mime, strlen($bytes), $taken, $in['consent_id'], mb_substr(trim((string)($in['notes'] ?? '')), 0, 255) ?: null, currentStaffId() ?: null]);
    $id = (int)$db->lastInsertId();
    auditLog($db, 'patient', $patientId, 'photo_added', ['photo_id' => $id, 'area' => $area, 'stage' => $stage]);
    return $id;
}

function patientPhotos(PDO $db, int $patientId): array {
    $s = $db->prepare("SELECT ph.*, s.name AS by_name FROM patient_photos ph LEFT JOIN staff s ON s.id = ph.uploaded_by WHERE ph.patient_id = ? AND ph.deleted_at IS NULL ORDER BY ph.area, ph.taken_at, ph.id");
    $s->execute([$patientId]);
    return $s->fetchAll();
}

/** Hides a photo taken by mistake. The file stays (it is part of the medical record) and the removal is audited. */
function removePatientPhoto(PDO $db, int $patientId, int $photoId): void {
    $s = $db->prepare("UPDATE patient_photos SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND patient_id = ? AND deleted_at IS NULL");
    $s->execute([currentStaffId() ?: null, $photoId, $patientId]);
    if (!$s->rowCount()) throw new RuntimeException('Photo not found.');
    auditLog($db, 'patient', $patientId, 'photo_removed', ['photo_id' => $photoId]);
}
