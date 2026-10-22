<?php
// pages/purchasing.php — reorder suggestions, purchase orders and suppliers.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';
requirePermission('purchasing.manage');

$db = getDB();
ensureInventorySchema($db);
$error = '';
$view = in_array($_GET['view'] ?? '', ['reorder', 'orders', 'suppliers'], true) ? $_GET['view'] : 'reorder';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_orders') {
            // One draft per supplier from the ticked suggestions
            $bySupplier = [];
            foreach ((array)($_POST['pick'] ?? []) as $pid) {
                $pid = (int)$pid;
                $sid = (int)($_POST['supplier'][$pid] ?? 0);
                $qty = (float)($_POST['qty'][$pid] ?? 0);
                if ($qty <= 0) continue;
                if (!$sid) throw new StockException('Choose a supplier for every ticked product.');
                $bySupplier[$sid][] = ['product_id' => $pid, 'qty' => $qty, 'unit_cost' => $_POST['cost'][$pid] ?? ''];
            }
            if (!$bySupplier) throw new StockException('Tick at least one product with a quantity.');
            $ids = [];
            foreach ($bySupplier as $sid => $lines) $ids[] = createPurchaseOrder($db, $sid, $lines);
            // Remember the supplier chosen for products that had none
            $set = $db->prepare("UPDATE products SET supplier_id = ? WHERE id = ? AND supplier_id IS NULL");
            foreach ($bySupplier as $sid => $lines) foreach ($lines as $l) $set->execute([$sid, $l['product_id']]);
            header('Location: ' . (count($ids) === 1 ? '/pages/purchase_order.php?id=' . $ids[0] . '&created=1' : '/pages/purchasing.php?view=orders&created=' . count($ids))); exit;
        }
        if ($action === 'save_supplier') {
            $sid = (int)($_POST['id'] ?? 0);
            $f = [
                'name'           => trim($_POST['name'] ?? ''),
                'contact_name'   => trim($_POST['contact_name'] ?? '') ?: null,
                'phone'          => trim($_POST['phone'] ?? '') ?: null,
                'email'          => trim($_POST['email'] ?? '') ?: null,
                'lead_time_days' => ($_POST['lead_time_days'] ?? '') !== '' ? max(0, (int)$_POST['lead_time_days']) : null,
                'notes'          => trim($_POST['notes'] ?? '') ?: null,
                'is_active'      => isset($_POST['is_active']) || !$sid ? 1 : 0,
            ];
            if ($f['name'] === '') throw new StockException('Enter the supplier name.');
            if ($f['email'] && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) throw new StockException('That email address doesn’t look right.');
            $dup = $db->prepare("SELECT id FROM suppliers WHERE name = ? AND id <> ?");
            $dup->execute([$f['name'], $sid]);
            if ($dup->fetch()) throw new StockException('A supplier with that name already exists.');
            if ($sid) {
                $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
                $db->prepare("UPDATE suppliers SET $sets WHERE id = ?")->execute([...array_values($f), $sid]);
            } else {
                $db->prepare("INSERT INTO suppliers (" . implode(', ', array_keys($f)) . ") VALUES (" . rtrim(str_repeat('?,', count($f)), ',') . ")")->execute(array_values($f));
            }
            logActivity(currentStaffId(), $sid ? 'supplier_updated' : 'supplier_created', null, null, $f['name']);
            header('Location: /pages/purchasing.php?view=suppliers&saved=1'); exit;
        }
    } catch (StockException $e) {
        $error = $e->getMessage();
    }
}

$suppliers = supplierOptions($db, false);
$activeSuppliers = array_values(array_filter($suppliers, fn($s) => $s['is_active']));
$openCount = (int)$db->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('draft','ordered','partial')")->fetchColumn();
$suggestions = reorderSuggestions($db);

$pageTitle    = 'Purchasing';
$pageSubtitle = 'What to order, from whom, and what is still on its way';
$pageActions  = '<a href="/pages/purchase_order.php?new=1" class="btn btn-gold"><i class="bi bi-plus-lg"></i>New order</a>';
require_once __DIR__ . '/../includes/header.php';
echo inventoryTabs('purchasing');
?>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i> Saved.</div><?php endif; ?>
<?php if (isset($_GET['created'])): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i> <?= (int)$_GET['created'] ?> draft orders created, one per supplier. Check each and place it.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><?= sanitize($error) ?></div><?php endif; ?>

