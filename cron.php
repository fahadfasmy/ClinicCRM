<?php
// cron.php — run every 5 minutes (the automatic patient messages need it):
//   */5 * * * * /usr/bin/php /path/to/clinic-crm/cron.php >> /path/to/logs/cron.log 2>&1
// Patient messages run every time; everything else still runs once an hour (it was an hourly job before Phase 10).

// Only allow CLI execution
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/includes/db.php';
// Multi-clinic mode: run once per clinic, each in its own process (one clinic failing never stops the others)
if (multiTenant() && !getopt('', ['tenant:'])) {
    // The platform's own jobs first, at most once an hour (billing dates, trial reminders, usage)
    $mark = rtrim(LOG_PATH, '/\\') . '/platform_jobs.last';
    if (!is_file($mark) || filemtime($mark) < time() - 55 * 60) {
        @touch($mark);
        require_once __DIR__ . '/includes/platform_jobs.php';
        foreach (platformJobs() as $line) echo '[' . date('H:i:s') . "] Platform · $line\n";
    }
    exit(cliForEachTenant(__FILE__) ? 1 : 0);
}
cliTenant();
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';        // no one is signed in: stock changes are recorded as automatic
require_once __DIR__ . '/includes/inventory.php';

$db  = getDB();
$now = date('Y-m-d H:i:s');
$log = [];

function clog(string $msg): void {
    global $log;
    $log[] = '[' . date('H:i:s') . '] ' . $msg;
    echo end($log) . "\n";
}

clog('=== Clinic CRM Cron Start ===');

// ── 0. Automatic patient messages (every run; each message is sent once) ──
try {
    require_once __DIR__ . '/includes/messaging.php';
    $am = runAutomations($db);
    clog($am ? 'Messages: ' . implode(', ', array_map(fn($k, $n) => "$k $n", array_keys($am), $am)) . '.' : 'Messages: nothing due.');
} catch (Throwable $e) {
    clog('Automatic messages failed: ' . $e->getMessage());
}
try {
    require_once __DIR__ . '/includes/crm.php';
    if ($bn = runBroadcasts($db)) clog("Broadcasts: $bn sent.");
} catch (Throwable $e) {
    clog('Broadcasts failed: ' . $e->getMessage());
}
$lastHourly = metaGet($db, 'cron_hourly_last');
if ($lastHourly && strtotime($lastHourly) > time() - 3300) {   // the hourly jobs ran less than 55 minutes ago
    metaSet($db, 'cron_last_run', date('Y-m-d H:i:s'));
    clog('=== Cron Complete (hourly jobs not due) ===');
    exit(0);
}
metaSet($db, 'cron_hourly_last', date('Y-m-d H:i:s'));

// ── 1. Update aging statuses ──
updateAgingStatuses($db);
clog('Aging statuses updated.');

// ── 2. Auto-generate aging reminders for critical leads ──
$criticalLeads = $db->query("
    SELECT l.id, l.name, l.assigned_to
    FROM leads l
    WHERE l.aging_status = 'critical'
      AND l.stage NOT IN ('converted','lost','cold')
      AND l.assigned_to IS NOT NULL
      AND NOT EXISTS (
          SELECT 1 FROM reminders r
          WHERE r.lead_id = l.id
            AND r.is_sent = 0
            AND r.type = 'lead_aging'
      )
    LIMIT 50
")->fetchAll();

foreach ($criticalLeads as $lead) {
    $remindAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    $db->prepare("
        INSERT INTO reminders (lead_id, staff_id, message, remind_at, type)
        VALUES (?, ?, ?, ?, 'lead_aging')
    ")->execute([
        $lead['id'],
        $lead['assigned_to'],
        "CRITICAL: Lead '{$lead['name']}' has not been contacted in 7+ days!",
        $remindAt,
    ]);
}
clog(count($criticalLeads) . ' critical aging reminders created.');

