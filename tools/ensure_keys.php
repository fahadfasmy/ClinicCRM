<?php
// tools/ensure_keys.php — single-clinic mode (today's Hostinger site): adds the encryption keys to crm_config.php when
// they are missing, so Emirates ID and passport numbers can be stored encrypted. Nobody ever sees the keys: they are
// generated here and written straight into the config file (a dated copy of the old file is kept next to it).
//   MASTER_KEY   — 32 random bytes; wraps the clinic's data key
//   DATA_KEY_ENC — the clinic's own data key, sealed with the master key
// When both exist it only checks that they work. Run by tools/run_migrations.sh. Keep a copy of crm_config.php somewhere
// safe: without these keys the encrypted numbers can't be read.
// Shared hosting often disables starting another PHP process, so every check here runs in this process.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/tenant.php';

// --check: encrypt and decrypt with the keys as configured
if (in_array('--check', $argv, true)) { echo tenantDecrypt(tenantEncrypt('ok')); exit(0); }
if (multiTenant()) { echo "Multi-clinic mode: keys come from provisioning, nothing to do.\n"; exit(0); }
if (defined('MASTER_KEY') && defined('DATA_KEY_ENC')) {
    try { $ok = tenantDecrypt(tenantEncrypt('ok')) === 'ok'; } catch (Throwable $e) { $ok = false; }
    if ($ok) { echo "Encryption keys: already set and working.\n"; exit(0); }
    fwrite(STDERR, "Encryption keys are in the config file but don't work. Put back the copy kept next to it (crm_config.php.before-keys-…).\n");
    exit(1);
}
$file = defined('CRM_CONFIG_FILE') ? CRM_CONFIG_FILE : '';
if ($file === '' || !is_file($file) || !is_writable($file)) { fwrite(STDERR, "No writable config file found.\n"); exit(1); }

try {
    [$keyId, $master] = defined('MASTER_KEY') ? masterKey() : ['k1', random_bytes(32)];   // an existing master key is kept
    $dataKey = random_bytes(32);
    $sealed = cryptoSeal($dataKey, $master, $keyId);
    $lines = "\n// Encryption keys, added by tools/ensure_keys.php on " . date('Y-m-d') . ". Never share them; keep a copy of this file safe.\n";
    if (!defined('MASTER_KEY')) $lines .= "define('MASTER_KEY', '" . base64_encode($master) . "');\ndefine('MASTER_KEY_ID', 'k1');\n";
    $lines .= "define('DATA_KEY_ENC', '" . $sealed . "');\n";
    $old = (string)file_get_contents($file);
    $new = preg_match('/\?>\s*$/', $old) ? preg_replace('/\?>\s*$/', rtrim($lines) . "\n?>\n", $old) : rtrim($old) . "\n" . $lines;

    // Self-test before anything is written: the new file must parse, and the data key must come back out of its seal
    token_get_all($new, TOKEN_PARSE);   // throws ParseError on a broken file
    if (cryptoOpen($sealed, $master) !== $dataKey || cryptoOpen(cryptoSeal('ok', $dataKey, 't1'), $dataKey) !== 'ok')
        throw new RuntimeException('The new keys failed their self-test; nothing was changed.');

    $backup = $file . '.before-keys-' . date('Ymd-His');
    if (!copy($file, $backup)) throw new RuntimeException('Could not keep a copy of the config file; nothing was changed.');
    chmod($backup, 0600);
    if (file_put_contents($file, $new, LOCK_EX) === false) { copy($backup, $file); throw new RuntimeException('Could not write the config file; it was put back as it was.'); }
    echo "Encryption keys: added to " . basename($file) . " and tested (previous version kept as " . basename($backup) . ").\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Encryption keys FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
