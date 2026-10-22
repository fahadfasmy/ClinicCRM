<?php
// pages/doc_print.php?type=invoice|receipt|credit_note&id= — the stored copy of an issued document, exactly as it was
// at issue (re-prints never change); &rendition= shows another stored copy. A VOID or PAID stamp is laid over it when
// its layout has stamps on. Staff with setup.layouts can re-render it with a published layout: that adds a copy and
// is audit-logged, never replacing the original. Every opening is logged against the patient.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/doc_layouts.php';
requirePermission('billing.view');

$db = getDB();
$type = in_array($_GET['type'] ?? '', ['invoice', 'receipt', 'credit_note'], true) ? $_GET['type'] : 'invoice';
$id = (int)($_GET['id'] ?? 0);
$table = ['invoice' => 'invoices', 'receipt' => 'receipts', 'credit_note' => 'credit_notes'][$type];
$row = $db->query("SELECT * FROM $table WHERE id = $id")->fetch();
if (!$row) { http_response_code(404); exit('Not found'); }
// Imported from ClinicSoft: not a tax invoice of this system (history is reprinted from ClinicSoft's archive)
if ($type === 'invoice' && !empty($row['imported'])) { http_response_code(409); exit('This was imported from ClinicSoft (' . htmlspecialchars((string)$row['legacy_ref']) . ') and is not a tax invoice of this system, so it isn’t printed here.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    requirePermission('setup.layouts');
    $rid = storeDocumentRendition($db, $type, $id, 'rerender', (int)($_POST['version_id'] ?? 0) ?: null);
    header("Location: /pages/doc_print.php?type=$type&id=$id&rendition=$rid"); exit;
}
$all = $db->prepare("SELECT id, layout, is_original, reason, created_at, layout_version_id FROM document_renditions WHERE doc_type = ? AND doc_id = ? ORDER BY is_original DESC, id");
$all->execute([$type, $id]);
$all = $all->fetchAll();
$want = (int)($_GET['rendition'] ?? 0);
$pick = $want ? (array_values(array_filter($all, fn($r) => (int)$r['id'] === $want))[0] ?? null) : ($all[0] ?? null);
if (!$pick) { http_response_code(404); exit('Not found'); }
$r = $db->query("SELECT * FROM document_renditions WHERE id = " . (int)$pick['id'])->fetch();
$pid = $type === 'credit_note' ? $db->query("SELECT patient_id FROM invoices WHERE id = " . (int)$row['invoice_id'])->fetchColumn() : $row['patient_id'];
if ($pid) logPatientAccess($db, (int)$pid, 'print:' . $type . ':' . $id);

$stamp = ($row['status'] ?? '') === 'void' ? 'VOID' : ($type === 'invoice' && $row['status'] === 'paid' ? 'PAID' : null);
$v = $r['layout_version_id'] ? findLayoutVersion($db, (int)$r['layout_version_id']) : null;
$stampsOn = $v ? !empty(json_decode($v['layout_json'], true)['page']['stamps']) : true;
$lang = $r['language'] ?? 'en';
$stampHtml = $stamp && $stampsOn ? '<div class="stamp" style="position:fixed;top:40%;left:0;right:0;text-align:center;font-size:60pt;font-weight:800;color:#b42318;opacity:.16;transform:rotate(-24deg);pointer-events:none">'
    . sanitize($lang === 'ar' ? DOC_STAMPS[$stamp] : ($lang === 'both' ? $stamp . ' / ' . DOC_STAMPS[$stamp] : $stamp)) . '</div>' : '';
$toolbar = '<div class="noprint" style="font:13px sans-serif;margin:0 auto 8px;max-width:210mm;display:flex;gap:8px;flex-wrap:wrap;align-items:center"><button onclick="window.print()">Print</button>';
if (count($all) > 1) {
    $toolbar .= '<span>Copy:</span>';
    foreach ($all as $x) $toolbar .= (int)$x['id'] === (int)$r['id'] ? '<strong>' . sanitize($x['is_original'] ? 'original' : $x['layout']) . '</strong>' : '<a href="?type=' . $type . '&amp;id=' . $id . '&amp;rendition=' . (int)$x['id'] . '">' . sanitize($x['is_original'] ? 'original' : $x['layout'] . ' (' . date('d M H:i', strtotime($x['created_at'])) . ')') . '</a>';
}
if (can('setup.layouts')) {
    $vs = $db->prepare("SELECT l.current_version_id, l.name, v.version FROM document_layouts l JOIN document_layout_versions v ON v.id = l.current_version_id WHERE l.document_type = ? AND l.status = 'published' ORDER BY l.is_default DESC, l.name");
    $vs->execute([$type]);
    $toolbar .= '<form method="POST" style="margin-left:auto;display:flex;gap:4px">' . csrfField() . '<label for="rv" style="align-self:center">Print with</label><select id="rv" name="version_id">';
    foreach ($vs as $x) $toolbar .= '<option value="' . (int)$x['current_version_id'] . '">' . sanitize($x['name'] . ' v' . $x['version']) . '</option>';
    $toolbar .= '</select><button>Re-render (adds a copy)</button></form>';
}
$toolbar .= '</div>';
sendStaffPageHeaders();
$html = $r['html'];
// Copies made by the layout engine have <div class="sheet">; the few from before it have <div class="page">
foreach (['<div class="sheet">', '<div class="page">'] as $anchor) if (str_contains($html, $anchor)) { $html = preg_replace('/' . preg_quote($anchor, '/') . '/', $toolbar . $anchor . $stampHtml, $html, 1); break; }
echo $html;
