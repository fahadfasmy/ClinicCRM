<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scoring.php';
require_once __DIR__ . '/../includes/inventory.php';
require_once __DIR__ . '/../includes/reenquiry.php';
requireLogin();

$db  = getDB();
ensureInventorySchema($db);
ensureReEnquirySchema($db);
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /pages/leads.php'); exit; }

$stmt = $db->prepare("SELECT l.*, s.name as staff_name FROM leads l LEFT JOIN staff s ON s.id = l.assigned_to WHERE l.id = ?");
$stmt->execute([$id]);
$lead = $stmt->fetch();
if (!$lead) { http_response_code(404); die('Lead not found.'); }
if (!can('leads.all') && !empty($lead['assigned_to']) && (int)$lead['assigned_to'] !== currentStaffId()) {
    http_response_code(403); die('Access denied.');
}

// ── Phone numbers ──
$leadPhones = [];
try {
    $ps = $db->prepare("SELECT id, phone FROM lead_phones WHERE lead_id = ? ORDER BY id ASC");
    $ps->execute([$id]);
    $leadPhones = $ps->fetchAll();
} catch (PDOException $e) {}
if (empty($leadPhones)) {
    $leadPhones = [['id' => 0, 'phone' => $lead['phone']]];
}

$hasFuCols = array_key_exists('followup_1_done', $lead);

// ── Treatments ──
$treatments = [];
try {
    $ts = $db->prepare("SELECT t.name, t.avg_price FROM lead_treatments lt JOIN treatments t ON t.id = lt.treatment_id WHERE lt.lead_id = ? ORDER BY t.sort_order");
    $ts->execute([$id]);
    $treatments = $ts->fetchAll();
} catch (PDOException $e) {}

// ── Follow-ups ──
$followups = [];
try {
    $fs = $db->prepare("SELECT f.*, st.name as staff_name FROM followups f JOIN staff st ON st.id = f.staff_id WHERE f.lead_id = ? ORDER BY f.created_at DESC");
    $fs->execute([$id]);
    $followups = $fs->fetchAll();
} catch (PDOException $e) {}

$fuError = '';

// ── Handle POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_fu'])) {
    $channel  = $_POST['fu_channel']     ?? 'phone_call';
    $outcome  = $_POST['fu_outcome']     ?? 'interested';
    $remarks  = trim($_POST['fu_remarks'] ?? '');
    $nextAt   = trim($_POST['fu_next_at'] ?? '');
    $nextAct  = trim($_POST['fu_next_action'] ?? '');
    $newStage = $_POST['fu_stage']       ?? $lead['stage'];
    $fuNum    = (int)($_POST['fu_num']   ?? 0);

    $validC = ['whatsapp','phone_call','in_person','email','sms','other'];
    $validO = ['interested','needs_info','price_objection','no_answer','wrong_number','not_interested','booked','converted'];
    $validS = ['new','contacted','qualified','booked','converted','lost','cold'];

    if (!$remarks) {
        $fuError = 'Please add a remark describing the conversation.';
    } elseif (!in_array($channel,$validC) || !in_array($outcome,$validO) || !in_array($newStage,$validS)) {
        $fuError = 'Invalid values submitted.';
    } else {
        $nAV   = ($nextAt  !== '') ? $nextAt  : null;
        $nActV = ($nextAct !== '') ? $nextAct : null;
        try {
            $db->prepare("INSERT INTO followups (lead_id,staff_id,channel,outcome,remarks,next_followup_at,next_action) VALUES (?,?,?,?,?,?,?)")
               ->execute([$id, currentStaffId(), $channel, $outcome, $remarks, $nAV, $nActV]);
            $db->prepare("UPDATE leads SET last_contacted=NOW(), aging_status='fresh', stage=?, next_followup=?, updated_at=NOW() WHERE id=?")
               ->execute([$newStage, $nAV, $id]);
            if ($newStage === 'converted' && $lead['stage'] !== 'converted') {
                try { $db->prepare("UPDATE leads SET converted_at=NOW() WHERE id=? AND (converted_at IS NULL OR converted_at='0000-00-00 00:00:00')")->execute([$id]); } catch (PDOException $e) {}
            }
            if ($hasFuCols && $fuNum >= 1 && $fuNum <= 3) {
                $col = 'followup_'.$fuNum.'_done';
                $canTick = true;
                if ($fuNum===2 && empty($lead['followup_1_done'])) $canTick=false;
                if ($fuNum===3 && empty($lead['followup_2_done'])) $canTick=false;
                if ($canTick && empty($lead[$col])) {
                    $db->prepare("UPDATE leads SET followup_{$fuNum}_done=1, followup_{$fuNum}_at=NOW(), followup_count=followup_count+1 WHERE id=?")->execute([$id]);
                }
            }
            try { updateLeadScore($id, $db); } catch (Exception $e) {}
            try { logActivity(currentStaffId(),'followup_added',$id,$lead['stage'],$newStage); } catch (Exception $e) {}
            if (in_array($newStage, ['lost','cold'], true) && !empty($_POST['cancel_appointments']) && can('appointments.book')) {
                cancelOpenAppointmentsForLead($db, $id, 'Lead marked ' . $newStage);
            }
        } catch (PDOException $e) {
            $fuError = 'Error saving: ' . $e->getMessage();
        }
        if (!$fuError) {
            $url = '/pages/lead_view.php?id='.$id.'&saved=1';
            if (!headers_sent()) { header('Location: '.$url); } else { echo '<script>window.location.href="'.$url.'";</script>'; }
            exit;
        }
    }
}

