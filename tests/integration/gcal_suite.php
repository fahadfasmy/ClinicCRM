<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Google Calendar checks against local/google_mock.php on 8083. Restores the local DB afterwards.
$_SERVER['HTTP_HOST'] = 'crm.test';
require ROOT . '/includes/auth.php';
require ROOT . '/includes/inventory.php';
$db = getDB();
ensureInventorySchema($db); ensureGcalSchema($db);
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? "  ok   " : "  FAIL "), $m, PHP_EOL; };
$EV = ROOT . '/local/google_mock_events.json';
@unlink($EV); @unlink(ROOT . '/local/google_mock_revoked.txt'); @unlink(ROOT . '/local/GMOCK_DOWN');
$events = fn() => json_decode(@file_get_contents($EV) ?: '{}', true);
$settingsBefore = $db->query("SELECT k, v FROM integration_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$maxAppt = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM appointments")->fetchColumn();
$db->exec("DELETE FROM staff_google");
foreach (['client_id' => 'test.apps.googleusercontent.com', 'client_secret' => 'mock-secret', 'auth_base' => 'http://127.0.0.1:8083',
          'token_base' => 'http://127.0.0.1:8083', 'api_base' => 'http://127.0.0.1:8083', 'crm_host' => 'crm.test'] as $k => $v) gcalSaveSetting($db, $k, $v);

