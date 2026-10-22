<?php
// pages/layout_preview.php?id= — a layout with sample data (&lines=60 to see page breaks, &lang=en|ar|both),
// or with a real document (&doc=<id> of the layout's type). &version= shows a published version instead of the draft.
// Nothing is stored. Opening a real document is logged against its patient.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/doc_layouts.php';
requirePermission('setup.layouts');

$db = getDB();
$l = findLayout($db, (int)($_GET['id'] ?? 0));
if (!$l) { http_response_code(404); exit('Not found'); }
if ($vid = (int)($_GET['version'] ?? 0)) {
    $v = findLayoutVersion($db, $vid);
    if (!$v || (int)$v['layout_id'] !== (int)$l['id']) { http_response_code(404); exit('Not found'); }
    $L = json_decode($v['layout_json'], true);
} else $L = checkLayout($db, $l['draft_json']);
$lang = in_array($_GET['lang'] ?? '', ['en', 'ar', 'both'], true) ? $_GET['lang'] : $L['page']['language'];
$doc = (int)($_GET['doc'] ?? 0);
if ($doc) {
    requirePermission('billing.view');
    $data = documentData($db, $l['document_type'], $doc);
    $pid = $l['document_type'] === 'credit_note' ? $db->query("SELECT i.patient_id FROM credit_notes c JOIN invoices i ON i.id = c.invoice_id WHERE c.id = $doc")->fetchColumn()
         : $db->query("SELECT patient_id FROM " . ($l['document_type'] === 'invoice' ? 'invoices' : 'receipts') . " WHERE id = $doc")->fetchColumn();
    if ($pid) logPatientAccess($db, (int)$pid, 'layout_preview:' . $l['document_type'] . ':' . $doc);
} else $data = sampleDocumentData($l['document_type'], max(1, min(80, (int)($_GET['lines'] ?? 4))));
sendStaffPageHeaders();
echo renderDocument($L, $data, $lang, layoutAssetUrls($db, $L), $vid ? null : 'DRAFT');
