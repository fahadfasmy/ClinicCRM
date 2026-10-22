<?php
// status.php — a public health page for the clinic's address (for staff, and for uptime monitors with ?format=json):
// the database answers, scheduled jobs (cron) ran in the last 15 minutes, last night's backup, and the database
// updates are current. Shows nothing about patients, settings or the server. HTTP 503 when something is down.
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/migrations.php';
$checks = [];
$db = null;
try { $db = getDB(); $db->query("SELECT 1"); $checks['database'] = ['ok', 'Answering']; }
catch (Throwable $e) { $checks['database'] = ['down', 'Not answering']; }
if ($db) {
    $meta = fn(string $k) => (function () use ($db, $k) { try { $s = $db->prepare("SELECT v FROM app_meta WHERE k = ?"); $s->execute([$k]); return $s->fetchColumn() ?: null; } catch (Throwable $e) { return null; } })();
    $cron = $meta('cron_last_run');
    $age = $cron ? (int)floor((time() - strtotime($cron)) / 60) : null;
    $checks['scheduled_jobs'] = $age === null ? ['warn', 'No run recorded yet'] : ($age <= 15 ? ['ok', "Last ran $age min ago"] : ['warn', "Last ran $age min ago (expected every 5)"]);
    $b = json_decode((string)$meta('backup_last'), true) ?: [];
    $bAge = !empty($b['at']) ? (int)floor((time() - strtotime($b['at'])) / 3600) : null;
    $checks['backup'] = $bAge === null ? ['warn', 'No nightly backup yet'] : ($bAge <= 30 && empty($b['error']) ? ['ok', "Last backup $bAge h ago"] : ['warn', !empty($b['error']) ? 'The last backup failed' : "Last backup $bAge h ago"]);
    try {
        $have = $db->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
        $files = array_map(fn($f) => basename($f, '.sql'), glob(__DIR__ . '/migrations/tenant/*.sql'));
        $pending = array_diff($files, $have);
        $checks['database_updates'] = $pending ? ['warn', count($pending) . ' waiting to be applied'] : ['ok', 'Up to date'];
    } catch (Throwable $e) { $checks['database_updates'] = ['warn', 'Unknown']; }
}
$overall = in_array('down', array_column($checks, 0), true) ? 'down' : (in_array('warn', array_column($checks, 0), true) ? 'degraded' : 'ok');
http_response_code($overall === 'down' ? 503 : 200);
header('Cache-Control: no-store');
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json');
    exit(json_encode(['status' => $overall, 'checks' => array_map(fn($c) => ['status' => $c[0], 'detail' => $c[1]], $checks), 'at' => date('c')]));
}
$label = ['ok' => 'All systems working', 'degraded' => 'Working, with a warning', 'down' => 'Not working'];
$colour = ['ok' => '#15803d', 'warn' => '#b45309', 'down' => '#b91c1c', 'degraded' => '#b45309'];
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta http-equiv="refresh" content="60"><title>Status</title>
<style>body{font:16px/1.5 system-ui,Segoe UI,Arial,sans-serif;max-width:620px;margin:50px auto;padding:0 18px;color:#1c1917}h1{font-size:22px}li{margin:6px 0;list-style:none}.dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:8px}</style></head><body>
<h1><span class="dot" style="background:<?= $colour[$overall] ?>"></span><?= $label[$overall] ?></h1>
<ul style="padding:0"><?php foreach ($checks as $k => [$st, $detail]): ?><li><span class="dot" style="background:<?= $colour[$st] ?>"></span><b><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $k))) ?>:</b> <?= htmlspecialchars($detail) ?></li><?php endforeach; ?></ul>
<p style="color:#78716c;font-size:13px">Checked <?= date('d M Y H:i') ?> · refreshes every minute · <a href="?format=json">JSON</a></p>
</body></html>
