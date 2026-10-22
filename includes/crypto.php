<?php
// includes/crypto.php — AES-256-GCM sealing with key ids, so keys can be rotated without guessing which one a value used.
// Two layers: the MASTER_KEY (config file, ideally the cloud provider's UAE key service later) only wraps each clinic's
// own data key; the clinic's data key encrypts that clinic's secrets (DB password, Emirates ID, tokens).
// Sealed format: "<key id>:" . base64(12-byte nonce . 16-byte tag . ciphertext)
require_once __DIR__ . '/config.php';

function cryptoSeal(string $plain, string $key, string $keyId): string {
    if (strlen($key) !== 32) throw new RuntimeException('Encryption key must be 32 bytes.');
    $iv = random_bytes(12);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $keyId);   // key id is authenticated too
    if ($ct === false) throw new RuntimeException('Encryption failed.');
    return $keyId . ':' . base64_encode($iv . $tag . $ct);
}

/** The key id a sealed value was made with (to pick the key, or to find values that still need re-wrapping). */
function cryptoKeyId(string $sealed): string {
    return strstr($sealed, ':', true) ?: '';
}

function cryptoOpen(string $sealed, string $key): string {
    $keyId = cryptoKeyId($sealed);
    $raw = base64_decode(substr($sealed, strlen($keyId) + 1), true);
    if ($keyId === '' || $raw === false || strlen($raw) < 28) throw new RuntimeException('Not an encrypted value.');
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $keyId);
    if ($plain === false) throw new RuntimeException('Decryption failed (wrong key, or the value was changed).');
    return $plain;
}

/** The master key from the config file (MASTER_KEY = base64 of 32 random bytes; MASTER_KEY_ID names it, default k1). */
function masterKey(): array {
    if (!defined('MASTER_KEY')) throw new RuntimeException('MASTER_KEY is not set in the config file (see config.example.php).');
    $k = base64_decode(MASTER_KEY, true);
    if ($k === false || strlen($k) !== 32) throw new RuntimeException('MASTER_KEY must be base64 of exactly 32 bytes.');
    return [defined('MASTER_KEY_ID') ? MASTER_KEY_ID : 'k1', $k];
}

function masterSeal(string $plain): string { [$id, $k] = masterKey(); return cryptoSeal($plain, $k, $id); }

function masterOpen(string $sealed): string {
    [$id, $k] = masterKey();
    // ponytail: one master key at a time; when rotating, add MASTER_KEY_OLD + a re-wrap tool and look the key up by id here
    if (cryptoKeyId($sealed) !== $id) throw new RuntimeException('Sealed with master key "' . cryptoKeyId($sealed) . "\", but the config has \"$id\".");
    return cryptoOpen($sealed, $k);
}

/** A new random data key for a clinic, already wrapped with the master key (store this in tenants.data_key_enc). */
function newWrappedDataKey(): string { return masterSeal(random_bytes(32)); }
