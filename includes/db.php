<?php
// includes/db.php - PDO Database Connection

// Connection settings and secrets come from a config file outside the web root (includes/config.php explains where).
// Which database: the current clinic's (includes/tenant.php), so one code base serves every clinic.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant.php';

/** A connection with the house settings (exceptions, utf8mb4, PHP clock in step with MySQL). Throws PDOException. */
function dbConnect(string $name, string $user, string $pass, string $host): PDO {
    $pdo = new PDO('mysql:host=' . $host . ';dbname=' . $name . ';charset=' . DB_CHARSET, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // Force consistent collation across all queries to prevent
    // "Illegal mix of collations" errors when tables have different collations
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
    $pdo->exec("SET collation_connection = 'utf8mb4_general_ci'");
    // Keep PHP's clock in step with MySQL's NOW() so "time ago" / overdue maths agree
    $offsetMin = (int)$pdo->query("SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW())")->fetchColumn();
    $offsetMin = (int)(round($offsetMin / 15) * 15);
    if ($offsetMin % 60 === 0) {
        $h = intdiv($offsetMin, 60);
        date_default_timezone_set($h === 0 ? 'UTC' : 'Etc/GMT' . ($h > 0 ? '-' : '+') . abs($h));
    }
    return $pdo;
}

/** The current clinic's database. */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $t = currentTenant();
            if ($t['db_name'] === '' || $t['db_user'] === '') {
                throw new PDOException('No database settings: create crm_config.php outside the web root (see config.example.php).');
            }
            $pdo = dbConnect($t['db_name'], $t['db_user'], $t['db_pass'], $t['db_host']);
        } catch (PDOException | RuntimeException $e) {
            // The real reason goes to the log only: it can contain the database host and user name
            appLog('Database connection failed: ' . $e->getMessage());
            if (PHP_SAPI === 'cli') { fwrite(STDERR, ($e instanceof PDOException ? 'Database connection failed (details in the log).' : $e->getMessage()) . "\n"); exit(1); }
            http_response_code(503);
            $wantsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') || str_contains((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api');
            if ($wantsJson) { header('Content-Type: application/json'); echo json_encode(['error' => 'The system is temporarily unavailable. Please try again shortly.']); }
            else echo '<!DOCTYPE html><meta charset="utf-8"><title>Temporarily unavailable</title><p style="font-family:sans-serif;margin:3rem">The system is temporarily unavailable. Please try again in a few minutes.</p>';
            exit(1);
        }
    }
    return $pdo;
}
