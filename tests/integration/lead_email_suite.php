<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Call-back email checks: timing rules, email content, queue/dedupe/retry/expiry, against local/smtp_mock.php on 2525.
require ROOT . '/includes/lead_email.php';
$db = getDB();
ensureMetaSchema($db); ensureLeadEmailSchema($db);
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? "  ok   " : "  FAIL "), $m, PHP_EOL; };
$out = ROOT . '/local/smtp_mock_out';
array_map('unlink', glob("$out/*.eml") ?: []);
$settingsBefore = $db->query("SELECT k, v FROM integration_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$maxMail = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM lead_emails")->fetchColumn();
ensureMailSchema($db);
$maxLog = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM email_log")->fetchColumn();

echo "When we'll call (Dubai time)\n";
$at = fn($s) => callbackPromise(new DateTimeImmutable($s, new DateTimeZone('Asia/Dubai')));
$ok(str_contains($at('2026-10-13 00:30')['line'], 'today, from 11:00 AM'), '00:30 → today from 11 AM');
$ok(str_contains($at('2026-10-13 10:59')['line'], 'today, from 11:00 AM'), '10:59 → today from 11 AM');
$ok(str_contains($at('2026-10-13 11:00')['line'], 'shortly today'), '11:00 → shortly');
$ok(str_contains($at('2026-10-13 20:59')['line'], 'shortly today'), '20:59 → shortly');
$ok(str_contains($at('2026-10-13 21:00')['line'], 'tomorrow, Wednesday 14 October, from 11:00 AM'), '21:00 → tomorrow, Wednesday 14 October');
$ok(str_contains($at('2026-10-31 23:59')['line'], 'tomorrow, Sunday 1 November'), '31 Oct 23:59 → tomorrow, Sunday 1 November (month rolls over)');
$utc = callbackPromise(new DateTimeImmutable('2026-10-13 17:30', new DateTimeZone('UTC')));   // 21:30 in Dubai
$ok(str_contains($utc['line'], 'tomorrow, Wednesday 14 October'), 'server clock in UTC still uses Dubai time (17:30 UTC = 21:30 Dubai)');

echo "Names\n";
$ok(leadEmailFirstName('aisha khan') === 'Aisha', 'first name, capitalised');
$ok(leadEmailFirstName('سارة أحمد') === 'سارة', 'Arabic first name kept');
$ok(leadEmailFirstName('Meta lead +971501234567') === null && leadEmailFirstName('+971 50 123') === null && leadEmailFirstName('') === null, 'placeholder names → "Hello,"');

echo "Content\n";
$now = new DateTimeImmutable('2026-10-13 15:00', new DateTimeZone('Asia/Dubai'));
[$sub, $html, $text] = buildLeadEmail(['name' => 'Aisha Khan', 'phone' => '+971 50 123 4567'], ['HydraFacial'], false, $now);
$ok($sub === "Thank you, Aisha – we'll call you shortly | Demo Aesthetic Clinic", "subject: $sub");
foreach (['Dear Aisha,', 'enquiry about <strong>HydraFacial</strong> at the first clinic Aesthetic Clinic', 'shortly today', 'ending in <strong>4567</strong>',
          'Our call will come from <strong>+971 4 000 0000</strong>', 'between 11:00 AM and 9:30 PM', 'questions about HydraFacial',
          'WhatsApp at +971 50 000 0000', 'The Patient Care Team', 'Open daily', 'If this wasn’t you'] as $want) $ok(str_contains($html, $want), "html has: $want");
$ok(!str_contains($html, '12:00 PM'), 'no opening-hours times in the footer');
$ok(str_contains($text, "Dear Aisha,") && str_contains($text, 'shortly today, on your number ending in 4567.') && !str_contains($text, '<'), 'plain-text version reads cleanly');
[, $h2] = buildLeadEmail(['name' => '<script>x</script> Bob', 'phone' => ''], ['Botox', 'Lip Filler', 'HydraFacial'], true, $now);
$ok(!str_contains($h2, '<script>') && str_contains($h2, '&lt;script&gt;'), 'name is escaped');
$ok(str_contains($h2, 'Botox, Lip Filler and HydraFacial') && str_contains($h2, 'getting in touch with Demo Aesthetic Clinic again'), 'several treatments + returning wording');
$ok(!str_contains($h2, 'ending in'), 'no phone → no "ending in"');
[$s3, $h3] = buildLeadEmail(['name' => 'Meta lead x@y.com', 'phone' => '0501234567'], [], false, $now);
$ok(str_contains($h3, 'Hello,') && str_contains($h3, 'questions about our treatments') && str_starts_with($s3, 'Thank you – '), 'no name, no treatment');

