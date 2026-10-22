<?php
// includes/platform_billing.php — clinics paying for the platform (Stripe Billing in AED). Only business data about the
// clinic goes to Stripe (clinic name, owner email, TRN, plan); never patient data.
//   billingStartTrial()      at signup: Stripe customer + a 14-day Growth trial that pauses if no card is added
//   billingCheckoutUrl()     "Choose a plan": Stripe Checkout (the clinic's TRN collected as a tax id)
//   billingPortalUrl()       card, invoices, plan change, cancel: the Stripe Customer Portal
//   stripeHandleEvent()      webhooks (pages/api_stripe.php): stored once in webhook_events, then the clinic's status
//   billingSweep()           cron: trial over → read-only; 7-day grace over → read-only; cancelled 90 days → archived
//   trialEmails()            cron: reminders 7, 3 and 1 days before the trial ends
// Config (crm_config.php): STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET, optional STRIPE_API_BASE, STRIPE_TEST_CLOCKS (test
// mode: each new clinic gets its own test clock), PLATFORM_HOST, PLATFORM_MAIL_* (the platform's own mailbox).
require_once __DIR__ . '/db.php';        // dbConnect, platformDB
require_once __DIR__ . '/data_flows.php';
require_once __DIR__ . '/mailer.php';

const TRIAL_DAYS = 14;
const GRACE_DAYS = 7;
const EXPORT_DAYS_AFTER_CANCEL = 90;

function stripeConfigured(): bool { return defined('STRIPE_SECRET_KEY') && STRIPE_SECRET_KEY !== ''; }
function platformHost(): string { return defined('PLATFORM_HOST') ? PLATFORM_HOST : 'app.' . (defined('PRODUCT_DOMAIN') ? PRODUCT_DOMAIN : 'localhost'); }
function platformUrl(string $path = ''): string { return (APP_ENV === 'local' ? 'http://' : 'https://') . platformHost() . $path; }
function tenantUrl(array $t, string $path = ''): string { return (APP_ENV === 'local' ? 'http://' : 'https://') . $t['primary_host'] . $path; }

/** One Stripe API call (form-encoded, as Stripe expects). Throws with Stripe's message on failure. */
function stripeApi(string $method, string $path, array $params = []): array {
    if (!stripeConfigured()) throw new RuntimeException('Stripe is not set up (STRIPE_SECRET_KEY in the config).');
    $url = rtrim(defined('STRIPE_API_BASE') ? STRIPE_API_BASE : 'https://api.stripe.com', '/') . '/' . ltrim($path, '/');
    outboundGuard($url);
    $ch = curl_init($method === 'GET' && $params ? $url . '?' . http_build_query($params) : $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . STRIPE_SECRET_KEY, 'Stripe-Version: 2024-06-20']]);
    if ($method !== 'GET' && $params) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($body === false) throw new RuntimeException('Could not reach Stripe: ' . $err);
    $j = json_decode((string)$body, true);
    if ($code >= 400 || !is_array($j)) throw new RuntimeException('Stripe: ' . ($j['error']['message'] ?? "HTTP $code"));
    return $j;
}

/** Verifies a Stripe webhook signature (Stripe-Signature: t=…,v1=…; HMAC-SHA256 of "t.payload"), within 5 minutes. */
function stripeVerifySignature(string $payload, string $header, string $secret, int $tolerance = 300, ?int $now = null): bool {
    if ($secret === '' || !preg_match('/(?:^|,)t=(\d+)/', $header, $t)) return false;
    if (abs(($now ?? time()) - (int)$t[1]) > $tolerance) return false;
    $want = hash_hmac('sha256', $t[1] . '.' . $payload, $secret);
    preg_match_all('/(?:^|,)v1=([a-f0-9]{64})/', $header, $sigs);
    foreach ($sigs[1] as $s) if (hash_equals($want, $s)) return true;
    return false;
}

