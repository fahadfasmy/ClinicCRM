<?php
// tools/import_clinicsoft.php — the ClinicSoft import from the command line (very large files, or scripted go-live).
// Same engine and rules as Admin → Import from ClinicSoft; without --commit it only checks (nothing saved).
//   php tools/import_clinicsoft.php --kind=patients --file=patients.csv [--tenant=slug] [--map="legacy_id=File No;mobile=Mobile 1"] [--commit]
// Kinds: patients, balances, packages, appointments, visits, invoices, notes. Columns are matched by name; --map overrides.
// Writes the rows that need attention to <file>.report.csv.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/db.php';
$a = getopt('', ['kind:', 'file:', 'map:', 'commit', 'tenant:']);
cliTenant();
require_once __DIR__ . '/../includes/importer.php';
try {
    $kind = (string)($a['kind'] ?? '');
    if (!isset(IMPORT_KINDS[$kind])) throw new RuntimeException('--kind is one of: ' . implode(', ', array_keys(IMPORT_KINDS)));
    $file = (string)($a['file'] ?? '');
    if (!is_file($file)) throw new RuntimeException('--file not found.');
    [$headers, $rows] = importRead($file);
    $map = importGuessMapping($kind, $headers);
    foreach (array_filter(explode(';', (string)($a['map'] ?? ''))) as $pair) {
        [$f, $h] = array_map('trim', explode('=', $pair, 2) + [1 => '']);
        $i = array_search(mb_strtolower($h), array_map('mb_strtolower', $headers), true);
        if (!isset(IMPORT_KINDS[$kind]['fields'][$f]) || $i === false) throw new RuntimeException("--map: no field \"$f\" or no column \"$h\".");
        $map[$f] = $i;
    }
    echo "Columns: " . implode(', ', array_map(fn($f, $i) => "$f ← {$headers[$i]}", array_keys($map), $map)) . "\n";
    $commit = isset($a['commit']);
    $db = getDB();
    $db->prepare("INSERT INTO import_runs (kind, file_name, file_hash, mapping, status, rows_total) VALUES (?,?,?,?,?,?)")
       ->execute([$kind, basename($file), hash_file('sha256', $file), json_encode($map), $commit ? 'committed' : 'dry_run', count($rows)]);
    $run = (int)$db->lastInsertId();
    $rep = importRun($db, $kind, $headers, $rows, $map, $commit, $run);
    $db->prepare("UPDATE import_runs SET rows_ok = ?, rows_skipped = ?, rows_error = ?, report = ?, committed_at = IF(status = 'committed', NOW(), NULL) WHERE id = ?")
       ->execute([$rep['ok'], $rep['skipped'], $rep['error'], json_encode($rep, JSON_UNESCAPED_UNICODE), $run]);
    file_put_contents("$file.report.csv", importReportCsv($rep));
    printf("%s: %d rows, %d %s, %d skipped, %d need fixing (see %s.report.csv).\n", $commit ? 'Imported' : 'Checked (nothing saved)', count($rows), $rep['ok'], $commit ? 'imported' : 'can be imported', $rep['skipped'], $rep['error'], basename($file));
    exit($rep['error'] ? 3 : 0);
} catch (Throwable $e) { fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n"); exit(1); }
