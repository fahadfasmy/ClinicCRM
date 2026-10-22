<?php
// pages/treatments.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && can('setup.treatments')) {
    $action = $_POST['action'] ?? '';
    $tid    = (int)($_POST['treatment_id'] ?? 0);
    $name   = trim($_POST['name'] ?? '');
    $cat    = trim($_POST['category'] ?? '');
    $price  = (float)($_POST['avg_price'] ?? 0);
    $order  = (int)($_POST['sort_order'] ?? 0);

    if ($action === 'add') {
        if (!$name) { $error = 'Name required.'; }
        else {
            $db->prepare("INSERT INTO treatments (name,category,avg_price,sort_order) VALUES (?,?,?,?)")
               ->execute([$name,$cat,$price,$order]);
            $success = "Treatment '{$name}' added.";
        }
    } elseif ($action === 'edit' && $tid) {
        $db->prepare("UPDATE treatments SET name=?,category=?,avg_price=?,sort_order=? WHERE id=?")
           ->execute([$name,$cat,$price,$order,$tid]);
        $success = 'Treatment updated.';
    } elseif ($action === 'toggle' && $tid) {
        $db->prepare("UPDATE treatments SET is_active=1-is_active WHERE id=?")->execute([$tid]);
        header('Location: /pages/treatments.php'); exit;
    }
    if (!$error && in_array($action, ['add', 'edit'], true)) {
        header('Location: /pages/treatments.php?saved=' . urlencode($success)); exit;
    }
}

$treatments = $db->query("
    SELECT t.*,
           COUNT(lt.lead_id) as interest_count
    FROM treatments t
    LEFT JOIN lead_treatments lt ON lt.treatment_id = t.id
    GROUP BY t.id ORDER BY t.sort_order, t.name
")->fetchAll();

$editT = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM treatments WHERE id=?");
    $stmt->execute([(int)$_GET['edit']]);
    $editT = $stmt->fetch();
}

$groups = [];
foreach ($treatments as $t) {
    $groups[ucwords(strtolower(trim($t['category'] ?: 'Uncategorised')))][] = $t;
}
ksort($groups);
$categories = array_keys($groups);
$noPrice = count(array_filter($treatments, fn($t) => $t['is_active'] && (float)$t['avg_price'] <= 0));
if (isset($_GET['saved'])) $success = $_GET['saved'];

