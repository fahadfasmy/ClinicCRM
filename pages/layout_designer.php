<?php
// pages/layout_designer.php?id= — the drag-and-drop document designer (assets/js/layout-designer.js, SortableJS
// 1.15.6 self-hosted). Left: blocks to drag in. Centre: the paper with header / body / footer rows on a 12-column
// grid, or the live preview. Right: the selected block's settings, or the page's. Saving and publishing go through
// layout_api.php, which validates everything again; required elements can't be removed (doc_required.php).
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/doc_layouts.php';
requirePermission('setup.layouts');

$db = getDB();
$l = findLayout($db, (int)($_GET['id'] ?? 0));
if (!$l) { http_response_code(404); exit('Layout not found'); }
$L = checkLayout($db, $l['draft_json']);
$docs = [];
if (can('billing.view')) {
    $q = ['invoice' => "SELECT id, number FROM invoices WHERE status <> 'draft' ORDER BY id DESC LIMIT 25", 'receipt' => "SELECT id, number FROM receipts ORDER BY id DESC LIMIT 25",
          'credit_note' => "SELECT id, number FROM credit_notes ORDER BY id DESC LIMIT 25"][$l['document_type']] ?? null;
    if ($q) $docs = $db->query($q)->fetchAll();
}
$boot = [
    'id' => (int)$l['id'], 'name' => $l['name'], 'type' => $l['document_type'], 'typeName' => LAYOUT_DOC_TYPES[$l['document_type']] ?? $l['document_type'],
    'invoiceType' => $l['invoice_type'], 'branchId' => $l['branch_id'] ? (int)$l['branch_id'] : 0, 'status' => $l['status'], 'version' => $l['current_version'] ? (int)$l['current_version'] : 0,
    'layout' => $L, 'locked' => layoutLocked($l['document_type']), 'problems' => layoutRequiredProblems($L, $l['document_type']),
    'blocks' => array_map(fn($b) => ['name' => $b[0], 'icon' => $b[1], 'fields' => $b[2], 'props' => $b[3]], LAYOUT_BLOCKS),
    'labels' => FIELD_LABELS, 'columns' => LINE_COLUMNS, 'merge' => array_map(fn($m) => $m[0], MERGE_FIELDS), 'fonts' => LAYOUT_FONTS,
    'conditions' => LAYOUT_CONDITIONS + array_combine(array_map(fn($b) => 'branch:' . $b['id'], branchList($db)), array_map(fn($b) => 'Branch is ' . $b['name'], branchList($db))),
    'branches' => array_values(array_map(fn($b) => ['id' => (int)$b['id'], 'name' => $b['name']], branchList($db))),
    'sizes' => array_map(fn($s) => $s[0], LAYOUT_PAGE_SIZES),
    'assets' => array_map(fn($a) => ['id' => (int)$a['id'], 'url' => '/pages/layout_api.php?action=asset&id=' . (int)$a['id']], $db->query("SELECT id FROM document_layout_assets ORDER BY id DESC")->fetchAll()),
    'docs' => $docs, 'csrf' => csrfToken(),
];
$pageTitle = 'Design: ' . $l['name'];
$pageSubtitle = sanitize(LAYOUT_DOC_TYPES[$l['document_type']] ?? '') . ' · ' . ($l['current_version'] ? 'published version ' . (int)$l['current_version'] : 'not published yet');
$pageBack = ['href' => '/pages/layouts.php', 'label' => 'Document layouts'];
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .ld-top { position: sticky; top: 0; z-index: 20; background: var(--bg, #faf8f5); padding: 8px 0; }
  .ld-wrap { display: grid; grid-template-columns: 200px minmax(0, 1fr) 320px; gap: 12px; align-items: start; }
  @media (max-width: 1199px) { .ld-wrap { grid-template-columns: 160px minmax(0, 1fr); } .ld-props { grid-column: 1 / -1; } }
  .ld-palette { position: sticky; top: 70px; max-height: calc(100vh - 90px); overflow: auto; }
  .ld-pal-item { display: flex; align-items: center; gap: 8px; padding: 8px 10px; margin-bottom: 4px; border: 1px solid var(--border, #e5ded3); border-radius: 8px; background: #fff; cursor: grab; font-size: 13px; touch-action: none; min-height: 40px; }
  .ld-stage { overflow: auto; background: #e9e5df; border-radius: 10px; padding: 16px; min-height: 70vh; }
  .ld-paper { background: #fff; margin: 0 auto; box-shadow: 0 1px 6px rgba(0,0,0,.18); transform-origin: top center; padding: 10px; }
  .ld-area { border: 1px dashed #cfc6b8; border-radius: 6px; margin-bottom: 10px; padding: 6px; }
  .ld-area-label { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #8a8175; margin-bottom: 4px; display: flex; gap: 6px; align-items: center; }
  .ld-row { display: flex; gap: 4px; align-items: stretch; margin-bottom: 4px; }
  .ld-row-handle { cursor: grab; color: #a49a8c; padding: 4px 2px; touch-action: none; display: flex; align-items: center; }
  .ld-blocks { flex: 1; display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 4px; min-height: 44px; border-radius: 4px; background: repeating-linear-gradient(90deg, #faf7f2 0 calc(100%/12 - 1px), #efe9df calc(100%/12 - 1px) calc(100%/12)); }
  .ld-block { position: relative; border: 1px solid #d8cfc1; background: #fffdf9; border-radius: 6px; padding: 6px 14px 6px 8px; font-size: 12px; cursor: grab; min-height: 44px; overflow: hidden; touch-action: none; }
  .ld-block:focus, .ld-block.sel { outline: 2px solid var(--gold, #b8925a); outline-offset: 1px; }
  .ld-block .nm { font-weight: 600; display: flex; gap: 4px; align-items: center; } .ld-block .sm { color: #7a7166; font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .ld-block .lk { color: #b8925a; } .ld-block.cond { border-style: dashed; }
  .ld-block img { max-height: 30px; max-width: 100%; display: block; }
  .ld-resize { position: absolute; top: 0; right: 0; bottom: 0; width: 10px; cursor: ew-resize; background: linear-gradient(90deg, transparent 3px, #cdbfa9 3px 5px, transparent 5px); touch-action: none; }
  .ld-row-del { border: 0; background: none; color: #a49a8c; padding: 0 4px; }
  .ld-props { position: sticky; top: 70px; max-height: calc(100vh - 90px); overflow: auto; }
  .ld-props .form-label { font-size: 12px; margin-bottom: 2px; } .ld-props .form-control, .ld-props .form-select { font-size: 13px; }
  .ld-col { display: grid; grid-template-columns: 20px 22px 1fr 1fr 54px 64px; gap: 4px; align-items: center; margin-bottom: 4px; }
  .ld-col .h { cursor: grab; color: #a49a8c; touch-action: none; }
  .ld-problems { white-space: pre-line; }
  .sortable-ghost { opacity: .35; } .ld-preview { width: 100%; height: 80vh; border: 0; background: #e9e5df; border-radius: 10px; }
  .btn-ld { min-height: 40px; }
</style>
<div class="ld-top d-flex flex-wrap gap-2 align-items-center">
  <div class="btn-group" role="group" aria-label="History"><button class="btn btn-ghost btn-ld" id="ldUndo" title="Undo (Ctrl+Z)" aria-label="Undo"><i class="bi bi-arrow-counterclockwise"></i></button><button class="btn btn-ghost btn-ld" id="ldRedo" title="Redo (Ctrl+Y)" aria-label="Redo"><i class="bi bi-arrow-clockwise"></i></button></div>
  <div class="btn-group" role="tablist" aria-label="View"><button class="btn btn-ghost btn-ld active" id="ldTabDesign" role="tab" aria-selected="true">Design</button><button class="btn btn-ghost btn-ld" id="ldTabPreview" role="tab" aria-selected="false">Preview</button></div>
  <label class="visually-hidden" for="ldZoom">Zoom</label><select class="form-select form-select-sm" id="ldZoom" style="width:auto"><option value="0.5">50%</option><option value="0.75">75%</option><option value="0.9" selected>90%</option><option value="1">100%</option><option value="1.25">125%</option><option value="1.5">150%</option></select>
  <label class="visually-hidden" for="ldLang">Language</label><select class="form-select form-select-sm" id="ldLang" style="width:auto"><option value="">Layout’s language</option><option value="en">English</option><option value="ar">Arabic</option><option value="both">Both</option></select>
  <label class="visually-hidden" for="ldDoc">Preview data</label><select class="form-select form-select-sm" id="ldDoc" style="width:auto"><option value="">Sample data</option><option value="s60">Sample, 60 items</option>
    <?php foreach ($docs as $d): ?><option value="<?= (int)$d['id'] ?>"><?= sanitize($d['number']) ?></option><?php endforeach; ?></select>
  <span class="ms-auto fs-13 text-tx-3" id="ldStatus" role="status" aria-live="polite">Not changed</span>
  <button class="btn btn-ghost btn-ld" id="ldSave"><i class="bi bi-save"></i>Save draft</button>
  <button class="btn btn-gold btn-ld" id="ldPublish"><i class="bi bi-send-check"></i>Publish</button>
</div>
<div class="alert alert-danger d-none" id="ldError" role="alert"></div>
<div class="alert alert-warning ld-problems d-none" id="ldProblems"></div>
<div class="ld-wrap">
  <aside class="ld-palette card-crm"><div class="card-body-crm p-2">
    <div class="fs-12 text-tx-3 mb-2">Drag a block onto a row, or select a row and press its name.</div>
    <div id="ldPalette"></div>
  </div></aside>
  <main>
    <div class="ld-stage" id="ldStage"><div id="ldPaperWrap"><div class="ld-paper" id="ldPaper"></div></div></div>
    <iframe class="ld-preview d-none" id="ldPreview" title="Preview" sandbox="allow-same-origin"></iframe>
  </main>
  <aside class="ld-props card-crm"><div class="card-body-crm" id="ldProps"></div></aside>
</div>
<script src="/assets/vendor/sortablejs-1.15.6/Sortable.min.js"></script>
<script>window.LD = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="/assets/js/layout-designer.js?v=1"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
