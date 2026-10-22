<?php
// pages/stock_receive.php — record a delivery: product, batch, expiry, quantity, cost.
// ?po_line=ID receives against a purchase order line. A scanned GS1 barcode fills product, batch and expiry.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';
requirePermission('inventory.receive');

$db = getDB();
ensureInventorySchema($db);
$error = '';
$products = $db->query("SELECT * FROM products WHERE is_active = 1 ORDER BY category, name")->fetchAll();
$byId = array_column($products, null, 'id');

// Receiving against an order line
$poLineId = (int)($_POST['po_line_id'] ?? $_GET['po_line'] ?? 0);
$poLine = null;
if ($poLineId) {
    $s = $db->prepare("SELECT pl.*, po.status, po.id AS po_id, s.name AS supplier_name FROM purchase_order_lines pl
                       JOIN purchase_orders po ON po.id = pl.po_id JOIN suppliers s ON s.id = po.supplier_id WHERE pl.id = ?");
    $s->execute([$poLineId]);
    $poLine = $s->fetch() ?: null;
    if (!$poLine || !in_array($poLine['status'], ['ordered', 'partial'], true)) { $poLine = null; $poLineId = 0; $error = 'That order line is not open for receiving.'; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $pid = (int)($_POST['product_id'] ?? 0);
    try {
        if (!isset($byId[$pid])) throw new StockException('Pick the product you received.');
        $qty = (float)($_POST['qty'] ?? 0);
        receiveStock($db, $pid, $qty, [
            'lot_number'    => $_POST['lot_number'] ?? '',
            'expiry_date'   => $_POST['expiry_date'] ?? '',
            'supplier'      => $_POST['supplier'] ?? '',
            'invoice_ref'   => $_POST['invoice_ref'] ?? '',
            'unit_cost'     => $_POST['unit_cost'] ?? '',
            'cold_chain_ok' => isset($_POST['cold_chain_ok']),
            'po_line_id'    => $poLine ? $poLineId : null,
        ]);
        // A scanned product code that isn't linked yet is remembered on the product
        $gtin = normalizeGtin($_POST['scanned_gtin'] ?? '');
        if ($gtin && empty($byId[$pid]['gtin']) && can('inventory.manage')) {
            $taken = $db->prepare("SELECT id FROM products WHERE gtin = ?");
            $taken->execute([$gtin]);
            if (!$taken->fetch()) $db->prepare("UPDATE products SET gtin = ? WHERE id = ?")->execute([$gtin, $pid]);
        }
        logActivity(currentStaffId(), 'stock_received', null, null, $byId[$pid]['name'] . ' × ' . $qty);
        if ($poLine) {
            // Next outstanding line of the same order, or back to the order
            $next = $db->prepare("SELECT id FROM purchase_order_lines WHERE po_id = ? AND qty_received + 0.0005 < qty_ordered ORDER BY id LIMIT 1");
            $next->execute([$poLine['po_id']]);
            $n = (int)$next->fetchColumn();
            $to = isset($_POST['another']) && $n ? '/pages/stock_receive.php?po_line=' . $n . '&done=' . $pid : '/pages/purchase_order.php?id=' . $poLine['po_id'] . '&saved=1';
        } else {
            $to = isset($_POST['another']) ? '/pages/stock_receive.php?done=' . $pid : '/pages/product.php?id=' . $pid . '&saved=1';
        }
        header('Location: ' . $to); exit;
    } catch (StockException $e) {
        $error = $e->getMessage();
    }
}

$sel = (int)($_POST['product_id'] ?? ($poLine['product_id'] ?? $_GET['product'] ?? 0));
$suppliers = $db->query("SELECT name FROM suppliers WHERE is_active = 1 UNION SELECT DISTINCT supplier FROM stock_lots WHERE supplier IS NOT NULL ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$default = [];
if ($poLine) {
    $default = ['qty' => fmtQty(max(0, (float)$poLine['qty_ordered'] - (float)$poLine['qty_received'])),
                'unit_cost' => $poLine['unit_cost'] !== null ? (float)$poLine['unit_cost'] : '', 'supplier' => $poLine['supplier_name']];
}
$v = fn($k) => sanitize((string)($_POST[$k] ?? $default[$k] ?? ''));
$done = (int)($_GET['done'] ?? 0);

$pageTitle = 'Receive stock';
$pageBack  = $poLine ? ['href' => '/pages/purchase_order.php?id=' . $poLine['po_id'], 'label' => poNumber((int)$poLine['po_id'])] : ['href' => '/pages/inventory.php', 'label' => 'Inventory'];
$pageSubtitle = $poLine ? 'Against order ' . poNumber((int)$poLine['po_id']) . ' from ' . sanitize($poLine['supplier_name']) : 'Record a delivery exactly as printed on the box';
require_once __DIR__ . '/../includes/header.php';
?>
<script src="/assets/js/scan.js?v=1"></script>
<?php if ($done && isset($byId[$done])): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i> Received <?= sanitize($byId[$done]['name']) ?>. Add the next item.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><?= sanitize($error) ?></div><?php endif; ?>
<?php if (!$products): ?>
  <div class="card-crm"><div class="empty-state"><i class="bi bi-box-seam"></i>No products yet. <?php if (can('inventory.manage')): ?><a href="/pages/product.php?new=1">Add a product</a> first.<?php endif; ?></div></div>
<?php else: ?>
<?php if ($poLine): $p = $byId[(int)$poLine['product_id']] ?? null; ?>
<div class="alert alert-info" style="max-width:760px"><i class="bi bi-receipt"></i><div><strong><?= sanitize($p['name'] ?? '') ?></strong>: <?= fmtQty($poLine['qty_ordered']) ?> ordered, <?= fmtQty($poLine['qty_received']) ?> received so far.
  Enter what is in this delivery; a short delivery keeps the rest open on the order.</div></div>
<?php endif; ?>
<form method="POST" class="card-crm" style="max-width:760px" id="recvForm">
  <?= csrfField() ?>
  <input type="hidden" name="po_line_id" value="<?= $poLineId ?: '' ?>"><input type="hidden" name="scanned_gtin" id="rGtin" value="<?= $v('scanned_gtin') ?>">
  <div class="form-section scan-box">
    <label class="form-label" for="rScan"><i class="bi bi-upc-scan"></i> Scan the box</label>
    <input class="form-control" id="rScan" autocomplete="off" placeholder="Click here and scan the barcode on the pack" aria-describedby="rScanMsg">
    <div class="form-text" id="rScanMsg">A scanner fills the product, batch and expiry from the pack’s GS1 code. You can also type everything by hand.</div>
  </div>
  <div class="form-section">
    <div class="row g-3">
      <div class="col-12"><label class="form-label" for="rProd">Product <span class="req">*</span></label>
        <select class="form-select" id="rProd" name="product_id" required <?= $poLine ? 'disabled' : '' ?>>
          <option value="">Choose…</option>
          <?php $cat = null; foreach ($products as $p): if ($p['category'] !== $cat): if ($cat !== null) echo '</optgroup>'; $cat = $p['category']; ?><optgroup label="<?= sanitize($cat ?: 'Uncategorised') ?>"><?php endif; ?>
          <option value="<?= $p['id'] ?>" <?= $sel === (int)$p['id'] ? 'selected' : '' ?>
            data-su="<?= sanitize($p['stock_unit']) ?>" data-uu="<?= sanitize($p['usage_unit']) ?>" data-per="<?= (float)$p['units_per_stock'] ?>" data-gtin="<?= sanitize($p['gtin'] ?? '') ?>"
            data-lots="<?= (int)$p['track_lots'] ?>" data-cold="<?= $p['storage'] !== 'room' ? sanitize(STORAGE_LABELS[$p['storage']]) : '' ?>"><?= sanitize($p['name']) ?></option>
          <?php endforeach; ?></optgroup>
        </select>
        <?php if ($poLine): ?><input type="hidden" name="product_id" value="<?= (int)$poLine['product_id'] ?>"><?php endif; ?></div>
      <div class="col-md-5"><label class="form-label" for="rQty">Quantity received <span class="req">*</span></label>
        <div class="input-group"><input type="number" class="form-control" id="rQty" name="qty" min="0.001" step="any" required value="<?= $v('qty') ?>"><span class="input-group-text" id="rSU">units</span></div>
        <div class="form-text" id="rConv"></div></div>
      <div class="col-md-7"><label class="form-label" for="rCost">Cost per <span class="su-label">unit</span></label>
        <div class="input-group"><span class="input-group-text">AED</span><input type="number" class="form-control" id="rCost" name="unit_cost" min="0" step="0.01" value="<?= $v('unit_cost') ?>"></div>
        <div class="form-text">From the invoice. Used for stock value and treatment margins.</div></div>
      <div class="col-md-6 lot-field"><label class="form-label" for="rLot">Batch / lot number <span class="req">*</span></label><input class="form-control" id="rLot" name="lot_number" value="<?= $v('lot_number') ?>" autocomplete="off"></div>
      <div class="col-md-6 lot-field"><label class="form-label" for="rExp">Expiry date <span class="req">*</span></label><input type="date" class="form-control" id="rExp" name="expiry_date" min="<?= date('Y-m-d') ?>" value="<?= $v('expiry_date') ?>"></div>
      <div class="col-md-7"><label class="form-label" for="rSup">Supplier</label><input class="form-control" id="rSup" name="supplier" list="rSups" value="<?= $v('supplier') ?>" <?= $poLine ? 'readonly' : '' ?>>
        <datalist id="rSups"><?php foreach ($suppliers as $s): ?><option value="<?= sanitize($s) ?>"><?php endforeach; ?></datalist></div>
      <div class="col-md-5"><label class="form-label" for="rInv">Invoice / delivery note</label><input class="form-control" id="rInv" name="invoice_ref" value="<?= $v('invoice_ref') ?>"></div>
      <div class="col-12" id="coldBox" hidden>
        <div class="alert alert-info mb-0"><i class="bi bi-snow2"></i>
          <div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="rCold" name="cold_chain_ok" <?= isset($_POST['cold_chain_ok']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="rCold"><strong>Cold chain kept.</strong> This item must be stored <span id="coldLabel"></span>. The delivery arrived cold and went straight into storage.</label></div></div>
      </div>
    </div>
  </div>
  <div class="form-actions">
    <a href="<?= sanitize($pageBack['href']) ?>" class="btn btn-ghost">Cancel</a>
    <button type="submit" name="another" value="1" class="btn btn-ghost"><i class="bi bi-plus-lg"></i><?= $poLine ? 'Save and receive next line' : 'Save and receive another' ?></button>
    <button type="submit" class="btn btn-gold"><i class="bi bi-check-lg"></i>Receive</button>
  </div>
</form>
<?php endif; ?>
<?php
$extraJs = "
const sel = document.getElementById('rProd');
function syncProduct() {
  const o = sel?.selectedOptions[0]; if (!o || !o.value) return;
  const per = parseFloat(o.dataset.per) || 1;
  document.getElementById('rSU').textContent = o.dataset.su;
  document.querySelectorAll('.su-label').forEach(e => e.textContent = o.dataset.su);
  const lots = o.dataset.lots === '1';
  document.querySelectorAll('.lot-field input').forEach(i => i.required = lots);
  document.querySelectorAll('.lot-field .req').forEach(r => r.hidden = !lots);
  document.getElementById('coldBox').hidden = !o.dataset.cold;
  document.getElementById('coldLabel').textContent = (o.dataset.cold || '').toLowerCase();
  document.getElementById('rCold').required = !!o.dataset.cold;
  const q = parseFloat(document.getElementById('rQty').value) || 0;
  document.getElementById('rConv').textContent = per > 1 && q ? '= ' + (q * per).toLocaleString() + ' ' + o.dataset.uu : '';
}
sel?.addEventListener('change', syncProduct);
document.getElementById('rQty')?.addEventListener('input', syncProduct);
syncProduct();

// Barcode scan: find the product by its GS1 code, fill batch and expiry
const msg = document.getElementById('rScanMsg');
const say = (t, bad) => { msg.textContent = t; msg.className = 'form-text ' + (bad ? 'text-danger' : 'text-green'); };
GS1.attach(document.getElementById('rScan'), (r) => {
  if (!r) return say('That barcode couldn’t be read. Scan again, or type the details by hand.', true);
  const found = r.gtin && [...sel.options].find(o => o.dataset.gtin === r.gtin);
  const filled = [];
  if (found) {
    if (sel.disabled && sel.value !== found.value) return say('This pack is ' + found.textContent.trim() + ', not the product on this order line.', true);
    sel.value = found.value; syncProduct(); filled.push(found.textContent.trim());
  }
  if (r.batch) { document.getElementById('rLot').value = r.batch; filled.push('batch ' + r.batch); }
  if (r.expiry) { document.getElementById('rExp').value = r.expiry; filled.push('expiry ' + r.expiry); }
  if (r.gtin && !found) {
    document.getElementById('rGtin').value = r.gtin;
    say((filled.length ? 'Filled ' + filled.join(', ') + '. ' : '') + 'Product code ' + r.gtin + ' isn’t linked to a product yet: choose the product and it will be remembered.', true);
    return;
  }
  say('Filled ' + filled.join(', ') + '. Check the quantity and save.');
  document.getElementById('rQty').focus();
});";
require_once __DIR__ . '/../includes/footer.php';
