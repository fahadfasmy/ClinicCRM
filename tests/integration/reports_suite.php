<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Phase 12 through the real pages: every report tab loads for the people allowed (no PHP errors), CSV exports and the
// print view work, finance adds a supplier invoice to the purchase VAT register and the VAT return picks it up, a
// practitioner sees only their own clinical figures, and the rest are refused. The entry it adds is removed at the end.
require ROOT . '/includes/auth.php';
$db = getDB();
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? "  ok   " : "  FAIL "), $m, PHP_EOL; };
$BASE = 'http://127.0.0.1:8080';
$UA = 'ClinicCRM-reports-suite/1.0';
$http = function (string $jar, string $method, string $path, array $form = []) use ($BASE, $UA) {
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_USERAGENT => $UA]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return [$code, substr($raw, 0, $hs), substr($raw, $hs)];
};
$acc = file(ROOT . '/local/test_accounts.txt', FILE_IGNORE_NEW_LINES);
$pw = trim(preg_replace('/^Password:\s*/i', '', $acc[2]));
$tok = [];
$login = function (string $email) use ($http, $pw, &$tok) {
    $jar = tempnam(sys_get_temp_dir(), 'rs');
    [, , $b] = $http($jar, 'GET', '/login.php'); preg_match('/name="_csrf" value="([a-f0-9]+)"/', $b, $m);
    $http($jar, 'POST', '/login.php', ['_csrf' => $m[1] ?? '', 'step' => 'password', 'email' => $email, 'password' => $pw]);
    [, , $d] = $http($jar, 'GET', '/pages/dashboard.php'); preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $d, $t);
    $tok[$jar] = $t[1] ?? '';
    return $jar;
};
$phpError = fn(string $h) => (bool)preg_match('/(Fatal error|Parse error|Uncaught |Warning: |Notice: |Deprecated: )/', $h);
$ref = 'SUITE-' . random_int(10000, 99999);
$y = date('Y');

