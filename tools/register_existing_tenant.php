<?php
// tools/register_existing_tenant.php — makes an EXISTING clinic database (the first clinic's) a clinic on the platform without
// changing its data: registers it with its addresses, gives it its own MySQL user and data key, records the baseline
// schema as already there, then applies the migrations that came after it.
//   php tools/register_existing_tenant.php --slug=main --name="Demo Aesthetic Clinic" --db-name=clinic_crm_local
//          --domains=main.localhost,127.0.0.1,localhost --owner-email=admin@example.ae [--profile=clinic.json]
// --profile: a JSON file of clinic profile settings (brand.name, clinic.phone …) filled in only where still empty.
// Production: takes a backup of that database into BACKUP_PATH first, and stops if the backup fails.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/provision.php';
require_once __DIR__ . '/backup.php';   // backupDatabase()

$a = getopt('', ['slug:', 'name:', 'db-name:', 'domains:', 'owner-email:', 'profile:']);
$slug = strtolower(trim($a['slug'] ?? ''));
$name = trim($a['name'] ?? '');
$dbName = trim($a['db-name'] ?? '');
$hosts = array_values(array_filter(array_map(fn($h) => strtolower(trim($h)), explode(',', $a['domains'] ?? ''))));
try {
    if (!preg_match(SLUG_PATTERN, $slug) || $name === '' || $dbName === '' || !$hosts) throw new RuntimeException('Give --slug, --name, --db-name and --domains (comma-separated, main address first).');
    if (tenantBySlug($slug)) throw new RuntimeException("A clinic with slug \"$slug\" already exists.");
    foreach ($hosts as $h) if (!preg_match('/^[a-z0-9.-]+$/', $h) || tenantByHost($h)) throw new RuntimeException("The address $h is invalid or already used.");
    $profile = [];
    if (!empty($a['profile'])) {
        $profile = json_decode((string)@file_get_contents($a['profile']), true);
        if (!is_array($profile)) throw new RuntimeException('--profile must be a JSON object of settings.');
        foreach ($profile as $k => $v) if (!preg_match('/^(brand|clinic)\.[a-z_]+$|^meta\.web_pixels$/', $k)) throw new RuntimeException("Not a clinic profile setting: $k");
    }

    $root = provisionRoot();
    $s = $root->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name IN ('staff','leads')");
    $s->execute([$dbName]);
    if ((int)$s->fetchColumn() !== 2) throw new RuntimeException("$dbName doesn't look like a clinic database (no staff and leads tables).");
    if (APP_ENV === 'production') {
        if (!is_dir(BACKUP_PATH) && !mkdir(BACKUP_PATH, 0750, true)) throw new RuntimeException("Can't create " . BACKUP_PATH);
        $file = rtrim(BACKUP_PATH, '/\\') . '/' . $dbName . '_' . date('Ymd_His') . '.sql.gz';
        $r = backupDatabase(dbConnect($dbName, PROVISION_DB_USER, defined('PROVISION_DB_PASS') ? PROVISION_DB_PASS : '', DB_HOST), $file);
        file_put_contents($file . '.sha256', hash_file('sha256', $file) . '  ' . basename($file) . "\n");
        echo "Backup: $file ({$r['tables']} tables, {$r['rows']} rows)\n";
    }

    $t = provisionRegister(['slug' => $slug, 'name' => $name, 'db_name' => $dbName, 'db_user' => 'pending', 'db_pass' => '', 'owner_email' => $a['owner-email'] ?? null], $hosts);
    $user = tenantDbPrefix() . $t['id'];
    $pass = provisionDbUser($root, $user, $dbName);
    platformDB()->prepare("UPDATE tenants SET db_user = ?, db_pass_enc = ? WHERE id = ?")->execute([$user, masterSeal($pass), $t['id']]);
    tenantUse(tenantBySlug($slug));
    echo "Clinic #{$t['id']} \"$name\" registered at " . implode(', ', $hosts) . " (database $dbName, user $user)\n";

    $db = getDB();   // as the clinic's own user from here on
    $dir = __DIR__ . '/../migrations/tenant';
    markMigrationApplied($db, $dir, '0000_baseline');
    $n = migrateApply($db, $dir, fn($l) => print("  $l\n"));
    echo "Baseline recorded as present; $n later migration(s) applied.\n";

    if (!empty($a['owner-email'])) {
        $o = $db->prepare("UPDATE staff SET is_owner = 1 WHERE email = ? AND role = 'admin'");
        $o->execute([trim($a['owner-email'])]);
        echo $o->rowCount() ? "Owner: {$a['owner-email']}\n" : "No admin with email {$a['owner-email']}; no owner set.\n";
    }
    $filled = 0;
    $set = $db->prepare("INSERT INTO integration_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = IF(v IS NULL OR v = '', VALUES(v), v)");
    foreach ($profile + ['brand.name' => $name] as $k => $v) { $set->execute([$k, is_array($v) ? json_encode($v) : (string)$v]); $filled++; }
    echo "Clinic profile: $filled setting(s) checked (existing values kept).\n";
    tenantStoragePath();
} catch (Throwable $e) {
    fwrite(STDERR, 'Registration FAILED: ' . $e->getMessage() . "\n");
    appLog('Registering existing clinic failed for ' . $slug . ': ' . $e->getMessage());
    exit(1);
}
