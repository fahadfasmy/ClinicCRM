<?php
// includes/gcal.php — each staff member can connect their own Google Calendar; appointments assigned to them as
// practitioner then appear there (one-way: the CRM stays the place to book, move and cancel).
// OAuth "web server" flow per staff member (pages/google_calendar.php), refresh token kept in staff_google.
// Event titles are deliberately minimal (no phone, treatment or notes) because Google stores data outside the UAE.
require_once __DIR__ . '/db.php';

const GCAL_SCOPE = 'https://www.googleapis.com/auth/calendar.events openid email';

class GcalException extends RuntimeException {}

function ensureGcalSchema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $opts = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $db->exec("CREATE TABLE IF NOT EXISTS integration_settings (k VARCHAR(60) PRIMARY KEY, v TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) $opts");
    $db->exec("CREATE TABLE IF NOT EXISTS staff_google (
        staff_id INT UNSIGNED PRIMARY KEY,
        google_email VARCHAR(190) NULL,
        refresh_token TEXT NOT NULL,
        access_token TEXT NULL,
        access_expires INT UNSIGNED NULL,
        last_error VARCHAR(500) NULL,
        connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $opts");
    // Which Google event belongs to which appointment; pending = still to be pushed (retried by the cron)
    $db->exec("CREATE TABLE IF NOT EXISTS appointment_gcal (
        appointment_id INT UNSIGNED PRIMARY KEY,
        staff_id INT UNSIGNED NULL,
        event_id VARCHAR(255) NULL,
        pending TINYINT(1) NOT NULL DEFAULT 0,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        error VARCHAR(500) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_pending (pending)
    ) $opts");
    $done = true;
}

function gcalSetting(PDO $db, string $k): string {
    ensureGcalSchema($db);
    $s = $db->prepare("SELECT v FROM integration_settings WHERE k = ?");
    $s->execute(["gcal.$k"]);
    return (string)$s->fetchColumn();
}

function gcalSaveSetting(PDO $db, string $k, string $v): void {
    ensureGcalSchema($db);
    $db->prepare("REPLACE INTO integration_settings (k, v) VALUES (?, ?)")->execute(["gcal.$k", $v]);
}

/** Google's addresses; the local test points them at a stand-in. */
function gcalUrl(PDO $db, string $which): string {
    $base = ['auth' => 'https://accounts.google.com', 'token' => 'https://oauth2.googleapis.com', 'api' => 'https://www.googleapis.com'][$which];
    return rtrim(gcalSetting($db, $which . '_base') ?: $base, '/');
}

function gcalConfigured(PDO $db): bool {
    return gcalSetting($db, 'client_id') !== '' && gcalSetting($db, 'client_secret') !== '';
}

function gcalRedirectUri(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/pages/google_calendar.php';
}

/** One HTTP call to Google. Returns the decoded JSON (or [] for empty replies); throws GcalException with Google's reason. */
function gcalHttp(string $method, string $url, ?array $json = null, ?array $form = null, ?string $token = null): array {
    require_once __DIR__ . '/data_flows.php';
    outboundGuard($url);
    $ch = curl_init($url);
    $headers = $token ? ["Authorization: Bearer $token"] : [];
    if ($json !== null) { $body = json_encode($json, JSON_UNESCAPED_UNICODE); $headers[] = 'Content-Type: application/json'; }
    elseif ($form !== null) { $body = http_build_query($form); $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers]);
    if (isset($body)) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new GcalException("Can’t reach Google ($err)");
    $data = $raw === '' ? [] : (json_decode($raw, true) ?? []);
    if ($code >= 400) {
        $msg = is_array($data['error'] ?? null) ? ($data['error']['message'] ?? 'error') : ($data['error_description'] ?? $data['error'] ?? "HTTP $code");
        throw new GcalException((string)$msg, $code);
    }
    return $data;
}

/** The Google sign-in address for one staff member ($state ties the reply to their session). */
function gcalAuthUrl(PDO $db, string $state): string {
    return gcalUrl($db, 'auth') . '/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => gcalSetting($db, 'client_id'), 'redirect_uri' => gcalRedirectUri(), 'response_type' => 'code',
        'scope' => GCAL_SCOPE, 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state,
    ]);
}

