<?php
// tools/backup.php — compressed dump of the clinic database into BACKUP_PATH (outside the web root), kept 14 days plus
// the first backup of each month for 12 months. cron.php takes one every night (runBackup); this runs it by hand.
//   php tools/backup.php              back up the configured database
//   php tools/backup.php --keep=30    keep this many days instead
// Pure PHP (no mysqldump needed, which shared hosting often blocks). Writes <db>_<YYYYmmdd_His>.sql.gz plus a .sha256 file.
// The dump ends with a "-- dump complete" line so tools/restore.php can refuse a cut-off file. Holds personal data: never copy it into the web root.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/backup.php';   // backupDatabase, pruneBackups, runBackup

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    // Multi-clinic mode: --tenant=<slug> for one clinic, --all-tenants for each in turn, --platform for the platform database
    $args = getopt('', ['keep:', 'tenant:', 'all-tenants', 'platform']);
    $keep = max(1, (int)($args['keep'] ?? 14));
    if (isset($args['all-tenants'])) exit(cliForEachTenant(__FILE__, ["--keep=$keep"]) ? 1 : 0);
    if (!isset($args['platform'])) cliTenant();
    try {
        $db = isset($args['platform']) ? platformDB() : getDB();
        $r = runBackup($db, $keep);
        printf("Backup OK: %s (%d tables, %d rows, %.1f MB, %.1fs). %d old backup(s) removed.\n",
            $r['file'], $r['tables'], $r['rows'], $r['size'] / 1048576, $r['seconds'], $r['pruned']);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Backup FAILED: ' . $e->getMessage() . "\n");
        appLog('Backup failed: ' . $e->getMessage());
        exit(1);
    }
}
