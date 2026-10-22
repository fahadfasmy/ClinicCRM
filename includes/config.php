<?php
// includes/config.php — loads the settings and secrets that must never sit inside the web root.
// Looked for, in order:
//   1. the file named in the CRM_CONFIG environment variable
//   2. crm_config.php one folder ABOVE the web root (on Hostinger: next to public_html, which the web server can't serve)
//   3. includes/db.local.php (local development only; never uploaded)
// The file defines constants; see config.example.php for every key. Missing optional keys get safe defaults below.
if (!defined('CRM_CONFIG_LOADED')) {
    define('CRM_CONFIG_LOADED', true);
    $webRoot = dirname(__DIR__);
    foreach ([getenv('CRM_CONFIG') ?: null, dirname($webRoot) . '/crm_config.php', __DIR__ . '/db.local.php'] as $cfg) {
        if ($cfg && is_file($cfg)) { require_once $cfg; define('CRM_CONFIG_FILE', $cfg); break; }
    }
    defined('APP_ENV')     || define('APP_ENV', defined('CRM_CONFIG_FILE') && str_ends_with(CRM_CONFIG_FILE, 'db.local.php') ? 'local' : 'production');
    defined('DB_CHARSET')  || define('DB_CHARSET', 'utf8mb4');
    defined('DATA_REGION') || define('DATA_REGION', 'AE');   // where clinic data must stay (UAE); see includes/data_flows.php
    // Logs and backups hold personal data, so by default they live next to the web root, never inside it
    defined('LOG_PATH')    || define('LOG_PATH', dirname($webRoot) . '/logs');
    defined('BACKUP_PATH') || define('BACKUP_PATH', dirname($webRoot) . '/backups');
    defined('STORAGE_PATH') || define('STORAGE_PATH', dirname($webRoot) . '/storage');   // per clinic: STORAGE_PATH/{tenant id}
    unset($webRoot, $cfg);

    // Never show PHP errors to visitors outside local development; write them to the log instead
    if (APP_ENV !== 'local') {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        if (is_dir(LOG_PATH) || @mkdir(LOG_PATH, 0750, true)) ini_set('error_log', LOG_PATH . '/php_errors.log');
    }
}

/** Writes one line to LOG_PATH/app.log (never to the screen). */
function appLog(string $msg): void {
    $line = date('c') . ' ' . str_replace(["\r", "\n"], ' ', $msg) . "\n";
    if ((is_dir(LOG_PATH) || @mkdir(LOG_PATH, 0750, true)) && @file_put_contents(LOG_PATH . '/app.log', $line, FILE_APPEND | LOCK_EX) !== false) return;
    error_log(rtrim($line));
}
