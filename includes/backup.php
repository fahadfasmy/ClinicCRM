<?php
// includes/backup.php — database backups (shared by tools/backup.php, the nightly backup in cron.php and the platform back
// office): a compressed dump into BACKUP_PATH (outside the web root) with a .sha256 file, ending with a "-- dump complete"
// line so tools/restore.php can refuse a cut-off file. Kept 14 days plus the first backup of each month for 12 months.
require_once __DIR__ . '/db.php';

/** Writes the dump to $path (gzip). Returns ['tables' => n, 'rows' => n]. */
function backupDatabase(PDO $db, string $path): array {
    $gz = gzopen($path, 'wb6');
    if (!$gz) throw new RuntimeException("Can't write $path");
    $dbName = $db->query("SELECT DATABASE()")->fetchColumn();
    $w = function (string $s) use ($gz) { if (gzwrite($gz, $s) === false) throw new RuntimeException('Writing the backup failed (disk full?)'); };
    $w("-- Clinic CRM backup of `$dbName` · " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='+00:00';\n\n");
    $db->exec("SET time_zone='+00:00'");   // TIMESTAMP columns come out in UTC and go back in UTC
    $tables = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    $views  = $db->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_COLUMN);
    $rows = 0;
    foreach ($tables as $t) {
        $create = $db->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        $w("DROP TABLE IF EXISTS `$t`;\n$create;\n");
        $cols = $db->query("SHOW COLUMNS FROM `$t`")->fetchAll();
        $binary = array_map(fn($c) => (bool)preg_match('/blob|binary/i', $c['Type']), $cols);
        $names = '`' . implode('`,`', array_column($cols, 'Field')) . '`';
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);   // stream big tables instead of loading them whole
        $q = $db->query("SELECT * FROM `$t`", PDO::FETCH_NUM);
        $batch = []; $size = 0;
        $flush = function () use (&$batch, &$size, $w, $t, $names) {
            if ($batch) $w("INSERT INTO `$t` ($names) VALUES\n" . implode(",\n", $batch) . ";\n");
            $batch = []; $size = 0;
        };
        foreach ($q as $r) {
            $vals = [];
            foreach ($r as $i => $v) $vals[] = $v === null ? 'NULL' : ($binary[$i] ? ($v === '' ? "''" : '0x' . bin2hex($v)) : $db->quote((string)$v));
            $line = '(' . implode(',', $vals) . ')';
            $batch[] = $line; $size += strlen($line); $rows++;
            if (count($batch) >= 500 || $size > 1000000) $flush();
        }
        $q->closeCursor();
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        $flush();
        $w("\n");
    }
    foreach ($views as $v) {
        $create = $db->query("SHOW CREATE VIEW `$v`")->fetch(PDO::FETCH_NUM)[1];
        $w("DROP VIEW IF EXISTS `$v`;\n" . preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s*/', '', $create) . ";\n");
    }
    $w("SET FOREIGN_KEY_CHECKS=1;\n-- dump complete\n");
    gzclose($gz);
    return ['tables' => count($tables), 'rows' => $rows];
}

/**
 * Removes this database's backups older than $days days (only files this tool made), except the first backup of each
 * month for the last $months months. Returns how many were removed.
 */
function pruneBackups(string $dbName, int $days, ?string $dir = null, int $months = 12): int {
    $files = array_filter(glob(rtrim($dir ?? BACKUP_PATH, '/\\') . '/' . $dbName . '_*.sql.gz') ?: [], fn($f) => preg_match('/_\d{8}_\d{6}\.sql\.gz$/', $f));
    sort($files);   // names sort by date
    $monthly = [];
    foreach ($files as $f) { preg_match('/_(\d{6})\d{2}_\d{6}\.sql\.gz$/', $f, $m); $monthly[$m[1]] ??= $f; }
    $keepMonthly = array_slice(array_reverse(array_values($monthly)), 0, $months);
    $n = 0;
    foreach ($files as $f) {
        if (filemtime($f) >= time() - $days * 86400 || in_array($f, $keepMonthly, true)) continue;
        @unlink($f); @unlink($f . '.sha256'); $n++;
    }
    return $n;
}

/** One backup of $db into BACKUP_PATH, then the old ones pruned. Returns what happened (file, tables, rows, size, pruned). */
function runBackup(PDO $db, int $keep = 14): array {
    $dbName = $db->query("SELECT DATABASE()")->fetchColumn();
    if (!is_dir(BACKUP_PATH) && !mkdir(BACKUP_PATH, 0750, true)) throw new RuntimeException('Can\'t create ' . BACKUP_PATH);
    $webRoot = realpath(__DIR__ . '/..');
    if (str_starts_with((string)realpath(BACKUP_PATH), $webRoot)) throw new RuntimeException('BACKUP_PATH is inside the web root; set it outside (see config.example.php).');
    // Two runs in the same second (duplicate cron entries) once overwrote each other's file: each run claims its own
    // name by creating the .part file exclusively, moving a second on when the name is taken
    for ($i = 0; ; $i++) {
        $final = rtrim(BACKUP_PATH, '/\\') . '/' . $dbName . '_' . date('Ymd_His', time() + $i) . '.sql.gz';
        $tmp = $final . '.part';
        if (!is_file($final) && ($h = @fopen($tmp, 'x'))) { fclose($h); break; }
        if ($i > 60) throw new RuntimeException('Could not find a free backup file name.');
    }
    try {
        $t0 = microtime(true);
        $r = backupDatabase($db, $tmp);
        rename($tmp, $final);
    } catch (Throwable $e) { @unlink($tmp); throw $e; }
    file_put_contents($final . '.sha256', hash_file('sha256', $final) . '  ' . basename($final) . "\n");
    return ['file' => $final, 'tables' => $r['tables'], 'rows' => $r['rows'], 'size' => filesize($final), 'seconds' => round(microtime(true) - $t0, 1), 'pruned' => pruneBackups($dbName, $keep)];
}

