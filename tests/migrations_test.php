<?php
// The SQL splitter and the migrations runner.

function test_splitter_keeps_semicolons_inside_strings_and_comments() {
    $sql = "CREATE TABLE a (x VARCHAR(10) DEFAULT ';');\n-- a comment; with a semicolon\nINSERT INTO a VALUES ('it''s; ok'), (\"say \\\"hi;\\\"\");\n"
         . "# hash comment;\n/* block; comment */ UPDATE a SET x = 'b' WHERE x = `x`;\n/*!40101 SET NAMES utf8mb4 */;\nSELECT 1";
    $s = sqlSplit($sql);
    check(count($s) === 5, 'five statements, got ' . count($s));
    check(str_contains($s[0], "DEFAULT ';'"), 'semicolon inside a default value kept');
    check(str_contains($s[1], "'it''s; ok'") && str_contains($s[1], '\\"hi;\\"'), 'quotes and escaped quotes kept whole');
    check(!str_contains($s[2], 'block'), 'block comment removed');
    check(str_starts_with($s[3], '/*!40101'), 'MySQL conditional comment kept (it runs)');
    check($s[4] === 'SELECT 1', 'last statement without a semicolon');
}

function test_splitter_streaming_matches_whole() {
    $sql = "INSERT INTO t VALUES ('a;b'),\n('c\\'d;');\nINSERT INTO t VALUES ('é;ü');\n";
    $st = sqlSplitNew(); $out = [];
    foreach (str_split($sql, 3) as $chunk) $out = array_merge($out, sqlSplitFeed($st, $chunk));
    $out = array_merge($out, sqlSplitFeed($st, '', true));
    check($out === sqlSplit($sql), 'feeding 3 bytes at a time gives the same statements');
    checkThrows(fn() => sqlSplit("INSERT INTO t VALUES ('unterminated"), 'quoted string', 'an unterminated string is an error');
}

function test_migrations_apply_once_in_order_and_record() {
    $db = testDb('clinic_crm_test_mig', true);
    $dir = tempDir('mig');
    file_put_contents("$dir/0002_add_row.sql", "INSERT INTO mig_demo (name) VALUES ('second; file');");
    file_put_contents("$dir/0001_create_demo.sql", "CREATE TABLE IF NOT EXISTS mig_demo (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50));\nINSERT INTO mig_demo (name) VALUES ('first');");
    $log = [];
    check(migrateApply($db, $dir, function ($l) use (&$log) { $log[] = $l; }) === 2, 'two applied');
    check(str_contains($log[0], '0001_create_demo') && str_contains($log[1], '0002_add_row'), 'applied in name order');
    check($db->query("SELECT GROUP_CONCAT(name ORDER BY id) FROM mig_demo")->fetchColumn() === 'first,second; file', 'both files ran');
    check((int)$db->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn() === 2, 'both recorded in schema_migrations');
    check(migrateApply($db, $dir, fn($l) => null) === 0, 'running again applies nothing');
    check(!migrationStatus($db, $dir)['pending'], 'nothing pending');

    file_put_contents("$dir/0001_create_demo.sql", "-- edited\n" . file_get_contents("$dir/0001_create_demo.sql"));
    check(migrationStatus($db, $dir)['changed'] === ['0001_create_demo'], 'an edited applied file is detected');
    checkThrows(fn() => migrateApply($db, $dir, fn($l) => null), 'were edited afterwards', 'and the runner refuses to continue');
    rmTree($dir);
    testDb('clinic_crm_test_mig', true);
}

function test_failed_migration_stops_and_is_not_recorded() {
    $db = testDb('clinic_crm_test_mig', true);
    $dir = tempDir('mig');
    file_put_contents("$dir/0001_ok.sql", "CREATE TABLE IF NOT EXISTS mig_ok (id INT PRIMARY KEY);");
    file_put_contents("$dir/0002_broken.sql", "INSERT INTO mig_ok VALUES (1);\nINSERT INTO no_such_table VALUES (1);");
    file_put_contents("$dir/0003_never.sql", "CREATE TABLE mig_never (id INT);");
    checkThrows(fn() => migrateApply($db, $dir, fn($l) => null), '0002_broken, statement 2', 'the failing file and statement are named');
    $done = $db->query("SELECT version FROM schema_migrations ORDER BY version")->fetchAll(PDO::FETCH_COLUMN);
    check($done === ['0001_ok'], 'only the file before the failure is recorded');
    check((int)$db->query("SELECT COUNT(*) FROM mig_ok")->fetchColumn() === 0, 'the broken file\'s data change was rolled back');
    check(!$db->query("SHOW TABLES LIKE 'mig_never'")->fetch(), 'files after the failure did not run');
    file_put_contents("$dir/0002_broken.sql", "INSERT INTO mig_ok VALUES (1);");
    check(migrateApply($db, $dir, fn($l) => null) === 2, 'once fixed, the rest applies');
    file_put_contents("$dir/0004 Bad Name.sql", "SELECT 1;");
    checkThrows(fn() => migrationFiles($dir), 'Bad migration file name', 'badly named files are refused');
    rmTree($dir);
    testDb('clinic_crm_test_mig', true);
}

function test_migrate_cli_dry_run_lists_without_applying() {
    $out = shell_exec(escapeshellarg(phpBin()) . ' ' . escapeshellarg(__DIR__ . '/../tools/migrate.php') . ' --dry-run 2>&1');
    check(str_contains((string)$out, 'environment: local') && (str_contains($out, 'Nothing to apply') || str_contains($out, 'pending')), 'dry run reports without applying: ' . trim((string)$out));
}

function test_migrate_cli_runs_one_at_a_time() {
    $db = getDB();
    $name = $db->query("SELECT DATABASE()")->fetchColumn();
    $db->query("SELECT GET_LOCK(" . $db->quote('clinic_migrate_' . $name) . ", 0)");   // as if another run were busy
    $out = shell_exec(escapeshellarg(phpBin()) . ' ' . escapeshellarg(__DIR__ . '/../tools/migrate.php') . ' 2>&1');
    $db->query("SELECT RELEASE_LOCK(" . $db->quote('clinic_migrate_' . $name) . ")");
    check(str_contains((string)$out, 'already running'), 'a second update of the same database stops: ' . trim((string)$out));
}
