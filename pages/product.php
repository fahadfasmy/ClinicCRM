<?php
// pages/product.php — one product: details, batches, movement history.  ?new=1 / ?edit=ID for the form.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';
requirePermission('inventory.view');

$db = getDB();
ensureInventorySchema($db);
$error = '';

$id     = (int)($_GET['id'] ?? $_GET['edit'] ?? 0);
$isForm = isset($_GET['new']) || isset($_GET['edit']);
if ($isForm) requirePermission('inventory.manage');

$load = function (int $id) use ($db) {
    $s = $db->prepare("SELECT * FROM products WHERE id = ?");
    $s->execute([$id]);
    return $s->fetch() ?: null;
};
$p = $id ? $load($id) : null;
if ($id && !$p) { http_response_code(404); die('Product not found.'); }

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            requirePermission('inventory.manage');
            $f = [
                'name'              => trim($_POST['name'] ?? ''),
                'brand'             => trim($_POST['brand'] ?? '') ?: null,
                'category'          => trim($_POST['category'] ?? '') ?: null,
                'sku'               => trim($_POST['sku'] ?? '') ?: null,
                'gtin'              => normalizeGtin($_POST['gtin'] ?? ''),
                'supplier_id'       => (int)($_POST['supplier_id'] ?? 0) ?: null,
                'stock_unit'        => trim($_POST['stock_unit'] ?? '') ?: 'piece',
                'usage_unit'        => trim($_POST['usage_unit'] ?? '') ?: 'piece',
                'units_per_stock'   => (float)($_POST['units_per_stock'] ?? 1),
                'storage'           => array_key_exists($_POST['storage'] ?? '', STORAGE_LABELS) ? $_POST['storage'] : 'room',
                'prescription_only' => isset($_POST['prescription_only']) ? 1 : 0,
                'track_lots'        => isset($_POST['track_lots']) ? 1 : 0,
                'opened_use_hours'  => isset($_POST['multi_dose']) && (int)($_POST['opened_use_hours'] ?? 0) > 0 ? min(2000, (int)$_POST['opened_use_hours']) : null,
                'reorder_level'     => max(0, (float)($_POST['reorder_level'] ?? 0)),
                'reorder_qty'       => max(0, (float)($_POST['reorder_qty'] ?? 0)),
                'sell_price'        => ($_POST['sell_price'] ?? '') !== '' ? max(0, round((float)$_POST['sell_price'], 2)) : null,
                'vat_rate'          => ($_POST['vat_rate'] ?? '5') === '0' ? 0 : 5,
                'notes'             => trim($_POST['notes'] ?? '') ?: null,
                'is_active'         => isset($_POST['is_active']) || !$p ? 1 : 0,
            ];
            if ($f['name'] === '') throw new StockException('Enter the product name.');
            if (trim($_POST['gtin'] ?? '') !== '' && !$f['gtin']) throw new StockException('That barcode number isn’t a valid GS1 product code (8, 12, 13 or 14 digits).');
            if ($f['gtin']) {
                $dup = $db->prepare("SELECT name FROM products WHERE gtin = ? AND id <> ?");
                $dup->execute([$f['gtin'], $p['id'] ?? 0]);
                if ($d = $dup->fetchColumn()) throw new StockException('That barcode is already linked to ' . $d . '.');
            }
            if ($f['units_per_stock'] <= 0) throw new StockException('“How many per stock unit” must be above zero.');
            if (isset($_POST['multi_dose']) && !$f['opened_use_hours']) throw new StockException('Enter how many hours an opened ' . $f['stock_unit'] . ' can be used.');
            if ($f['opened_use_hours'] && $f['units_per_stock'] <= 1) throw new StockException('Opened-vial tracking needs more than one ' . $f['usage_unit'] . ' per ' . $f['stock_unit'] . '.');
            // Changing the unit size would silently re-scale existing stock, so it's locked once stock exists
            if ($p && (float)$p['units_per_stock'] != $f['units_per_stock']
                && (float)$db->query("SELECT COUNT(*) FROM stock_movements WHERE product_id = " . (int)$p['id'])->fetchColumn() > 0) {
                throw new StockException('The number of ' . $p['usage_unit'] . ' per ' . $p['stock_unit'] . ' can’t change once stock has been recorded. Create a new product for the new pack size.');
            }
            if ($p) {
                $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
                $db->prepare("UPDATE products SET $sets WHERE id = ?")->execute([...array_values($f), $p['id']]);
                $pid = (int)$p['id'];
                logActivity(currentStaffId(), 'product_updated', null, null, $f['name']);
            } else {
                $cols = implode(', ', array_keys($f));
                $db->prepare("INSERT INTO products ($cols) VALUES (" . rtrim(str_repeat('?,', count($f)), ',') . ")")->execute(array_values($f));
                $pid = (int)$db->lastInsertId();
                logActivity(currentStaffId(), 'product_created', null, null, $f['name']);
            }
            header('Location: /pages/product.php?id=' . $pid . '&saved=1'); exit;
        }
        if ($action === 'delete' && $p) {
            requirePermission('inventory.manage');
            $_SESSION['bulk_flash'] = ['action' => 'delete', 'done' => [deleteProduct($db, (int)$p['id'])], 'failed' => []];
            header('Location: /pages/inventory.php'); exit;
        }
        if ($action === 'lot_status' && $p) {
            requirePermission('inventory.manage');
            setLotStatus($db, (int)$_POST['lot_id'], $_POST['status'] ?? '');
            logActivity(currentStaffId(), 'lot_status_changed', null, null, ($_POST['status'] ?? '') . ' lot #' . (int)$_POST['lot_id']);
            header('Location: /pages/product.php?id=' . $p['id'] . '&saved=1'); exit;
        }
        if ($action === 'discard_open' && $p) {
            requirePermission('inventory.waste');
            recordWastage($db, (int)$_POST['lot_id'], 0, $_POST['reason'] ?? 'opened_leftover', $_POST['note'] ?? '', (int)$_POST['container_id']);
            header('Location: /pages/product.php?id=' . $p['id'] . '&saved=1'); exit;
        }
        if ($action === 'adjust' && $p) {
            requirePermission('inventory.adjust');
            $qty = (float)($_POST['qty'] ?? 0) * (($_POST['direction'] ?? '') === 'remove' ? -1 : 1);
            adjustLot($db, (int)$_POST['lot_id'], $qty, $_POST['reason'] ?? '', $_POST['note'] ?? '');
            header('Location: /pages/product.php?id=' . $p['id'] . '&saved=1'); exit;
        }
    } catch (StockException $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = 'Could not save: ' . $e->getMessage();
    }
}

