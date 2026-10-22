<?php
// pages/campaigns.php — Marketing → Campaigns: the funnel for leads created in a period, by campaign or by source:
// leads → booked → came → patients → invoiced → revenue (net, after credit notes) and collected, with cost per
// patient when a budget is entered. Campaigns are made from the leads' campaign names ("Find new campaigns") or by hand.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/crm.php';
require_once __DIR__ . '/../includes/billing.php';
requirePermission('marketing.campaigns');

$db = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        if (($_POST['action'] ?? '') === 'backfill') { $r = campaignBackfill($db); $_SESSION['crm_flash'] = ['success', "{$r['campaigns']} new campaign(s), {$r['leads']} lead(s) linked."]; }
        else { saveCampaign($db, (int)($_POST['id'] ?? 0), $_POST); $_SESSION['crm_flash'] = ['success', 'Campaign saved.']; }
    } catch (Throwable $e) { $_SESSION['crm_flash'] = ['danger', $e->getMessage()]; }
    header('Location: /pages/campaigns.php'); exit;
}
$flash = $_SESSION['crm_flash'] ?? null;
unset($_SESSION['crm_flash']);
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01', strtotime('-2 months'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$by = ($_GET['by'] ?? '') === 'source' ? 'source' : 'campaign';
$rows = funnelReport($db, $from, $to, $by);
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8'); header("Content-Disposition: attachment; filename=\"funnel-$by-$from-$to.csv\"");
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [ucfirst($by), 'Source', 'Leads', 'Booked', 'Came', 'Patients', 'Invoiced', 'Revenue (net)', 'Collected', 'Budget', 'Cost per patient']);
    foreach ($rows as $r) fputcsv($out, [$r['name'], $r['source'], $r['leads'], $r['booked'], $r['showed'], $r['patients'], $r['invoiced'], number_format($r['revenue'], 2, '.', ''), number_format($r['collected'], 2, '.', ''), $r['budget'], $r['cost_per_patient']]);
    exit;
}
$edit = isset($_GET['edit']) ? $db->query("SELECT * FROM campaigns WHERE id = " . (int)$_GET['edit'])->fetch() : null;
$sources = $db->query("SELECT slug, name FROM lead_sources ORDER BY sort_order")->fetchAll(PDO::FETCH_KEY_PAIR);
$tot = ['leads' => 0, 'booked' => 0, 'showed' => 0, 'patients' => 0, 'invoiced' => 0, 'revenue' => 0, 'collected' => 0];
foreach ($rows as $r) foreach ($tot as $k => $v) $tot[$k] += $r[$k];
$pct = fn($a, $b) => $b ? round($a / $b * 100) . '%' : '–';

$pageTitle = 'Campaigns';
$pageSubtitle = money($tot['revenue']) . ' revenue from ' . number_format($tot['leads']) . ' leads';
$pageActions = '<a class="btn btn-ghost" href="?' . sanitize(http_build_query(['from' => $from, 'to' => $to, 'by' => $by, 'export' => 'csv'])) . '"><i class="bi bi-download"></i>Export</a>'
    . '<form method="POST" class="d-inline">' . csrfField() . '<input type="hidden" name="action" value="backfill"><button class="btn btn-ghost"><i class="bi bi-magic"></i>Find new campaigns</button></form>';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?> <?= $flash[0] === 'success' ? 'alert-auto' : '' ?>"><i class="bi bi-info-circle"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<form class="card-crm page-section mb-3" method="GET"><div class="card-body-crm d-flex flex-wrap gap-2 align-items-end">
  <div><label class="form-label" for="ff">Leads from</label><input class="form-control" type="date" id="ff" name="from" value="<?= $from ?>"></div>
  <div><label class="form-label" for="ft">To</label><input class="form-control" type="date" id="ft" name="to" value="<?= $to ?>"></div>
  <div><label class="form-label" for="fb">By</label><select class="form-select" id="fb" name="by"><option value="campaign">Campaign</option><option value="source" <?= $by === 'source' ? 'selected' : '' ?>>Source</option></select></div>
  <button class="btn btn-gold">Show</button></div></form>
