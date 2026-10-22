<?php
// pages/api_stripe.php — Stripe subscription events (Stripe → Developers → Webhooks → https://PLATFORM_HOST/pages/api_stripe.php).
// Signature checked with STRIPE_WEBHOOK_SECRET (wrong or old signature = 400, nothing stored); each event is stored once
// in webhook_events, so Stripe's retries are harmless; then the clinic's status follows (includes/platform_billing.php).
const PLATFORM_REQUEST = true;   // the platform's address, not a clinic's
require_once __DIR__ . '/../includes/platform_billing.php';
if (!multiTenant() || requestHost() !== strtolower(platformHost()) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(404); exit; }
header('Content-Type: application/json');
$payload = (string)file_get_contents('php://input');
if (!stripeVerifySignature($payload, (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), defined('STRIPE_WEBHOOK_SECRET') ? STRIPE_WEBHOOK_SECRET : '')) {
    http_response_code(400); exit('{"error":"bad signature"}');
}
$ev = json_decode($payload, true);
if (!is_array($ev) || empty($ev['id']) || empty($ev['type'])) { http_response_code(400); exit('{"error":"not an event"}'); }
try {
    echo json_encode(['received' => true, 'result' => stripeHandleEvent($ev)]);
} catch (Throwable $e) {
    appLog('Stripe webhook ' . $ev['id'] . ' failed: ' . $e->getMessage());
    platformDB()->prepare("UPDATE webhook_events SET error = ? WHERE provider = 'stripe' AND event_id = ?")->execute([mb_substr($e->getMessage(), 0, 500), $ev['id']]);
    http_response_code(500);   // Stripe retries
    echo '{"error":"processing failed"}';
}
