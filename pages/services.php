<?php
// pages/services.php — bookable treatments and their product recipes.  ?id=ID / ?new=1 opens the editor.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';
requirePermission('inventory.view');

$db = getDB();
ensureInventorySchema($db);
$error = '';

$id     = (int)($_GET['id'] ?? 0);
$isEdit = $id || isset($_GET['new']);
$svc = null;
if ($id) {
    $s = $db->prepare("SELECT * FROM services WHERE id = ?");
    $s->execute([$id]);
    $svc = $s->fetch() ?: null;
    if (!$svc) { http_response_code(404); die('Treatment not found.'); }
}

// Estimated cost per usage unit: average purchase cost of batches still in stock (else the latest batch)
$unitCosts = [];
foreach ($db->query("
    SELECT p.id, p.units_per_stock,
      (SELECT AVG(l.unit_cost) FROM stock_lots l WHERE l.product_id = p.id AND l.unit_cost IS NOT NULL AND l.qty_on_hand > 0) AS avg_cost,
      (SELECT l.unit_cost FROM stock_lots l WHERE l.product_id = p.id AND l.unit_cost IS NOT NULL ORDER BY l.id DESC LIMIT 1) AS last_cost
    FROM products p")->fetchAll() as $r) {
    $c = $r['avg_cost'] ?? $r['last_cost'];
    if ($c !== null) $unitCosts[(int)$r['id']] = (float)$c / max(0.001, (float)$r['units_per_stock']);
}

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['bulk']) || ($_POST['action'] ?? '') === 'delete')) {
    requirePermission('inventory.manage');
    requireCsrf();
    try {
        $res = isset($_POST['bulk'])
            ? bulkCatalogAction($db, 'service', (string)$_POST['bulk'], (array)($_POST['ids'] ?? []))
            : ['done' => [deleteService($db, (int)($svc['id'] ?? 0))], 'failed' => []];
        $_SESSION['bulk_flash'] = ['action' => $_POST['bulk'] ?? 'delete'] + $res;
        header('Location: /pages/services.php'); exit;
    } catch (StockException $e) {
        $error = $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('inventory.manage');
    requireCsrf();
    try {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') throw new StockException('Enter the treatment name.');
        $f = [$name, trim($_POST['category'] ?? '') ?: null, max(0, (float)($_POST['price'] ?? 0)),
              max(5, (int)($_POST['duration_min'] ?? 30)), trim($_POST['description'] ?? '') ?: null, isset($_POST['is_active']) || !$svc ? 1 : 0,
              mb_substr(trim($_POST['name_ar'] ?? ''), 0, 120) ?: null, ($_POST['vat_rate'] ?? '5') === '0' ? 0 : 5, mb_substr(trim($_POST['cpt_code'] ?? ''), 0, 10) ?: null, isset($_POST['is_online_bookable']) ? 1 : 0];

        $items = [];
        foreach ((array)($_POST['item_product'] ?? []) as $i => $pid) {
            $pid = (int)$pid;
            if (!$pid) continue;
            $qty = (float)($_POST['item_qty'][$i] ?? 0);
            $min = ($_POST['item_min'][$i] ?? '') === '' ? null : (float)$_POST['item_min'][$i];
            $max = ($_POST['item_max'][$i] ?? '') === '' ? null : (float)$_POST['item_max'][$i];
            if ($qty <= 0) throw new StockException('Each product in the recipe needs a usual amount above zero.');
            if ($min !== null && $max !== null && $min > $max) throw new StockException('A minimum is larger than its maximum.');
            if (($min !== null && $qty < $min) || ($max !== null && $qty > $max)) throw new StockException('A usual amount is outside its own allowed range.');
            if (isset($items[$pid])) throw new StockException('A product is listed twice in the recipe. Combine them into one line.');
            $items[$pid] = [$qty, $min, $max];
        }
        $tags = array_map('intval', (array)($_POST['treatments'] ?? []));

        $db->beginTransaction();
        if ($svc) {
            $db->prepare("UPDATE services SET name=?, category=?, price=?, duration_min=?, description=?, is_active=?, name_ar=?, vat_rate=?, cpt_code=?, is_online_bookable=? WHERE id=?")->execute([...$f, $svc['id']]);
            $sid = (int)$svc['id'];
        } else {
            $db->prepare("INSERT INTO services (name, category, price, duration_min, description, is_active, name_ar, vat_rate, cpt_code, is_online_bookable) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute($f);
            $sid = (int)$db->lastInsertId();
        }
        // Recipe changes apply to new bookings only; booked appointments keep the amounts they reserved.
        $db->prepare("DELETE FROM service_items WHERE service_id = ?")->execute([$sid]);
        $ins = $db->prepare("INSERT INTO service_items (service_id, product_id, qty, qty_min, qty_max) VALUES (?,?,?,?,?)");
        foreach ($items as $pid => [$q, $mn, $mx]) $ins->execute([$sid, $pid, $q, $mn, $mx]);
        $db->prepare("DELETE FROM service_treatments WHERE service_id = ?")->execute([$sid]);
        $insT = $db->prepare("INSERT IGNORE INTO service_treatments (service_id, treatment_id) VALUES (?,?)");
        foreach ($tags as $t) $insT->execute([$sid, $t]);
        $db->commit();
        logActivity(currentStaffId(), $svc ? 'service_updated' : 'service_created', null, null, $name);
        header('Location: /pages/services.php?saved=' . $sid); exit;
    } catch (StockException $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

// ─────────── EDITOR ───────────
if ($isEdit) {
    requirePermission('inventory.manage');
    $products = $db->query("SELECT id, name, usage_unit, category FROM products WHERE is_active = 1 ORDER BY category, name")->fetchAll();
    $treatments = $db->query("SELECT id, name, category FROM treatments WHERE is_active = 1 ORDER BY category, name")->fetchAll();
    if ($error) {
        $rows = [];
        foreach ((array)($_POST['item_product'] ?? []) as $i => $pid) $rows[] = ['product_id' => (int)$pid, 'qty' => $_POST['item_qty'][$i] ?? '', 'qty_min' => $_POST['item_min'][$i] ?? '', 'qty_max' => $_POST['item_max'][$i] ?? ''];
        $tagIds = array_map('intval', (array)($_POST['treatments'] ?? []));
    } else {
        $rows = [];
        $tagIds = [];
        if ($svc) {
            $s = $db->prepare("SELECT * FROM service_items WHERE service_id = ? ORDER BY id");
            $s->execute([$svc['id']]);
            $rows = $s->fetchAll();
            $s = $db->prepare("SELECT treatment_id FROM service_treatments WHERE service_id = ?");
            $s->execute([$svc['id']]);
            $tagIds = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        }
    }
    if (!$rows) $rows[] = ['product_id' => 0, 'qty' => '', 'qty_min' => '', 'qty_max' => ''];
    $v = fn($k, $d = '') => sanitize((string)($_POST[$k] ?? $svc[$k] ?? $d));
    $cats = $db->query("SELECT DISTINCT category FROM services WHERE category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
    $tagGroups = [];
    foreach ($treatments as $t) $tagGroups[ucwords(strtolower(trim($t['category'] ?: 'Other')))][] = $t;
    ksort($tagGroups);

    $productOptions = function (int $selected) use ($products): string {
        $h = '<option value="">Choose product…</option>'; $cat = null;
        foreach ($products as $p) {
            if ($p['category'] !== $cat) { if ($cat !== null) $h .= '</optgroup>'; $cat = $p['category']; $h .= '<optgroup label="' . sanitize($cat ?: 'Uncategorised') . '">'; }
            $h .= '<option value="' . $p['id'] . '" data-unit="' . sanitize($p['usage_unit']) . '"' . ($selected === (int)$p['id'] ? ' selected' : '') . '>' . sanitize($p['name']) . '</option>';
        }
        return $h . ($cat !== null ? '</optgroup>' : '');
    };

    $pageTitle = $svc ? $svc['name'] : 'New treatment';
    $pageBack  = ['href' => '/pages/services.php', 'label' => 'Treatment menu'];
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><?= sanitize($error) ?></div><?php endif; ?>
    <?php if (!$products): ?><div class="alert alert-warning"><i class="bi bi-info-circle"></i><div>No products yet. <a href="/pages/product.php?new=1">Add products</a> before building recipes.</div></div><?php endif; ?>
    <form method="POST" class="card-crm" style="max-width:980px">
      <?= csrfField() ?>
      <div class="form-section">
        <h3 class="form-section-title">Treatment</h3>
        <p class="form-section-sub">What reception books and the client pays for.</p>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label" for="sName">Name <span class="req">*</span></label><input class="form-control" id="sName" name="name" required value="<?= $v('name') ?>" placeholder="e.g. Anti-wrinkle, upper face"></div>
          <div class="col-md-6"><label class="form-label" for="sCat">Category</label><input class="form-control" id="sCat" name="category" list="sCats" value="<?= $v('category') ?>">
            <datalist id="sCats"><?php foreach (array_unique([...$cats, 'Injectables', 'Skin', 'Laser', 'Hair', 'Body', 'Dental']) as $c): ?><option value="<?= sanitize($c) ?>"><?php endforeach; ?></datalist></div>
          <div class="col-md-3"><label class="form-label" for="sPrice">Price</label><div class="input-group"><span class="input-group-text">AED</span><input type="number" class="form-control" id="sPrice" name="price" min="0" step="0.01" value="<?= $v('price', '0') ?>"></div></div>
          <div class="col-md-3"><label class="form-label" for="sDur">Duration</label><div class="input-group"><input type="number" class="form-control" id="sDur" name="duration_min" min="5" step="5" value="<?= $v('duration_min', '30') ?>"><span class="input-group-text">min</span></div></div>
          <div class="col-md-6"><label class="form-label" for="sNameAr">Name (Arabic)</label><input class="form-control" id="sNameAr" name="name_ar" dir="rtl" lang="ar" maxlength="120" value="<?= $v('name_ar') ?>"></div>
          <div class="col-6 col-md-3"><label class="form-label" for="sVat">VAT</label><select class="form-select" id="sVat" name="vat_rate"><option value="5">5%</option><option value="0" <?= $v('vat_rate', '5') === '0' || $v('vat_rate', '5') === '0.00' ? 'selected' : '' ?>>0% (zero-rated)</option></select></div>
          <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="sOnline" name="is_online_bookable" value="1" <?= ($error ? isset($_POST['is_online_bookable']) : !empty($svc['is_online_bookable'])) ? 'checked' : '' ?>><label class="form-check-label" for="sOnline">Patients can book this online</label></div></div>
          <div class="col-6 col-md-3"><label class="form-label" for="sCpt">CPT code</label><input class="form-control" id="sCpt" name="cpt_code" maxlength="10" value="<?= $v('cpt_code') ?>"></div>
          <?php if ($svc): ?><div class="col-md-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" id="sAct" name="is_active" <?= ($error ? isset($_POST['is_active']) : $svc['is_active']) ? 'checked' : '' ?>><label class="form-check-label" for="sAct">Bookable</label></div></div><?php endif; ?>
          <div class="col-12"><label class="form-label" for="sDesc">Description</label><textarea class="form-control" id="sDesc" name="description" rows="2"><?= $v('description') ?></textarea></div>
        </div>
      </div>

      <div class="form-section">
        <h3 class="form-section-title">Products used</h3>
        <p class="form-section-sub">The usual amount is reserved when this treatment is booked. At completion the practitioner records what was actually used; amounts outside the allowed range need a reason.</p>
        <div class="table-responsive">
        <table class="table mb-2" id="recipe">
          <thead><tr><th style="min-width:240px">Product</th><th>Usual amount</th><th>Allowed min</th><th>Allowed max</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><select class="form-select form-select-sm item-product" name="item_product[]" aria-label="Product"><?= $productOptions((int)$r['product_id']) ?></select></td>
              <td><div class="input-group input-group-sm"><input type="number" class="form-control" name="item_qty[]" min="0.001" step="any" value="<?= sanitize((string)($r['qty'] === '' ? '' : fmtQty($r['qty']))) ?>" aria-label="Usual amount"><span class="input-group-text unit">–</span></div></td>
              <td><input type="number" class="form-control form-control-sm" name="item_min[]" min="0" step="any" value="<?= sanitize((string)($r['qty_min'] === null || $r['qty_min'] === '' ? '' : fmtQty($r['qty_min']))) ?>" aria-label="Minimum"></td>
              <td><input type="number" class="form-control form-control-sm" name="item_max[]" min="0" step="any" value="<?= sanitize((string)($r['qty_max'] === null || $r['qty_max'] === '' ? '' : fmtQty($r['qty_max']))) ?>" aria-label="Maximum"></td>
              <td class="text-end"><button type="button" class="btn-icon danger remove-row" title="Remove" aria-label="Remove product"><i class="bi bi-x-lg"></i></button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <button type="button" class="btn btn-ghost btn-sm" id="addRow"><i class="bi bi-plus-lg"></i>Add product</button>
      </div>

      <div class="form-section">
        <h3 class="form-section-title">Matches these interests</h3>
        <p class="form-section-sub">Leads tagged with any of these are offered this treatment first when booking.</p>
        <div class="treat-picker">
          <?php foreach ($tagGroups as $g => $ts): ?>
          <div class="treat-group"><div class="treat-group-label"><?= sanitize($g) ?></div>
            <div class="d-flex flex-wrap gap-2">
              <?php foreach ($ts as $t): ?>
              <label class="pill-check"><input type="checkbox" name="treatments[]" value="<?= $t['id'] ?>" <?= in_array((int)$t['id'], $tagIds, true) ? 'checked' : '' ?>><span><?= sanitize($t['name']) ?></span></label>
              <?php endforeach; ?>
            </div></div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-actions">
        <?php if ($svc): $blockers = serviceDeleteBlockers($db, (int)$svc['id']); ?>
          <?php if ($blockers): ?><span class="fs-12 text-tx-3 me-auto">Can’t be deleted because <?= sanitize(listAnd($blockers)) ?>. Untick Bookable to hide it.</span>
          <?php else: ?><button type="submit" form="delForm" class="btn btn-ghost text-danger me-auto"><i class="bi bi-trash3"></i>Delete treatment</button><?php endif; ?>
        <?php endif; ?>
        <a href="/pages/services.php" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-gold"><i class="bi bi-check-lg"></i><?= $svc ? 'Save changes' : 'Add treatment' ?></button>
      </div>
    </form>
    <?php if ($svc && !$blockers): ?>
    <form method="POST" id="delForm" onsubmit="return confirm('Delete this treatment and its recipe? This can’t be undone.')"><?= csrfField() ?><input type="hidden" name="action" value="delete"></form>
    <?php endif; ?>
    <?php
    $extraJs = "
    const tbody = document.querySelector('#recipe tbody');
    const syncUnit = tr => { const o = tr.querySelector('.item-product').selectedOptions[0]; tr.querySelector('.unit').textContent = o && o.dataset.unit ? o.dataset.unit : '–'; };
    tbody.querySelectorAll('tr').forEach(syncUnit);
    tbody.addEventListener('change', e => { if (e.target.classList.contains('item-product')) syncUnit(e.target.closest('tr')); });
    tbody.addEventListener('click', e => {
      const b = e.target.closest('.remove-row'); if (!b) return;
      const tr = b.closest('tr');
      if (tbody.rows.length > 1) tr.remove(); else tr.querySelectorAll('input,select').forEach(i => i.value = '');
    });
    document.getElementById('addRow').addEventListener('click', () => {
      const tr = tbody.rows[0].cloneNode(true);
      tr.querySelectorAll('input,select').forEach(i => i.value = '');
      tbody.appendChild(tr); syncUnit(tr); tr.querySelector('select').focus();
    });";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// ─────────── LIST ───────────
$services = $db->query("
    SELECT s.*,
      (SELECT COUNT(*) FROM service_items si WHERE si.service_id = s.id) AS item_count,
      (SELECT COUNT(*) FROM appointment_services x JOIN appointments a ON a.id = x.appointment_id WHERE x.service_id = s.id AND a.status IN ('booked','confirmed','arrived','in_triage','with_doctor')) AS booked_count,
      (SELECT COUNT(*) FROM appointments a WHERE a.service_id = s.id AND a.status = 'completed') AS done_count,
      (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') FROM service_treatments st JOIN treatments t ON t.id = st.treatment_id WHERE st.service_id = s.id) AS tags
    FROM services s ORDER BY s.is_active DESC, s.category, s.sort_order, s.name")->fetchAll();
$recipeCost = [];
foreach ($db->query("SELECT service_id, product_id, qty FROM service_items")->fetchAll() as $r) {
    $sid = (int)$r['service_id'];
    $recipeCost[$sid] ??= ['cost' => 0.0, 'missing' => false];
    if (isset($unitCosts[(int)$r['product_id']])) $recipeCost[$sid]['cost'] += (float)$r['qty'] * $unitCosts[(int)$r['product_id']];
    else $recipeCost[$sid]['missing'] = true;
}
$unmapped = (int)$db->query("SELECT COUNT(*) FROM treatments t WHERE t.is_active = 1 AND NOT EXISTS (SELECT 1 FROM service_treatments st WHERE st.treatment_id = t.id)")->fetchColumn();

$pageTitle    = 'Treatment menu';
$pageSubtitle = count($services) . ' bookable treatments with their product recipes';
$pageActions  = can('inventory.manage') ? '<a href="/pages/stock_import.php?kind=treatments#treatments" class="btn btn-ghost"><i class="bi bi-file-earmark-spreadsheet"></i>Update prices from a spreadsheet</a><a href="/pages/services.php?new=1" class="btn btn-gold"><i class="bi bi-plus-lg"></i>New treatment</a>' : '';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i> Treatment saved. New bookings use the updated recipe.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><?= sanitize($error) ?></div><?php endif; ?>
<?php include __DIR__ . '/../includes/bulk_flash.php'; ?>
<?php if ($unmapped && can('inventory.manage') && $services): ?>
<div class="alert alert-info"><i class="bi bi-info-circle"></i><div><strong><?= $unmapped ?> interest tags aren’t linked to a treatment yet.</strong> Link them from each treatment’s “Matches these interests” section so booking can suggest the right one.</div></div>
<?php endif; ?>

<?php $bulk = can('inventory.manage') && $services; ?>
<form method="POST" class="card-crm" <?= $bulk ? 'data-bulk' : '' ?>>
  <?php if ($bulk): ?>
  <?= csrfField() ?>
  <div class="bulk-bar" hidden>
    <span class="bulk-count">0 selected</span>
    <button class="btn btn-ghost btn-sm" name="bulk" value="hide"><i class="bi bi-eye-slash"></i>Hide from booking</button>
    <button class="btn btn-ghost btn-sm" name="bulk" value="show"><i class="bi bi-eye"></i>Make bookable</button>
    <button class="btn btn-ghost btn-sm text-danger" name="bulk" value="delete" data-confirm="Delete {n} treatment(s) and their recipes? Treatments that have been booked are skipped and stay as they are."><i class="bi bi-trash3"></i>Delete</button>
    <button type="button" class="btn btn-ghost btn-sm bulk-clear ms-auto">Clear selection</button>
  </div>
  <?php endif; ?>
  <div class="table-responsive">
  <table class="table table-hover">
    <thead><tr><?php if ($bulk): ?><th class="bulk-cell"><input class="form-check-input bulk-all" type="checkbox" aria-label="Select all treatments"></th><?php endif; ?><th>Treatment</th><th class="text-end">Price</th><th class="text-end">Product cost</th><th class="text-end">Margin</th><th class="text-end">Booked</th><th class="text-end">Done</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($services as $s): $rc = $recipeCost[(int)$s['id']] ?? null; $cost = $rc['cost'] ?? 0; ?>
      <tr class="<?= can('inventory.manage') ? 'row-link' : '' ?> <?= !$s['is_active'] ? 'opacity-50' : '' ?>" <?= can('inventory.manage') ? 'data-href="/pages/services.php?id=' . $s['id'] . '"' : '' ?>>
        <?php if ($bulk): ?><td class="bulk-cell"><label class="bulk-hit"><input class="form-check-input bulk-check" type="checkbox" name="ids[]" value="<?= $s['id'] ?>" aria-label="Select <?= sanitize($s['name']) ?>"></label></td><?php endif; ?>
        <td>
          <?php if (can('inventory.manage')): ?><a class="cell-title" href="/pages/services.php?id=<?= $s['id'] ?>"><?= sanitize($s['name']) ?></a><?php else: ?><span class="cell-title"><?= sanitize($s['name']) ?></span><?php endif; ?>
          <span class="cell-sub"><?= sanitize($s['category'] ?: 'Uncategorised') ?> · <?= (int)$s['duration_min'] ?> min · <?= (int)$s['item_count'] ?> product<?= $s['item_count'] == 1 ? '' : 's' ?><?= $s['tags'] ? ' · matches ' . sanitize($s['tags']) : '' ?></span>
        </td>
        <td class="text-end tabular text-nowrap"><?= (float)$s['price'] > 0 ? 'AED ' . number_format($s['price']) : '<span class="text-amber fs-12">Not set</span>' ?></td>
        <td class="text-end tabular text-nowrap"><?= $rc ? 'AED ' . number_format($cost) . ($rc['missing'] ? ' <span class="text-amber" title="Some products have no purchase cost yet">*</span>' : '') : '<span class="text-tx-3">No recipe</span>' ?></td>
        <td class="text-end tabular text-nowrap"><?= $rc && (float)$s['price'] > 0 ? round(((float)$s['price'] - $cost) / (float)$s['price'] * 100) . '%' : '<span class="text-tx-3">–</span>' ?></td>
        <td class="text-end tabular"><?= (int)$s['booked_count'] ?: '<span class="text-tx-3">0</span>' ?></td>
        <td class="text-end tabular"><?= (int)$s['done_count'] ?: '<span class="text-tx-3">0</span>' ?></td>
        <td><span class="chip <?= $s['is_active'] ? 'aging-fresh' : '' ?>"><?= $s['is_active'] ? 'Bookable' : 'Hidden' ?></span></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$services): ?><tr><td colspan="7" class="table-empty">No treatments on the menu yet.<?= can('inventory.manage') ? ' <a href="/pages/services.php?new=1">Add the first one</a>.' : '' ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</form>
<?php if ($bulk): ?><script src="/assets/js/bulk.js?v=1" defer></script><?php endif; ?>
<p class="fs-12 text-tx-3 mt-2">Product cost uses the average purchase cost of batches in stock. * means a product in the recipe has no cost recorded yet.</p>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
