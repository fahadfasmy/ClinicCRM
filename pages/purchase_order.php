<?php
// pages/purchase_order.php — one purchase order. ?new=1 creates a draft; drafts are editable until placed.
// Placed orders are received line by line through the receive form (?po_line=ID). ?print=1 gives a page to send.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';
requirePermission('purchasing.manage');

$db = getDB();
ensureInventorySchema($db);
$error = '';
$id = (int)($_GET['id'] ?? 0);
$po = $id ? getPurchaseOrder($db, $id) : null;
if ($id && !$po) { http_response_code(404); die('Order not found.'); }
if (!$id && !isset($_GET['new'])) { header('Location: /pages/purchasing.php?view=orders'); exit; }

/** Lines posted by the editor: line_product[], line_qty[], line_cost[]. */
$postedLines = function (): array {
    $out = [];
    foreach ((array)($_POST['line_product'] ?? []) as $i => $pid) {
        if (!(int)$pid) continue;
        $out[] = ['product_id' => (int)$pid, 'qty' => $_POST['line_qty'][$i] ?? 0, 'unit_cost' => $_POST['line_cost'][$i] ?? ''];
    }
    return $out;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save' || $action === 'place') {
            $sid = (int)($_POST['supplier_id'] ?? 0);
            $expected = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['expected_date'] ?? '') ? $_POST['expected_date'] : null;
            if (!$po) {
                $id = createPurchaseOrder($db, $sid, $postedLines(), $_POST['notes'] ?? '');
                if ($expected) $db->prepare("UPDATE purchase_orders SET expected_date = ? WHERE id = ?")->execute([$expected, $id]);
            } else {
                $s = $db->prepare("SELECT id FROM suppliers WHERE id = ?");
                $s->execute([$sid]);
                if (!$s->fetch()) throw new StockException('Pick a supplier.');
                updateDraftOrder($db, $id, $sid, $expected, $_POST['notes'] ?? '', $postedLines());
            }
            if ($action === 'place') setPoStatus($db, $id, 'ordered');
            header('Location: /pages/purchase_order.php?id=' . $id . '&' . ($action === 'place' ? 'placed' : 'saved') . '=1'); exit;
        }
        if ($po && in_array($action, ['cancelled', 'closed', 'ordered'], true)) {
            setPoStatus($db, $id, $action);
            header('Location: /pages/purchase_order.php?id=' . $id . '&saved=1'); exit;
        }
        if ($po && $action === 'expected') {
            $expected = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['expected_date'] ?? '') ? $_POST['expected_date'] : null;
            $db->prepare("UPDATE purchase_orders SET expected_date = ? WHERE id = ?")->execute([$expected, $id]);
            header('Location: /pages/purchase_order.php?id=' . $id . '&saved=1'); exit;
        }
    } catch (StockException $e) {
        $error = $e->getMessage();
    }
}

$lines = $po ? purchaseOrderLines($db, $id) : [];
$editing = !$po || $po['status'] === 'draft';