$pageTitle    = 'Treatments';
$pageSubtitle = count($treatments) . ' treatments in ' . count($groups) . ' categories';
$pageActions  = can('setup.treatments') && $editT ? '<a href="/pages/treatments.php" class="btn btn-gold"><i class="bi bi-plus-lg"></i>Add treatment</a>' : '';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($success): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i><?= sanitize($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><?= sanitize($error) ?></div><?php endif; ?>
<?php if ($noPrice && can('setup.treatments')): ?>
<div class="alert alert-warning"><i class="bi bi-info-circle"></i><div><strong><?= $noPrice ?> active treatments have no average price.</strong> Lead scores and revenue estimates count them as AED 0. Add a price to make analytics more accurate.</div></div>
<?php endif; ?>

<div class="row g-3">
  <div class="<?= can('setup.treatments') ? 'col-xl-8' : 'col-12' ?>">
    <div class="card-crm">
      <div class="card-header-crm flex-wrap">
        <span><i class="bi bi-clipboard2-pulse"></i> Catalogue</span>
        <input type="search" class="form-control form-control-sm" id="treatSearch" placeholder="Search treatments…" style="max-width:240px" aria-label="Search treatments">
      </div>
      <div class="table-responsive">
      <table class="table table-hover" id="treatTable">
        <thead>
          <tr><th>Treatment</th><th class="text-end">Avg price</th><th class="text-end">Interested leads</th><th>Status</th><?php if (can('setup.treatments')): ?><th class="text-end"></th><?php endif; ?></tr>
        </thead>
        <?php foreach ($groups as $cat => $items): ?>
        <tbody class="treat-cat">
          <tr class="group-row"><td colspan="<?= can('setup.treatments') ? 5 : 4 ?>"><?= sanitize($cat) ?> <span class="text-tx-3 fw-normal">· <?= count($items) ?></span></td></tr>
          <?php foreach ($items as $t): ?>
          <tr class="<?= !$t['is_active'] ? 'opacity-50' : '' ?> <?= $editT && $editT['id'] == $t['id'] ? 'table-active' : '' ?>" data-name="<?= sanitize(strtolower($t['name'])) ?>">
            <td class="fw-500"><?= sanitize($t['name']) ?></td>
            <td class="text-end tabular"><?= (float)$t['avg_price'] > 0 ? 'AED ' . number_format($t['avg_price'], 0) : '<span class="text-amber fs-12">Not set</span>' ?></td>
            <td class="text-end tabular"><?= (int)$t['interest_count'] ?></td>
            <td><span class="chip <?= $t['is_active'] ? 'aging-fresh' : '' ?>"><?= $t['is_active'] ? 'Active' : 'Hidden' ?></span></td>
            <?php if (can('setup.treatments')): ?>
            <td class="text-end">
              <div class="row-actions">
                <a href="?edit=<?= $t['id'] ?>" class="btn-icon" title="Edit"><i class="bi bi-pencil"></i></a>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="action" value="toggle"><input type="hidden" name="treatment_id" value="<?= $t['id'] ?>">
                  <button type="submit" class="btn-icon" title="<?= $t['is_active'] ? 'Hide from lead forms' : 'Show in lead forms' ?>"><i class="bi bi-<?= $t['is_active'] ? 'eye-slash' : 'eye' ?>"></i></button>
                </form>
              </div>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <?php endforeach; ?>
      </table>
      </div>
    </div>
  </div>

  <?php if (can('setup.treatments')): ?>
  <div class="col-xl-4">
    <div class="card-crm form-side">
      <div class="card-header-crm"><span><i class="bi bi-<?= $editT ? 'pencil' : 'plus-lg' ?>"></i> <?= $editT ? 'Edit treatment' : 'Add treatment' ?></span></div>
      <form method="POST">
        <div class="card-body-crm">
          <input type="hidden" name="action" value="<?= $editT ? 'edit' : 'add' ?>">
          <?php if ($editT): ?><input type="hidden" name="treatment_id" value="<?= $editT['id'] ?>"><?php endif; ?>
          <div class="mb-3">
            <label class="form-label" for="tName">Name <span class="req">*</span></label>
            <input type="text" name="name" id="tName" class="form-control" required value="<?= sanitize($editT['name'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label" for="tCat">Category</label>
            <input type="text" name="category" id="tCat" class="form-control" list="catList" placeholder="e.g. Injectables" value="<?= sanitize($editT['category'] ?? '') ?>">
            <datalist id="catList"><?php foreach ($categories as $c): ?><option value="<?= sanitize($c) ?>"><?php endforeach; ?></datalist>
            <div class="form-text">Pick an existing category to keep the list tidy.</div>
          </div>
          <div class="row g-3">
            <div class="col-7">
              <label class="form-label" for="tPrice">Average price</label>
              <div class="input-group">
                <span class="input-group-text">AED</span>
                <input type="number" name="avg_price" id="tPrice" class="form-control" min="0" step="50" value="<?= (float)($editT['avg_price'] ?? 0) ?>">
              </div>
            </div>
            <div class="col-5">
              <label class="form-label" for="tOrder">Sort order</label>
              <input type="number" name="sort_order" id="tOrder" class="form-control" min="0" value="<?= (int)($editT['sort_order'] ?? 0) ?>">
            </div>
          </div>
        </div>
        <div class="form-actions">
          <?php if ($editT): ?><a href="/pages/treatments.php" class="btn btn-ghost">Cancel</a><?php endif; ?>
          <button type="submit" class="btn btn-gold"><i class="bi bi-check-lg"></i><?= $editT ? 'Save changes' : 'Add treatment' ?></button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php
$extraJs = "
document.getElementById('treatSearch').addEventListener('input', e => {
  const q = e.target.value.trim().toLowerCase();
  document.querySelectorAll('#treatTable tbody.treat-cat').forEach(tb => {
    let any = false;
    tb.querySelectorAll('tr[data-name]').forEach(r => { const m = !q || r.dataset.name.includes(q); r.classList.toggle('d-none', !m); any = any || m; });
    tb.classList.toggle('d-none', !any);
  });
});
";
require_once __DIR__ . '/../includes/footer.php';
?>
