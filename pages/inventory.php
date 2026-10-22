<?php
// pages/inventory.php — stock list: on hand, reserved, available, next expiry, status.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';
requirePermission('inventory.view');

$db = getDB();
ensureInventorySchema($db);
expireOpenContainers($db);

// Bulk actions on selected products
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk'])) {
    requirePermission('inventory.manage');
    requireCsrf();
    try {
        $_SESSION['bulk_flash'] = ['action' => (string)$_POST['bulk']] + bulkCatalogAction($db, 'product', (string)$_POST['bulk'], (array)($_POST['ids'] ?? []));
    } catch (StockException $e) {
        $_SESSION['bulk_flash'] = ['action' => (string)$_POST['bulk'], 'done' => [], 'failed' => [$e->getMessage()]];
    }
    $back = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
    header('Location: /pages/inventory.php' . ($back ? '?' . $back : '')); exit;
}

$showHidden = !empty($_GET['all']);
$rows   = stockSummary($db, !$showHidden);
$hiddenCount = (int)$db->query("SELECT COUNT(*) FROM products WHERE is_active = 0")->fetchColumn();
$counts = ['short' => 0, 'low' => 0, 'expiring' => 0, 'empty' => 0, 'ok' => 0];
foreach ($rows as $r) $counts[$r['state']]++;
$expiredLots = $db->query("
    SELECT l.*, p.name, p.usage_unit, p.stock_unit, p.units_per_stock
    FROM stock_lots l JOIN products p ON p.id = l.product_id
    WHERE l.qty_on_hand > 0 AND (l.expiry_date < CURDATE() OR l.status <> 'available')
    ORDER BY l.expiry_date")->fetchAll();
$expiringLots = $db->query("
    SELECT l.*, p.name, p.usage_unit, p.stock_unit, p.units_per_stock
    FROM stock_lots l JOIN products p ON p.id = l.product_id
    WHERE l.qty_on_hand > 0 AND l.status = 'available' AND l.expiry_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 90 DAY
    ORDER BY l.expiry_date")->fetchAll();
$totalValue = array_sum(array_column($rows, 'stock_value'));

$fState = $_GET['state'] ?? '';
$fCat   = $_GET['cat'] ?? '';
$categories = array_values(array_unique(array_filter(array_column($rows, 'category'))));
sort($categories);
$shown = array_filter($rows, fn($r) => (!$fState || $r['state'] === $fState) && (!$fCat || $r['category'] === $fCat));

$pageTitle    = 'Inventory';
$pageSubtitle = count($rows) . ' products' . ($totalValue > 0 ? ' · stock value AED ' . number_format($totalValue) : '');
$pageActions  = (can('inventory.manage') ? '<a href="/pages/stock_import.php" class="btn btn-ghost"><i class="bi bi-file-earmark-spreadsheet"></i>Import</a><a href="/pages/product.php?new=1" class="btn btn-ghost"><i class="bi bi-plus-lg"></i>Add product</a>' : '')
              . (can('inventory.receive') ? '<a href="/pages/stock_receive.php" class="btn btn-gold"><i class="bi bi-box-arrow-in-down"></i>Receive stock</a>' : '');
require_once __DIR__ . '/../includes/header.php';
echo inventoryTabs('inventory');
$approvals = can('inventory.approve') ? pendingApprovalCount($db) : 0;

$tiles = [
    ['short',    'Short for bookings', $counts['short'],    'bi-exclamation-octagon', 'tone-red',    'Booked treatments need more than is in stock'],
    ['low',      'Low stock',          $counts['low'],      'bi-graph-down-arrow',    'tone-orange', 'Available is below the reorder level'],
    ['expiring', 'Expiring in 60 days', $counts['expiring'], 'bi-hourglass-split',    'tone-amber',  count($expiringLots) . ' batches expire within 90 days'],
    ['',         'Expired or on hold', count($expiredLots), 'bi-slash-circle',        'tone-slate',  'Not usable; remove or return them'],
];
?>

<?php if (isAdmin()) foreach (maintenanceWarnings($db) as $w): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i><div><?= sanitize($w) ?></div></div>
<?php endforeach; ?>
<?php include __DIR__ . '/../includes/bulk_flash.php'; ?>
<?php if ($approvals): ?>
<div class="alert alert-warning"><i class="bi bi-clipboard-check"></i><div><strong><?= $approvals ?> stock count difference<?= $approvals == 1 ? '' : 's' ?> waiting for your approval.</strong> <a href="/pages/stock_counts.php">Review</a></div></div>
<?php endif; ?>

<div class="row g-3 page-section">
  <?php foreach ($tiles as [$state, $label, $value, $icon, $tone, $foot]): ?>
  <div class="col-sm-6 col-xl-3">
    <a class="stat-card" href="<?= $state ? '?state=' . $state : '#expired' ?>">
      <div class="stat-top"><div class="stat-label"><?= $label ?></div><span class="stat-icon <?= $tone ?>"><i class="bi <?= $icon ?>"></i></span></div>
      <div class="stat-value"><?= $value ?></div>
      <div class="stat-foot"><?= $foot ?></div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="toolbar">
  <div class="segmented" role="tablist" aria-label="Filter by status">
    <?php foreach (['' => 'All'] + array_map(fn($c) => $c[0], STOCK_STATE_CHIPS) as $key => $label):
      $q = array_filter(['state' => $key, 'cat' => $fCat, 'all' => $showHidden ? 1 : '']); ?>
    <a href="?<?= http_build_query($q) ?>" class="<?= $fState === $key ? 'active' : '' ?>" role="tab" aria-selected="<?= $fState === $key ? 'true' : 'false' ?>">
      <?= $label ?> <span class="count"><?= $key === '' ? count($rows) : $counts[$key] ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php if ($categories): ?>
  <select class="form-select form-select-sm" aria-label="Category" onchange="location='?'+new URLSearchParams(Object.fromEntries(Object.entries({state:'<?= sanitize($fState) ?>',cat:this.value,all:'<?= $showHidden ? 1 : '' ?>'}).filter(([k,v])=>v)))">
    <option value="">All categories</option>
    <?php foreach ($categories as $c): ?><option value="<?= sanitize($c) ?>" <?= $fCat === $c ? 'selected' : '' ?>><?= sanitize($c) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <?php if ($hiddenCount): ?>
  <a class="fs-13 ms-auto" href="?<?= http_build_query(array_filter(['state' => $fState, 'cat' => $fCat, 'all' => $showHidden ? '' : 1])) ?>"><?= $showHidden ? 'Hide hidden products' : 'Show hidden products (' . $hiddenCount . ')' ?></a>
  <?php endif; ?>
</div>

<?php $bulk = can('inventory.manage') && $shown; ?>
<form method="POST" class="card-crm page-section" <?= $bulk ? 'data-bulk' : '' ?>>
  <?php if ($bulk): ?>
  <?= csrfField() ?>
  <div class="bulk-bar" hidden>
    <span class="bulk-count">0 selected</span>
    <button class="btn btn-ghost btn-sm" name="bulk" value="rx_on"><i class="bi bi-prescription2"></i>Prescription only</button>
    <button class="btn btn-ghost btn-sm" name="bulk" value="rx_off">Not prescription only</button>
    <button class="btn btn-ghost btn-sm" name="bulk" value="hide"><i class="bi bi-eye-slash"></i>Hide</button>
    <?php if ($showHidden): ?><button class="btn btn-ghost btn-sm" name="bulk" value="show"><i class="bi bi-eye"></i>Show again</button><?php endif; ?>
    <button class="btn btn-ghost btn-sm text-danger" name="bulk" value="delete" data-confirm="Delete {n} product(s)? Products with stock, history, orders or a place in a treatment recipe are skipped and stay as they are."><i class="bi bi-trash3"></i>Delete</button>
    <button type="button" class="btn btn-ghost btn-sm bulk-clear ms-auto">Clear selection</button>
  </div>
  <?php endif; ?>
  <div class="table-responsive">
  <table class="table table-hover" id="stockTable">
    <thead>
      <tr>
        <?php if ($bulk): ?><th class="bulk-cell"><input class="form-check-input bulk-all" type="checkbox" aria-label="Select all products shown"></th><?php endif; ?>
        <th>Product</th>
        <th class="text-end">On hand</th>
        <th class="text-end">Reserved</th>
        <th class="text-end">Available</th>
        <th>Next expiry</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($shown as $r): [$chip, $chipCls] = STOCK_STATE_CHIPS[$r['state']]; ?>
      <tr class="row-link <?= $r['is_active'] ? '' : 'opacity-50' ?>" data-href="/pages/product.php?id=<?= $r['id'] ?>">
        <?php if ($bulk): ?><td class="bulk-cell"><label class="bulk-hit"><input class="form-check-input bulk-check" type="checkbox" name="ids[]" value="<?= $r['id'] ?>" aria-label="Select <?= sanitize($r['name']) ?>"></label></td><?php endif; ?>
        <td>
          <a href="/pages/product.php?id=<?= $r['id'] ?>" class="cell-title"><?= sanitize($r['name']) ?></a>
          <span class="cell-sub">
            <?= sanitize($r['category'] ?: 'Uncategorised') ?>
            <?php if ($r['storage'] !== 'room'): ?> · <i class="bi bi-snow2"></i> <?= STORAGE_LABELS[$r['storage']] ?><?php endif; ?>
            <?php if ($r['prescription_only']): ?> · <span class="text-amber">Prescription only</span><?php endif; ?>
          </span>
        </td>
        <td class="text-end tabular text-nowrap"><?= fmtStock($r['on_hand'], $r) ?></td>
        <td class="text-end tabular text-nowrap"><?= (float)$r['qty_reserved'] ? qtyUnit($r['qty_reserved'], $r['usage_unit']) : '<span class="text-tx-3">–</span>' ?></td>
        <td class="text-end tabular text-nowrap fw-600 <?= $r['available'] < 0 ? 'text-danger' : '' ?>"><?= qtyUnit($r['available'], $r['usage_unit']) ?></td>
        <td class="text-nowrap <?= $r['next_expiry'] && strtotime($r['next_expiry']) <= strtotime('+60 days') ? 'text-amber fw-500' : 'text-tx-2' ?>"><?= $r['next_expiry'] ? date('d M Y', strtotime($r['next_expiry'])) : '<span class="text-tx-3">–</span>' ?></td>
        <td><span class="chip <?= $chipCls ?>"><?= $chip ?></span><?= $r['is_active'] ? '' : ' <span class="chip">Hidden</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$shown): ?>
      <tr><td colspan="7" class="table-empty">
        <?= $rows ? 'No products match this filter.' : 'No products yet. ' . (can('inventory.manage') ? '<a href="/pages/product.php?new=1">Add the first product</a>.' : 'The inventory team adds them.') ?>
      </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</form>
<?php if ($bulk): ?><script src="/assets/js/bulk.js?v=1" defer></script><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-hourglass-split"></i> Expiring within 90 days</span><span class="card-header-sub">Use these first</span></div>
      <div class="list-rows">
        <?php foreach ($expiringLots as $l): $days = (int)floor((strtotime($l['expiry_date']) - strtotime('today')) / 86400); ?>
        <a class="list-row" href="/pages/product.php?id=<?= $l['product_id'] ?>">
          <div class="list-row-main">
            <div class="list-row-title"><?= sanitize($l['name']) ?></div>
            <div class="list-row-sub">Batch <?= sanitize($l['lot_number'] ?: '–') ?> · <?= fmtStock($l['qty_on_hand'], $l) ?></div>
          </div>
          <div class="list-row-end"><span class="chip <?= $days <= 30 ? 'aging-overdue' : 'aging-due' ?>"><?= $days === 0 ? 'Today' : $days . ' days' ?></span></div>
        </a>
        <?php endforeach; ?>
        <?php if (!$expiringLots): ?><div class="empty-state py-4"><i class="bi bi-check2-circle"></i>Nothing expires in the next 90 days.</div><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-6" id="expired">
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-slash-circle"></i> Expired or on hold</span><span class="card-header-sub">Not counted as usable</span></div>
      <div class="list-rows">
        <?php foreach ($expiredLots as $l): $expired = $l['expiry_date'] && $l['expiry_date'] < date('Y-m-d'); ?>
        <a class="list-row" href="/pages/product.php?id=<?= $l['product_id'] ?>">
          <div class="list-row-main">
            <div class="list-row-title"><?= sanitize($l['name']) ?></div>
            <div class="list-row-sub">Batch <?= sanitize($l['lot_number'] ?: '–') ?> · <?= fmtStock($l['qty_on_hand'], $l) ?></div>
          </div>
          <div class="list-row-end"><span class="chip aging-critical"><?= $expired ? 'Expired ' . date('d M', strtotime($l['expiry_date'])) : ucfirst($l['status']) ?></span></div>
        </a>
        <?php endforeach; ?>
        <?php if (!$expiredLots): ?><div class="empty-state py-4"><i class="bi bi-check2-circle"></i>No expired or held stock.</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
