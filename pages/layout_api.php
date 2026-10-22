<?php
// pages/layout_api.php — the designer's calls (setup.layouts, CSRF on every write):
//   POST action=save     JSON {id, layout, meta}            → {ok, problems[], saved_at}   (problems = what blocks publishing)
//   POST action=publish  JSON {id}                          → {ok, version} | {error}
//   POST action=preview  JSON {id, layout, lang, doc, lines} → the rendered page (nothing stored)
//   POST action=upload   multipart image                    → {id, url}
//   GET  action=asset&id=                                   → a layout image
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/doc_layouts.php';
requirePermission('setup.layouts');

$db = getDB();
$action = (string)($_GET['action'] ?? '');
$json = function (array $out, int $code = 200) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($out, JSON_UNESCAPED_UNICODE); exit; };

if ($action === 'asset') {
    $a = $db->prepare("SELECT * FROM document_layout_assets WHERE id = ?"); $a->execute([(int)($_GET['id'] ?? 0)]); $a = $a->fetch();
    $base = realpath(tenantStoragePath());
    $f = $a ? realpath($base . '/' . $a['path']) : false;
    if (!$f || !str_starts_with($f, $base . DIRECTORY_SEPARATOR)) { http_response_code(404); exit; }
    header('Content-Type: ' . $a['mime']); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=3600');
    readfile($f); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $json(['error' => 'POST only'], 405);
requireCsrf();
try {
    if ($action === 'upload') {
        $f = $_FILES['file'] ?? [];
        if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('Choose an image (PNG or JPG, 512 KB at most).');
        $id = saveLayoutAsset($db, (string)file_get_contents($f['tmp_name']));
        $json(['ok' => true, 'id' => $id, 'url' => '/pages/layout_api.php?action=asset&id=' . $id]);
    }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $l = findLayout($db, (int)($in['id'] ?? 0));
    if (!$l) throw new RuntimeException('Layout not found.');
    switch ($action) {
        case 'save':
            $problems = saveLayoutDraft($db, (int)$l['id'], $in['layout'] ?? null, (array)($in['meta'] ?? []));
            $json(['ok' => true, 'problems' => $problems, 'saved_at' => date('H:i:s')]);
        case 'publish':
            $vid = publishLayout($db, (int)$l['id']);
            $json(['ok' => true, 'version' => (int)findLayoutVersion($db, $vid)['version']]);
        case 'preview':
            $L = checkLayout($db, $in['layout'] ?? null);
            $lang = in_array($in['lang'] ?? '', ['en', 'ar', 'both'], true) ? $in['lang'] : $L['page']['language'];
            $doc = (int)($in['doc'] ?? 0);
            if ($doc && !can('billing.view')) throw new RuntimeException('You can preview with sample data only.');
            $data = $doc ? documentData($db, $l['document_type'], $doc) : sampleDocumentData($l['document_type'], max(1, min(80, (int)($in['lines'] ?? 4))));
            header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
            echo renderDocument($L, $data, $lang, layoutAssetUrls($db, $L), 'DRAFT');
            exit;
    }
    throw new RuntimeException('Unknown action.');
} catch (InvalidArgumentException $e) {
    $json(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    $json(['error' => $e->getMessage()], 422);
}
