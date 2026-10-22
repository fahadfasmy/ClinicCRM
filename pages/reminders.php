<?php
// pages/reminders.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();
$staffId = currentStaffId();

$success = $error = '';

// Add manual reminder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_reminder'])) {
    $leadId    = (int)($_POST['lead_id'] ?? 0);
    $message   = trim($_POST['message'] ?? '');
    $remindAt  = $_POST['remind_at'] ?? '';

    if (!$leadId || !$message || !$remindAt) {
        $error = 'Choose a lead, write a message and pick a time.';
    } else {
        $db->prepare("
            INSERT INTO reminders (lead_id, staff_id, message, remind_at, type)
            VALUES (?,?,?,?,'manual')
        ")->execute([$leadId, $staffId, $message, $remindAt]);
        $success = 'Reminder set.';
    }
}

// Mark reminder done (admins can close anyone's)
if (isset($_GET['dismiss']) && is_numeric($_GET['dismiss'])) {
    if (can('leads.all')) {
        $db->prepare("UPDATE reminders SET is_sent=1, sent_at=NOW() WHERE id=?")->execute([(int)$_GET['dismiss']]);
    } else {
        $db->prepare("UPDATE reminders SET is_sent=1, sent_at=NOW() WHERE id=? AND staff_id=?")->execute([(int)$_GET['dismiss'], $staffId]);
    }
    header('Location: /pages/reminders.php?dismissed=1');
    exit;
}

$whereStaff  = can('leads.all') ? '' : "AND r.staff_id = $staffId";
$whereStaffL = can('leads.all') ? '' : "AND l.assigned_to = $staffId";