$pracA = (int)$db->query("SELECT id FROM staff WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$pracB = (int)$db->query("SELECT id FROM staff WHERE is_active = 1 AND id <> $pracA ORDER BY id LIMIT 1")->fetchColumn();
$pracC = (int)$db->query("SELECT id FROM staff WHERE is_active = 1 AND id NOT IN ($pracA, $pracB) ORDER BY id LIMIT 1")->fetchColumn();
$svc = $db->query("SELECT id, duration_min FROM services WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch();
$db->prepare("INSERT INTO leads (name, phone, source, stage) VALUES ('Aisha Khan Test', '+971500055001', 'other', 'new')")->execute();
$lead = (int)$db->lastInsertId();
$byAppt = function (int $id) use ($events) { foreach ($events() as $eid => $e) if (($e['extendedProperties']['private']['crm_appointment'] ?? '') === (string)$id) return ['id' => $eid] + $e; return null; };
$map = function (int $id) use ($db) { $s = $db->prepare("SELECT * FROM appointment_gcal WHERE appointment_id = ?"); $s->execute([$id]); return $s->fetch(); };

echo "Sign-in\n";
$url = gcalAuthUrl($db, 'st123');
parse_str(parse_url($url, PHP_URL_QUERY), $q);
$ok(str_starts_with($url, 'http://127.0.0.1:8083/o/oauth2/v2/auth?') && $q['access_type'] === 'offline' && $q['prompt'] === 'consent' && str_contains($q['scope'], 'calendar.events') && $q['redirect_uri'] === 'http://crm.test/pages/google_calendar.php', 'sign-in link asks for lasting calendar access and returns to the Google Calendar page');
$email = gcalConnect($db, $pracA, 'code-' . base64_encode('praca@example.test'));
$ok($email === 'praca@example.test' && gcalStaff($db, $pracA)['refresh_token'] === 'rt-praca@example.test', 'connected, email and lasting access stored');
try { gcalConnect($db, $pracC, 'code-' . base64_encode('noscope@example.test')); $ok(false, 'calendar box unticked refused'); }
catch (GcalException $e) { $ok(str_contains($e->getMessage(), 'Calendar access wasn’t allowed') && !gcalStaff($db, $pracC), 'calendar box unticked → refused with a clear message'); }

echo "Appointments\n";
[$a1] = bookAppointment($db, $lead, (int)$svc['id'], '2026-11-20 15:00', $pracA, 'secret notes');
$e = $byAppt($a1);
$mins = max(5, (int)$svc['duration_min'] ?: 30);
$ok($e && $e['owner'] === 'praca@example.test', 'booking → event in the practitioner’s calendar');
$ok($e && $e['summary'] === "Appointment #$a1 – Aisha T." && $e['start'] === ['dateTime' => '2026-11-20T15:00:00', 'timeZone' => 'Asia/Dubai']
    && $e['end']['dateTime'] === (new DateTime('2026-11-20 15:00'))->modify("+$mins minutes")->format('Y-m-d\TH:i:s'), "minimal title, Dubai time, $mins-minute treatment length: {$e['summary']}");
$ok($e && !str_contains(json_encode($e), 'secret notes') && !str_contains(json_encode($e), '0500055001') && str_contains($e['description'], "https://crm.test/pages/appointment.php?id=$a1"), 'no notes or phone in Google; link back to the CRM');
$evId = $e['id'];
rescheduleAppointment($db, $a1, '2026-11-21 11:30', $pracA);
$e = $byAppt($a1);
$ok($e && $e['id'] === $evId && $e['start']['dateTime'] === '2026-11-21T11:30:00', 'rescheduled → same event moved');
rescheduleAppointment($db, $a1, '2026-11-21 11:30', $pracB);
$ok(!$byAppt($a1) && !$map($a1)['event_id'] && !$map($a1)['pending'], 'given to a practitioner without Google → removed from the old calendar');
gcalConnect($db, $pracB, 'code-' . base64_encode('pracb@example.test'));
$n = gcalBackfill($db, $pracB);
$e = $byAppt($a1);
$ok($n >= 1 && $e && $e['owner'] === 'pracb@example.test', 'second practitioner connects → their upcoming appointments are added');
rescheduleAppointment($db, $a1, '2026-11-21 11:30', $pracA);
$e = $byAppt($a1);
$ok($e && $e['owner'] === 'praca@example.test' && count(array_filter($events(), fn($x) => ($x['extendedProperties']['private']['crm_appointment'] ?? '') === (string)$a1)) === 1, 'reassigned → moved to the other calendar, never duplicated');
$all = $events(); unset($all[$e['id']]); file_put_contents($EV, json_encode($all));
rescheduleAppointment($db, $a1, '2026-11-22 10:00', $pracA);
$ok(($e2 = $byAppt($a1)) && $e2['start']['dateTime'] === '2026-11-22T10:00:00', 'event deleted by hand in Google → put back on the next change');
cancelAppointment($db, $a1, 'cancelled', 'test');
$ok(!$byAppt($a1), 'cancelled → removed from Google');
[$a2] = bookAppointment($db, $lead, (int)$svc['id'], '2026-11-23 12:00', null, '');
$ok(!$byAppt($a2) && !$map($a2)['pending'], 'no practitioner → nothing in Google, nothing pending');
[$a3] = bookAppointment($db, $lead, (int)$svc['id'], '2026-11-24 12:00', $pracA, '');
cancelAppointment($db, $a3, 'no_show', '');
$ok(!$byAppt($a3), 'no-show → removed');

echo "Failures never block bookings\n";
touch(ROOT . '/local/GMOCK_DOWN');
$db->exec("UPDATE staff_google SET access_expires = 0");
[$a4] = bookAppointment($db, $lead, (int)$svc['id'], '2026-11-25 16:00', $pracA, '');
$m4 = $map($a4);
$ok($a4 > 0 && $m4['pending'] && str_contains((string)$m4['error'], 'Backend Error'), 'Google down → booking still saved, marked for retry: ' . $m4['error']);
unlink(ROOT . '/local/GMOCK_DOWN');
$r = gcalRetryPending($db);
$ok($r['synced'] >= 1 && $byAppt($a4) && !$map($a4)['pending'], 'hourly retry puts it in the calendar once Google is back');
$db->beginTransaction();
rescheduleAppointment($db, $a4, '2026-11-25 17:00', $pracA);
$db->commit();
$ok($map($a4)['pending'] && $byAppt($a4)['start']['dateTime'] === '2026-11-25T16:00:00', 'inside a database transaction → no Google call, left for the retry');
gcalRetryPending($db);
$ok($byAppt($a4)['start']['dateTime'] === '2026-11-25T17:00:00', '…and the retry applies it');
$db->exec("UPDATE staff_google SET refresh_token = 'rt-revoked@x', access_expires = 0 WHERE staff_id = $pracA");
rescheduleAppointment($db, $a4, '2026-11-25 18:00', $pracA);
$ok(str_contains((string)gcalStaff($db, $pracA)['last_error'], 'Connect your Google Calendar again') && $map($a4)['pending'], 'access removed in Google → “connect again” shown, change kept for retry');
gcalConnect($db, $pracA, 'code-' . base64_encode('praca@example.test'));
gcalRetryPending($db);
$ok($byAppt($a4)['start']['dateTime'] === '2026-11-25T18:00:00' && !gcalStaff($db, $pracA)['last_error'], 'reconnected → caught up');

echo "Disconnect\n";
gcalDisconnect($db, $pracA);
$ok(!gcalStaff($db, $pracA) && str_contains((string)@file_get_contents(ROOT . '/local/google_mock_revoked.txt'), 'rt-praca@example.test'), 'disconnect asks Google to forget the access and removes it here');
rescheduleAppointment($db, $a4, '2026-11-26 09:00', $pracA);
$ok(!$map($a4)['pending'] && !$map($a4)['event_id'], 'after disconnecting, changes no longer go to Google and nothing piles up');

echo "Titles\n";
$t = fn($n) => gcalEventBody($db, ['id' => 7, 'lead_name' => $n, 'scheduled_at' => '2026-11-01 10:00', 'duration_min' => 0])['summary'];
$ok($t('Mona') === 'Appointment #7 – Mona' && $t('سارة أحمد') === 'Appointment #7 – سارة أ.' && $t('  ') === 'Appointment #7', 'one-word, Arabic and empty names');
$ok(gcalEventBody($db, ['id' => 7, 'lead_name' => 'x', 'scheduled_at' => '2026-11-01 23:50', 'duration_min' => 30])['end']['dateTime'] === '2026-11-02T00:20:00', 'crossing midnight');

// Clean up (cancel what is still booked so the reserved stock is released)
foreach ($db->query("SELECT id FROM appointments WHERE id > $maxAppt AND status = 'booked'")->fetchAll(PDO::FETCH_COLUMN) as $id) cancelAppointment($db, (int)$id, 'cancelled', 'test cleanup');
$ids = $db->query("SELECT id FROM appointments WHERE id > $maxAppt")->fetchAll(PDO::FETCH_COLUMN);
foreach ($ids as $id) foreach (['stock_movements', 'appointment_items', 'appointment_gcal', 'appointment_services', 'appointment_status_history'] as $t) $db->exec("DELETE FROM $t WHERE appointment_id = $id");
$db->exec("DELETE FROM appointments WHERE id > $maxAppt");
$db->exec("DELETE FROM leads WHERE id = $lead");
$db->exec("DELETE FROM staff_google");
$db->exec("DELETE FROM integration_settings");
$ins = $db->prepare("INSERT INTO integration_settings (k, v) VALUES (?, ?)");
foreach ($settingsBefore as $k => $v) $ins->execute([$k, $v]);
@unlink($EV); @unlink(ROOT . '/local/google_mock_revoked.txt');
echo "\n$pass passed, $fail failed\n";
