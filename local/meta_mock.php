<?php
// local/meta_mock.php — LOCAL TEST ONLY. A stand-in for graph.facebook.com so the Meta Leads integration can be
// tested without a real Meta account. Run as a router: php -S 127.0.0.1:8082 local/meta_mock.php
// Received Conversions API events are saved to local/meta_mock_events.json for inspection.
header('Content-Type: application/json');
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$path = preg_replace('#^v\d+\.\d+/#', '', $path);
$body = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$token = $_GET['access_token'] ?? $body['access_token'] ?? '';
$fail = fn($code, $msg, $http = 400) => [http_response_code($http), print(json_encode(['error' => ['message' => $msg, 'type' => 'OAuthException', 'code' => $code, 'fbtrace_id' => 'MOCK']])), exit];
if ($token === '') $fail(104, 'An access token is required to request this resource.');
if ($token === 'expired') $fail(190, 'Error validating access token: Session has expired.');

const PAGE = '111222333444555';
const DATASET = '100000000000001';
$t = fn($ago) => gmdate('Y-m-d\TH:i:sO', time() - $ago);
$forms = [
    ['id' => '900000000000001', 'name' => 'Lip Filler Offer – Oct', 'status' => 'ACTIVE', 'questions' => [
        ['key' => 'full_name', 'label' => 'Full name', 'type' => 'FULL_NAME'], ['key' => 'phone_number', 'label' => 'Phone number', 'type' => 'PHONE'],
        ['key' => 'which_treatment_are_you_interested_in?', 'label' => 'Which treatment are you interested in?', 'type' => 'CUSTOM'],
        ['key' => 'when_would_you_like_to_visit?', 'label' => 'When would you like to visit?', 'type' => 'CUSTOM']]],
    ['id' => '900000000000002', 'name' => 'HydraFacial Free Consultation', 'status' => 'ACTIVE', 'questions' => [
        ['key' => 'full_name', 'label' => 'Full name', 'type' => 'FULL_NAME'], ['key' => 'email', 'label' => 'Email', 'type' => 'EMAIL'],
        ['key' => 'phone_number', 'label' => 'Phone number', 'type' => 'PHONE']]],
];
$fd = fn(array $kv) => array_map(fn($k, $v) => ['name' => $k, 'values' => [$v]], array_keys($kv), $kv);
$leads = [
    ['id' => '1200000000000001', 'created_time' => $t(600), 'form_id' => '900000000000001', 'ad_id' => '2380001', 'ad_name' => 'Lips video', 'adset_name' => 'Dubai women 25-45', 'campaign_name' => 'Lip Filler Oct', 'platform' => 'ig', 'is_organic' => false,
     'field_data' => $fd(['full_name' => 'Mariam Test', 'phone_number' => '+971 50 999 0001', 'which_treatment_are_you_interested_in?' => 'Lip Filler', 'when_would_you_like_to_visit?' => 'This week'])],
    ['id' => '1200000000000002', 'created_time' => $t(3 * 86400), 'form_id' => '900000000000001', 'ad_id' => '2380001', 'ad_name' => 'Lips video', 'campaign_name' => 'Lip Filler Oct', 'platform' => 'fb', 'is_organic' => false,
     'field_data' => $fd(['full_name' => 'سارة أحمد', 'phone_number' => '0509990002', 'which_treatment_are_you_interested_in?' => 'Botox and Lip Filler', 'when_would_you_like_to_visit?' => 'Next month'])],
    ['id' => '1200000000000003', 'created_time' => $t(10 * 86400), 'form_id' => '900000000000001', 'ad_id' => '2380002', 'ad_name' => 'Lips carousel', 'campaign_name' => 'Lip Filler Oct', 'platform' => 'fb', 'is_organic' => false,
     'field_data' => $fd(['full_name' => 'Already Here', 'phone_number' => '+971527726844', 'which_treatment_are_you_interested_in?' => 'Dermal Fillers'])],
    ['id' => '1200000000000004', 'created_time' => $t(20 * 86400), 'form_id' => '900000000000002', 'campaign_name' => null, 'platform' => 'fb', 'is_organic' => true,
     'field_data' => $fd(['full_name' => 'Email Only', 'email' => 'emailonly@example.test'])],
    ['id' => '1200000000000005', 'created_time' => $t(40 * 86400), 'form_id' => '900000000000002', 'ad_id' => '2380003', 'ad_name' => 'Glow', 'campaign_name' => 'HydraFacial Sept', 'platform' => 'ig', 'is_organic' => false,
     'field_data' => $fd(['full_name' => 'No Contact'])],
    ['id' => '1200000000000006', 'created_time' => $t(70 * 86400), 'form_id' => '900000000000002', 'ad_id' => '2380003', 'ad_name' => 'Glow', 'campaign_name' => 'HydraFacial Sept', 'platform' => 'ig', 'is_organic' => false,
     'field_data' => $fd(['full_name' => 'Old Lead', 'email' => 'old@example.test', 'phone_number' => '+971 55 999 0006'])],
];
$pick = fn(array $o, string $fields) => $o; // field filtering not needed for the tests

