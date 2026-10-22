<?php
// Backups: a dump restores to an identical database; damaged or cut-off dumps are refused; old backups are pruned.
require_once __DIR__ . '/../tools/backup.php';
require_once __DIR__ . '/../tools/restore.php';

function backupFixture(): PDO {
    $db = testDb('clinic_crm_test_bak', true);
    $db->exec("CREATE TABLE people (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), note TEXT NULL, secret VARBINARY(64) NULL,
               amount DECIMAL(12,2), seen DATETIME NULL, ts TIMESTAMP NULL DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE child (id INT PRIMARY KEY, person_id INT UNSIGNED, FOREIGN KEY (person_id) REFERENCES people(id)) ENGINE=InnoDB");
    $ins = $db->prepare("INSERT INTO people (name, note, secret, amount, seen, ts) VALUES (?,?,?,?,?,?)");
    $ins->execute(['سارة أحمد', "line1\nline2; with 'quotes' and \\backslash", random_bytes(32), '1234.56', '2026-10-16 09:30:00', '2026-10-16 09:30:00']);
    $ins->execute(['Zero', null, "\0\x01\xff", '0.00', null, null]);
    $ins->execute(['Empty', '', '', '-5.10', '2000-01-01 00:00:00', null]);
    for ($i = 0; $i < 1200; $i++) $ins->execute(["Bulk $i", str_repeat('x', 50), null, $i, null, null]);   // more than one INSERT batch
    $db->exec("INSERT INTO child VALUES (1, 1), (2, 2)");
    $db->exec("CREATE VIEW people_names AS SELECT id, name FROM people");
    return $db;
}

function tableChecksums(PDO $db): array {
    $out = [];
    foreach ($db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $t)
        $out[$t] = $db->query("CHECKSUM TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
    return $out;
}

function test_backup_restores_identically() {
    $src = backupFixture();
    $dir = tempDir('bak');
    $file = "$dir/clinic_crm_test_bak_20261016_120000.sql.gz";
    $r = backupDatabase($src, $file);
    check($r['tables'] === 2 && $r['rows'] === 1205, "2 tables, 1205 rows dumped (got {$r['tables']}, {$r['rows']})");
    $dst = testDb('clinic_crm_restore_t', true);
    restoreDump($dst, $file);
    check(tableChecksums($src) === tableChecksums($dst), 'every table identical after restore (Arabic, binary, NULLs, decimals, newlines, quotes)');
    check($dst->query("SELECT name FROM people_names WHERE id = 1")->fetchColumn() === 'سارة أحمد', 'views restored');
    check($dst->query("SELECT ts FROM people WHERE id = 1")->fetchColumn() === $src->query("SELECT ts FROM people WHERE id = 1")->fetchColumn(), 'TIMESTAMP values unchanged');
    rmTree($dir);
    testDb('clinic_crm_test_bak', true); testDb('clinic_crm_restore_t', true);
}

function test_damaged_or_cut_off_backups_are_refused() {
    $src = backupFixture();
    $dir = tempDir('bak');
    $file = "$dir/clinic_crm_test_bak_20261016_120000.sql.gz";
    backupDatabase($src, $file);
    // Cut off: drop the last few lines
    $lines = gzfile($file);
    $cut = "$dir/cut.sql.gz"; $g = gzopen($cut, 'wb'); gzwrite($g, implode('', array_slice($lines, 0, -3))); gzclose($g);
    checkThrows(fn() => restoreDump(testDb('clinic_crm_restore_t', true), $cut), 'incomplete', 'a cut-off dump is refused');
    check(!testDb('clinic_crm_restore_t')->query("SHOW TABLES")->fetch(), '…and nothing was loaded from it');
    file_put_contents("$file.sha256", str_repeat('0', 64) . '  x');
    checkThrows(fn() => restoreDump(testDb('clinic_crm_restore_t', true), $file), 'Checksum mismatch', 'a file that fails its checksum is refused');
    rmTree($dir);
    testDb('clinic_crm_test_bak', true); testDb('clinic_crm_restore_t', true);
}

function test_restore_cli_refuses_a_non_empty_real_database() {
    $out = shell_exec(escapeshellarg(phpBin()) . ' ' . escapeshellarg(__DIR__ . '/../tools/restore.php') . ' --file=' . escapeshellarg(__FILE__) . ' --into=clinic_crm_local 2>&1');
    check(str_contains((string)$out, 'already has') || str_contains((string)$out, 'FAILED'), 'restoring over clinic_crm_local is refused: ' . trim((string)$out));
}

function test_prune_removes_only_old_backups_of_that_database() {
    $dir = tempDir('bak');
    $mk = function ($name, $daysOld) use ($dir) { file_put_contents("$dir/$name", 'x'); touch("$dir/$name", time() - $daysOld * 86400); };
    $mk('mydb_20260101_000000.sql.gz', 20);
    $mk('mydb_20260110_000000.sql.gz', 3);
    $mk('otherdb_20260101_000000.sql.gz', 20);
    $mk('mydb_manual_copy.sql.gz', 20);
    $out = pruneBackups('mydb', 14, $dir, 0);   // no monthly copies kept in this test
    $left = array_map('basename', glob("$dir/*"));
    sort($left);
    check($out === 1, 'one file pruned');
    check($left === ['mydb_20260110_000000.sql.gz', 'mydb_manual_copy.sql.gz', 'otherdb_20260101_000000.sql.gz'], 'only the old automatic backup of that database went: ' . implode(', ', $left));
    rmTree($dir);
}

function test_prune_keeps_the_first_backup_of_each_month_for_12_months() {
    $dir = tempDir('bak');
    $mk = function ($name, $daysOld) use ($dir) { file_put_contents("$dir/$name", 'x'); touch("$dir/$name", time() - $daysOld * 86400); };
    // 14 months of history, two backups a month (the 1st and the 15th), all older than 14 days
    for ($i = 1; $i <= 14; $i++) { $m = date('Ym', strtotime("first day of -$i month")); $mk("mydb_{$m}01_020000.sql.gz", 30 * $i + 10); $mk("mydb_{$m}15_020000.sql.gz", 30 * $i - 5); }
    $mk('mydb_' . date('Ymd') . '_020000.sql.gz', 0);
    pruneBackups('mydb', 14, $dir, 12);
    $left = array_map('basename', glob("$dir/*"));
    $firsts = array_filter($left, fn($f) => str_contains($f, '01_020000'));
    check(count($firsts) === 11 && count($left) === 12, 'one backup per month for 12 months is kept: this month’s (today) and the 1st of the 11 before: ' . count($left));
    check(!in_array('mydb_' . date('Ym', strtotime('first day of -12 month')) . '01_020000.sql.gz', $left, true), 'the 12-month-old one went');
    rmTree($dir);
}

function test_lead_dates_read_back_from_a_backup() {
    require_once __DIR__ . '/../tools/repair_lead_timestamps.php';
    $db = testDb('clinic_crm_test_dumpread', true);
    $db->exec("CREATE TABLE leads (id INT PRIMARY KEY, name VARCHAR(100), notes TEXT, updated_at DATETIME)");
    $tricky = ["O'Brien \ back", "Line1\nLine2, (with) commas", 'فاطمة', null];
    $ins = $db->prepare("INSERT INTO leads (id, name, notes, updated_at) VALUES (?, ?, ?, ?)");
    foreach ($tricky as $i => $t) $ins->execute([$i + 1, $t ?? 'x', $t, '2025-0' . ($i + 1) . '-0' . ($i + 1) . ' 10:20:30']);
    $dir = tempDir('dumpread');
    backupDatabase($db, "$dir/d.sql.gz");
    $stamps = leadStampsFromDump("$dir/d.sql.gz");
    check($stamps === [1 => '2025-01-01 10:20:30', 2 => '2025-02-02 10:20:30', 3 => '2025-03-03 10:20:30', 4 => '2025-04-04 10:20:30'], 'every lead’s date is read back, past quotes, backslashes, new lines, commas, Arabic and NULL');
    $gz = gzopen("$dir/d.sql.gz", 'rb'); $sql = ''; while (!gzeof($gz)) $sql .= gzgets($gz, 1 << 20); gzclose($gz);
    $st = sqlSplitNew(); $rows = [];
    foreach (sqlSplitFeed($st, $sql, true) as $s) $rows = array_merge($rows, dumpInsertRows($s));
    check(array_column($rows, 'notes') === $tricky, 'and the text values come back exactly');
    rmTree($dir);
}

function test_two_backups_at_the_same_moment_keep_separate_files() {
    $procs = []; $pipes = [];
    foreach ([0, 1] as $k) $procs[$k] = proc_open([phpBin(), __DIR__ . '/../tools/backup.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k]);
    $files = [];
    foreach ([0, 1] as $k) { $out = stream_get_contents($pipes[$k][1]); proc_close($procs[$k]); if (preg_match('/Backup OK: (\S+\.sql\.gz)/', $out, $m)) $files[] = $m[1]; }
    check(count($files) === 2 && $files[0] !== $files[1] && is_file($files[0]) && is_file($files[1]), 'two runs, two different backup files: ' . implode(', ', array_map('basename', $files)));
    foreach ($files as $f) { @unlink($f); @unlink($f . '.sha256'); }
}
