<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Phase 16 through the real pages: Admin → Import from ClinicSoft (upload, recognised columns, a check that saves
// nothing, the rows-to-check CSV, the import, a second run adding nothing), opening balances shown and collectable but
// never printed as tax invoices, who may import, the help pages and the public status page. Everything it adds is removed.
require ROOT . '/includes/auth.php';
$db = getDB();
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? "  ok   " : "  FAIL "), $m, PHP_EOL; };
$BASE = 'http://127.0.0.1:8080';
$UA = 'ClinicCRM-import-suite/1.0';
$http = function (string $jar, string $method, string $path, $form = []) use ($BASE, $UA) {
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_USERAGENT => $UA, CURLOPT_TIMEOUT => 120]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $form); }
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
    return [$code, $m[1] ?? '', substr($raw, $hs), substr($raw, 0, $hs)];
};
$acc = file(ROOT . '/local/test_accounts.txt', FILE_IGNORE_NEW_LINES);
$pw = trim(preg_replace('/^Password:\s*/i', '', $acc[2]));
$tok = [];
$login = function (string $email) use ($http, $pw, &$tok) {
    $jar = tempnam(sys_get_temp_dir(), 'is');
    [, , $b] = $http($jar, 'GET', '/login.php'); preg_match('/name="_csrf" value="([a-f0-9]+)"/', $b, $m);
    $http($jar, 'POST', '/login.php', ['_csrf' => $m[1] ?? '', 'step' => 'password', 'email' => $email, 'password' => $pw]);
    [, , $d] = $http($jar, 'GET', '/pages/dashboard.php'); preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $d, $t);
    $tok[$jar] = $t[1] ?? '';
    return $jar;
};
$phpError = fn(string $h) => (bool)preg_match('/(Fatal error|Parse error|Uncaught |Warning: |Notice: |Deprecated: )/', $h);
$tag = (string)random_int(10000, 99999);
$csvPath = tempnam(sys_get_temp_dir(), 'imp') . '.csv';
file_put_contents($csvPath, "\xEF\xBB\xBFFile No;Patient Name;Gender;DOB;Nationality;Mobile\n{$tag}1;Huda Import$tag;F;01/02/1990;Emirati;050" . substr($tag, 0, 4) . "111\n{$tag}2;Rami Import$tag;M;;Jordanian;\n{$tag}3;;M;;;\n");
$balPath = tempnam(sys_get_temp_dir(), 'imp') . '.csv';
file_put_contents($balPath, "File No,Balance Due,Advance\n{$tag}1,123.45,\n{$tag}2,,50\n");
$runIds = [];

