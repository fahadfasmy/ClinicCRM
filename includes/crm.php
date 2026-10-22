<?php
// includes/crm.php — CRM upgrades (Phase 11): campaigns and the lead → booked → showed → invoiced → revenue funnel,
// recall rules and the recall list, broadcasts to patients with marketing consent, and the public online booking
// (free slots, a one-time code by WhatsApp or SMS, then the patient and appointment). Meta Schedule / Purchase events
// are queued from createAppointment (scheduler.php) and refreshInvoice (billing.php).
require_once __DIR__ . '/messaging.php';
require_once __DIR__ . '/scheduler.php';

const RECALL_KINDS = ['not_visited' => 'Not visited for', 'service_interval' => 'Treatment due again after', 'package_left' => 'Package sessions left, no visit for'];
const RECALL_STATUS = ['open' => ['To contact', 'tone-amber'], 'contacted' => ['Contacted', 'tone-blue'], 'booked' => ['Booked', 'tone-green'], 'dismissed' => ['Dismissed', 'tone-slate']];
const OTP_TTL_MIN = 10;

// ── Campaigns ──

/** Makes a campaign for each source + campaign name the leads carry, and points the leads at it. Safe to repeat. */
function campaignBackfill(PDO $db): array {
    $made = $db->exec("INSERT IGNORE INTO campaigns (source, source_id, name)
                       SELECT l.source, (SELECT s.id FROM lead_sources s WHERE s.slug = l.source LIMIT 1), TRIM(l.source_campaign)
                       FROM leads l WHERE l.source_campaign IS NOT NULL AND TRIM(l.source_campaign) <> '' GROUP BY l.source, TRIM(l.source_campaign)");
    $linked = $db->exec("UPDATE leads l JOIN campaigns c ON c.source <=> l.source AND c.name = TRIM(l.source_campaign) SET l.campaign_id = c.id, l.updated_at = l.updated_at
                         WHERE l.campaign_id IS NULL AND l.source_campaign IS NOT NULL AND TRIM(l.source_campaign) <> ''");
    return ['campaigns' => (int)$made, 'leads' => (int)$linked];
}

function saveCampaign(PDO $db, int $id, array $in): int {
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Give the campaign a name.');
    $budget = ($in['budget'] ?? '') !== '' ? round((float)$in['budget'], 2) : null;
    if ($budget !== null && $budget < 0) throw new RuntimeException('The budget can’t be negative.');
    $src = trim((string)($in['source'] ?? '')) ?: null;
    $f = [$src, $src ? ((int)$db->query("SELECT id FROM lead_sources WHERE slug = " . $db->quote($src))->fetchColumn() ?: null) : null, mb_substr($name, 0, 150),
          mb_substr(trim((string)($in['meta_campaign_id'] ?? '')), 0, 40) ?: null, mb_substr(trim((string)($in['meta_adset_id'] ?? '')), 0, 40) ?: null, mb_substr(trim((string)($in['meta_ad_id'] ?? '')), 0, 40) ?: null,
          $budget, ($in['starts_on'] ?? '') ?: null, ($in['ends_on'] ?? '') ?: null];
    try {
        if ($id) $db->prepare("UPDATE campaigns SET source = ?, source_id = ?, name = ?, meta_campaign_id = ?, meta_adset_id = ?, meta_ad_id = ?, budget = ?, starts_on = ?, ends_on = ? WHERE id = ?")->execute([...$f, $id]);
        else { $db->prepare("INSERT INTO campaigns (source, source_id, name, meta_campaign_id, meta_adset_id, meta_ad_id, budget, starts_on, ends_on) VALUES (?,?,?,?,?,?,?,?,?)")->execute($f); $id = (int)$db->lastInsertId(); }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') throw new RuntimeException('A campaign with that name already exists for this source.');
        throw $e;
    }
    // Leads with this campaign name follow it
    $db->prepare("UPDATE leads SET campaign_id = ?, updated_at = updated_at WHERE campaign_id IS NULL AND source <=> ? AND TRIM(source_campaign) = ?")->execute([$id, $src, $name]);
    return $id;
}

/**
 * The funnel for leads created in [from, to], by campaign or by source: leads → booked → showed → became patients →
 * invoiced → revenue (net of issued invoices, less credit notes) and collected; cost per patient when there's a budget.
 */
function funnelReport(PDO $db, string $from, string $to, string $by = 'campaign'): array {
    $key = $by === 'source' ? "COALESCE(l.source, 'unknown')" : "COALESCE(l.campaign_id, 0)";
    $s = $db->prepare("SELECT $key AS k, COUNT(DISTINCT l.id) AS leads,
            COUNT(DISTINCT CASE WHEN a.id IS NOT NULL THEN l.id END) AS booked,
            COUNT(DISTINCT CASE WHEN a.status IN ('arrived','in_triage','with_doctor','completed') THEN l.id END) AS showed,
            COUNT(DISTINCT p.id) AS patients
        FROM leads l LEFT JOIN patients p ON p.lead_id = l.id AND p.status <> 'merged'
        LEFT JOIN appointments a ON (a.lead_id = l.id OR (p.id IS NOT NULL AND a.patient_id = p.id)) AND a.status NOT IN ('cancelled','rescheduled')
        WHERE l.created_at >= ? AND l.created_at < ? + INTERVAL 1 DAY AND COALESCE(l.is_duplicate, 0) = 0 GROUP BY k");
    $s->execute([$from, $to]);
    $rows = [];
    foreach ($s as $r) $rows[(string)$r['k']] = $r + ['invoiced' => 0, 'revenue' => 0, 'collected' => 0];
    $m = $db->prepare("SELECT $key AS k, COUNT(DISTINCT i.patient_id) AS invoiced, SUM(i.net) - COALESCE(SUM(cn.gross), 0) AS revenue, SUM(i.paid - i.refunded) AS collected
        FROM leads l JOIN patients p ON p.lead_id = l.id JOIN invoices i ON i.patient_id = p.id AND i.status NOT IN ('draft','void') AND i.imported IS NULL
        LEFT JOIN (SELECT invoice_id, SUM(gross) AS gross FROM credit_notes GROUP BY invoice_id) cn ON cn.invoice_id = i.id
        WHERE l.created_at >= ? AND l.created_at < ? + INTERVAL 1 DAY AND COALESCE(l.is_duplicate, 0) = 0 GROUP BY k");
    $m->execute([$from, $to]);
    foreach ($m as $r) if (isset($rows[(string)$r['k']])) { $rows[(string)$r['k']]['invoiced'] = (int)$r['invoiced']; $rows[(string)$r['k']]['revenue'] = (float)$r['revenue']; $rows[(string)$r['k']]['collected'] = (float)$r['collected']; }
    $camp = $by === 'campaign' ? $db->query("SELECT id, name, source, budget FROM campaigns")->fetchAll(PDO::FETCH_UNIQUE) : [];
    foreach ($rows as $k => &$r) {
        $c = $camp[(int)$k] ?? null;
        $r['name'] = $by === 'source' ? $k : ($c['name'] ?? 'No campaign');
        $r['source'] = $c['source'] ?? ($by === 'source' ? $k : '');
        $r['budget'] = $c['budget'] ?? null;
        $r['cost_per_patient'] = $r['budget'] !== null && $r['patients'] ? round((float)$r['budget'] / (int)$r['patients'], 2) : null;
    }
    unset($r);
    uasort($rows, fn($a, $b) => $b['revenue'] <=> $a['revenue'] ?: $b['leads'] <=> $a['leads']);
    return $rows;
}

// ── Recalls ──

function saveRecallRule(PDO $db, int $id, array $in): int {
    $kind = isset(RECALL_KINDS[$in['kind'] ?? '']) ? $in['kind'] : null;
    $days = (int)($in['days_after'] ?? 0);
    $name = trim((string)($in['name'] ?? ''));
    $svc = (int)($in['service_id'] ?? 0) ?: null;
    if (!$kind || $name === '' || $days < 1 || $days > 3650) throw new RuntimeException('Name the rule, choose its kind and the number of days (1–3650).');
    if ($kind === 'service_interval' && !$svc) throw new RuntimeException('Choose the treatment for this rule.');
    $f = [mb_substr($name, 0, 150), $kind, $kind === 'service_interval' ? $svc : null, $days, empty($in['send_message']) ? 0 : 1, empty($in['is_active']) ? 0 : 1];
    if ($id) $db->prepare("UPDATE recall_rules SET name = ?, kind = ?, service_id = ?, days_after = ?, send_message = ?, is_active = ? WHERE id = ?")->execute([...$f, $id]);
    else { $db->prepare("INSERT INTO recall_rules (name, kind, service_id, days_after, send_message, is_active) VALUES (?,?,?,?,?,?)")->execute($f); $id = (int)$db->lastInsertId(); }
    return $id;
}

/**
 * Adds a recall for each patient a rule now applies to (one open recall per patient and rule). Nobody with a visit
 * booked ahead is recalled. The recall goes to the consultant who owns the patient's lead. Returns how many were added.
 */
function generateRecalls(PDO $db): int {
    $n = 0;
    $future = "NOT EXISTS (SELECT 1 FROM appointments f WHERE f.patient_id = p.id AND f.status IN ('booked','confirmed') AND f.scheduled_at > NOW())";
    $notOpen = "NOT EXISTS (SELECT 1 FROM recalls r WHERE r.patient_id = p.id AND r.rule_id = ? AND r.status IN ('open','contacted'))";
    foreach ($db->query("SELECT * FROM recall_rules WHERE is_active = 1")->fetchAll() as $rule) {
        $d = (int)$rule['days_after'];
        $sql = match ($rule['kind']) {
            'not_visited' => "SELECT p.id, MAX(a.scheduled_at) AS last FROM patients p JOIN appointments a ON a.patient_id = p.id AND a.status = 'completed'
                              WHERE p.status = 'active' AND $future AND $notOpen GROUP BY p.id HAVING last < NOW() - INTERVAL $d DAY",
            'service_interval' => "SELECT p.id, MAX(a.scheduled_at) AS last FROM patients p JOIN appointments a ON a.patient_id = p.id AND a.status = 'completed'
                              JOIN appointment_services s ON s.appointment_id = a.id AND s.service_id = " . (int)$rule['service_id'] . "
                              WHERE p.status = 'active' AND $future AND $notOpen GROUP BY p.id HAVING last < NOW() - INTERVAL $d DAY",
            'package_left' => "SELECT p.id, COALESCE(MAX(a.scheduled_at), MIN(pp.starts_on)) AS last FROM patients p JOIN patient_packages pp ON pp.patient_id = p.id AND pp.status = 'active' AND pp.expires_on >= CURDATE()
                              LEFT JOIN appointments a ON a.patient_id = p.id AND a.status = 'completed'
                              WHERE p.status = 'active' AND $future AND $notOpen AND EXISTS (SELECT 1 FROM patient_package_sessions ss WHERE ss.patient_package_id = pp.id AND ss.used < ss.total)
                              GROUP BY p.id HAVING last < NOW() - INTERVAL $d DAY",
        };
        $s = $db->prepare($sql); $s->execute([$rule['id']]);
        $ins = $db->prepare("INSERT INTO recalls (patient_id, rule_id, due_on, assigned_to) VALUES (?, ?, CURDATE(), (SELECT l.assigned_to FROM patients x JOIN leads l ON l.id = x.lead_id WHERE x.id = ?))");
        foreach ($s as $r) { $ins->execute([$r['id'], $rule['id'], $r['id']]); $n++; }
    }
    return $n;
}

function updateRecall(PDO $db, int $id, string $status, string $note = ''): void {
    if (!isset(RECALL_STATUS[$status])) throw new RuntimeException('Choose what happened.');
    $db->prepare("UPDATE recalls SET status = ?, note = COALESCE(?, note), updated_by = ? WHERE id = ?")->execute([$status, mb_substr(trim($note), 0, 255) ?: null, currentStaffId() ?: null, $id]);
}

// ── Broadcasts (marketing: consent and quiet hours) ──

/** Patients in a segment who agreed to marketing. $seg: branch_id, gender, not_visited_days, service_id, has_package, tag. */
function segmentPatientIds(PDO $db, array $seg): array {
    $w = ["p.status = 'active'", 'p.marketing_consent = 1']; $a = [];
    if (!empty($seg['branch_id'])) { $w[] = 'p.branch_id = ?'; $a[] = (int)$seg['branch_id']; }
    if (in_array($seg['gender'] ?? '', ['female', 'male'], true)) { $w[] = 'p.gender = ?'; $a[] = $seg['gender']; }
    if (!empty($seg['not_visited_days'])) { $w[] = "NOT EXISTS (SELECT 1 FROM appointments x WHERE x.patient_id = p.id AND x.status = 'completed' AND x.scheduled_at > NOW() - INTERVAL ? DAY)"; $a[] = (int)$seg['not_visited_days']; }
    if (!empty($seg['service_id'])) { $w[] = "EXISTS (SELECT 1 FROM appointments x JOIN appointment_services s ON s.appointment_id = x.id WHERE x.patient_id = p.id AND x.status = 'completed' AND s.service_id = ?)"; $a[] = (int)$seg['service_id']; }
    if (!empty($seg['has_package'])) $w[] = "EXISTS (SELECT 1 FROM patient_packages pp WHERE pp.patient_id = p.id AND pp.status = 'active')";
    if (trim((string)($seg['tag'] ?? '')) !== '') { $w[] = 'FIND_IN_SET(?, REPLACE(p.tags, \', \', \',\'))'; $a[] = trim($seg['tag']); }
    $s = $db->prepare("SELECT p.id FROM patients p WHERE " . implode(' AND ', $w) . " ORDER BY p.id");
    $s->execute($a);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}

function createBroadcast(PDO $db, string $name, int $templateId, array $seg): int {
    if (!can('marketing.broadcasts')) throw new RuntimeException('You don’t have permission to send broadcasts.');
    $t = findTemplate($db, $templateId);
    if (!$t || !$t['is_active']) throw new RuntimeException('Choose a template.');
    if (trim($name) === '') throw new RuntimeException('Name the broadcast.');
    $n = count(segmentPatientIds($db, $seg));
    if (!$n) throw new RuntimeException('No patient with marketing consent matches.');
    $db->prepare("INSERT INTO broadcasts (name, template_id, segment, recipients, created_by) VALUES (?,?,?,?,?)")->execute([mb_substr(trim($name), 0, 150), $templateId, json_encode($seg), $n, currentStaffId() ?: null]);
    $id = (int)$db->lastInsertId();
    auditLog($db, 'broadcast', $id, 'created', ['recipients' => $n, 'template' => $t['name']]);
    return $id;
}

function startBroadcast(PDO $db, int $id): void {
    if (!can('marketing.broadcasts')) throw new RuntimeException('You don’t have permission to send broadcasts.');
    $s = $db->prepare("UPDATE broadcasts SET status = 'sending', started_at = NOW() WHERE id = ? AND status = 'draft'"); $s->execute([$id]);
    if (!$s->rowCount()) throw new RuntimeException('This broadcast was already started.');
    auditLog($db, 'broadcast', $id, 'started', []);
}

/** Sends the next batch of every broadcast being sent (cron, outside quiet hours). Returns messages sent. */
function runBroadcasts(PDO $db, int $batch = 200): int {
    if (inQuietHours($db)) return 0;
    $sent = 0;
    foreach ($db->query("SELECT * FROM broadcasts WHERE status = 'sending'")->fetchAll() as $b) {
        $todo = segmentPatientIds($db, json_decode($b['segment'], true) ?: []);
        $done = array_map('intval', $db->query("SELECT patient_id FROM messages WHERE automation_key = 'broadcast:" . (int)$b['id'] . "'")->fetchAll(PDO::FETCH_COLUMN));
        $left = array_slice(array_values(array_diff($todo, $done)), 0, $batch);
        foreach ($left as $pid) {
            if (sendTemplateMessage($db, 'custom', $pid, null, ['template_id' => (int)$b['template_id'], 'marketing' => true, 'automation' => 'broadcast:' . $b['id'], 'idempotency' => "broadcast:{$b['id']}:pt:$pid",
                                    'vars' => ['link' => msgSetting($db, 'booking_link')]])) $sent++;
        }
        if (count(array_diff($todo, $done)) <= $batch) $db->prepare("UPDATE broadcasts SET status = 'sent', finished_at = NOW() WHERE id = ?")->execute([$b['id']]);
    }
    return $sent;
}

function broadcastResults(PDO $db, int $id): array {
    return $db->query("SELECT status, COUNT(*) FROM messages WHERE automation_key = 'broadcast:$id' GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
}

// ── Online booking ──

function onlineBookingOn(PDO $db): bool { return msgSetting($db, 'online_booking') === '1' && planFeature('online_booking') && tenantWritable(); }

function bookableServices(PDO $db): array {
    return $db->query("SELECT id, name, name_ar, duration_min, price FROM services WHERE is_active = 1 AND is_online_bookable = 1 ORDER BY name")->fetchAll();
}

/** Free start times on a day for a treatment at a branch: [['time' => 'Y-m-d H:i', 'staff_id' => n], …], any practitioner or one. */
function onlineSlots(PDO $db, int $branchId, int $serviceId, int $staffId, string $date): array {
    $svc = $db->prepare("SELECT duration_min FROM services WHERE id = ? AND is_active = 1 AND is_online_bookable = 1"); $svc->execute([$serviceId]);
    $min = (int)$svc->fetchColumn();
    if (!$min || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return [];
    $days = max(1, (int)msgSetting($db, 'booking_days_ahead', '30'));
    $tz = new DateTimeZone(CLINIC_TZ);
    $today = new DateTimeImmutable('today', $tz);
    if ($date < $today->format('Y-m-d') || $date > $today->modify("+$days days")->format('Y-m-d')) return [];
    $notice = (new DateTimeImmutable('now', $tz))->modify('+' . max(0, (int)msgSetting($db, 'booking_notice_hours', '2')) . ' hours')->format('Y-m-d H:i');
    $pracs = $staffId ? [$staffId] : array_map('intval', $db->query("SELECT staff_id FROM practitioner_profiles WHERE is_bookable = 1")->fetchAll(PDO::FETCH_COLUMN));
    $out = [];
    foreach ($pracs as $pid) {
        $busy = $db->prepare("SELECT scheduled_at, ends_at FROM appointments WHERE practitioner_id = ? AND status NOT IN ('cancelled','no_show','rescheduled') AND scheduled_at < ? + INTERVAL 1 DAY AND ends_at > ?");
        $busy->execute([$pid, $date, $date]);
        $busy = $busy->fetchAll();
        foreach (freeSlots($db, $pid, $branchId, $date, $min, 15) as $t) {
            if ($t < $notice || isset($out[$t])) continue;
            $end = date('Y-m-d H:i', strtotime("$t +$min minutes"));
            foreach ($busy as $b) if ($t < substr($b['ends_at'], 0, 16) && $end > substr($b['scheduled_at'], 0, 16)) continue 2;
            $out[$t] = ['time' => $t, 'staff_id' => $pid];
        }
    }
    ksort($out);
    return array_values($out);
}

/** Sends a 6-digit code by WhatsApp or SMS (rate-limited per number). Returns the channel used. */
function otpSend(PDO $db, string $phone, string $purpose = 'booking'): string {
    $phone = normalizePhone($phone) ?? throw new RuntimeException('Enter a valid mobile number.');
    if (!rateLimitOk($db, "otp:$phone", 5, 3600)) throw new RuntimeException('Too many codes for this number. Try again in an hour.');
    $code = (string)random_int(100000, 999999);
    $db->prepare("INSERT INTO otp_codes (phone, purpose, code_hash, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL " . OTP_TTL_MIN . " MINUTE)")->execute([$phone, $purpose, hash('sha256', "$phone|$code")]);
    $text = "Your " . clinicName() . " code is $code. It expires in " . OTP_TTL_MIN . " minutes.";
    foreach (['whatsapp' => waReady($db), 'sms' => smsReady($db)] as $ch => $ready) {
        if (!$ready) continue;
        $tpl = $ch === 'whatsapp' ? pickTemplate($db, 'otp', 'whatsapp', 'en') : null;
        if ($ch === 'whatsapp' && (!$tpl || $tpl['wa_status'] !== 'approved')) continue;   // WhatsApp needs an approved authentication template
        deliver($db, ['channel' => $ch, 'to' => $phone, 'body' => $text, 'wa_template' => $tpl['wa_template_name'] ?? null, 'wa_language' => 'en', 'params' => [$code]]);
        $db->prepare("INSERT INTO messages (channel, direction, to_from, body, status, automation_key, sent_at) VALUES (?, 'out', ?, ?, 'sent', 'otp', NOW())")->execute([$ch, $phone, 'One-time code (hidden)']);
        return $ch;
    }
    throw new RuntimeException('Online booking can’t send codes right now. Please call the clinic.');
}

/** Checks a code: right, not expired, not used, fewer than 5 tries. */
function otpVerify(PDO $db, string $phone, string $code, string $purpose = 'booking'): bool {
    $phone = normalizePhone($phone);
    if (!$phone) return false;
    $s = $db->prepare("SELECT * FROM otp_codes WHERE phone = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $s->execute([$phone, $purpose]);
    $o = $s->fetch();
    if (!$o || $o['attempts'] >= 5) return false;
    if (!hash_equals($o['code_hash'], hash('sha256', $phone . '|' . preg_replace('/\D/', '', $code)))) {
        $db->prepare("UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?")->execute([$o['id']]);
        return false;
    }
    $db->prepare("UPDATE otp_codes SET used_at = NOW() WHERE id = ?")->execute([$o['id']]);
    return true;
}

/**
 * Books online after the code is checked: finds the patient by phone (or the lead), or creates a lead (source
 * "online_booking") and its patient; books through createAppointment (booked_via = online); sends the Meta website
 * "Schedule" event to the booking page's pixel. Returns [appointment id, patient id].
 */
function onlineBook(PDO $db, array $in): array {
    $phone = normalizePhone((string)($in['phone'] ?? '')) ?? throw new RuntimeException('Enter a valid mobile number.');
    $name = trim(preg_replace('/\s+/', ' ', (string)($in['name'] ?? '')));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 80) throw new RuntimeException('Enter your name.');
    $slot = array_values(array_filter(onlineSlots($db, (int)$in['branch_id'], (int)$in['service_id'], (int)($in['staff_id'] ?? 0), substr((string)$in['time'], 0, 10)), fn($s) => $s['time'] === $in['time']))[0] ?? null;
    if (!$slot) throw new RuntimeException('That time was just taken. Please choose another.');
    [$pid, $lid] = msgMatchPhone($db, $phone);
    if (!$pid && $lid) { $l = $db->query("SELECT patient_id FROM leads WHERE id = $lid")->fetchColumn(); $pid = $l ? (int)$l : null; }
    if (!$pid) {
        if (!$lid) {
            $db->exec("INSERT INTO lead_sources (name, slug, icon, is_active, sort_order) SELECT 'Online booking', 'online_booking', 'bi-globe', 1, 99 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM lead_sources WHERE slug = 'online_booking')");
            $db->prepare("INSERT INTO leads (name, phone, source, stage, notes) VALUES (?, ?, 'online_booking', 'new', 'Booked online')")->execute([mb_substr($name, 0, 100), $phone]);
            $lid = (int)$db->lastInsertId();
            logActivity(0, 'lead_created', $lid, null, 'online booking');
        }
        [$pid] = patientFromLead($db, $lid);
    }
    [$apptId] = createAppointment($db, ['service_ids' => [(int)$in['service_id']], 'start' => $slot['time'], 'practitioner_id' => $slot['staff_id'], 'branch_id' => (int)$in['branch_id'],
                                        'patient_id' => $pid, 'booked_via' => 'online', 'visit_type' => 'treatment', 'notes' => 'Booked online']);
    if (!empty($in['whatsapp_opt_in'])) $db->prepare("UPDATE patients SET whatsapp_opt_in = 1, whatsapp_opt_in_at = NOW() WHERE id = ? AND whatsapp_opt_in = 0")->execute([$pid]);
    // Meta "Schedule" from the booking page, the same way the landing pages send "Lead" (pixel set as landing page "book")
    try { require_once __DIR__ . '/meta_leads.php'; if (isset(metaWebPixels()['book'])) metaSendWebEvent($db, 'book', 'Schedule', 'book-' . $apptId, $phone, appUrl() . '/book.php'); }
    catch (Throwable $e) { appLog('Online booking Schedule event: ' . $e->getMessage()); }
    auditLog($db, 'appointment', (int)$apptId, 'booked_online', ['patient' => $pid]);
    return [(int)$apptId, (int)$pid];
}