<div class="toolbar">
  <div class="segmented" role="tablist" aria-label="Purchasing">
    <?php foreach (['reorder' => ['To order', count($suggestions)], 'orders' => ['Orders', $openCount], 'suppliers' => ['Suppliers', count($activeSuppliers)]] as $k => [$label, $n]): ?>
    <a href="?view=<?= $k ?>" class="<?= $view === $k ? 'active' : '' ?>" role="tab" aria-selected="<?= $view === $k ? 'true' : 'false' ?>"><?= $label ?> <span class="count"><?= $n ?></span></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($view === 'reorder'): ?>
  <?php if ($suggestions && !$activeSuppliers): ?>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i><div>Add a supplier first, then create orders from this list. <a href="?view=suppliers">Add supplier</a></div></div>
  <?php endif; ?>
  <form method="POST" class="card-crm">
    <?= csrfField() ?><input type="hidden" name="action" value="create_orders">
    <div class="card-header-crm"><span><i class="bi bi-cart-plus"></i> Suggested orders</span><span class="card-header-sub">Stock + on order − booked, compared with each product’s reorder level</span></div>
    <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th style="width:36px"><input class="form-check-input" type="checkbox" id="pickAll" aria-label="Select all" checked></th><th>Product</th><th class="text-end">Available</th><th class="text-end">On order</th><th class="text-end">Reorder level</th><th style="width:150px">Order</th><th style="width:130px">Cost each</th><th style="min-width:180px">Supplier</th></tr></thead>
      <tbody>
      <?php foreach ($suggestions as $s): $pid = (int)$s['id']; ?>
        <tr>
          <td><input class="form-check-input pick" type="checkbox" name="pick[]" value="<?= $pid ?>" checked aria-label="Order <?= sanitize($s['name']) ?>"></td>
          <td><a class="fw-600" href="/pages/product.php?id=<?= $pid ?>"><?= sanitize($s['name']) ?></a>
            <span class="cell-sub"><?= $s['why'] === 'bookings' ? '<span class="text-danger">Booked treatments need more than will be in stock</span>' : 'Below reorder level' ?></span></td>
          <td class="text-end tabular text-nowrap <?= $s['available'] < 0 ? 'text-danger fw-600' : '' ?>"><?= qtyUnit($s['available'], $s['usage_unit']) ?></td>
          <td class="text-end tabular text-nowrap"><?= $s['on_order'] > 0 ? qtyUnit($s['on_order'], $s['usage_unit']) : '<span class="text-tx-3">–</span>' ?></td>
          <td class="text-end tabular text-nowrap"><?= (float)$s['reorder_level'] > 0 ? qtyUnit($s['reorder_level'], $s['usage_unit']) : '<span class="text-tx-3">–</span>' ?></td>
          <td><div class="input-group input-group-sm"><input type="number" class="form-control" name="qty[<?= $pid ?>]" min="0" step="1" value="<?= $s['suggest_stock_units'] ?>" aria-label="Quantity of <?= sanitize($s['name']) ?>"><span class="input-group-text"><?= sanitize(unitWord($s['stock_unit'], 2)) ?></span></div>
            <?php if ((float)$s['units_per_stock'] > 1): ?><span class="cell-sub">= <?= qtyUnit($s['suggest_stock_units'] * (float)$s['units_per_stock'], $s['usage_unit']) ?></span><?php endif; ?></td>
          <td><div class="input-group input-group-sm"><span class="input-group-text">AED</span><input type="number" class="form-control" name="cost[<?= $pid ?>]" min="0" step="0.01" value="<?= $s['last_cost'] !== null ? $s['last_cost'] : '' ?>" aria-label="Cost per <?= sanitize($s['stock_unit']) ?>"></div></td>
          <td><select class="form-select form-select-sm" name="supplier[<?= $pid ?>]" aria-label="Supplier for <?= sanitize($s['name']) ?>">
            <option value="">Choose…</option>
            <?php foreach ($activeSuppliers as $sup): ?><option value="<?= $sup['id'] ?>" <?= (int)$s['supplier_id'] === (int)$sup['id'] ? 'selected' : '' ?>><?= sanitize($sup['name']) ?></option><?php endforeach; ?>
          </select></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$suggestions): ?><tr><td colspan="8" class="table-empty">Nothing to order. Every product is above its reorder level and covers its bookings.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
    <?php if ($suggestions): ?>
    <div class="form-actions"><span class="fs-12 text-tx-3 me-auto">Creates one draft order per supplier. Nothing is sent until you place it.</span>
      <button class="btn btn-gold" type="submit" <?= $activeSuppliers ? '' : 'disabled' ?>><i class="bi bi-cart-check"></i>Create draft orders</button></div>
    <?php endif; ?>
  </form>
  <p class="fs-12 text-tx-3 mt-3">Set a reorder level and usual order quantity on each product to get suggestions. Products without a level only appear when booked treatments need more than will be in stock.</p>

