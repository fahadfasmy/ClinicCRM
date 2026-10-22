<?php
// pages/subscription.php — Admin → Subscription: the clinic's plan and status (trial days left, overdue, read-only),
// usage against the plan's limits, choosing a plan (Stripe Checkout), managing the card, invoices and cancelling (Stripe
// Customer Portal), the legal documents accepted, and the full export. Works while the clinic is read-only or suspended.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); exit('Only admins manage the subscription.'); }

$db = getDB();
if (!multiTenant()) {
    $pageTitle = 'Subscription';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="card-crm"><div class="card-body-crm">' . t('This installation runs for one clinic and has no subscription or plan limits.') . '</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}
require_once __DIR__ . '/../includes/platform_billing.php';
require_once __DIR__ . '/../includes/legal.php';
$tid = currentTenant()['id'];
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        if (!empty($_SESSION['support'])) throw new RuntimeException('Support can’t change the subscription.');
        $url = ($_POST['action'] ?? '') === 'portal'
            ? billingPortalUrl($tid, tenantUrl(currentTenant(), '/pages/subscription.php'))
            : billingCheckoutUrl($tid, (string)($_POST['plan'] ?? ''), (string)($_POST['interval'] ?? 'month'));
        platformAudit(null, $tid, ($_POST['action'] ?? '') === 'portal' ? 'billing_portal_opened' : 'checkout_started', ['by' => staffName(), 'plan' => $_POST['plan'] ?? null]);
        header('Location: ' . $url, true, 303); exit;
    } catch (RuntimeException $e) { $err = $e->getMessage(); }
}

$rec = tenantRecord();
$plan = tenantPlan();
$sub = tenantSubscription($tid);
$plans = platformDB()->query("SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order")->fetchAll();
$legal = platformDB()->prepare("SELECT doc, version, accepted_by_email, at FROM legal_acceptances WHERE tenant_id = ? ORDER BY at DESC"); $legal->execute([$tid]);
$legal = $legal->fetchAll();
$tone = ['trial' => 'tone-blue', 'active' => 'tone-green', 'past_due' => 'tone-amber', 'read_only' => 'tone-red', 'suspended' => 'tone-red', 'cancelled' => 'tone-slate'];
$fmtLimit = fn($v) => $v === null ? t('Unlimited') : number_format((int)$v);

$pageTitle = 'Subscription';
$pageSubtitle = sanitize(($plan['name'] ?? t('No plan')) . ' · ' . t(TENANT_STATUS_LABELS[$rec['status']] ?? $rec['status']));
$pageActions = (!empty($sub['stripe_customer_id']) && !in_array($sub['status'] ?? '', ['trialing', ''], true) ? '<form method="POST" class="d-inline">' . csrfField() . '<input type="hidden" name="action" value="portal"><button class="btn btn-ghost"><i class="bi bi-credit-card"></i>' . t('Card, invoices and cancelling') . '</button></form>' : '')
    . '<a class="btn btn-ghost" href="/pages/export_all.php"><i class="bi bi-box-arrow-down"></i>' . t('Export all data') . '</a>';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($err): ?><div class="alert alert-danger" role="alert"><?= sanitize($err) ?></div><?php endif; ?>
<?php if (isset($_GET['paid'])): ?><div class="alert alert-success" role="status"><i class="bi bi-check-circle"></i><?= t('Thank you. Your payment is being confirmed; this page updates within a minute.') ?></div><?php endif; ?>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card-crm h-100"><div class="card-body-crm">
    <div class="fs-12 text-tx-3 text-uppercase fw-600"><?= t('Status') ?></div>
    <div class="fs-5 fw-700"><span class="chip <?= $tone[$rec['status']] ?? 'tone-slate' ?>"><?= sanitize(t(TENANT_STATUS_LABELS[$rec['status']] ?? $rec['status'])) ?></span></div>
    <div class="fs-13 text-tx-3 mt-1">
      <?php if ($rec['status'] === 'trial' && $rec['trial_ends_at']): ?><?= t('Free trial until') ?> <?= uiDate($rec['trial_ends_at']) ?>
      <?php elseif ($rec['status'] === 'past_due' && !empty($sub['grace_until'])): ?><?= t('Update the card before') ?> <?= uiDate($sub['grace_until']) ?>
      <?php elseif (!empty($sub['current_period_end']) && $rec['status'] === 'active'): ?><?= !empty($sub['cancel_at_period_end']) ? t('Ends on') : t('Renews on') ?> <?= uiDate($sub['current_period_end']) ?>
      <?php elseif ($rec['status'] === 'cancelled' && $rec['cancelled_at']): ?><?= t('Export available until') ?> <?= uiDate(date('Y-m-d', strtotime($rec['cancelled_at'] . ' +' . EXPORT_DAYS_AFTER_CANCEL . ' days'))) ?>
      <?php endif; ?>
    </div>
  </div></div></div>
  <div class="col-md-8"><div class="card-crm h-100"><div class="card-body-crm">
    <div class="fs-12 text-tx-3 text-uppercase fw-600 mb-2"><?= t('Usage this month') ?></div>
    <div class="row g-2"><?php foreach (PLAN_LIMIT_LABELS as $k => [, $many]): $lim = planLimit($k); $use = planUsage($db, $k); $pct = $lim ? min(100, (int)round($use / max(1, $lim) * 100)) : 0; ?>
      <div class="col-6 col-lg-4"><div class="fs-13"><?= sanitize(t(ucfirst($many))) ?></div>
        <div class="fw-600 tabular"><?= number_format($use) ?> / <?= $fmtLimit($lim) ?></div>
        <?php if ($lim): ?><div class="progress" style="height:6px" role="progressbar" aria-label="<?= sanitize(t(ucfirst($many))) ?>" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar <?= $pct >= 90 ? 'bg-danger' : '' ?>" style="width:<?= $pct ?>%"></div></div><?php endif; ?></div>
    <?php endforeach; ?></div>
  </div></div></div>
