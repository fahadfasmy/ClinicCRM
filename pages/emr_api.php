<?php
// pages/emr_api.php — JSON helpers for the clinical note. ?action=icd&q= searches ICD-10 codes (needs emr.view).
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/emr.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!can('emr.view')) { http_response_code(403); echo json_encode(['error' => 'No permission']); exit; }

if (($_GET['action'] ?? '') === 'icd') {
    $rows = icdSearch((string)($_GET['q'] ?? ''));
    $out = array_map(fn($r) => ['code' => $r['code'], 'label' => icdFormat($r['code']), 'description' => $r['description']], $rows);
    // No match: an object {empty: true} tells the page the code list was never imported, [] means just no match
    if (!$out && !referenceDb()->query("SELECT 1 FROM icd10_codes LIMIT 1")->fetchColumn()) { echo '{"empty":true}'; exit; }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}
http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