echo "Sending\n";
$db->exec("DELETE FROM integration_settings WHERE k LIKE 'mail.%'");   // never use a real saved mailbox in tests
mailSetting($db, '__reload');
metaSaveSetting($db, 'mail_enabled', '1');
try { sendEmail($db, 'x@example.test', 'S', '<p>x</p>'); $ok(false, 'refuses to send before setup'); }
catch (RuntimeException $e) { $ok(str_contains($e->getMessage(), 'isn’t set up yet'), 'not set up → clear error: ' . $e->getMessage()); }
foreach (['host' => '127.0.0.1', 'port' => '2525', 'security' => 'none', 'password' => 'good-pass'] as $k => $v) mailSaveSetting($db, $k, $v);
$mk = fn($id, $name, $phone, $email, $ago) => metaImportLead($db, ['id' => $id, 'created_time' => gmdate('Y-m-d\TH:i:sO', time() - $ago),
    'field_data' => array_values(array_filter([['name' => 'full_name', 'values' => [$name]], ['name' => 'phone_number', 'values' => [$phone]], $email ? ['name' => 'email', 'values' => [$email]] : null]))]);
$a = $mk('EM1', 'Aisha Test', '+971500066001', 'aisha@example.test', 300);
$ok((int)$db->query("SELECT COUNT(*) FROM lead_emails WHERE lead_id = {$a['lead_id']} AND status = 'queued' AND kind = 'welcome'")->fetchColumn() === 1, 'fresh lead with email → queued');
$r = sendQueuedLeadEmails($db);
$files = glob("$out/*.eml");
$ok($r['sent'] === 1 && count($files) === 1, 'sent through the mail server: ' . json_encode($r));
$raw = file_get_contents($files[0]);
$ok(str_contains($raw, 'X-Mock-To: RCPT TO:<aisha@example.test>') && str_contains($raw, 'MAIL FROM:<info@example.ae>'), 'from info@, to the lead');
preg_match('/^Subject: (.+)$/m', $raw, $m);
$ok(str_starts_with(iconv_mime_decode(trim($m[1]), 0, 'UTF-8'), 'Thank you, Aisha – '), 'subject header decodes: ' . iconv_mime_decode(trim($m[1]), 0, 'UTF-8'));
$ok(str_contains($raw, 'Reply-To: <info@example.ae>') && str_contains($raw, 'multipart/alternative'), 'reply-to and html+text parts');
preg_match_all('/Content-Transfer-Encoding: base64\r\n\r\n(.+?)\r\n--/s', $raw, $parts);
$ok(count($parts[1]) === 2 && str_contains(base64_decode($parts[1][1]), 'Dear Aisha,'), 'html part decodes to the email');
$notes = $db->query("SELECT notes FROM leads WHERE id = {$a['lead_id']}")->fetchColumn();
$ok(str_contains($notes, '✉ Call-back email sent to aisha@example.test'), 'recorded on the lead notes');
$ok((int)$db->query("SELECT COUNT(*) FROM activity_log WHERE lead_id = {$a['lead_id']} AND action = 'email_sent'")->fetchColumn() === 1, 'activity logged');
$lg = $db->query("SELECT * FROM email_log WHERE id > $maxLog AND lead_id = {$a['lead_id']}")->fetch();
$ok($lg && $lg['status'] === 'sent' && $lg['context'] === 'Call-back email' && str_starts_with($lg['subject'], 'Thank you, Aisha'), 'listed in the email log with its lead');
$ok(sendQueuedLeadEmails($db)['sent'] === 0 && count(glob("$out/*.eml")) === 1, 'not sent twice');
$mk('EM1', 'Aisha Test', '+971500066001', 'aisha@example.test', 300);
$ok((int)$db->query("SELECT COUNT(*) FROM lead_emails WHERE lead_id = {$a['lead_id']}")->fetchColumn() === 1, 'same Meta lead again (webhook + hourly check) → no second email');

$old = $mk('EM2', 'Old Lead', '+971500066002', 'old@example.test', 3 * 86400);
$none = $mk('EM3', 'No Email', '+971500066003', '', 60);
$bad = $mk('EM4', 'Bad Email', '+971500066004', 'not-an-email', 60);
$ok((int)$db->query("SELECT COUNT(*) FROM lead_emails WHERE lead_id IN ({$old['lead_id']},{$none['lead_id']},{$bad['lead_id']})")->fetchColumn() === 0, 'old lead / no email / invalid email → nothing queued');

$ret = $mk('EM5', 'Aisha Again', '+971500066001', 'aisha@example.test', 60);
$ok($ret['status'] === 'updated' && (int)$db->query("SELECT COUNT(*) FROM lead_emails WHERE lead_id = {$a['lead_id']} AND ref = 'EM5' AND kind = 'returning'")->fetchColumn() === 1, 'same person, new form → "returning" email queued');
sendQueuedLeadEmails($db);
$raw2 = file_get_contents(end(glob("$out/*.eml")));
preg_match_all('/Content-Transfer-Encoding: base64\r\n\r\n(.+?)\r\n--/s', $raw2, $p2);
$ok(str_contains(base64_decode($p2[1][1]), 'getting in touch with Demo Aesthetic Clinic again'), 'returning wording sent');

