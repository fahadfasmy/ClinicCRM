<?php
// includes/tenant.php — which clinic (tenant) this request or command belongs to.
// Two modes:
//   single-clinic: no PLATFORM_DB_NAME in the config. One clinic, its database settings straight from the config
//                  (today's Hostinger site). Everything below still works, so the code never needs two versions.
//   multi-clinic:  the web address (clinicslug.PRODUCT_DOMAIN, or a custom domain) is looked up in the platform
//                  database, which gives that clinic's database, MySQL user, storage folder and data key.
//                  Unknown address = plain 404. Command-line tools pick the clinic with --tenant=<slug>.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/crypto.php';

function multiTenant(): bool {
    return defined('PLATFORM_DB_NAME') && PLATFORM_DB_NAME !== '';
}

/** The current clinic: id, slug, name, status, db_host, db_name, db_user, db_pass, data_key_enc, primary_host. */
function currentTenant(): array {
    return $GLOBALS['__tenant'] ??= resolveTenant();
}

/** Selects the clinic for the rest of this command (CLI only; call before the first getDB()). */
function tenantUse(array $t): void {
    $GLOBALS['__tenant'] = $t;
}

function resolveTenant(): array {
    if (!multiTenant()) {
        return [
            'id' => defined('TENANT_ID') ? (int)TENANT_ID : 1, 'slug' => 'default', 'name' => '', 'status' => 'active',
            'db_host' => defined('DB_HOST') ? DB_HOST : 'localhost', 'db_name' => defined('DB_NAME') ? DB_NAME : '',
            'db_user' => defined('DB_USER') ? DB_USER : '', 'db_pass' => defined('DB_PASS') ? DB_PASS : '',
            'data_key_enc' => defined('DATA_KEY_ENC') ? DATA_KEY_ENC : null,
            'primary_host' => defined('APP_URL') ? (string)parse_url(APP_URL, PHP_URL_HOST) : '',
        ];
    }
    if (PHP_SAPI === 'cli') {
        // Local development only: tests and scripts default to one clinic (never set CLI_DEFAULT_TENANT in production)
        $slug = getenv('CRM_TENANT') ?: (defined('CLI_DEFAULT_TENANT') ? CLI_DEFAULT_TENANT : '');
        if ($slug !== '' && ($t = tenantBySlug($slug))) return $t;
        throw new RuntimeException('No clinic selected: run this with --tenant=<slug>.');
    }
    $t = tenantByHost(requestHost());
    if (!$t || $t['status'] === 'archived') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Not found');
    }
    return $t;
}

/** The host name of this request, lower-case, without the port. */
function requestHost(): string {
    return strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
}

const TENANT_SELECT = "SELECT t.*, (SELECT d.host FROM tenant_domains d WHERE d.tenant_id = t.id ORDER BY d.is_primary DESC, d.host LIMIT 1) AS primary_host FROM tenants t";

function tenantByHost(string $host): ?array {
    if ($host === '') return null;
    $key = 'crm_tenant_host:' . $host;
    // Cached 60 s when APCu is available; otherwise one indexed lookup per request
    if (function_exists('apcu_fetch') && ($row = apcu_fetch($key)) !== false) return $row ? tenantFromRow($row) : null;
    $s = platformDB()->prepare(TENANT_SELECT . " JOIN tenant_domains h ON h.tenant_id = t.id WHERE h.host = ?");
    $s->execute([$host]);
    $row = $s->fetch() ?: null;
    if (function_exists('apcu_store')) apcu_store($key, $row ?? 0, 60);
    return $row ? tenantFromRow($row) : null;
}

function tenantBySlug(string $slug): ?array {
    $s = platformDB()->prepare(TENANT_SELECT . " WHERE t.slug = ?");
    $s->execute([$slug]);
    $row = $s->fetch();
    return $row ? tenantFromRow($row) : null;
}

/** A platform row with the database password unsealed (the sealed copy never leaves this function). */
function tenantFromRow(array $r): array {
    return [
        'id' => (int)$r['id'], 'slug' => $r['slug'], 'name' => $r['name'], 'status' => $r['status'],
        'db_host' => $r['db_host'] ?: (defined('DB_HOST') ? DB_HOST : 'localhost'), 'db_name' => $r['db_name'],
        'db_user' => $r['db_user'], 'db_pass' => masterOpen($r['db_pass_enc']),
        'data_key_enc' => $r['data_key_enc'], 'primary_host' => (string)$r['primary_host'],
    ];
}

/** The shared platform database (clinics, addresses, later plans and billing). Never holds patient data. */
function platformDB(): PDO {
    static $pdo = null;
    if (!multiTenant()) throw new RuntimeException('There is no platform database in single-clinic mode.');
    return $pdo ??= dbConnect(PLATFORM_DB_NAME, defined('PLATFORM_DB_USER') ? PLATFORM_DB_USER : DB_USER,
        defined('PLATFORM_DB_PASS') ? PLATFORM_DB_PASS : DB_PASS, defined('PLATFORM_DB_HOST') ? PLATFORM_DB_HOST : DB_HOST);
}

