<?php
// platform/plans.php — plans editor (platform owner and billing): prices in AED, the Stripe price ids, limits (empty =
// unlimited) and the modules each plan includes. Changes apply to every clinic on the plan at once and are audit-logged.
const PLATFORM_REQUEST = true;   // the platform's address, not a clinic's
require_once __DIR__ . '/../includes/platform_auth.php';
platformPage();
$admin = requirePlatformAdmin(['owner', 'billing']);
$p = platformDB();
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pCsrfCheck();
    try {
        $id = (int)($_POST['id'] ?? 0);
        $old = $p->query("SELECT * FROM plans WHERE id = $id")->fetch() ?: throw new RuntimeException('No such plan.');
        $limits = [];
        foreach (PLAN_LIMIT_LABELS as $k => $l) { $v = trim((string)($_POST['limit'][$k] ?? '')); $limits[$k] = $v === '' ? null : max(0, (int)$v); }
        $features = [];
        foreach (PLAN_FEATURE_LABELS as $k => $l) $features[$k] = !empty($_POST['feature'][$k]);
        $m = round((float)($_POST['price_month_aed'] ?? 0), 2); $y = round((float)($_POST['price_year_aed'] ?? 0), 2);
        if ($m <= 0 || $y <= 0) throw new RuntimeException('Enter both prices.');
        $p->prepare("UPDATE plans SET name = ?, price_month_aed = ?, price_year_aed = ?, stripe_price_month = ?, stripe_price_year = ?, limits = ?, features = ?, is_active = ? WHERE id = ?")
          ->execute([mb_substr(trim((string)$_POST['name']), 0, 80) ?: $old['name'], $m, $y, trim((string)($_POST['stripe_price_month'] ?? '')) ?: null, trim((string)($_POST['stripe_price_year'] ?? '')) ?: null,
                     json_encode($limits), json_encode($features), empty($_POST['is_active']) ? 0 : 1, $id]);
        platformAudit((int)$admin['id'], null, 'plan_changed', ['plan' => $old['code'], 'before' => ['month' => $old['price_month_aed'], 'limits' => json_decode($old['limits'], true)], 'after' => ['month' => $m, 'limits' => $limits]]);
        $msg = 'Saved.';
    } catch (RuntimeException $x) { $err = $x->getMessage(); }
}
pHead('Plans', $admin);
if ($msg) echo '<p class="ok" role="status">' . e($msg) . '</p>';
if ($err) echo '<p class="err" role="alert">' . e($err) . '</p>';
echo '<p class="muted">Placeholder prices from the brief; confirm before launch. Clinics with no plan (the first clinic, comp accounts) have no limits.</p>';
foreach ($p->query("SELECT * FROM plans ORDER BY sort_order")->fetchAll() as $pl) {
    $l = json_decode($pl['limits'], true) ?: []; $f = json_decode($pl['features'], true) ?: [];
    echo '<form method="POST" class="card">' . pCsrfField() . '<input type="hidden" name="id" value="' . (int)$pl['id'] . '"><h2 style="margin-top:0">' . e($pl['name']) . ' <span class="chip">' . e($pl['code']) . '</span></h2>'
       . '<div style="display:flex;gap:12px;flex-wrap:wrap"><div><label>Name</label><input name="name" value="' . e($pl['name']) . '"></div>'
       . '<div><label>AED a month</label><input name="price_month_aed" type="number" step="0.01" value="' . e($pl['price_month_aed']) . '"></div>'
       . '<div><label>AED a year</label><input name="price_year_aed" type="number" step="0.01" value="' . e($pl['price_year_aed']) . '"></div>'
       . '<div><label>Stripe price (month)</label><input name="stripe_price_month" value="' . e($pl['stripe_price_month']) . '" placeholder="price_…"></div>'
       . '<div><label>Stripe price (year)</label><input name="stripe_price_year" value="' . e($pl['stripe_price_year']) . '" placeholder="price_…"></div></div>'
       . '<div style="display:flex;gap:12px;flex-wrap:wrap">';
    foreach (PLAN_LIMIT_LABELS as $k => [, $many]) echo '<div><label>' . e(ucfirst($many)) . '</label><input name="limit[' . $k . ']" type="number" min="0" value="' . e(isset($l[$k]) ? (string)$l[$k] : '') . '" placeholder="unlimited" style="width:120px"></div>';
    echo '</div><p>';
    foreach (PLAN_FEATURE_LABELS as $k => $lab) echo '<label style="display:inline;font-weight:400;margin-right:14px"><input type="checkbox" name="feature[' . $k . ']" value="1"' . (!empty($f[$k]) ? ' checked' : '') . '> ' . e($lab) . '</label>';
    echo '</p><label style="display:inline;font-weight:400"><input type="checkbox" name="is_active" value="1"' . ($pl['is_active'] ? ' checked' : '') . '> Offered to clinics</label> <button style="margin-left:12px">Save ' . e($pl['name']) . '</button></form>';
}
pFoot();