echo "Failures\n";
mailSaveSetting($db, 'password', 'wrong');
$w = $mk('EM6', 'Wrong Pass', '+971500066006', 'wp@example.test', 60);
$r = sendQueuedLeadEmails($db);
$row = $db->query("SELECT status, attempts, error FROM lead_emails WHERE lead_id = {$w['lead_id']}")->fetch();
$ok($r['failed'] === 1 && $row['status'] === 'error' && str_contains($row['error'], 'username or password was refused'), 'wrong password → error explained: ' . $row['error']);
$ok((int)$db->query("SELECT COUNT(*) FROM leads WHERE id = {$w['lead_id']}")->fetchColumn() === 1, 'lead still saved');
mailSaveSetting($db, 'password', 'good-pass');
$ok(sendQueuedLeadEmails($db)['sent'] === 1, 'retried and sent once the password is fixed');
$bo = $mk('EM7', 'Bounce', '+971500066007', 'bounce@example.test', 60);
sendQueuedLeadEmails($db);
$ok($db->query("SELECT status FROM lead_emails WHERE lead_id = {$bo['lead_id']}")->fetchColumn() === 'error', 'address refused by the server → error, kept for retry');
$db->exec("UPDATE lead_emails SET created_at = NOW() - INTERVAL 25 HOUR WHERE lead_id = {$bo['lead_id']}");
sendQueuedLeadEmails($db);
$ok($db->query("SELECT status FROM lead_emails WHERE lead_id = {$bo['lead_id']}")->fetchColumn() === 'expired', 'given up after 24 hours (never a late "we\'ll call shortly")');
metaSaveSetting($db, 'mail_enabled', '0');
$off = $mk('EM8', 'Off', '+971500066008', 'off@example.test', 60);
$ok((int)$db->query("SELECT COUNT(*) FROM lead_emails WHERE lead_id = {$off['lead_id']}")->fetchColumn() === 0, 'switched off → nothing queued');

echo "Shared mailer
";
metaSaveSetting($db, 'mail_enabled', '1');
array_map('unlink', glob("$out/*.eml") ?: []);
sendEmail($db, 'staff@example.test', 'Weekly report – October', '<h2>Report</h2><p>Line one<br>Line two</p>', '', 'Report');
$raw = file_get_contents(glob("$out/*.eml")[0]);
preg_match_all('/Content-Transfer-Encoding: base64\r\n\r\n(.+?)\r\n--/s', $raw, $pp);
$plainPart = trim(base64_decode($pp[1][0] ?? ''));
$ok($plainPart === "Report\nLine one\nLine two", 'plain-text version made from the HTML when none is given: ' . json_encode($plainPart));
mailSaveSetting($db, 'from_name', 'Clinic Team'); mailSaveSetting($db, 'reply_to', 'reception@example.test');
sendEmail($db, 'staff@example.test', 'Hi', '<p>x</p>', 'x', 'Report');
$raw = file_get_contents(end(glob("$out/*.eml")));
$ok(str_contains($raw, 'Reply-To: <reception@example.test>') && str_contains($raw, 'From: Clinic Team <info@example.ae>'), 'from name and reply-to settings used');
mailSaveSetting($db, 'security', 'tls');
try { sendEmail($db, 'staff@example.test', 'Hi', '<p>x</p>'); $ok(false, 'STARTTLS refusal reported'); }
catch (RuntimeException $e) { $ok(str_contains($e->getMessage(), 'mail server said'), 'server without STARTTLS → error shown: ' . $e->getMessage()); }
$ok($db->query("SELECT status FROM email_log ORDER BY id DESC LIMIT 1")->fetchColumn() === 'error', 'failed email logged as failed');
mailSaveSetting($db, 'security', 'none'); mailSaveSetting($db, 'port', '2599');
try { sendEmail($db, 'staff@example.test', 'Hi', '<p>x</p>'); $ok(false, 'unreachable server reported'); }
catch (RuntimeException $e) { $ok(str_contains($e->getMessage(), 'Can’t reach the mail server 127.0.0.1:2599'), 'wrong port → clear error'); }

// Clean up
$db->exec("DELETE FROM leads WHERE phone LIKE '+97150006600%'");
$db->exec("DELETE FROM lead_emails WHERE id > $maxMail");
$db->exec("DELETE FROM email_log WHERE id > $maxLog");
$db->exec("DELETE FROM integration_settings");
$ins = $db->prepare("INSERT INTO integration_settings (k, v) VALUES (?, ?)");
foreach ($settingsBefore as $k => $v) $ins->execute([$k, $v]);
echo "\n$pass passed, $fail failed\n";
