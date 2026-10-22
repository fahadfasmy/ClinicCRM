<?php
// pages/billing_settings.php — Admin → Payment & tax settings: whether prices include VAT, the VAT rate for other
// items, the largest discount each role may give without a manager, the clinic's discount offers (pre-approved, so
// no limit applies), and a check that every branch has its TRN for the tax invoice.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/billing.php';
requirePermission('setup.clinic');

$db = getDB();
$limitRoles = array_diff(array_keys(ROLES), ['admin']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $set = $db->prepare("REPLACE INTO integration_settings (k, v) VALUES (?, ?)");
    try {
        switch ($_POST['action'] ?? '') {
            case 'tax':
                $set->execute(['billing.prices_include_vat', empty($_POST['inclusive']) ? '0' : '1']);
                $set->execute(['billing.vat_rate', ($_POST['vat_rate'] ?? '5') === '0' ? '0' : '5']);
                foreach ($limitRoles as $r) {
                    $v = (float)($_POST['limit'][$r] ?? 0);
                    if ($v < 0 || $v > 100) throw new RuntimeException('Discount limits are 0–100%.');
                    $set->execute(["billing.discount_limit.$r", (string)$v]);
                }
                logActivity(currentStaffId(), 'billing_settings_changed', null, null, (empty($_POST['inclusive']) ? 'prices exclude VAT' : 'prices include VAT'));
                $_SESSION['setup_flash'] = ['success', 'Saved. New invoices use these settings; existing ones keep theirs.'];
                break;
            case 'rule':
                $rid = (int)($_POST['id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                $kind = ($_POST['kind'] ?? '') === 'amount' ? 'amount' : 'percent';
                $val = round((float)($_POST['value'] ?? 0), 2);
                if ($name === '' || $val <= 0 || ($kind === 'percent' && $val > 100)) throw new RuntimeException('Give the offer a name and a value (percent up to 100).');
                $svc = array_values(array_map('intval', (array)($_POST['services'] ?? [])));
                $f = [mb_substr($name, 0, 100), $kind, $val, ($_POST['max_amount'] ?? '') !== '' ? round((float)$_POST['max_amount'], 2) : null,
                      ($_POST['valid_from'] ?? '') ?: null, ($_POST['valid_to'] ?? '') ?: null, $svc ? json_encode($svc) : null, empty($_POST['is_active']) ? 0 : 1];
                if ($rid) $db->prepare("UPDATE discount_rules SET name = ?, kind = ?, value = ?, max_amount = ?, valid_from = ?, valid_to = ?, service_ids = ?, is_active = ? WHERE id = ?")->execute([...$f, $rid]);
                else $db->prepare("INSERT INTO discount_rules (name, kind, value, max_amount, valid_from, valid_to, service_ids, is_active) VALUES (?,?,?,?,?,?,?,?)")->execute($f);
                logActivity(currentStaffId(), 'discount_rule_saved', null, null, $name);
                $_SESSION['setup_flash'] = ['success', 'Offer saved.'];
                break;
        }
    } catch (Throwable $e) { $_SESSION['setup_flash'] = ['danger', $e->getMessage()]; }
    header('Location: /pages/billing_settings.php' . (($_POST['action'] ?? '') === 'rule' ? '#offers' : '')); exit;
}
$flash = $_SESSION['setup_flash'] ?? null;
unset($_SESSION['setup_flash']);
$inclusive = pricesIncludeVat($db);
$noTrn = array_filter(branchList($db), fn($b) => trim((string)$b['trn']) === '');
$rules = $db->query("SELECT * FROM discount_rules ORDER BY is_active DESC, name")->fetchAll();
$services = $db->query("SELECT id, name FROM services WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$editRule = isset($_GET['rule']) ? (array_values(array_filter($rules, fn($r) => (int)$r['id'] === (int)$_GET['rule']))[0] ?? null) : null;

$pageTitle = 'Payment & tax settings';
$pageBack = ['href' => '/pages/settings_clinic.php', 'label' => 'Clinic setup'];
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?> <?= $flash[0] === 'success' ? 'alert-auto' : '' ?>"><i class="bi bi-<?= $flash[0] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<?php if ($noTrn): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i><div><strong>Tax invoices need the clinic’s TRN.</strong> Add it to <?= implode(', ', array_map(fn($b) => '<a href="/pages/branch.php?id=' . (int)$b['id'] . '">' . sanitize($b['name']) . '</a>', $noTrn)) ?>.</div></div><?php endif; ?>
<form method="POST" class="card-crm mb-3"><?= csrfField() ?><input type="hidden" name="action" value="tax">
  <div class="card-header-crm"><span><i class="bi bi-percent"></i> VAT</span></div>
  <div class="card-body-crm">
    <fieldset><legend class="form-label">Treatment and product prices</legend>
      <div class="form-check"><input class="form-check-input" type="radio" name="inclusive" value="1" id="incY" <?= $inclusive ? 'checked' : '' ?>><label class="form-check-label" for="incY"><strong>Include VAT.</strong> A AED 1,200 treatment costs the patient 1,200 (1,142.86 + 57.14 VAT).</label></div>
      <div class="form-check"><input class="form-check-input" type="radio" name="inclusive" value="" id="incN" <?= $inclusive ? '' : 'checked' ?>><label class="form-check-label" for="incN"><strong>VAT is added.</strong> A AED 1,200 treatment costs the patient 1,260.</label></div>
    </fieldset>
    <div class="row g-3 mt-1"><div class="col-md-4"><label class="form-label" for="vr">VAT on other items</label><select class="form-select" id="vr" name="vat_rate"><option value="5">5% (standard)</option><option value="0" <?= billingSetting($db, 'vat_rate', '5') === '0' ? 'selected' : '' ?>>0%</option></select>
      <div class="fs-12 text-tx-3 mt-1">Each treatment and package has its own rate (Treatment menu).</div></div></div>
  </div>
  <div class="card-header-crm border-top"><span><i class="bi bi-tag"></i> Discount limits</span></div>
  <div class="card-body-crm"><p class="fs-13 text-tx-3">The largest discount on one item, as a percent, that each role may give. Above it, a person needs the “Discounts above the role limit” feature (Staff). Admins have no limit.</p>
    <div class="row g-2"><?php foreach ($limitRoles as $r): ?><div class="col-6 col-md-3"><label class="form-label" for="lim_<?= $r ?>"><?= ROLES[$r][0] ?></label>
      <div class="input-group"><input class="form-control text-end" type="number" min="0" max="100" step="0.5" id="lim_<?= $r ?>" name="limit[<?= $r ?>]" value="<?= sanitize(billingSetting($db, "discount_limit.$r", (string)(DISCOUNT_LIMIT_DEFAULTS[$r] ?? 0))) ?>"><span class="input-group-text">%</span></div></div><?php endforeach; ?></div></div>
  <div class="form-actions"><button class="btn btn-gold ms-auto"><i class="bi bi-check-lg"></i>Save</button></div>
</form>

<div class="row g-3" id="offers">
  <div class="col-lg-7"><div class="card-crm"><div class="card-header-crm"><span><i class="bi bi-stars"></i> Discount offers</span></div><div class="list-group list-group-flush">
    <?php foreach ($rules as $r): $ids = $r['service_ids'] ? json_decode($r['service_ids'], true) : []; ?>
    <a class="list-group-item list-group-item-action d-flex gap-2" href="?rule=<?= (int)$r['id'] ?>#offers"><span class="flex-grow-1"><span class="fw-600"><?= sanitize($r['name']) ?></span>
      <span class="d-block fs-12 text-tx-3"><?= $r['kind'] === 'percent' ? rtrim(rtrim($r['value'], '0'), '.') . '%' : money($r['value']) ?><?= $r['max_amount'] !== null ? ', at most ' . money($r['max_amount']) : '' ?>
        · <?= $ids ? sanitize(implode(', ', array_map(fn($i) => $services[$i] ?? '?', $ids))) : 'every treatment' ?><?= $r['valid_from'] || $r['valid_to'] ? ' · ' . ($r['valid_from'] ? formatDate($r['valid_from']) : '…') . ' – ' . ($r['valid_to'] ? formatDate($r['valid_to']) : '…') : '' ?></span></span>
      <?= $r['is_active'] ? '' : '<span class="chip tone-slate">Off</span>' ?></a>
    <?php endforeach; ?><?php if (!$rules): ?><div class="list-group-item fs-13 text-tx-3">No offers. An offer is a discount any cashier can apply, without the role limit.</div><?php endif; ?>
  </div></div></div>
  <div class="col-lg-5"><form method="POST" class="card-crm"><?= csrfField() ?><input type="hidden" name="action" value="rule"><input type="hidden" name="id" value="<?= (int)($editRule['id'] ?? 0) ?>">
    <div class="card-header-crm"><span><?= $editRule ? 'Edit offer' : 'New offer' ?></span></div>
    <div class="card-body-crm">
      <label class="form-label" for="rn">Name</label><input class="form-control mb-2" id="rn" name="name" maxlength="100" required value="<?= sanitize((string)($editRule['name'] ?? '')) ?>">
      <div class="row g-2 mb-2"><div class="col-5"><label class="form-label" for="rk">Type</label><select class="form-select" id="rk" name="kind"><option value="percent">Percent</option><option value="amount" <?= ($editRule['kind'] ?? '') === 'amount' ? 'selected' : '' ?>>Amount</option></select></div>
        <div class="col-3"><label class="form-label" for="rv">Value</label><input class="form-control" id="rv" name="value" type="number" min="0.01" step="0.01" required value="<?= sanitize((string)($editRule['value'] ?? '')) ?>"></div>
        <div class="col-4"><label class="form-label" for="rm">At most (AED)</label><input class="form-control" id="rm" name="max_amount" type="number" min="0" step="0.01" value="<?= sanitize((string)($editRule['max_amount'] ?? '')) ?>"></div></div>
      <div class="row g-2 mb-2"><div class="col-6"><label class="form-label" for="rf">From</label><input class="form-control" type="date" id="rf" name="valid_from" value="<?= sanitize((string)($editRule['valid_from'] ?? '')) ?>"></div>
        <div class="col-6"><label class="form-label" for="rt">Until</label><input class="form-control" type="date" id="rt" name="valid_to" value="<?= sanitize((string)($editRule['valid_to'] ?? '')) ?>"></div></div>
      <label class="form-label" for="rs">Only for <span class="fw-normal text-tx-3">(none chosen = every treatment)</span></label>
      <select class="form-select mb-2" id="rs" name="services[]" multiple size="5"><?php $sel = $editRule && $editRule['service_ids'] ? json_decode($editRule['service_ids'], true) : []; foreach ($services as $k => $n): ?><option value="<?= (int)$k ?>" <?= in_array((int)$k, $sel, true) ? 'selected' : '' ?>><?= sanitize($n) ?></option><?php endforeach; ?></select>
      <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="ra" name="is_active" value="1" <?= !$editRule || $editRule['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="ra">On</label></div>
    </div>
    <div class="form-actions"><?php if ($editRule): ?><a class="btn btn-ghost" href="/pages/billing_settings.php#offers">Cancel</a><?php endif; ?><button class="btn btn-gold ms-auto"><i class="bi bi-check-lg"></i>Save offer</button></div>
  </form></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