// ─────────── FORM ───────────
if ($isForm) {
    $v = fn($k, $d = '') => sanitize((string)($_POST[$k] ?? $p[$k] ?? $d));
    $cats = $db->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
    $supplierList = supplierOptions($db);
    $hasStock = $p && (int)$db->query("SELECT COUNT(*) FROM stock_movements WHERE product_id = " . (int)$p['id'])->fetchColumn() > 0;
    $pageTitle = $p ? 'Edit ' . $p['name'] : 'Add product';
    $pageBack  = ['href' => $p ? '/pages/product.php?id=' . $p['id'] : '/pages/inventory.php', 'label' => $p ? $p['name'] : 'Inventory'];
    require_once __DIR__ . '/../includes/header.php';
    $checked = fn($k, $default) => (isset($_POST['action']) ? isset($_POST[$k]) : (bool)($p[$k] ?? $default)) ? 'checked' : '';
    ?>
    <?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><?= sanitize($error) ?></div><?php endif; ?>
    <form method="POST" class="card-crm" style="max-width:860px">
      <?= csrfField() ?><input type="hidden" name="action" value="save">
      <div class="form-section">
        <h3 class="form-section-title">Product</h3>
        <p class="form-section-sub">What the clinic stocks. Use the name on the box so staff recognise it.</p>
        <div class="row g-3">
          <div class="col-md-8"><label class="form-label" for="pName">Name <span class="req">*</span></label><input class="form-control" id="pName" name="name" required value="<?= $v('name') ?>" placeholder="e.g. Botulinum toxin type A 100U"></div>
          <div class="col-md-4"><label class="form-label" for="pBrand">Brand</label><input class="form-control" id="pBrand" name="brand" value="<?= $v('brand') ?>"></div>
          <div class="col-md-8"><label class="form-label" for="pCat">Category</label><input class="form-control" id="pCat" name="category" list="pCats" value="<?= $v('category') ?>" placeholder="e.g. Injectables, Consumables">
            <datalist id="pCats"><?php foreach ($cats as $c): ?><option value="<?= sanitize($c) ?>"><?php endforeach; ?></datalist></div>
          <div class="col-md-4"><label class="form-label" for="pSku">Code / SKU</label><input class="form-control" id="pSku" name="sku" value="<?= $v('sku') ?>"></div>
          <div class="col-md-6"><label class="form-label" for="pGtin">Barcode (GTIN)</label><input class="form-control" id="pGtin" name="gtin" inputmode="numeric" autocomplete="off" value="<?= $v('gtin') ?>" placeholder="Scan the pack or type the number under the barcode">
            <div class="form-text">Lets staff scan packs when receiving stock and completing treatments.</div></div>
          <div class="col-md-6"><label class="form-label" for="pSup">Usual supplier</label>
            <select class="form-select" id="pSup" name="supplier_id"><option value="">None</option>
              <?php foreach ($supplierList as $sup): ?><option value="<?= $sup['id'] ?>" <?= (int)($_POST['supplier_id'] ?? $p['supplier_id'] ?? 0) === (int)$sup['id'] ? 'selected' : '' ?>><?= sanitize($sup['name']) ?></option><?php endforeach; ?>
            </select><div class="form-text">Pre-selected on reorder suggestions.</div></div>
        </div>
      </div>
      <div class="form-section">
        <h3 class="form-section-title">Units</h3>
        <p class="form-section-sub">You receive stock in one unit and use it in another, e.g. receive <em>vials</em>, use <em>units</em>, 100 units per vial.</p>
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label" for="pSU">Received as</label><input class="form-control" id="pSU" name="stock_unit" value="<?= $v('stock_unit', 'box') ?>" placeholder="vial, syringe, box"></div>
          <div class="col-md-4"><label class="form-label" for="pUU">Used in</label><input class="form-control" id="pUU" name="usage_unit" value="<?= $v('usage_unit', 'piece') ?>" placeholder="units, ml, piece"></div>
          <div class="col-md-4"><label class="form-label" for="pPer">How many per <span id="pSULabel"><?= $v('stock_unit', 'box') ?></span></label>
            <input type="number" class="form-control" id="pPer" name="units_per_stock" min="0.001" step="any" value="<?= $v('units_per_stock', '1') ?>" <?= $hasStock ? 'readonly' : '' ?>>
            <?php if ($hasStock): ?><div class="form-text">Locked because stock has been recorded.</div><?php endif; ?></div>
          <div class="col-md-4"><label class="form-label" for="pRL">Reorder when available falls below</label>
            <div class="input-group"><input type="number" class="form-control" id="pRL" name="reorder_level" min="0" step="any" value="<?= $v('reorder_level', '0') ?>"><span class="input-group-text usage-label"><?= $v('usage_unit', 'piece') ?></span></div></div>
          <div class="col-md-4"><label class="form-label" for="pRQ">Usual order quantity</label>
            <div class="input-group"><input type="number" class="form-control" id="pRQ" name="reorder_qty" min="0" step="any" value="<?= $v('reorder_qty', '0') ?>"><span class="input-group-text usage-label"><?= $v('usage_unit', 'piece') ?></span></div></div>
          <div class="col-md-4"><label class="form-label" for="pSell">Retail price per <?= $v('stock_unit', 'unit') ?> <span class="fw-normal text-tx-3">(empty = not sold)</span></label>
            <div class="input-group"><span class="input-group-text">AED</span><input type="number" class="form-control" id="pSell" name="sell_price" min="0" step="0.01" value="<?= $v('sell_price') ?>"></div></div>
          <div class="col-md-4"><label class="form-label" for="pVat">VAT on sale</label><select class="form-select" id="pVat" name="vat_rate"><option value="5">5%</option><option value="0" <?= in_array($v('vat_rate', '5'), ['0', '0.00'], true) ? 'selected' : '' ?>>0%</option></select></div>
        </div>
      </div>
      <div class="form-section">
        <h3 class="form-section-title">Handling</h3>
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label" for="pSt">Storage</label>
            <select class="form-select" id="pSt" name="storage"><?php foreach (STORAGE_LABELS as $k => $l): ?><option value="<?= $k ?>" <?= ($p['storage'] ?? $_POST['storage'] ?? 'room') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
            <div class="form-text">Cold items need a cold-chain check when received.</div></div>
          <div class="col-md-8 d-flex flex-column gap-2 pt-md-4">
            <div class="form-check"><input class="form-check-input" type="checkbox" id="pLots" name="track_lots" <?= $checked('track_lots', 1) ?>><label class="form-check-label" for="pLots">Track batch number and expiry <span class="text-tx-3">(required for medicines and injectables)</span></label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="pRx" name="prescription_only" <?= $checked('prescription_only', 0) ?>><label class="form-check-label" for="pRx">Prescription-only medicine</label></div>
            <?php $md = isset($_POST['action']) ? isset($_POST['multi_dose']) : !empty($p['opened_use_hours']); ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="pMD" name="multi_dose" <?= $md ? 'checked' : '' ?>><label class="form-check-label" for="pMD">Multi-dose: one <span class="su-label"><?= $v('stock_unit', 'box') ?></span> is shared between patients once opened</label></div>
            <div class="d-flex align-items-center gap-2 ms-4" id="pMDHours" <?= $md ? '' : 'hidden' ?>>
              <label class="fs-12 text-tx-2 mb-0" for="pOH">Usable for</label>
              <div class="input-group input-group-sm" style="width:150px"><input type="number" class="form-control" id="pOH" name="opened_use_hours" min="1" max="2000" step="1" value="<?= $v('opened_use_hours', '24') ?>"><span class="input-group-text">hours</span></div>
              <span class="fs-12 text-tx-3">after opening, as on the label. What’s left is then written off as wastage.</span>
            </div>
            <?php if ($p): ?><div class="form-check"><input class="form-check-input" type="checkbox" id="pAct" name="is_active" <?= $checked('is_active', 1) ?>><label class="form-check-label" for="pAct">Active <span class="text-tx-3">(untick to hide from lists and recipes)</span></label></div><?php endif; ?>
          </div>
          <div class="col-12"><label class="form-label" for="pNotes">Notes</label><textarea class="form-control" id="pNotes" name="notes" rows="2" placeholder="Handling instructions, e.g. use within the time on the label once opened"><?= $v('notes') ?></textarea></div>
        </div>
      </div>
      <div class="form-actions">
        <?php if ($p): $blockers = productDeleteBlockers($db, (int)$p['id']); ?>
          <?php if ($blockers): ?><span class="fs-12 text-tx-3 me-auto">Can’t be deleted because <?= sanitize(listAnd($blockers)) ?>. Untick Active to hide it.</span>
          <?php else: ?><button type="submit" form="delForm" class="btn btn-ghost text-danger me-auto"><i class="bi bi-trash3"></i>Delete product</button><?php endif; ?>
        <?php endif; ?>
        <a href="<?= $pageBack['href'] ?>" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-gold"><i class="bi bi-check-lg"></i><?= $p ? 'Save changes' : 'Add product' ?></button>
      </div>
    </form>
    <?php if ($p && !$blockers): ?>
    <form method="POST" id="delForm" onsubmit="return confirm('Delete this product? This can’t be undone.')"><?= csrfField() ?><input type="hidden" name="action" value="delete"></form>
    <?php endif; ?>
    <?php
    echo '<script src="/assets/js/scan.js?v=1"></script>';
    $extraJs = "
    GS1.attach(document.getElementById('pGtin'), (r, raw) => { const g = document.getElementById('pGtin'); g.value = r && r.gtin ? r.gtin : raw.replace(/\D/g, ''); });
    const su = document.getElementById('pSU'), uu = document.getElementById('pUU');
    su.addEventListener('input', () => { document.getElementById('pSULabel').textContent = su.value || 'box'; document.querySelectorAll('.su-label').forEach(e => e.textContent = su.value || 'box'); });
    document.getElementById('pMD').addEventListener('change', e => { document.getElementById('pMDHours').hidden = !e.target.checked; });
    uu.addEventListener('input', () => document.querySelectorAll('.usage-label').forEach(e => e.textContent = uu.value || 'piece'));";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// ─────────── DETAIL ───────────
if (!$p) { header('Location: /pages/inventory.php'); exit; }
if (isMultiDose($p)) expireOpenContainers($db, (int)$p['id']);
$openVials = isMultiDose($p) ? openContainers($db, (int)$p['id']) : [];
$summary = array_values(array_filter(stockSummary($db, false), fn($r) => (int)$r['id'] === (int)$p['id']))[0];
$lots = $db->prepare("SELECT l.*, s.name AS received_by_name FROM stock_lots l LEFT JOIN staff s ON s.id = l.received_by
                      WHERE l.product_id = ? ORDER BY (l.qty_on_hand > 0) DESC, l.expiry_date IS NULL, l.expiry_date, l.id");
$lots->execute([$p['id']]);
$lots = $lots->fetchAll();
$usedIn = $db->prepare("SELECT s.id, s.name, si.qty, si.qty_min, si.qty_max FROM service_items si JOIN services s ON s.id = si.service_id WHERE si.product_id = ? ORDER BY s.name");
$usedIn->execute([$p['id']]);
$usedIn = $usedIn->fetchAll();
$moves = $db->prepare("
    SELECT m.*, l.lot_number, st.name AS staff_name, a.lead_id, ld.name AS lead_name
    FROM stock_movements m
    LEFT JOIN stock_lots l ON l.id = m.lot_id
    LEFT JOIN staff st ON st.id = m.staff_id
    LEFT JOIN appointments a ON a.id = m.appointment_id
    LEFT JOIN leads ld ON ld.id = a.lead_id
    WHERE m.product_id = ? ORDER BY m.id DESC LIMIT 150");
$moves->execute([$p['id']]);
$moves = $moves->fetchAll();
$usableLots = array_filter($lots, fn($l) => (float)$l['qty_on_hand'] > 0 || $l['status'] === 'available');

[$chip, $chipCls] = STOCK_STATE_CHIPS[$summary['state']];
$pageTitle    = $p['name'];
$pageBack     = ['href' => '/pages/inventory.php', 'label' => 'Inventory'];
$pageSubtitle = '<span class="chip ' . $chipCls . '">' . $chip . '</span>' . (!$p['is_active'] ? ' <span class="chip">Inactive</span>' : '')
              . ($p['category'] ? ' &nbsp;' . sanitize($p['category']) : '') . ($p['brand'] ? ' · ' . sanitize($p['brand']) : '');
$pageActions  = (can('inventory.manage') ? '<a href="/pages/product.php?edit=' . $p['id'] . '" class="btn btn-ghost"><i class="bi bi-pencil"></i>Edit</a>' : '')
              . (can('inventory.receive') ? '<a href="/pages/stock_receive.php?product=' . $p['id'] . '" class="btn btn-gold"><i class="bi bi-box-arrow-in-down"></i>Receive stock</a>' : '');
require_once __DIR__ . '/../includes/header.php';
$lotState = function (array $l): array {
    if ($l['status'] === 'recalled') return ['Recalled', 'aging-critical'];
    if ($l['status'] === 'quarantined') return ['On hold', 'aging-overdue'];
    if ($l['expiry_date'] && $l['expiry_date'] < date('Y-m-d')) return ['Expired', 'aging-critical'];
    if ((float)$l['qty_on_hand'] <= 0) return ['Used up', ''];
    if ($l['expiry_date'] && strtotime($l['expiry_date']) <= strtotime('+60 days')) return ['Expiring soon', 'aging-due'];
    return ['Available', 'aging-fresh'];
};
?>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i> Saved.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><?= sanitize($error) ?></div><?php endif; ?>

<div class="row g-3 page-section">
  <?php foreach ([
      ['On hand', fmtStock($summary['on_hand'], $p), 'Usable batches, not expired'],
      ['Reserved', qtyUnit($p['qty_reserved'], $p['usage_unit']), 'For booked treatments'],
      ['Available', qtyUnit($summary['available'], $p['usage_unit']), (float)$p['reorder_level'] > 0 ? 'Reorder below ' . fmtQty($p['reorder_level']) : 'No reorder level set'],
      ['Stock value', $summary['stock_value'] > 0 ? 'AED ' . number_format($summary['stock_value'], 0) : '–', 'At purchase cost'],
  ] as [$label, $val, $foot]): ?>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-label"><?= $label ?></div><div class="stat-value fs-5"><?= $val ?></div><div class="stat-foot"><?= $foot ?></div></div></div>
  <?php endforeach; ?>
</div>

<div class="lead-layout">
  <aside class="card-crm profile-card">
    <div class="card-header-crm"><span><i class="bi bi-info-circle"></i> Details</span></div>
    <div class="card-body-crm pt-2">
      <div class="kv"><span class="kv-label">Received as</span><span class="kv-value"><?= sanitize($p['stock_unit']) ?></span></div>
      <div class="kv"><span class="kv-label">Used in</span><span class="kv-value"><?= sanitize($p['usage_unit']) ?> · <?= fmtQty($p['units_per_stock']) ?> per <?= sanitize($p['stock_unit']) ?></span></div>
      <div class="kv"><span class="kv-label">Storage</span><span class="kv-value"><?= STORAGE_LABELS[$p['storage']] ?></span></div>
      <div class="kv"><span class="kv-label">Batch tracking</span><span class="kv-value"><?= $p['track_lots'] ? 'Batch and expiry' : 'Not tracked' ?></span></div>
      <div class="kv"><span class="kv-label">Prescription only</span><span class="kv-value"><?= $p['prescription_only'] ? 'Yes' : 'No' ?></span></div>
      <?php if (isMultiDose($p)): ?><div class="kv"><span class="kv-label">Once opened</span><span class="kv-value">Usable <?= (int)$p['opened_use_hours'] ?> h, shared</span></div><?php endif; ?>
      <?php if ($p['sku']): ?><div class="kv"><span class="kv-label">Code</span><span class="kv-value"><?= sanitize($p['sku']) ?></span></div><?php endif; ?>
      <?php if ($p['gtin']): ?><div class="kv"><span class="kv-label">Barcode</span><span class="kv-value tabular"><?= sanitize($p['gtin']) ?></span></div><?php endif; ?>
      <?php if ($p['supplier_id']): $sn = $db->prepare("SELECT name FROM suppliers WHERE id = ?"); $sn->execute([$p['supplier_id']]); ?><div class="kv"><span class="kv-label">Usual supplier</span><span class="kv-value"><?= sanitize((string)$sn->fetchColumn()) ?></span></div><?php endif; ?>
      <?php if ($p['notes']): ?><p class="fs-12 text-tx-2 mt-3 mb-0"><?= nl2br(sanitize($p['notes'])) ?></p><?php endif; ?>
      <div class="section-title mt-4">Used in treatments</div>
      <?php foreach ($usedIn as $u): ?>
        <div class="kv"><a class="kv-label" href="/pages/services.php?id=<?= $u['id'] ?>"><?= sanitize($u['name']) ?></a><span class="kv-value"><?= qtyUnit($u['qty'], $p['usage_unit']) ?></span></div>
      <?php endforeach; ?>
      <?php if (!$usedIn): ?><p class="fs-12 text-tx-3 mb-0">Not part of any treatment recipe yet.</p><?php endif; ?>
    </div>
  </aside>

  <div class="d-flex flex-column gap-3 min-w-0">
    <?php if (isMultiDose($p)): ?>
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-droplet-half"></i> Opened <?= sanitize(unitWord($p['stock_unit'], 2)) ?></span><span class="card-header-sub">Used first. Leftover is written off at the use-by time.</span></div>
      <div class="list-rows">
        <?php foreach ($openVials as $c): $mins = (int)round((strtotime($c['use_by']) - time()) / 60); ?>
        <div class="list-row">
          <div class="list-row-main">
            <div class="list-row-title"><?= qtyUnit($c['qty_left'], $p['usage_unit']) ?> left <span class="text-tx-3 fw-400">of <?= fmtQty($c['qty_initial']) ?></span></div>
            <div class="list-row-sub">Batch <?= sanitize($c['lot_number'] ?: '–') ?> · opened <?= date('d M, H:i', strtotime($c['opened_at'])) ?><?= $c['opened_by_name'] ? ' by ' . sanitize($c['opened_by_name']) : '' ?> · use by <?= date('d M, H:i', strtotime($c['use_by'])) ?></div>
          </div>
          <div class="list-row-end d-flex align-items-center gap-2">
            <span class="chip <?= $mins < 120 ? 'aging-overdue' : 'aging-due' ?>"><?= $mins < 60 ? $mins . ' min left' : floor($mins / 60) . ' h left' ?></span>
            <?php if (can('inventory.waste')): ?>
            <form method="POST" onsubmit="return confirm('Discard what is left in this <?= sanitize($p['stock_unit']) ?> as wastage?')"><?= csrfField() ?>
              <input type="hidden" name="action" value="discard_open"><input type="hidden" name="lot_id" value="<?= $c['lot_id'] ?>"><input type="hidden" name="container_id" value="<?= $c['id'] ?>">
              <button class="btn btn-ghost btn-sm" type="submit">Discard</button></form>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$openVials): ?><div class="empty-state py-4"><i class="bi bi-droplet"></i>Nothing open. A <?= sanitize($p['stock_unit']) ?> is opened automatically when a treatment uses it.</div><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-boxes"></i> Batches</span><span class="card-header-sub">Earliest expiry is used first</span></div>
      <div class="table-responsive">
      <table class="table">
        <thead><tr><th>Batch</th><th>Expiry</th><th class="text-end">On hand</th><th class="text-end">Received</th><th>Status</th><?php if (can('inventory.manage') || can('inventory.adjust') || can('inventory.waste')): ?><th class="text-end"></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($lots as $l): [$ls, $lc] = $lotState($l); ?>
          <tr class="<?= (float)$l['qty_on_hand'] <= 0 ? 'opacity-50' : '' ?>">
            <td><span class="fw-600"><?= sanitize($l['lot_number'] ?: 'No batch number') ?></span>
              <span class="cell-sub"><?= sanitize($l['supplier'] ?: '') ?><?= $l['invoice_ref'] ? ' · ' . sanitize($l['invoice_ref']) : '' ?></span></td>
            <td class="text-nowrap"><?= $l['expiry_date'] ? date('d M Y', strtotime($l['expiry_date'])) : '<span class="text-tx-3">–</span>' ?></td>
            <td class="text-end tabular text-nowrap"><?= fmtStock($l['qty_on_hand'], $p) ?><?php $inOpen = array_sum(array_map(fn($c) => (int)$c['lot_id'] === (int)$l['id'] ? (float)$c['qty_left'] : 0, $openVials)); if ($inOpen > 0): ?><span class="cell-sub"><?= fmtQty($inOpen) ?> in opened <?= sanitize($p['stock_unit']) ?></span><?php endif; ?></td>
            <td class="text-end text-nowrap"><span class="tabular"><?= fmtQty($l['qty_received']) ?></span><span class="cell-sub"><?= date('d M Y', strtotime($l['received_at'])) ?><?= $l['received_by_name'] ? ' · ' . sanitize($l['received_by_name']) : '' ?></span></td>
            <td><span class="chip <?= $lc ?>"><?= $ls ?></span></td>
            <?php if (can('inventory.manage') || can('inventory.adjust') || can('inventory.waste')): ?>
            <td class="text-end">
              <div class="dropdown">
                <button type="button" class="btn-icon" data-bs-toggle="dropdown" aria-label="Batch actions"><i class="bi bi-three-dots"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <?php if (can('inventory.manage')): foreach (['available' => 'Mark available', 'quarantined' => 'Put on hold', 'recalled' => 'Mark recalled'] as $st => $lbl): if ($st === $l['status']) continue; ?>
                  <li><form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="lot_status"><input type="hidden" name="lot_id" value="<?= $l['id'] ?>"><input type="hidden" name="status" value="<?= $st ?>">
                    <button class="dropdown-item" type="submit"><?= $lbl ?></button></form></li>
                  <?php endforeach; endif; ?>
                  <?php if (can('inventory.waste') && (float)$l['qty_on_hand'] > 0): ?><li><a class="dropdown-item" href="/pages/wastage.php?lot=<?= $l['id'] ?>">Record wastage…</a></li><?php endif; ?>
                  <?php if (can('inventory.adjust')): ?><li><button class="dropdown-item" type="button" data-adjust="<?= $l['id'] ?>" data-label="<?= sanitize($l['lot_number'] ?: 'No batch number') ?>">Correct quantity…</button></li><?php endif; ?>
                  <li><a class="dropdown-item" href="/pages/appointments.php?lot=<?= $l['id'] ?>">Patients who received it</a></li>
                </ul>
              </div>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (!$lots): ?><tr><td colspan="6" class="table-empty">No stock received yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>

    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-journal-text"></i> Stock history</span><span class="card-header-sub">Every change, newest first. Entries are never edited.</span></div>
      <div class="table-responsive">
      <table class="table">
        <thead><tr><th>When</th><th>What</th><th>Batch</th><th class="text-end">On hand</th><th class="text-end">Reserved</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($moves as $m): ?>
          <tr>
            <td class="text-nowrap text-tx-2"><?= date('d M Y, H:i', strtotime($m['created_at'])) ?></td>
            <td><?= MOVEMENT_LABELS[$m['type']] ?? $m['type'] ?>
              <?php if ($m['lead_id']): ?><span class="cell-sub"><a href="/pages/appointment.php?id=<?= $m['appointment_id'] ?>"><?= sanitize($m['lead_name']) ?></a></span><?php endif; ?>
              <?php if ($m['reason'] || $m['note']): ?><span class="cell-sub"><?= sanitize(reasonLabel($m['reason'])) ?><?= $m['reason'] && $m['note'] ? ' · ' : '' ?><?= sanitize($m['note'] ?? '') ?></span><?php endif; ?></td>
            <td class="text-nowrap"><?= sanitize($m['lot_number'] ?? '') ?: '<span class="text-tx-3">–</span>' ?></td>
            <td class="text-end tabular <?= (float)$m['qty_change'] < 0 ? 'text-danger' : ((float)$m['qty_change'] > 0 ? 'text-green' : 'text-tx-3') ?>"><?= (float)$m['qty_change'] ? sprintf('%+g', (float)$m['qty_change']) : '–' ?></td>
            <td class="text-end tabular text-tx-2"><?= (float)$m['reserved_change'] ? sprintf('%+g', (float)$m['reserved_change']) : '–' ?></td>
            <td class="text-nowrap text-tx-2"><?= sanitize($m['staff_name'] ?? 'Automatic') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$moves): ?><tr><td colspan="6" class="table-empty">No stock movements yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
</div>

<?php if (can('inventory.adjust')): ?>
<div class="modal fade" id="adjustModal" tabindex="-1" aria-labelledby="adjustTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <?= csrfField() ?><input type="hidden" name="action" value="adjust"><input type="hidden" name="lot_id" id="adjLot">
      <div class="modal-header"><h5 class="modal-title" id="adjustTitle">Correct batch <span id="adjLabel"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <p class="fs-12 text-tx-2">Corrections are added as a new line in the stock history. Nothing is overwritten.</p>
        <div class="row g-3">
          <div class="col-5"><label class="form-label" for="adjDir">Change</label>
            <select class="form-select" name="direction" id="adjDir"><option value="remove">Remove</option><option value="add">Add</option></select></div>
          <div class="col-7"><label class="form-label" for="adjQty">Quantity</label>
            <div class="input-group"><input type="number" class="form-control" name="qty" id="adjQty" min="0.001" step="any" required><span class="input-group-text"><?= sanitize($p['usage_unit']) ?></span></div></div>
          <div class="col-12"><label class="form-label" for="adjReason">Reason</label>
            <select class="form-select" name="reason" id="adjReason" required><?php foreach (ADJUST_REASONS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="col-12"><label class="form-label" for="adjNote">Note</label><input class="form-control" name="note" id="adjNote" maxlength="255" placeholder="What happened"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-gold">Save correction</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php
$extraJs = "
document.querySelectorAll('[data-adjust]').forEach(b => b.addEventListener('click', () => {
  document.getElementById('adjLot').value = b.dataset.adjust;
  document.getElementById('adjLabel').textContent = b.dataset.label;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('adjustModal')).show();
}));";
require_once __DIR__ . '/../includes/footer.php';
