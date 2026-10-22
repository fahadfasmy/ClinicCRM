<?php
// tests/integration/run_all.php — the feature regression suites (Meta leads, call-back email, returning enquiries,
// Google Calendar, sign-in security). They work against the local copy (clinic_crm_local) through the stand-ins in local/,
// and restore what they change. Needs the local web server on 127.0.0.1:8080 (preview "clinic-crm").
//   php tests/integration/run_all.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../includes/db.php';
if (APP_ENV !== 'local' || currentTenant()['db_name'] !== 'clinic_crm_local') { fwrite(STDERR, "Integration suites only run against clinic_crm_local.\n"); exit(2); }

$root = realpath(__DIR__ . '/../..');
$php = PHP_BINARY;
// Start the stand-ins (mail on 2525, Meta on 8082, Google on 8083, SMS on 8084, WhatsApp Cloud on 8085)
$mocks = [];
$start = function (array $cmd) use (&$mocks, $root) {
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, $root);
    if (is_resource($p)) $mocks[] = $p;
};
$start([$php, 'local/smtp_mock.php']);
$start([$php, '-S', '127.0.0.1:8082', 'local/meta_mock.php']);
$start([$php, '-S', '127.0.0.1:8083', 'local/google_mock.php']);
$start([$php, '-S', '127.0.0.1:8084', 'local/sms_mock.php']);
$start([$php, '-S', '127.0.0.1:8085', 'local/wa_cloud_mock.php']);
$start([$php, '-S', '127.0.0.1:8086', 'local/stripe_mock.php']);
usleep(800000);

$total = [0, 0]; $bad = [];
foreach (['meta_suite', 'lead_email_suite', 'reenquiry_suite', 'gcal_suite', 'security_suite', 'tenancy_suite', 'clinic_setup_suite', 'patients_suite', 'scheduler_suite', 'emr_suite', 'consents_suite', 'billing_suite', 'layouts_suite', 'insurance_suite', 'messaging_suite', 'crm_suite', 'reports_suite', 'compliance_suite', 'i18n_suite', 'saas_suite', 'import_suite'] as $s) {
    $out = (string)shell_exec(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . "/$s.php") . ' 2>&1');
    preg_match('/(\d+) passed, (\d+) failed\s*$/', $out, $m);
    $p = (int)($m[1] ?? 0); $f = isset($m[2]) ? (int)$m[2] : 1;
    $total[0] += $p; $total[1] += $f;
    printf("%-18s %3d passed, %d failed%s\n", $s, $p, $f, $m ? '' : '  (did not finish)');
    if ($f) { $bad[] = $s; foreach (preg_split('/\R/', $out) as $l) if (str_contains($l, 'FAIL') || str_contains($l, 'Fatal')) echo "    $l\n"; }
}
foreach ($mocks as $p) { proc_terminate($p); proc_close($p); }
// php -S children can outlive proc_terminate on Windows: stop anything still listening on the stand-in ports
if (stripos(PHP_OS, 'WIN') === 0) foreach ([2525, 8082, 8083, 8084, 8085, 8086] as $port)
    foreach (array_unique(array_filter(array_map(fn($l) => preg_match("/:$port\s.*LISTENING\s+(\d+)/", $l, $x) ? $x[1] : null, explode("\n", (string)shell_exec('netstat -ano'))))) as $pid)
        shell_exec("taskkill /F /PID $pid 2>NUL");
printf("\nIntegration: %d passed, %d failed\n", $total[0], $total[1]);
exit($total[1] ? 1 : 0);
