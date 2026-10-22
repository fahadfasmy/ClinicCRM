<?php
// pages/wastage.php — record wasted stock with a reason, discard opened vials, and review wastage by month.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';
requirePermission('inventory.view');

$db = getDB();
ensureInventorySchema($db);
expireOpenContainers($db);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    requirePermission('inventory.waste');
    try {
        $containerId = (int)($_POST['container_id'] ?? 0) ?: null;
        recordWastage($db, (int)($_POST['lot_id'] ?? 0), (float)($_POST['qty'] ?? 0), $_POST['reason'] ?? '', $_POST['note'] ?? '', $containerId);
        header('Location: /pages/wastage.php?saved=1'); exit;
    } catch (StockException $e) {
        $error = $e->getMessage();
    }
}

// Batches that still hold stock, for the form
$lots = $db->query("SELECT l.id, l.product_id, l.lot_number, l.expiry_date, l.qty_on_hand, l.status, p.name, p.usage_unit, p.stock_unit, p.units_per_stock
                    FROM stock_lots l JOIN products p ON p.id = l.product_id
                    WHERE l.qty_on_hand > 0 ORDER BY p.name, l.expiry_date IS NULL, l.expiry_date, l.id")->fetchAll();
$products = [];
foreach ($lots as $l) {
    $products[(int)$l['product_id']] ??= ['name' => $l['name'], 'unit' => $l['usage_unit'], 'lots' => []];
    $products[(int)$l['product_id']]['lots'][] = [
        'id' => (int)$l['id'], 'qty' => (float)$l['qty_on_hand'],
        'label' => ($l['lot_number'] ?: 'No batch number') . ' · ' . ($l['expiry_date'] ? 'exp ' . date('M Y', strtotime($l['expiry_date'])) : 'no expiry')
                 . ' · ' . fmtQty($l['qty_on_hand']) . ' ' . unitWord($l['usage_unit'], $l['qty_on_hand'])
                 . ($l['status'] !== 'available' ? ' · ' . ($l['status'] === 'quarantined' ? 'on hold' : 'recalled') : '')
                 . ($l['expiry_date'] && $l['expiry_date'] < date('Y-m-d') ? ' · expired' : ''),
        'expired' => $l['expiry_date'] && $l['expiry_date'] < date('Y-m-d'), 'status' => $l['status'],
    ];
}
$selLot = (int)($_POST['lot_id'] ?? $_GET['lot'] ?? 0);
$selProduct = 0;
foreach ($lots as $l) if ((int)$l['id'] === $selLot) $selProduct = (int)$l['product_id'];
$openVials = openContainers($db);

// Wastage log for a month
$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
$fReason = isset(WASTE_REASONS[$_GET['reason'] ?? '']) ? $_GET['reason'] : '';
$from = $month . '-01';
$to = date('Y-m-d', strtotime($from . ' +1 month'));
$sql = "SELECT m.*, -m.qty_change AS qty, l.lot_number, l.unit_cost, p.name AS product_name, p.usage_unit, p.units_per_stock, s.name AS staff_name,
               -m.qty_change * COALESCE(l.unit_cost, 0) / NULLIF(p.units_per_stock, 0) AS value
        FROM stock_movements m JOIN stock_lots l ON l.id = m.lot_id JOIN products p ON p.id = m.product_id LEFT JOIN staff s ON s.id = m.staff_id
        WHERE m.type = 'waste' AND m.created_at >= ? AND m.created_at < ?" . ($fReason ? " AND m.reason = ?" : '') . " ORDER BY m.id DESC";
$st = $db->prepare($sql);
$st->execute($fReason ? [$from, $to, $fReason] : [$from, $to]);
$log = $st->fetchAll();
$byReason = [];
$st = $db->prepare("SELECT m.reason, COUNT(*) AS n, SUM(-m.qty_change * COALESCE(l.unit_cost, 0) / NULLIF(p.units_per_stock, 0)) AS value
                    FROM stock_movements m JOIN stock_lots l ON l.id = m.lot_id JOIN products p ON p.id = m.product_id
                    WHERE m.type = 'waste' AND m.created_at >= ? AND m.created_at < ? GROUP BY m.reason ORDER BY value DESC");
$st->execute([$from, $to]);
foreach ($st->fetchAll() as $r) $byReason[$r['reason'] ?? ''] = $r;
$monthTotal = array_sum(array_map(fn($r) => (float)$r['value'], $byReason));

$pageTitle    = 'Wastage';
$pageSubtitle = 'Stock that was thrown away, with the reason';
require_once __DIR__ . '/../includes/header.php';
echo inventoryTabs('wastage');
?>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i> Wastage recorded.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><?= sanitize($error) ?></div><?php endif; ?>

<div class="row g-3 page-section">
  <?php if (can('inventory.waste')): ?>
  <div class="col-lg-7">
    <form method="POST" class="card-crm" id="wasteForm">
      <?= csrfField() ?>
      <div class="card-header-crm"><span><i class="bi bi-trash3"></i> Record wastage</span><span class="card-header-sub">Added to the stock history; nothing is overwritten</span></div>
      <div class="card-body-crm">
        <?php if (!$products): ?><p class="text-tx-3 mb-0">No stock on record.</p><?php else: ?>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label" for="wProduct">Product <span class="req">*</span></label>
            <select class="form-select" id="wProduct" required><option value="">Choose…</option>
              <?php foreach ($products as $pid => $pr): ?><option value="<?= $pid ?>" <?= $pid === $selProduct ? 'selected' : '' ?>><?= sanitize($pr['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-md-6"><label class="form-label" for="wLot">Batch <span class="req">*</span></label>
            <select class="form-select" id="wLot" name="lot_id" required></select></div>
          <div class="col-md-5"><label class="form-label" for="wQty">Quantity wasted <span class="req">*</span></label>
            <div class="input-group"><input type="number" class="form-control" id="wQty" name="qty" min="0.001" step="any" required value="<?= sanitize($_POST['qty'] ?? '') ?>"><span class="input-group-text" id="wUnit">–</span></div>
            <div class="form-text" id="wMax"></div></div>
          <div class="col-md-7"><label class="form-label" for="wReason">Reason <span class="req">*</span></label>
            <select class="form-select" id="wReason" name="reason" required><option value="">Choose…</option>
              <?php foreach (WASTE_REASONS as $k => $l): if ($k === 'opened_leftover') continue; ?><option value="<?= $k ?>" <?= ($_POST['reason'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-12"><label class="form-label" for="wNote">Note</label><input class="form-control" id="wNote" name="note" maxlength="255" value="<?= sanitize($_POST['note'] ?? '') ?>" placeholder="What happened, e.g. fridge alarm overnight, vial cracked on delivery"></div>
        </div>
        <?php endif; ?>
      </div>
      <?php if ($products): ?><div class="form-actions"><button class="btn btn-gold" type="submit"><i class="bi bi-trash3"></i>Record wastage</button></div><?php endif; ?>
    </form>
  </div>
  <?php endif; ?>
  <div class="<?= can('inventory.waste') ? 'col-lg-5' : 'col-12' ?>">
    <div class="card-crm h-100">
      <div class="card-header-crm"><span><i class="bi bi-droplet-half"></i> Opened vials</span><span class="card-header-sub">Written off automatically at use-by</span></div>
      <div class="list-rows">
        <?php foreach ($openVials as $c): $mins = (int)round((strtotime($c['use_by']) - time()) / 60); ?>
        <div class="list-row">
          <div class="list-row-main">
            <div class="list-row-title"><a href="/pages/product.php?id=<?= $c['product_id'] ?>"><?= sanitize($c['product_name']) ?></a></div>
            <div class="list-row-sub"><?= qtyUnit($c['qty_left'], $c['usage_unit']) ?> left · batch <?= sanitize($c['lot_number'] ?: '–') ?> · use by <?= date('d M, H:i', strtotime($c['use_by'])) ?></div>
          </div>
          <div class="list-row-end d-flex align-items-center gap-2">
            <span class="chip <?= $mins < 120 ? 'aging-overdue' : 'aging-due' ?>"><?= $mins < 60 ? $mins . ' min' : floor($mins / 60) . ' h' ?></span>
            <?php if (can('inventory.waste')): ?>
            <form method="POST" onsubmit="return confirm('Discard what is left in this vial as wastage?')"><?= csrfField() ?>
              <input type="hidden" name="lot_id" value="<?= $c['lot_id'] ?>"><input type="hidden" name="container_id" value="<?= $c['id'] ?>"><input type="hidden" name="reason" value="opened_leftover">
              <button class="btn btn-ghost btn-sm" type="submit">Discard</button></form>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$openVials): ?><div class="empty-state py-4"><i class="bi bi-droplet"></i>No opened vials right now.</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="toolbar">
  <form class="d-flex gap-2 flex-wrap" method="GET">
    <input type="month" class="form-control form-control-sm" name="month" value="<?= sanitize($month) ?>" max="<?= date('Y-m') ?>" aria-label="Month" onchange="this.form.submit()">
    <select class="form-select form-select-sm" name="reason" aria-label="Reason" onchange="this.form.submit()"><option value="">All reasons</option>
      <?php foreach (WASTE_REASONS as $k => $l): ?><option value="<?= $k ?>" <?= $fReason === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  </form>
  <div class="toolbar-spacer"></div>
  <span class="text-tx-2 fs-13">Total <?= date('F Y', strtotime($from)) ?>: <strong>AED <?= number_format($monthTotal, 0) ?></strong> at batch cost</span>
</div>

<?php if ($byReason): ?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php foreach ($byReason as $code => $r): ?>
  <a class="chip chip-lg <?= $fReason === $code ? 'aging-due' : '' ?>" href="?<?= http_build_query(['month' => $month, 'reason' => $fReason === $code ? '' : $code]) ?>">
    <?= sanitize(reasonLabel($code) ?: 'No reason') ?> · <?= (int)$r['n'] ?> · AED <?= number_format((float)$r['value'], 0) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card-crm">
  <div class="table-responsive">
  <table class="table">
    <thead><tr><th>When</th><th>Product</th><th>Batch</th><th class="text-end">Quantity</th><th class="text-end">Value</th><th>Reason</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($log as $m): ?>
      <tr>
        <td class="text-nowrap text-tx-2"><?= date('d M Y, H:i', strtotime($m['created_at'])) ?></td>
        <td><a href="/pages/product.php?id=<?= $m['product_id'] ?>"><?= sanitize($m['product_name']) ?></a></td>
        <td class="text-nowrap"><?= sanitize($m['lot_number'] ?: '–') ?></td>
        <td class="text-end tabular text-nowrap"><?= qtyUnit($m['qty'], $m['usage_unit']) ?></td>
        <td class="text-end tabular text-nowrap"><?= $m['unit_cost'] !== null ? 'AED ' . number_format((float)$m['value'], 0) : '<span class="text-tx-3">–</span>' ?></td>
        <td><?= sanitize(reasonLabel($m['reason'])) ?><?php if ($m['note']): ?><span class="cell-sub"><?= sanitize($m['note']) ?></span><?php endif; ?></td>
        <td class="text-nowrap text-tx-2"><?= sanitize($m['staff_name'] ?? 'Automatic') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$log): ?><tr><td colspan="7" class="table-empty">No wastage recorded in <?= date('F Y', strtotime($from)) ?>.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php
$extraJs = "
const WP = " . json_encode($products, JSON_HEX_TAG | JSON_HEX_AMP) . ", SEL_LOT = " . $selLot . ";
const wp = document.getElementById('wProduct'), wl = document.getElementById('wLot');
function fillLots() {
  if (!wp) return;
  const pr = WP[wp.value];
  wl.innerHTML = pr ? pr.lots.map(l => '<option value=\"' + l.id + '\"' + (l.id === SEL_LOT ? ' selected' : '') + '>' + l.label.replace(/[&<>\"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[c])) + '</option>').join('') : '<option value=\"\">Choose a product first</option>';
  document.getElementById('wUnit').textContent = pr ? pr.unit : '–';
  showMax();
}
function showMax() {
  const pr = WP[wp.value], lot = pr && pr.lots.find(l => String(l.id) === wl.value);
  document.getElementById('wMax').textContent = lot ? 'Batch has ' + lot.qty + ' ' + pr.unit + ' on record.' : '';
  if (lot) document.getElementById('wQty').max = lot.qty;
  const rs = document.getElementById('wReason');
  if (lot && lot.expired && !rs.value) rs.value = 'expired';
  if (lot && lot.status === 'recalled' && !rs.value) rs.value = 'recalled';
}
wp?.addEventListener('change', fillLots);
wl?.addEventListener('change', showMax);
fillLots();";
require_once __DIR__ . '/../includes/footer.php';
