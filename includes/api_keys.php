<?php
// includes/api_keys.php — API keys for integrations (e.g. the WhatsApp Web extension).
// Keys are shown once when created; only a SHA-256 hash is stored.
require_once __DIR__ . '/db.php';

function ensureApiKeysTable(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("
        CREATE TABLE IF NOT EXISTS api_keys (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            label        VARCHAR(100) NOT NULL,
            key_prefix   VARCHAR(12)  NOT NULL,
            key_hash     CHAR(64)     NOT NULL UNIQUE,
            assign_to    INT UNSIGNED NULL,
            created_by   INT UNSIGNED NULL,
            is_active    TINYINT(1)   NOT NULL DEFAULT 1,
            last_used_at DATETIME     NULL,
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $done = true;
}

/** Creates a key and returns the plain value (only time it is available). */
function createApiKey(PDO $db, string $label, ?int $assignTo, int $createdBy): string {
    ensureApiKeysTable($db);
    $plain = 'crm_' . bin2hex(random_bytes(20));
    $db->prepare("INSERT INTO api_keys (label, key_prefix, key_hash, assign_to, created_by) VALUES (?,?,?,?,?)")
       ->execute([$label, substr($plain, 0, 10), hash('sha256', $plain), $assignTo, $createdBy]);
    return $plain;
}

/** Returns the active key row for the request's X-CRM-Key header, or null. */
function apiKeyFromRequest(PDO $db): ?array {
    ensureApiKeysTable($db);
    $plain = $_SERVER['HTTP_X_CRM_KEY'] ?? '';
    if (strlen($plain) < 20) return null;
    $stmt = $db->prepare("SELECT * FROM api_keys WHERE key_hash = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([hash('sha256', $plain)]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $db->prepare("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?")->execute([$row['id']]);
    return $row;
}

/** Digits only, e.g. "+971 50-123 4567" → "971501234567". */
function phoneDigits(string $phone): string {
    return preg_replace('/\D/', '', $phone);
}

/**
 * Finds an existing lead by phone, tolerant of formatting and of local vs
 * international form (0501234567 vs +971501234567) by comparing the last 9 digits.
 */
function findLeadByPhoneLoose(PDO $db, string $phone): ?array {
    $digits = phoneDigits($phone);
    if (strlen($digits) < 7) return null;
    $tail = substr($digits, -9);
    $sql = "SELECT l.id, l.name, l.stage, l.phone, s.name AS staff_name
            FROM leads l LEFT JOIN staff s ON s.id = l.assigned_to
            WHERE RIGHT(REGEXP_REPLACE(l.phone, '[^0-9]', ''), 9) = ?
            LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute([$tail]);
    if ($row = $stmt->fetch()) return $row;
    try {
        $stmt = $db->prepare("SELECT l.id, l.name, l.stage, l.phone, s.name AS staff_name
            FROM lead_phones lp JOIN leads l ON l.id = lp.lead_id LEFT JOIN staff s ON s.id = l.assigned_to
            WHERE RIGHT(REGEXP_REPLACE(lp.phone, '[^0-9]', ''), 9) = ? LIMIT 1");
        $stmt->execute([$tail]);
        if ($row = $stmt->fetch()) return $row;
    } catch (PDOException $e) {}
    return null;
}
