<?php
// pages/followups.php — each open lead's current scheduled follow-up
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();
$staffFilter = can('leads.all') ? '' : ('AND l.assigned_to = ' . currentStaffId());

$tabs = [
    'overdue'  => ['Overdue',  "AND f.next_followup_at < CURDATE()"],
    'today'    => ['Today',    "AND DATE(f.next_followup_at) = CURDATE()"],
    'upcoming' => ['Upcoming', "AND f.next_followup_at >= CURDATE() + INTERVAL 1 DAY"],
    'all'      => ['All scheduled', ""],
];
$filter = $_GET['filter'] ?? 'overdue';
if ($filter === 'due') $filter = 'overdue'; // old links
if (!isset($tabs[$filter])) $filter = 'overdue';

$base = "FROM followups f " . currentFollowupJoin() . "
    JOIN leads l ON l.id = f.lead_id
    LEFT JOIN staff s ON s.id = l.assigned_to
    WHERE f.next_followup_at IS NOT NULL AND l.stage IN " . OPEN_STAGES_SQL . " $staffFilter";

$counts = [];
foreach ($tabs as $key => [, $cond]) {
    $counts[$key] = (int)$db->query("SELECT COUNT(*) $base $cond")->fetchColumn();
}

$followups = $db->query("
    SELECT f.*, l.name as lead_name, l.phone, l.stage, l.score, l.aging_status, s.name as staff_name
    $base {$tabs[$filter][1]}
    ORDER BY f.next_followup_at ASC
    LIMIT 500
")->fetchAll();

$pageTitle = 'Follow-ups';
$pageSubtitle = 'The next scheduled contact for every open lead.';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="toolbar">
  <div class="segmented">
    <?php foreach ($tabs as $key => [$label]): ?>
    <a href="?filter=<?= $key ?>" class="<?= $filter === $key ? 'active' : '' ?>"><?= $label ?> <span class="count"><?= $counts[$key] ?></span></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="card-crm">
  <?php if (!$followups): ?>
  <div class="empty-state">
    <i class="bi bi-check2-circle"></i>
    <div class="empty-state-title"><?= $filter === 'overdue' ? 'Nothing overdue' : 'No follow-ups here' ?></div>
    <p><?= $filter === 'overdue' ? 'Every open lead has been contacted on time.' : 'Schedule the next contact from a lead’s page.' ?></p>
  </div>
  <?php else: ?>
  <div class="table-responsive">
  <table id="fuTable" class="table table-hover">
    <thead>
      <tr>
        <th><?= t('Due') ?></th>
        <th><?= t('Lead') ?></th>
        <th><?= t('Next action') ?></th>
        <th><?= t('Last outcome') ?></th>
        <th><?= t('Last remark') ?></th>
        <?php if (can('leads.all')): ?><th><?= t('Owner') ?></th><?php endif; ?>
        <th class="text-end no-sort"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($followups as $fu):
      $ts = strtotime($fu['next_followup_at']);
      $overdue = $ts < strtotime('today');
      $isToday = date('Y-m-d', $ts) === date('Y-m-d');
      $wa = preg_replace('/\D/', '', $fu['phone']); ?>
    <tr class="row-link" data-href="/pages/lead_view.php?id=<?= $fu['lead_id'] ?>">
      <td data-order="<?= $ts ?>" class="text-nowrap">
        <div class="fw-600 <?= $overdue ? 'text-red' : ($isToday ? 'text-amber' : '') ?>">
          <?= $isToday ? 'Today' : ($overdue ? str_replace(' ago', '', timeSince($fu['next_followup_at'])) . ' overdue' : date('D, d M', $ts)) ?>
        </div>
        <span class="cell-sub"><?= date('d M Y · h:i A', $ts) ?></span>
      </td>
      <td>
        <a href="/pages/lead_view.php?id=<?= $fu['lead_id'] ?>" class="cell-title"><?= sanitize($fu['lead_name']) ?></a>
        <span class="cell-sub"><?= sanitize($fu['phone']) ?> · <?= ucfirst($fu['stage']) ?></span>
      </td>
      <td><?= $fu['next_action'] ? sanitize($fu['next_action']) : '<span class="text-tx-3">—</span>' ?></td>
      <td><?= outcomeLabel($fu['outcome']) ?></td>
      <td class="text-tx-2" style="max-width:260px"><div class="text-truncate" title="<?= sanitize($fu['remarks']) ?>"><?= sanitize($fu['remarks']) ?></div></td>
      <?php if (can('leads.all')): ?><td class="text-nowrap"><?= $fu['staff_name'] ? sanitize($fu['staff_name']) : '<span class="text-tx-3">' . t('Unassigned') . '</span>' ?></td><?php endif; ?>
      <td class="text-end">
        <div class="row-actions">
          <a href="tel:<?= sanitize($fu['phone']) ?>" class="btn-icon" title="<?= t('Call') ?>"><i class="bi bi-telephone"></i></a>
          <a href="https://wa.me/<?= $wa ?>" target="_blank" rel="noopener" class="btn-icon success" title="<?= t('WhatsApp') ?>"><i class="bi bi-whatsapp"></i></a>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php
$extraJs = "initDataTable('#fuTable', {order:[[0,'asc']], columnDefs:[{orderable:false, targets:'no-sort'}]});";
require_once __DIR__ . '/../includes/footer.php';
?>