</div>

<h2 class="h5 mb-2"><?= t('Plans') ?></h2>
<div class="row g-3 mb-3">
  <?php foreach ($plans as $pl): $lim = json_decode($pl['limits'], true) ?: []; $feat = json_decode($pl['features'], true) ?: []; $current = $plan && (int)$plan['id'] === (int)$pl['id'] && $rec['status'] === 'active'; ?>
  <div class="col-md-4"><div class="card-crm h-100 <?= $current ? 'border border-2 border-warning' : '' ?>"><div class="card-body-crm">
    <div class="d-flex"><h3 class="h5 mb-1"><?= sanitize($pl['name']) ?></h3><?php if ($current): ?><span class="chip tone-green ms-auto"><?= t('Your plan') ?></span><?php endif; ?></div>
    <div class="fs-4 fw-700">AED <?= number_format((float)$pl['price_month_aed']) ?><span class="fs-13 fw-normal text-tx-3"> / <?= t('month') ?></span></div>
    <div class="fs-13 text-tx-3 mb-2"><?= t('or AED {n} a year (2 months free)', ['n' => number_format((float)$pl['price_year_aed'])]) ?></div>
    <ul class="fs-13 ps-3 mb-2"><?php foreach (PLAN_LIMIT_LABELS as $k => [$one, $many]): ?><li><?= $fmtLimit($lim[$k] ?? null) ?> <?= sanitize(t(($lim[$k] ?? 2) === 1 ? $one : $many)) ?></li><?php endforeach; ?>
      <?php foreach (PLAN_FEATURE_LABELS as $k => $l): ?><li class="<?= empty($feat[$k]) ? 'text-tx-3 text-decoration-line-through' : '' ?>"><?= sanitize(t($l)) ?></li><?php endforeach; ?></ul>
    <?php if (!$current): ?><form method="POST" class="d-flex gap-2"><?= csrfField() ?><input type="hidden" name="plan" value="<?= sanitize($pl['code']) ?>">
      <button class="btn btn-gold btn-sm" name="interval" value="month"><?= t('Monthly') ?></button><button class="btn btn-ghost btn-sm" name="interval" value="year"><?= t('Yearly') ?></button></form><?php endif; ?>
  </div></div></div>
  <?php endforeach; ?>
</div>
<p class="fs-13 text-tx-3"><?= t('Payment is by card through Stripe. Stripe receives the clinic’s name, email, TRN and plan, never patient data. Prices exclude VAT.') ?></p>

<div class="card-crm"><div class="card-header-crm"><span><?= t('Legal documents accepted') ?></span></div><div class="card-body-crm fs-13">
  <?php if ($legal): ?><ul class="mb-0"><?php foreach ($legal as $l): ?><li><a href="<?= sanitize(platformUrl('/' . $l['doc'] . '.php')) ?>" target="_blank" rel="noopener"><?= sanitize(t(LEGAL_DOCS[$l['doc']][0])) ?></a> · <?= t('version') ?> <?= sanitize($l['version']) ?> · <?= sanitize($l['accepted_by_email']) ?> · <?= uiDate($l['at']) ?></li><?php endforeach; ?></ul>
  <?php else: ?><span class="text-tx-3"><?= t('None recorded.') ?></span><?php endif; ?>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