// Pending reminders
$pending = $db->query("
    SELECT r.*, l.name as lead_name, l.phone, l.stage, s.name as staff_name
    FROM reminders r
    JOIN leads l ON l.id = r.lead_id
    LEFT JOIN staff s ON s.id = r.staff_id
    WHERE r.is_sent = 0 $whereStaff
    ORDER BY r.remind_at ASC
")->fetchAll();

// Leads needing attention (critical/overdue aging)
$agingTotal = (int)$db->query("SELECT COUNT(*) FROM leads l WHERE l.aging_status IN ('critical','overdue') AND l.stage NOT IN ('converted','lost','cold') $whereStaffL")->fetchColumn();
$agingLeads = $db->query("
    SELECT l.id, l.name, l.phone, l.stage, l.aging_status, l.last_contacted, l.created_at, s.name as staff_name
    FROM leads l
    LEFT JOIN staff s ON s.id = l.assigned_to
    WHERE l.aging_status IN ('critical','overdue')
      AND l.stage NOT IN ('converted','lost','cold') $whereStaffL
    ORDER BY FIELD(l.aging_status,'critical','overdue'), l.score DESC, COALESCE(l.last_contacted, l.created_at) ASC
    LIMIT 8
")->fetchAll();

// Recently completed
$sent = $db->query("
    SELECT r.*, l.name as lead_name
    FROM reminders r JOIN leads l ON l.id = r.lead_id
    WHERE r.is_sent = 1 $whereStaff
    ORDER BY r.sent_at DESC LIMIT 10
")->fetchAll();

// Leads for the add-reminder form
$leads = $db->query("
    SELECT id, name, phone FROM leads l
    WHERE stage NOT IN ('converted','lost') $whereStaffL
    ORDER BY name LIMIT 1000
")->fetchAll();
$preLead = (int)($_GET['lead_id'] ?? 0);

$agingSummary = $db->query("
    SELECT aging_status, COUNT(*) as cnt
    FROM leads l WHERE stage NOT IN ('converted','lost','cold') $whereStaffL
    GROUP BY aging_status
")->fetchAll(PDO::FETCH_KEY_PAIR);
$agingTotalOpen = array_sum($agingSummary) ?: 1;

$overdueCount = count(array_filter($pending, fn($r) => strtotime($r['remind_at']) < time()));

$pageTitle = 'Reminders';
$pageSubtitle = count($pending) ? count($pending) . ' pending' . ($overdueCount ? " · <span class=\"text-red\">$overdueCount overdue</span>" : '') : 'You’re all caught up.';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($success): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i><?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><?= $error ?></div><?php endif; ?>
<?php if (isset($_GET['dismissed'])): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i><?= t('Reminder marked as done.') ?></div><?php endif; ?>

<div class="row g-3">

  <div class="col-xl-8 d-flex flex-column gap-3">

    <!-- Pending reminders -->
    <div class="card-crm">
      <div class="card-header-crm">
        <span><i class="bi bi-bell"></i> <?= t('Pending reminders') ?></span>
        <span class="card-header-sub"><?= count($pending) ?> total</span>
      </div>
      <?php if (empty($pending)): ?>
        <div class="empty-state"><i class="bi bi-bell-slash"></i><div class="empty-state-title"><?= t('No pending reminders') ?></div><p><?= t('Set one with the form on the right.') ?></p></div>
      <?php else: ?>
      <div class="list-rows">
        <?php foreach ($pending as $r):
          $ts = strtotime($r['remind_at']);
          $isOverdue = $ts < time(); ?>
        <div class="list-row">
          <span class="stat-icon <?= $isOverdue ? 'tone-red' : 'tone-gold' ?>" style="width:32px;height:32px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center">
            <i class="bi <?= $r['type'] === 'manual' ? 'bi-bell' : ($r['type'] === 'lead_aging' ? 'bi-hourglass-split' : 'bi-calendar-event') ?>"></i>
          </span>
          <div class="list-row-main">
            <div class="list-row-title" style="white-space:normal"><?= sanitize($r['message']) ?></div>
            <div class="list-row-sub">
              <a href="/pages/lead_view.php?id=<?= $r['lead_id'] ?>"><?= sanitize($r['lead_name']) ?></a>
              · <?= ucfirst($r['stage']) ?>
              <?php if (can('leads.all') && $r['staff_name']): ?> · for <?= sanitize($r['staff_name']) ?><?php endif; ?>
            </div>
          </div>
          <div class="list-row-end">
            <span class="chip <?= $isOverdue ? 'aging-critical' : 'aging-due' ?>" title="<?= formatDate($r['remind_at']) ?>">
              <?= $isOverdue ? 'Overdue ' . str_replace(' ago', '', timeSince($r['remind_at'])) : (date('Y-m-d', $ts) === date('Y-m-d') ? 'Today ' . date('h:i A', $ts) : date('d M, h:i A', $ts)) ?>
            </span>
            <a href="?dismiss=<?= $r['id'] ?>" class="btn btn-ghost btn-sm" title="<?= t('Mark as done') ?>"><i class="bi bi-check2"></i><?= t('Done') ?></a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Needs attention -->
    <?php if ($agingLeads): ?>
    <div class="card-crm">
      <div class="card-header-crm">
        <span><i class="bi bi-exclamation-circle"></i> <?= t('Needs attention') ?></span>
        <a href="/pages/leads.php?aging=critical" class="card-link">See all <?= $agingTotal ?> <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="list-rows">
        <?php foreach ($agingLeads as $al): ?>
        <a class="list-row" href="/pages/lead_view.php?id=<?= $al['id'] ?>">
          <span class="avatar avatar-sm"><?= sanitize(initials($al['name'])) ?></span>
          <div class="list-row-main">
            <div class="list-row-title"><?= sanitize($al['name']) ?></div>
            <div class="list-row-sub">
              Last contact: <?= $al['last_contacted'] ? timeSince($al['last_contacted']) : 'never' ?>
              <?php if (can('leads.all')): ?> · <?= $al['staff_name'] ? sanitize($al['staff_name']) : 'Unassigned' ?><?php endif; ?>
            </div>
          </div>
          <div class="list-row-end"><?= stageBadge($al['stage']) ?> <?= agingBadge($al['aging_status']) ?></div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Recently completed -->
    <?php if (!empty($sent)): ?>
    <details class="card-crm">
      <summary class="card-header-crm" style="cursor:pointer;list-style:none;border-bottom:0">
        <span><i class="bi bi-check2-all"></i> <?= t('Recently completed') ?></span>
        <span class="card-header-sub"><?= t('Show') ?></span>
      </summary>
      <div class="list-rows" style="border-top:1px solid var(--border)">
        <?php foreach ($sent as $r): ?>
        <div class="list-row">
          <div class="list-row-main">
            <div class="list-row-title text-tx-2 fw-500"><?= sanitize($r['message']) ?></div>
            <div class="list-row-sub"><?= sanitize($r['lead_name']) ?></div>
          </div>
          <div class="list-row-end fs-12 text-tx-3"><?= formatDate($r['sent_at']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </details>
    <?php endif; ?>
  </div>

  <!-- Right column -->
  <div class="col-xl-4 d-flex flex-column gap-3">
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-bell-plus"></i> <?= t('New reminder') ?></span></div>
      <form method="POST" class="card-body-crm">
        <input type="hidden" name="add_reminder" value="1">
        <div class="mb-3">
          <label class="form-label" for="rLead"><?= t('Lead') ?></label>
          <input class="form-control mb-2" list="leadOptions" id="rLeadSearch" placeholder="<?= t('Type a name or phone…') ?>" autocomplete="off"
                 value="<?php foreach ($leads as $l) if ($l['id'] == $preLead) echo sanitize($l['name'] . ' — ' . $l['phone']); ?>">
          <datalist id="leadOptions">
            <?php foreach ($leads as $l): ?><option data-id="<?= $l['id'] ?>" value="<?= sanitize($l['name'] . ' — ' . $l['phone']) ?>"></option><?php endforeach; ?>
          </datalist>
          <input type="hidden" name="lead_id" id="rLead" value="<?= $preLead ?: '' ?>">
          <div class="form-text" id="rLeadHint"><?= t('Pick from the suggestions.') ?></div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="rMsg"><?= t('Message') ?></label>
          <textarea name="message" id="rMsg" class="form-control" rows="3" required placeholder="<?= t('e.g. Call to follow up on Botox inquiry') ?>"></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label" for="rAt"><?= t('Remind me') ?></label>
          <input type="datetime-local" name="remind_at" id="rAt" class="form-control" required min="<?= date('Y-m-d\TH:i') ?>">
        </div>
        <button type="submit" class="btn btn-gold w-100"><i class="bi bi-bell-plus"></i><?= t('Set reminder') ?></button>
      </form>
    </div>

    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-hourglass-split"></i> <?= t('Open leads by aging') ?></span></div>
      <div class="card-body-crm">
        <?php foreach (['fresh' => 'Recently contacted', 'due' => '2–3 days', 'overdue' => '3–7 days', 'critical' => '7+ days'] as $ag => $hint):
          $cnt = $agingSummary[$ag] ?? 0; ?>
        <a href="/pages/leads.php?aging=<?= $ag ?>" class="d-block mb-3 text-reset">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="d-flex align-items-center gap-2"><?= agingBadge($ag) ?><span class="fs-12 text-tx-3"><?= $hint ?></span></span>
            <strong class="tabular"><?= $cnt ?></strong>
          </div>
          <div class="score-bar" style="height:5px"><div class="score-bar-fill aging-bar-<?= $ag ?>" style="width:<?= round($cnt / $agingTotalOpen * 100) ?>%"></div></div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = "
const opts = document.getElementById('leadOptions'), search = document.getElementById('rLeadSearch'), hidden = document.getElementById('rLead');
search.addEventListener('input', () => {
  const m = [...opts.options].find(o => o.value === search.value);
  hidden.value = m ? m.dataset.id : '';
  document.getElementById('rLeadHint').textContent = m ? 'Selected.' : 'Pick from the suggestions.';
});
";
require_once __DIR__ . '/../includes/footer.php';
?>
