<?php
// tools/provision_tenant.php — adds a new clinic: its own database and MySQL user, the clinic schema (baseline + every
// migration), default rows only (never demo data), the owner's admin account, and a one-time link to choose a password.
//   php tools/provision_tenant.php --slug=demo --name="Demo Clinic" --owner-email=owner@example.ae --owner-name="Dr Owner"
//   optional: --domain=crm.democlinic.ae (an extra address; <slug>.PRODUCT_DOMAIN is always added and is the main one)
//             --trial (start the 14-day Growth trial, as the signup page does; without it the clinic has no plan limits)
// Refuses to touch an existing database or slug. Multi-clinic mode only. The signup page uses the same provisionTenant().
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/provision.php';

$a = getopt('', ['slug:', 'name:', 'owner-email:', 'owner-name:', 'domain:', 'trial']);
$slug = strtolower(trim($a['slug'] ?? ''));
try {
    [$t, $token] = provisionTenant(['slug' => $slug, 'name' => $a['name'] ?? '', 'owner_name' => $a['owner-name'] ?? '', 'owner_email' => $a['owner-email'] ?? '',
                                    'domain' => $a['domain'] ?? ''], fn($l) => print("$l\n"));
    if (isset($a['trial'])) {
        require_once __DIR__ . '/../includes/platform_billing.php';
        billingStartTrial($t['id'], ['name' => $t['name'], 'email' => strtolower(trim($a['owner-email'])), 'slug' => $t['slug'], 'trn' => '']);
        echo "Free trial started (" . TRIAL_DAYS . " days, Growth plan).\n";
    }
    echo "Owner account created. Send the owner this one-time link (valid 7 days) to choose a password:\n  https://{$t['primary_host']}/login.php?reset=$token\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Provisioning FAILED: ' . $e->getMessage() . "\n");
    appLog('Provisioning failed for ' . $slug . ': ' . $e->getMessage());
    exit(1);
}
