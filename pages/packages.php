<?php
// pages/packages.php — Billing → Packages: treatment packages (sessions of one or more treatments, a price, how long
// they last) and, below, the value of sessions patients have paid for but not used yet (package liability).
// Selling one is an invoice line; the sessions are attached at booking and used when the visit is completed.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/billing.php';
requirePermission('billing.view');

$db = getDB();
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        $items = [];
        foreach ((array)($_POST['svc'] ?? []) as $i => $sid) if ((int)$sid && (int)($_POST['sessions'][$i] ?? 0) > 0) $items[(int)$sid] = ($items[(int)$sid] ?? 0) + (int)$_POST['sessions'][$i];
        savePackage($db, (int)($_POST['id'] ?? 0), $_POST, $items);
        $_SESSION['bill_flash'] = ['success', 'Package saved.'];
        header('Location: /pages/packages.php'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$flash = $_SESSION['bill_flash'] ?? null;
unset($_SESSION['bill_flash']);
$edit = null;
if (can('billing.packages_manage') && (isset($_GET['new']) || isset($_GET['id']) || $error)) {
    $edit = ['id' => 0, 'name' => '', 'name_ar' => '', 'price' => '', 'vat_rate' => 5, 'validity_days' => 365, 'is_active' => 1, 'items' => []];
    if ($id = (int)($_POST['id'] ?? $_GET['id'] ?? 0)) {
        $s = $db->prepare("SELECT * FROM packages WHERE id = ?"); $s->execute([$id]);
        $edit = ($s->fetch() ?: $edit);
        $it = $db->prepare("SELECT service_id, sessions FROM package_items WHERE package_id = ?"); $it->execute([$id]);
        $edit['items'] = $it->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    if ($error) $edit = array_merge($edit, array_intersect_key($_POST, $edit));
}
$services = $db->query("SELECT id, name, price FROM services WHERE is_active = 1 ORDER BY name")->fetchAll();
$liab = packageLiability($db);
$names = $liab ? $db->query("SELECT id, CONCAT_WS(' ', first_name, last_name) FROM patients WHERE id IN (" . implode(',', array_map('intval', array_column($liab, 'patient_id'))) . ")")->fetchAll(PDO::FETCH_KEY_PAIR) : [];

$pageTitle = 'Packages';
$pageSubtitle = 'Unused sessions worth ' . money(array_sum(array_map('floatval', array_column($liab, 'unused_value'))));
$pageActions = can('billing.packages_manage') && !$edit ? '<a class="btn btn-gold" href="?new=1"><i class="bi bi-plus-lg"></i>New package</a>' : '';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?> alert-auto"><i class="bi bi-check-circle"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i><div><strong><?= sanitize($error) ?></strong></div></div><?php endif; ?>
<?php if ($edit): $rows = $edit['items'] + array_fill_keys(['x1', 'x2', 'x3'], 0); ?>
<form method="POST" class="card-crm mb-3"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
  <div class="card-header-crm"><span><i class="bi bi-box2-heart"></i> <?= $edit['id'] ? 'Edit package' : 'New package' ?></span></div>
  <div class="card-body-crm"><div class="row g-3">
    <div class="col-md-6"><label class="form-label" for="pName">Name</label><input class="form-control" id="pName" name="name" maxlength="150" required value="<?= sanitize((string)$edit['name']) ?>"></div>
    <div class="col-md-6"><label class="form-label" for="pNameAr">Name (Arabic)</label><input class="form-control" id="pNameAr" name="name_ar" maxlength="150" dir="rtl" lang="ar" value="<?= sanitize((string)$edit['name_ar']) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label" for="pPrice">Price (AED)</label><input class="form-control" id="pPrice" name="price" type="number" min="0.01" step="0.01" required value="<?= sanitize((string)$edit['price']) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label" for="pVat">VAT</label><select class="form-select" id="pVat" name="vat_rate"><option value="5">5%</option><option value="0" <?= (float)$edit['vat_rate'] === 0.0 ? 'selected' : '' ?>>0%</option></select></div>
    <div class="col-6 col-md-3"><label class="form-label" for="pDays">Valid for (days)</label><input class="form-control" id="pDays" name="validity_days" type="number" min="1" max="3650" required value="<?= (int)$edit['validity_days'] ?>"></div>
    <div class="col-6 col-md-3 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="pAct" name="is_active" value="1" <?= $edit['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="pAct">On sale</label></div></div>
  </div>
  <fieldset class="mt-3"><legend class="form-label">Treatments and sessions</legend>
    <?php $n = 0; foreach ($rows as $sid => $cnt): ?><div class="row g-2 mb-2">
      <div class="col-8 col-md-6"><label class="visually-hidden" for="ps<?= $n ?>">Treatment</label><select class="form-select" id="ps<?= $n ?>" name="svc[<?= $n ?>]"><option value="">—</option><?php foreach ($services as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$sid === (int)$s['id'] ? 'selected' : '' ?>><?= sanitize($s['name']) ?> (<?= number_format($s['price'], 2) ?> each)</option><?php endforeach; ?></select></div>
      <div class="col-4 col-md-2"><label class="visually-hidden" for="pn<?= $n ?>">Sessions</label><input class="form-control" id="pn<?= $n ?>" name="sessions[<?= $n ?>]" type="number" min="0" max="999" value="<?= $cnt ?: '' ?>" placeholder="Sessions"></div>
    </div><?php $n++; endforeach; ?>
  </fieldset></div>
  <div class="form-actions"><a class="btn btn-ghost" href="/pages/packages.php">Cancel</a><button class="btn btn-gold ms-auto"><i class="bi bi-check-lg"></i>Save</button></div>
</form>
<?php endif; ?>
<div class="card-crm mb-3"><div class="list-group list-group-flush">
  <?php foreach (packageList($db, false) as $pk): ?>
  <<?= can('billing.packages_manage') ? 'a href="?id=' . (int)$pk['id'] . '"' : 'div' ?> class="list-group-item list-group-item-action d-flex gap-2 align-items-center">
    <span class="flex-grow-1"><span class="fw-600"><?= sanitize($pk['name']) ?></span><span class="d-block fs-12 text-tx-3"><?= sanitize((string)$pk['contents']) ?> · valid <?= (int)$pk['validity_days'] ?> days</span></span>
    <span class="tabular"><?= money($pk['price']) ?></span><?= $pk['is_active'] ? '' : '<span class="chip tone-slate">Not on sale</span>' ?>
  </<?= can('billing.packages_manage') ? 'a' : 'div' ?>>
  <?php endforeach; ?>
</div></div>
<div class="card-crm"><div class="card-header-crm"><span><i class="bi bi-hourglass-split"></i> Paid for, not used yet</span></div>
  <div class="table-responsive"><table class="table mb-0"><thead><tr><th>Patient</th><th>Package</th><th class="text-end">Sessions used</th><th>Expires</th><th class="text-end">Unused value</th></tr></thead>
  <tbody><?php foreach ($liab as $l): ?><tr><td><a href="/pages/patient.php?id=<?= (int)$l['patient_id'] ?>&amp;tab=billing"><?= sanitize((string)($names[$l['patient_id']] ?? '')) ?></a></td><td><?= sanitize($l['name']) ?></td>
    <td class="text-end tabular"><?= (int)$l['used'] ?> of <?= (int)$l['total'] ?></td><td><?= formatDate($l['expires_on']) ?></td><td class="text-end tabular"><?= number_format($l['unused_value'], 2) ?></td></tr><?php endforeach; ?>
  <?php if (!$liab): ?><tr><td colspan="5" class="text-tx-3">No open packages.</td></tr><?php endif; ?></tbody></table></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