<div class="card-crm mb-3"><div class="table-responsive"><table class="table table-hover mb-0 align-middle">
  <thead><tr><th><?= $by === 'source' ? 'Source' : 'Campaign' ?></th><th class="text-end">Leads</th><th class="text-end">Booked</th><th class="text-end">Came</th><th class="text-end">Patients</th><th class="text-end">Invoiced</th><th class="text-end">Revenue</th><th class="text-end">Collected</th><th class="text-end">Cost / patient</th><?php if ($by === 'campaign'): ?><th></th><?php endif; ?></tr></thead>
  <tbody><?php foreach ($rows as $k => $r): ?><tr>
    <td><span class="fw-600"><?= sanitize($by === 'source' ? ($sources[$r['name']] ?? $r['name']) : $r['name']) ?></span><?= $by === 'campaign' && $r['source'] ? '<span class="cell-sub">' . sanitize($sources[$r['source']] ?? $r['source']) . '</span>' : '' ?></td>
    <td class="text-end tabular"><?= (int)$r['leads'] ?></td><td class="text-end tabular"><?= (int)$r['booked'] ?> <span class="fs-12 text-tx-3"><?= $pct($r['booked'], $r['leads']) ?></span></td>
    <td class="text-end tabular"><?= (int)$r['showed'] ?> <span class="fs-12 text-tx-3"><?= $pct($r['showed'], $r['booked']) ?></span></td><td class="text-end tabular"><?= (int)$r['patients'] ?></td>
    <td class="text-end tabular"><?= (int)$r['invoiced'] ?></td><td class="text-end tabular fw-600"><?= number_format($r['revenue'], 2) ?></td><td class="text-end tabular"><?= number_format($r['collected'], 2) ?></td>
    <td class="text-end tabular"><?= $r['cost_per_patient'] !== null ? number_format($r['cost_per_patient'], 2) : '–' ?></td>
    <?php if ($by === 'campaign'): ?><td><?php if ((int)$k): ?><a class="btn btn-ghost btn-sm" href="?edit=<?= (int)$k ?>&amp;from=<?= $from ?>&amp;to=<?= $to ?>">Budget</a><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="10" class="text-tx-3">No leads in this period.</td></tr><?php endif; ?></tbody>
  <tfoot><tr class="fw-700"><td>Total</td><td class="text-end"><?= $tot['leads'] ?></td><td class="text-end"><?= $tot['booked'] ?></td><td class="text-end"><?= $tot['showed'] ?></td><td class="text-end"><?= $tot['patients'] ?></td><td class="text-end"><?= $tot['invoiced'] ?></td>
    <td class="text-end"><?= number_format($tot['revenue'], 2) ?></td><td class="text-end"><?= number_format($tot['collected'], 2) ?></td><td></td><?php if ($by === 'campaign'): ?><td></td><?php endif; ?></tr></tfoot>
</table></div></div>
<form method="POST" class="card-crm" style="max-width:720px"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <div class="card-header-crm"><span><?= $edit ? 'Edit campaign' : 'New campaign' ?></span></div><div class="card-body-crm"><div class="row g-2">
    <div class="col-md-6"><label class="form-label" for="cn">Name <span class="fw-normal text-tx-3">(as on the leads)</span></label><input class="form-control" id="cn" name="name" required value="<?= sanitize((string)($edit['name'] ?? '')) ?>"></div>
    <div class="col-md-6"><label class="form-label" for="cs">Source</label><select class="form-select" id="cs" name="source"><option value="">—</option><?php foreach ($sources as $k => $n): ?><option value="<?= sanitize($k) ?>" <?= ($edit['source'] ?? '') === $k ? 'selected' : '' ?>><?= sanitize($n) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label" for="cb">Budget (AED)</label><input class="form-control" id="cb" name="budget" type="number" min="0" step="0.01" value="<?= sanitize((string)($edit['budget'] ?? '')) ?>"></div>
    <div class="col-md-4"><label class="form-label" for="c1">Starts</label><input class="form-control" type="date" id="c1" name="starts_on" value="<?= sanitize((string)($edit['starts_on'] ?? '')) ?>"></div>
    <div class="col-md-4"><label class="form-label" for="c2">Ends</label><input class="form-control" type="date" id="c2" name="ends_on" value="<?= sanitize((string)($edit['ends_on'] ?? '')) ?>"></div>
    <div class="col-md-4"><label class="form-label" for="cm1">Meta campaign ID</label><input class="form-control" id="cm1" name="meta_campaign_id" value="<?= sanitize((string)($edit['meta_campaign_id'] ?? '')) ?>"></div>
    <div class="col-md-4"><label class="form-label" for="cm2">Ad set ID</label><input class="form-control" id="cm2" name="meta_adset_id" value="<?= sanitize((string)($edit['meta_adset_id'] ?? '')) ?>"></div>
    <div class="col-md-4"><label class="form-label" for="cm3">Ad ID</label><input class="form-control" id="cm3" name="meta_ad_id" value="<?= sanitize((string)($edit['meta_ad_id'] ?? '')) ?>"></div>
  </div></div><div class="form-actions"><?php if ($edit): ?><a class="btn btn-ghost" href="/pages/campaigns.php">Cancel</a><?php endif; ?><button class="btn btn-gold ms-auto">Save</button></div></form>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
