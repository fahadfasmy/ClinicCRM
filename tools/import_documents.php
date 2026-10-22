<?php
// tools/import_documents.php — scanned documents from ClinicSoft (ID copies, insurance cards, reports, consent PDFs):
// a folder of files whose names start with the patient's ClinicSoft file number, e.g. "10234_emirates-id.pdf" or
// "10234 - lab report.jpg". Each lands on the patient's Documents tab (type guessed from the name: eid / passport /
// insurance / report / other). PDF, JPG and PNG up to 10 MB. Without --commit it only lists what it would do.
//   php tools/import_documents.php --dir=/path/to/scans [--tenant=slug] [--pattern="/^(\d+)[ _-]/"] [--commit]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/db.php';
$a = getopt('', ['dir:', 'pattern:', 'commit', 'tenant:']);
cliTenant();
require_once __DIR__ . '/../includes/importer.php';
$dir = rtrim((string)($a['dir'] ?? ''), '/\\');
if (!is_dir($dir)) { fwrite(STDERR, "--dir not found\n"); exit(2); }
$pattern = $a['pattern'] ?? '/^([A-Za-z0-9-]+?)[ _-]/';
$commit = isset($a['commit']);
$db = getDB();
$n = ['added' => 0, 'skipped' => 0, 'error' => 0]; $report = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
    if (!$f->isFile()) continue;
    $name = $f->getFilename();
    try {
        if (!preg_match($pattern, $name, $m)) throw new RuntimeException('No file number at the start of the name.');
        $pid = importPatient($db, $m[1]);
        $key = $m[1] . '|' . hash_file('sha256', $f->getPathname());
        if (importKeySeen($db, 'document', $key) !== null) { $n['skipped']++; $report[] = [$name, 'skipped', 'Already imported.']; continue; }
        $l = strtolower($name);
        $type = str_contains($l, 'passport') ? 'passport' : (preg_match('/emirates|\beid\b|id[ _-]?card/', $l) ? (str_contains($l, 'back') ? 'eid_back' : 'eid_front') : (str_contains($l, 'insur') ? 'insurance_card' : (preg_match('/report|lab|result|scan|x-?ray/', $l) ? 'report' : 'other')));
        if ($commit) {
            $id = savePatientDocumentBytes($db, $pid, $type, (string)file_get_contents($f->getPathname()), $name);
            importKeyRemember($db, 'document', $key, $id, null);
        }
        $n['added']++; $report[] = [$name, $commit ? 'added' : 'would add', "patient file {$m[1]}, as $type"];
    } catch (Throwable $e) { $n['error']++; $report[] = [$name, 'error', $e->getMessage()]; }
}
$h = fopen("$dir/import-documents-report.csv", 'w'); fwrite($h, "\xEF\xBB\xBF"); fputcsv($h, ['File', 'Result', 'Details']); foreach ($report as $r) fputcsv($h, $r); fclose($h);
printf("%s: %d %s, %d skipped, %d need fixing (see import-documents-report.csv in the folder).\n", $commit ? 'Imported' : 'Checked (nothing saved)', $n['added'], $commit ? 'added' : 'to add', $n['skipped'], $n['error']);
exit($n['error'] ? 3 : 0);
