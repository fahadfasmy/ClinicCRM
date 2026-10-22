<?php
// pages/export_all.php — Admin → Export all data: everything the clinic holds, as one .tar.gz (opens with 7-Zip, Windows
// 11 or any Mac): every table as JSON and CSV, plus the stored files (scans, photos, signatures, issued PDFs/documents).
// Left out: password hashes, tokens and encrypted values (Emirates ID and passport numbers stay encrypted; ask support
// to include them when moving to another system). Works while the clinic is read-only and for 90 days after cancelling.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); exit('Only admins can export all data.'); }
if (!empty($_SESSION['support'])) { http_response_code(403); exit('Support sessions can’t export the clinic’s data.'); }
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    @set_time_limit(600);
    $tmp = sys_get_temp_dir() . '/crm-export-' . bin2hex(random_bytes(8));
    mkdir($tmp, 0700);
    try {
        $tar = new PharData("$tmp/export.tar");
        $schema = $db->query("SELECT table_name, column_name, data_type FROM information_schema.columns WHERE table_schema = DATABASE() ORDER BY table_name, ordinal_position")->fetchAll(PDO::FETCH_NUM);
        $cols = [];
        foreach ($schema as [$t, $c, $type]) if (!exportSkipColumn($c, $type) && !in_array($c, ['password', 'totp_secret'], true)) $cols[$t][] = $c;
        $counts = [];
        foreach ($cols as $t => $cs) {
            if (in_array($t, ['schema_migrations', 'rate_limits', 'otp_codes', 'trusted_devices', 'password_resets', 'login_codes'], true)) continue;
            $q = $db->query("SELECT `" . implode('`,`', $cs) . "` FROM `$t`");
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            $counts[$t] = count($rows);
            $tar->addFromString("tables/$t.json", json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $h = fopen('php://temp', 'r+'); fwrite($h, "\xEF\xBB\xBF"); fputcsv($h, $cs);
            foreach ($rows as $r) fputcsv($h, array_values($r));
            rewind($h); $tar->addFromString("tables/$t.csv", stream_get_contents($h)); fclose($h);
        }
        $files = 0;
        $base = tenantStoragePath();
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $f) {
            if (!$f->isFile()) continue;
            $tar->addFile($f->getPathname(), 'files/' . str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1)));
            $files++;
        }
        $tar->addFromString('README.txt', "Export of " . clinicName() . " made " . date('c') . " by " . staffName() . ".\n"
            . "tables/: every table as JSON and as CSV (UTF-8; opens in Excel). files/: stored files, in the folders the system keeps them.\n"
            . "Not included: password hashes, sign-in tokens and encrypted values (Emirates ID and passport numbers).\n"
            . "Rows per table:\n" . implode("\n", array_map(fn($t, $n) => "  $t: $n", array_keys($counts), $counts)) . "\nFiles: $files\n");
        $tar->compress(Phar::GZ);
        unset($tar);
        auditLog($db, 'clinic', 0, 'exported_all', ['tables' => count($counts), 'files' => $files]);
        if (multiTenant()) { require_once __DIR__ . '/../includes/platform_billing.php'; platformAudit(null, currentTenant()['id'], 'clinic_export', ['by' => staffName(), 'tables' => count($counts), 'files' => $files]); }
        $gz = "$tmp/export.tar.gz";
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="clinic-export-' . date('Ymd-His') . '.tar.gz"');
        header('Content-Length: ' . filesize($gz));
        readfile($gz);
    } finally {
        foreach (glob("$tmp/*") as $f) @unlink($f);
        @rmdir($tmp);
    }
    exit;
}

$pageTitle = 'Export all data';
$pageSubtitle = t('Everything the clinic holds, in one file');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="card-crm" style="max-width:720px"><div class="card-body-crm fs-13">
  <p><?= t('One .tar.gz file (opens with 7-Zip, Windows 11 or a Mac) with every table as JSON and CSV, and every stored file: identity scans, clinical photos, signatures and issued documents.') ?></p>
  <p><?= t('Not included: password hashes, sign-in tokens and encrypted Emirates ID and passport numbers.') ?></p>
  <p class="text-tx-3"><?= t('It holds patient data: keep it on an encrypted computer, and delete it when you no longer need it. The export is recorded in the audit log.') ?></p>
  <form method="POST"><?= csrfField() ?><button class="btn btn-gold"><i class="bi bi-box-arrow-down"></i><?= t('Download the export') ?></button></form>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
