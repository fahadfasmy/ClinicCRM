<?php
// tools/platform_admin.php — adds a platform back-office person (run on the server; there is no sign-up for them):
//   php tools/platform_admin.php --email=you@company.ae --name="Your Name" --role=owner|support|billing
// Prints a one-time password once; at the first sign-in at https://PLATFORM_HOST/platform/ they set up an authenticator
// app (2-step is mandatory). --reset gives an existing person a new password and makes them set up 2-step again.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/platform_billing.php';

$a = getopt('', ['email:', 'name:', 'role:', 'reset']);
$email = strtolower(trim($a['email'] ?? ''));
$role = $a['role'] ?? 'support';
try {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Give --email.');
    if (!in_array($role, ['owner', 'support', 'billing'], true)) throw new RuntimeException('--role is owner, support or billing.');
    $pw = rtrim(strtr(base64_encode(random_bytes(15)), '+/', 'Kq'), '=');
    $p = platformDB();
    if (isset($a['reset'])) {
        $n = $p->prepare("UPDATE platform_admins SET password = ?, totp_secret_enc = NULL WHERE email = ?");
        $n->execute([password_hash($pw, PASSWORD_DEFAULT), $email]);
        if (!$n->rowCount()) throw new RuntimeException('No such platform admin.');
        platformAudit(null, null, 'platform_admin_reset', ['email' => $email]);
    } else {
        if (trim($a['name'] ?? '') === '') throw new RuntimeException('Give --name.');
        $p->prepare("INSERT INTO platform_admins (email, name, password, role) VALUES (?,?,?,?)")->execute([$email, trim($a['name']), password_hash($pw, PASSWORD_DEFAULT), $role]);
        platformAudit(null, null, 'platform_admin_added', ['email' => $email, 'role' => $role]);
    }
    echo "One-time password for $email (shown once; change your authenticator at first sign-in):\n  $pw\nSign in at " . platformUrl('/platform/') . "\n";
} catch (Throwable $e) { fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n"); exit(1); }