$fuDone = [
    1 => $hasFuCols && !empty($lead['followup_1_done']),
    2 => $hasFuCols && !empty($lead['followup_2_done']),
    3 => $hasFuCols && !empty($lead['followup_3_done']),
];
$nextFuNum = 0;
foreach ($fuDone as $n => $d) { if (!$d) { $nextFuNum = $n; break; } }

$score     = (int)$lead['score'];
$breakdown = getScoreBreakdown($id, $db)['breakdown'] ?? [];
$primaryPhone = $leadPhones[0]['phone'] ?? $lead['phone'];
$waPrimary = preg_replace('/[^0-9]/', '', $primaryPhone);

$stageLabels = ['new'=>'New','contacted'=>'Contacted','qualified'=>'Qualified','booked'=>'Booked','converted'=>'Converted','lost'=>'Lost','cold'=>'Cold'];
$trackStages = ['new','contacted','qualified','booked','converted'];
$stageIdx    = array_search($lead['stage'], $trackStages, true);

$budgetLabels  = ['under_500'=>'Under AED 500','500_1000'=>'AED 500 – 1,000','1000_3000'=>'AED 1,000 – 3,000','3000_plus'=>'AED 3,000+','unknown'=>'Unknown'];
$urgencyLabels = ['immediate'=>'Immediate','within_month'=>'Within a month','3_months'=>'Within 3 months','just_browsing'=>'Just browsing','unknown'=>'Unknown'];

