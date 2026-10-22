<?php
// includes/meta_leads.php — Meta Lead Ads ⇄ CRM.
//   In:  Lead Ads submissions become CRM leads — instantly by webhook (meta_receiver.php), plus an hourly
//        check from cron for anything the webhook missed, plus a one-off import of the last 90 days.
//   Out: when a Meta lead changes stage, the stage is sent to Meta (Conversions API for CRM), so Leads Center
//        shows it and ads can optimise for leads that actually book.
// Credentials live in integration_settings (set on the Meta Leads page), never in code.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/phone_helpers.php';
require_once __DIR__ . '/api_keys.php';     // findLeadByPhoneLoose()
require_once __DIR__ . '/reenquiry.php';    // recordReEnquiry()
require_once __DIR__ . '/lead_email.php';   // queueLeadEmail()

const META_DEFAULTS = [
    'graph_version' => 'v23.0',
    'graph_base'    => 'https://graph.facebook.com',
    'verify_token'  => 'clinic_crm_verify_2024',     // what the existing Meta webhook setup used
    'assign'        => '',                           // '' nobody, 'rr' round robin, or a staff id
    'capi_enabled'  => '0',
];
// CRM stage → event name Meta shows in Leads Center. Meta wants every funnel stage, starting with the raw lead.
const META_STAGE_EVENTS = [
    'new' => 'Lead', 'contacted' => 'Contacted', 'qualified' => 'Qualified', 'booked' => 'Booked',
    'converted' => 'Converted', 'lost' => 'Lost', 'cold' => 'Cold',
];
const META_LEAD_FIELDS = 'id,created_time,field_data,ad_id,ad_name,adset_name,campaign_name,form_id,platform,is_organic';

function ensureMetaSchema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $opts = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $db->exec("CREATE TABLE IF NOT EXISTS integration_settings (k VARCHAR(60) PRIMARY KEY, v TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) $opts");
    $db->exec("CREATE TABLE IF NOT EXISTS meta_forms (id VARCHAR(40) PRIMARY KEY, name VARCHAR(200) NULL, status VARCHAR(20) NULL,
        questions TEXT NULL, synced_at DATETIME NULL) $opts");
    $db->exec("CREATE TABLE IF NOT EXISTS meta_capi_events (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        lead_id INT UNSIGNED NOT NULL,
        stage VARCHAR(20) NOT NULL,
        event_name VARCHAR(40) NOT NULL,
        status ENUM('queued','sent','error') NOT NULL DEFAULT 'queued',
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        error VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME NULL,
        UNIQUE KEY uq_lead_stage (lead_id, stage),
        KEY idx_status (status)
    ) $opts");
    // Fast look-up of a Meta lead id, and polling/backfill imports logged alongside webhooks
    if (!$db->query("SHOW INDEX FROM leads WHERE Key_name = 'idx_meta_lead_id'")->fetch()) {
        $db->exec("ALTER TABLE leads ADD INDEX idx_meta_lead_id (meta_lead_id)");
    }
    $col = $db->query("SHOW COLUMNS FROM meta_webhook_log LIKE 'via'")->fetch();
    if (!$col) $db->exec("ALTER TABLE meta_webhook_log ADD COLUMN via VARCHAR(12) NOT NULL DEFAULT 'webhook' AFTER status");
    // Phase 11: Schedule and Purchase events carry a value (Purchase: the invoice's net amount, AED)
    if (!$db->query("SHOW COLUMNS FROM meta_capi_events LIKE 'value'")->fetch()) $db->exec("ALTER TABLE meta_capi_events ADD COLUMN value DECIMAL(12,2) NULL, ADD COLUMN currency CHAR(3) NULL");
    $done = true;
}

