<?php
// includes/platform_jobs.php — the platform's own scheduled jobs, run by cron.php before it loops over the clinics
// (multi-clinic mode only): status dates (trial over, grace over, archive), trial reminders, and this month's usage per
// clinic (messages sent, active staff, storage) for the back office and the limits overview.
require_once __DIR__ . '/platform_billing.php';

/** This month's usage for every clinic that isn't archived. Returns how many clinics were counted. */
function platformUsageCounters(): int {
    $p = platformDB(); $n = 0;
    foreach ($p->query("SELECT * FROM tenants WHERE status <> 'archived'")->fetchAll() as $row) {
        try {
            $t = tenantFromRow($row + ['primary_host' => '']);
            $db = dbConnect($t['db_name'], $t['db_user'], $t['db_pass'], $t['db_host']);
            $msgs = (int)$db->query("SELECT COUNT(*) FROM messages WHERE direction = 'out' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn();
            $staff = (int)$db->query("SELECT COUNT(*) FROM staff WHERE is_active = 1 AND COALESCE(is_support, 0) = 0")->fetchColumn();
            $bytes = 0; $dir = rtrim(STORAGE_PATH, '/\\') . '/' . $t['id'];
            if (is_dir($dir)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) $bytes += $f->getSize();
            $p->prepare("INSERT INTO usage_counters (tenant_id, period, messages_sent, storage_bytes, active_staff) VALUES (?, DATE_FORMAT(NOW(), '%Y-%m'), ?, ?, ?)
                         ON DUPLICATE KEY UPDATE messages_sent = VALUES(messages_sent), storage_bytes = VALUES(storage_bytes), active_staff = VALUES(active_staff)")
              ->execute([$t['id'], $msgs, $bytes, $staff]);
            $n++;
        } catch (Throwable $e) { appLog("Usage count for clinic {$row['id']} failed: " . $e->getMessage()); }
    }
    return $n;
}

/** All platform jobs; one failing never stops the others. Returns log lines. */
function platformJobs(): array {
    $out = [];
    foreach (['Billing dates' => fn() => json_encode(billingSweep()), 'Trial reminders' => fn() => trialEmails() . ' sent', 'Usage' => fn() => platformUsageCounters() . ' clinics counted'] as $name => $job) {
        try { $out[] = "$name: " . $job(); } catch (Throwable $e) { $out[] = "$name FAILED: " . $e->getMessage(); appLog("Platform job $name failed: " . $e->getMessage()); }
    }
    return $out;
}
