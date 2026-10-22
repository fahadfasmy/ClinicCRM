<?php
// pages/layouts.php — Admin → Document layouts: every layout with its document type, language, paper, branch, status
// and default badge. Edit (designer), duplicate, preview, versions, set as default, archive, export / import JSON.
// Imports pass the same validation as the designer. Four starter layouts are made the first time.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/doc_layouts.php';
requirePermission('setup.layouts');

$db = getDB();
ensureStarterLayouts($db);
if (($_GET['export'] ?? '') !== '' && ($l = findLayout($db, (int)$_GET['export']))) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="layout-' . preg_replace('/[^a-z0-9]+/i', '-', $l['name']) . '.json"');
    echo json_encode(['document_type' => $l['document_type'], 'name' => $l['name'], 'layout' => json_decode($l['draft_json'], true)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $id = (int)($_POST['id'] ?? 0);
    try {
        switch ($_POST['action'] ?? '') {
            case 'duplicate': $new = duplicateLayout($db, $id); header("Location: /pages/layout_designer.php?id=$new"); exit;
            case 'default': setDefaultLayout($db, $id); $msg = 'Default layout changed. New documents use it.'; break;
            case 'archive': archiveLayout($db, $id); $msg = 'Layout archived.'; break;
            case 'import':
                $f = $_FILES['file'] ?? [];
                if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > LAYOUT_MAX_BYTES + 4096) throw new RuntimeException('Choose a layout file (.json, 200 KB at most).');
                $j = json_decode((string)file_get_contents($f['tmp_name']), true);
                if (!is_array($j) || !isset(LAYOUT_DOC_TYPES[$j['document_type'] ?? '']) || !is_array($j['layout'] ?? null)) throw new RuntimeException('That file is not a layout exported from this system.');
                $new = createLayout($db, $j['document_type'], 'Imported: ' . mb_substr((string)($j['name'] ?? 'layout'), 0, 80), $j['layout']);
                auditLog($db, 'layout', $new, 'imported', []);
                header("Location: /pages/layout_designer.php?id=$new"); exit;
            default: throw new RuntimeException('Unknown action.');
        }
        $_SESSION['setup_flash'] = ['success', $msg];
    } catch (InvalidArgumentException $e) {
        $_SESSION['setup_flash'] = ['danger', "The layout isn’t valid:\n" . $e->getMessage()];
    } catch (Throwable $e) {
        $_SESSION['setup_flash'] = ['danger', $e->getMessage()];
    }
    header('Location: /pages/layouts.php'); exit;
}
$flash = $_SESSION['setup_flash'] ?? null;
unset($_SESSION['setup_flash']);
$rows = $db->query("SELECT l.*, v.version, b.name AS branch_name, s.name AS by_name FROM document_layouts l LEFT JOIN document_layout_versions v ON v.id = l.current_version_id
                    LEFT JOIN branches b ON b.id = l.branch_id LEFT JOIN staff s ON s.id = l.updated_by ORDER BY l.status = 'archived', FIELD(l.document_type, 'invoice', 'receipt', 'credit_note'), l.is_default DESC, l.name")->fetchAll();
$langs = ['en' => 'English', 'ar' => 'Arabic', 'both' => 'English + Arabic'];
$papers = ['A4' => 'A4', 'A5' => 'A5', 'Letter' => 'Letter', 'roll80' => '80 mm roll'];

$pageTitle = 'Document layouts';
$pageSubtitle = 'How invoices, receipts and credit notes look';
$pageBack = ['href' => '/pages/settings_clinic.php', 'label' => 'Clinic setup'];
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?> <?= $flash[0] === 'success' ? 'alert-auto' : '' ?>"><i class="bi bi-<?= $flash[0] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i><div><strong><?= nl2br(sanitize($flash[1])) ?></strong></div></div><?php endif; ?>
<div class="card-crm mb-3"><div class="table-responsive"><table class="table mb-0 align-middle">
  <thead><tr><th>Layout</th><th>Document</th><th>Language</th><th>Paper</th><th>Used for</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
  <tbody><?php foreach ($rows as $l): $arch = $l['status'] === 'archived'; ?>
    <tr class="<?= $arch ? 'text-tx-3' : '' ?>">
      <td><a class="fw-600" href="/pages/layout_designer.php?id=<?= (int)$l['id'] ?>"><?= sanitize($l['name']) ?></a><?= $l['is_default'] ? ' <span class="chip tone-gold">Default</span>' : '' ?>
        <span class="cell-sub"><?= $l['version'] ? 'version ' . (int)$l['version'] : 'never published' ?><?= $l['draft_saved_at'] && $l['by_name'] ? ' · edited by ' . sanitize($l['by_name']) : '' ?></span></td>
      <td><?= LAYOUT_DOC_TYPES[$l['document_type']] ?? sanitize($l['document_type']) ?></td><td><?= $langs[$l['language']] ?></td><td><?= $papers[$l['page_size']] ?><?= $l['orientation'] === 'landscape' ? ' (landscape)' : '' ?></td>
      <td class="fs-13"><?= $l['branch_name'] ? sanitize($l['branch_name']) : 'All branches' ?><?= $l['invoice_type'] !== 'any' ? ' · ' . $l['invoice_type'] . ' invoices' : '' ?></td>
      <td><span class="chip <?= ['draft' => 'tone-slate', 'published' => 'tone-green', 'archived' => 'tone-slate'][$l['status']] ?>"><?= ucfirst($l['status']) ?></span></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-ghost btn-sm" href="/pages/layout_preview.php?id=<?= (int)$l['id'] ?>" target="_blank" aria-label="Preview <?= sanitize($l['name']) ?>"><i class="bi bi-eye"></i></a>
        <a class="btn btn-ghost btn-sm" href="/pages/layout_versions.php?id=<?= (int)$l['id'] ?>" aria-label="Versions"><i class="bi bi-clock-history"></i></a>
        <a class="btn btn-ghost btn-sm" href="?export=<?= (int)$l['id'] ?>" aria-label="Export"><i class="bi bi-download"></i></a>
        <form method="POST" class="d-inline"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="btn btn-ghost btn-sm" name="action" value="duplicate" aria-label="Duplicate"><i class="bi bi-copy"></i></button>
          <?php if ($l['status'] === 'published' && !$l['is_default']): ?><button class="btn btn-ghost btn-sm" name="action" value="default">Make default</button><?php endif; ?>
          <?php if (!$arch && !$l['is_default']): ?><button class="btn btn-ghost btn-sm" name="action" value="archive" onclick="return confirm('Archive this layout?')" aria-label="Archive"><i class="bi bi-archive"></i></button><?php endif; ?></form>
      </td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<form method="POST" enctype="multipart/form-data" class="card-crm" style="max-width:560px"><?= csrfField() ?><input type="hidden" name="action" value="import">
  <div class="card-header-crm"><span><i class="bi bi-upload"></i> Import a layout</span></div>
  <div class="card-body-crm"><label class="form-label" for="imp">Layout file exported from this system (.json)</label><input class="form-control" type="file" id="imp" name="file" accept=".json,application/json" required></div>
  <div class="form-actions"><button class="btn btn-ghost ms-auto">Import</button></div></form>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
