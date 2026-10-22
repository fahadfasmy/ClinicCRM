<?php
// tools/icd10_import.php — loads ICD-10-CM codes into the shared reference database (multi-clinic mode), or the clinic's
// own table in single-clinic mode. A single clinic can also use Admin → Clinical settings → ICD-10 import.
//   php tools/icd10_import.php --file=icd10cm-codes-2026.txt
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/emr.php';
$file = getopt('', ['file:'])['file'] ?? '';
if (!is_file($file)) { fwrite(STDERR, "Usage: php tools/icd10_import.php --file=icd10cm-codes-2026.txt\n"); exit(2); }
try {
    $db = multiTenant() ? dbConnect(REFERENCE_DB_NAME, defined('PLATFORM_DB_USER') ? PLATFORM_DB_USER : DB_USER, defined('PLATFORM_DB_PASS') ? PLATFORM_DB_PASS : DB_PASS, DB_HOST)
                        : (function () { cliTenant(); return getDB(); })();
    [$read, $saved] = icdImportFile($db, $file);
    echo "Read $read codes, saved $saved.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Import FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
