<?php
// Phase 15 (pure parts): plan limits and modules (with an upgrade message, nothing lost), the read-only rule (trial
// over, cancelled, read-only support session), Stripe webhook signatures (right, wrong, old), authenticator codes
// (RFC 6238 test vector), support tickets (signed, one minute, once). The full flow with Stripe's test clock, signup,
// and support sessions runs through the real pages in tests/integration/saas_suite.php.
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/platform_auth.php';

function saasDb(): PDO {
    static $done = false;
    $db = testDb('clinic_crm_test_saas', !$done);
    if (!$done) {
        migrateApply($db, __DIR__ . '/../migrations/tenant', fn($l) => null);
        $db->exec("INSERT INTO staff (id, name, email, password, role) VALUES (951, 'Owner', 'o.s@example.test', 'x', 'admin'), (952, 'Desk', 'd.s@example.test', 'x', 'receptionist')");
        $done = true;
    }
    $_SESSION['staff_id'] = 951; $_SESSION['role'] = 'admin'; unset($_SESSION['support']);
    return $db;
}
function starterPlan(): array {
    return ['id' => 1, 'code' => 'starter', 'name' => 'Starter', 'limits' => ['branches' => 1, 'practitioners' => 3, 'staff_seats' => 2, 'storage_gb' => 5, 'messages_per_month' => 500, 'layouts' => 3],
            'features' => ['insurance' => false, 'broadcasts' => false, 'automations' => false, 'online_booking' => false]];
}
function onPlan(?array $plan, string $status = 'active'): void { $GLOBALS['__test_plan'] = $plan; $GLOBALS['__test_tenant_record'] = ['status' => $status, 'plan_id' => $plan ? 1 : null, 'trial_ends_at' => null, 'read_only_since' => null, 'cancelled_at' => null]; }
function offPlan(): void { unset($GLOBALS['__test_plan'], $GLOBALS['__test_tenant_record'], $_SESSION['support']); }

function test_limits_refuse_with_an_upgrade_message() {
    $db = saasDb();
    onPlan(starterPlan());
    check(planLimit('branches') === 1 && planLimit('nope') === null, 'the plan’s limits');
    checkThrows(fn() => planCheck($db, 'branches'), 'Starter plan includes 1 branch', 'a second branch is refused, saying which plan and what to do');
    check(planUsage($db, 'staff_seats') === 2, 'two staff seats in use');
    checkThrows(fn() => planCheck($db, 'staff_seats'), 'Upgrade on the Subscription page', 'a third seat is refused');
    $db->exec("INSERT INTO staff (name, email, password, role, is_support) VALUES ('Support · X', 'sx@support.invalid', '!', 'admin', 1)");
    check(planUsage($db, 'staff_seats') === 2, 'support accounts don’t take a seat');
    planCheck($db, 'layouts');   // 0 of 3
    check(true, 'under the limit nothing happens');
    onPlan(null);
    planCheck($db, 'branches', 50);
    check(planLimit('branches') === null && planFeature('insurance'), 'no plan (the first clinic, comp accounts): no limits, every module');
    offPlan();
}

function test_modules_outside_the_plan_are_off_for_everyone() {
    saasDb();
    onPlan(starterPlan());
    check(!can('insurance.view') && !can('insurance.claims') && !can('marketing.broadcasts'), 'insurance and broadcasts are off, even for an admin');
    check(can('patients.view') && can('billing.view'), 'everything else is untouched');
    onPlan(['features' => ['insurance' => true, 'broadcasts' => true]] + starterPlan());
    check(can('insurance.view') && can('marketing.broadcasts'), 'on a plan that includes them they are back');
    offPlan();
}

function test_read_only_rule() {
    saasDb();
    foreach (['trial' => true, 'active' => true, 'past_due' => true, 'read_only' => false, 'suspended' => false, 'cancelled' => false] as $st => $want) {
        onPlan(null, $st);
        check(tenantWritable() === $want, "$st: " . ($want ? 'can change things' : 'read-only'));
    }
    onPlan(null, 'active');
    $_SESSION['support'] = ['grant_id' => 1, 'mode' => 'read_only', 'admin_name' => 'S', 'expires_at' => '2030-01-01 00:00:00'];
    check(!tenantWritable() && str_contains(tenantReadOnlyReason(), 'support'), 'a read-only support session can’t change anything');
    $_SESSION['support']['mode'] = 'read_write';
    check(tenantWritable(), 'one the clinic allowed to make changes can');
    check(in_array('subscription.php', READ_ONLY_ALLOWED, true) && in_array('export_all.php', READ_ONLY_ALLOWED, true) && in_array('logout.php', READ_ONLY_ALLOWED, true), 'billing, export and sign-out stay open when read-only');
    offPlan();
}

function test_stripe_webhook_signatures() {
    $payload = '{"id":"evt_1","type":"invoice.paid"}';
    $t = 1792400000;
    $sig = 't=' . $t . ',v1=' . hash_hmac('sha256', "$t.$payload", 'whsec_test');
    check(stripeVerifySignature($payload, $sig, 'whsec_test', 300, $t + 10), 'a correctly signed event is accepted');
    check(!stripeVerifySignature($payload, $sig, 'whsec_other', 300, $t + 10), 'signed with another secret: refused');
    check(!stripeVerifySignature($payload . ' ', $sig, 'whsec_test', 300, $t + 10), 'a changed payload: refused');
    check(!stripeVerifySignature($payload, $sig, 'whsec_test', 300, $t + 301), 'older than 5 minutes (a replay): refused');
    check(stripeVerifySignature($payload, 't=' . $t . ',v1=' . str_repeat('0', 64) . ',v1=' . substr($sig, strpos($sig, 'v1=') + 3), 'whsec_test', 300, $t), 'any of several signatures (secret rotation) is enough');
    check(!stripeVerifySignature($payload, '', 'whsec_test') && !stripeVerifySignature($payload, $sig, ''), 'no header or no secret: refused');
}

function test_authenticator_codes() {
    // RFC 6238 test vector: secret "12345678901234567890" (base32 GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ), time 59 → 94287082 (last 6: 287082)
    $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    check(totpCode($secret, intdiv(59, 30)) === '287082', 'matches the RFC 6238 test vector');
    check(totpVerify($secret, '287082', 59) && totpVerify($secret, '287 082', 75), 'accepted now and one step later (clock drift), spaces ignored');
    check(!totpVerify($secret, '287082', 59 + 120) && !totpVerify($secret, '12345', 59), 'not two minutes later, not a short code');
    check(strlen(totpSecret()) === 32 && totpSecret() !== totpSecret(), 'new secrets are random, 160 bits');
}

function test_support_tickets_are_signed_short_and_single_use() {
    $t = supportTicket(7, 3);
    check(supportTicketOpen($t) === [7, 3], 'a fresh ticket opens');
    check(supportTicketOpen($t) === null, 'the same ticket again: refused');
    $p = explode('.', supportTicket(7, 3)); $p[0] = '8';
    check(supportTicketOpen(implode('.', $p)) === null, 'a ticket changed to another grant: refused');
    [$g, $a, , $n] = explode('.', supportTicket(7, 3)); $exp = time() - 1;
    check(supportTicketOpen("$g.$a.$exp.$n." . hash_hmac('sha256', "support|$g.$a.$exp.$n", masterKey()[1])) === null, 'an expired ticket: refused');
    platformDB()->exec("DELETE FROM webhook_events WHERE provider = 'support'");
}