// ─────────── PRINT ───────────
if ($po && isset($_GET['print'])) {
    $total = array_sum(array_map(fn($l) => (float)$l['qty_ordered'] * (float)$l['unit_cost'], $lines));
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title><?= poNumber($id) ?> · <?= sanitize($po['supplier_name']) ?></title>
    <style>body{font:14px/1.5 system-ui,-apple-system,'Segoe UI',sans-serif;color:#1F1D1A;margin:32px;max-width:820px}h1{font-size:22px;margin:0 0 4px}
    table{border-collapse:collapse;width:100%;margin:18px 0}th,td{border-bottom:1px solid #ddd;padding:8px;text-align:left}th{font-size:12px;text-transform:uppercase;color:#666}
    td.n,th.n{text-align:right}.meta{display:flex;justify-content:space-between;gap:24px;margin-top:16px}.muted{color:#666}@media print{button{display:none}}</style></head><body>
    <button onclick="window.print()" style="float:right">Print</button>
    <h1>Purchase order <?= poNumber($id) ?></h1>
    <div class="muted"><?= sanitize(clinicName()) ?> · <?= date('d M Y', strtotime($po['ordered_at'] ?: $po['created_at'])) ?></div>
    <div class="meta"><div><strong>To</strong><br><?= sanitize($po['supplier_name']) ?><br><?= sanitize(implode(' · ', array_filter([$po['contact_name'], $po['supplier_phone'], $po['supplier_email']]))) ?></div>
      <div><?php if ($po['expected_date']): ?><strong>Needed by</strong><br><?= date('d M Y', strtotime($po['expected_date'])) ?><?php endif; ?></div></div>
    <table><thead><tr><th>Product</th><th>Code</th><th class="n">Quantity</th><th class="n">Unit price</th><th class="n">Total</th></tr></thead><tbody>
    <?php foreach ($lines as $l): ?><tr><td><?= sanitize($l['product_name']) ?><?= $l['brand'] ? ' <span class="muted">(' . sanitize($l['brand']) . ')</span>' : '' ?></td>
      <td><?= sanitize($l['sku'] ?: ($l['gtin'] ?: '')) ?></td><td class="n"><?= qtyUnit($l['qty_ordered'], $l['stock_unit']) ?></td>
      <td class="n"><?= $l['unit_cost'] !== null ? 'AED ' . number_format((float)$l['unit_cost'], 2) : '' ?></td>
      <td class="n"><?= $l['unit_cost'] !== null ? 'AED ' . number_format((float)$l['qty_ordered'] * (float)$l['unit_cost'], 2) : '' ?></td></tr><?php endforeach; ?>
    </tbody><?php if ($total > 0): ?><tfoot><tr><th colspan="4" class="n">Total</th><th class="n">AED <?= number_format($total, 2) ?></th></tr></tfoot><?php endif; ?></table>
    <?php if ($po['notes']): ?><p><?= nl2br(sanitize($po['notes'])) ?></p><?php endif; ?>
    <p class="muted">Please quote <?= poNumber($id) ?> on the delivery note and invoice. Cold-chain items must arrive refrigerated.</p>
    </body></html><?php
    exit;
}

$suppliers = supplierOptions($db, !$po);
$products = $db->query("SELECT p.id, p.name, p.stock_unit, p.usage_unit, p.units_per_stock, p.supplier_id,
    (SELECT l.unit_cost FROM stock_lots l WHERE l.product_id = p.id AND l.unit_cost IS NOT NULL ORDER BY l.id DESC LIMIT 1) AS last_cost
    FROM products p WHERE p.is_active = 1 ORDER BY p.name")->fetchAll();

$pageTitle    = $po ? poNumber($id) : 'New purchase order';
$pageBack     = ['href' => '/pages/purchasing.php?view=orders', 'label' => 'Orders'];
$pageSubtitle = $po ? poBadge($po['status']) . ' &nbsp;' . sanitize($po['supplier_name']) . ' · created ' . date('d M Y', strtotime($po['created_at'])) . ($po['created_by_name'] ? ' by ' . sanitize($po['created_by_name']) : '')
                    . ($po['ordered_at'] ? ' · placed ' . date('d M Y', strtotime($po['ordered_at'])) : '') : '';
$pageActions  = $po && $lines ? '<a href="?id=' . $id . '&print=1" target="_blank" rel="noopener" class="btn btn-ghost"><i class="bi bi-printer"></i>Print or save as PDF</a>' : '';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if (isset($_GET['saved']) || isset($_GET['created'])): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i> Saved.</div><?php endif; ?>
<?php if (isset($_GET['placed'])): ?><div class="alert alert-success"><i class="bi bi-check-circle"></i><div><strong>Order placed.</strong> Send it to the supplier (print or save as PDF), then receive each line here when the delivery arrives.</div></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><?= sanitize($error) ?></div><?php endif; ?>

<?php if ($editing): ?>
  <?php if (!$suppliers): ?>
  <div class="card-crm"><div class="empty-state"><i class="bi bi-truck"></i>Add a supplier first. <a href="/pages/purchasing.php?view=suppliers&add=1">Add supplier</a></div></div>
  <?php else:
    $formLines = $_SERVER['REQUEST_METHOD'] === 'POST' ? $postedLines() : array_map(fn($l) => ['product_id' => $l['product_id'], 'qty' => fmtQty($l['qty_ordered']), 'unit_cost' => $l['unit_cost']], $lines);
    $preSup = (int)($_POST['supplier_id'] ?? $po['supplier_id'] ?? $_GET['supplier'] ?? 0);
  ?>
  <form method="POST" class="card-crm" id="poForm" style="max-width:1000px">
    <?= csrfField() ?>
    <div class="form-section">
      <div class="row g-3">
        <div class="col-md-5"><label class="form-label" for="poSup">Supplier <span class="req">*</span></label>
          <select class="form-select" id="poSup" name="supplier_id" required><option value="">Choose…</option>
            <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>" <?= $preSup === (int)$s['id'] ? 'selected' : '' ?>><?= sanitize($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label" for="poExp">Needed by</label><input type="date" class="form-control" id="poExp" name="expected_date" value="<?= sanitize($_POST['expected_date'] ?? $po['expected_date'] ?? '') ?>" min="<?= date('Y-m-d') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="poNotes">Notes for the supplier</label><input class="form-control" id="poNotes" name="notes" value="<?= sanitize($_POST['notes'] ?? $po['notes'] ?? '') ?>" placeholder="Delivery hours, account number"></div>
      </div>
    </div>
    <div class="table-responsive">
    <table class="table mb-0" id="poLines">
      <thead><tr><th>Product</th><th style="width:170px">Quantity</th><th style="width:170px">Price each</th><th class="text-end" style="width:120px">Total</th><th style="width:44px"></th></tr></thead>
      <tbody>
      <?php foreach (array_merge($formLines, [['product_id' => 0, 'qty' => '', 'unit_cost' => '']]) as $l): ?>
        <tr class="po-line">
          <td><select class="form-select form-select-sm line-product" name="line_product[]" aria-label="Product"><option value="">Choose a product…</option>
            <?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>" data-su="<?= sanitize(unitWord($p['stock_unit'], 2)) ?>" data-cost="<?= $p['last_cost'] ?? '' ?>" data-sup="<?= (int)$p['supplier_id'] ?>" <?= (int)$l['product_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= sanitize($p['name']) ?></option><?php endforeach; ?>
          </select></td>
          <td><div class="input-group input-group-sm"><input type="number" class="form-control line-qty" name="line_qty[]" min="0" step="any" value="<?= sanitize((string)$l['qty']) ?>" aria-label="Quantity"><span class="input-group-text line-su">–</span></div></td>
          <td><div class="input-group input-group-sm"><span class="input-group-text">AED</span><input type="number" class="form-control line-cost" name="line_cost[]" min="0" step="0.01" value="<?= sanitize((string)($l['unit_cost'] ?? '')) ?>" aria-label="Price each"></div></td>
          <td class="text-end tabular line-total">–</td>
          <td class="text-end"><button type="button" class="btn-icon danger line-del" aria-label="Remove line"><i class="bi bi-x-lg"></i></button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td><button type="button" class="btn btn-ghost btn-sm" id="addLine"><i class="bi bi-plus-lg"></i>Add product</button></td><td></td><th class="text-end">Total</th><th class="text-end tabular" id="poTotal">–</th><td></td></tr></tfoot>
    </table>
    </div>
    <div class="form-actions">
      <?php if ($po): ?><button class="btn btn-ghost me-auto" type="submit" name="action" value="cancelled" formnovalidate onclick="return confirm('Cancel this draft order?')">Cancel order</button><?php endif; ?>
      <button class="btn btn-ghost" type="submit" name="action" value="save"><i class="bi bi-floppy"></i>Save draft</button>
      <button class="btn btn-gold" type="submit" name="action" value="place" onclick="return confirm('Place this order? It can no longer be edited, only received, closed or cancelled.')"><i class="bi bi-send"></i>Place order</button>
    </div>
  </form>
  <?php endif; ?>

<?php else:
  $total = array_sum(array_map(fn($l) => (float)$l['qty_ordered'] * (float)$l['unit_cost'], $lines));
  $open = in_array($po['status'], ['ordered', 'partial'], true);
  $receipts = $db->prepare("SELECT l.*, s.name AS received_by_name FROM stock_lots l JOIN purchase_order_lines pl ON pl.id = l.po_line_id LEFT JOIN staff s ON s.id = l.received_by WHERE pl.po_id = ? ORDER BY l.id DESC");
  $receipts->execute([$id]);
  $receipts = $receipts->fetchAll();
  $byLine = [];
  foreach ($receipts as $r) $byLine[(int)$r['po_line_id']][] = $r;
?>
<div class="lead-layout">
  <aside class="card-crm profile-card">
    <div class="card-header-crm"><span><i class="bi bi-receipt"></i> Order</span></div>
    <div class="card-body-crm pt-2">
      <div class="kv"><span class="kv-label">Supplier</span><span class="kv-value"><?= sanitize($po['supplier_name']) ?></span></div>
      <?php if ($po['contact_name'] || $po['supplier_phone']): ?><div class="kv"><span class="kv-label">Contact</span><span class="kv-value"><?= sanitize(implode(' · ', array_filter([$po['contact_name'], $po['supplier_phone']]))) ?></span></div><?php endif; ?>
      <?php if ($po['supplier_email']): ?><div class="kv"><span class="kv-label">Email</span><span class="kv-value"><a href="mailto:<?= sanitize($po['supplier_email']) ?>?subject=<?= rawurlencode('Order ' . poNumber($id)) ?>"><?= sanitize($po['supplier_email']) ?></a></span></div><?php endif; ?>
      <div class="kv"><span class="kv-label">Placed</span><span class="kv-value"><?= $po['ordered_at'] ? date('d M Y', strtotime($po['ordered_at'])) . ($po['ordered_by_name'] ? '<span class="cell-sub">' . sanitize($po['ordered_by_name']) . '</span>' : '') : '–' ?></span></div>
      <div class="kv"><span class="kv-label">Value</span><span class="kv-value"><?= $total > 0 ? 'AED ' . number_format($total, 2) : '–' ?></span></div>
      <?php if ($po['notes']): ?><p class="fs-12 text-tx-2 mt-3 mb-0"><?= nl2br(sanitize($po['notes'])) ?></p><?php endif; ?>
      <?php if ($open): ?>
      <div class="section-title mt-4">Expected delivery</div>
      <form method="POST" class="d-flex gap-2"><?= csrfField() ?><input type="hidden" name="action" value="expected">
        <input type="date" class="form-control form-control-sm" name="expected_date" value="<?= sanitize($po['expected_date'] ?? '') ?>" aria-label="Expected delivery date">
        <button class="btn btn-ghost btn-sm" type="submit">Save</button></form>
      <div class="section-title mt-4">Finish</div>
      <form method="POST" class="d-flex flex-column gap-2"><?= csrfField() ?>
        <?php if ($po['status'] === 'ordered'): ?><button class="btn btn-ghost btn-sm" name="action" value="cancelled" onclick="return confirm('Cancel this order? Tell the supplier too.')">Cancel order</button><?php endif; ?>
        <button class="btn btn-ghost btn-sm" name="action" value="closed" onclick="return confirm('Close the order? Anything not yet delivered is no longer expected.')">Close, rest won’t come</button>
      </form>
      <?php endif; ?>
    </div>
  </aside>

  <div class="d-flex flex-column gap-3 min-w-0">
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-box-seam"></i> Lines</span><span class="card-header-sub"><?= $open ? 'Receive each line when it arrives; batch and expiry are recorded per delivery' : '' ?></span></div>
      <div class="table-responsive">
      <table class="table mb-0">
        <thead><tr><th>Product</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Price each</th><th class="text-end"></th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): $left = max(0, (float)$l['qty_ordered'] - (float)$l['qty_received']); ?>
          <tr>
            <td><a class="fw-600" href="/pages/product.php?id=<?= $l['product_id'] ?>"><?= sanitize($l['product_name']) ?></a>
              <?php foreach ($byLine[(int)$l['id']] ?? [] as $r): ?><span class="cell-sub">Batch <?= sanitize($r['lot_number'] ?: '–') ?><?= $r['expiry_date'] ? ', exp ' . date('M Y', strtotime($r['expiry_date'])) : '' ?> · <?= qtyUnit(round((float)$r['qty_received'] / max((float)$l['units_per_stock'], 0.001), 3), $l['stock_unit']) ?> on <?= date('d M', strtotime($r['received_at'])) ?></span><?php endforeach; ?></td>
            <td class="text-end tabular text-nowrap"><?= qtyUnit($l['qty_ordered'], $l['stock_unit']) ?></td>
            <td class="text-end tabular text-nowrap <?= $left > 0.0005 && (float)$l['qty_received'] > 0 ? 'text-amber fw-600' : '' ?>"><?= fmtQty($l['qty_received']) ?><?= $left > 0.0005 && $open ? '<span class="cell-sub">' . fmtQty($left) . ' to come</span>' : '' ?></td>
            <td class="text-end tabular text-nowrap"><?= $l['unit_cost'] !== null ? 'AED ' . number_format((float)$l['unit_cost'], 2) : '–' ?></td>
            <td class="text-end"><?php if ($open && $left > 0.0005 && can('inventory.receive')): ?><a class="btn btn-gold btn-sm" href="/pages/stock_receive.php?po_line=<?= $l['id'] ?>"><i class="bi bi-box-arrow-in-down"></i>Receive</a><?php elseif ($left <= 0.0005): ?><span class="chip aging-fresh">Complete</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$extraJs = "
const tbody = document.querySelector('#poLines tbody');
if (tbody) {
  const tpl = tbody.lastElementChild.cloneNode(true);
  const money = v => 'AED ' + v.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
  function sync(row) {
    const o = row.querySelector('.line-product').selectedOptions[0];
    row.querySelector('.line-su').textContent = o && o.value ? o.dataset.su : '–';
    const cost = row.querySelector('.line-cost');
    if (o && o.value && cost.value === '' && o.dataset.cost) cost.value = o.dataset.cost;
    const q = parseFloat(row.querySelector('.line-qty').value) || 0, c = parseFloat(cost.value) || 0;
    row.querySelector('.line-total').textContent = q && c ? money(q * c) : '–';
    let t = 0;
    tbody.querySelectorAll('.po-line').forEach(r => t += (parseFloat(r.querySelector('.line-qty').value) || 0) * (parseFloat(r.querySelector('.line-cost').value) || 0));
    document.getElementById('poTotal').textContent = t ? money(t) : '–';
  }
  function wire(row) {
    row.querySelectorAll('select,input').forEach(el => el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', () => sync(row)));
    row.querySelector('.line-del').addEventListener('click', () => { if (tbody.children.length > 1) row.remove(); else row.querySelectorAll('select,input').forEach(e => e.value = ''); sync(tbody.firstElementChild); });
    sync(row);
  }
  // Products whose usual supplier is the chosen one are marked with a star
  const sup = document.getElementById('poSup');
  function sortProducts() {
    tbody.querySelectorAll('.line-product').forEach(sel => {
      [...sel.options].slice(1).forEach(o => o.textContent = o.textContent.replace(/^★ /, '') );
      [...sel.options].slice(1).filter(o => sup.value && o.dataset.sup === sup.value).forEach(o => o.textContent = '★ ' + o.textContent);
    });
  }
  sup.addEventListener('change', sortProducts); sortProducts();
  tbody.querySelectorAll('.po-line').forEach(wire);
  document.getElementById('addLine').addEventListener('click', () => { const r = tpl.cloneNode(true); tbody.appendChild(r); wire(r); sortProducts(); r.querySelector('select').focus(); });
}";
require_once __DIR__ . '/../includes/footer.php';