// ─────────── Settings ───────────
function metaSetting(PDO $db, string $k): string {
    static $cache = null;
    if ($cache === null || $k === '__reload') {
        ensureMetaSchema($db);
        $cache = $db->query("SELECT k, v FROM integration_settings WHERE k LIKE 'meta.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($k === '__reload') return '';
    }
    $v = $cache["meta.$k"] ?? null;
    return $v === null || $v === '' ? (META_DEFAULTS[$k] ?? '') : $v;
}

function metaSaveSetting(PDO $db, string $k, string $v): void {
    ensureMetaSchema($db);
    $db->prepare("REPLACE INTO integration_settings (k, v) VALUES (?, ?)")->execute(["meta.$k", $v]);
    metaSetting($db, '__reload');
}

function metaConfigured(PDO $db): bool {
    return metaSetting($db, 'page_id') !== '' && metaSetting($db, 'token') !== '';
}

// ─────────── Graph API ───────────
class MetaApiException extends RuntimeException {}

/** Calls the Graph API. GET params go in the URL, POST params as a JSON body. Throws MetaApiException. */
function metaGraph(PDO $db, string $path, array $params = [], string $method = 'GET', ?string $token = null): array {
    $token ??= metaSetting($db, 'token');
    if ($token === '') throw new MetaApiException('No Meta access token saved yet.');
    $url = rtrim(metaSetting($db, 'graph_base'), '/') . '/' . metaSetting($db, 'graph_version') . '/' . ltrim($path, '/');
    require_once __DIR__ . '/data_flows.php';
    outboundGuard($url);
    $ch = curl_init();
    if ($method === 'GET') {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params + ['access_token' => $token]);
    } else {
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($params + ['access_token' => $token]),
                                CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    }
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) throw new MetaApiException('Could not reach Meta: ' . $err);
    $data = json_decode($body, true);
    if (!is_array($data)) throw new MetaApiException("Meta sent an unreadable reply (HTTP $code).");
    if (isset($data['error'])) {
        $e = $data['error'];
        $msg = $e['error_user_msg'] ?? $e['message'] ?? 'Unknown error';
        if (($e['code'] ?? 0) == 190 && !str_contains($msg, 'Page Access Token')) $msg = 'The access token has expired or was revoked. Create a new one and save it here. (' . $msg . ')';
        throw new MetaApiException($msg);
    }
    return $data;
}

/** Follows "paging.next" links until there are no more pages (or $maxPages is reached). */
function metaGraphAll(PDO $db, string $path, array $params, int $maxPages = 50, ?string $token = null): array {
    $out = [];
    $after = null;
    for ($i = 0; $i < $maxPages; $i++) {
        $res = metaGraph($db, $path, $params + ($after ? ['after' => $after] : []), 'GET', $token);
        array_push($out, ...($res['data'] ?? []));
        $after = $res['paging']['cursors']['after'] ?? null;
        if (!$after || empty($res['paging']['next'])) break;
    }
    return $out;
}

/**
 * Lead forms and leads must be read with the page's own token. It is fetched with the saved (system user) token,
 * which never expires, so the page token doesn't either. Fetched once per request.
 */
function metaPageToken(PDO $db): string {
    static $cache = [];
    $key = metaSetting($db, 'page_id') . '|' . md5(metaSetting($db, 'token'));
    if (isset($cache[$key])) return $cache[$key];
    $page = metaGraph($db, metaSetting($db, 'page_id'), ['fields' => 'access_token']);
    if (empty($page['access_token'])) throw new MetaApiException('The token can’t act for this page. Give the system user access to the page (Leads, Ads and Community activity), then create the token again.');
    return $cache[$key] = $page['access_token'];
}

/** Page name, number of lead forms and dataset, for the "Test connection" button. */
function metaTestConnection(PDO $db): array {
    $out = ['ok' => [], 'problems' => []];
    try {
        $page = metaGraph($db, metaSetting($db, 'page_id'), ['fields' => 'id,name']);
        $out['ok'][] = 'Facebook page: ' . ($page['name'] ?? $page['id']);
        $forms = metaSyncForms($db);
        $out['ok'][] = count($forms) . ' lead form' . (count($forms) == 1 ? '' : 's') . ' found' . ($forms ? ': ' . implode(', ', array_slice(array_column($forms, 'name'), 0, 5)) . (count($forms) > 5 ? '…' : '') : '');
    } catch (MetaApiException $e) {
        $out['problems'][] = 'Lead access: ' . $e->getMessage();
    }
    if (($ds = metaSetting($db, 'dataset_id')) !== '') {
        try {
            $d = metaGraph($db, $ds, ['fields' => 'id,name'], 'GET', metaCapiToken($db));
            $out['ok'][] = 'Dataset for stage updates: ' . ($d['name'] ?? $d['id']);
        } catch (MetaApiException $e) {
            $out['problems'][] = 'Dataset: ' . $e->getMessage();
        }
    }
    return $out;
}

