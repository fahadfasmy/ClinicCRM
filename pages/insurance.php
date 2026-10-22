<?php
// pages/insurance.php — Admin → Insurance: companies (payers and TPAs, with their eClaimLink IDs), their plans (co-pay,
// deductible, cap per visit, treatments that need approval), each plan's price list (?prices=<plan>), the denial codes
// and whether exported claim files are marked TEST or PRODUCTION. Viewing needs insurance.view; changes insurance.manage.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/insurance.php';
requirePermission('insurance.view');

$db = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    requirePermission('insurance.manage');
    $back = '/pages/insurance.php';
    try {
        switch ($_POST['action'] ?? '') {
            case 'company': saveInsuranceCompany($db, (int)($_POST['id'] ?? 0), $_POST); $msg = 'Company saved.'; break;
            case 'plan': $pid = saveInsurancePlan($db, (int)($_POST['id'] ?? 0), $_POST); $msg = 'Plan saved.'; break;
            case 'prices': savePlanPrices($db, (int)$_POST['plan_id'], (array)($_POST['p'] ?? []), $_POST['valid_from'] ?? null, $_POST['valid_to'] ?? null); $msg = 'Price list saved.'; $back .= '?prices=' . (int)$_POST['plan_id']; break;
            case 'denial':
                $code = strtoupper(trim((string)($_POST['code'] ?? ''))); $d = trim((string)($_POST['description'] ?? ''));
                if (!empty($_POST['delete'])) { $db->prepare("DELETE FROM denial_codes WHERE code = ?")->execute([$code]); $msg = 'Denial code removed.'; break; }
                if ($code === '' || $d === '') throw new RuntimeException('Enter the code and what it means.');
                $db->prepare("REPLACE INTO denial_codes (code, description) VALUES (?, ?)")->execute([mb_substr($code, 0, 20), mb_substr($d, 0, 255)]);
                $msg = 'Denial code saved.'; $back .= '#denials'; break;
            case 'disposition':
                $db->prepare("REPLACE INTO integration_settings (k, v) VALUES ('billing.eclaim_disposition', ?)")->execute([($_POST['v'] ?? '') === 'PRODUCTION' ? 'PRODUCTION' : 'TEST']);
                auditLog($db, 'setting', 0, 'eclaim_disposition', ['to' => $_POST['v'] ?? '']); $msg = 'Saved.'; break;
            default: throw new RuntimeException('Unknown action.');
        }
        $_SESSION['setup_flash'] = ['success', $msg];
    } catch (Throwable $e) { $_SESSION['setup_flash'] = ['danger', $e->getMessage()]; }
    header("Location: $back"); exit;
}
$flash = $_SESSION['setup_flash'] ?? null;
unset($_SESSION['setup_flash']);
$edit = can('insurance.manage');
$companies = insuranceCompanies($db);
$plans = insurancePlans($db);
$services = $db->query("SELECT id, name, price, cpt_code FROM services WHERE is_active = 1 ORDER BY name")->fetchAll();
$svcNames = array_column($services, 'name', 'id');
$pricePlan = isset($_GET['prices']) ? findPlan($db, (int)$_GET['prices']) : null;