try {
    echo "Every report loads\n";
    $admin = $login('admin@clinic.test');
    $pages = ['/pages/overview.php', '/pages/overview.php?from=' . $y . '-01-01&to=' . date('Y-m-d')];
    foreach (['collection', 'dayclose', 'vat', 'register', 'receivables', 'credit', 'liability'] as $r) $pages[] = "/pages/reports_finance.php?r=$r&from=$y-01-01";
    foreach (['diagnoses', 'procedures', 'prescriptions', 'unsigned', 'consents', 'not_visited'] as $r) $pages[] = "/pages/reports_clinical.php?r=$r&from=$y-01-01";
    foreach (['visits', 'diagnosis', 'waiting', 'department', 'residency'] as $r) $pages[] = "/pages/reports_dha.php?r=$r&from=$y-01-01";
    $bad = [];
    foreach ($pages as $p) { [$c, , $b] = $http($admin, 'GET', $p); if ($c !== 200 || $phpError($b) || !str_contains($b, 'card-crm')) $bad[] = "$p ($c)"; }
    $ok(!$bad, count($pages) . ' report pages load for the admin' . ($bad ? ': ' . implode(', ', $bad) : ''));
    [, , $b] = $http($admin, 'GET', '/pages/dashboard.php');
    $ok(str_contains($b, '/pages/overview.php') && str_contains($b, '/pages/reports_dha.php'), 'the Insights menu lists the new reports');

    echo "Export and print\n";
    [$c, $h, $b] = $http($admin, 'GET', "/pages/reports_finance.php?r=register&from=$y-01-01&export=csv");
    $ok($c === 200 && str_contains($h, 'text/csv') && str_starts_with($b, "\xEF\xBB\xBF") && str_contains($b, 'Invoice register'), 'CSV export (UTF-8 for Excel)');
    [$c, $h, $b] = $http($admin, 'GET', "/pages/overview.php?export=csv&s=1");
    $ok($c === 200 && str_contains($b, 'Collections by payment method') && !str_contains($b, 'Invoiced and collected'), 'one table on its own');
    [$c, , $b] = $http($admin, 'GET', "/pages/reports_dha.php?r=visits&print=1");
    $ok($c === 200 && str_contains($b, 'print()') && str_contains($b, 'By nationality') && !str_contains($b, 'sidebar') && !$phpError($b), 'the print view');

    echo "Purchase VAT register\n";
    $fin = $login('finance@clinic.test');
    $day = date('Y-m-d');
    [, , $before] = $http($fin, 'GET', "/pages/reports_finance.php?r=vat&from=$day&to=$day");
    $http($fin, 'POST', '/pages/reports_finance.php', ['_csrf' => $tok[$fin], 'action' => 'purchase_vat', 'invoice_date' => $day, 'supplier' => 'Suite Supplier', 'supplier_trn' => '100000000000003',
        'invoice_ref' => $ref, 'net' => '1234.00', 'vat' => '61.70', 'from' => $day, 'to' => $day]);
    $ok((float)$db->query("SELECT vat FROM purchase_vat WHERE invoice_ref = " . $db->quote($ref))->fetchColumn() === 61.7, 'finance adds a supplier tax invoice');
    [, , $b] = $http($fin, 'GET', "/pages/reports_finance.php?r=vat&from=$day&to=$day");
    $ok(str_contains($b, $ref) && str_contains($b, '61.70') && !$phpError($b), 'the VAT return shows it as input VAT');
    $http($fin, 'POST', '/pages/reports_finance.php', ['_csrf' => $tok[$fin], 'action' => 'purchase_vat', 'invoice_date' => $day, 'supplier' => 'Suite Supplier', 'invoice_ref' => $ref, 'net' => '1', 'vat' => '0.05', 'from' => $day, 'to' => $day]);
    [, , $b] = $http($fin, 'GET', "/pages/reports_finance.php?r=vat&from=$day&to=$day");
    $ok(str_contains($b, 'already in the register') && (int)$db->query("SELECT COUNT(*) FROM purchase_vat WHERE invoice_ref = " . $db->quote($ref))->fetchColumn() === 1, 'the same invoice twice is refused');
    $http($fin, 'POST', '/pages/reports_finance.php', ['action' => 'purchase_vat', 'invoice_date' => $day, 'supplier' => 'No token', 'invoice_ref' => "$ref-x", 'net' => '1', 'vat' => '0']);
    $ok(!$db->query("SELECT COUNT(*) FROM purchase_vat WHERE invoice_ref = " . $db->quote("$ref-x"))->fetchColumn(), 'not without the form’s token');
    [$c] = $http($fin, 'GET', '/pages/overview.php');
    $ok($c === 200, 'finance has the clinic overview');

    echo "Who sees what\n";
    [$c1] = $http($fin, 'GET', '/pages/reports_dha.php'); [$c2] = $http($fin, 'GET', '/pages/reports_clinical.php');
    $ok($c1 === 403 && $c2 === 403, 'finance can’t open DHA or clinical reports');
    $doc = $login('doctor@clinic.test');
    [$c, , $b] = $http($doc, 'GET', "/pages/reports_clinical.php?r=procedures&prac=0&from=$y-01-01");
    $ok($c === 200 && str_contains($b, 'Your patients only') && !str_contains($b, 'name="prac"') && !$phpError($b), 'a practitioner sees only their own clinical figures (no one else to pick)');
    [$c1] = $http($doc, 'GET', '/pages/reports_finance.php'); [$c2] = $http($doc, 'GET', '/pages/overview.php');
    $ok($c1 === 403 && $c2 === 403, 'a practitioner can’t open finance or the overview');
    $rec = $login('reception@clinic.test');
    $codes = array_map(fn($p) => $http($rec, 'GET', $p)[0], ['/pages/overview.php', '/pages/reports_finance.php', '/pages/reports_clinical.php', '/pages/reports_dha.php', '/pages/reports_finance.php?export=csv']);
    $ok($codes === [403, 403, 403, 403, 403], 'reception can open none of them (exports included)');
} finally {
    $ids = $db->query("SELECT id FROM purchase_vat WHERE invoice_ref LIKE " . $db->quote("$ref%"))->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) { $in = implode(',', $ids); $db->exec("DELETE FROM audit_log WHERE entity = 'purchase_vat' AND entity_id IN ($in)"); $db->exec("DELETE FROM purchase_vat WHERE id IN ($in)"); }
    $db->exec("DELETE FROM login_log WHERE user_agent = " . $db->quote($UA));
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
