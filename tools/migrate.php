<?php
// tools/migrate.php — applies pending schema migrations.
//   php tools/migrate.php --dry-run          list what would run
//   php tools/migrate.php                    apply (local / staging)
//   php tools/migrate.php --yes              apply on production: also needs a backup made in the last 10 minutes
//   php tools/migrate.php --set=tenant       which folder under migrations/ (tenant | platform | reference); default tenant
// Multi-clinic mode, clinic migrations: --tenant=<slug> for one clinic, or --all-tenants for each in turn.
// Command line only. Stops at the first failure and says which statement failed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/migrations.php';

$args = getopt('', ['dry-run', 'yes', 'set:', 'tenant:', 'all-tenants']);
$set = $args['set'] ?? 'tenant';
if (!in_array($set, ['tenant', 'platform', 'reference'], true)) { fwrite(STDERR, "Unknown --set=$set\n"); exit(2); }
if ($set !== 'tenant' && !multiTenant()) { fwrite(STDERR, "--set=$set needs multi-clinic mode (PLATFORM_DB_NAME in the config).\n"); exit(2); }
if ($set === 'tenant' && isset($args['all-tenants'])) {
    exit(cliForEachTenant(__FILE__, array_keys(array_filter(['--dry-run' => isset($args['dry-run']), '--yes' => isset($args['yes'])]))) ? 1 : 0);
}
$dir = __DIR__ . '/../migrations/' . $set;
if ($set === 'tenant') cliTenant();
$db = match ($set) {
    'tenant'    => getDB(),
    'platform'  => platformDB(),
    'reference' => dbConnect(REFERENCE_DB_NAME, defined('PLATFORM_DB_USER') ? PLATFORM_DB_USER : DB_USER, defined('PLATFORM_DB_PASS') ? PLATFORM_DB_PASS : DB_PASS, DB_HOST),
};
$dbName = $db->query("SELECT DATABASE()")->fetchColumn();
// One update at a time per database (two cron entries firing in the same second once raced); released when this ends
if (!isset($args['dry-run']) && !(int)$db->query("SELECT GET_LOCK(" . $db->quote('clinic_migrate_' . $dbName) . ", 0)")->fetchColumn()) {
    echo "Database: $dbName · another update of this database is already running; this one stops.\n";
    exit(0);
}
echo "Database: $dbName  ·  environment: " . APP_ENV . "  ·  set: $set\n";

try {
    if ($set === 'tenant' && !isset($args['dry-run']) && adoptExistingDatabase($db, $dir)) echo "Existing clinic database: baseline recorded as already present.\n";
    $status = migrationStatus($db, $dir);
    if ($status['changed']) { fwrite(STDERR, "Refusing: applied migrations were edited afterwards: " . implode(', ', $status['changed']) . "\n"); exit(1); }
    if (!$status['pending']) { echo "Nothing to apply.\n"; exit(0); }
    echo count($status['pending']) . " pending:\n";
    foreach (array_keys($status['pending']) as $v) echo "  $v\n";
    if (isset($args['dry-run'])) exit(0);

    if (APP_ENV === 'production') {
        if (!isset($args['yes'])) { fwrite(STDERR, "Production: add --yes to apply.\n"); exit(1); }
        $b = newestBackup($dbName);
        if (!$b || filemtime($b) < time() - 600) { fwrite(STDERR, "Production: make a fresh backup first (php tools/backup.php); the newest is " . ($b ? date('Y-m-d H:i', filemtime($b)) : 'missing') . ".\n"); exit(1); }
    }
    $n = migrateApply($db, $dir, fn($line) => print("  $line\n"));
    echo "Done: $n applied.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Stopped: " . $e->getMessage() . "\n");
    exit(1);
}
