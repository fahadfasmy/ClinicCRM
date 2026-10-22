<?php
// pages/meta_receiver.php — Meta calls this the moment someone submits a Lead Ads form.
// GET  = Meta checking the webhook (hub.challenge); POST = a new lead id, whose details are fetched from Meta.
// Settings (tokens, app secret) come from the Meta Leads page; see includes/meta_leads.php.
require_once __DIR__ . '/../includes/meta_leads.php';

$db = getDB();
ensureMetaSchema($db);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $ok = ($_GET['hub_mode'] ?? '') === 'subscribe' && hash_equals(metaSetting($db, 'verify_token'), (string)($_GET['hub_verify_token'] ?? ''));
    http_response_code($ok ? 200 : 403);
    echo $ok ? (string)($_GET['hub_challenge'] ?? '') : 'Forbidden';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$raw = file_get_contents('php://input');
$logId = null;
$log = function (string $status, ?int $leadId, ?string $msg) use ($db, &$logId, $raw) {
    if ($logId === null) {
        $db->prepare("INSERT INTO meta_webhook_log (payload, status, via, lead_id, error_msg) VALUES (?,?, 'webhook', ?, ?)")
           ->execute([$raw, $status, $leadId, $msg === null ? null : mb_substr($msg, 0, 500)]);
        $logId = (int)$db->lastInsertId();
    } else {
        // One delivery can carry several leads: keep one row per lead after the first
        $db->prepare("INSERT INTO meta_webhook_log (payload, status, via, lead_id, error_msg) VALUES (NULL,?, 'webhook', ?, ?)")
           ->execute([$status, $leadId, $msg === null ? null : mb_substr($msg, 0, 500)]);
    }
};

// Only Meta knows the app secret, so a valid signature proves the delivery is genuine
$secret = metaSetting($db, 'app_secret');
if ($secret !== '') {
    $sig = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    if (!hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $sig)) {
        $log('error', null, 'Rejected: the signature doesn’t match the app secret (not from Meta, or the app secret is wrong).');
        http_response_code(403);
        exit;
    }
}

$payload = json_decode($raw, true);
$changes = [];
foreach ($payload['entry'] ?? [] as $entry) foreach ($entry['changes'] ?? [] as $c) if (($c['field'] ?? '') === 'leadgen') $changes[] = $c['value'] ?? [];
if (!$changes) { $log('error', null, 'No lead in this delivery.'); http_response_code(200); echo 'OK'; exit; }

foreach ($changes as $v) {
    $leadgenId = (string)($v['leadgen_id'] ?? '');
    if ($leadgenId === '') { $log('error', null, 'Delivery without a lead id.'); continue; }
    try {
        $ml = metaGraph($db, $leadgenId, ['fields' => META_LEAD_FIELDS], 'GET', metaPageToken($db));
        $ml['form_id'] ??= $v['form_id'] ?? null;
        $ml['ad_id']   ??= $v['ad_id'] ?? null;
        $r = metaImportLead($db, $ml);
        $log($r['status'] === 'duplicate' ? 'duplicate' : 'processed', $r['lead_id'],
             $r['status'] === 'updated' ? 'Added to an existing lead with the same phone or email' : null);
    } catch (Throwable $e) {
        // The hourly check will try this lead again, so nothing is lost
        $log('error', null, $e->getMessage());
    }
}
http_response_code(200);
echo 'OK';

// Reply to Meta first, then send any "we'll call you" emails for the leads just saved
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
try { sendQueuedLeadEmails($db); } catch (Throwable $e) {}
