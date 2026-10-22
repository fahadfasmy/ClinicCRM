<?php
// pages/stock_reports.php — usage against recipes, wastage, stock value on a date, treatment margin.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';
requirePermission('inventory.reports');

$db = getDB();
ensureInventorySchema($db);

$reports = ['usage' => 'Usage vs recipe', 'wastage' => 'Wastage', 'value' => 'Stock value'];
if (can('reports.margin')) $reports['margin'] = 'Treatment margin';
$r = isset($reports[$_GET['r'] ?? '']) ? $_GET['r'] : 'usage';
$validDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) ? $d : null;
$from = $validDate($_GET['from'] ?? '') ?? date('Y-m-01');
$to   = $validDate($_GET['to'] ?? '') ?? date('Y-m-d');
if ($to < $from) [$from, $to] = [$to, $from];
$toEx = date('Y-m-d', strtotime($to . ' +1 day'));
$on   = $validDate($_GET['on'] ?? '') ?? date('Y-m-d');
$pct  = fn($a, $b) => $b != 0 ? round(($a - $b) / $b * 100) : null;
$aed  = fn($v) => 'AED ' . number_format((float)$v, 0);

$pageTitle    = 'Stock reports';
$pageSubtitle = $reports[$r];
require_once __DIR__ . '/../includes/header.php';
echo inventoryTabs('reports');
?>
<div class="toolbar">
  <div class="segmented" role="tablist" aria-label="Report">
    <?php foreach ($reports as $k => $label): ?>
    <a href="?<?= http_build_query(['r' => $k, 'from' => $from, 'to' => $to, 'on' => $on]) ?>" class="<?= $r === $k ? 'active' : '' ?>" role="tab" aria-selected="<?= $r === $k ? 'true' : 'false' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
  <div class="toolbar-spacer"></div>
  <form class="d-flex gap-2 align-items-center flex-wrap" method="GET">
    <input type="hidden" name="r" value="<?= $r ?>">
    <?php if ($r === 'value'): ?>
      <label class="fs-13 text-tx-2" for="fOn">On</label><input type="date" class="form-control form-control-sm" id="fOn" name="on" value="<?= $on ?>" max="<?= date('Y-m-d') ?>">
    <?php else: ?>
      <input type="date" class="form-control form-control-sm" name="from" value="<?= $from ?>" aria-label="From">
      <span class="text-tx-3">to</span>
      <input type="date" class="form-control form-control-sm" name="to" value="<?= $to ?>" aria-label="To">
    <?php endif; ?>
    <button class="btn btn-ghost btn-sm" type="submit">Show</button>
  </form>
</div>

