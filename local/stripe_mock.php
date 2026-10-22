<?php
// local/stripe_mock.php — LOCAL TEST ONLY. A stand-in for the parts of Stripe the platform uses:
//   php -S 127.0.0.1:8086 local/stripe_mock.php
// Customers (with a test clock), subscriptions with a 14-day trial that pauses when it ends without a card, Checkout
// (open /checkout/<id> and POST /checkout/<id>/pay to "pay"), the billing portal, test clocks (create, advance), and
// webhooks signed exactly like Stripe's (Stripe-Signature: t=…,v1=HMAC-SHA256(secret, "t.payload")).
// Test controls: POST /__config {webhook_url, webhook_secret}, POST /__reset, GET /__state, POST /__fail_next/<customer>.
// State lives in local/stripe_mock_state.json. Requests need "Authorization: Bearer sk_test_…".
$stateFile = __DIR__ . '/stripe_mock_state.json';
$S = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$S += ['config' => [], 'customers' => [], 'subscriptions' => [], 'sessions' => [], 'clocks' => [], 'events' => [], 'n' => 0];
$save = function () use (&$S, $stateFile) { file_put_contents($stateFile, json_encode($S, JSON_PRETTY_PRINT)); };
$id = function (string $p) use (&$S) { $S['n']++; return $p . '_mock' . str_pad((string)$S['n'], 6, '0', STR_PAD_LEFT); };
$path = trim((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$method = $_SERVER['REQUEST_METHOD'];
parse_str((string)file_get_contents('php://input'), $in);
$in = $in + $_GET;
header('Content-Type: application/json');
$fail = function (int $code, string $msg) { http_response_code($code); exit(json_encode(['error' => ['type' => 'invalid_request_error', 'message' => $msg]])); };
$now = fn(?string $customer = null) => ($customer && ($c = $S['customers'][$customer]['test_clock'] ?? null)) ? (int)$S['clocks'][$c]['frozen_time'] : time();

/** Records an event and delivers it to the configured webhook, signed. */
$emit = function (string $type, array $object) use (&$S, $id) {
    $ev = ['id' => $id('evt'), 'object' => 'event', 'type' => $type, 'created' => time(), 'data' => ['object' => $object], 'livemode' => false];
    $S['events'][] = $ev;
    if (!empty($S['config']['webhook_url'])) {
        $payload = json_encode($ev);
        $t = time();
        $sig = 't=' . $t . ',v1=' . hash_hmac('sha256', "$t.$payload", (string)$S['config']['webhook_secret']);
        $ch = curl_init($S['config']['webhook_url']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', "Stripe-Signature: $sig"], $S['config']['webhook_headers'] ?? [])]);
        $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $S['events'][count($S['events']) - 1]['delivered'] = $code;
    }
};

// ── Test controls ──
if ($path === '__reset') { $S = ['config' => $S['config'], 'customers' => [], 'subscriptions' => [], 'sessions' => [], 'clocks' => [], 'events' => [], 'n' => 0]; $save(); exit('{}'); }
if ($path === '__config' && $method === 'POST') { $S['config'] = array_merge($S['config'], json_decode((string)file_get_contents('php://input'), true) ?: $in); $save(); exit('{}'); }
if ($path === '__state') exit(json_encode($S));
if (preg_match('#^__fail_next/(cus_\w+)$#', $path, $m)) { $S['customers'][$m[1]]['fail_next'] = true; $save(); exit('{}'); }

// ── Checkout page (the "customer" pays) ──
if (preg_match('#^checkout/(cs_\w+)(/pay)?$#', $path, $m)) {
    $cs = &$S['sessions'][$m[1]];
    if (!$cs) $fail(404, 'No such session');
    if (empty($m[2])) { header('Content-Type: text/html'); exit('<h1>Stripe Checkout (stand-in)</h1><form method="post" action="/checkout/' . $m[1] . '/pay"><button>Pay</button></form>'); }
    $cus = $cs['customer'];
    $t = $now($cus);
    $sub = ['id' => $id('sub'), 'object' => 'subscription', 'customer' => $cus, 'status' => 'active', 'items' => ['data' => [['price' => ['id' => $cs['price']]]]],
            'current_period_start' => $t, 'current_period_end' => $t + ($cs['interval'] === 'year' ? 365 : 30) * 86400, 'cancel_at_period_end' => false,
            'trial_end' => null, 'metadata' => $cs['subscription_metadata'] ?? [], 'default_payment_method' => 'pm_card_visa'];
    $S['subscriptions'][$sub['id']] = $sub;
    $S['customers'][$cus]['has_card'] = true;
    $cs['status'] = 'complete'; $cs['subscription'] = $sub['id'];
    $emit('checkout.session.completed', $cs);
    $emit('customer.subscription.created', $sub);
    $emit('invoice.paid', ['id' => $id('in'), 'object' => 'invoice', 'customer' => $cus, 'subscription' => $sub['id'], 'amount_paid' => $cs['amount'], 'currency' => 'aed', 'status' => 'paid']);
    $save();
    header('Content-Type: text/html'); http_response_code(303); header('Location: ' . $cs['success_url']); exit;
}

// ── The API ──
if (!preg_match('#^Bearer sk_test_\w+#', $_SERVER['HTTP_AUTHORIZATION'] ?? '')) $fail(401, 'Invalid API Key provided.');
if ($path === 'v1/test_helpers/test_clocks' && $method === 'POST') {
    $c = ['id' => $id('clock'), 'object' => 'test_helpers.test_clock', 'frozen_time' => (int)($in['frozen_time'] ?? time()), 'status' => 'ready', 'name' => $in['name'] ?? ''];
    $S['clocks'][$c['id']] = $c; $save(); exit(json_encode($c));
}
if (preg_match('#^v1/test_helpers/test_clocks/(clock_\w+)/advance$#', $path, $m) && $method === 'POST') {
    $clock = &$S['clocks'][$m[1]];
    if (!$clock) $fail(404, 'No such test clock');
    $to = (int)$in['frozen_time'];
    if ($to <= $clock['frozen_time']) $fail(400, 'The new frozen_time must be after the current one.');
    $clock['frozen_time'] = $to;
    foreach ($S['subscriptions'] as $sid => &$sub) {
        if (($S['customers'][$sub['customer']]['test_clock'] ?? null) !== $m[1]) continue;
        // Trial over with no card: the trial settings say pause
        if ($sub['status'] === 'trialing' && $sub['trial_end'] <= $to && empty($S['customers'][$sub['customer']]['has_card'])) {
            if ($sub['trial_end'] - 3 * 86400 > $to - 86400 * 365) $emit('customer.subscription.trial_will_end', $sub);
            $sub['status'] = 'paused'; $sub['pause_collection'] = null;
            $emit('customer.subscription.updated', $sub);
        }
        // A paid period ends: renew, or fail when the test asked for it
        while (in_array($sub['status'], ['active', 'past_due'], true) && $sub['current_period_end'] <= $to) {
            if ($sub['cancel_at_period_end']) { $sub['status'] = 'canceled'; $emit('customer.subscription.deleted', $sub); break; }
            $len = $sub['current_period_end'] - $sub['current_period_start'];
            if (!empty($S['customers'][$sub['customer']]['fail_next'])) {
                unset($S['customers'][$sub['customer']]['fail_next']);
                $sub['status'] = 'past_due';
                $emit('invoice.payment_failed', ['id' => $id('in'), 'object' => 'invoice', 'customer' => $sub['customer'], 'subscription' => $sid, 'status' => 'open', 'attempt_count' => 1]);
                $emit('customer.subscription.updated', $sub);
                break;
            }
            $sub['current_period_start'] = $sub['current_period_end']; $sub['current_period_end'] += $len; $sub['status'] = 'active';
            $emit('invoice.paid', ['id' => $id('in'), 'object' => 'invoice', 'customer' => $sub['customer'], 'subscription' => $sid, 'status' => 'paid']);
            $emit('customer.subscription.updated', $sub);
        }
    }
    unset($sub);
    $save(); exit(json_encode($clock));
}
if ($path === 'v1/customers' && $method === 'POST') {
    $c = ['id' => $id('cus'), 'object' => 'customer', 'email' => $in['email'] ?? null, 'name' => $in['name'] ?? null, 'metadata' => $in['metadata'] ?? [], 'test_clock' => $in['test_clock'] ?? null];
    if (isset($in['tax_id_data'])) $c['tax_ids'] = $in['tax_id_data'];
    $S['customers'][$c['id']] = $c; $save(); exit(json_encode($c));
}
if ($path === 'v1/subscriptions' && $method === 'POST') {
    $cus = $in['customer'] ?? ''; if (!isset($S['customers'][$cus])) $fail(400, 'No such customer');
    $t = $now($cus); $trial = (int)($in['trial_period_days'] ?? 0);
    $sub = ['id' => $id('sub'), 'object' => 'subscription', 'customer' => $cus, 'status' => $trial ? 'trialing' : 'incomplete', 'items' => ['data' => [['price' => ['id' => $in['items'][0]['price'] ?? '']]]],
            'trial_end' => $trial ? $t + $trial * 86400 : null, 'current_period_start' => $t, 'current_period_end' => $t + max($trial, 30) * 86400, 'cancel_at_period_end' => false,
            'metadata' => $in['metadata'] ?? [], 'trial_settings' => $in['trial_settings'] ?? null];
    $S['subscriptions'][$sub['id']] = $sub; $emit('customer.subscription.created', $sub); $save(); exit(json_encode($sub));
}
if (preg_match('#^v1/subscriptions/(sub_\w+)$#', $path, $m)) {
    $sub = &$S['subscriptions'][$m[1]]; if (!$sub) $fail(404, 'No such subscription');
    if ($method === 'DELETE') { $sub['status'] = 'canceled'; $emit('customer.subscription.deleted', $sub); }
    $save(); exit(json_encode($sub));
}
if ($path === 'v1/checkout/sessions' && $method === 'POST') {
    $cus = $in['customer'] ?? ''; if (!isset($S['customers'][$cus])) $fail(400, 'No such customer');
    if (($in['mode'] ?? '') !== 'subscription') $fail(400, 'Only subscription mode in this stand-in.');
    $price = $in['line_items'][0]['price'] ?? ''; if ($price === '') $fail(400, 'line_items[0][price] is required');
    $cs = ['id' => $id('cs'), 'object' => 'checkout.session', 'customer' => $cus, 'mode' => 'subscription', 'status' => 'open', 'price' => $price,
           'interval' => str_contains($price, 'year') ? 'year' : 'month', 'amount' => (int)($in['metadata']['amount'] ?? 0), 'metadata' => $in['metadata'] ?? [],
           'subscription_metadata' => $in['subscription_data']['metadata'] ?? [], 'success_url' => $in['success_url'] ?? '', 'cancel_url' => $in['cancel_url'] ?? '',
           'tax_id_collection' => $in['tax_id_collection'] ?? null];
    $cs['url'] = 'http://' . $_SERVER['HTTP_HOST'] . '/checkout/' . $cs['id'];
    $S['sessions'][$cs['id']] = $cs; $save(); exit(json_encode($cs));
}
if ($path === 'v1/billing_portal/sessions' && $method === 'POST') {
    $cus = $in['customer'] ?? ''; if (!isset($S['customers'][$cus])) $fail(400, 'No such customer');
    exit(json_encode(['id' => $id('bps'), 'object' => 'billing_portal.session', 'customer' => $cus, 'url' => 'http://' . $_SERVER['HTTP_HOST'] . '/portal/' . $cus, 'return_url' => $in['return_url'] ?? '']));
}
$fail(404, "Unrecognized request URL ($method /$path).");