$pageTitle = $pricePlan ? 'Prices: ' . $pricePlan['company'] . ' – ' . $pricePlan['name'] : 'Insurance';
$pageSubtitle = $pricePlan ? 'Leave a price empty to charge the cash price' : count($companies) . ' companies · ' . count($plans) . ' plans';
$pageBack = $pricePlan ? ['href' => '/pages/insurance.php', 'label' => 'Insurance'] : ['href' => '/pages/settings_clinic.php', 'label' => 'Clinic setup'];
require_once __DIR__ . '/../includes/header.php';
$editCo = isset($_GET['company']) ? (array_values(array_filter($companies, fn($c) => (int)$c['id'] === (int)$_GET['company']))[0] ?? null) : null;
$editPlan = isset($_GET['plan']) ? findPlan($db, (int)$_GET['plan']) : null;
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?> <?= $flash[0] === 'success' ? 'alert-auto' : '' ?>"><i class="bi bi-info-circle"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<?php if ($pricePlan): $pp = planPrices($db, (int)$pricePlan['id']); $pl = $db->query("SELECT * FROM price_lists WHERE insurance_plan_id = " . (int)$pricePlan['id'])->fetch(); ?>
<form method="POST" class="card-crm"><?= csrfField() ?><input type="hidden" name="action" value="prices"><input type="hidden" name="plan_id" value="<?= (int)$pricePlan['id'] ?>">
  <div class="card-body-crm d-flex gap-2 flex-wrap"><div><label class="form-label" for="vf">Valid from</label><input class="form-control" type="date" id="vf" name="valid_from" value="<?= sanitize((string)($pl['valid_from'] ?? '')) ?>"></div>
    <div><label class="form-label" for="vt">Until</label><input class="form-control" type="date" id="vt" name="valid_to" value="<?= sanitize((string)($pl['valid_to'] ?? '')) ?>"></div></div>
  <div class="table-responsive"><table class="table mb-0 align-middle"><thead><tr><th>Treatment</th><th class="text-end">Cash price</th><th style="width:160px">Plan price</th><th style="width:140px">CPT code</th></tr></thead><tbody>
  <?php foreach ($services as $s): $x = $pp[(int)$s['id']] ?? null; ?><tr><td><label for="pp<?= (int)$s['id'] ?>"><?= sanitize($s['name']) ?></label></td><td class="text-end tabular"><?= number_format($s['price'], 2) ?></td>
    <td><input class="form-control form-control-sm text-end" type="number" min="0" step="0.01" id="pp<?= (int)$s['id'] ?>" name="p[<?= (int)$s['id'] ?>][price]" value="<?= sanitize((string)($x['price'] ?? '')) ?>" <?= $edit ? '' : 'disabled' ?>></td>
    <td><input class="form-control form-control-sm" maxlength="10" name="p[<?= (int)$s['id'] ?>][cpt_code]" aria-label="CPT code for <?= sanitize($s['name']) ?>" value="<?= sanitize((string)($x['cpt_code'] ?? '')) ?>" placeholder="<?= sanitize((string)$s['cpt_code']) ?>" <?= $edit ? '' : 'disabled' ?>></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($edit): ?><div class="form-actions"><button class="btn btn-gold ms-auto"><i class="bi bi-check-lg"></i>Save prices</button></div><?php endif; ?>
