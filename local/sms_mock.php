<?php
// local/sms_mock.php — LOCAL TEST ONLY. A stand-in SMS provider: php -S 127.0.0.1:8084 local/sms_mock.php
// POST /send {to, text} → {id}; each text is appended to local/sms_mock_out.json; GET /sent returns them.
header('Content-Type: application/json');
$file = __DIR__ . '/sms_mock_out.json';
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if ($path === 'sent') { echo is_file($file) ? file_get_contents($file) : '[]'; exit; }
if ($path === 'reset') { @unlink($file); echo '[]'; exit; }
$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
if ($path !== 'send' || empty($body['to']) || !isset($body['text'])) { http_response_code(400); echo json_encode(['error' => 'bad request']); exit; }
$id = 'sms-' . bin2hex(random_bytes(6));
$log = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
$log[] = ['id' => $id, 'at' => date('c'), 'to' => $body['to'], 'text' => $body['text']];
file_put_contents($file, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode(['id' => $id]);
