<?php
// pages/api_wa_cloud.php — the WhatsApp Cloud API webhook. GET = Meta checking the webhook (hub.challenge with the
// verify token from the Messaging page); POST = message statuses and incoming messages, accepted only with a valid
// X-Hub-Signature-256 made with the app secret. Rate-limited per address.
require_once __DIR__ . '/../includes/messaging.php';

$db = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $vt = waConfig($db)['verify_token'];
    $ok = ($_GET['hub_mode'] ?? '') === 'subscribe' && $vt !== '' && hash_equals($vt, (string)($_GET['hub_verify_token'] ?? ''));
    http_response_code($ok ? 200 : 403);
    echo $ok ? (string)($_GET['hub_challenge'] ?? '') : 'Forbidden';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
if (!rateLimitOk($db, 'wa_webhook:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 600, 60)) { http_response_code(429); exit; }
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 1048576 || !waSignatureValid($db, $raw, (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''))) { http_response_code(403); echo 'Bad signature'; exit; }
$payload = json_decode($raw, true);
if (!is_array($payload)) { http_response_code(400); exit; }
try {
    waHandleWebhook($db, $payload);
} catch (Throwable $e) {
    appLog('WhatsApp webhook: ' . $e->getMessage());   // Meta retries on errors; answer 200 only when handled
    http_response_code(500); exit;
}
http_response_code(200);
echo 'OK';