$appts = $db->prepare("SELECT a.*, s.name AS service_name, p.name AS practitioner_name FROM appointments a
                       JOIN services s ON s.id = a.service_id LEFT JOIN staff p ON p.id = a.practitioner_id
                       WHERE a.lead_id = ? ORDER BY a.status IN ('booked','confirmed','arrived','in_triage','with_doctor') DESC, a.scheduled_at DESC");
$appts->execute([$id]);
$appts = $appts->fetchAll();
$openAppts = count(array_filter($appts, fn($a) => in_array($a['status'], ['booked', 'confirmed', 'arrived', 'in_triage', 'with_doctor'], true)));

$pageTitle = $lead['name'];
$pageBack  = ['href' => '/pages/leads.php', 'label' => 'All leads'];
$pageSubtitle = stageBadge($lead['stage']) . ' &nbsp;' . agingBadge($lead['aging_status'] ?? 'fresh') . ' ' . returningChip($lead)
    . ' &nbsp;<span class="text-tx-3">Added ' . date('d M Y', strtotime($lead['created_at'])) . ' via ' . sourceBadge($lead['source']) . '</span>';
$pageActions = '<a href="tel:' . sanitize($primaryPhone) . '" class="btn btn-ghost"><i class="bi bi-telephone"></i>' . t('Call') . '</a>'
    . '<a href="https://wa.me/' . $waPrimary . '" target="_blank" rel="noopener" class="btn btn-whatsapp"><i class="bi bi-whatsapp"></i>' . t('WhatsApp') . '</a>'
    . '<a href="/pages/lead_edit.php?id=' . $id . '" class="btn btn-ghost"><i class="bi bi-pencil"></i>' . t('Edit') . '</a>'
    . (can('appointments.book') ? '<a href="/pages/appointment.php?lead=' . $id . '" class="btn btn-gold"><i class="bi bi-calendar-plus"></i>' . t('Book treatment') . '</a>' : '');
// The patient record made from this lead, or a button to make one
if (!empty($lead['patient_id']) && can('patients.view')) {
    $pageActions .= '<a href="/pages/patient.php?id=' . (int)$lead['patient_id'] . '" class="btn btn-ghost"><i class="bi bi-person-vcard"></i>' . t('Patient record') . '</a>';
} elseif (empty($lead['patient_id']) && can('patients.edit')) {
    $pageActions .= '<form method="POST" action="/pages/patient_edit.php" class="d-inline">' . csrfField() . '<input type="hidden" name="action" value="convert"><input type="hidden" name="lead_id" value="' . $id . '">'
        . '<button class="btn btn-ghost" data-confirm="Create a patient record from this lead and mark the lead Converted?"><i class="bi bi-person-check"></i>' . t('Convert to patient') . '</button></form>';
}
$leadFlash = $_SESSION['lead_flash'] ?? null;
unset($_SESSION['lead_flash']);
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($leadFlash): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i><?= sanitize($leadFlash) ?></div><?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success alert-auto"><i class="bi bi-check-circle-fill"></i><?= t('Follow-up saved.') ?></div>
<?php endif; ?>
<?php if (isset($_GET['created'])): ?>
<div class="alert alert-success alert-auto"><i class="bi bi-check-circle-fill"></i><?= t('Lead created.') ?></div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
<div class="alert alert-success alert-auto"><i class="bi bi-check-circle-fill"></i><?= t('Lead updated.') ?></div>
<?php endif; ?>
<?php if (isset($_GET['merged'])): ?>
<div class="alert alert-success alert-auto"><i class="bi bi-check-circle-fill"></i><?= t('This lead already existed, so the new details were added to it instead of creating a duplicate.') ?></div>
<?php endif; ?>
<?php if (isReEnquiryOpen($lead)): ?>
<div class="alert alert-warning"><i class="bi bi-arrow-repeat"></i><div><strong>Enquired again <?= timeSince($lead['reenquiry_at']) ?>:</strong> <?= sanitize($lead['reenquiry_note'] ?? '') ?>. Details are in the notes. This clears once a follow-up is logged.</div></div>
<?php endif; ?>
<?php if (isset($_GET['nodelete'])): ?>
<div class="alert alert-warning"><i class="bi bi-shield-lock"></i><div><?= t('This lead has treatment records, so it can’t be deleted. The products and batches used must stay traceable. Mark the lead Lost instead.') ?></div></div>
<?php endif; ?>
<?php if ($fuError): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill"></i><?= sanitize($fuError) ?></div>
<?php endif; ?>

<div class="lead-layout">

  <!-- ══ PROFILE ══ -->
  <aside class="card-crm profile-card">

    <!-- Stage progress -->
    <div class="pc-sec">
      <div class="pc-label"><?= t('Pipeline stage') ?></div>
      <?php if ($stageIdx === false): ?>
        <div class="d-flex align-items-center gap-2"><?= stageBadge($lead['stage']) ?>
          <?php if ($lead['stage'] === 'lost' && !empty($lead['lost_reason'])): ?><span class="fs-12 text-tx-3"><?= sanitize($lead['lost_reason']) ?></span><?php endif; ?>
        </div>
      <?php else: ?>
      <ol class="stage-track" aria-label="<?= t('Stage progress') ?>">
        <?php foreach ($trackStages as $i => $st): ?>
        <li class="<?= $i < $stageIdx ? 'done' : ($i === $stageIdx ? 'current' : '') ?>" title="<?= $stageLabels[$st] ?>">
          <span class="st-bar"></span><span class="st-name"><?= $stageLabels[$st] ?></span>
        </li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
    </div>

    <!-- Contact -->
    <div class="pc-sec">
      <div class="pc-label"><?= t('Contact') ?></div>
      <?php foreach ($leadPhones as $p): $waNum = preg_replace('/[^0-9]/', '', $p['phone']); ?>
      <div class="contact-row">
        <i class="bi bi-telephone"></i>
        <a href="tel:<?= sanitize($p['phone']) ?>" class="contact-value tabular"><?= sanitize($p['phone']) ?></a>
        <button type="button" class="btn-icon" title="<?= t('Copy number') ?>" onclick="copyText('<?= sanitize($p['phone']) ?>', this)"><i class="bi bi-copy"></i></button>
        <a href="https://wa.me/<?= $waNum ?>" target="_blank" rel="noopener" class="btn-icon success" title="<?= t('Open in WhatsApp') ?>"><i class="bi bi-whatsapp"></i></a>
      </div>
      <?php endforeach; ?>
      <?php if (!empty($lead['email'])): ?>
      <div class="contact-row">
        <i class="bi bi-envelope"></i>
        <a href="mailto:<?= sanitize($lead['email']) ?>" class="contact-value"><?= sanitize($lead['email']) ?></a>
      </div>
      <?php endif; ?>
    </div>

    <!-- Score -->
    <div class="pc-sec">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="pc-label mb-0"><?= t('Lead score') ?></div>
        <span class="<?= getScoreBadgeClass($score) ?>"><?= getScoreLabel($score) ?></span>
      </div>
      <div class="d-flex align-items-center gap-3">
        <div class="score-big tabular"><?= $score ?><span>/100</span></div>
        <div class="score-bar" style="height:6px"><div class="score-bar-fill" data-score="<?= $score ?>"></div></div>
      </div>
      <?php if ($breakdown): ?>
      <details class="score-details">
        <summary><?= t('Why this score?') ?></summary>
        <?php foreach ($breakdown as $b): ?>
        <div class="kv"><span class="kv-label"><?= sanitize($b['label']) ?></span><span class="kv-value tabular"><?= $b['points'] ?><span class="text-tx-3 fw-normal">/<?= $b['max'] ?></span></span></div>
        <?php endforeach; ?>
      </details>
      <?php endif; ?>
    </div>

    <!-- Follow-up tracker -->
    <?php if ($hasFuCols): ?>
    <div class="pc-sec">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="pc-label mb-0"><?= t('Follow-up tracker') ?></div>
        <span class="fs-12 <?= $nextFuNum ? 'text-tx-3' : 'text-green' ?>"><?= $nextFuNum ? (count(array_filter($fuDone)) . ' of 3 done') : '<i class="bi bi-check-all"></i> All done' ?></span>
      </div>
      <div class="fu-steps">
        <?php foreach ($fuDone as $n => $done):
          $at = $lead["followup_{$n}_at"] ?? null;
          $state = $done ? 'done' : ($n === $nextFuNum ? 'next' : 'locked'); ?>
        <button type="button" class="fu-step <?= $state ?>" <?= $state === 'next' ? 'onclick="focusLogForm(true)"' : 'disabled' ?>>
          <i class="bi <?= $done ? 'bi-check-circle-fill' : ($state === 'next' ? 'bi-circle' : 'bi-lock') ?>"></i>
          <span>FU <?= $n ?></span>
          <small><?= $done && $at ? date('d M', strtotime($at)) : ($state === 'next' ? 'Log now' : '—') ?></small>
        </button>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Details -->
    <div class="pc-sec">
      <div class="pc-label"><?= t('Details') ?></div>
      <div class="kv"><span class="kv-label"><?= t('Owner') ?></span><span class="kv-value"><?= !empty($lead['staff_name']) ? sanitize($lead['staff_name']) : '<span class="text-tx-3">' . t('Unassigned') . '</span>' ?></span></div>
      <div class="kv"><span class="kv-label"><?= t('Budget') ?></span><span class="kv-value"><?= $budgetLabels[$lead['budget_range'] ?? 'unknown'] ?? '—' ?></span></div>
      <div class="kv"><span class="kv-label"><?= t('Urgency') ?></span><span class="kv-value"><?= $urgencyLabels[$lead['urgency'] ?? 'unknown'] ?? '—' ?></span></div>
      <div class="kv"><span class="kv-label"><?= t('Last contact') ?></span><span class="kv-value"><?= timeSince($lead['last_contacted'] ?? null) ?></span></div>
      <div class="kv"><span class="kv-label"><?= t('Next follow-up') ?></span><span class="kv-value"><?= !empty($lead['next_followup']) ? formatDate($lead['next_followup']) : '—' ?></span></div>
      <?php if (!empty($lead['source_campaign'])): ?>
      <div class="kv"><span class="kv-label"><?= t('Campaign') ?></span><span class="kv-value"><?= sanitize($lead['source_campaign']) ?></span></div>
      <?php endif; ?>
      <?php if (!empty($lead['referrer_name'])): ?>
      <div class="kv"><span class="kv-label"><?= t('Referred by') ?></span><span class="kv-value"><?= sanitize($lead['referrer_name']) ?></span></div>
      <?php endif; ?>
    </div>

    <!-- Treatments -->
    <div class="pc-sec">
      <div class="pc-label"><?= t('Treatment interest') ?></div>
      <?php if ($treatments): ?>
      <div class="d-flex flex-wrap gap-1">
        <?php foreach ($treatments as $t): ?><span class="chip tone-gold"><?= sanitize($t['name']) ?></span><?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="fs-13 text-tx-3"><?= t('None recorded.') ?> <a href="/pages/lead_edit.php?id=<?= $id ?>"><?= t('Add') ?></a></div>
      <?php endif; ?>
    </div>

    <!-- Notes -->
    <?php if (!empty($lead['notes'])): ?>
    <div class="pc-sec">
      <div class="pc-label"><?= t('Notes') ?></div>
      <p class="fs-13 text-tx-2 mb-0" style="line-height:1.6"><?= nl2br(sanitize($lead['notes'])) ?></p>
    </div>
    <?php endif; ?>
  </aside>

  <!-- ══ ACTIVITY ══ -->
  <div class="d-flex flex-column gap-3 min-w-0">

    <!-- Treatments -->
    <?php if ($appts || can('appointments.book')): ?>
    <div class="card-crm" id="treatmentsCard">
      <div class="card-header-crm">
        <span><i class="bi bi-calendar-check"></i> <?= t('Treatments') ?></span>
        <?php if (can('appointments.book')): ?><a href="/pages/appointment.php?lead=<?= $id ?>" class="btn btn-ghost btn-sm"><i class="bi bi-plus-lg"></i><?= t('Book') ?></a><?php endif; ?>
      </div>
      <div class="list-rows">
        <?php foreach ($appts as $ap): $late = in_array($ap['status'], ['booked', 'confirmed', 'arrived', 'in_triage', 'with_doctor'], true) && strtotime($ap['scheduled_at']) < time() - 3600; ?>
        <a class="list-row" href="/pages/appointment.php?id=<?= $ap['id'] ?>&amp;return=<?= urlencode('/pages/lead_view.php?id=' . $id) ?>">
          <div class="list-row-main">
            <div class="list-row-title"><?= sanitize($ap['service_name']) ?></div>
            <div class="list-row-sub"><?= date('D d M Y, H:i', strtotime($ap['scheduled_at'])) ?><?= $ap['practitioner_name'] ? ' · ' . sanitize($ap['practitioner_name']) : '' ?></div>
          </div>
          <div class="list-row-end"><?php if ($late): ?><span class="chip aging-overdue"><?= t('Needs completing') ?></span><?php endif; ?><?= apptBadge($ap['status']) ?></div>
        </a>
        <?php endforeach; ?>
        <?php if (!$appts): ?><div class="empty-state py-4"><i class="bi bi-calendar-plus"></i><?= t('No treatments booked yet.') ?></div><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Log follow-up -->
    <div class="card-crm" id="logCard">
      <div class="card-header-crm">
        <span><i class="bi bi-chat-left-text"></i> <?= t('Log a follow-up') ?></span>
        <span class="card-header-sub"><?= count($followups) ?> logged so far</span>
      </div>
      <form method="POST" id="logForm" class="card-body-crm">
        <input type="hidden" name="save_fu" value="1">

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label" for="fuChannel"><?= t('Channel') ?></label>
            <select name="fu_channel" id="fuChannel" class="form-select">
              <option value="whatsapp"><?= t('WhatsApp') ?></option>
              <option value="phone_call" selected><?= t('Phone call') ?></option>
              <option value="in_person"><?= t('In person') ?></option>
              <option value="email"><?= t('Email') ?></option>
              <option value="sms">SMS</option>
              <option value="other"><?= t('Other') ?></option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="fuOutcome"><?= t('Outcome') ?></label>
            <select name="fu_outcome" id="fuOutcome" class="form-select">
              <option value="interested" selected><?= t('Interested') ?></option>
              <option value="needs_info"><?= t('Needs info') ?></option>
              <option value="price_objection"><?= t('Price objection') ?></option>
              <option value="no_answer"><?= t('No answer') ?></option>
              <option value="wrong_number"><?= t('Wrong number') ?></option>
              <option value="not_interested"><?= t('Not interested') ?></option>
              <option value="booked"><?= t('Booked') ?></option>
              <option value="converted"><?= t('Converted') ?></option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="fuStage"><?= t('Move to stage') ?></label>
            <select name="fu_stage" id="fuStage" class="form-select">
              <?php foreach ($stageLabels as $st => $lbl): ?>
              <option value="<?= $st ?>" <?= $lead['stage'] === $st ? 'selected' : '' ?>><?= $lbl ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($openAppts && can('appointments.book')): ?>
            <div class="form-check mt-2" id="fuCancelWrap" hidden>
              <input class="form-check-input" type="checkbox" name="cancel_appointments" id="fuCancelAppts" value="1" checked>
              <label class="form-check-label fs-12" for="fuCancelAppts">Cancel <?= $openAppts ?> booked treatment<?= $openAppts > 1 ? 's' : '' ?> and release the stock</label>
            </div>
            <script>
              (() => { const s = document.getElementById('fuStage'), w = document.getElementById('fuCancelWrap');
                const sync = () => { w.hidden = !['lost', 'cold'].includes(s.value); }; s.addEventListener('change', sync); sync(); })();
            </script>
            <?php endif; ?>
          </div>
          <div class="col-12">
            <label class="form-label" for="fuRemarks"><?= t('What happened?') ?> <span class="req">*</span></label>
            <textarea name="fu_remarks" id="fuRemarks" class="form-control" rows="3" required
                      placeholder="<?= t('Summary of the conversation, questions asked, objections…') ?>"></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="fuNextAt"><?= t('Next follow-up') ?></label>
            <input type="datetime-local" name="fu_next_at" id="fuNextAt" class="form-control" min="<?= date('Y-m-d\TH:i') ?>">
            <div class="d-flex gap-1 mt-2 flex-wrap">
              <button type="button" class="btn btn-subtle btn-sm" onclick="setNext(0,17)"><?= t('Today 5pm') ?></button>
              <button type="button" class="btn btn-subtle btn-sm" onclick="setNext(1,11)"><?= t('Tomorrow') ?></button>
              <button type="button" class="btn btn-subtle btn-sm" onclick="setNext(3,11)"><?= t('In 3 days') ?></button>
              <button type="button" class="btn btn-subtle btn-sm" onclick="setNext(7,11)"><?= t('Next week') ?></button>
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="fuNextAction"><?= t('Next action') ?></label>
            <input type="text" name="fu_next_action" id="fuNextAction" class="form-control" placeholder="<?= t('e.g. Send price list') ?>">
          </div>
        </div>

        <div class="d-flex align-items-center justify-content-between gap-3 mt-3 pt-3 border-top flex-wrap">
          <?php if ($hasFuCols && $nextFuNum): ?>
          <label class="form-check mb-0">
            <input class="form-check-input" type="checkbox" name="fu_num" value="<?= $nextFuNum ?>" id="fuCountCheck">
            <span class="form-check-label"><?= t('Count this as') ?> <strong>Follow-up <?= $nextFuNum ?></strong> <?= t('of 3') ?></span>
          </label>
          <?php else: ?><span></span><?php endif; ?>
          <button type="submit" class="btn btn-gold"><i class="bi bi-check-lg"></i><?= t('Save follow-up') ?></button>
        </div>
      </form>
    </div>

    <!-- Timeline -->
    <div class="card-crm">
      <div class="card-header-crm">
        <span><i class="bi bi-clock-history"></i> <?= t('Activity') ?></span>
      </div>
      <?php if (empty($followups)): ?>
      <div class="empty-state">
        <i class="bi bi-chat-left-text"></i>
        <div class="empty-state-title"><?= t('No follow-ups yet') ?></div>
        <p><?= t('Log the first conversation above.') ?></p>
      </div>
      <?php else: ?>
      <div class="card-body-crm">
        <div class="timeline">
          <?php foreach ($followups as $fu): ?>
          <div class="timeline-item">
            <span class="timeline-dot"></span>
            <div class="timeline-card">
              <div class="d-flex justify-content-between align-items-start gap-2 mb-2 flex-wrap">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                  <?= outcomeLabel($fu['outcome']) ?>
                  <span class="fs-12 text-tx-2"><?= channelLabel($fu['channel']) ?></span>
                </div>
                <div class="timeline-meta text-end">
                  <span title="<?= formatDate($fu['created_at']) ?>"><?= date('d M Y, h:i A', strtotime($fu['created_at'])) ?></span> · <?= sanitize($fu['staff_name']) ?>
                </div>
              </div>
              <p class="mb-0 fs-13" style="line-height:1.55"><?= nl2br(sanitize($fu['remarks'])) ?></p>
              <?php if (!empty($fu['next_followup_at'])): ?>
              <div class="timeline-next">
                <i class="bi bi-calendar-event"></i>
                Next: <?= date('d M Y, h:i A', strtotime($fu['next_followup_at'])) ?>
                <?php if (!empty($fu['next_action'])): ?><span class="text-tx-3">— <?= sanitize($fu['next_action']) ?></span><?php endif; ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php
$extraJs = "
// Suggest a stage when the outcome implies one
const outcomeStage = { booked: 'booked', converted: 'converted', not_interested: 'lost', wrong_number: 'lost', interested: null };
document.getElementById('fuOutcome')?.addEventListener('change', e => {
  const st = outcomeStage[e.target.value];
  if (!st) return;
  const stage = document.getElementById('fuStage');
  stage.value = st;
  stage.dispatchEvent(new Event('change'));   // shows the cancel-appointments option for Lost
});

function setNext(days, hour) {
  const d = new Date(); d.setDate(d.getDate() + days); d.setHours(hour, 0, 0, 0);
  const pad = n => String(n).padStart(2, '0');
  document.getElementById('fuNextAt').value = d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) + 'T' + pad(hour) + ':00';
}

function focusLogForm(countIt) {
  const card = document.getElementById('logCard');
  card.scrollIntoView({ behavior: 'smooth', block: 'start' });
  const cb = document.getElementById('fuCountCheck');
  if (countIt && cb) cb.checked = true;
  setTimeout(() => document.getElementById('fuRemarks').focus(), 300);
}

function copyText(text, btn) {
  navigator.clipboard?.writeText(text).then(() => {
    const i = btn.querySelector('i'); i.className = 'bi bi-check2';
    setTimeout(() => i.className = 'bi bi-copy', 1200);
  });
}
";
require_once __DIR__ . '/../includes/footer.php';
?>
