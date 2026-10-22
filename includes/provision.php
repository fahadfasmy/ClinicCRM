<?php
// includes/provision.php — shared by tools/provision_tenant.php (new clinic) and tools/register_existing_tenant.php
// (a clinic database that already exists, e.g. the first clinic). Command line only: needs a database login that may create databases and users
// (PROVISION_DB_USER / PROVISION_DB_PASS in the config), which the web application itself never has.
require_once __DIR__ . '/migrations.php';

const SLUG_PATTERN = '/^[a-z][a-z0-9-]{1,38}[a-z0-9]$/';

function provisionRoot(): PDO {
    if (!multiTenant()) throw new RuntimeException('Clinics can only be added in multi-clinic mode (PLATFORM_DB_NAME in the config).');
    if (!defined('PROVISION_DB_USER')) throw new RuntimeException('PROVISION_DB_USER / PROVISION_DB_PASS are not set in the config.');
    return new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', PROVISION_DB_USER, defined('PROVISION_DB_PASS') ? PROVISION_DB_PASS : '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

function tenantDbPrefix(): string { return defined('TENANT_DB_PREFIX') ? TENANT_DB_PREFIX : 'crm_t_'; }

/**
 * Creates (or resets the password of) the clinic's own MySQL user: full rights on its own database only, plus read-only
 * on the shared reference database. Returns the new password. Names are checked before they reach SQL.
 */
function provisionDbUser(PDO $root, string $user, string $dbName): string {
    foreach ([$user, $dbName] as $n) if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $n)) throw new RuntimeException("Unsafe database name: $n");
    $host = defined('TENANT_DB_USER_HOST') ? TENANT_DB_USER_HOST : '%';
    $pass = bin2hex(random_bytes(24));
    $u = $root->quote($user) . '@' . $root->quote($host);
    $root->exec("CREATE USER IF NOT EXISTS $u IDENTIFIED BY " . $root->quote($pass));
    $root->exec("ALTER USER $u IDENTIFIED BY " . $root->quote($pass));
    $root->exec("GRANT ALL PRIVILEGES ON `$dbName`.* TO $u");
    if (defined('REFERENCE_DB_NAME')) $root->exec("GRANT SELECT ON `" . REFERENCE_DB_NAME . "`.* TO $u");
    return $pass;
}

