<?php
// tools/repair_lead_timestamps.php — one-off repair (17 Oct 2026). The Phase 3 patient backfill linked leads to their new
// patient records, and MySQL bumped each lead's updated_at ("ON UPDATE CURRENT_TIMESTAMP"). Reports count conversions by
// that date, so converted leads looked converted on the backfill day. This puts back each such lead's updated_at from the
// last backup made BEFORE the backfill. Only leads whose updated_at still equals (to the second) the moment their patient
// record was created are touched, so anything staff changed since is left alone. Nothing else is changed.
//   php tools/repair_lead_timestamps.php          dry run: what would change
//   php tools/repair_lead_timestamps.php --yes    repair (production: takes a fresh backup first, only if there is work)
// Running it again finds nothing to do.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/migrations.php';   // sqlSplitNew/Feed, newestBackup
require_once __DIR__ . '/backup.php';                   // backupDatabase()

/** The rows of one "INSERT INTO t (cols) VALUES (…),(…)" statement written by backupDatabase(): [[col => value], …]. */
function dumpInsertRows(string $stmt): array {
    if (!preg_match('/^INSERT INTO `[^`]+` \(([^)]*)\) VALUES\s*/s', $stmt, $m)) return [];
    $cols = array_map(fn($c) => trim($c, '` '), explode(',', $m[1]));
    $s = substr($stmt, strlen($m[0])); $n = strlen($s); $i = 0; $rows = [];
    $esc = ['0' => "\0", 'n' => "\n", 'r' => "\r", 'Z' => "\x1a", 't' => "\t", 'b' => "\x08"];
    while ($i < $n) {
        while ($i < $n && $s[$i] !== '(') $i++;
        if ($i >= $n) break;
        $i++; $vals = [];
        while (true) {
            while ($s[$i] === ' ' || $s[$i] === "\n") $i++;
            if ($s[$i] === "'") {
                $v = ''; $i++;
                while ($s[$i] !== "'") {
                    if ($s[$i] === '\\') { $c = $s[$i + 1]; $v .= $esc[$c] ?? $c; $i += 2; continue; }
                    $v .= $s[$i++];
                }
                $i++;   // PDO::quote() escapes quotes with a backslash, so a closing quote always ends the string
                $vals[] = $v;
            } else {
                $start = $i;
                while ($s[$i] !== ',' && $s[$i] !== ')') $i++;
                $raw = trim(substr($s, $start, $i - $start));
                $vals[] = $raw === 'NULL' ? null : $raw;
            }
            if ($s[$i] === ',') { $i++; continue; }
            $i++; break;   // ')'
        }
        if (count($vals) === count($cols)) $rows[] = array_combine($cols, $vals);
    }
    return $rows;
}

/** [lead id => updated_at] from a backup file (streams, so large dumps are fine). */
function leadStampsFromDump(string $file): array {
    $gz = gzopen($file, 'rb');
    if (!$gz) throw new RuntimeException("Can't open $file");
    $st = sqlSplitNew(); $out = [];
    $take = function (string $stmt) use (&$out) {
        if (!str_starts_with($stmt, 'INSERT INTO `leads` ')) return;
        foreach (dumpInsertRows($stmt) as $r) if (isset($r['id'], $r['updated_at'])) $out[(int)$r['id']] = $r['updated_at'];
    };
    while (!gzeof($gz)) foreach (sqlSplitFeed($st, (string)gzgets($gz, 1 << 20)) as $s) $take($s);
    foreach (sqlSplitFeed($st, '', true) as $s) $take($s);
    gzclose($gz);
    return $out;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $yes = in_array('--yes', $argv, true);
    cliTenant();
    try {
        $db = getDB();
        $dbName = $db->query("SELECT DATABASE()")->fetchColumn();
        $backfillAt = $db->query("SELECT MIN(at) FROM audit_log WHERE entity = 'patient' AND action = 'backfill'")->fetchColumn();
        if (!$backfillAt) { echo "No patient backfill recorded: nothing to repair.\n"; exit(0); }
        // Leads the backfill touched and nobody has changed since: updated_at equals the second their patient was made
        $rows = $db->query("SELECT l.id, l.updated_at, p.created_at FROM leads l JOIN patients p ON p.lead_id = l.id
                            WHERE ABS(TIMESTAMPDIFF(SECOND, l.updated_at, p.created_at)) <= 1 AND p.created_at BETWEEN '$backfillAt' - INTERVAL 30 MINUTE AND '$backfillAt'")->fetchAll();
        echo "Backfill at $backfillAt; leads still carrying its timestamp: " . count($rows) . "\n";
        if (!$rows) { echo "Nothing to repair.\n"; exit(0); }
        $files = array_filter(glob(rtrim(BACKUP_PATH, '/\\') . '/' . $dbName . '_*.sql.gz') ?: [], fn($f) => filemtime($f) < strtotime($backfillAt));
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $file = $files[0] ?? null;
        if (!$file) throw new RuntimeException('No backup from before the backfill was found in ' . BACKUP_PATH . '.');
        $stamps = leadStampsFromDump($file);
        echo 'Original dates from ' . basename($file) . ' (' . count($stamps) . " leads in it)\n";
        $fix = [];
        foreach ($rows as $r) if (isset($stamps[(int)$r['id']]) && $stamps[(int)$r['id']] !== $r['updated_at']) $fix[(int)$r['id']] = $stamps[(int)$r['id']];
        echo count($fix) . ' lead(s) to put back' . (count($rows) - count($fix) ? ', ' . (count($rows) - count($fix)) . ' not in that backup (left as they are)' : '') . ".\n";
        foreach (array_slice($fix, 0, 5, true) as $id => $v) echo "  lead #$id → $v\n";
        if (!$yes) { echo "Dry run: nothing changed. Add --yes to repair.\n"; exit(0); }
        if (APP_ENV === 'production') {
            $bk = rtrim(BACKUP_PATH, '/\\') . '/' . $dbName . '_' . date('Ymd_His') . '.sql.gz';
            $r = backupDatabase($db, $bk);
            file_put_contents($bk . '.sha256', hash_file('sha256', $bk) . '  ' . basename($bk) . "\n");
            echo "Backup first: " . basename($bk) . " ({$r['rows']} rows)\n";
        }
        $db->beginTransaction();
        $u = $db->prepare("UPDATE leads SET updated_at = ? WHERE id = ?");
        foreach ($fix as $id => $v) $u->execute([$v, $id]);
        $db->prepare("INSERT INTO audit_log (entity, entity_id, action, changes) VALUES ('lead', 0, 'timestamps_repaired', ?)")
           ->execute([json_encode(['leads' => count($fix), 'from_backup' => basename($file)])]);
        $db->commit();
        echo 'Repaired ' . count($fix) . " lead(s).\n";
    } catch (Throwable $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        fwrite(STDERR, 'Repair FAILED: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