<?php elseif ($view === 'orders'):
  $fStatus = isset(PO_STATUS[$_GET['status'] ?? '']) ? $_GET['status'] : '';
  $orders = $db->query("
      SELECT po.*, s.name AS supplier_name, COUNT(pl.id) AS line_count,
             SUM(pl.qty_ordered * COALESCE(pl.unit_cost, 0)) AS total,
             SUM(GREATEST(pl.qty_ordered - pl.qty_received, 0)) AS outstanding
      FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id LEFT JOIN purchase_order_lines pl ON pl.po_id = po.id
      " . ($fStatus ? "WHERE po.status = " . $db->quote($fStatus) : "WHERE po.status IN ('draft','ordered','partial') OR po.created_at >= CURDATE() - INTERVAL 90 DAY") . "
      GROUP BY po.id ORDER BY FIELD(po.status,'partial','ordered','draft','received','closed','cancelled'), po.id DESC LIMIT 200")->fetchAll();
?>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <a class="chip chip-lg <?= !$fStatus ? 'aging-due' : '' ?>" href="?view=orders">Open and last 90 days</a>
    <?php foreach (PO_STATUS as $k => [$l]): ?><a class="chip chip-lg <?= $fStatus === $k ? 'aging-due' : '' ?>" href="?view=orders&status=<?= $k ?>"><?= $l ?></a><?php endforeach; ?>
  </div>
  <div class="card-crm">
    <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Order</th><th>Supplier</th><th>Created</th><th>Expected</th><th class="text-end">Lines</th><th class="text-end">Value</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): $late = in_array($o['status'], ['ordered', 'partial'], true) && $o['expected_date'] && $o['expected_date'] < date('Y-m-d'); ?>
        <tr class="row-link" data-href="/pages/purchase_order.php?id=<?= $o['id'] ?>">
          <td><a class="cell-title" href="/pages/purchase_order.php?id=<?= $o['id'] ?>"><?= poNumber((int)$o['id']) ?></a></td>
          <td><?= sanitize($o['supplier_name']) ?></td>
          <td class="text-nowrap"><?= date('d M Y', strtotime($o['created_at'])) ?></td>
          <td class="text-nowrap <?= $late ? 'text-danger fw-600' : '' ?>"><?= $o['expected_date'] ? date('d M Y', strtotime($o['expected_date'])) . ($late ? ' · late' : '') : '<span class="text-tx-3">–</span>' ?></td>
          <td class="text-end tabular"><?= (int)$o['line_count'] ?></td>
          <td class="text-end tabular text-nowrap"><?= (float)$o['total'] ? 'AED ' . number_format((float)$o['total'], 0) : '–' ?></td>
          <td><?= poBadge($o['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="7" class="table-empty">No orders<?= $fStatus ? ' with this status' : ' yet' ?>.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>

<?php else: // suppliers
  $stats = array_column($db->query("SELECT supplier_id, COUNT(*) AS orders, MAX(created_at) AS last_order FROM purchase_orders GROUP BY supplier_id")->fetchAll(), null, 'supplier_id');
  $products = array_column($db->query("SELECT supplier_id, COUNT(*) AS n FROM products WHERE supplier_id IS NOT NULL AND is_active = 1 GROUP BY supplier_id")->fetchAll(), 'n', 'supplier_id');
?>
  <div class="card-crm">
    <div class="card-header-crm"><span><i class="bi bi-truck"></i> Suppliers</span><button type="button" class="btn btn-ghost btn-sm" data-supplier="0"><i class="bi bi-plus-lg"></i>Add supplier</button></div>
    <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>Supplier</th><th>Contact</th><th class="text-end">Lead time</th><th class="text-end">Products</th><th class="text-end">Orders</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($suppliers as $s): ?>
        <tr class="<?= $s['is_active'] ? '' : 'opacity-50' ?>">
          <td><span class="fw-600"><?= sanitize($s['name']) ?></span><?= $s['is_active'] ? '' : ' <span class="chip">Inactive</span>' ?><?php if ($s['notes']): ?><span class="cell-sub"><?= sanitize($s['notes']) ?></span><?php endif; ?></td>
          <td><?= sanitize($s['contact_name'] ?? '') ?><span class="cell-sub"><?= sanitize(implode(' · ', array_filter([$s['phone'], $s['email']]))) ?></span></td>
          <td class="text-end tabular"><?= $s['lead_time_days'] !== null ? (int)$s['lead_time_days'] . ' days' : '–' ?></td>
          <td class="text-end tabular"><?= (int)($products[$s['id']] ?? 0) ?></td>
          <td class="text-end tabular"><?= (int)($stats[$s['id']]['orders'] ?? 0) ?><?php if (!empty($stats[$s['id']]['last_order'])): ?><span class="cell-sub">last <?= date('d M Y', strtotime($stats[$s['id']]['last_order'])) ?></span><?php endif; ?></td>
          <td class="text-end"><button type="button" class="btn-icon" aria-label="Edit <?= sanitize($s['name']) ?>" data-supplier="<?= $s['id'] ?>"
              data-json="<?= sanitize(json_encode($s)) ?>"><i class="bi bi-pencil"></i></button></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$suppliers): ?><tr><td colspan="6" class="table-empty">No suppliers yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>

  <div class="modal fade" id="supModal" tabindex="-1" aria-labelledby="supTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form method="POST" class="modal-content">
        <?= csrfField() ?><input type="hidden" name="action" value="save_supplier"><input type="hidden" name="id" id="sId">
        <div class="modal-header"><h5 class="modal-title" id="supTitle">Supplier</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12"><label class="form-label" for="sName">Name <span class="req">*</span></label><input class="form-control" id="sName" name="name" required maxlength="150"></div>
            <div class="col-md-6"><label class="form-label" for="sContact">Contact person</label><input class="form-control" id="sContact" name="contact_name" maxlength="100"></div>
            <div class="col-md-6"><label class="form-label" for="sPhone">Phone</label><input class="form-control" id="sPhone" name="phone" maxlength="40"></div>
            <div class="col-md-8"><label class="form-label" for="sEmail">Email</label><input type="email" class="form-control" id="sEmail" name="email" maxlength="150"></div>
            <div class="col-md-4"><label class="form-label" for="sLead">Delivery time</label><div class="input-group"><input type="number" class="form-control" id="sLead" name="lead_time_days" min="0" max="365"><span class="input-group-text">days</span></div></div>
            <div class="col-12"><label class="form-label" for="sNotes">Notes</label><input class="form-control" id="sNotes" name="notes" placeholder="Account number, ordering hours, minimum order"></div>
            <div class="col-12" id="sActiveWrap"><div class="form-check"><input class="form-check-input" type="checkbox" id="sActive" name="is_active" checked><label class="form-check-label" for="sActive">Active</label></div></div>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-gold">Save supplier</button></div>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php
$extraJs = "
document.getElementById('pickAll')?.addEventListener('change', e => document.querySelectorAll('.pick').forEach(c => c.checked = e.target.checked));
document.querySelectorAll('[data-supplier]').forEach(b => b.addEventListener('click', () => {
  const s = b.dataset.json ? JSON.parse(b.dataset.json) : {};
  const set = (id, v) => document.getElementById(id).value = v ?? '';
  set('sId', s.id); set('sName', s.name); set('sContact', s.contact_name); set('sPhone', s.phone); set('sEmail', s.email); set('sLead', s.lead_time_days); set('sNotes', s.notes);
  document.getElementById('sActive').checked = !s.id || s.is_active == 1;
  document.getElementById('sActiveWrap').hidden = !s.id;
  document.getElementById('supTitle').textContent = s.id ? 'Edit supplier' : 'Add supplier';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('supModal')).show();
}));
" . (isset($_GET['add']) ? "document.querySelector('[data-supplier=\"0\"]')?.click();" : '');
require_once __DIR__ . '/../includes/footer.php';