function planByCode(string $code): ?array { $s = platformDB()->prepare("SELECT * FROM plans WHERE code = ? AND is_active = 1"); $s->execute([$code]); return $s->fetch() ?: null; }
function planByPrice(string $price): ?array { $s = platformDB()->prepare("SELECT * FROM plans WHERE stripe_price_month = ? OR stripe_price_year = ?"); $s->execute([$price, $price]); return $s->fetch() ?: null; }
function tenantSubscription(int $tid): array { $s = platformDB()->prepare("SELECT * FROM subscriptions WHERE tenant_id = ?"); $s->execute([$tid]); return $s->fetch() ?: []; }

/** Changes a clinic's status (with the timestamps the lifecycle needs) and writes the platform audit log. */
function tenantSetStatus(int $tid, string $status, string $why, ?int $adminId = null): void {
    $p = platformDB();
    $old = $p->query("SELECT status FROM tenants WHERE id = $tid")->fetchColumn();
    if ($old === false || $old === $status) return;
    $p->prepare("UPDATE tenants SET status = ?, read_only_since = CASE WHEN ? IN ('read_only','cancelled','suspended') THEN COALESCE(read_only_since, NOW()) ELSE NULL END,
                 cancelled_at = CASE WHEN ? = 'cancelled' THEN COALESCE(cancelled_at, NOW()) WHEN ? IN ('active','trial') THEN NULL ELSE cancelled_at END WHERE id = ?")
      ->execute([$status, $status, $status, $status, $tid]);
    platformAudit($adminId, $tid, 'status_' . $status, ['from' => $old, 'why' => $why]);
    if (function_exists('apcu_delete')) foreach ($p->query("SELECT host FROM tenant_domains WHERE tenant_id = $tid")->fetchAll(PDO::FETCH_COLUMN) as $h) apcu_delete('crm_tenant_host:' . $h);
}

function platformAudit(?int $adminId, ?int $tid, string $action, array $detail = []): void {
    platformDB()->prepare("INSERT INTO platform_audit_log (admin_id, tenant_id, action, detail, ip) VALUES (?,?,?,?,?)")
        ->execute([$adminId, $tid, $action, json_encode($detail, JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR'] ?? null]);
}

/** At signup: the Growth trial (14 days). With Stripe set up, a customer and a trial subscription that pauses without a card. */
function billingStartTrial(int $tid, array $info): void {
    $p = platformDB();
    $plan = planByCode('growth');
    $ends = date('Y-m-d H:i:s', time() + TRIAL_DAYS * 86400);
    $p->prepare("UPDATE tenants SET status = 'trial', plan_id = ?, trial_ends_at = ? WHERE id = ?")->execute([$plan['id'] ?? null, $ends, $tid]);
    $p->prepare("INSERT INTO subscriptions (tenant_id, status) VALUES (?, 'trialing') ON DUPLICATE KEY UPDATE status = 'trialing'")->execute([$tid]);
    if (!stripeConfigured()) return;
    $c = ['name' => $info['name'], 'email' => $info['email'], 'metadata' => ['tenant_id' => $tid, 'slug' => $info['slug']]];
    if (!empty($info['trn'])) $c['tax_id_data'] = [['type' => 'ae_trn', 'value' => $info['trn']]];
    if (defined('STRIPE_TEST_CLOCKS') && STRIPE_TEST_CLOCKS) $c['test_clock'] = stripeApi('POST', '/v1/test_helpers/test_clocks', ['frozen_time' => time(), 'name' => 'tenant ' . $tid])['id'];
    $cus = stripeApi('POST', '/v1/customers', $c);
    $sub = ['customer' => $cus['id'], 'trial_period_days' => TRIAL_DAYS, 'trial_settings' => ['end_behavior' => ['missing_payment_method' => 'pause']], 'metadata' => ['tenant_id' => $tid]];
    if (!empty($plan['stripe_price_month'])) $sub['items'] = [['price' => $plan['stripe_price_month']]];
    $s = stripeApi('POST', '/v1/subscriptions', $sub);
    $p->prepare("UPDATE subscriptions SET stripe_customer_id = ?, stripe_subscription_id = ?, `interval` = 'month' WHERE tenant_id = ?")->execute([$cus['id'], $s['id'], $tid]);
}

/** "Choose a plan": a Stripe Checkout page for this clinic. */
function billingCheckoutUrl(int $tid, string $planCode, string $interval): string {
    $plan = planByCode($planCode) ?? throw new RuntimeException('Choose one of the plans.');
    $interval = $interval === 'year' ? 'year' : 'month';
    $price = $plan[$interval === 'year' ? 'stripe_price_year' : 'stripe_price_month'] ?? '';
    if ($price === '') throw new RuntimeException('This plan can’t be bought online yet. Please contact us.');
    $sub = tenantSubscription($tid);
    if (empty($sub['stripe_customer_id'])) throw new RuntimeException('Billing isn’t set up for this clinic yet. Please contact us.');
    $host = (string)platformDB()->query("SELECT host FROM tenant_domains WHERE tenant_id = $tid ORDER BY is_primary DESC LIMIT 1")->fetchColumn();
    $back = tenantUrl(['primary_host' => $host], '/pages/subscription.php');
    $amount = (int)round((float)$plan[$interval === 'year' ? 'price_year_aed' : 'price_month_aed'] * 100);
    return stripeApi('POST', '/v1/checkout/sessions', [
        'mode' => 'subscription', 'customer' => $sub['stripe_customer_id'], 'line_items' => [['price' => $price, 'quantity' => 1]],
        'success_url' => $back . '?paid=1', 'cancel_url' => $back, 'tax_id_collection' => ['enabled' => 'true'], 'customer_update' => ['name' => 'auto'],
        'metadata' => ['tenant_id' => $tid, 'plan' => $plan['code'], 'interval' => $interval, 'amount' => $amount],
        'subscription_data' => ['metadata' => ['tenant_id' => $tid, 'plan' => $plan['code']]],
    ])['url'];
}

/** The Stripe Customer Portal: card, invoices, plan change and cancel. */
function billingPortalUrl(int $tid, string $returnUrl): string {
    $sub = tenantSubscription($tid);
    if (empty($sub['stripe_customer_id'])) throw new RuntimeException('Billing isn’t set up for this clinic yet.');
    return stripeApi('POST', '/v1/billing_portal/sessions', ['customer' => $sub['stripe_customer_id'], 'return_url' => $returnUrl])['url'];
}

/** The clinic a Stripe object belongs to (by our metadata, else by customer), or 0. */
function stripeTenantOf(array $o): int {
    $p = platformDB();
    $cus = (string)($o['customer'] ?? '');
    $byCustomer = $cus !== '' ? (int)$p->query("SELECT tenant_id FROM subscriptions WHERE stripe_customer_id = " . $p->quote($cus))->fetchColumn() : 0;
    $meta = (int)($o['metadata']['tenant_id'] ?? 0);
    if ($meta && $byCustomer && $meta !== $byCustomer) return 0;   // metadata and customer disagree: trust neither
    return $byCustomer ?: 0;
}

/**
 * Handles one Stripe event. Stored once in webhook_events (a repeat delivery is acknowledged and ignored), then applied:
 * the clinic's status follows its subscription. Returns what happened, for the log.
 */
function stripeHandleEvent(array $ev): string {
    $p = platformDB();
    $ins = $p->prepare("INSERT IGNORE INTO webhook_events (provider, event_id, type, payload) VALUES ('stripe', ?, ?, ?)");
    $ins->execute([$ev['id'], $ev['type'], json_encode($ev)]);
    if (!$ins->rowCount()) {   // seen before: done = ignore; failed half-way = try again (Stripe retries after an error)
        $d = $p->prepare("SELECT processed_at FROM webhook_events WHERE provider = 'stripe' AND event_id = ?"); $d->execute([$ev['id']]);
        if ($d->fetchColumn()) return 'duplicate';
    }
    $o = $ev['data']['object'] ?? [];
    $tid = stripeTenantOf($o);
    $result = 'ignored';
    if ($tid) {
        $sub = tenantSubscription($tid);
        switch ($ev['type']) {
            case 'checkout.session.completed':
                $plan = planByCode((string)($o['metadata']['plan'] ?? ''));
                $old = $sub['stripe_subscription_id'] ?? null;
                $p->prepare("UPDATE subscriptions SET stripe_subscription_id = ?, status = 'active', `interval` = ?, grace_until = NULL WHERE tenant_id = ?")
                  ->execute([$o['subscription'] ?? null, ($o['metadata']['interval'] ?? 'month') === 'year' ? 'year' : 'month', $tid]);
                if ($plan) $p->prepare("UPDATE tenants SET plan_id = ? WHERE id = ?")->execute([$plan['id'], $tid]);
                tenantSetStatus($tid, 'active', 'paid by card (Stripe Checkout)');
                if ($old && $old !== ($o['subscription'] ?? null)) { try { stripeApi('DELETE', "/v1/subscriptions/$old"); } catch (Throwable $e) { appLog("Old trial subscription $old not cancelled: " . $e->getMessage()); } }
                $result = 'activated';
                break;
            case 'customer.subscription.created':
            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                if (!empty($sub['stripe_subscription_id']) && $sub['stripe_subscription_id'] !== ($o['id'] ?? '')) { $result = 'other subscription'; break; }
                $status = $ev['type'] === 'customer.subscription.deleted' ? 'canceled' : (string)($o['status'] ?? '');
                $p->prepare("UPDATE subscriptions SET stripe_subscription_id = COALESCE(stripe_subscription_id, ?), status = ?, current_period_end = ?, cancel_at_period_end = ? WHERE tenant_id = ?")
                  ->execute([$o['id'] ?? null, $status, isset($o['current_period_end']) ? date('Y-m-d H:i:s', (int)$o['current_period_end']) : null, !empty($o['cancel_at_period_end']) ? 1 : 0, $tid]);
                if (!empty($o['items']['data'][0]['price']['id']) && ($plan = planByPrice($o['items']['data'][0]['price']['id']))) $p->prepare("UPDATE tenants SET plan_id = ? WHERE id = ?")->execute([$plan['id'], $tid]);
                $map = ['active' => 'active', 'trialing' => 'trial', 'past_due' => 'past_due', 'paused' => 'read_only', 'unpaid' => 'read_only', 'incomplete_expired' => 'read_only', 'canceled' => 'cancelled'];
                if (isset($map[$status])) {
                    if ($status === 'past_due') billingStartGrace($tid);
                    tenantSetStatus($tid, $map[$status], "Stripe subscription $status");
                }
                $result = "subscription $status";
                break;
            case 'invoice.paid':
                $p->prepare("UPDATE subscriptions SET grace_until = NULL WHERE tenant_id = ?")->execute([$tid]);
                $cur = $p->query("SELECT status FROM tenants WHERE id = $tid")->fetchColumn();
                if (in_array($cur, ['past_due', 'read_only', 'trial'], true)) tenantSetStatus($tid, 'active', 'invoice paid');
                $result = 'paid';
                break;
            case 'invoice.payment_failed':
                billingStartGrace($tid);
                tenantSetStatus($tid, 'past_due', 'payment failed');
                $result = 'payment failed';
                break;
            case 'customer.subscription.trial_will_end':
                $result = 'trial ending (emails come from trialEmails)';
                break;
        }
    }
    $p->prepare("UPDATE webhook_events SET processed_at = NOW(), error = ? WHERE provider = 'stripe' AND event_id = ?")->execute([$tid ? null : 'no matching clinic', $ev['id']]);
    return $result;
}

function billingStartGrace(int $tid): void {
    platformDB()->prepare("UPDATE subscriptions SET grace_until = COALESCE(grace_until, NOW() + INTERVAL " . GRACE_DAYS . " DAY), status = 'past_due' WHERE tenant_id = ?")->execute([$tid]);
}

/** Cron: the dates that change a clinic's status even without a webhook. Returns counts. */
function billingSweep(): array {
    $p = platformDB(); $n = ['trial_ended' => 0, 'grace_over' => 0, 'archived' => 0];
    foreach ($p->query("SELECT id FROM tenants WHERE status = 'trial' AND trial_ends_at < NOW()")->fetchAll(PDO::FETCH_COLUMN) as $id) { tenantSetStatus((int)$id, 'read_only', 'trial ended without a plan'); $n['trial_ended']++; }
    foreach ($p->query("SELECT t.id FROM tenants t JOIN subscriptions s ON s.tenant_id = t.id WHERE t.status = 'past_due' AND s.grace_until < NOW()")->fetchAll(PDO::FETCH_COLUMN) as $id) { tenantSetStatus((int)$id, 'read_only', GRACE_DAYS . '-day grace period over'); $n['grace_over']++; }
    foreach ($p->query("SELECT id FROM tenants WHERE status = 'cancelled' AND cancelled_at < NOW() - INTERVAL " . EXPORT_DAYS_AFTER_CANCEL . " DAY")->fetchAll(PDO::FETCH_COLUMN) as $id) { tenantSetStatus((int)$id, 'archived', EXPORT_DAYS_AFTER_CANCEL . ' days after cancelling (data kept, encrypted)'); $n['archived']++; }
    return $n;
}

/** The platform's own mailbox (signup, trial and billing emails; never patient data). */
function platformMail(string $to, string $subject, string $html): void {
    if (!defined('PLATFORM_MAIL_HOST')) throw new RuntimeException('The platform mailbox is not set up (PLATFORM_MAIL_* in the config).');
    $text = trim(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/li|\/h\d)\b[^>]*>/i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    smtpSendWith(['host' => PLATFORM_MAIL_HOST, 'port' => defined('PLATFORM_MAIL_PORT') ? PLATFORM_MAIL_PORT : 587, 'security' => defined('PLATFORM_MAIL_SECURITY') ? PLATFORM_MAIL_SECURITY : 'tls',
        'from_email' => PLATFORM_MAIL_FROM, 'from_name' => defined('PLATFORM_MAIL_FROM_NAME') ? PLATFORM_MAIL_FROM_NAME : 'Clinic CRM',
        'username' => defined('PLATFORM_MAIL_USER') ? PLATFORM_MAIL_USER : '', 'password' => defined('PLATFORM_MAIL_PASS') ? PLATFORM_MAIL_PASS : '', 'reply_to' => ''], $to, $subject, $html, $text);
}

/** Cron: trial reminders 7, 3 and 1 days before the end, once each, to the owner. Returns how many were sent. */
function trialEmails(): int {
    $p = platformDB(); $sent = 0;
    $rows = $p->query("SELECT t.id, t.name, t.owner_email, t.trial_ends_at, (SELECT host FROM tenant_domains d WHERE d.tenant_id = t.id ORDER BY is_primary DESC LIMIT 1) AS host
                       FROM tenants t WHERE t.status = 'trial' AND t.trial_ends_at > NOW() AND t.owner_email IS NOT NULL")->fetchAll();
    foreach ($rows as $t) {
        $left = (int)ceil((strtotime($t['trial_ends_at']) - time()) / 86400);
        foreach ([1, 3, 7] as $d) {
            if ($left > $d) continue;
            $ins = $p->prepare("INSERT IGNORE INTO trial_emails (tenant_id, days_left) VALUES (?, ?)");
            $ins->execute([$t['id'], $d]);
            if (!$ins->rowCount()) break;   // this reminder already went
            try {
                platformMail($t['owner_email'], "Your free trial ends in $left day" . ($left === 1 ? '' : 's'),
                    '<p>Hello,</p><p>The free trial for <b>' . htmlspecialchars($t['name']) . '</b> ends on ' . date('d M Y', strtotime($t['trial_ends_at'])) . '.</p>'
                    . '<p>Choose a plan to keep using everything: <a href="' . (APP_ENV === 'local' ? 'http://' : 'https://') . htmlspecialchars($t['host']) . '/pages/subscription.php">Subscription</a>.</p>'
                    . '<p>If you don’t, the clinic becomes read-only: nothing is deleted, and you can still export your data.</p>');
                $sent++;
            } catch (Throwable $e) { appLog("Trial email to tenant {$t['id']} failed: " . $e->getMessage()); }
            break;
        }
    }
    return $sent;
}