/**
 * Meta only sends instant lead notifications for pages subscribed to the app. Subscribes the page to the
 * "leadgen" field using the page's own token (fetched with the saved token). Safe to repeat.
 */
function metaSubscribePage(PDO $db): string {
    $pageId = metaSetting($db, 'page_id');
    $res = metaGraph($db, $pageId . '/subscribed_apps', ['subscribed_fields' => 'leadgen'], 'POST', metaPageToken($db));
    if (empty($res['success'])) throw new MetaApiException('Meta didn’t confirm the page subscription.');
    return 'Page subscribed to instant lead notifications';
}

// ─────────── Forms ───────────
/** Refreshes the page's lead forms (name and questions) and returns them. */
function metaSyncForms(PDO $db): array {
    ensureMetaSchema($db);
    $forms = metaGraphAll($db, metaSetting($db, 'page_id') . '/leadgen_forms', ['fields' => 'id,name,status,questions{key,label,type}', 'limit' => 100], 10, metaPageToken($db));
    $up = $db->prepare("REPLACE INTO meta_forms (id, name, status, questions, synced_at) VALUES (?,?,?,?,NOW())");
    foreach ($forms as $f) $up->execute([$f['id'], mb_substr($f['name'] ?? '', 0, 200), $f['status'] ?? null, json_encode($f['questions'] ?? [], JSON_UNESCAPED_UNICODE)]);
    return $forms;
}

/** A form's name and question labels (key => label), fetched once and cached. */
function metaForm(PDO $db, ?string $formId): array {
    if (!$formId) return ['name' => null, 'labels' => []];
    $s = $db->prepare("SELECT name, questions FROM meta_forms WHERE id = ?");
    $s->execute([$formId]);
    $row = $s->fetch();
    if (!$row) {
        try {
            $f = metaGraph($db, $formId, ['fields' => 'id,name,status,questions{key,label,type}'], 'GET', metaPageToken($db));
            $db->prepare("REPLACE INTO meta_forms (id, name, status, questions, synced_at) VALUES (?,?,?,?,NOW())")
               ->execute([$formId, mb_substr($f['name'] ?? '', 0, 200), $f['status'] ?? null, json_encode($f['questions'] ?? [], JSON_UNESCAPED_UNICODE)]);
            $row = ['name' => $f['name'] ?? null, 'questions' => json_encode($f['questions'] ?? [])];
        } catch (MetaApiException $e) {
            return ['name' => null, 'labels' => []];
        }
    }
    $labels = [];
    foreach (json_decode($row['questions'] ?? '[]', true) ?: [] as $q) if (!empty($q['key'])) $labels[strtolower($q['key'])] = $q['label'] ?? $q['key'];
    return ['name' => $row['name'], 'labels' => $labels];
}

// ─────────── Importing one lead ───────────
/** "+971 50-123 4567" → "+971501234567"; a local "0501234567" gets the UAE code. */
function metaNormalisePhone(string $p): string {
    $d = preg_replace('/\D/', '', $p);
    if ($d === '') return '';
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    elseif (str_starts_with($d, '0') && strlen($d) === 10) $d = '971' . substr($d, 1);
    return '+' . $d;
}

