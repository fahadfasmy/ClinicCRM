<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Phase 8 through the real pages (local server 8080): the layouts list and designer load; the designer API saves a
// valid draft, refuses script and unknown blocks, blocks publishing without required elements, publishes a version;
// images upload (scripts refused); preview; a new invoice prints in the new layout while an older one still prints as
// issued; re-render adds a copy; export → import; defaults; only setup.layouts may design; CSRF.
// Everything it creates is removed at the end (the original defaults are restored).
require ROOT . '/includes/auth.php';
require ROOT . '/includes/doc_layouts.php';
$db = getDB();
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? "  ok   " : "  FAIL "), $m, PHP_EOL; };
$BASE = 'http://127.0.0.1:8080';
$http = function (string $jar, string $method, string $path, $body = null, array $headers = []) use ($BASE) {
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_USERAGENT => 'ClinicCRM-layouts-suite/1.0', CURLOPT_HTTPHEADER => $headers]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) && !array_filter($body, fn($v) => $v instanceof CURLFile) ? http_build_query($body) : $body); }
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
    return [$code, $m[1] ?? '', substr($raw, $hs)];
};
$acc = file(ROOT . '/local/test_accounts.txt', FILE_IGNORE_NEW_LINES);
$pw = trim(preg_replace('/^Password:\s*/i', '', $acc[2]));
$tok = [];
$login = function (string $email) use ($http, $pw, &$tok) {
    $jar = tempnam(sys_get_temp_dir(), 'ly');
    [, , $b] = $http($jar, 'GET', '/login.php'); preg_match('/name="_csrf" value="([a-f0-9]+)"/', $b, $m);
    $http($jar, 'POST', '/login.php', ['_csrf' => $m[1] ?? '', 'step' => 'password', 'email' => $email, 'password' => $pw]);
    [, , $d] = $http($jar, 'GET', '/pages/dashboard.php'); preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $d, $t);
    $tok[$jar] = $t[1] ?? '';
    return $jar;
};
$api = function (string $jar, string $action, array $body) use ($http, &$tok) {
    [$c, , $b] = $http($jar, 'POST', "/pages/layout_api.php?action=$action", json_encode($body), ['Content-Type: application/json', 'X-CSRF-Token: ' . $tok[$jar]]);
    return [$c, json_decode($b, true) ?? $b];
};
$phpError = fn(string $h) => (bool)preg_match('/(Fatal error|Parse error|Uncaught |Warning: |Notice: |Deprecated: )/', $h);

