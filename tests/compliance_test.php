<?php
// Phase 13: the UAE residency check (an unregistered outbound host fails it), the outbound guard, the notification bell
// (who sees what), audit and chart-access searches, a patient's data export and anonymise, 2-step sign-in by role,
// idle sign-out setting, restore drills and the nightly backup timing. Scratch database clinic_crm_test_compliance.
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/data_flows.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/messaging.php';

function cmpDb(): PDO {
    static $done = false;
    $db = testDb('clinic_crm_test_compliance', !$done);
    if (!$done) {
        migrateApply($db, __DIR__ . '/../migrations/tenant', fn($l) => null);
        $db->exec("INSERT INTO staff (id, name, email, password, role) VALUES (931, 'Ada Admin', 'ada.c@example.test', 'x', 'admin'), (932, 'Rita Desk', 'rita.c@example.test', 'x', 'receptionist'), (933, 'Dr Who', 'who.c@example.test', 'x', 'practitioner')");
        $db->exec("INSERT INTO leads (id, name, phone, email, source, stage, notes) VALUES (771, 'Huda Hassan', '+971501112233', 'huda@example.test', 'walk_in', 'converted', 'Prefers mornings')");
        $db->exec("INSERT INTO patients (id, mrn, lead_id, first_name, last_name, name_ar, gender, dob, nationality, email, eid_enc, eid_hash, eid_last4, emergency_name, notes, tags, marketing_consent, whatsapp_opt_in, branch_id, status)
                   VALUES (771, 'MRN-771', 771, 'Huda', 'Hassan', 'هدى حسن', 'female', '1991-06-15', 'AE', 'huda@example.test', 0x0102, 'h', '4321', 'Sara Hassan', 'VIP', 'vip', 1, 1, 1, 'active')");
        $db->exec("INSERT INTO patient_phones (patient_id, phone, is_primary) VALUES (771, '+971501112233', 1)");
        $db->exec("INSERT INTO services (id, name, price, vat_rate, duration_min) VALUES (81, 'Facial', 300, 5, 30)");
        $db->exec("INSERT INTO appointments (id, patient_id, service_id, branch_id, scheduled_at, ends_at, status) VALUES (781, 771, 81, 1, '2026-09-01 10:00', '2026-09-01 10:30', 'completed')");
        $db->exec("INSERT INTO invoices (id, number, branch_id, patient_id, type, status, issued_at, gross, net, vat, total, patient_share, paid) VALUES (791, 'CMP-1', 1, 771, 'cash', 'paid', '2026-09-01 11:00', 300, 300, 15, 315, 315, 315)");
        $db->exec("INSERT INTO invoice_lines (invoice_id, kind, service_id, description, qty, unit_price, taxable, vat_rate, vat_amount, line_total) VALUES (791, 'service', 81, 'Facial', 1, 300, 300, 5, 15, 315)");
        $db->exec("INSERT INTO messages (channel, direction, patient_id, to_from, body, status) VALUES ('sms', 'out', 771, '+971501112233', 'Hi Huda, see you tomorrow', 'sent')");
        $done = true;
    }
    cmpAs(931, 'admin');
    return $db;
}
function cmpAs(int $id, string $role): void { $_SESSION['staff_id'] = $id; $_SESSION['role'] = $role; staffPermissionOverrides($id, true); }

function test_an_unregistered_outbound_host_fails_the_residency_check() {
    $db = cmpDb();
    check(residencyCheck($db, []) === [], 'nothing configured outside the register: passes');
    $db->exec("REPLACE INTO integration_settings (k, v) VALUES ('meta.graph_base', 'https://graph.evil-proxy.example.net')");
    $p = residencyCheck($db, []);
    check(count($p) === 1 && str_contains($p[0], 'meta.graph_base') && str_contains($p[0], 'graph.evil-proxy.example.net'), 'a setting pointing at an unregistered host fails: ' . implode('; ', $p));
    $db->exec("REPLACE INTO integration_settings (k, v) VALUES ('meta.graph_base', 'https://graph.facebook.com')");
    check(residencyCheck($db, []) === [], 'back to the registered host: passes');
    $db->exec("REPLACE INTO integration_settings (k, v) VALUES ('msg.google_review_url', 'https://g.page/r/abc')");
    check(residencyCheck($db, []) === [], 'a link people open in their browser is not a data flow');
}

function test_a_page_loading_third_party_files_fails_the_residency_check() {
    $dir = tempDir('res');
    file_put_contents("$dir/ok.php", '<script src="/assets/vendor/x.js"></script><a href="https://wa.me/971500000000">chat</a><img src="data:image/png;base64,AA">');
    check(residencyCheck(null, [$dir]) === [], 'self-hosted files and plain links are fine');
    file_put_contents("$dir/bad.php", "<script src=\"https://cdn.jsdelivr.net/npm/x.js\"></script>\n<link rel=\"stylesheet\" href=\"//fonts.googleapis.com/css?family=X\">\n<style>body{background:url('https://img.example.org/a.png')}</style>");
    $p = residencyCheck(null, [$dir]);
    check(count($p) === 3 && str_contains(implode(' ', $p), 'cdn.jsdelivr.net') && str_contains(implode(' ', $p), 'fonts.googleapis.com') && str_contains(implode(' ', $p), 'img.example.org'), 'a script, a font and a CSS image from other hosts are each reported');
    rmTree($dir);
    check(residencyCheck(null) === [], 'the real staff and patient pages pass');
}

function test_the_outbound_guard_refuses_unregistered_hosts() {
    check(outboundAllowed('https://graph.facebook.com/v21.0/me') && outboundAllowed('smtp.hostinger.com') && outboundAllowed('https://www.googleapis.com/calendar/v3'), 'registered hosts are allowed');
    check(!outboundAllowed('https://api.openai.com/v1') && !outboundAllowed(''), 'others are not');
    check(outboundAllowed('http://127.0.0.1:8082') === (APP_ENV === 'local'), 'this computer only on a local copy');
    checkThrows(fn() => outboundGuard('https://telemetry.example.io/collect'), 'not in the data-flow register', 'the guard throws before anything is sent');
}

function test_notifications_reach_the_right_people() {
    $db = cmpDb();
    $db->exec("DELETE FROM staff_notifications");
    $db->exec("INSERT INTO staff_notifications (staff_id, permission, kind, title) VALUES (NULL, 'messages.view', 'request', 'Reschedule request'), (NULL, 'data.flows.view', 'backup', 'Backup failed'), (932, NULL, 'note', 'For Rita'), (NULL, NULL, 'all', 'For everyone')");
    cmpAs(931, 'admin');
    check(notifCount($db) === 3, 'the admin sees the request, the backup alert and the one for everyone (not Rita’s)');
    cmpAs(932, 'receptionist');
    check(notifCount($db) === 3 && !in_array('Backup failed', array_column(notifList($db, true), 'title'), true), 'reception: the request, their own and everyone’s — not the backup alert');
    cmpAs(933, 'practitioner');
    check(array_column(notifList($db, true), 'title') === ['For everyone'], 'a practitioner only gets the one for everyone');
    check(notifMarkRead($db, (int)$db->query("SELECT id FROM staff_notifications WHERE title = 'For Rita'")->fetchColumn()) === 0, 'nobody can close someone else’s notification');
    cmpAs(932, 'receptionist');
    notifMarkRead($db, null);
    check(notifCount($db) === 0 && (int)$db->query("SELECT read_by FROM staff_notifications WHERE title = 'Reschedule request'")->fetchColumn() === 932, 'mark all done: closed and who did it recorded');
    cmpAs(931, 'admin');
    check(notifCount($db) === 1, 'shared ones are done for everyone; the admin still has the backup alert');
}

function test_audit_and_access_searches() {
    $db = cmpDb();
    auditLog($db, 'invoice', 791, 'voided', ['reason' => 'test']);
    logPatientAccess($db, 771, 'profile:overview');
    cmpAs(932, 'receptionist'); logPatientAccess($db, 771, 'profile:documents'); cmpAs(931, 'admin');
    check(count(auditSearch($db, ['entity' => 'invoice', 'entity_id' => 791])) === 1 && auditSearch($db, ['action' => 'void'])[0]['staff'] === 'Ada Admin', 'changes by record and by action, with who did it');
    $a = accessSearch($db, ['patient' => 'MRN-771']);
    check(count($a) >= 2 && count(accessSearch($db, ['patient' => '771', 'staff' => 932])) === 1, 'chart access by MRN or number, and by staff member');
    check(accessSearch($db, ['from' => '2000-01-01', 'to' => '2000-01-02']) === [], 'and by date');
}

function test_patient_export_has_everything_but_secrets() {
    $db = cmpDb();
    $before = (int)$db->query("SELECT COUNT(*) FROM patient_access_log WHERE patient_id = 771 AND what = 'export'")->fetchColumn();
    $x = patientExport($db, 771);
    check($x['patient']['first_name'] === 'Huda' && $x['patient']['emirates_id'] === '…4321' && !array_key_exists('eid_enc', $x['patient']) && !array_key_exists('eid_hash', $x['patient']), 'their details, Emirates ID as the last 4 digits only, no encrypted values');
    check(count($x['appointments']) === 1 && count($x['invoices']) === 1 && count($x['invoice_lines']) === 1 && count($x['patient_phones']) === 1 && count($x['messages']) === 1 && $x['lead'][0]['name'] === 'Huda Hassan', 'appointments, invoices with lines, phones, messages and the lead');
    check(json_encode($x, JSON_UNESCAPED_UNICODE) !== false && str_contains(json_encode($x, JSON_UNESCAPED_UNICODE), 'هدى'), 'it encodes as JSON, Arabic intact');
    check((int)$db->query("SELECT COUNT(*) FROM patient_access_log WHERE patient_id = 771 AND what = 'export'")->fetchColumn() === $before + 1 && auditSearch($db, ['entity' => 'patient', 'action' => 'exported']), 'the export is logged');
}

function test_anonymise_removes_identity_and_keeps_the_records_the_law_needs() {
    $db = cmpDb();
    checkThrows(fn() => anonymisePatient($db, 771, 'Asked by email', 'MRN-77'), 'MRN', 'the MRN must be typed exactly');
    checkThrows(fn() => anonymisePatient($db, 771, 'x', 'MRN-771'), 'why', 'a reason is needed');
    $db->exec("INSERT INTO appointments (id, patient_id, service_id, branch_id, scheduled_at, ends_at, status) VALUES (782, 771, 81, 1, NOW() + INTERVAL 3 DAY, NOW() + INTERVAL 3 DAY + INTERVAL 30 MINUTE, 'booked')");
    checkThrows(fn() => anonymisePatient($db, 771, 'Asked by email on 1 Nov', 'MRN-771'), 'future appointments', 'refused while a future appointment is booked');
    $db->exec("UPDATE appointments SET status = 'cancelled' WHERE id = 782");
    anonymisePatient($db, 771, 'Asked by email on 1 Nov', 'MRN-771');
    $p = $db->query("SELECT * FROM patients WHERE id = 771")->fetch();
    check($p['first_name'] === 'Anonymised' && $p['last_name'] === '#MRN-771' && $p['name_ar'] === null && $p['email'] === null && $p['eid_enc'] === null && $p['eid_last4'] === null
          && $p['emergency_name'] === null && $p['notes'] === null && $p['dob'] === '1991-01-01' && $p['status'] === 'anonymised' && !$p['marketing_consent'], 'identity removed (birth year kept)');
    check(!$db->query("SELECT COUNT(*) FROM patient_phones WHERE patient_id = 771")->fetchColumn(), 'phone numbers removed');
    $l = $db->query("SELECT name, phone, email, notes FROM leads WHERE id = 771")->fetch();
    check($l === ['name' => 'Anonymised', 'phone' => '', 'email' => null, 'notes' => null], 'the lead they came from is blanked');
    check($db->query("SELECT CONCAT(to_from, '|', body) FROM messages WHERE patient_id = 771")->fetchColumn() === '|[anonymised]', 'message text and number removed');
    check($db->query("SELECT total FROM invoices WHERE id = 791")->fetchColumn() == 315 && (int)$db->query("SELECT COUNT(*) FROM appointments WHERE patient_id = 771")->fetchColumn() === 2, 'invoices and visits are kept');
    $au = auditSearch($db, ['entity' => 'patient', 'action' => 'anonymised'])[0];
    check(str_contains($au['changes'], 'Asked by email') && !str_contains($au['changes'], 'Huda'), 'the audit keeps the reason, not the old identity');
    checkThrows(fn() => anonymisePatient($db, 771, 'again please', 'MRN-771'), 'already', 'it happens once');
}

function test_two_step_by_role_and_idle_setting() {
    $db = cmpDb();
    mailSaveSetting($db, 'password', 'x'); mailSaveSetting($db, 'require_2fa', '1'); mailSaveSetting($db, 'require_2fa_roles', '');
    check(secTwoStepRequired($db, 'receptionist') && secTwoStepRequired($db, 'admin'), 'no roles picked: everyone needs the code');
    mailSaveSetting($db, 'require_2fa_roles', json_encode(['admin', 'finance', 'nope']));
    check(secTwoStepRoles($db) === ['admin', 'finance'] && secTwoStepRequired($db, 'finance') && !secTwoStepRequired($db, 'receptionist'), 'only the roles picked (unknown ones ignored)');
    mailSaveSetting($db, 'require_2fa', '0');
    check(!secTwoStepRequired($db, 'admin'), 'switched off: no one');
    check(secIdleMinutes($db) === 0, 'idle sign-out is off by default');
    mailSaveSetting($db, 'idle_minutes', '30');
    check(secIdleMinutes($db) === 30, 'set to 30 minutes');
}

function test_restore_drills_and_backup_timing() {
    $db = cmpDb();
    checkThrows(fn() => recordRestoreDrill($db, ['drilled_on' => date('Y-m-d', strtotime('+1 day')), 'backup_file' => 'x.sql.gz', 'result' => 'pass']), 'day', 'not in the future');
    checkThrows(fn() => recordRestoreDrill($db, ['drilled_on' => date('Y-m-d'), 'backup_file' => '', 'result' => 'pass']), 'backup file', 'which backup is needed');
    $id = recordRestoreDrill($db, ['drilled_on' => date('Y-m-d'), 'backup_file' => 'db_20261101_020001.sql.gz', 'result' => 'pass', 'tables_count' => 118, 'rows_count' => 4271, 'notes' => 'Local copy']);
    check($db->query("SELECT result FROM restore_drills WHERE id = $id")->fetchColumn() === 'pass' && auditSearch($db, ['entity' => 'restore_drill']), 'a drill is recorded and audit-logged');
    $today = (new DateTimeImmutable('now', new DateTimeZone(CLINIC_TZ)))->format('Y-m-d');
    metaSet($db, 'backup_last', json_encode(['day' => $today]));
    check(!nightlyBackupDue($db), 'already backed up today: not due');
    metaSet($db, 'backup_last', json_encode(['day' => '2026-01-01']));
    check(nightlyBackupDue($db) === ((int)(new DateTimeImmutable('now', new DateTimeZone(CLINIC_TZ)))->format('G') >= 2), 'due after 02:00 when the last one was another day');
}