$parts = explode('/', $path);
if (count($parts) === 1 && $parts[0] === PAGE && ($_GET['fields'] ?? '') === 'access_token') { echo json_encode(['id' => PAGE, 'access_token' => 'page-token-mock']); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === PAGE . '/subscribed_apps') {
    if ($token !== 'page-token-mock') $fail(210, 'A page access token is required to request this resource.');
    if (($body['subscribed_fields'] ?? '') !== 'leadgen') $fail(100, 'subscribed_fields must include leadgen');
    echo json_encode(['success' => true]); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && count($parts) === 2 && $parts[1] === 'events') {
    $webPixels = ['100000000000002', '100000000000003'];
    if (in_array($parts[0], $webPixels, true)) {
        // Website pixel (Conversions API "Lead" from the landing-page forms)
        $errs = [];
        foreach ($body['data'] ?? [] as $i => $e) {
            if (($e['action_source'] ?? '') !== 'website' || !in_array($e['event_name'] ?? '', ['Lead', 'Schedule'], true)) $errs[] = "event $i: expected a website Lead or Schedule";
            if (empty($e['user_data']['client_user_agent']) || !isset($e['user_data']['client_ip_address'])) $errs[] = "event $i: website events need client_ip_address and client_user_agent";
        }
        if ($errs) $fail(100, 'Invalid parameter: ' . implode('; ', $errs));
        $file = __DIR__ . '/meta_mock_events.json';
        $all = json_decode(@file_get_contents($file) ?: '[]', true);
        $all[] = ['at' => date('c'), 'pixel' => $parts[0], 'data' => $body['data']];
        file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['events_received' => count($body['data']), 'fbtrace_id' => 'MOCK']);
        exit;
    }
    if ($parts[0] !== DATASET) $fail(100, 'Unsupported post request. Object with ID does not exist.');
    $errs = [];
    foreach ($body['data'] ?? [] as $i => $e) {
        if (empty($e['event_name']) || empty($e['event_time']) || ($e['action_source'] ?? '') !== 'system_generated') $errs[] = "event $i: missing event_name/event_time or wrong action_source";
        if (empty($e['user_data']['lead_id']) || !is_int($e['user_data']['lead_id'])) $errs[] = "event $i: user_data.lead_id must be an integer";
        if (($e['custom_data']['event_source'] ?? '') !== 'crm' || empty($e['custom_data']['lead_event_source'])) $errs[] = "event $i: custom_data.event_source/lead_event_source missing";
    }
    if ($errs) $fail(100, 'Invalid parameter: ' . implode('; ', $errs));
    $file = __DIR__ . '/meta_mock_events.json';
    $all = json_decode(@file_get_contents($file) ?: '[]', true);
    $all[] = ['at' => date('c'), 'test_event_code' => $body['test_event_code'] ?? null, 'data' => $body['data']];
    file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode(['events_received' => count($body['data']), 'messages' => [], 'fbtrace_id' => 'MOCK']);
    exit;
}
// Like Meta: forms, leads and single leads need the page's own token (error 190 otherwise)
$needsPageToken = (count($parts) === 2 && in_array($parts[1], ['leadgen_forms', 'leads'], true))
    || (count($parts) === 1 && $parts[0] !== PAGE && $parts[0] !== DATASET);
if ($needsPageToken && $token !== 'page-token-mock') $fail(190, '(#190) This method must be called with a Page Access Token');
if (count($parts) === 1) {
    if ($parts[0] === PAGE) { echo json_encode(['id' => PAGE, 'name' => 'Demo Aesthetic Clinic (mock)']); exit; }
    if ($parts[0] === DATASET) { echo json_encode(['id' => DATASET, 'name' => 'Clinic CRM dataset (mock)']); exit; }
    foreach ($forms as $f) if ($f['id'] === $parts[0]) { echo json_encode($f); exit; }
    foreach ($leads as $l) if ($l['id'] === $parts[0]) { echo json_encode($l); exit; }
    $fail(100, "Unsupported get request. Object with ID '{$parts[0]}' does not exist.");
}
if (count($parts) === 2 && $parts[0] === PAGE && $parts[1] === 'leadgen_forms') { echo json_encode(['data' => $forms]); exit; }
if (count($parts) === 2 && $parts[1] === 'leads') {
    $since = 0;
    foreach (json_decode($_GET['filtering'] ?? '[]', true) ?: [] as $flt) if (($flt['field'] ?? '') === 'time_created') $since = (int)$flt['value'];
    $list = array_values(array_filter($leads, fn($l) => $l['form_id'] === $parts[0] && strtotime($l['created_time']) > $since));
    usort($list, fn($a, $b) => strcmp($b['created_time'], $a['created_time']));   // newest first, like Meta
    // Two per page so paging is exercised
    $offset = (int)($_GET['after'] ?? 0);
    $page = array_slice($list, $offset, 2);
    $more = $offset + 2 < count($list);
    echo json_encode(['data' => $page, 'paging' => ['cursors' => ['before' => (string)$offset, 'after' => (string)($offset + 2)]] + ($more ? ['next' => 'mock-next'] : [])]);
    exit;
}
$fail(100, 'Unknown path ' . $path);