<?php if ($r === 'usage'):
    $st = $db->prepare("
        SELECT sv.id AS service_id, sv.name AS service, p.id AS product_id, p.name AS product, p.usage_unit,
               COUNT(*) AS n, SUM(ai.qty_reserved) AS recipe, SUM(ai.qty_used) AS used, SUM(ai.variance_reason IS NOT NULL) AS explained
        FROM appointment_items ai JOIN appointments a ON a.id = ai.appointment_id JOIN services sv ON sv.id = a.service_id JOIN products p ON p.id = ai.product_id
        WHERE a.status = 'completed' AND a.completed_at >= ? AND a.completed_at < ? AND ai.qty_used IS NOT NULL
        GROUP BY sv.id, p.id ORDER BY sv.name, p.name");
    $st->execute([$from, $toEx]);
    $byService = $st->fetchAll();
    $st = $db->prepare("
        SELECT COALESCE(pr.name, 'Not recorded') AS practitioner, p.name AS product, p.usage_unit, sv.name AS service,
               COUNT(*) AS n, SUM(ai.qty_reserved) AS recipe, SUM(ai.qty_used) AS used
        FROM appointment_items ai JOIN appointments a ON a.id = ai.appointment_id JOIN services sv ON sv.id = a.service_id JOIN products p ON p.id = ai.product_id
        LEFT JOIN staff pr ON pr.id = COALESCE(a.practitioner_id, a.completed_by)
        WHERE a.status = 'completed' AND a.completed_at >= ? AND a.completed_at < ? AND ai.qty_used IS NOT NULL
        GROUP BY pr.id, sv.id, p.id HAVING ABS(SUM(ai.qty_used) - SUM(ai.qty_reserved)) > 0.0005 ORDER BY practitioner, service, product");
    $st->execute([$from, $toEx]);
    $byPractitioner = $st->fetchAll();
?>
  <div class="card-crm page-section">
    <div class="card-header-crm"><span><i class="bi bi-clipboard2-data"></i> By treatment</span><span class="card-header-sub">Recipe amount vs what practitioners recorded. Over 10 % either way is highlighted.</span></div>
    <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Treatment</th><th>Product</th><th class="text-end">Treatments</th><th class="text-end">Recipe total</th><th class="text-end">Used</th><th class="text-end">Difference</th><th class="text-end">Avg per treatment</th></tr></thead>
      <tbody>
      <?php $prev = null; foreach ($byService as $row): $v = $pct($row['used'], $row['recipe']); $flag = $v !== null && abs($v) > 10; ?>
        <tr>
          <td><?= $row['service'] !== $prev ? '<a class="fw-600" href="/pages/services.php?id=' . $row['service_id'] . '">' . sanitize($row['service']) . '</a>' : '' ?></td>
          <td><?= sanitize($row['product']) ?></td>
          <td class="text-end tabular"><?= (int)$row['n'] ?></td>
          <td class="text-end tabular text-nowrap"><?= qtyUnit($row['recipe'], $row['usage_unit']) ?></td>
          <td class="text-end tabular text-nowrap"><?= qtyUnit($row['used'], $row['usage_unit']) ?></td>
          <td class="text-end tabular text-nowrap <?= $flag ? 'fw-600 ' . ($v > 0 ? 'text-danger' : 'text-amber') : 'text-tx-2' ?>"><?= sprintf('%+g', round($row['used'] - $row['recipe'], 3)) ?><?= $v !== null ? ' (' . sprintf('%+d', $v) . ' %)' : '' ?>
            <?php if ($flag && (int)$row['explained']): ?><span class="cell-sub"><?= (int)$row['explained'] ?> with a reason</span><?php endif; ?></td>
          <td class="text-end tabular text-nowrap"><?= fmtQty(round($row['used'] / max(1, $row['n']), 2)) ?> <span class="text-tx-3">/ <?= fmtQty(round($row['recipe'] / max(1, $row['n']), 2)) ?></span></td>
        </tr>
      <?php $prev = $row['service']; endforeach; ?>
      <?php if (!$byService): ?><tr><td colspan="7" class="table-empty">No completed treatments in this period.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
  <div class="card-crm">
    <div class="card-header-crm"><span><i class="bi bi-person-badge"></i> By practitioner</span><span class="card-header-sub">Only where the amount used differs from the recipe</span></div>
    <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Practitioner</th><th>Treatment</th><th>Product</th><th class="text-end">Treatments</th><th class="text-end">Used / recipe</th><th class="text-end">Difference</th></tr></thead>
      <tbody>
      <?php foreach ($byPractitioner as $row): $v = $pct($row['used'], $row['recipe']); ?>
        <tr>
          <td class="fw-600"><?= sanitize($row['practitioner']) ?></td>
          <td><?= sanitize($row['service']) ?></td>
          <td><?= sanitize($row['product']) ?></td>
          <td class="text-end tabular"><?= (int)$row['n'] ?></td>
          <td class="text-end tabular text-nowrap"><?= fmtQty($row['used']) ?> / <?= qtyUnit($row['recipe'], $row['usage_unit']) ?></td>
          <td class="text-end tabular text-nowrap <?= $v !== null && abs($v) > 10 ? 'fw-600 ' . ($v > 0 ? 'text-danger' : 'text-amber') : 'text-tx-2' ?>"><?= $v !== null ? sprintf('%+d', $v) . ' %' : '–' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$byPractitioner): ?><tr><td colspan="6" class="table-empty">Every treatment in this period used exactly the recipe amounts.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>

<?php elseif ($r === 'wastage'):
    $valueExpr = "-m.qty_change * COALESCE(l.unit_cost, 0) / NULLIF(p.units_per_stock, 0)";
    $st = $db->prepare("SELECT m.reason, COUNT(*) AS n, SUM($valueExpr) AS value FROM stock_movements m JOIN stock_lots l ON l.id = m.lot_id JOIN products p ON p.id = m.product_id
                        WHERE m.type = 'waste' AND m.created_at >= ? AND m.created_at < ? GROUP BY m.reason ORDER BY value DESC");
    $st->execute([$from, $toEx]);
    $byReason = $st->fetchAll();
    $st = $db->prepare("SELECT p.id, p.name, p.usage_unit, COUNT(*) AS n, SUM(-m.qty_change) AS qty, SUM($valueExpr) AS value,
                               GROUP_CONCAT(DISTINCT m.reason ORDER BY m.reason) AS reasons
                        FROM stock_movements m JOIN stock_lots l ON l.id = m.lot_id JOIN products p ON p.id = m.product_id
                        WHERE m.type = 'waste' AND m.created_at >= ? AND m.created_at < ? GROUP BY p.id ORDER BY value DESC, qty DESC");
    $st->execute([$from, $toEx]);
    $byProduct = $st->fetchAll();
    $months = $db->query("SELECT DATE_FORMAT(m.created_at, '%Y-%m') AS ym, m.reason, SUM($valueExpr) AS value
                          FROM stock_movements m JOIN stock_lots l ON l.id = m.lot_id JOIN products p ON p.id = m.product_id
                          WHERE m.type = 'waste' AND m.created_at >= DATE_FORMAT(CURDATE() - INTERVAL 11 MONTH, '%Y-%m-01')
                          GROUP BY ym, m.reason")->fetchAll();
    $grid = []; $reasonsSeen = [];
    foreach ($months as $m) { $grid[$m['ym']][$m['reason'] ?? ''] = (float)$m['value']; $reasonsSeen[$m['reason'] ?? ''] = true; }
    $total = array_sum(array_column($byReason, 'value'));
?>
  <div class="row g-3 page-section">
    <div class="col-lg-5">
      <div class="card-crm h-100">
        <div class="card-header-crm"><span><i class="bi bi-pie-chart"></i> By reason</span><span class="card-header-sub">Total <?= $aed($total) ?></span></div>
        <div class="list-rows">
          <?php foreach ($byReason as $row): $share = $total > 0 ? (float)$row['value'] / $total * 100 : 0; ?>
          <div class="list-row">
            <div class="list-row-main">
              <div class="list-row-title"><?= sanitize(reasonLabel($row['reason']) ?: 'No reason') ?></div>
              <div class="progress mt-1" style="height:6px" role="presentation"><div class="progress-bar" style="width:<?= round($share) ?>%;background:var(--gold)"></div></div>
            </div>
            <div class="list-row-end text-end tabular"><strong><?= $aed($row['value']) ?></strong><span class="cell-sub"><?= (int)$row['n'] ?> entr<?= $row['n'] == 1 ? 'y' : 'ies' ?></span></div>
          </div>
          <?php endforeach; ?>
          <?php if (!$byReason): ?><div class="empty-state py-4"><i class="bi bi-check2-circle"></i>No wastage in this period.</div><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card-crm h-100">
        <div class="card-header-crm"><span><i class="bi bi-box-seam"></i> By product</span></div>
        <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Product</th><th class="text-end">Quantity</th><th class="text-end">Value</th><th>Reasons</th></tr></thead>
          <tbody>
          <?php foreach ($byProduct as $row): ?>
            <tr>
              <td><a href="/pages/product.php?id=<?= $row['id'] ?>"><?= sanitize($row['name']) ?></a></td>
              <td class="text-end tabular text-nowrap"><?= qtyUnit($row['qty'], $row['usage_unit']) ?></td>
              <td class="text-end tabular text-nowrap"><?= $aed($row['value']) ?></td>
              <td class="fs-12 text-tx-2"><?= sanitize(implode(', ', array_map('reasonLabel', explode(',', (string)$row['reasons'])))) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$byProduct): ?><tr><td colspan="4" class="table-empty">No wastage in this period.</td></tr><?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>
    </div>
  </div>
  <div class="card-crm">
    <div class="card-header-crm"><span><i class="bi bi-calendar3"></i> Last 12 months</span><span class="card-header-sub">Value at batch cost, AED</span></div>
    <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>Month</th><?php foreach (array_keys($reasonsSeen) as $rc): ?><th class="text-end"><?= sanitize(reasonLabel($rc) ?: 'No reason') ?></th><?php endforeach; ?><th class="text-end">Total</th></tr></thead>
      <tbody>
      <?php for ($i = 11; $i >= 0; $i--): $ym = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); $row = $grid[$ym] ?? []; ?>
        <tr>
          <td class="text-nowrap"><?= date('M Y', strtotime($ym . '-01')) ?></td>
          <?php foreach (array_keys($reasonsSeen) as $rc): ?><td class="text-end tabular <?= empty($row[$rc]) ? 'text-tx-3' : '' ?>"><?= empty($row[$rc]) ? '–' : number_format($row[$rc], 0) ?></td><?php endforeach; ?>
          <td class="text-end tabular fw-600"><?= $row ? number_format(array_sum($row), 0) : '–' ?></td>
        </tr>
      <?php endfor; ?>
      </tbody>
    </table>
    </div>
  </div>

<?php elseif ($r === 'value'):
    $onEx = date('Y-m-d', strtotime($on . ' +1 day'));
    $st = $db->prepare("
        SELECT p.id, p.name, p.category, p.usage_unit, p.stock_unit, p.units_per_stock,
               SUM(x.qty) AS qty, SUM(x.qty * COALESCE(l.unit_cost, 0) / NULLIF(p.units_per_stock, 0)) AS value,
               SUM(CASE WHEN l.unit_cost IS NULL AND x.qty > 0 THEN 1 ELSE 0 END) AS uncosted,
               SUM(CASE WHEN l.expiry_date < ? AND x.qty > 0 THEN x.qty * COALESCE(l.unit_cost, 0) / NULLIF(p.units_per_stock, 0) ELSE 0 END) AS expired_value
        FROM (SELECT lot_id, SUM(qty_change) AS qty FROM stock_movements WHERE lot_id IS NOT NULL AND created_at < ? GROUP BY lot_id) x
        JOIN stock_lots l ON l.id = x.lot_id JOIN products p ON p.id = l.product_id
        WHERE x.qty > 0.0005
        GROUP BY p.id ORDER BY p.category, p.name");
    $st->execute([$on, $onEx]);
    $rows = $st->fetchAll();
    $total = array_sum(array_column($rows, 'value'));
    $expired = array_sum(array_column($rows, 'expired_value'));
    $uncosted = array_sum(array_column($rows, 'uncosted'));
?>
  <div class="row g-3 page-section">
    <div class="col-sm-4"><div class="stat-card"><div class="stat-label">Stock value <?= $on === date('Y-m-d') ? 'today' : 'on ' . date('d M Y', strtotime($on)) ?></div><div class="stat-value fs-5"><?= $aed($total) ?></div><div class="stat-foot">At the cost of each batch</div></div></div>
    <div class="col-sm-4"><div class="stat-card"><div class="stat-label">Of which expired</div><div class="stat-value fs-5 <?= $expired > 0 ? 'text-danger' : '' ?>"><?= $aed($expired) ?></div><div class="stat-foot">Still on record, not usable</div></div></div>
    <div class="col-sm-4"><div class="stat-card"><div class="stat-label">Batches without a cost</div><div class="stat-value fs-5"><?= (int)$uncosted ?></div><div class="stat-foot"><?= $uncosted ? 'Counted as AED 0; enter costs when receiving' : 'Every batch has a cost' ?></div></div></div>
  </div>
  <div class="card-crm">
    <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Product</th><th class="text-end">Quantity</th><th class="text-end">Value</th><th class="text-end">Share</th></tr></thead>
      <tbody>
      <?php $prev = false; foreach ($rows as $row): if ($row['category'] !== $prev): $prev = $row['category']; ?>
        <tr class="table-group"><td colspan="4" class="fw-600 text-tx-2 fs-12 text-uppercase"><?= sanitize($row['category'] ?: 'Uncategorised') ?></td></tr>
      <?php endif; ?>
        <tr>
          <td><a href="/pages/product.php?id=<?= $row['id'] ?>"><?= sanitize($row['name']) ?></a><?= (int)$row['uncosted'] ? '<span class="cell-sub text-amber">' . (int)$row['uncosted'] . ' batch without cost</span>' : '' ?></td>
          <td class="text-end tabular text-nowrap"><?= fmtStock($row['qty'], $row) ?></td>
          <td class="text-end tabular text-nowrap"><?= $aed($row['value']) ?></td>
          <td class="text-end tabular text-tx-2"><?= $total > 0 ? round($row['value'] / $total * 100) . ' %' : '–' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="4" class="table-empty">No stock on record on this date.</td></tr><?php endif; ?>
      </tbody>
      <?php if ($rows): ?><tfoot><tr><th>Total</th><th></th><th class="text-end tabular"><?= $aed($total) ?></th><th></th></tr></tfoot><?php endif; ?>
    </table>
    </div>
  </div>

<?php else: // margin (admin only)
    $st = $db->prepare("
        SELECT sv.id, sv.name, sv.price, COUNT(*) AS n,
               COALESCE(SUM(c.cost), 0) AS cost, SUM(c.uncosted) AS uncosted
        FROM appointments a JOIN services sv ON sv.id = a.service_id
        LEFT JOIN (SELECT m.appointment_id, SUM(-m.qty_change * COALESCE(l.unit_cost, 0) / NULLIF(p.units_per_stock, 0)) AS cost,
                          SUM(l.unit_cost IS NULL) AS uncosted
                   FROM stock_movements m JOIN stock_lots l ON l.id = m.lot_id JOIN products p ON p.id = m.product_id
                   WHERE m.type = 'consume' GROUP BY m.appointment_id) c ON c.appointment_id = a.id
        WHERE a.status = 'completed' AND a.completed_at >= ? AND a.completed_at < ?
        GROUP BY sv.id ORDER BY (sv.price * COUNT(*) - COALESCE(SUM(c.cost), 0)) DESC");
    $st->execute([$from, $toEx]);
    $rows = $st->fetchAll();
    $rev = array_sum(array_map(fn($x) => (float)$x['price'] * (int)$x['n'], $rows));
    $cost = array_sum(array_column($rows, 'cost'));
    $wst = $db->prepare("SELECT COALESCE(SUM(-m.qty_change * COALESCE(l.unit_cost, 0) / NULLIF(p.units_per_stock, 0)), 0)
                         FROM stock_movements m JOIN stock_lots l ON l.id = m.lot_id JOIN products p ON p.id = m.product_id
                         WHERE m.type = 'waste' AND m.created_at >= ? AND m.created_at < ?");
    $wst->execute([$from, $toEx]);
    $waste = (float)$wst->fetchColumn();
?>
  <div class="row g-3 page-section">
    <?php foreach ([['Treatment revenue', $aed($rev), array_sum(array_column($rows, 'n')) . ' completed treatments, at menu price'],
                    ['Product cost', $aed($cost), 'Batches actually used'],
                    ['Margin after products', $aed($rev - $cost), $rev > 0 ? round(($rev - $cost) / $rev * 100) . ' % of revenue' : '–'],
                    ['Wastage in period', $aed($waste), 'Not included in the margin above']] as [$l, $v, $f]): ?>
    <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-label"><?= $l ?></div><div class="stat-value fs-5"><?= $v ?></div><div class="stat-foot"><?= $f ?></div></div></div>
    <?php endforeach; ?>
  </div>
  <div class="card-crm">
    <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Treatment</th><th class="text-end">Done</th><th class="text-end">Price</th><th class="text-end">Avg product cost</th><th class="text-end">Avg margin</th><th class="text-end">Total margin</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): $avgCost = $row['cost'] / max(1, $row['n']); $m = (float)$row['price'] - $avgCost; ?>
        <tr>
          <td><a class="fw-600" href="/pages/services.php?id=<?= $row['id'] ?>"><?= sanitize($row['name']) ?></a><?= (int)$row['uncosted'] ? '<span class="cell-sub text-amber">Some batches used have no cost; margin is overstated</span>' : '' ?></td>
          <td class="text-end tabular"><?= (int)$row['n'] ?></td>
          <td class="text-end tabular text-nowrap"><?= (float)$row['price'] ? $aed($row['price']) : '<span class="text-tx-3">No price</span>' ?></td>
          <td class="text-end tabular text-nowrap"><?= $aed($avgCost) ?></td>
          <td class="text-end tabular text-nowrap <?= $m < 0 ? 'text-danger' : '' ?>"><?= $aed($m) ?><?= (float)$row['price'] > 0 ? '<span class="cell-sub">' . round($m / $row['price'] * 100) . ' %</span>' : '' ?></td>
          <td class="text-end tabular text-nowrap fw-600"><?= $aed($m * $row['n']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="table-empty">No completed treatments in this period.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
  <p class="fs-12 text-tx-3 mt-3">Revenue uses today’s menu price for each treatment. Discounts, packages and staff time are not included.</p>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