ensureStarterLayouts($db);
$tag = (string)random_int(1000, 9999);
$defaults = $db->query("SELECT id FROM document_layouts WHERE is_default = 1")->fetchAll(PDO::FETCH_COLUMN);
$classic = (int)$db->query("SELECT id FROM document_layouts WHERE starter = 'classic_invoice'")->fetchColumn();
$branch = (int)$db->query("SELECT id FROM branches WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$db->prepare("INSERT INTO services (name, price, vat_rate, duration_min, is_active) VALUES (?, 500, 5, 30, 1)")->execute(["Layout svc $tag"]);
$svc = (int)$db->lastInsertId();
$db->prepare("INSERT INTO patients (mrn, first_name, last_name, branch_id, status) VALUES (?, ?, 'Layout', ?, 'active')")->execute(["LAY-$tag", "Rana$tag", $branch]);
$pid = (int)$db->lastInsertId();
$made = []; $assetId = 0; $invs = [];
$issue = function () use ($db, $pid, $branch, $svc, &$invs) { $i = createInvoice($db, $pid, $branch); addInvoiceLine($db, $i, ['kind' => 'service', 'service_id' => $svc]); issueInvoice($db, $i); $invs[] = $i; return $i; };

try {
    echo "Pages\n";
    $admin = $login('admin@clinic.test');
    [$c, , $b] = $http($admin, 'GET', '/pages/layouts.php');
    $ok($c === 200 && str_contains($b, 'Classic') && str_contains($b, 'Receipt printer (80 mm)') && !$phpError($b), 'layouts list with the starter layouts');
    $post = fn(array $f) => $http($admin, 'POST', '/pages/layouts.php', ['_csrf' => $tok[$admin]] + $f);
    [, $loc] = $post(['action' => 'duplicate', 'id' => $classic]);
    $dup = preg_match('/id=(\d+)/', $loc, $m) ? (int)$m[1] : 0; $made[] = $dup;
    [$c, , $b] = $http($admin, 'GET', "/pages/layout_designer.php?id=$dup");
    $ok($dup && $c === 200 && str_contains($b, 'window.LD = ') && str_contains($b, 'sortablejs-1.15.6/Sortable.min.js') && !$phpError($b), 'the designer opens on a copy of Classic');
    [$c, , $js] = $http($admin, 'GET', '/assets/vendor/sortablejs-1.15.6/Sortable.min.js');
    $ok($c === 200 && str_contains($js, 'Sortable 1.15.6'), 'SortableJS is served from this server');

    echo "Designer API\n";
    $L = json_decode(findLayout($db, $dup)['draft_json'], true);
    $L['body'][] = ['id' => 'rx', 'blocks' => [['type' => 'notes', 'w' => 12, 'text' => ['en' => "Marker $tag <b>bold</b> <script>alert(1)</script>", 'ar' => '']]]];
    [$c, $j] = $api($admin, 'save', ['id' => $dup, 'layout' => $L, 'meta' => ['name' => "Suite $tag"]]);
    $ok($c === 200 && ($j['ok'] ?? false) && $j['problems'] === [], 'a valid draft saves with nothing missing');
    $saved = json_encode(json_decode(findLayout($db, $dup)['draft_json'], true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ok(str_contains($saved, '<b>bold</b>') && !str_contains($saved, '<script>') && findLayout($db, $dup)['name'] === "Suite $tag", 'the script tag was stored as text; the name changed');
    [$c, $j] = $api($admin, 'save', ['id' => $dup, 'layout' => array_merge($L, ['body' => [['blocks' => [['type' => 'iframe', 'w' => 6]]]]])]);
    $ok($c === 422 && str_contains($j['error'] ?? '', 'Unknown block type'), 'an unknown block is refused');
    $noTitle = $L; foreach ($noTitle['header'] as &$r) $r['blocks'] = array_values(array_filter($r['blocks'], fn($b) => $b['type'] !== 'title')); unset($r);
    [, $j] = $api($admin, 'save', ['id' => $dup, 'layout' => $noTitle]);
    $ok(str_contains(implode(' ', $j['problems'] ?? []), 'Tax Invoice'), 'saving without the title warns that it can’t be published');
    [$c, $j] = $api($admin, 'publish', ['id' => $dup]);
    $ok($c === 422 && str_contains($j['error'] ?? '', 'can’t be published') && !findLayout($db, $dup)['current_version_id'], 'publishing without “Tax Invoice” is refused');
    $api($admin, 'save', ['id' => $dup, 'layout' => $L]);
    [$c, $j] = $api($admin, 'publish', ['id' => $dup]);
    $ok($c === 200 && ($j['version'] ?? 0) === 1, 'publishes version 1');
    [$c, , $pv] = $http($admin, 'POST', '/pages/layout_api.php?action=preview', json_encode(['id' => $dup, 'layout' => $L, 'lang' => 'ar', 'lines' => 60]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $tok[$admin]]);
    $ok($c === 200 && str_contains($pv, 'dir="rtl"') && str_contains($pv, 'مسودة') && substr_count($pv, '<tr><td style="text-align:start">') >= 60, 'Arabic preview with 60 items and the DRAFT stamp');
    [$c, , $pv] = $http($admin, 'GET', "/pages/layout_preview.php?id=$dup&lang=both");
    $ok($c === 200 && str_contains($pv, 'Tax Invoice') && str_contains($pv, "Marker $tag"), 'preview page');

    echo "Images\n";
    $chunk = fn($t, $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    $f = tempnam(sys_get_temp_dir(), 'lg'); file_put_contents($f, "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 30, 12, 8, 0, 0, 0, 0)) . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\x30", 30), 12))) . $chunk('IEND', ''));
    [$c, , $b] = $http($admin, 'POST', '/pages/layout_api.php?action=upload', ['_csrf' => $tok[$admin], 'file' => new CURLFile($f, 'image/png', 'logo.png')]);
    $assetId = (int)(json_decode($b, true)['id'] ?? 0);
    $ok($c === 200 && $assetId > 0, 'a PNG logo uploads');
    $bad = tempnam(sys_get_temp_dir(), 'lg'); file_put_contents($bad, '<?php echo 1;');
    [$c, , $b] = $http($admin, 'POST', '/pages/layout_api.php?action=upload', ['_csrf' => $tok[$admin], 'file' => new CURLFile($bad, 'image/png', 'evil.png')]);
    $ok($c === 422 && str_contains($b, 'PNG or JPG'), 'a script named .png is refused');
    [$c, , $img] = $http($admin, 'GET', "/pages/layout_api.php?action=asset&id=$assetId");
    $ok($c === 200 && str_starts_with($img, "\x89PNG"), 'the designer can show the uploaded image');

    echo "Printing with layouts\n";
    $old = $issue();   // printed with the current default (Classic)
    $oldHtml = documentRendition($db, 'invoice', $old)['html'];
    $post(['action' => 'default', 'id' => $dup]);
    $ok((int)findLayout($db, $dup)['is_default'] === 1 && !(int)findLayout($db, $classic)['is_default'], 'the copy is now the default');
    $new = $issue();
    [$c, , $p1] = $http($admin, 'GET', "/pages/doc_print.php?type=invoice&id=$new");
    $ok($c === 200 && str_contains($p1, "Marker $tag"), 'a new invoice prints with the new layout');
    [$c, , $p0] = $http($admin, 'GET', "/pages/doc_print.php?type=invoice&id=$old");
    $ok(!str_contains($p0, "Marker $tag") && documentRendition($db, 'invoice', $old)['html'] === $oldHtml, 'the older invoice still prints exactly as issued');
    [, $loc] = $http($admin, 'POST', "/pages/doc_print.php?type=invoice&id=$old", ['_csrf' => $tok[$admin], 'version_id' => findLayout($db, $dup)['current_version_id']]);
    [, , $p2] = $http($admin, 'GET', $loc);
    $ok(str_contains($loc, 'rendition=') && str_contains($p2, "Marker $tag") && documentRendition($db, 'invoice', $old)['html'] === $oldHtml, 're-render adds a copy in the new layout; the original stays the default print');
    $ok((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE entity = 'invoice' AND entity_id = $old AND action = 'rerendered'")->fetchColumn() === 1, 'and it is audit-logged');
    [, $loc] = $post(['action' => 'archive', 'id' => $dup]);
    $ok((int)findLayout($db, $dup)['is_default'] === 1 && findLayout($db, $dup)['status'] === 'published', 'the default layout can’t be archived');

    echo "Export and import\n";
    [$c, , $exp] = $http($admin, 'GET', "/pages/layouts.php?export=$dup");
    $ej = json_decode($exp, true);
    $ok($c === 200 && ($ej['document_type'] ?? '') === 'invoice' && isset($ej['layout']['header']), 'export gives the layout as JSON');
    $ej['layout']['body'][] = ['blocks' => [['type' => 'text', 'w' => 6, 'text' => ['en' => '{{patient.secret}}']]]];
    $tf = tempnam(sys_get_temp_dir(), 'lj'); file_put_contents($tf, json_encode($ej));
    $before = (int)$db->query("SELECT COUNT(*) FROM document_layouts")->fetchColumn();
    [, , $b] = $http($admin, 'POST', '/pages/layouts.php', ['_csrf' => $tok[$admin], 'action' => 'import', 'file' => new CURLFile($tf, 'application/json', 'l.json')]);
    [, , $b] = $http($admin, 'GET', '/pages/layouts.php');
    $ok((int)$db->query("SELECT COUNT(*) FROM document_layouts")->fetchColumn() === $before && str_contains($b, '{{patient.secret}}'), 'an import with an unknown field is refused, saying which');
    array_pop($ej['layout']['body']); file_put_contents($tf, json_encode($ej));
    [, $loc] = $http($admin, 'POST', '/pages/layouts.php', ['_csrf' => $tok[$admin], 'action' => 'import', 'file' => new CURLFile($tf, 'application/json', 'l.json')]);
    $imp = preg_match('/id=(\d+)/', $loc, $m) ? (int)$m[1] : 0; $made[] = $imp;
    $ok($imp && str_starts_with(findLayout($db, $imp)['name'], 'Imported:') && findLayout($db, $imp)['status'] === 'draft', 'a valid import becomes a draft layout');

    echo "Who may design\n";
    foreach (['finance@clinic.test' => 'finance', 'reception@clinic.test' => 'receptionist'] as $email => $role) {
        $j = $login($email);
        [$c1] = $http($j, 'GET', '/pages/layouts.php');
        [$c2] = $http($j, 'GET', "/pages/layout_designer.php?id=$dup");
        [$c3] = $api($j, 'save', ['id' => $dup, 'layout' => $L]);
        $ok($c1 === 403 && $c2 === 403 && $c3 === 403, "$role can’t open or change layouts");
    }
    [$c] = $http($admin, 'POST', '/pages/layout_api.php?action=save', json_encode(['id' => $dup, 'layout' => $L]), ['Content-Type: application/json']);
    $ok($c === 419, 'a save without the CSRF token is refused');
    [$c, , $b] = $http($admin, 'GET', "/pages/layout_versions.php?id=$dup");
    $ok($c === 200 && str_contains($b, 'Version 1') && str_contains($b, '1 document printed') === false && !$phpError($b), 'versions page');
} finally {
    if ($invs) { $in = implode(',', $invs);
        $db->exec("DELETE FROM document_renditions WHERE doc_type = 'invoice' AND doc_id IN ($in)");
        $db->exec("DELETE FROM invoice_lines WHERE invoice_id IN ($in)");
        $db->exec("DELETE FROM audit_log WHERE entity = 'invoice' AND entity_id IN ($in)");
        $db->exec("DELETE FROM invoices WHERE id IN ($in)"); }
    $db->exec("UPDATE document_layouts SET is_default = 0");
    if ($defaults) $db->exec("UPDATE document_layouts SET is_default = 1 WHERE id IN (" . implode(',', $defaults) . ")");
    foreach (array_filter($made) as $lid) {
        $db->exec("DELETE FROM document_layout_versions WHERE layout_id = $lid");
        $db->exec("DELETE FROM audit_log WHERE entity = 'layout' AND entity_id = $lid");
        $db->exec("DELETE FROM document_layouts WHERE id = $lid");
    }
    if ($assetId) { $a = $db->query("SELECT path FROM document_layout_assets WHERE id = $assetId")->fetchColumn(); if ($a && is_file(tenantStoragePath() . '/' . $a)) unlink(tenantStoragePath() . '/' . $a);
        $db->exec("DELETE FROM document_layout_assets WHERE id = $assetId"); $db->exec("DELETE FROM audit_log WHERE entity = 'layout_asset' AND entity_id = $assetId"); }
    foreach (['patient_access_log', 'patient_wallet'] as $t) $db->exec("DELETE FROM $t WHERE patient_id = $pid");
    $db->exec("DELETE FROM audit_log WHERE entity = 'patient' AND entity_id = $pid");
    $db->exec("DELETE FROM patients WHERE id = $pid");
    $db->exec("DELETE FROM services WHERE id = $svc");
    $db->exec("DELETE FROM login_log WHERE user_agent = 'ClinicCRM-layouts-suite/1.0'");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