/** This clinic's own folder for uploads and generated files (STORAGE_PATH/{tenant id}), created on first use. */
function tenantStoragePath(): string {
    $dir = rtrim(STORAGE_PATH, '/\\') . '/' . currentTenant()['id'];
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) throw new RuntimeException("Can't create the clinic's storage folder.");
    return $dir;
}

/** This clinic's data key, unwrapped with the master key. */
function tenantDataKey(): string {
    $enc = currentTenant()['data_key_enc'] ?? null;
    if (!$enc) throw new RuntimeException('This clinic has no data key yet (tools/register_existing_tenant.php or provisioning creates one).');
    return masterOpen($enc);
}

function tenantEncrypt(string $plain): string { return cryptoSeal($plain, tenantDataKey(), 't' . currentTenant()['id']); }

function tenantDecrypt(string $sealed): string {
    if (cryptoKeyId($sealed) !== 't' . currentTenant()['id']) throw new RuntimeException('This value belongs to another clinic.');
    return cryptoOpen($sealed, tenantDataKey());
}

/** The clinic's web address for links made outside a web request (cron, emails). */
function tenantHost(): string {
    return requestHost() ?: currentTenant()['primary_host'];
}

/**
 * A clinic profile setting (brand.*, clinic.*, meta.web_pixels …) from this clinic's integration_settings.
 * Edited on Admin → Clinic profile. Never put a clinic's details in code.
 */
function tenantSetting(string $k, string $default = ''): string {
    static $cache = null;
    if ($cache === null || $k === '__reload') {
        try { $cache = getDB()->query("SELECT k, v FROM integration_settings WHERE k LIKE 'brand.%' OR k LIKE 'clinic.%' OR k = 'meta.web_pixels'")->fetchAll(PDO::FETCH_KEY_PAIR); }
        catch (PDOException $e) { if ($e->getCode() !== '42S02') throw $e; $cache = []; }   // table not created yet
        if ($k === '__reload') return '';
    }
    $v = (string)($cache[$k] ?? '');
    return $v !== '' ? $v : $default;
}

/** The clinic's display name (falls back to the product name until the profile is filled in). */
function clinicName(): string {
    return tenantSetting('brand.name', 'Clinic CRM');
}

/**
 * Command-line tools: selects the clinic from --tenant=<slug> in multi-clinic mode (exits with a message when missing
 * or unknown). Does nothing in single-clinic mode.
 */
function cliTenant(): void {
    if (!multiTenant()) return;
    $slug = getopt('', ['tenant:'])['tenant'] ?? '';
    $slug = $slug !== '' ? $slug : (getenv('CRM_TENANT') ?: (defined('CLI_DEFAULT_TENANT') ? CLI_DEFAULT_TENANT : ''));   // local default
    if ($slug === '') { fwrite(STDERR, "Multi-clinic mode: add --tenant=<slug> (or use the tool's all-clinics form).\n"); exit(2); }
    $t = tenantBySlug($slug);
    if (!$t) { fwrite(STDERR, "No clinic with slug \"$slug\".\n"); exit(2); }
    tenantUse($t);
}

/**
 * Runs `php $script --tenant=<slug> …$args` once per clinic that is in use, one after another, in its own process (so
 * nothing leaks between clinics). One clinic failing never stops the others. Returns how many failed.
 */
function cliForEachTenant(string $script, array $args = []): int {
    $slugs = platformDB()->query("SELECT slug FROM tenants WHERE status NOT IN ('suspended','cancelled','archived') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $failed = 0;
    foreach ($slugs as $slug) {
        echo "== $slug ==\n";
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg("--tenant=$slug");
        foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
        passthru($cmd, $code);
        if ($code !== 0) { $failed++; appLog(basename($script) . " failed for clinic $slug (exit $code)"); }
    }
    return $failed;
}

/**
 * The clinic's brand colour (brand.primary_color, #rrggbb) laid over the gold theme tokens in app.css. Empty when not
 * set, so the clinic keeps the default gold.
 */
function brandStyleTag(): string {
    $c = tenantSetting('brand.primary_color');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $c)) return '';
    $mix = fn(int $pct, string $with) => "color-mix(in srgb, $c $pct%, $with)";
    return "<style>:root{--gold:$c;--gold-hover:{$mix(88, '#000')};--gold-dark:{$mix(72, '#000')};--gold-light:{$mix(14, '#fff')};--gold-muted:{$mix(34, '#fff')};--gold-ring:{$mix(22, 'transparent')}}"
         . "[data-theme=dark]{--gold:$c;--gold-hover:{$mix(85, '#fff')};--gold-dark:{$mix(70, '#fff')};--gold-light:{$mix(16, 'transparent')};--gold-muted:{$mix(34, 'transparent')};--gold-ring:{$mix(32, 'transparent')}}</style>";
}

// Every web request resolves its clinic first, so an unknown address is a plain 404 on every page and endpoint
// (pages on the platform's own address — signup, back office, Stripe webhook — define PLATFORM_REQUEST first: they belong to no clinic)
if (PHP_SAPI !== 'cli' && !defined('PLATFORM_REQUEST')) currentTenant();
