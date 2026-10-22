<?php
// local/google_mock.php — LOCAL TEST ONLY. Stand-in for Google sign-in, tokens and Calendar events.
// Run as a router: php -S 127.0.0.1:8083 local/google_mock.php   (events saved to local/google_mock_events.json)
// Sign-in approves straight away for the address in login_hint (default prac@example.test).
// Refresh token "rt-revoked@…" behaves like access removed by the user. Set GMOCK_DOWN file to simulate an outage.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . '/google_mock_events.json';
$events = json_decode(@file_get_contents($file) ?: '{}', true);
$save = function () use (&$events, $file) { file_put_contents($file, json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); };
$json = function ($data, int $code = 200) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($data); exit; };
if (file_exists(__DIR__ . '/GMOCK_DOWN')) $json(['error' => ['code' => 503, 'message' => 'Backend Error']], 503);

if ($path === '/o/oauth2/v2/auth') {
    foreach (['client_id', 'redirect_uri', 'response_type', 'scope', 'state'] as $k) if (empty($_GET[$k])) { http_response_code(400); exit("missing $k"); }
    if (($_GET['access_type'] ?? '') !== 'offline' || strpos($_GET['scope'], 'calendar.events') === false) { http_response_code(400); exit('bad request'); }
    $email = $_GET['login_hint'] ?? 'prac@example.test';
    header('Location: ' . $_GET['redirect_uri'] . '?' . http_build_query(['code' => 'code-' . base64_encode($email), 'state' => $_GET['state'], 'scope' => $_GET['scope']]));
    exit;
}
if ($path === '/token') {
    $p = $_POST;
    if (($p['client_id'] ?? '') === '' || ($p['client_secret'] ?? '') !== 'mock-secret') $json(['error' => 'invalid_client', 'error_description' => 'Unauthorized'], 401);
    if (($p['grant_type'] ?? '') === 'authorization_code') {
        if (strpos($p['code'] ?? '', 'code-') !== 0) $json(['error' => 'invalid_grant', 'error_description' => 'Malformed auth code.'], 400);
        $email = base64_decode(substr($p['code'], 5));
        $idt = 'x.' . rtrim(strtr(base64_encode(json_encode(['email' => $email])), '+/', '-_'), '=') . '.y';
        $json(['access_token' => "at-$email-" . time(), 'expires_in' => 3599, 'refresh_token' => "rt-$email",
               'scope' => strpos($email, 'noscope') === 0 ? 'openid email' : 'https://www.googleapis.com/auth/calendar.events openid email', 'id_token' => $idt]);
    }
    if (($p['grant_type'] ?? '') === 'refresh_token') {
        if (strpos($p['refresh_token'] ?? '', 'rt-revoked') === 0) $json(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400);
        $email = substr($p['refresh_token'], 3);
        $json(['access_token' => "at-$email-" . time(), 'expires_in' => 3599]);
    }
    $json(['error' => 'unsupported_grant_type'], 400);
}
if ($path === '/revoke') { file_put_contents(__DIR__ . '/google_mock_revoked.txt', ($_POST['token'] ?? '') . "\n", FILE_APPEND); $json([]); }

if (preg_match('#^/calendar/v3/calendars/primary/events(?:/([^/]+))?$#', $path, $m)) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer at-(.+)-\d+$/', $auth, $am)) $json(['error' => ['code' => 401, 'message' => 'Invalid Credentials']], 401);
    $owner = $am[1];
    $id = isset($m[1]) ? rawurldecode($m[1]) : null;
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'POST' && $id === null) {
        if (empty($body['start']['dateTime']) || empty($body['end']['dateTime']) || empty($body['summary'])) $json(['error' => ['code' => 400, 'message' => 'Missing start/end/summary']], 400);
        $id = 'ev' . bin2hex(random_bytes(6));
        $events[$id] = ['owner' => $owner] + $body;
        $save();
        $json(['id' => $id] + $body);
    }
    if ($id === null || !isset($events[$id]) || $events[$id]['owner'] !== $owner) $json(['error' => ['code' => 404, 'message' => 'Not Found']], 404);
    if ($method === 'PUT') { $events[$id] = ['owner' => $owner] + $body; $save(); $json(['id' => $id] + $body); }
    if ($method === 'DELETE') { unset($events[$id]); $save(); http_response_code(204); exit; }
}
$json(['error' => ['code' => 404, 'message' => "Unknown path $path"]], 404);