/** Finishes sign-in: swaps the code for tokens and stores the connection. Returns the Google email. */
function gcalConnect(PDO $db, int $staffId, string $code): string {
    $t = gcalHttp('POST', gcalUrl($db, 'token') . '/token', null, [
        'code' => $code, 'client_id' => gcalSetting($db, 'client_id'), 'client_secret' => gcalSetting($db, 'client_secret'),
        'redirect_uri' => gcalRedirectUri(), 'grant_type' => 'authorization_code',
    ]);
    if (empty($t['refresh_token'])) throw new GcalException('Google didn’t give lasting access. Remove the app at myaccount.google.com → Security → Third-party access, then connect again.');
    if (strpos((string)($t['scope'] ?? ''), 'calendar.events') === false) throw new GcalException('Calendar access wasn’t allowed. Connect again and tick the calendar box on Google’s screen.');
    $email = null;
    if (!empty($t['id_token']) && count($parts = explode('.', $t['id_token'])) === 3) {
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $email = $claims['email'] ?? null;    // came straight from Google's token endpoint over HTTPS
    }
    ensureGcalSchema($db);
    $db->prepare("REPLACE INTO staff_google (staff_id, google_email, refresh_token, access_token, access_expires, connected_at) VALUES (?,?,?,?,?,NOW())")
       ->execute([$staffId, $email, $t['refresh_token'], $t['access_token'] ?? null, time() + (int)($t['expires_in'] ?? 0) - 60]);
    return (string)$email;
}

/** Disconnects one staff member (asks Google to forget the access too). Their existing events stay in their calendar. */
function gcalDisconnect(PDO $db, int $staffId): void {
    $row = gcalStaff($db, $staffId);
    if ($row) { try { gcalHttp('POST', gcalUrl($db, 'token') . '/revoke', null, ['token' => $row['refresh_token']]); } catch (Throwable $e) {} }
    $db->prepare("DELETE FROM staff_google WHERE staff_id = ?")->execute([$staffId]);
    $db->prepare("DELETE FROM appointment_gcal WHERE staff_id = ?")->execute([$staffId]);
}

function gcalStaff(PDO $db, int $staffId): ?array {
    ensureGcalSchema($db);
    $s = $db->prepare("SELECT * FROM staff_google WHERE staff_id = ?");
    $s->execute([$staffId]);
    return $s->fetch() ?: null;
}

/** A working access token for this staff member (refreshed when needed). */
function gcalToken(PDO $db, array &$row): string {
    if (!empty($row['access_token']) && (int)$row['access_expires'] > time()) return $row['access_token'];
    try {
        $t = gcalHttp('POST', gcalUrl($db, 'token') . '/token', null, [
            'client_id' => gcalSetting($db, 'client_id'), 'client_secret' => gcalSetting($db, 'client_secret'),
            'refresh_token' => $row['refresh_token'], 'grant_type' => 'refresh_token',
        ]);
    } catch (GcalException $e) {
        if (stripos($e->getMessage(), 'invalid_grant') !== false || stripos($e->getMessage(), 'expired or revoked') !== false) {
            $msg = 'Google access was removed or expired. Connect your Google Calendar again.';
            $db->prepare("UPDATE staff_google SET last_error = ? WHERE staff_id = ?")->execute([$msg, $row['staff_id']]);
            throw new GcalException($msg);
        }
        throw $e;
    }
    $row['access_token'] = $t['access_token'];
    $row['access_expires'] = time() + (int)($t['expires_in'] ?? 3600) - 60;
    $db->prepare("UPDATE staff_google SET access_token = ?, access_expires = ?, last_error = NULL WHERE staff_id = ?")
       ->execute([$row['access_token'], $row['access_expires'], $row['staff_id']]);
    return $row['access_token'];
}

/** The event for an appointment: minimal title, Dubai time, treatment duration, link back to the CRM. */
function gcalEventBody(PDO $db, array $a): array {
    $name = trim((string)$a['lead_name']);
    $parts = preg_split('/\s+/u', $name);
    $short = count($parts) > 1 ? $parts[0] . ' ' . mb_substr(end($parts), 0, 1) . '.' : $name;
    $start = new DateTimeImmutable($a['scheduled_at'], new DateTimeZone('Asia/Dubai'));
    $end = $start->modify('+' . max(5, (int)($a['duration_min'] ?: 30)) . ' minutes');
    $host = gcalSetting($db, 'crm_host') ?: tenantHost();
    return [
        'summary' => 'Appointment #' . $a['id'] . ($short !== '' ? ' – ' . $short : ''),
        'description' => 'Booked in Clinic CRM. Details: https://' . $host . '/pages/appointment.php?id=' . $a['id'],
        'start' => ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => 'Asia/Dubai'],
        'end' => ['dateTime' => $end->format('Y-m-d\TH:i:s'), 'timeZone' => 'Asia/Dubai'],
        'extendedProperties' => ['private' => ['crm_appointment' => (string)$a['id']]],
    ];
}

/** Marks an appointment as needing its Google event updated, then tries straight away. Never throws. */
function gcalQueue(PDO $db, int $apptId): void {
    try {
        ensureGcalSchema($db);
        $db->prepare("INSERT INTO appointment_gcal (appointment_id, pending) VALUES (?, 1) ON DUPLICATE KEY UPDATE pending = 1, attempts = 0, error = NULL")->execute([$apptId]);
        // Never call Google while a database transaction is open (it would hold locks); the hourly job picks it up
        if (!$db->inTransaction()) gcalSync($db, $apptId);
    } catch (Throwable $e) {
        // Recorded on the row by gcalSync; the hourly job retries
    }
}