try {
    echo "Who may import\n";
    $rec = $login('reception@clinic.test');
    [$c] = $http($rec, 'GET', '/pages/import.php');
    $ok($c === 403, 'reception can’t import');
    $admin = $login('admin@clinic.test');
    [$c, , $b] = $http($admin, 'GET', '/pages/import.php');
    $ok($c === 200 && !$phpError($b) && str_contains($b, 'Patients') && str_contains($b, 'Past invoices') && str_contains($b, 'Clinical notes'), 'the admin sees the import screen with every kind');
    [$c, , $b, $h] = $http($admin, 'GET', '/pages/import.php?template=balances');
    $ok(str_contains($h, 'text/csv') && str_contains($b, 'Amount owed (AED)'), 'a list of the columns we read, per kind');

    echo "Patients: upload, check, import, run again\n";
    $before = (int)$db->query("SELECT COUNT(*) FROM patients")->fetchColumn();
    [$c, $loc] = $http($admin, 'POST', '/pages/import.php', ['_csrf' => $tok[$admin], 'action' => 'upload', 'kind' => 'patients', 'file' => new CURLFile($csvPath, 'text/csv', 'patients.csv')]);
    [, , $b] = $http($admin, 'GET', '/pages/import.php?step=map');
    $ok($loc === '/pages/import.php?step=map' && preg_match('/name="map\[legacy_id\]".*?<option value="0" selected>File No/s', $b) && preg_match('/name="map\[full_name\]".*?<option value="1" selected>Patient Name/s', $b) && str_contains($b, "Huda Import$tag"),
        'the columns are recognised and the first rows are shown');
    $map = ['legacy_id' => 0, 'full_name' => 1, 'gender' => 2, 'dob' => 3, 'nationality' => 4, 'mobile' => 5];
    $form = ['_csrf' => $tok[$admin], 'action' => 'dry_run']; foreach ($map as $k => $v) $form["map[$k]"] = $v;
    [$c, $loc] = $http($admin, 'POST', '/pages/import.php', $form);
    preg_match('/run=(\d+)/', $loc, $m); $runIds[] = $run = (int)($m[1] ?? 0);
    [, , $b] = $http($admin, 'GET', "/pages/import.php?run=$run");
    $ok($run && str_contains($b, 'nothing was saved') && str_contains($b, 'Import the 2 rows that are fine') && (int)$db->query("SELECT COUNT(*) FROM patients")->fetchColumn() === $before, 'the check: 2 fine, 1 to fix, nothing saved');
    [, , $csv] = $http($admin, 'GET', "/pages/import.php?run=$run&csv=1");
    $ok(str_contains($csv, 'No name') && str_contains($csv, 'Line in the file'), 'the rows to check as a CSV');
    [$c, $loc] = $http($admin, 'POST', '/pages/import.php', ['_csrf' => $tok[$admin], 'action' => 'commit']);
    preg_match('/run=(\d+)/', $loc, $m); $runIds[] = (int)($m[1] ?? 0);
    $ok((int)$db->query("SELECT COUNT(*) FROM patients WHERE legacy_id IN ('{$tag}1','{$tag}2')")->fetchColumn() === 2 && $db->query("SELECT nationality FROM patients WHERE legacy_id = '{$tag}2'")->fetchColumn() === 'JO', 'imported: two patients with their file numbers');
    $http($admin, 'POST', '/pages/import.php', ['_csrf' => $tok[$admin], 'action' => 'upload', 'kind' => 'patients', 'file' => new CURLFile($csvPath, 'text/csv', 'patients.csv')]);
    [, $loc] = $http($admin, 'POST', '/pages/import.php', $form);
    preg_match('/run=(\d+)/', $loc, $m); $runIds[] = (int)($m[1] ?? 0);
    $ok((int)$db->query("SELECT rows_skipped FROM import_runs WHERE id = " . (int)($m[1] ?? 0))->fetchColumn() === 2, 'the same file again: both already in');
    $http($admin, 'POST', '/pages/import.php', ['_csrf' => $tok[$admin], 'action' => 'cancel']);
    [$c] = $http($admin, 'POST', '/pages/import.php', ['_csrf' => $tok[$admin], 'action' => 'commit']);
    $ok((int)$db->query("SELECT COUNT(*) FROM patients WHERE legacy_id LIKE '{$tag}%'")->fetchColumn() === 2, 'importing without a check first is refused');

    echo "Balances\n";
    $http($admin, 'POST', '/pages/import.php', ['_csrf' => $tok[$admin], 'action' => 'upload', 'kind' => 'balances', 'file' => new CURLFile($balPath, 'text/csv', 'balances.csv')]);
    [, $loc] = $http($admin, 'POST', '/pages/import.php', ['_csrf' => $tok[$admin], 'action' => 'dry_run', 'map[legacy_id]' => 0, 'map[due]' => 1, 'map[advance]' => 2]);
    preg_match('/run=(\d+)/', $loc, $m); $runIds[] = (int)($m[1] ?? 0);
    [, $loc] = $http($admin, 'POST', '/pages/import.php', ['_csrf' => $tok[$admin], 'action' => 'commit']);
    preg_match('/run=(\d+)/', $loc, $m); $runIds[] = (int)($m[1] ?? 0);
    $inv = $db->query("SELECT * FROM invoices WHERE number = 'OB-{$tag}1'")->fetch();
    $ok($inv && $inv['imported'] === 'opening_balance' && $inv['total'] == 123.45, 'the balance owed is an opening balance');
    [$c, , $b] = $http($admin, 'GET', '/pages/invoice.php?id=' . (int)$inv['id']);
    $ok($c === 200 && str_contains($b, 'Balance carried over from ClinicSoft') && str_contains($b, 'Take payment') && !str_contains($b, 'doc_print.php?type=invoice'), 'its page offers Take payment and no tax-invoice print');
    [$c, , $b] = $http($admin, 'GET', '/pages/doc_print.php?type=invoice&id=' . (int)$inv['id']);
    $ok($c === 409 && str_contains($b, 'not a tax invoice'), 'printing it as a tax invoice is refused');

    echo "Help and status\n";
    [$c, , $b] = $http($rec, 'GET', '/pages/help.php');
    [$c2, , $b2] = $http($rec, 'GET', '/pages/help.php?t=billing');
    $ok($c === 200 && $c2 === 200 && !$phpError($b . $b2) && str_contains($b2, 'Day close') && !str_contains($b, 'Go-live checklist'), 'help topics for everyone; the go-live checklist only for admins');
    [, , $b] = $http($admin, 'GET', '/pages/help.php?t=golive');
    $ok(str_contains($b, 'Import from ClinicSoft in this order'), 'admins have the go-live checklist');
    [$c, , $j] = $http(tempnam(sys_get_temp_dir(), 'is'), 'GET', '/status.php?format=json');
    $s = json_decode($j, true);
    $ok(in_array($c, [200, 503], true) && isset($s['status'], $s['checks']['database'], $s['checks']['scheduled_jobs']) && !str_contains($j, 'patient'), 'the public status page (no sign-in, nothing about patients)');
} finally {
    $pids = $db->query("SELECT id FROM patients WHERE legacy_id LIKE '{$tag}%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($pids) {
        $in = implode(',', $pids);
        $inv = $db->query("SELECT id FROM invoices WHERE patient_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        if ($inv) { $i = implode(',', $inv); $db->exec("DELETE FROM invoice_lines WHERE invoice_id IN ($i)"); $db->exec("DELETE FROM invoices WHERE id IN ($i)"); }
        foreach (['patient_wallet', 'patient_phones', 'patient_access_log'] as $t) $db->exec("DELETE FROM $t WHERE patient_id IN ($in)");
        $db->exec("DELETE FROM audit_log WHERE entity = 'patient' AND entity_id IN ($in)");
        $db->exec("DELETE FROM patients WHERE id IN ($in)");
    }
    $db->exec("DELETE FROM import_keys WHERE legacy_key LIKE '{$tag}%'");
    $runs = array_filter($runIds);
    $db->exec("DELETE FROM audit_log WHERE entity = 'import' AND entity_id IN (" . (implode(',', $runs) ?: '0') . ")");
    $db->exec("DELETE FROM import_runs WHERE file_hash IN ('" . hash_file('sha256', $csvPath) . "', '" . hash_file('sha256', $balPath) . "')");
    @unlink($csvPath); @unlink($balPath);
    $db->exec("DELETE FROM login_log WHERE user_agent = " . $db->quote($UA));
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
