<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Phase 14 through the real pages: a staff member switches to Arabic (top-bar button) and every daily screen comes back
// right to left with Bootstrap's RTL stylesheet and Arabic text; the choice is remembered on their record and in the
// browser (the sign-in page follows it); another person stays in English; switching back restores English exactly.
require ROOT . '/includes/auth.php';
$db = getDB();
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? "  ok   " : "  FAIL "), $m, PHP_EOL; };
$BASE = 'http://127.0.0.1:8080';
$UA = 'ClinicCRM-i18n-suite/1.0';
$http = function (string $jar, string $method, string $path, array $form = []) use ($BASE, $UA) {
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_USERAGENT => $UA]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
    return [$code, $m[1] ?? '', substr($raw, $hs)];
};
$acc = file(ROOT . '/local/test_accounts.txt', FILE_IGNORE_NEW_LINES);
$pw = trim(preg_replace('/^Password:\s*/i', '', $acc[2]));
$tok = [];
$login = function (string $email, ?string $jar = null) use ($http, $pw, &$tok) {
    $jar ??= tempnam(sys_get_temp_dir(), 'ln');
    [, , $b] = $http($jar, 'GET', '/login.php'); preg_match('/name="_csrf" value="([a-f0-9]+)"/', $b, $m);
    $http($jar, 'POST', '/login.php', ['_csrf' => $m[1] ?? '', 'step' => 'password', 'email' => $email, 'password' => $pw]);
    [, , $d] = $http($jar, 'GET', '/pages/dashboard.php'); preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $d, $t);
    $tok[$jar] = $t[1] ?? '';
    return $jar;
};
$phpError = fn(string $h) => (bool)preg_match('/(Fatal error|Parse error|Uncaught |Warning: |Notice: |Deprecated: )/', $h);
$adminId = (int)$db->query("SELECT id FROM staff WHERE email = 'admin@clinic.test'")->fetchColumn();
$oldLang = $db->query("SELECT ui_language FROM staff WHERE id = $adminId")->fetchColumn();
$patient = (int)$db->query("SELECT id FROM patients WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
$invoice = (int)$db->query("SELECT id FROM invoices ORDER BY id LIMIT 1")->fetchColumn();

try {
    echo "Switching to Arabic\n";
    $admin = $login('admin@clinic.test');
    [, , $en] = $http($admin, 'GET', '/pages/dashboard.php');
    $ok(str_contains($en, 'dir="ltr"') && str_contains($en, 'bootstrap.min.css') && str_contains($en, '>ع</button>') && str_contains($en, '>Dashboard<'), 'English to start, with the ع button');
    [$c, $loc] = $http($admin, 'POST', '/pages/ui_lang.php', ['_csrf' => $tok[$admin], 'lang' => 'ar', 'back' => '/pages/patients.php']);
    $ok($c === 302 && $loc === '/pages/patients.php' && $db->query("SELECT ui_language FROM staff WHERE id = $adminId")->fetchColumn() === 'ar', 'the button saves Arabic on their record and returns to the same page');
    [, , $b] = $http($admin, 'GET', '/pages/dashboard.php');
    $ok(str_contains($b, '<html lang="ar" dir="rtl">') && str_contains($b, 'bootstrap.rtl.min.css') && str_contains($b, 'لوحة التحكم') && str_contains($b, 'المرضى') && str_contains($b, '"Filter this list…":"تصفية هذه القائمة…"'), 'right to left, Bootstrap RTL, Arabic menu, Arabic for the page scripts');
    $pages = ['/pages/dashboard.php', '/pages/leads.php', '/pages/followups.php', '/pages/reminders.php', '/pages/inbox.php', '/pages/notifications.php', '/pages/scheduler.php',
              '/pages/waiting_list.php', '/pages/visit_board.php', '/pages/patients.php', "/pages/patient.php?id=$patient", "/pages/patient_edit.php?id=$patient", '/pages/notes.php',
              '/pages/invoices.php', '/pages/receipts.php', "/pages/receipt_new.php?patient=$patient", '/pages/day_close.php', '/pages/account.php', '/pages/lead_add.php'];
    if ($invoice) $pages[] = "/pages/invoice.php?id=$invoice";
    $bad = [];
    foreach ($pages as $p) { [$c, , $b] = $http($admin, 'GET', $p); if ($c !== 200 || $phpError($b) || !str_contains($b, 'dir="rtl"')) $bad[] = "$p ($c)"; }
    $ok(!$bad, count($pages) . ' daily screens load in Arabic' . ($bad ? ': ' . implode(', ', $bad) : ''));
    [, , $b] = $http($admin, 'GET', '/pages/invoices.php');
    $ok(str_contains($b, '<h1 class="page-title">الفواتير</h1>') && (str_contains($b, 'لا توجد فواتير') || str_contains($b, '>المريض<')), 'the invoices page title and columns are in Arabic');
    [, , $b] = $http($admin, 'GET', "/pages/patient.php?id=$patient");
    $ok(str_contains($b, 'العودة') || str_contains($b, 'المرضى'), 'the patient page is in Arabic');
    [, , $b] = $http($admin, 'GET', '/pages/staff.php');
    $ok(str_contains($b, 'dir="rtl"') && !$phpError($b), 'screens not translated yet still work right to left (their text stays English)');

    echo "Other people, and the sign-in page\n";
    $rec = $login('reception@clinic.test');
    [, , $b] = $http($rec, 'GET', '/pages/dashboard.php');
    $ok(str_contains($b, 'dir="ltr"') && str_contains($b, '>Dashboard<'), 'another person stays in English');
    $http($admin, 'GET', '/logout.php');
    [, , $b] = $http($admin, 'GET', '/login.php');
    $ok(str_contains($b, 'dir="rtl"') && str_contains($b, 'تسجيل الدخول') && str_contains($b, 'href="/login.php?lang=en"'), 'after signing out, the sign-in page stays in Arabic in that browser');
    $fresh = tempnam(sys_get_temp_dir(), 'ln');
    [, , $b] = $http($fresh, 'GET', '/login.php');
    $ok(str_contains($b, 'dir="ltr"') && str_contains($b, '>Sign in<'), 'a new browser starts in English');
    [$c] = $http($fresh, 'GET', '/login.php?lang=ar'); [, , $b] = $http($fresh, 'GET', '/login.php');
    $ok($c === 302 && str_contains($b, 'مرحبًا بعودتك'), 'the sign-in page’s own link switches it');
    $admin = $login('admin@clinic.test', $admin);
    [, , $b] = $http($admin, 'GET', '/pages/dashboard.php');
    $ok(str_contains($b, 'dir="rtl"'), 'signing in again brings their Arabic back');

    echo "Back to English\n";
    $http($admin, 'POST', '/pages/ui_lang.php', ['_csrf' => $tok[$admin], 'lang' => 'en', 'back' => '/pages/dashboard.php']);
    [, , $b] = $http($admin, 'GET', '/pages/dashboard.php');
    $ok(str_contains($b, 'dir="ltr"') && str_contains($b, '>Dashboard<') && str_contains($b, 'bootstrap.min.css') && !str_contains($b, 'bootstrap.rtl'), 'switching back restores English');
    [$c, $loc] = $http($admin, 'POST', '/pages/ui_lang.php', ['_csrf' => $tok[$admin], 'lang' => 'ar', 'back' => 'https://evil.example/x']);
    $ok($loc === '/pages/dashboard.php', 'it never sends people to another site');
    [$c] = $http($admin, 'POST', '/pages/ui_lang.php', ['lang' => 'ar']);
    $ok($c === 403 || $c === 400 || $c === 419, 'not without the page’s token');
} finally {
    $db->prepare("UPDATE staff SET ui_language = ? WHERE id = ?")->execute([$oldLang ?: 'en', $adminId]);
    $db->exec("DELETE FROM login_log WHERE user_agent = " . $db->quote($UA));
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