/**
 * Makes Google match the appointment: an event in the practitioner's calendar while it is booked or completed,
 * none when cancelled or when the practitioner has no connected calendar. Moves the event when the practitioner changes.
 */
function gcalSync(PDO $db, int $apptId): void {
    ensureGcalSchema($db);
    $m = $db->prepare("SELECT * FROM appointment_gcal WHERE appointment_id = ?");
    $m->execute([$apptId]);
    $map = $m->fetch() ?: ['staff_id' => null, 'event_id' => null];
    $a = $db->prepare("SELECT a.id, a.scheduled_at, a.status, a.practitioner_id, COALESCE(a.duration_min, s.duration_min) AS duration_min,
                              COALESCE(NULLIF(TRIM(CONCAT_WS(' ', pt.first_name, pt.last_name)), ''), l.name, '') AS lead_name
                       FROM appointments a LEFT JOIN leads l ON l.id = a.lead_id LEFT JOIN patients pt ON pt.id = a.patient_id
                       JOIN services s ON s.id = a.service_id WHERE a.id = ?");
    $a->execute([$apptId]);
    $appt = $a->fetch();
    $target = $appt && in_array($appt['status'], ['booked', 'confirmed', 'arrived', 'in_triage', 'with_doctor', 'completed'], true) && $appt['practitioner_id'] && gcalConfigured($db)
            ? gcalStaff($db, (int)$appt['practitioner_id']) : null;
    $done = function (?int $staffId, ?string $eventId) use ($db, $apptId) {
        $db->prepare("UPDATE appointment_gcal SET staff_id = ?, event_id = ?, pending = 0, error = NULL WHERE appointment_id = ?")->execute([$staffId, $eventId, $apptId]);
    };
    try {
        // Remove the event from a calendar it no longer belongs in
        if ($map['event_id'] && (!$target || (int)$map['staff_id'] !== (int)$target['staff_id'])) {
            $old = gcalStaff($db, (int)$map['staff_id']);
            if ($old) {
                try { gcalHttp('DELETE', gcalUrl($db, 'api') . '/calendar/v3/calendars/primary/events/' . rawurlencode($map['event_id']), null, null, gcalToken($db, $old)); }
                catch (GcalException $e) { if (!in_array($e->getCode(), [404, 410], true)) throw $e; }   // already gone
            }
            $map = ['staff_id' => null, 'event_id' => null];
        }
        if (!$target) { $done(null, null); return; }
        $body = gcalEventBody($db, $appt);
        $token = gcalToken($db, $target);
        $base = gcalUrl($db, 'api') . '/calendar/v3/calendars/primary/events';
        if ($map['event_id']) {
            try { $ev = gcalHttp('PUT', $base . '/' . rawurlencode($map['event_id']), $body, null, $token); }
            catch (GcalException $e) {
                if (!in_array($e->getCode(), [404, 410], true)) throw $e;
                $ev = gcalHttp('POST', $base, $body, null, $token);   // deleted in Google by hand: put it back
            }
        } else {
            $ev = gcalHttp('POST', $base, $body, null, $token);
        }
        $done((int)$target['staff_id'], (string)$ev['id']);
    } catch (Throwable $e) {
        $db->prepare("UPDATE appointment_gcal SET pending = 1, attempts = LEAST(attempts + 1, 255), error = ? WHERE appointment_id = ?")
           ->execute([mb_substr($e->getMessage(), 0, 500), $apptId]);
        throw $e;
    }
}

/** After someone connects: put their upcoming booked appointments in their calendar. Returns how many. */
function gcalBackfill(PDO $db, int $staffId): int {
    $ids = $db->prepare("SELECT id FROM appointments WHERE practitioner_id = ? AND status IN ('booked','confirmed') AND scheduled_at >= CURDATE() ORDER BY scheduled_at LIMIT 200");
    $ids->execute([$staffId]);
    $n = 0;
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) { gcalQueue($db, (int)$id); $n++; }
    return $n;
}

/** Hourly: retry appointments whose Google update failed (up to 10 tries each). */
function gcalRetryPending(PDO $db): array {
    ensureGcalSchema($db);
    $out = ['synced' => 0, 'failed' => 0];
    foreach ($db->query("SELECT appointment_id FROM appointment_gcal WHERE pending = 1 AND attempts < 10 ORDER BY appointment_id LIMIT 100")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        try { gcalSync($db, (int)$id); $out['synced']++; } catch (Throwable $e) { $out['failed']++; }
    }
    return $out;
}