</form>
<?php else: ?>
<div class="row g-3">
  <div class="col-xl-8">
    <?php foreach ($companies as $c): ?>
    <div class="card-crm mb-3"><div class="card-header-crm"><span><i class="bi bi-shield-plus"></i> <?= sanitize($c['name']) ?> <span class="fs-12 text-tx-3"><?= $c['type'] === 'tpa' ? 'TPA' : 'Payer' ?> · payer ID <?= sanitize((string)$c['payer_id'] ?: '—') ?><?= $c['receiver_id'] ? ' · receiver ' . sanitize($c['receiver_id']) : '' ?></span></span>
      <?= $c['is_active'] ? '' : '<span class="chip tone-slate ms-2">Inactive</span>' ?><?php if ($edit): ?><a class="btn btn-ghost btn-sm ms-auto" href="?company=<?= (int)$c['id'] ?>">Edit</a><?php endif; ?></div>
      <div class="list-group list-group-flush"><?php foreach (array_filter($plans, fn($p) => (int)$p['company_id'] === (int)$c['id']) as $p): $pre = $p['preauth_service_ids'] ? json_decode($p['preauth_service_ids'], true) : []; ?>
        <div class="list-group-item d-flex gap-2 align-items-center"><span class="flex-grow-1"><span class="fw-600"><?= sanitize($p['name']) ?></span><?= $p['network'] ? ' <span class="fs-12 text-tx-3">' . sanitize($p['network']) . '</span>' : '' ?>
          <span class="d-block fs-12 text-tx-3">Co-pay <?= rtrim(rtrim($p['copay_percent'], '0'), '.') ?>%<?= (float)$p['deductible'] ? ' after ' . money($p['deductible']) . ' deductible' : '' ?><?= $p['max_copay'] !== null ? ', at most ' . money($p['max_copay']) . ' a visit' : '' ?><?= $pre ? ' · approval needed for ' . sanitize(implode(', ', array_map(fn($i) => $svcNames[$i] ?? '?', $pre))) : '' ?></span></span>
          <?= $p['is_active'] ? '' : '<span class="chip tone-slate">Inactive</span>' ?>
          <a class="btn btn-ghost btn-sm" href="?prices=<?= (int)$p['id'] ?>">Prices</a><?php if ($edit): ?><a class="btn btn-ghost btn-sm" href="?plan=<?= (int)$p['id'] ?>">Edit</a><?php endif; ?></div>
      <?php endforeach; ?></div></div>
    <?php endforeach; ?>
    <?php if (!$companies): ?><div class="card-crm"><div class="empty-state"><i class="bi bi-shield-plus"></i><div class="empty-state-title">No insurance companies yet</div><p>Add the insurers and TPAs you work with, then their plans.</p></div></div><?php endif; ?>
    <div class="card-crm" id="denials"><div class="card-header-crm"><span><i class="bi bi-x-octagon"></i> Denial codes</span></div><div class="list-group list-group-flush">
      <?php foreach (denialCodes($db) as $code => $desc): ?><div class="list-group-item d-flex gap-2"><span class="fw-600 tabular"><?= sanitize($code) ?></span><span class="flex-grow-1"><?= sanitize($desc) ?></span>
        <?php if ($edit): ?><form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="denial"><input type="hidden" name="code" value="<?= sanitize($code) ?>"><input type="hidden" name="delete" value="1"><button class="btn btn-ghost btn-sm" aria-label="Remove <?= sanitize($code) ?>"><i class="bi bi-trash"></i></button></form><?php endif; ?></div><?php endforeach; ?>
      <?php if ($edit): ?><form method="POST" class="list-group-item d-flex gap-2"><?= csrfField() ?><input type="hidden" name="action" value="denial">
        <label class="visually-hidden" for="dc">Code</label><input class="form-control form-control-sm" style="max-width:130px" id="dc" name="code" placeholder="Code" required>
        <label class="visually-hidden" for="dd">Meaning</label><input class="form-control form-control-sm" id="dd" name="description" placeholder="What it means" required><button class="btn btn-ghost btn-sm">Add</button></form><?php endif; ?>
    </div></div>
  </div>
  <?php if ($edit): ?>
  <div class="col-xl-4">
    <form method="POST" class="card-crm mb-3"><?= csrfField() ?><input type="hidden" name="action" value="company"><input type="hidden" name="id" value="<?= (int)($editCo['id'] ?? 0) ?>">
      <div class="card-header-crm"><span><?= $editCo ? 'Edit company' : 'New insurance company' ?></span></div><div class="card-body-crm">
        <label class="form-label" for="cn">Name</label><input class="form-control mb-2" id="cn" name="name" required maxlength="150" value="<?= sanitize((string)($editCo['name'] ?? '')) ?>">
        <div class="row g-2 mb-2"><div class="col-5"><label class="form-label" for="ct">Type</label><select class="form-select" id="ct" name="type"><option value="payer">Payer</option><option value="tpa" <?= ($editCo['type'] ?? '') === 'tpa' ? 'selected' : '' ?>>TPA</option></select></div>
          <div class="col-7"><label class="form-label" for="cp">Payer ID (eClaimLink)</label><input class="form-control" id="cp" name="payer_id" maxlength="30" value="<?= sanitize((string)($editCo['payer_id'] ?? '')) ?>" placeholder="e.g. INS026"></div></div>
        <label class="form-label" for="cr">Receiver ID <span class="fw-normal text-tx-3">(the TPA, if claims go to one)</span></label><input class="form-control mb-2" id="cr" name="receiver_id" maxlength="30" value="<?= sanitize((string)($editCo['receiver_id'] ?? '')) ?>">
        <div class="row g-2"><div class="col-6"><label class="form-label" for="cph">Phone</label><input class="form-control" id="cph" name="phone" value="<?= sanitize((string)($editCo['phone'] ?? '')) ?>"></div>
          <div class="col-6"><label class="form-label" for="ce">Email</label><input class="form-control" id="ce" name="email" type="email" value="<?= sanitize((string)($editCo['email'] ?? '')) ?>"></div></div>
        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="ca" name="is_active" value="1" <?= !$editCo || $editCo['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="ca">Active</label></div>
      </div><div class="form-actions"><?php if ($editCo): ?><a class="btn btn-ghost" href="/pages/insurance.php">Cancel</a><?php endif; ?><button class="btn btn-gold ms-auto">Save company</button></div></form>
    <?php if ($companies): ?>
    <form method="POST" class="card-crm mb-3"><?= csrfField() ?><input type="hidden" name="action" value="plan"><input type="hidden" name="id" value="<?= (int)($editPlan['id'] ?? 0) ?>">
      <div class="card-header-crm"><span><?= $editPlan ? 'Edit plan' : 'New plan' ?></span></div><div class="card-body-crm">
        <label class="form-label" for="pc">Company</label><select class="form-select mb-2" id="pc" name="company_id"><?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)($editPlan['company_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= sanitize($c['name']) ?></option><?php endforeach; ?></select>
        <div class="row g-2 mb-2"><div class="col-7"><label class="form-label" for="pn">Plan name</label><input class="form-control" id="pn" name="name" required value="<?= sanitize((string)($editPlan['name'] ?? '')) ?>"></div>
          <div class="col-5"><label class="form-label" for="pnw">Network</label><input class="form-control" id="pnw" name="network" value="<?= sanitize((string)($editPlan['network'] ?? '')) ?>"></div></div>
        <div class="row g-2 mb-2"><div class="col-4"><label class="form-label" for="pcp">Co-pay %</label><input class="form-control" id="pcp" name="copay_percent" type="number" min="0" max="100" step="0.01" value="<?= sanitize((string)($editPlan['copay_percent'] ?? '0')) ?>"></div>
          <div class="col-4"><label class="form-label" for="pd">Deductible</label><input class="form-control" id="pd" name="deductible" type="number" min="0" step="0.01" value="<?= sanitize((string)($editPlan['deductible'] ?? '0')) ?>"></div>
          <div class="col-4"><label class="form-label" for="pm">Cap / visit</label><input class="form-control" id="pm" name="max_copay" type="number" min="0" step="0.01" value="<?= sanitize((string)($editPlan['max_copay'] ?? '')) ?>" placeholder="None"></div></div>
        <label class="form-label" for="ppa">Needs approval first</label>
        <select class="form-select mb-2" id="ppa" name="preauth[]" multiple size="4"><?php $sel = $editPlan && $editPlan['preauth_service_ids'] ? json_decode($editPlan['preauth_service_ids'], true) : []; foreach ($services as $s): ?><option value="<?= (int)$s['id'] ?>" <?= in_array((int)$s['id'], $sel, true) ? 'selected' : '' ?>><?= sanitize($s['name']) ?></option><?php endforeach; ?></select>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="pa" name="is_active" value="1" <?= !$editPlan || $editPlan['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="pa">Active</label></div>
      </div><div class="form-actions"><?php if ($editPlan): ?><a class="btn btn-ghost" href="/pages/insurance.php">Cancel</a><?php endif; ?><button class="btn btn-gold ms-auto">Save plan</button></div></form>
    <?php endif; ?>
    <form method="POST" class="card-crm"><?= csrfField() ?><input type="hidden" name="action" value="disposition">
      <div class="card-header-crm"><span><i class="bi bi-filetype-xml"></i> eClaimLink files</span></div><div class="card-body-crm">
        <label class="form-label" for="dv">Mark exported claim files as</label><select class="form-select" id="dv" name="v"><option value="TEST">TEST (until the insurance officer has checked one in the portal)</option><option value="PRODUCTION" <?= billingSetting($db, 'eclaim_disposition', 'TEST') === 'PRODUCTION' ? 'selected' : '' ?>>PRODUCTION</option></select>
      </div><div class="form-actions"><button class="btn btn-ghost ms-auto">Save</button></div></form>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
