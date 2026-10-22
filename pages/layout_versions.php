<?php
// pages/layout_versions.php?id= — the published versions of a layout (never changed or deleted), with who published
// each and how many documents were printed with it.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/doc_layouts.php';
requirePermission('setup.layouts');

$db = getDB();
$l = findLayout($db, (int)($_GET['id'] ?? 0));
if (!$l) { http_response_code(404); exit('Not found'); }
$s = $db->prepare("SELECT v.id, v.version, v.published_at, v.content_hash, s.name AS by_name, (SELECT COUNT(*) FROM document_renditions r WHERE r.layout_version_id = v.id) AS docs
                   FROM document_layout_versions v LEFT JOIN staff s ON s.id = v.published_by WHERE v.layout_id = ? ORDER BY v.version DESC");
$s->execute([$l['id']]);
$pageTitle = 'Versions of ' . $l['name'];
$pageBack = ['href' => '/pages/layouts.php', 'label' => 'Document layouts'];
require_once __DIR__ . '/../includes/header.php';
?>
<div class="card-crm"><div class="list-group list-group-flush">
  <?php foreach ($s as $v): ?><div class="list-group-item d-flex gap-2 align-items-center">
    <span class="flex-grow-1"><span class="fw-600">Version <?= (int)$v['version'] ?></span><?= (int)$v['id'] === (int)$l['current_version_id'] ? ' <span class="chip tone-green">Current</span>' : '' ?>
      <span class="d-block fs-12 text-tx-3"><?= formatDate($v['published_at']) ?> <?= date('H:i', strtotime($v['published_at'])) ?> · <?= sanitize((string)$v['by_name']) ?> · <?= (int)$v['docs'] ?> document<?= $v['docs'] == 1 ? '' : 's' ?> printed with it · <span class="tabular"><?= substr($v['content_hash'], 0, 12) ?></span></span></span>
    <a class="btn btn-ghost btn-sm" href="/pages/layout_preview.php?id=<?= (int)$l['id'] ?>&amp;version=<?= (int)$v['id'] ?>" target="_blank"><i class="bi bi-eye"></i>Preview</a></div><?php endforeach; ?>
  <?php if (!$l['current_version_id']): ?><div class="list-group-item text-tx-3">Not published yet.</div><?php endif; ?>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
