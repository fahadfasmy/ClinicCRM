<?php
// pages/recall.php — find a batch by the number on a supplier notice, hold or recall it, and reach every patient who received it.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';
requirePermission('inventory.view');

$db = getDB();
ensureInventorySchema($db);
$error = '';
$q = trim($_GET['q'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    requirePermission('inventory.manage');
    try {
        $lotId = (int)($_POST['lot_id'] ?? 0);
        setLotStatus($db, $lotId, $_POST['status'] ?? '');
        logActivity(currentStaffId(), 'lot_status_changed', null, null, ($_POST['status'] ?? '') . ' lot #' . $lotId);
        header('Location: /pages/recall.php?' . http_build_query(['q' => $_POST['q'] ?? '', 'saved' => 1])); exit;
    } catch (StockException $e) {
        $error = $e->getMessage();
    }
}

$lotSql = "SELECT l.*, p.name AS product_name, p.usage_unit, p.stock_unit, p.units_per_stock,
                  (SELECT COUNT(DISTINCT m.appointment_id) FROM stock_movements m WHERE m.lot_id = l.id AND m.type = 'consume') AS patients,
                  (SELECT MAX(m.created_at) FROM stock_movements m WHERE m.lot_id = l.id AND m.type = 'consume') AS last_used,
                  (SELECT COALESCE(SUM(c.qty_left),0) FROM opened_containers c WHERE c.lot_id = l.id AND c.status = 'open') AS in_open
           FROM stock_lots l JOIN products p ON p.id = l.product_id";
if ($q !== '') {
    // Batch numbers are matched ignoring spaces, dashes and case, since notices and boxes print them differently
    $norm = preg_replace('/[\s\-\/.]+/', '', mb_strtolower($q));
    $st = $db->prepare("$lotSql WHERE REPLACE(REPLACE(REPLACE(REPLACE(LOWER(l.lot_number),' ',''),'-',''),'/',''),'.','') LIKE ? OR LOWER(p.name) LIKE ?
                        ORDER BY l.received_at DESC LIMIT 50");
    $st->execute(['%' . $norm . '%', '%' . mb_strtolower($q) . '%']);
    $results = $st->fetchAll();
} else {
    $results = $db->query("$lotSql WHERE l.status <> 'available' ORDER BY l.status = 'recalled' DESC, l.received_at DESC LIMIT 50")->fetchAll();
}

$pageTitle    = 'Batch recall';
$pageSubtitle = 'Look up a batch number from a supplier or authority notice';
require_once __DIR__ . '/../includes/header.php';
echo inventoryTabs('recall');
$statusChip = fn($s) => ['available' => ['Available', 'aging-fresh'], 'quarantined' => ['On hold', 'aging-overdue'], 'recalled' => ['Recalled', 'aging-critical']][$s];
?>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i> Batch status changed.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><?= sanitize($error) ?></div><?php endif; ?>

<form class="card-crm page-section" method="GET">
  <div class="card-body-crm">
    <label class="form-label" for="rq">Batch number</label>
    <div class="d-flex gap-2">
      <input class="form-control" id="rq" name="q" value="<?= sanitize($q) ?>" placeholder="e.g. C3456AB, or a product name" autofocus>
      <button class="btn btn-gold" type="submit"><i class="bi bi-search"></i>Find</button>
    </div>
    <div class="form-text">Spaces, dashes and upper or lower case don’t matter. Part of the number is enough.</div>
  </div>
</form>

<div class="card-crm">
  <div class="card-header-crm"><span><i class="bi bi-upc-scan"></i> <?= $q !== '' ? 'Matching batches' : 'Batches on hold or recalled' ?></span><span class="card-header-sub"><?= count($results) ?> found</span></div>
  <div class="table-responsive">
  <table class="table">
    <thead><tr><th>Batch</th><th>Product</th><th>Expiry</th><th class="text-end">On hand</th><th class="text-end">Patients</th><th>Status</th><th class="text-end"></th></tr></thead>
    <tbody>
    <?php foreach ($results as $l): [$sl, $sc] = $statusChip($l['status']); ?>
      <tr>
        <td><span class="fw-600"><?= sanitize($l['lot_number'] ?: 'No batch number') ?></span><span class="cell-sub"><?= sanitize($l['supplier'] ?: '') ?><?= $l['invoice_ref'] ? ' · ' . sanitize($l['invoice_ref']) : '' ?></span></td>
        <td><a href="/pages/product.php?id=<?= $l['product_id'] ?>"><?= sanitize($l['product_name']) ?></a><span class="cell-sub">Received <?= date('d M Y', strtotime($l['received_at'])) ?></span></td>
        <td class="text-nowrap"><?= $l['expiry_date'] ? date('d M Y', strtotime($l['expiry_date'])) : '–' ?></td>
        <td class="text-end tabular text-nowrap"><?= fmtStock($l['qty_on_hand'], $l) ?><?= (float)$l['in_open'] > 0 ? '<span class="cell-sub">' . fmtQty($l['in_open']) . ' in an opened ' . sanitize($l['stock_unit']) . '</span>' : '' ?></td>
        <td class="text-end tabular"><a href="/pages/appointments.php?lot=<?= $l['id'] ?>" class="fw-600"><?= (int)$l['patients'] ?></a><?= $l['last_used'] ? '<span class="cell-sub">last ' . date('d M Y', strtotime($l['last_used'])) . '</span>' : '' ?></td>
        <td><span class="chip <?= $sc ?>"><?= $sl ?></span></td>
        <td class="text-end text-nowrap">
          <a class="btn btn-ghost btn-sm" href="/pages/appointments.php?lot=<?= $l['id'] ?>"><i class="bi bi-person-lines-fill"></i>Patients</a>
          <?php if (can('inventory.manage')): ?>
          <div class="dropdown d-inline-block">
            <button type="button" class="btn-icon" data-bs-toggle="dropdown" aria-label="Change status"><i class="bi bi-three-dots"></i></button>
            <ul class="dropdown-menu dropdown-menu-end">
              <?php foreach (['quarantined' => 'Put on hold (stop using)', 'recalled' => 'Mark recalled', 'available' => 'Release: mark available'] as $st => $lbl): if ($st === $l['status']) continue; ?>
              <li><form method="POST"><?= csrfField() ?><input type="hidden" name="lot_id" value="<?= $l['id'] ?>"><input type="hidden" name="status" value="<?= $st ?>"><input type="hidden" name="q" value="<?= sanitize($q) ?>">
                <button class="dropdown-item" type="submit"><?= $lbl ?></button></form></li>
              <?php endforeach; ?>
              <?php if (can('inventory.waste') && (float)$l['qty_on_hand'] > 0): ?><li><a class="dropdown-item" href="/pages/wastage.php?lot=<?= $l['id'] ?>">Write off remaining stock…</a></li><?php endif; ?>
            </ul>
          </div>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$results): ?><tr><td colspan="7" class="table-empty"><?= $q !== '' ? 'No batch matches “' . sanitize($q) . '”. Check the number on the notice; if it was never received here, no patient got it.' : 'No batches are on hold or recalled.' ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<p class="fs-12 text-tx-3 mt-3">A batch on hold or recalled is no longer offered for treatments or counted as usable stock. Patients are listed from the batches recorded when each treatment was completed.</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