// ── 3. Auto-generate follow-up due reminders ──
$dueFu = $db->query("
    SELECT DISTINCT f.lead_id, f.staff_id, l.name as lead_name, f.next_followup_at
    FROM followups f
    JOIN leads l ON l.id = f.lead_id
    WHERE f.next_followup_at BETWEEN DATE_SUB(NOW(), INTERVAL 1 HOUR) AND DATE_ADD(NOW(), INTERVAL 15 MINUTE)
      AND l.stage NOT IN ('converted','lost')
      AND NOT EXISTS (
          SELECT 1 FROM reminders r
          WHERE r.lead_id = f.lead_id AND r.is_sent = 0 AND r.type = 'followup_due'
            AND r.remind_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
      )
    LIMIT 100
")->fetchAll();

foreach ($dueFu as $fu) {
    $db->prepare("
        INSERT INTO reminders (lead_id, staff_id, message, remind_at, type)
        VALUES (?, ?, ?, ?, 'followup_due')
    ")->execute([
        $fu['lead_id'],
        $fu['staff_id'],
        "Follow-up due for lead: {$fu['lead_name']}",
        $fu['next_followup_at'],
    ]);
}
clog(count($dueFu) . ' follow-up due reminders created.');

// ── 4. Recalculate scores for recently updated leads ──
$staleScores = $db->query("
    SELECT id FROM leads
    WHERE (score_updated IS NULL OR score_updated < DATE_SUB(NOW(), INTERVAL 6 HOUR))
      AND stage NOT IN ('converted','lost')
    LIMIT 100
")->fetchAll(PDO::FETCH_COLUMN);

foreach ($staleScores as $lid) {
    updateLeadScore((int)$lid, $db);
}
clog(count($staleScores) . ' lead scores recalculated.');

// ── 5. (Turned off 2026-10-01 at the clinic's request.) This used to mark New leads that were never
//       contacted within 30 days as Cold; its first live run moved 1,127 leads, which were put back to New.

// ── 6. Clean up old sent reminders (older than 90 days) ──
$deleted = $db->exec("DELETE FROM reminders WHERE is_sent=1 AND sent_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
clog("$deleted old reminders purged.");

// ── 7. Clean up old webhook logs (older than 60 days) ──
$deletedWh = $db->exec("DELETE FROM meta_webhook_log WHERE received_at < DATE_SUB(NOW(), INTERVAL 60 DAY)");
clog("$deletedWh old webhook logs purged.");

// ── 8. Treatment stock: expired opened vials, uncompleted treatments, ledger check ──
try {
    ensureInventorySchema($db);
    $inv = runInventoryMaintenance($db);
    clog("{$inv['vials_written_off']} opened vials written off; {$inv['overdue_total']} treatments not completed ({$inv['overdue_reminders']} reminders sent); stock ledger " . ($inv['ledger_ok'] ? 'OK' : 'MISMATCH, see activity log') . '.');
} catch (Throwable $e) {
    clog('Inventory maintenance failed: ' . $e->getMessage());
}

// ── 9. Meta Lead Ads: pick up leads the webhook missed, send lead stages to Meta ──
try {
    require_once __DIR__ . '/includes/meta_leads.php';
    $ms = runMetaSync($db);
    $p = $ms['pull'] ?? [];
    clog(isset($p['skipped']) ? 'Meta: not connected, skipped.'
        : (isset($p['error']) ? 'Meta check failed: ' . $p['error']
        : "Meta: {$p['created']} new leads, {$p['updated']} matched to existing leads" . (!empty($p['errors']) ? ', ' . count($p['errors']) . ' problems' : '') . '.'));
    if (isset($ms['capi'])) clog('Meta stage updates: ' . ($ms['capi']['error'] ?? (($ms['capi']['sent'] ?? 0) . ' sent')) . '.');
} catch (Throwable $e) {
    clog('Meta sync failed: ' . $e->getMessage());
}

// ── 9b. Clinical notes: lock unsigned notes older than the clinic's auto-lock time (Clinical settings) ──
try {
    require_once __DIR__ . '/includes/emr.php';
    clog(emrAutoLock($db) . ' unsigned clinical note(s) locked.');
} catch (Throwable $e) {
    clog('Clinical note auto-lock skipped: ' . $e->getMessage());
}

// ── 9d. Campaigns from new leads' campaign names; recalls due ──
try {
    require_once __DIR__ . '/includes/crm.php';
    $cb = campaignBackfill($db);
    clog("Campaigns: {$cb['campaigns']} new, {$cb['leads']} leads linked. Recalls: " . generateRecalls($db) . ' new.');
} catch (Throwable $e) {
    clog('Campaigns/recalls skipped: ' . $e->getMessage());
}

// ── 9e. Nightly backup (once a day after 02:00 clinic time; 14 daily + 12 monthly kept) and residency check ──
try {
    require_once __DIR__ . '/includes/compliance.php';
    require_once __DIR__ . '/includes/messaging.php';   // staffNotify
    if (nightlyBackupDue($db)) {
        require_once __DIR__ . '/includes/backup.php';
        $day = (new DateTimeImmutable('now', new DateTimeZone(CLINIC_TZ)))->format('Y-m-d');
        try {
            $b = runBackup($db);
            metaSet($db, 'backup_last', json_encode(['day' => $day, 'at' => date('c'), 'file' => basename($b['file']), 'size' => $b['size'], 'tables' => $b['tables'], 'rows' => $b['rows'], 'pruned' => $b['pruned']]));
            clog("Backup: {$b['tables']} tables, {$b['rows']} rows, {$b['pruned']} old removed.");
        } catch (Throwable $e) {
            // Keep the last good backup's details; the day stays unchanged so the next hourly run tries again
            $prev = json_decode((string)metaGet($db, 'backup_last'), true) ?: [];
            metaSet($db, 'backup_last', json_encode(['error' => $e->getMessage(), 'error_at' => date('c')] + $prev));
            clog('Backup FAILED: ' . $e->getMessage());
            appLog('Nightly backup failed: ' . $e->getMessage());
            if (empty($prev['error'])) staffNotify($db, 'data.flows.view', 'backup', 'Last night’s backup failed', $e->getMessage(), '/pages/data_flows.php');   // once, not every retry
        }
        // The residency check once a day too: the admin hears about a service that is not in the data-flow register
        require_once __DIR__ . '/includes/data_flows.php';
        if ($rp = residencyCheck($db)) {
            staffNotify($db, 'data.flows.view', 'residency', 'UAE residency check: ' . count($rp) . ' problem(s)', implode("\n", array_slice($rp, 0, 5)), '/pages/data_flows.php');
            clog('Residency check: ' . count($rp) . ' problem(s).');
        }
    }
} catch (Throwable $e) {
    clog('Backup skipped: ' . $e->getMessage());
}

// ── 9c. Packages past their end date expire ──
try {
    require_once __DIR__ . '/includes/billing.php';
    clog(expirePackages($db) . ' package(s) expired.');
    require_once __DIR__ . '/includes/insurance.php';
    clog(expirePriorAuths($db) . ' prior approval(s) expired.');
} catch (Throwable $e) {
    clog('Package expiry skipped: ' . $e->getMessage());
}

// ── 10. Google Calendar: retry appointment updates that didn't reach a practitioner's calendar ──
try {
    $g = gcalRetryPending($db);
    if ($g['synced'] || $g['failed']) clog("Google Calendar: {$g['synced']} appointments updated, {$g['failed']} still failing.");
} catch (Throwable $e) {
    clog('Google Calendar retry failed: ' . $e->getMessage());
}

metaSet($db, 'cron_last_run', date('Y-m-d H:i:s'));
clog('=== Cron Complete ===');
