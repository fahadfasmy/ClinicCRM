<?php
// local/wa_cloud_mock.php — LOCAL TEST ONLY. A stand-in for the WhatsApp Cloud API (graph.facebook.com) so patient
// messages can be tested without a Meta account. Run as a router: php -S 127.0.0.1:8085 local/wa_cloud_mock.php
// Each message sent is appended to local/wa_cloud_mock_out.json; GET /sent returns them.
header('Content-Type: application/json');
$file = __DIR__ . '/wa_cloud_mock_out.json';
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if ($path === 'sent') { echo is_file($file) ? file_get_contents($file) : '[]'; exit; }
if ($path === 'reset') { @unlink($file); echo '[]'; exit; }
if (!preg_match('#^v\d+\.\d+/(\d+)/messages$#', $path)) { http_response_code(404); echo json_encode(['error' => ['message' => 'Unknown path', 'code' => 100]]); exit; }
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!str_starts_with($auth, 'Bearer ') || substr($auth, 7) === '') { http_response_code(401); echo json_encode(['error' => ['message' => 'An access token is required', 'code' => 190]]); exit; }
$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
if (($body['messaging_product'] ?? '') !== 'whatsapp' || empty($body['to'])) { http_response_code(400); echo json_encode(['error' => ['message' => 'Bad message', 'code' => 100]]); exit; }
$id = 'wamid.MOCK' . bin2hex(random_bytes(8));
$log = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
$log[] = ['id' => $id, 'at' => date('c'), 'message' => $body];
file_put_contents($file, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode(['messaging_product' => 'whatsapp', 'contacts' => [['input' => $body['to'], 'wa_id' => $body['to']]], 'messages' => [['id' => $id]]]);