/** Who a new Meta lead is assigned to, from the setting. */
function metaPickOwner(PDO $db): ?int {
    $a = metaSetting($db, 'assign');
    if ($a === 'rr') {
        // The active consultant who received the fewest new leads in the last 7 days
        $id = $db->query("SELECT s.id FROM staff s LEFT JOIN leads l ON l.assigned_to = s.id AND l.created_at >= NOW() - INTERVAL 7 DAY
                          WHERE s.is_active = 1 AND s.role = 'consultant' GROUP BY s.id ORDER BY COUNT(l.id), s.id LIMIT 1")->fetchColumn();
        return $id ? (int)$id : null;
    }
    if (ctype_digit($a)) {
        $s = $db->prepare("SELECT id FROM staff WHERE id = ? AND is_active = 1");
        $s->execute([(int)$a]);
        return ($id = $s->fetchColumn()) ? (int)$id : null;
    }
    return null;
}

/**
 * Turns one Meta lead (Graph API "lead" object) into a CRM lead, or adds it to the lead that already has
 * the same Meta lead id or phone number. Returns ['status' => created|updated|duplicate, 'lead_id' => int, 'name' => string].
 */
function metaImportLead(PDO $db, array $ml): array {
    ensureMetaSchema($db);
    ensureReEnquirySchema($db);   // before the transaction: ALTER TABLE would commit it
    $metaId = (string)($ml['id'] ?? '');
    if ($metaId === '') throw new MetaApiException('The lead has no id.');
    $s = $db->prepare("SELECT id, name FROM leads WHERE meta_lead_id = ? LIMIT 1");
    $s->execute([$metaId]);
    if ($dup = $s->fetch()) return ['status' => 'duplicate', 'lead_id' => (int)$dup['id'], 'name' => $dup['name']];

    $form = metaForm($db, $ml['form_id'] ?? null);
    $f = [];
    foreach ($ml['field_data'] ?? [] as $fd) $f[strtolower($fd['name'] ?? '')] = implode(', ', array_filter((array)($fd['values'] ?? []), fn($v) => $v !== ''));
    $name  = trim($f['full_name'] ?? trim(($f['first_name'] ?? '') . ' ' . ($f['last_name'] ?? ''))) ?: trim($f['name'] ?? '');
    $phone = metaNormalisePhone($f['phone_number'] ?? $f['phone'] ?? '');
    $email = trim($f['email'] ?? '');
    if ($phone === '' && $email === '') throw new MetaApiException('The lead has no phone number or email.');
    if ($name === '') $name = 'Meta lead ' . ($phone ?: $email);

    // Everything that isn't a standard contact field: the clinic's own questions, with their labels
    $standard = ['full_name', 'first_name', 'last_name', 'name', 'phone_number', 'phone', 'email'];
    $answers = [];
    foreach ($f as $k => $v) if (!in_array($k, $standard, true) && $v !== '') $answers[$form['labels'][$k] ?? ucfirst(str_replace('_', ' ', rtrim($k, '?')))] = $v;
    $gender = ['male' => 'male', 'female' => 'female', 'm' => 'male', 'f' => 'female'][strtolower($f['gender'] ?? '')] ?? 'unknown';

    $when = isset($ml['created_time']) ? date('d M Y, h:i A', strtotime($ml['created_time'])) : date('d M Y, h:i A');
    $note = "Meta lead form" . ($form['name'] ? " “{$form['name']}”" : '') . " · $when"
          . (!empty($ml['campaign_name']) ? "\nCampaign: {$ml['campaign_name']}" . (!empty($ml['ad_name']) ? " · Ad: {$ml['ad_name']}" : '') : '')
          . (!empty($ml['is_organic']) ? "\n(Organic, not from an ad)" : '')
          . ($answers ? "\n" . implode("\n", array_map(fn($q, $a) => "$q: $a", array_keys($answers), $answers)) : '');
    $campaign = mb_substr($ml['campaign_name'] ?? $form['name'] ?? 'Meta lead form', 0, 150);

    $existing = $phone !== '' ? findLeadByPhoneLoose($db, $phone) : null;
    if (!$existing && $email !== '') {
        $s = $db->prepare("SELECT id, name, stage FROM leads WHERE email = ? LIMIT 1");
        $s->execute([$email]);
        $existing = $s->fetch() ?: null;
    }

    $db->beginTransaction();
    try {
        if ($existing) {
            // Same person enquired again (or was entered by hand): link it to Meta and add the new answers
            $leadId = (int)$existing['id'];
            $db->prepare("UPDATE leads SET meta_lead_id = COALESCE(meta_lead_id, ?), meta_form_id = COALESCE(meta_form_id, ?), meta_ad_id = COALESCE(meta_ad_id, ?),
                          email = COALESCE(NULLIF(email, ''), ?), notes = CONCAT(COALESCE(notes, ''), IF(COALESCE(notes, '') = '', '', '\n\n'), ?) WHERE id = ?")
               ->execute([$metaId, $ml['form_id'] ?? null, $ml['ad_id'] ?? null, $email ?: null, $note, $leadId]);
            $status = 'updated';
            $name = $existing['name'];
            metaActivity($db, 'meta_lead_matched', $leadId, mb_substr("Meta lead $metaId" . ($form['name'] ? " ({$form['name']})" : ''), 0, 255));
            // A fresh form from someone already in the CRM is a new enquiry; older ones (catch-up imports) are only linked
            $metaTime = isset($ml['created_time']) ? strtotime($ml['created_time']) : time();
            if ($metaTime >= time() - 86400) recordReEnquiry($db, $leadId, 'Meta form' . ($form['name'] ? " – {$form['name']}" : ''), '',
                ['lead_id' => $metaId, 'form_id' => $ml['form_id'] ?? null, 'ad_id' => $ml['ad_id'] ?? null]);
        } else {
            $owner = metaPickOwner($db);
            // Dated when the person filled in the form (not when it was imported), so reports and ageing stay right
            $metaTime = isset($ml['created_time']) ? strtotime($ml['created_time']) : false;
            $createdAt = date('Y-m-d H:i:s', $metaTime && $metaTime <= time() ? $metaTime : time());
            $db->prepare("INSERT INTO leads (name, phone, email, gender, source, source_campaign, stage, assigned_to, notes, meta_lead_id, meta_form_id, meta_ad_id, score, created_at, updated_at)
                          VALUES (?,?,?,?,'meta_form',?,'new',?,?,?,?,?,0,?,?)")
               ->execute([mb_substr($name, 0, 120), $phone, $email ?: null, $gender, $campaign, $owner, $note, $metaId, $ml['form_id'] ?? null, $ml['ad_id'] ?? null, $createdAt, $createdAt]);
            $leadId = (int)$db->lastInsertId();
            if ($phone !== '') saveLeadPhones($leadId, [$phone], $db);
            $status = 'created';
            // A reminder so someone calls within 30 minutes: the owner, or the first active admin
            // Only for fresh enquiries; leads picked up by a catch-up import are older and need no "call now"
            $remindTo = strtotime($createdAt) >= time() - 86400
                ? ($owner ?: (int)$db->query("SELECT id FROM staff WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn()) : 0;
            if ($remindTo) $db->prepare("INSERT INTO reminders (lead_id, staff_id, message, remind_at, type) VALUES (?,?,?,?,'followup_due')")
                              ->execute([$leadId, $remindTo, 'New Meta lead — call now!', date('Y-m-d H:i:s', strtotime('+30 minutes'))]);
            metaActivity($db, 'lead_created_meta', $leadId, mb_substr($form['name'] ?? 'Meta lead form', 0, 255));
        }
        // Answers that name a treatment the clinic offers become interest tags
        foreach (metaMatchTreatments($db, $answers) as $tid) {
            $db->prepare("INSERT INTO lead_treatments (lead_id, treatment_id) SELECT ?, ? FROM DUAL
                          WHERE NOT EXISTS (SELECT 1 FROM lead_treatments WHERE lead_id = ? AND treatment_id = ?)")->execute([$leadId, $tid, $leadId, $tid]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    updateLeadScore($leadId, $db);
    // "We'll call you" email, only for fresh enquiries (a catch-up import must not email old leads)
    $enquiredAt = isset($ml['created_time']) ? strtotime($ml['created_time']) : time();
    if ($email !== '' && $enquiredAt >= time() - 86400) queueLeadEmail($db, $leadId, $metaId, $email, $status === 'updated');
    return ['status' => $status, 'lead_id' => $leadId, 'name' => $name];
}

/** Activity log entry made by the integration itself (no staff member). */
function metaActivity(PDO $db, string $action, int $leadId, string $text): void {
    $db->prepare("INSERT INTO activity_log (staff_id, lead_id, action, new_value) VALUES (NULL, ?, ?, ?)")->execute([$leadId, $action, mb_substr($text, 0, 255)]);
}

/** Treatment ids whose name appears in any answer ("Lip Filler, Botox" → both). */
function metaMatchTreatments(PDO $db, array $answers): array {
    static $treatments = null;
    $treatments ??= $db->query("SELECT id, name FROM treatments WHERE is_active = 1")->fetchAll();
    $text = mb_strtolower(' ' . implode(' | ', $answers) . ' ');
    $ids = [];
    foreach ($treatments as $t) {
        $n = mb_strtolower(trim($t['name']));
        if ($n !== '' && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($n, '/') . '(?![\p{L}\p{N}])/u', $text)) $ids[] = (int)$t['id'];
    }
    return $ids;
}

/** Records an import in the delivery log (webhook deliveries are logged by the receiver itself). */
function metaLog(PDO $db, string $via, string $status, ?int $leadId, ?string $msg, ?string $payload = null): void {
    ensureMetaSchema($db);
    $db->prepare("INSERT INTO meta_webhook_log (payload, status, via, lead_id, error_msg) VALUES (?,?,?,?,?)")
       ->execute([$payload, $status, $via, $leadId, $msg === null ? null : mb_substr($msg, 0, 500)]);
}

// ─────────── Pulling leads (hourly safety net and catch-up import) ───────────
/**
 * Imports every lead created after $since (unix time) from all of the page's forms.
 * Returns counts: ['created' => n, 'updated' => n, 'duplicate' => n, 'errors' => [..], 'forms' => n].
 */
function metaPullLeads(PDO $db, int $since, string $via): array {
    $res = ['created' => 0, 'updated' => 0, 'duplicate' => 0, 'errors' => [], 'forms' => 0];
    $forms = metaSyncForms($db);
    $res['forms'] = count($forms);
    foreach ($forms as $form) {
        try {
            $leads = metaGraphAll($db, $form['id'] . '/leads', [
                'fields' => META_LEAD_FIELDS, 'limit' => 100,
                'filtering' => json_encode([['field' => 'time_created', 'operator' => 'GREATER_THAN', 'value' => $since]]),
            ], 50, metaPageToken($db));
        } catch (MetaApiException $e) {
            $res['errors'][] = ($form['name'] ?? $form['id']) . ': ' . $e->getMessage();
            continue;
        }
        foreach (array_reverse($leads) as $ml) {      // oldest first, so notes read in order
            $ml['form_id'] ??= $form['id'];
            try {
                $r = metaImportLead($db, $ml);
                $res[$r['status']]++;
                if ($r['status'] !== 'duplicate') metaLog($db, $via, 'processed', $r['lead_id'], $r['status'] === 'updated' ? 'Added to an existing lead with the same phone or email' : null);
            } catch (Throwable $e) {
                $res['errors'][] = 'Lead ' . ($ml['id'] ?? '?') . ': ' . $e->getMessage();
                metaLog($db, $via, 'error', null, $e->getMessage(), json_encode($ml, JSON_UNESCAPED_UNICODE));
            }
        }
    }
    return $res;
}

/** Hourly from cron: everything since the last check, with an hour of overlap (duplicates are skipped). */
function metaPollNow(PDO $db): array {
    if (!metaConfigured($db)) return ['skipped' => 'not connected'];
    $last = (int)metaSetting($db, 'last_poll');
    $since = max($last ? $last - 3600 : time() - 86400, time() - 90 * 86400);   // Meta keeps leads for 90 days
    $started = time();
    $res = metaPullLeads($db, $since, 'hourly');
    if (!$res['errors'] || $res['created'] + $res['updated'] + $res['duplicate'] > 0) metaSaveSetting($db, 'last_poll', (string)$started);
    metaSaveSetting($db, 'last_poll_result', json_encode(['at' => $started] + $res, JSON_UNESCAPED_UNICODE));
    return $res;
}

// ─────────── Sending stages back to Meta (Conversions API for CRM) ───────────
function metaCapiToken(PDO $db): string {
    return metaSetting($db, 'capi_token') !== '' ? metaSetting($db, 'capi_token') : metaSetting($db, 'token');
}

// Funnel order: a lead moved back to an earlier stage is not reported again, so Meta only sees progress.
// Lost and Cold sit outside the funnel and are reported whenever they happen.
const META_FUNNEL_RANK = ['new' => 0, 'contacted' => 1, 'qualified' => 2, 'booked' => 3, 'converted' => 4];

/** Queues one event per stage reached by each lead that came from Meta (each stage is sent once).
 *  A lead that skipped stages (New → Booked) also reports the ones it passed (Contacted, Qualified). */
function metaQueueStageEvents(PDO $db): int {
    ensureMetaSchema($db);
    $stages = "'" . implode("','", array_keys(META_STAGE_EVENTS)) . "'";
    $rows = $db->query("SELECT l.id, l.stage,
                               (SELECT GROUP_CONCAT(e2.stage) FROM meta_capi_events e2 WHERE e2.lead_id = l.id) AS reported
                        FROM leads l
                        WHERE l.meta_lead_id IS NOT NULL AND l.meta_lead_id <> '' AND l.stage IN ($stages) AND l.stage <> 'new'")->fetchAll();
    $ins = $db->prepare("INSERT IGNORE INTO meta_capi_events (lead_id, stage, event_name) VALUES (?,?,?)");
    // Every Meta lead starts the funnel with one "Lead" event, whatever stage it has reached since
    $n = $db->exec("INSERT IGNORE INTO meta_capi_events (lead_id, stage, event_name)
                    SELECT l.id, 'new', 'Lead' FROM leads l WHERE l.meta_lead_id IS NOT NULL AND l.meta_lead_id <> ''
                      AND NOT EXISTS (SELECT 1 FROM meta_capi_events e WHERE e.lead_id = l.id AND e.stage = 'new')");
    foreach ($rows as $r) {
        $reported = explode(',', (string)$r['reported']);
        $rank = META_FUNNEL_RANK[$r['stage']] ?? null;
        $best = max([0, ...array_map(fn($st) => META_FUNNEL_RANK[$st] ?? 0, $reported)]);
        if ($rank !== null && $rank < $best) continue;
        // Lost/Cold: just that stage. Funnel stages: every stage up to this one, in funnel order.
        $todo = $rank === null ? [$r['stage']] : array_keys(array_filter(META_FUNNEL_RANK, fn($k) => $k > 0 && $k <= $rank));
        foreach ($todo as $st) {
            if (in_array($st, $reported, true)) continue;
            $ins->execute([$r['id'], $st, META_STAGE_EVENTS[$st]]);
            $n += $ins->rowCount();
        }
    }
    return $n;
}

/**
 * Queues a CRM event for a lead that came from a Meta lead form: Schedule (a booking) or Purchase (their first paid
 * invoice; value = the invoice's net amount in AED, no line details). Meta's CRM events need its lead id, so other leads
 * are skipped (online bookings send a website event instead, see metaSendWebEvent). Sent by cron, once each.
 */
function metaQueueEvent(PDO $db, int $leadId, string $key, string $eventName, ?float $value = null): bool {
    ensureMetaSchema($db);
    if (metaSetting($db, 'capi_enabled') !== '1') return false;
    $m = $db->prepare("SELECT meta_lead_id FROM leads WHERE id = ?"); $m->execute([$leadId]);
    if (!preg_match('/^\d+$/', (string)$m->fetchColumn())) return false;
    $s = $db->prepare("INSERT IGNORE INTO meta_capi_events (lead_id, stage, event_name, value, currency) VALUES (?,?,?,?,?)");
    $s->execute([$leadId, mb_substr($key, 0, 20), $eventName, $value, $value === null ? null : 'AED']);
    return $s->rowCount() > 0;
}

/** The event Meta receives for one queued stage. */
function metaCapiEvent(array $row): array {
    $ud = $row['meta_lead_id'] ? ['lead_id' => (int)$row['meta_lead_id']] : [];
    if ($d = preg_replace('/\D/', '', (string)$row['phone'])) $ud['ph'] = [hash('sha256', $d)];
    if ($row['email']) $ud['em'] = [hash('sha256', strtolower(trim($row['email'])))];
    return [
        'event_name'    => $row['event_name'],
        'event_time'    => strtotime($row['created_at']),
        'action_source' => 'system_generated',
        'user_data'     => $ud,
        'custom_data'   => ['event_source' => 'crm', 'lead_event_source' => 'Clinic CRM'] + (isset($row['value']) && $row['value'] !== null ? ['value' => (float)$row['value'], 'currency' => $row['currency'] ?: 'AED'] : []),
    ];
}

/** Sends queued stage events (up to 500 at a time). Returns ['sent' => n, 'failed' => n, 'error' => ?string]. */
function metaSendStageEvents(PDO $db): array {
    $out = ['sent' => 0, 'failed' => 0, 'error' => null];
    if (metaSetting($db, 'capi_enabled') !== '1' || metaSetting($db, 'dataset_id') === '') return $out + ['skipped' => true];
    $testCode = metaSetting($db, 'test_event_code');
    $limit = $testCode !== '' ? 5 : 500;      // a test sends a small sample
    $rows = $db->query("SELECT e.*, l.meta_lead_id, l.phone, l.email FROM meta_capi_events e JOIN leads l ON l.id = e.lead_id
                        WHERE e.status IN ('queued','error') AND e.attempts < 5 ORDER BY e.id LIMIT $limit")->fetchAll();
    if (!$rows) return $out;
    $params = ['data' => array_map('metaCapiEvent', $rows)];
    if ($testCode !== '') $params['test_event_code'] = $testCode;
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
    try {
        $res = metaGraph($db, metaSetting($db, 'dataset_id') . '/events', $params, 'POST', metaCapiToken($db));
        // A test send only shows in Events Manager's Test Events tab, so the events stay queued for the real send
        if ($testCode === '') $db->exec("UPDATE meta_capi_events SET status = 'sent', sent_at = NOW(), attempts = attempts + 1, error = NULL WHERE id IN ($ids)");
        $out['sent'] = (int)($res['events_received'] ?? count($rows));
        $out['test'] = $testCode !== '';
    } catch (MetaApiException $e) {
        $db->prepare("UPDATE meta_capi_events SET status = 'error', attempts = attempts + 1, error = ? WHERE id IN ($ids)")->execute([mb_substr($e->getMessage(), 0, 500)]);
        $out['failed'] = count($rows);
        $out['error'] = $e->getMessage();
    }
    metaSaveSetting($db, 'last_capi_result', json_encode(['at' => time()] + $out, JSON_UNESCAPED_UNICODE));
    return $out;
}

// ─────────── Website enquiry forms (Conversions API "Lead" next to the browser pixel) ───────────
// Landing page → the Meta pixel it loads (Admin → Clinic profile, meta.web_pixels). Only these pixels can be sent
// to, whatever a request claims.
function metaWebPixels(): array {
    $map = json_decode(tenantSetting('meta.web_pixels', '{}'), true);
    return is_array($map) ? array_filter($map, fn($id, $page) => is_string($page) && preg_match('/^\d{6,20}$/', (string)$id), ARRAY_FILTER_USE_BOTH) : [];
}

/**
 * Sends a website "Lead" to the page's pixel. The browser sends the same event with the same event_id,
 * so Meta counts it once even when both arrive. Returns null on success, or the error.
 */
function metaSendWebLead(PDO $db, string $landing, string $eventId, string $phone, string $sourceUrl): ?string {
    return metaSendWebEvent($db, $landing, 'Lead', $eventId, $phone, $sourceUrl);
}

/** A website event (Lead, Schedule) to a landing page's pixel, from the visitor's request (IP, browser, hashed phone). */
function metaSendWebEvent(PDO $db, string $landing, string $eventName, string $eventId, string $phone, string $sourceUrl): ?string {
    $pixel = metaWebPixels()[$landing] ?? null;
    if (!$pixel || metaSetting($db, 'token') === '') return 'not set up';
    $ud = ['client_ip_address' => $_SERVER['REMOTE_ADDR'] ?? '', 'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''];
    if ($d = preg_replace('/\D/', '', $phone)) $ud['ph'] = [hash('sha256', $d)];
    foreach (['fbp' => '_fbp', 'fbc' => '_fbc'] as $k => $cookie) if (!empty($_COOKIE[$cookie])) $ud[$k] = (string)$_COOKIE[$cookie];
    $event = ['event_name' => $eventName, 'event_time' => time(), 'action_source' => 'website', 'user_data' => $ud];
    if ($eventId !== '') $event['event_id'] = mb_substr($eventId, 0, 100);
    if ($sourceUrl !== '') $event['event_source_url'] = mb_substr($sourceUrl, 0, 500);
    try {
        metaGraph($db, $pixel . '/events', ['data' => [$event]], 'POST');
        $err = null;
    } catch (MetaApiException $e) {
        $err = $e->getMessage();
    }
    metaSaveSetting($db, 'last_web_lead', json_encode(['at' => time(), 'page' => $landing, 'event' => $eventName, 'error' => $err], JSON_UNESCAPED_UNICODE));
    return $err;
}

/** Cron entry point: pull new leads, then send stage changes. */
function runMetaSync(PDO $db): array {
    ensureMetaSchema($db);
    $out = [];
    try { $out['pull'] = metaPollNow($db); } catch (Throwable $e) { $out['pull'] = ['error' => $e->getMessage()]; }
    if (metaSetting($db, 'capi_enabled') === '1') {
        try { $out['queued'] = metaQueueStageEvents($db); $out['capi'] = metaSendStageEvents($db); } catch (Throwable $e) { $out['capi'] = ['error' => $e->getMessage()]; }
    }
    try { $out['mail'] = sendQueuedLeadEmails($db); } catch (Throwable $e) { $out['mail'] = ['error' => $e->getMessage()]; }
    return $out;
}

/** "…xyz1" so a saved secret can be recognised without showing it. */
function metaMask(string $v): string {
    return $v === '' ? '' : '••••••••' . substr($v, -4);
}
