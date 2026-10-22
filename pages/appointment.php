<?php
// pages/appointment.php — book a treatment (?lead=ID), or view / reschedule / cancel / complete one (?id=ID).
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';
require_once __DIR__ . '/../includes/scheduler.php';
require_once __DIR__ . '/../includes/consents.php';
require_once __DIR__ . '/../includes/billing.php';
require_once __DIR__ . '/../includes/insurance.php';
requirePermission('appointments.view');

$db = getDB();
ensureInventorySchema($db);
$error = '';
$apptId = (int)($_GET['id'] ?? 0);
$leadId = (int)($_GET['lead'] ?? 0);
$return = preg_match('#^/pages/[a-z_]+\.php(\?[\w=&%.-]*)?$#', $_GET['return'] ?? '') ? $_GET['return'] : '';

// ─────────── BOOK ───────────
if (!$apptId) {
    requirePermission('appointments.book');
    $s = $db->prepare("SELECT * FROM leads WHERE id = ?");
    $s->execute([$leadId]);
    $lead = $s->fetch();
    if (!$lead) { http_response_code(404); die('Lead not found.'); }
    if (!canAccessLead($lead)) { http_response_code(403); die('Access denied.'); }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
        try {
            [$newId, $warnings] = bookAppointment($db, $leadId, (int)($_POST['service_id'] ?? 0),
                trim(($_POST['date'] ?? '') . ' ' . ($_POST['time'] ?? '')), (int)($_POST['practitioner_id'] ?? 0) ?: null, $_POST['notes'] ?? '');
            $_SESSION['appt_flash'] = ['booked', $warnings];
            header('Location: /pages/appointment.php?id=' . $newId . ($return ? '&return=' . urlencode($return) : '')); exit;
        } catch (StockException $e) {
            $error = $e->getMessage();
        }
    }

    // Treatments matching the lead's interests come first
    $s = $db->prepare("SELECT DISTINCT st.service_id FROM lead_treatments lt JOIN service_treatments st ON st.treatment_id = lt.treatment_id WHERE lt.lead_id = ?");
    $s->execute([$leadId]);
    $suggested = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    $services = $db->query("SELECT * FROM services WHERE is_active = 1 ORDER BY category, sort_order, name")->fetchAll();
    // Recipe + current availability per service, for the live stock panel
    $stock = array_column(stockSummary($db, false), null, 'id');
    $recipes = [];
    foreach ($db->query("SELECT si.*, p.name, p.usage_unit FROM service_items si JOIN products p ON p.id = si.product_id ORDER BY p.name")->fetchAll() as $r) {
        $av = $stock[(int)$r['product_id']]['available'] ?? 0;
        $recipes[(int)$r['service_id']][] = ['name' => $r['name'],
            'uses' => fmtQty($r['qty']) . ' ' . unitWord($r['usage_unit'], $r['qty']),
            'available' => fmtQty($av) . ' ' . unitWord($r['usage_unit'], $av), 'short' => $av < (float)$r['qty']];
    }
    $svcInfo = [];
    foreach ($services as $sv) $svcInfo[(int)$sv['id']] = ['price' => (float)$sv['price'], 'mins' => (int)$sv['duration_min'], 'items' => $recipes[(int)$sv['id']] ?? []];
    $practitioners = practitionerOptions($db);
    $sel = (int)($_POST['service_id'] ?? ($suggested[0] ?? 0));

    $pageTitle = 'Book treatment';
    $pageBack  = ['href' => $return ?: '/pages/lead_view.php?id=' . $leadId, 'label' => $lead['name']];
    $pageSubtitle = sanitize($lead['name']) . ' · ' . sanitize($lead['phone']) . ' &nbsp;' . stageBadge($lead['stage']);
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><?= sanitize($error) ?></div><?php endif; ?>
    <?php if (!$services): ?>
      <div class="card-crm"><div class="empty-state"><i class="bi bi-clipboard2-pulse"></i><?= t('No bookable treatments yet. The inventory team adds them under') ?> <a href="/pages/services.php"><?= t('Treatment menu') ?></a>.</div></div>
    <?php else: ?>
    <div class="row g-3" style="max-width:1100px">
      <div class="col-lg-7">
        <form method="POST" class="card-crm">
          <?= csrfField() ?>
          <div class="card-body-crm">
            <div class="mb-3"><label class="form-label" for="bSvc"><?= t('Treatment') ?> <span class="req">*</span></label>
              <select class="form-select" id="bSvc" name="service_id" required>
                <option value=""><?= t('Choose…') ?></option>
                <?php if ($suggested): ?><optgroup label="Matches this lead’s interests">
                  <?php foreach ($services as $sv): if (!in_array((int)$sv['id'], $suggested, true)) continue; ?><option value="<?= $sv['id'] ?>" <?= $sel === (int)$sv['id'] ? 'selected' : '' ?>><?= sanitize($sv['name']) ?></option><?php endforeach; ?>
                </optgroup><?php endif; ?>
                <?php $cat = null; foreach ($services as $sv): if (in_array((int)$sv['id'], $suggested, true)) continue;
                  if ($sv['category'] !== $cat): if ($cat !== null) echo '</optgroup>'; $cat = $sv['category']; ?><optgroup label="<?= sanitize($cat ?: 'Other') ?>"><?php endif; ?>
                  <option value="<?= $sv['id'] ?>" <?= $sel === (int)$sv['id'] ? 'selected' : '' ?>><?= sanitize($sv['name']) ?></option>
                <?php endforeach; if ($cat !== null) echo '</optgroup>'; ?>
              </select></div>
            <div class="row g-3">
              <div class="col-sm-6"><label class="form-label" for="bDate"><?= t('Date') ?> <span class="req">*</span></label><input type="date" class="form-control" id="bDate" name="date" required min="<?= date('Y-m-d') ?>" value="<?= sanitize($_POST['date'] ?? date('Y-m-d', strtotime('+1 day'))) ?>"></div>
              <div class="col-sm-6"><label class="form-label" for="bTime"><?= t('Time') ?> <span class="req">*</span></label><input type="time" class="form-control" id="bTime" name="time" required step="900" value="<?= sanitize($_POST['time'] ?? '11:00') ?>"></div>
              <div class="col-12"><label class="form-label" for="bPr"><?= t('Practitioner') ?></label>
                <select class="form-select" id="bPr" name="practitioner_id"><option value=""><?= t('Not decided yet') ?></option>
                  <?php foreach ($practitioners as $pr): ?><option value="<?= $pr['id'] ?>" <?= (int)($_POST['practitioner_id'] ?? 0) === (int)$pr['id'] ? 'selected' : '' ?>><?= sanitize($pr['name']) ?></option><?php endforeach; ?>
                </select></div>
              <div class="col-12"><label class="form-label" for="bNotes"><?= t('Notes') ?></label><textarea class="form-control" id="bNotes" name="notes" rows="2" placeholder="<?= t('Areas, allergies, anything the practitioner should know') ?>"><?= sanitize($_POST['notes'] ?? '') ?></textarea></div>
            </div>
          </div>
          <div class="form-actions">
            <a href="<?= sanitize($pageBack['href']) ?>" class="btn btn-ghost"><?= t('Cancel') ?></a>
            <button type="submit" class="btn btn-gold"><i class="bi bi-calendar-check"></i><?= t('Book and reserve stock') ?></button>
          </div>
        </form>
      </div>
      <div class="col-lg-5">
        <div class="card-crm form-side">
          <div class="card-header-crm"><span><i class="bi bi-box-seam"></i> <?= t('Stock for this treatment') ?></span><span class="card-header-sub" id="bMeta"></span></div>
          <div id="bStock" class="list-rows"></div>
          <p class="fs-12 text-tx-3 px-3 pb-3 mb-0">Booking reserves these amounts. Stock never blocks a booking; any shortfall is flagged so it can be ordered in time.</p>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?php
    $extraJs = "
    const SVC = " . json_encode($svcInfo, JSON_HEX_TAG | JSON_HEX_AMP) . ";
    const esc = s => String(s).replace(/[&<>\"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',\"'\":'&#39;'}[c]));
    function showStock() {
      const box = document.getElementById('bStock'), info = SVC[document.getElementById('bSvc')?.value];
      if (!box) return;
      if (!info) { box.innerHTML = '<div class=\"empty-state py-4\">Choose a treatment to see its products.</div>'; document.getElementById('bMeta').textContent = ''; return; }
      document.getElementById('bMeta').textContent = (info.price ? 'AED ' + info.price.toLocaleString() + ' · ' : '') + info.mins + ' min';
      box.innerHTML = info.items.length ? info.items.map(i => '<div class=\"list-row\"><div class=\"list-row-main\"><div class=\"list-row-title\">' + esc(i.name) + '</div><div class=\"list-row-sub\">Uses ' + esc(i.uses) + ' · ' + esc(i.available) + ' available now</div></div><div class=\"list-row-end\"><span class=\"chip ' + (i.short ? 'aging-critical\">Short' : 'aging-fresh\">In stock') + '</span></div></div>').join('')
        : '<div class=\"empty-state py-4\">This treatment has no products in its recipe.</div>';
    }
    document.getElementById('bSvc')?.addEventListener('change', showStock);
    showStock();";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// ─────────── VIEW / ACTIONS ───────────
$a = getAppointment($db, $apptId);
if (!$a) { http_response_code(404); die('Appointment not found.'); }
if (!canAccessLead($a)) { http_response_code(403); die('Access denied.'); }
$items = appointmentItems($db, $apptId);
$flash = $_SESSION['appt_flash'] ?? null;
unset($_SESSION['appt_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    try {
        if (isset(APPT_STEPS[$action])) {
            setAppointmentStep($db, $apptId, $action);
            $_SESSION['appt_flash'] = ['step', []];
        } elseif ($action === 'reschedule') {
            requirePermission('appointments.book');
            $w = rescheduleAppointment($db, $apptId, trim(($_POST['date'] ?? '') . ' ' . ($_POST['time'] ?? '')), (int)($_POST['practitioner_id'] ?? 0) ?: null);
            $_SESSION['appt_flash'] = ['rescheduled', $w];
        } elseif ($action === 'cancel' || $action === 'no_show') {
            requirePermission('appointments.book');
            cancelAppointment($db, $apptId, $action === 'cancel' ? 'cancelled' : 'no_show', $_POST['reason'] ?? '');
            $_SESSION['appt_flash'] = [$action === 'cancel' ? 'cancelled' : 'no_show', []];
        } elseif ($action === 'complete') {
            requirePermission('appointments.complete');
            $used = [];
            foreach ($items as $it) {
                $used[$it['id']] = ['qty' => $_POST['qty'][$it['id']] ?? '', 'lot_id' => (int)($_POST['lot'][$it['id']] ?? 0) ?: null, 'reason' => $_POST['reason'][$it['id']] ?? ''];
            }
            completeAppointment($db, $apptId, $used, $_POST['notes'] ?? '');
            $_SESSION['appt_flash'] = ['completed', []];
        } elseif ($action === 'eligibility') {
            requirePermission('appointments.book');
            $db->prepare("UPDATE appointments SET eligibility_ref = ? WHERE id = ?")->execute([mb_substr(trim((string)($_POST['eligibility_ref'] ?? '')), 0, 60) ?: null, $apptId]);
            $_SESSION['appt_flash'] = ['step', []];
        } elseif ($action === 'package') {
            requirePermission('appointments.book');
            setAppointmentPackage($db, $apptId, (int)($_POST['patient_package_id'] ?? 0) ?: null);
            $_SESSION['appt_flash'] = ['step', []];
        }
        header('Location: /pages/appointment.php?id=' . $apptId . ($return ? '&return=' . urlencode($return) : '')); exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$isOpen   = in_array($a['status'], APPT_OPEN, true);
$notStarted = in_array($a['status'], ['booked', 'confirmed'], true);
$services = appointmentServices($db, $apptId);
$history = $db->prepare("SELECT h.*, s.name AS staff_name FROM appointment_status_history h LEFT JOIN staff s ON s.id = h.staff_id WHERE h.appointment_id = ? ORDER BY h.at, h.id");
$history->execute([$apptId]);
$history = $history->fetchAll();
$clientHref = $a['patient_id'] ? '/pages/patient.php?id=' . (int)$a['patient_id'] : '/pages/lead_view.php?id=' . (int)$a['lead_id'];
$overdue  = $isOpen && strtotime($a['scheduled_at']) < time() - 3600;
$lotsUsed = $a['status'] === 'completed' ? appointmentLotsUsed($db, $apptId) : [];
$lotOptions = [];
$openVials = [];
if ($isOpen && can('appointments.complete')) foreach ($items as $it) {
    if (isMultiDose($it)) {
        expireOpenContainers($db, (int)$it['product_id']);
        $openVials[(int)$it['product_id']] = openContainers($db, (int)$it['product_id']);
    }
    $lotOptions[(int)$it['product_id']] = usableLots($db, (int)$it['product_id']);
}
// Batches that must not be used, so a scanned pack from one of them is refused with the reason
$blockedLots = [];
if ($isOpen && can('appointments.complete') && $items) {
    $ids = implode(',', array_map(fn($it) => (int)$it['product_id'], $items));
    foreach ($db->query("SELECT product_id, lot_number, status, expiry_date, qty_on_hand FROM stock_lots WHERE product_id IN ($ids) AND lot_number IS NOT NULL
                         AND (status <> 'available' OR expiry_date < CURDATE() OR qty_on_hand <= 0)")->fetchAll() as $l) {
        $why = $l['status'] === 'recalled' ? 'RECALLED' : ($l['status'] === 'quarantined' ? 'on hold' : ($l['expiry_date'] && $l['expiry_date'] < date('Y-m-d') ? 'expired' : 'used up in the records'));
        $blockedLots[(int)$l['product_id']][strtolower(preg_replace('/[\s\-\/.]+/', '', $l['lot_number']))] = $why;
    }
}
$practitioners = practitionerOptions($db);
$totalCost = 0.0;

$pageTitle    = $services ? implode(' + ', $services) : $a['service_name'];
$pageBack     = ['href' => $return ?: '/pages/appointments.php', 'label' => $return ? 'Back' : 'Appointments'];
$pageSubtitle = apptBadge($a['status']) . ($overdue ? ' <span class="chip aging-overdue">' . t('Needs completing') . '</span>' : '')
              . ' &nbsp;<a href="' . $clientHref . '">' . sanitize($a['lead_name']) . '</a> · ' . date('D d M Y, H:i', strtotime($a['scheduled_at']));
require_once __DIR__ . '/../includes/header.php';
$flashText = ['step' => 'Visit updated.', 'booked' => 'Treatment booked and stock reserved.', 'rescheduled' => 'Appointment moved. The stock stays reserved.',
              'cancelled' => 'Appointment cancelled. Reserved stock was released.', 'no_show' => 'Marked as no-show. Reserved stock was released.',
              'completed' => 'Treatment completed. Stock was deducted from the batches shown below.'];
?>
<?php if ($flash): ?>
  <div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i><?= $flashText[$flash[0]] ?? 'Saved.' ?></div>
  <?php if ($flash[1]): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i><div><strong><?= t('Not enough stock for this date.') ?></strong> The booking is saved; order more in time:<ul class="mb-0 mt-1"><?php foreach ($flash[1] as $w): ?><li><?= sanitize($w) ?></li><?php endforeach; ?></ul></div></div><?php endif; ?>
<?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><?= sanitize($error) ?></div><?php endif; ?>
<?php if ($a['patient_id'] && !in_array($a['status'], ['cancelled', 'no_show', 'rescheduled'], true)):
  foreach (missingConsents($db, (int)$a['patient_id'], visitServiceIds($db, $apptId, null)) as $tplId => [$cName, $cFor]): ?>
<div class="alert alert-warning py-2 d-flex flex-wrap align-items-center gap-2" role="alert"><i class="bi bi-pen"></i><div class="flex-grow-1"><strong><?= t('Consent not signed:') ?></strong> <?= sanitize($cName) ?> <span class="fs-12">(required for <?= sanitize($cFor) ?>)</span></div>
  <?php if (can('consents.collect')): ?><form method="POST" action="/pages/consent_sign.php"><?= csrfField() ?><input type="hidden" name="action" value="start"><input type="hidden" name="patient_id" value="<?= (int)$a['patient_id'] ?>"><input type="hidden" name="appointment_id" value="<?= $apptId ?>"><input type="hidden" name="template_id" value="<?= $tplId ?>">
    <button class="btn btn-ghost btn-sm"><i class="bi bi-tablet"></i><?= t('Hand to patient') ?></button></form><?php endif; ?></div>
<?php endforeach; endif; ?>

<div class="lead-layout">
  <aside class="card-crm profile-card">
    <div class="card-header-crm"><span><i class="bi bi-calendar-event"></i> <?= t('Appointment') ?></span></div>
    <div class="card-body-crm pt-2">
      <div class="kv"><span class="kv-label"><?= t('Client') ?></span><span class="kv-value"><a href="<?= $clientHref ?>"><?= sanitize($a['lead_name']) ?></a><?= $a['mrn'] ? '<span class="cell-sub">' . sanitize($a['mrn']) . '</span>' : '' ?></span></div>
      <div class="kv"><span class="kv-label"><?= t('Phone') ?></span><span class="kv-value tabular"><?= sanitize((string)$a['lead_phone']) ?></span></div>
      <div class="kv"><span class="kv-label"><?= t('Where') ?></span><span class="kv-value"><?= sanitize(($a['branch_name'] ?? '') . ($a['room_name'] ? ' · ' . $a['room_name'] : '')) ?: '–' ?></span></div>
      <div class="kv"><span class="kv-label"><?= t('Visit') ?></span><span class="kv-value"><?= APPT_VISIT_TYPES[$a['visit_type']] ?? '' ?></span></div>
      <div class="kv"><span class="kv-label"><?= t('When') ?></span><span class="kv-value"><?= date('D d M Y, H:i', strtotime($a['scheduled_at'])) ?></span></div>
      <div class="kv"><span class="kv-label"><?= t('Duration') ?></span><span class="kv-value"><?= (int)$a['duration_min'] ?> min</span></div>
      <div class="kv"><span class="kv-label"><?= t('Practitioner') ?></span><span class="kv-value"><?= sanitize($a['practitioner_name'] ?? 'Not set') ?></span></div>
      <div class="kv"><span class="kv-label"><?= t('Price') ?></span><span class="kv-value"><?= (float)$a['visit_price'] ? 'AED ' . number_format($a['visit_price']) : '–' ?></span></div>
      <div class="kv"><span class="kv-label"><?= t('Booked by') ?></span><span class="kv-value"><?= sanitize($a['booked_by_name'] ?? '–') ?><span class="cell-sub"><?= date('d M Y', strtotime($a['created_at'])) ?></span></span></div>
      <?php if ($a['status'] === 'completed'): ?><div class="kv"><span class="kv-label"><?= t('Completed by') ?></span><span class="kv-value"><?= sanitize($a['completed_by_name'] ?? '–') ?><span class="cell-sub"><?= date('d M Y, H:i', strtotime($a['completed_at'])) ?></span></span></div><?php endif; ?>
      <?php if (in_array($a['status'], ['cancelled', 'no_show'], true) && $a['cancel_reason']): ?><div class="kv"><span class="kv-label"><?= t('Reason') ?></span><span class="kv-value"><?= sanitize($a['cancel_reason']) ?></span></div><?php endif; ?>
      <?php if ($a['notes']): ?><p class="fs-12 text-tx-2 mt-3 mb-0"><?= nl2br(sanitize($a['notes'])) ?></p><?php endif; ?>

      <?php $next = array_filter(APPT_STEPS, fn($st) => in_array($a['status'], $st[0], true) && can($st[2])); if ($next): ?>
      <div class="section-title mt-4"><?= t('Next step') ?></div>
      <form method="POST" class="d-flex flex-wrap gap-2"><?= csrfField() ?>
        <?php foreach ($next as $to => $st): ?><button class="btn btn-ghost btn-sm" name="action" value="<?= $to ?>"><?= APPT_STATUS[$to][0] ?></button><?php endforeach; ?>
      </form>
      <?php endif; ?>
      <?php if (can('emr.view') && $a['patient_id']):
        $encId = $db->prepare("SELECT id FROM encounters WHERE appointment_id = ?"); $encId->execute([$apptId]); $encId = (int)$encId->fetchColumn(); ?>
      <div class="mt-3"><?php if ($encId): ?><a class="btn btn-ghost btn-sm" href="/pages/encounter.php?id=<?= $encId ?>"><i class="bi bi-clipboard2-pulse"></i><?= t('Clinical note') ?></a>
        <?php elseif (can('emr.write') && !in_array($a['status'], ['cancelled', 'no_show'], true)): ?><form method="POST" action="/pages/encounter.php" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="open"><input type="hidden" name="appointment_id" value="<?= $apptId ?>"><input type="hidden" name="patient_id" value="<?= (int)$a['patient_id'] ?>">
          <button class="btn btn-ghost btn-sm"><i class="bi bi-clipboard2-plus"></i><?= t('Start clinical note') ?></button></form><?php endif; ?></div>
      <?php endif; ?>
      <?php if ($a['patient_id'] && can('billing.view')):
        $vInv = $db->prepare("SELECT id, number, status FROM invoices WHERE appointment_id = ? AND status <> 'void' ORDER BY id LIMIT 1"); $vInv->execute([$apptId]); $vInv = $vInv->fetch();
        $myPk = $isOpen ? patientPackages($db, (int)$a['patient_id'], true) : []; ?>
      <div class="mt-2"><?php if ($vInv): ?><a class="btn btn-ghost btn-sm" href="/pages/invoice.php?id=<?= (int)$vInv['id'] ?>"><i class="bi bi-receipt"></i><?= $vInv['number'] ? sanitize($vInv['number']) : 'Draft invoice' ?></a>
        <?php elseif (can('billing.invoice') && !in_array($a['status'], ['cancelled', 'no_show', 'rescheduled'], true)): ?><form method="POST" action="/pages/invoice.php" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="new"><input type="hidden" name="appointment_id" value="<?= $apptId ?>">
          <button class="btn btn-ghost btn-sm"><i class="bi bi-receipt"></i><?= t('Invoice this visit') ?></button></form><?php endif; ?></div>
      <?php if ($myPk && can('appointments.book')): ?>
      <form method="POST" class="mt-2 d-flex gap-1"><?= csrfField() ?><input type="hidden" name="action" value="package">
        <label class="visually-hidden" for="apPk"><?= t('Use a package session') ?></label><select class="form-select form-select-sm" id="apPk" name="patient_package_id"><option value=""><?= t('No package') ?></option>
          <?php foreach ($myPk as $mp): ?><option value="<?= (int)$mp['id'] ?>" <?= (int)$a['patient_package_id'] === (int)$mp['id'] ? 'selected' : '' ?>><?= sanitize($mp['name']) ?> (<?= sanitize((string)$mp['sessions']) ?>)</option><?php endforeach; ?></select>
        <button class="btn btn-ghost btn-sm"><?= t('Use') ?></button></form>
      <?php elseif ($a['patient_package_id']): ?><div class="fs-12 text-tx-3 mt-1"><i class="bi bi-box2-heart"></i> <?= t('Booked on a package') ?></div><?php endif; ?>
      <?php endif; ?>
      <?php if ($a['patient_id'] && ($cards = patientCards($db, (int)$a['patient_id'], true))): $card = $cards[0];
        $need = servicesNeedingApproval($db, (int)$a['patient_id'], (int)$card['plan_id'], array_keys(appointmentServices($db, $apptId)) ?: [(int)$a['service_id']]); ?>
      <div class="section-title mt-4"><?= t('Insurance') ?></div>
      <div class="fs-13"><?= sanitize($card['company'] . ' – ' . $card['plan_name']) ?> · <?= sanitize($card['member_no']) ?></div>
      <?php if ($need && $isOpen): ?><div class="alert alert-warning py-1 px-2 fs-13 mt-1 mb-1"><i class="bi bi-shield-exclamation"></i> Approval needed for <?= sanitize(implode(', ', $need)) ?>.
        <?php if (can('insurance.manage')): ?><a href="/pages/prior_auths.php?patient=<?= (int)$a['patient_id'] ?>&amp;appointment=<?= $apptId ?>"><?= t('Request it') ?></a><?php endif; ?></div><?php endif; ?>
      <?php if (can('appointments.book')): ?><form method="POST" class="d-flex gap-1 mt-1"><?= csrfField() ?><input type="hidden" name="action" value="eligibility">
        <label class="visually-hidden" for="elg"><?= t('Eligibility reference') ?></label><input class="form-control form-control-sm" id="elg" name="eligibility_ref" maxlength="60" placeholder="<?= t('Eligibility reference') ?>" value="<?= sanitize((string)$a['eligibility_ref']) ?>">
        <button class="btn btn-ghost btn-sm"><?= t('Save') ?></button></form>
      <?php elseif ($a['eligibility_ref']): ?><div class="fs-12 text-tx-3">Eligibility: <?= sanitize($a['eligibility_ref']) ?></div><?php endif; ?>
      <?php endif; ?>
      <div class="section-title mt-4"><?= t('Journey') ?></div>
      <ol class="journey">
        <?php foreach (appointmentJourney($a) as [$step, $at, $by]): ?>
        <li class="<?= $at ? 'done' : '' ?>"><span class="fw-600"><?= $step ?></span> <span class="text-tx-3 fs-12"><?= $at ? date('d M, H:i', strtotime($at)) : '—' ?><?= $by ? ' · ' . sanitize($by) : '' ?></span></li>
        <?php endforeach; ?>
      </ol>
      <?php if ($a['arrived_at'] && $a['doctor_in_at']): ?><div class="fs-12 text-tx-3">Waited <?= minutesBetween($a['arrived_at'], $a['doctor_in_at']) ?> min from arrival to the doctor.</div><?php endif; ?>
      <?php if ($notStarted && can('appointments.book')): ?>
      <div class="section-title mt-4"><?= t('Change') ?></div>
      <form method="POST" class="d-flex flex-column gap-2">
        <?= csrfField() ?><input type="hidden" name="action" value="reschedule">
        <div class="d-flex gap-2"><input type="date" class="form-control form-control-sm" name="date" required value="<?= date('Y-m-d', strtotime($a['scheduled_at'])) ?>" aria-label="<?= t('New date') ?>">
          <input type="time" class="form-control form-control-sm" name="time" step="900" required value="<?= date('H:i', strtotime($a['scheduled_at'])) ?>" aria-label="<?= t('New time') ?>"></div>
        <select class="form-select form-select-sm" name="practitioner_id" aria-label="<?= t('Practitioner') ?>"><option value=""><?= t('Practitioner not set') ?></option>
          <?php foreach ($practitioners as $pr): ?><option value="<?= $pr['id'] ?>" <?= (int)$a['practitioner_id'] === (int)$pr['id'] ? 'selected' : '' ?>><?= sanitize($pr['name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-ghost btn-sm" type="submit"><i class="bi bi-calendar2-week"></i><?= t('Reschedule') ?></button>
      </form>
      <div class="d-flex gap-2 mt-2">
        <button type="button" class="btn btn-ghost btn-sm flex-fill" data-cancel="cancel"><?= t('Cancel') ?></button>
        <button type="button" class="btn btn-ghost btn-sm flex-fill" data-cancel="no_show"><?= t('No-show') ?></button>
      </div>
      <?php elseif ($isOpen && can('appointments.book')): ?>
      <div class="d-flex gap-2 mt-3"><button type="button" class="btn btn-ghost btn-sm flex-fill" data-cancel="cancel"><?= t('Cancel visit') ?></button></div>
      <?php endif; ?>
      <?php if (count($history) > 1): ?>
      <details class="mt-3 fs-12"><summary class="text-tx-3"><?= t('History') ?></summary>
        <?php foreach ($history as $h): ?><div><?= date('d M H:i', strtotime($h['at'])) ?> · <?= sanitize(APPT_STATUS[$h['to_status']][0] ?? $h['to_status']) ?><?= $h['note'] ? ' · ' . sanitize($h['note']) : '' ?><?= $h['staff_name'] ? ' · ' . sanitize($h['staff_name']) : '' ?></div><?php endforeach; ?>
      </details>
      <?php endif; ?>
    </div>
  </aside>

  <div class="min-w-0">
  <?php if ($isOpen && can('appointments.complete')): ?>
    <form method="POST" class="card-crm" id="completeForm">
      <?= csrfField() ?><input type="hidden" name="action" value="complete">
      <div class="card-header-crm"><span><i class="bi bi-clipboard2-check"></i> <?= t('Complete treatment') ?></span><span class="card-header-sub"><?= t('Record what was actually used') ?></span></div>
      <div class="card-body-crm scan-box pb-0">
        <label class="form-label" for="cScan"><i class="bi bi-upc-scan"></i> <?= t('Scan each pack used') ?></label>
        <input class="form-control" id="cScan" autocomplete="off" placeholder="<?= t('Click here and scan the barcode on the vial or box') ?>" aria-describedby="cScanMsg">
        <div class="form-text" id="cScanMsg"><?= t('Selects the batch for that product and checks it isn’t expired, on hold or recalled.') ?></div>
      </div>
      <div class="table-responsive">
      <table class="table mb-0">
        <thead><tr><th><?= t('Product') ?></th><th style="width:170px"><?= t('Used') ?></th><th style="min-width:210px"><?= t('Batch') ?></th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): $lots = $lotOptions[(int)$it['product_id']] ?? []; ?>
          <tr data-gtin="<?= sanitize($it['gtin'] ?? '') ?>" data-pid="<?= (int)$it['product_id'] ?>" data-name="<?= sanitize($it['product_name']) ?>" data-track="<?= (int)$it['track_lots'] ?>">
            <td><span class="fw-600"><?= sanitize($it['product_name']) ?></span><?= $it['prescription_only'] ? ' <span class="chip aging-due">' . t('Rx') . '</span>' : '' ?>
              <span class="cell-sub">Reserved <?= qtyUnit($it['qty_reserved'], $it['usage_unit']) ?><?= $it['qty_min'] !== null ? ' · usual range ' . fmtQty($it['qty_min']) . '–' . fmtQty($it['qty_max']) : '' ?></span>
              <?php foreach ($openVials[(int)$it['product_id']] ?? [] as $ov): ?>
              <span class="cell-sub text-amber"><i class="bi bi-droplet-half"></i> Opened <?= sanitize($it['stock_unit']) ?> used first: <?= qtyUnit($ov['qty_left'], $it['usage_unit']) ?> left, batch <?= sanitize($ov['lot_number'] ?: '–') ?>, use by <?= date('H:i', strtotime($ov['use_by'])) ?><?= date('Y-m-d', strtotime($ov['use_by'])) !== date('Y-m-d') ? ' ' . date('d M', strtotime($ov['use_by'])) : '' ?></span>
              <?php endforeach; ?></td>
            <td><div class="input-group input-group-sm"><input type="number" class="form-control used-qty" name="qty[<?= $it['id'] ?>]" min="0" step="any" required
                  value="<?= sanitize((string)($_POST['qty'][$it['id']] ?? fmtQty($it['qty_reserved']))) ?>" data-min="<?= $it['qty_min'] ?? '' ?>" data-max="<?= $it['qty_max'] ?? '' ?>" aria-label="Amount of <?= sanitize($it['product_name']) ?> used">
                <span class="input-group-text"><?= sanitize($it['usage_unit']) ?></span></div>
              <input type="text" class="form-control form-control-sm mt-1 reason" name="reason[<?= $it['id'] ?>]" placeholder="<?= t('Reason for different amount') ?>" value="<?= sanitize($_POST['reason'][$it['id']] ?? '') ?>" hidden aria-label="<?= t('Reason') ?>"></td>
            <td>
              <?php if (!$lots): ?><span class="chip aging-critical"><?= t('No usable stock') ?></span>
              <?php elseif ($it['track_lots']): ?>
              <select class="form-select form-select-sm" name="lot[<?= $it['id'] ?>]" aria-label="<?= t('Batch used') ?>">
                <option value=""><?= !empty($openVials[(int)$it['product_id']]) ? 'Opened ' . sanitize($it['stock_unit']) . ' first, then earliest expiry' : 'Earliest expiry (' . sanitize($lots[0]['lot_number'] ?? '') . ')' ?></option>
                <?php foreach ($lots as $l): ?><option value="<?= $l['id'] ?>" data-lot="<?= sanitize(strtolower(preg_replace('/[\s\-\/.]+/', '', (string)$l['lot_number']))) ?>"><?= sanitize($l['lot_number'] ?: 'No number') ?> · exp <?= $l['expiry_date'] ? date('M Y', strtotime($l['expiry_date'])) : '–' ?> · <?= fmtQty($l['qty_on_hand']) ?> left</option><?php endforeach; ?>
              </select>
              <?php else: ?><span class="text-tx-3 fs-12"><?= t('Not batch-tracked') ?></span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?><tr><td colspan="3" class="table-empty"><?= t('This treatment has no products in its recipe.') ?></td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
      <div class="card-body-crm border-top"><label class="form-label" for="cNotes"><?= t('Treatment notes') ?></label><textarea class="form-control" id="cNotes" name="notes" rows="2" placeholder="<?= t('Areas treated, anything to follow up') ?>"><?= sanitize($_POST['notes'] ?? '') ?></textarea></div>
      <div class="form-actions"><button type="submit" class="btn btn-gold"><i class="bi bi-check2-circle"></i><?= t('Complete and deduct stock') ?></button></div>
    </form>
  <?php else: ?>
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-box-seam"></i> <?= t('Products') ?></span>
        <span class="card-header-sub"><?= $a['status'] === 'completed' ? 'Used, with batches (for recalls)' : ($isOpen ? 'Reserved for this treatment' : 'Reservation released') ?></span></div>
      <div class="table-responsive">
      <table class="table mb-0">
        <thead><tr><th><?= t('Product') ?></th><th class="text-end"><?= $a['status'] === 'completed' ? 'Used' : 'Reserved' ?></th><?php if ($a['status'] === 'completed'): ?><th><?= t('Batch') ?></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td class="fw-600"><?= sanitize($it['product_name']) ?><?php if ($it['variance_reason']): ?><span class="cell-sub">Differs from usual: <?= sanitize($it['variance_reason']) ?></span><?php endif; ?></td>
            <td class="text-end tabular text-nowrap"><?= qtyUnit($a['status'] === 'completed' ? $it['qty_used'] : $it['qty_reserved'], $it['usage_unit']) ?></td>
            <?php if ($a['status'] === 'completed' && !$it['track_lots']): ?><td class="text-tx-3 fs-12"><?= t('Not batch-tracked') ?></td>
            <?php elseif ($a['status'] === 'completed'): ?><td class="text-nowrap"><?php foreach ($lotsUsed[(int)$it['product_id']] ?? [] as $lu): ?><div><?= sanitize($lu['lot_number'] ?: 'No batch number') ?> <span class="text-tx-3 fs-12"><?= fmtQty($lu['qty']) ?> · exp <?= $lu['expiry_date'] ? date('M Y', strtotime($lu['expiry_date'])) : '–' ?></span></div><?php endforeach; ?><?= empty($lotsUsed[(int)$it['product_id']]) ? '<span class="text-tx-3">–</span>' : '' ?></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?><tr><td colspan="3" class="table-empty"><?= t('No products for this treatment.') ?></td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
      <?php if ($isOpen): ?><div class="card-body-crm border-top fs-12 text-tx-3"><?= t('A practitioner completes this treatment and records what was used.') ?></div><?php endif; ?>
    </div>
  <?php endif; ?>
  </div>
</div>

<?php if ($isOpen && can('appointments.book')): ?>
<div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <?= csrfField() ?><input type="hidden" name="action" id="cancelAction">
      <div class="modal-header"><h5 class="modal-title" id="cancelTitle"><?= t('Cancel appointment') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= t('Close') ?>"></button></div>
      <div class="modal-body">
        <p class="text-tx-2 fs-12"><?= t('The reserved stock goes back to available straight away.') ?></p>
        <label class="form-label" for="cReason"><?= t('Reason') ?></label><input class="form-control" id="cReason" name="reason" maxlength="255" placeholder="<?= t('Optional') ?>">
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-ghost" data-bs-dismiss="modal"><?= t('Keep appointment') ?></button><button type="submit" class="btn btn-gold" id="cancelSubmit"><?= t('Cancel appointment') ?></button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($isOpen && can('appointments.complete')): ?><script src="/assets/js/scan.js?v=1"></script><?php endif; ?>
<?php
$extraJs = "
const BLOCKED = " . json_encode($blockedLots, JSON_HEX_TAG | JSON_HEX_AMP) . ";
if (window.GS1) {
  const msg = document.getElementById('cScanMsg');
  const say = (t, bad) => { msg.textContent = t; msg.className = 'form-text fw-600 ' + (bad ? 'text-danger' : 'text-green'); };
  GS1.attach(document.getElementById('cScan'), (r) => {
    if (!r || !r.gtin) return say('That barcode couldn’t be read as a product code. Choose the batch from the list instead.', true);
    const row = [...document.querySelectorAll('#completeForm tr[data-gtin]')].find(t => t.dataset.gtin === r.gtin);
    if (!row) return say('This pack isn’t one of the products in this treatment (or its barcode isn’t saved on the product).', true);
    const name = row.dataset.name, select = row.querySelector('select[name^=lot]');
    if (!select) return say(row.dataset.track === '1' ? name + ': no usable stock on record. Receive the stock before completing.' : name + ' recognised. Its batch isn’t tracked, so nothing to select.', row.dataset.track === '1');
    if (!r.batch) return say(name + ' recognised, but the barcode has no batch number. Choose the batch from the list.', true);
    const key = r.batch.toLowerCase().replace(/[\s\-\/.]+/g, '');
    const why = (BLOCKED[row.dataset.pid] || {})[key];
    if (why) { if (select) select.value = ''; return say('STOP: ' + name + ' batch ' + r.batch + ' is ' + why + '. Don’t use this pack.', true); }
    const opt = select && [...select.options].find(o => o.dataset.lot === key);
    if (!opt) return say(name + ' batch ' + r.batch + ' isn’t in stock records. Check the pack, or receive it first.', true);
    select.value = opt.value;
    say(name + ': batch ' + r.batch + ' selected' + (r.expiry ? ', expires ' + r.expiry : '') + '.');
  });
}
document.querySelectorAll('[data-cancel]').forEach(b => b.addEventListener('click', () => {
  const ns = b.dataset.cancel === 'no_show';
  document.getElementById('cancelAction').value = b.dataset.cancel;
  document.getElementById('cancelTitle').textContent = ns ? 'Mark as no-show' : 'Cancel appointment';
  document.getElementById('cancelSubmit').textContent = ns ? 'Mark as no-show' : 'Cancel appointment';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('cancelModal')).show();
}));
// Ask for a reason only when the amount is outside the usual range
function checkRange(inp) {
  const v = parseFloat(inp.value), min = parseFloat(inp.dataset.min), max = parseFloat(inp.dataset.max);
  const out = !isNaN(v) && ((!isNaN(min) && v < min) || (!isNaN(max) && v > max));
  const r = inp.closest('td').querySelector('.reason');
  r.hidden = !out && !r.value; r.required = out;
}
document.querySelectorAll('.used-qty').forEach(i => { i.addEventListener('input', () => checkRange(i)); checkRange(i); });";
require_once __DIR__ . '/../includes/footer.php';