/** Adds the clinic to the platform database with its addresses. Returns the tenant row as currentTenant() gives it. */
function provisionRegister(array $f, array $hosts): array {
    $p = platformDB();
    $p->beginTransaction();
    try {
        $p->prepare("INSERT INTO tenants (slug, name, db_name, db_user, db_pass_enc, data_key_enc, key_id, storage_prefix, status, owner_email)
                     VALUES (?,?,?,?,?,?,?,?,?,?)")
          ->execute([$f['slug'], $f['name'], $f['db_name'], $f['db_user'], masterSeal($f['db_pass']), newWrappedDataKey(), masterKey()[0], '', $f['status'] ?? 'active', $f['owner_email'] ?? null]);
        $id = (int)$p->lastInsertId();
        $p->prepare("UPDATE tenants SET storage_prefix = ? WHERE id = ?")->execute([(string)$id, $id]);
        $d = $p->prepare("INSERT INTO tenant_domains (tenant_id, host, is_primary, verified_at) VALUES (?,?,?,NOW())");
        foreach (array_values(array_unique(array_map('strtolower', $hosts))) as $i => $h) $d->execute([$id, $h, $i === 0 ? 1 : 0]);
        $p->commit();
    } catch (Throwable $e) {
        $p->rollBack();
        throw $e;
    }
    return tenantBySlug($f['slug']);
}

/** The usual address of a clinic: <slug>.PRODUCT_DOMAIN. */
function defaultTenantHost(string $slug): string {
    if (!defined('PRODUCT_DOMAIN')) throw new RuntimeException('PRODUCT_DOMAIN is not set in the config.');
    return $slug . '.' . PRODUCT_DOMAIN;
}

/**
 * Creates a clinic end to end: its database and MySQL user, the schema (every migration), default rows only (never demo
 * data), the owner's admin account (unusable until they choose a password) and a one-time password link (7 days).
 * $in: slug, name, owner_name, owner_email, optional domain, legal_name, trn, dha_license. Returns [tenant, reset token].
 * Used by tools/provision_tenant.php and the signup page. $log receives progress lines.
 */
function provisionTenant(array $in, ?callable $log = null): array {
    $log ??= fn($l) => null;
    $slug = strtolower(trim((string)($in['slug'] ?? '')));
    $name = trim((string)($in['name'] ?? '')); $owner = trim((string)($in['owner_name'] ?? '')); $email = strtolower(trim((string)($in['owner_email'] ?? '')));
    if (!preg_match(SLUG_PATTERN, $slug)) throw new RuntimeException('The web address name must be 3-40 lower-case letters, digits and dashes, starting with a letter.');
    if ($name === '' || $owner === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The clinic name, owner name and a valid email are needed.');
    if (tenantBySlug($slug)) throw new RuntimeException("The web address \"$slug\" is already taken.");
    $hosts = [defaultTenantHost($slug)];
    if (!empty($in['domain'])) $hosts[] = strtolower(trim($in['domain']));
    foreach ($hosts as $h) if (!preg_match('/^[a-z0-9.-]+$/', $h) || tenantByHost($h)) throw new RuntimeException("The address $h is invalid or already used.");

    $root = provisionRoot();
    $dbName = tenantDbPrefix() . str_replace('-', '_', $slug);
    $exists = $root->prepare("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?");
    $exists->execute([$dbName]);
    if ($exists->fetchColumn()) throw new RuntimeException("Database $dbName already exists; refusing to touch it.");
    $root->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $log("Created database $dbName");

    // Register with a placeholder user first to get the clinic id, which names its MySQL user (crm_t_<id>)
    $t = provisionRegister(['slug' => $slug, 'name' => $name, 'db_name' => $dbName, 'db_user' => 'pending', 'db_pass' => '', 'owner_email' => $email], $hosts);
    $user = tenantDbPrefix() . $t['id'];
    $pass = provisionDbUser($root, $user, $dbName);
    platformDB()->prepare("UPDATE tenants SET db_user = ?, db_pass_enc = ?, legal_name = ?, trn = ?, dha_license = ? WHERE id = ?")
        ->execute([$user, masterSeal($pass), ($in['legal_name'] ?? '') ?: null, ($in['trn'] ?? '') ?: null, ($in['dha_license'] ?? '') ?: null, $t['id']]);
    $previous = $GLOBALS['__tenant'] ?? null;
    tenantUse(tenantBySlug($slug));
    $log("Clinic #{$t['id']} registered at " . implode(', ', $hosts) . " (database user $user)");
    try {
        // Everything from here runs as the clinic's own user, so a missing grant shows up now rather than in production
        $db = dbConnect($dbName, $user, $pass, currentTenant()['db_host']);
        $n = migrateApply($db, __DIR__ . '/../migrations/tenant', fn($l) => $log("  $l"));
        $log("Schema ready ($n migrations).");
        $db->prepare("REPLACE INTO integration_settings (k, v) VALUES ('brand.name', ?)")->execute([$name]);
        $db->prepare("INSERT INTO staff (name, email, password, role, is_owner) VALUES (?, ?, ?, 'admin', 1)")
           ->execute([$owner, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);   // unusable until they choose one
        $token = bin2hex(random_bytes(32));
        $db->prepare("INSERT INTO password_resets (token_hash, staff_id, expires_at) VALUES (?, ?, NOW() + INTERVAL 7 DAY)")->execute([hash('sha256', $token), (int)$db->lastInsertId()]);
        tenantStoragePath();
    } finally {
        if ($previous) tenantUse($previous); else unset($GLOBALS['__tenant']);
    }
    return [tenantBySlug($slug), $token];
}
