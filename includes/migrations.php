<?php
// includes/migrations.php — numbered schema changes (migrations/<set>/NNNN_description.sql), applied in order and
// recorded in schema_migrations. Also holds the SQL statement splitter that tools/restore.php shares.
// Existing ensure*Schema() functions stay as they are; every NEW schema change goes through a migration file.
require_once __DIR__ . '/db.php';

/**
 * Splits SQL text into statements, streaming: feed chunks with sqlSplitFeed() and collect what it returns.
 * Understands '…', "…" and `…` quoting with backslash escapes, -- and # line comments and block comments,
 * so a ";" inside a string or comment never ends a statement. DELIMITER blocks are not supported (by design).
 */
function sqlSplitNew(): array { return ['buf' => '', 'q' => null, 'esc' => false, 'line' => false, 'block' => false]; }

function sqlSplitFeed(array &$st, string $chunk, bool $final = false): array {
    $out = [];
    $n = strlen($chunk);
    for ($i = 0; $i < $n; $i++) {
        $c = $chunk[$i];
        if ($st['line']) { if ($c === "\n") { $st['line'] = false; $st['buf'] .= $c; } continue; }
        if ($st['block']) { if ($c === '*' && ($chunk[$i + 1] ?? '') === '/') { $st['block'] = false; $i++; } continue; }
        if ($st['q'] !== null) {
            $st['buf'] .= $c;
            if ($st['esc']) { $st['esc'] = false; continue; }
            if ($c === '\\' && $st['q'] !== '`') { $st['esc'] = true; continue; }
            if ($c === $st['q']) $st['q'] = null;
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $st['q'] = $c; $st['buf'] .= $c; continue; }
        if ($c === '#' || ($c === '-' && ($chunk[$i + 1] ?? '') === '-' && in_array($chunk[$i + 2] ?? ' ', [' ', "\t", "\n", "\r"], true))) { $st['line'] = true; continue; }
        if ($c === '/' && ($chunk[$i + 1] ?? '') === '*' && ($chunk[$i + 2] ?? '') !== '!') { $st['block'] = true; $i++; continue; }
        if ($c === ';') {
            if (trim($st['buf']) !== '') $out[] = trim($st['buf']);
            $st['buf'] = '';
            continue;
        }
        $st['buf'] .= $c;
    }
    if ($final) {
        if ($st['q'] !== null) throw new RuntimeException('The SQL ends inside a quoted string.');
        if (trim($st['buf']) !== '') $out[] = trim($st['buf']);
        $st['buf'] = '';
    }
    return $out;
}

/** All statements in a piece of SQL text (convenience for small files). */
function sqlSplit(string $sql): array {
    $st = sqlSplitNew();
    return sqlSplitFeed($st, $sql, true);
}

function ensureMigrationsTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(100) PRIMARY KEY,
        checksum CHAR(64) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Migration files of a set, in order: [version => path]. Version = file name without .sql. */
function migrationFiles(string $dir): array {
    $files = [];
    foreach (glob(rtrim($dir, '/\\') . '/*.sql') ?: [] as $f) {
        $v = basename($f, '.sql');
        if (!preg_match('/^\d{4}_[a-z0-9_]+$/', $v)) throw new RuntimeException("Bad migration file name: " . basename($f) . " (use NNNN_description.sql)");
        $files[$v] = $f;
    }
    ksort($files, SORT_STRING);
    return $files;
}

/**
 * What would run, and problems with what already ran: ['pending' => [version => path], 'changed' => [versions edited after applying]].
 */
function migrationStatus(PDO $db, string $dir): array {
    ensureMigrationsTable($db);
    $done = $db->query("SELECT version, checksum FROM schema_migrations")->fetchAll(PDO::FETCH_KEY_PAIR);
    $pending = $changed = [];
    foreach (migrationFiles($dir) as $v => $f) {
        $sum = hash_file('sha256', $f);
        if (!isset($done[$v])) $pending[$v] = $f;
        elseif (!hash_equals($done[$v], $sum)) $changed[] = $v;
    }
    return ['pending' => $pending, 'changed' => $changed];
}

/**
 * Applies pending migrations in order, stopping at the first failure. $log receives one line per step.
 * Each file runs in a transaction; MySQL commits DDL (CREATE/ALTER) implicitly, so write each file to be safe to re-run
 * (IF NOT EXISTS etc.) — a file is only recorded once every statement in it has succeeded.
 */
function migrateApply(PDO $db, string $dir, callable $log): int {
    $status = migrationStatus($db, $dir);
    if ($status['changed']) throw new RuntimeException('These applied migrations were edited afterwards (never edit an applied file; add a new one): ' . implode(', ', $status['changed']));
    $n = 0;
    foreach ($status['pending'] as $v => $f) {
        $sql = file_get_contents($f);
        if (preg_match('/^\s*DELIMITER\b/mi', $sql)) throw new RuntimeException("$v uses DELIMITER, which the runner doesn't support.");
        $stmts = sqlSplit($sql);
        $db->beginTransaction();
        try {
            foreach ($stmts as $k => $s) {
                try { $db->exec($s); }
                catch (PDOException $e) { throw new RuntimeException("$v, statement " . ($k + 1) . ': ' . $e->getMessage(), 0, $e); }
            }
            $db->prepare("INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)")->execute([$v, hash_file('sha256', $f)]);
            if ($db->inTransaction()) $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $log("FAILED $v: " . $e->getMessage());
            throw $e;
        }
        $log("applied $v (" . count($stmts) . ' statement' . (count($stmts) == 1 ? '' : 's') . ')');
        $n++;
    }
    return $n;
}

/** The newest backup file of a database in BACKUP_PATH, or null. */
function newestBackup(string $dbName): ?string {
    $files = glob(rtrim(BACKUP_PATH, '/\\') . '/' . $dbName . '_*.sql.gz') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $files[0] ?? null;
}
/** Records migrations as applied without running them (the database already has that schema). */
function markMigrationApplied(PDO $db, string $dir, string $version): void {
    $files = migrationFiles($dir);
    if (!isset($files[$version])) throw new RuntimeException("No migration $version");
    ensureMigrationsTable($db);
    $db->prepare("INSERT IGNORE INTO schema_migrations (version, checksum) VALUES (?, ?)")->execute([$version, hash_file('sha256', $files[$version])]);
}

/**
 * A clinic database that existed before migrations (it has leads, but nothing recorded yet) already has the baseline
 * schema: record 0000_baseline as applied instead of running it. Returns true when it did so.
 */
function adoptExistingDatabase(PDO $db, string $dir): bool {
    ensureMigrationsTable($db);
    if ($db->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn() > 0) return false;
    if (!$db->query("SHOW TABLES LIKE 'leads'")->fetch()) return false;
    markMigrationApplied($db, $dir, '0000_baseline');
    return true;
}
