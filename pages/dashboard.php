<?php
// pages/dashboard.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/reenquiry.php';
requireLogin();

$db = getDB();
updateAgingStatuses($db);

$staffFilter = can('leads.all') ? null : currentStaffId();
$whereStaff  = $staffFilter ? "AND assigned_to = $staffFilter" : "";
$whereStaffL = $staffFilter ? "AND l.assigned_to = $staffFilter" : "";

// ── KPI counts ──
function count_leads(PDO $db, string $where = ''): int {
    return (int)$db->query("SELECT COUNT(*) FROM leads $where")->fetchColumn();
}

$total       = count_leads($db, "WHERE 1 $whereStaff");
$new_today   = count_leads($db, "WHERE DATE(created_at) = CURDATE() $whereStaff");
$new_week    = count_leads($db, "WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) $whereStaff");
$hot         = count_leads($db, "WHERE score >= 61 AND stage NOT IN ('converted','lost') $whereStaff");
$converted   = count_leads($db, "WHERE stage = 'converted' $whereStaff");
$critical    = count_leads($db, "WHERE aging_status = 'critical' AND stage NOT IN ('converted','lost','cold') $whereStaff");
$due_today   = (int)$db->query("
    SELECT COUNT(*) FROM followups f " . currentFollowupJoin() . "
    JOIN leads l ON l.id = f.lead_id
    WHERE DATE(f.next_followup_at) <= CURDATE() AND l.stage IN " . OPEN_STAGES_SQL . " $whereStaffL
")->fetchColumn();

$conversion_rate = $total > 0 ? round(($converted / $total) * 100, 1) : 0;

// ── Source breakdown ──
$sources = $db->query("
    SELECT source, COUNT(*) as cnt
    FROM leads WHERE 1 $whereStaff
    GROUP BY source ORDER BY cnt DESC
")->fetchAll();
$sourceTotal = array_sum(array_column($sources, 'cnt')) ?: 1;
$sourceNames = [];
foreach ($sources as $s) $sourceNames[] = trim(html_entity_decode(sourceBadge($s['source']), ENT_QUOTES, 'UTF-8'));

// ── Stage pipeline counts ──
$stages_raw = $db->query("
    SELECT stage, COUNT(*) as cnt
    FROM leads WHERE 1 $whereStaff
    GROUP BY stage
")->fetchAll(PDO::FETCH_KEY_PAIR);

// ── Recent leads ──
$recent = $db->query("
    SELECT l.*, s.name as staff_name
    FROM leads l
    LEFT JOIN staff s ON s.id = l.assigned_to
    WHERE 1 $whereStaffL
    ORDER BY l.created_at DESC LIMIT 6
")->fetchAll();

// ── Returning enquiries: known people who enquired again and haven't been contacted since ──
ensureReEnquirySchema($db);
$returning = $db->query("
    SELECT l.id, l.name, l.stage, l.reenquiry_at, l.reenquiry_note, l.last_contacted, s.name as staff_name
    FROM leads l
    LEFT JOIN staff s ON s.id = l.assigned_to
    WHERE " . REENQUIRY_OPEN_SQL . " $whereStaffL
    ORDER BY l.reenquiry_at DESC LIMIT 6
")->fetchAll();

// ── Follow-ups due (current follow-up of each open lead) ──
$due_followups = $db->query("
    SELECT l.id, l.name, l.phone, l.stage, l.score,
           f.next_followup_at, f.next_action, f.channel, s.name as staff_name
    FROM followups f " . currentFollowupJoin() . "
    JOIN leads l ON l.id = f.lead_id
    LEFT JOIN staff s ON s.id = l.assigned_to
    WHERE DATE(f.next_followup_at) <= CURDATE()
      AND l.stage IN " . OPEN_STAGES_SQL . " $whereStaffL
    ORDER BY f.next_followup_at ASC LIMIT 6
")->fetchAll();

// ── New leads per day, last 30 days (zero-filled) ──
$trendRaw = $db->query("
    SELECT DATE(created_at) as day, COUNT(*) as cnt
    FROM leads
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) $whereStaff
    GROUP BY DATE(created_at)
")->fetchAll(PDO::FETCH_KEY_PAIR);
$trendLabels = $trendData = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trendLabels[] = date('d M', strtotime($d));
    $trendData[]   = (int)($trendRaw[$d] ?? 0);
}
$trendTotal = array_sum($trendData);

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', staffName())[0];

$pageTitle = "$greeting, $firstName";
$pageSubtitle = $due_today > 0
    ? "You have <strong class=\"text-tx-2\">$due_today follow-up" . ($due_today == 1 ? '' : 's') . "</strong> due today and <strong class=\"text-tx-2\">$new_today new lead" . ($new_today == 1 ? '' : 's') . "</strong>."
    : "No follow-ups due. $new_today new lead" . ($new_today == 1 ? '' : 's') . " today.";
$pageActions = '<a href="/pages/followups.php" class="btn btn-ghost"><i class="bi bi-chat-left-text"></i>' . t('Follow-ups') . '</a>'
             . '<a href="/pages/lead_add.php" class="btn btn-gold"><i class="bi bi-plus-lg"></i>' . t('Add lead') . '</a>';
require_once __DIR__ . '/../includes/header.php';

$kpis = [
    ['Total leads', number_format($total), "+$new_week this week", 'bi-people', 'tone-gold', '/pages/leads.php'],
    ['Hot leads', $hot, 'Score 61+ and still open', 'bi-fire', 'tone-orange', '/pages/leads.php?score=hot'],
    ['Converted', $converted, "$conversion_rate% conversion rate", 'bi-check2-circle', 'tone-green', '/pages/leads.php?stage=converted'],
    ['Needs attention', $critical, 'No contact in 7+ days', 'bi-exclamation-circle', $critical ? 'tone-red' : 'tone-slate', '/pages/leads.php?aging=critical'],
];
$stageOrder = ['new' => 'New', 'contacted' => 'Contacted', 'qualified' => 'Qualified', 'booked' => 'Booked', 'converted' => 'Converted'];
$stageVars  = ['new' => 'slate', 'contacted' => 'blue', 'qualified' => 'violet', 'booked' => 'amber', 'converted' => 'green'];
$pipeMax = max(1, max(array_map(fn($s) => $stages_raw[$s] ?? 0, array_keys($stageOrder))));
?>

<!-- KPI Row -->
<div class="row g-3 page-section">
  <?php foreach ($kpis as [$label, $value, $foot, $icon, $tone, $href]): ?>
  <div class="col-6 col-xl-3">
    <a class="stat-card" href="<?= $href ?>">
      <div class="stat-top">
        <div class="stat-label"><?= $label ?></div>
        <span class="stat-icon <?= $tone ?>"><i class="bi <?= $icon ?>"></i></span>
      </div>
      <div class="stat-value"><?= $value ?></div>
      <div class="stat-foot"><?= $foot ?></div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<!-- Pipeline + Sources -->
<div class="row g-3 page-section">
  <div class="col-xl-8">
    <div class="card-crm h-100">
      <div class="card-header-crm">
        <span><i class="bi bi-funnel"></i> <?= t('Pipeline') ?></span>
        <a href="/pages/leads.php" class="card-link"><?= t('View all leads') ?> <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="card-body-crm">
        <div class="pipeline-strip mb-4">
          <?php foreach ($stageOrder as $st => $label): $cnt = $stages_raw[$st] ?? 0; ?>
          <a href="/pages/leads.php?stage=<?= $st ?>" class="pipeline-step" style="--dot:var(--<?= $stageVars[$st] ?>)">
            <div class="ps-count"><?= $cnt ?></div>
            <div class="ps-label"><?= $label ?></div>
            <div class="ps-bar"><span style="width:<?= round($cnt / $pipeMax * 100) ?>%"></span></div>
          </a>
          <?php endforeach; ?>
        </div>
        <div class="d-flex align-items-baseline justify-content-between mb-2">
          <div class="section-title mb-0"><?= t('New leads · last 30 days') ?></div>
          <div class="fs-12 text-tx-3"><strong class="text-tx-2"><?= $trendTotal ?></strong> <?= t('total') ?></div>
        </div>
        <div style="height:190px"><canvas id="trendChart"></canvas></div>
      </div>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="card-crm h-100">
      <div class="card-header-crm">
        <span><i class="bi bi-signpost-split"></i> <?= t('Lead sources') ?></span>
        <a href="/pages/analytics.php" class="card-link"><?= t('Analytics') ?> <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="card-body-crm">
        <?php if (!$sources): ?>
        <div class="empty-state"><i class="bi bi-pie-chart"></i><p><?= t('No leads yet.') ?></p></div>
        <?php else: ?>
        <div class="mx-auto mb-3" style="height:170px;max-width:170px"><canvas id="sourceChart"></canvas></div>
        <ul class="legend-list">
          <?php foreach ($sources as $i => $s): ?>
          <li>
            <span class="swatch" data-series="<?= $i ?>"></span>
            <a href="/pages/leads.php?source=<?= urlencode($s['source']) ?>" class="text-tx-2"><?= sourceBadge($s['source']) ?></a>
            <span class="val"><?= $s['cnt'] ?></span>
            <span class="pct"><?= round($s['cnt'] / $sourceTotal * 100) ?>%</span>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($returning): ?>
<div class="card-crm mb-3">
  <div class="card-header-crm">
    <span><i class="bi bi-arrow-repeat"></i> <?= t('Returning enquiries') ?></span>
    <a href="/pages/leads.php?aging=returning" class="card-link"><?= t('View all') ?> <i class="bi bi-arrow-right"></i></a>
  </div>
  <div class="list-rows">
    <?php foreach ($returning as $l): ?>
    <a class="list-row" href="/pages/lead_view.php?id=<?= $l['id'] ?>">
      <span class="avatar avatar-sm"><?= sanitize(initials($l['name'])) ?></span>
      <div class="list-row-main">
        <div class="list-row-title"><?= sanitize($l['name']) ?></div>
        <div class="list-row-sub"><?= sanitize($l['reenquiry_note'] ?? '') ?> · <?= timeSince($l['reenquiry_at']) ?><?= $l['staff_name'] && can('leads.all') ? ' · ' . sanitize($l['staff_name']) : '' ?></div>
      </div>
      <div class="list-row-end"><?= stageBadge($l['stage']) ?></div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Follow-ups due + Recent leads -->
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card-crm h-100">
      <div class="card-header-crm">
        <span><i class="bi bi-clock-history"></i> <?= t('Follow-ups due') ?></span>
        <a href="/pages/followups.php" class="card-link"><?= t('View all') ?> <i class="bi bi-arrow-right"></i></a>
      </div>
      <?php if (empty($due_followups)): ?>
        <div class="empty-state"><i class="bi bi-check2-circle"></i><div class="empty-state-title"><?= t('All caught up') ?></div><p><?= t('No follow-ups due today.') ?></p></div>
      <?php else: ?>
      <div class="list-rows">
        <?php foreach ($due_followups as $fu):
          $overdue = strtotime($fu['next_followup_at']) < strtotime('today'); ?>
        <a class="list-row" href="/pages/lead_view.php?id=<?= $fu['id'] ?>">
          <span class="avatar avatar-sm"><?= sanitize(initials($fu['name'])) ?></span>
          <div class="list-row-main">
            <div class="list-row-title"><?= sanitize($fu['name']) ?></div>
            <div class="list-row-sub"><?= $fu['next_action'] ? sanitize($fu['next_action']) : sanitize($fu['phone']) ?><?= $fu['staff_name'] && can('leads.all') ? ' · ' . sanitize($fu['staff_name']) : '' ?></div>
          </div>
          <div class="list-row-end">
            <?= stageBadge($fu['stage']) ?>
            <span class="chip <?= $overdue ? 'aging-critical' : 'aging-due' ?>" title="<?= formatDate($fu['next_followup_at']) ?>">
              <?= $overdue ? 'Overdue ' . str_replace(' ago', '', timeSince($fu['next_followup_at'])) : 'Today ' . date('h:i A', strtotime($fu['next_followup_at'])) ?>
            </span>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card-crm h-100">
      <div class="card-header-crm">
        <span><i class="bi bi-person-plus"></i> <?= t('Recent leads') ?></span>
        <a href="/pages/leads.php" class="card-link"><?= t('View all') ?> <i class="bi bi-arrow-right"></i></a>
      </div>
      <?php if (empty($recent)): ?>
        <div class="empty-state"><i class="bi bi-people"></i><div class="empty-state-title"><?= t('No leads yet') ?></div><p><a href="/pages/lead_add.php"><?= t('Add your first lead') ?></a></p></div>
      <?php else: ?>
      <div class="list-rows">
        <?php foreach ($recent as $l): ?>
        <a class="list-row" href="/pages/lead_view.php?id=<?= $l['id'] ?>">
          <span class="avatar avatar-sm"><?= sanitize(initials($l['name'])) ?></span>
          <div class="list-row-main">
            <div class="list-row-title"><?= sanitize($l['name']) ?></div>
            <div class="list-row-sub"><?= sourceBadge($l['source']) ?> · <?= timeSince($l['created_at']) ?></div>
          </div>
          <div class="list-row-end">
            <?= stageBadge($l['stage']) ?>
            <?= scoreChip((int)$l['score']) ?>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
$extraJs = "
document.querySelectorAll('.legend-list .swatch').forEach(el => el.style.background = CRM.series[el.dataset.series % CRM.series.length]);

const trendCtx = document.getElementById('trendChart');
if (trendCtx) new Chart(trendCtx, {
  type: 'bar',
  data: {
    labels: " . json_encode($trendLabels) . ",
    datasets: [{ label: 'New leads', data: " . json_encode($trendData) . ", backgroundColor: CRM.colors.gold, hoverBackgroundColor: '#8A6934', maxBarThickness: 18 }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 } },
      y: { beginAtZero: true, ticks: { precision: 0, maxTicksLimit: 4 } }
    }
  }
});

const srcCtx = document.getElementById('sourceChart');
if (srcCtx) new Chart(srcCtx, {
  type: 'doughnut',
  data: {
    labels: " . json_encode($sourceNames) . ",
    datasets: [{ data: " . json_encode(array_map('intval', array_column($sources, 'cnt'))) . ", backgroundColor: CRM.series, borderColor: CRM.colors.surface, borderWidth: 2, hoverOffset: 4 }]
  },
  options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, cutout: '70%' }
});
";
require_once __DIR__ . '/../includes/footer.php';
?>
