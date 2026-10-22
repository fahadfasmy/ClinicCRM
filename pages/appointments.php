<?php
// pages/appointments.php — treatment appointments by day, plus the recall lookup (?lot=ID).
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';
requirePermission('appointments.view');

$db = getDB();
ensureInventorySchema($db);

// ── Recall lookup: every patient who received a batch ──
$lotId = (int)($_GET['lot'] ?? 0);
if ($lotId) {
    $s = $db->prepare("SELECT l.*, p.name AS product_name, p.usage_unit, p.id AS pid FROM stock_lots l JOIN products p ON p.id = l.product_id WHERE l.id = ?");
    $s->execute([$lotId]);
    $lot = $s->fetch();
    if (!$lot) { http_response_code(404); die('Batch not found.'); }
    $s = $db->prepare("
        SELECT a.id, a.scheduled_at, a.completed_at, ld.id AS lead_id, a.patient_id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', pt.first_name, pt.last_name)), ''), ld.name, 'Client') AS name,
               COALESCE((SELECT phone FROM patient_phones x WHERE x.patient_id = a.patient_id ORDER BY is_primary DESC, id LIMIT 1), ld.phone) AS phone,
               sv.name AS service_name, pr.name AS practitioner_name, -SUM(m.qty_change) AS qty
        FROM stock_movements m
        JOIN appointments a ON a.id = m.appointment_id
        LEFT JOIN leads ld ON ld.id = a.lead_id
        LEFT JOIN patients pt ON pt.id = a.patient_id
        JOIN services sv ON sv.id = a.service_id
        LEFT JOIN staff pr ON pr.id = a.practitioner_id
        WHERE m.lot_id = ? AND m.type = 'consume'
        GROUP BY a.id ORDER BY a.completed_at DESC");
    $s->execute([$lotId]);
    $patients = $s->fetchAll();

    // Contact list for a recall, e.g. to hand to the medical director
    if (isset($_GET['csv']) && can('inventory.manage')) {
        logActivity(currentStaffId(), 'recall_list_exported', null, null, 'lot #' . $lotId);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="batch-' . preg_replace('/[^A-Za-z0-9_-]/', '', $lot['lot_number'] ?: (string)$lot['id']) . '-patients.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Patient', 'Phone', 'Treatment', 'Date', 'Practitioner', 'Amount', 'Product', 'Batch', 'Expiry']);
        foreach ($patients as $r) {
            fputcsv($out, [$r['name'], $r['phone'], $r['service_name'], date('Y-m-d', strtotime($r['completed_at'] ?: $r['scheduled_at'])), $r['practitioner_name'] ?? '',
                           fmtQty($r['qty']) . ' ' . $lot['usage_unit'], $lot['product_name'], $lot['lot_number'], $lot['expiry_date']]);
        }
        exit;
    }

    $pageTitle    = 'Batch ' . ($lot['lot_number'] ?: '#' . $lot['id']);
    $pageBack     = ['href' => '/pages/recall.php?q=' . urlencode($lot['lot_number'] ?? ''), 'label' => 'Batch recall'];
    $pageActions  = can('inventory.manage') && $patients ? '<a href="?lot=' . $lotId . '&csv=1" class="btn btn-ghost"><i class="bi bi-download"></i>Download list</a>' : '';
    $pageSubtitle = sanitize($lot['product_name']) . ' · ' . count($patients) . ' patient' . (count($patients) == 1 ? '' : 's') . ' received this batch'
                  . ($lot['status'] !== 'available' ? ' &nbsp;<span class="chip aging-critical">' . ucfirst($lot['status']) . '</span>' : '');
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-person-lines-fill"></i> Patients who received this batch</span><span class="card-header-sub">Use this list if the supplier recalls the batch</span></div>
      <div class="table-responsive">
      <table class="table table-hover">
        <thead><tr><th>Patient</th><th>Phone</th><th>Treatment</th><th>Date</th><th>Practitioner</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($patients as $r): ?>
          <tr class="row-link" data-href="/pages/appointment.php?id=<?= $r['id'] ?>">
            <td><a class="cell-title" href="<?= $r['patient_id'] ? '/pages/patient.php?id=' . (int)$r['patient_id'] : '/pages/lead_view.php?id=' . (int)$r['lead_id'] ?>"><?= sanitize($r['name']) ?></a></td>
            <td class="tabular text-nowrap"><?= sanitize((string)$r['phone']) ?></td>
            <td><?= sanitize($r['service_name']) ?></td>
            <td class="text-nowrap"><?= date('d M Y', strtotime($r['completed_at'] ?: $r['scheduled_at'])) ?></td>
            <td><?= sanitize($r['practitioner_name'] ?? '–') ?></td>
            <td class="text-end tabular text-nowrap"><?= qtyUnit($r['qty'], $lot['usage_unit']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$patients): ?><tr><td colspan="6" class="table-empty">No patient has received this batch yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// ── Appointment list ──
$tabs = [
    'today'    => ['Today',            "a.status IN ('booked','confirmed','arrived','in_triage','with_doctor','completed') AND DATE(a.scheduled_at) = CURDATE()", 'a.scheduled_at'],
    'upcoming' => ['Upcoming',         "a.status IN ('booked','confirmed','arrived','in_triage','with_doctor') AND a.scheduled_at >= NOW()", 'a.scheduled_at'],
    'overdue'  => ['Needs completing', "a.status IN ('booked','confirmed','arrived','in_triage','with_doctor') AND a.scheduled_at < NOW() - INTERVAL 1 HOUR", 'a.scheduled_at'],
    'done'     => ['Completed',        "a.status = 'completed'", 'a.completed_at DESC'],
    'closed'   => ['Cancelled',        "a.status IN ('cancelled','no_show')", 'a.scheduled_at DESC'],
];
$tab  = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'today';
$fPr  = (int)($_GET['pr'] ?? 0);
if (!isset($_GET['pr']) && currentRole() === 'practitioner') $fPr = currentStaffId();   // practitioners start on their own list

$counts = [];
foreach ($tabs as $k => [, $where]) {
    $s = $db->prepare("SELECT COUNT(*) FROM appointments a WHERE $where" . ($fPr ? " AND a.practitioner_id = ?" : ''));
    $s->execute($fPr ? [$fPr] : []);
    $counts[$k] = (int)$s->fetchColumn();
}
[$tabLabel, $where, $order] = $tabs[$tab];
$s = $db->prepare("
    SELECT a.*, COALESCE((SELECT GROUP_CONCAT(s2.name ORDER BY s2.name SEPARATOR ', ') FROM appointment_services x JOIN services s2 ON s2.id = x.service_id WHERE x.appointment_id = a.id), sv.name) AS service_name,
      COALESCE(a.duration_min, sv.duration_min) AS duration_min, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', pt.first_name, pt.last_name)), ''), ld.name, 'Client') AS lead_name,
      COALESCE((SELECT phone FROM patient_phones x WHERE x.patient_id = a.patient_id ORDER BY is_primary DESC, id LIMIT 1), ld.phone) AS lead_phone, ld.assigned_to, pr.name AS practitioner_name,
      (SELECT COUNT(*) FROM appointment_items ai JOIN products p ON p.id = ai.product_id
        WHERE ai.appointment_id = a.id AND ai.qty_used IS NULL
          AND ai.qty_reserved > (SELECT COALESCE(SUM(l.qty_on_hand),0) FROM stock_lots l WHERE l.product_id = p.id AND l.status = 'available' AND (l.expiry_date IS NULL OR l.expiry_date >= DATE(a.scheduled_at)))
      ) AS short_items
    FROM appointments a
    JOIN services sv ON sv.id = a.service_id
    LEFT JOIN leads ld ON ld.id = a.lead_id
    LEFT JOIN patients pt ON pt.id = a.patient_id
    LEFT JOIN staff pr ON pr.id = a.practitioner_id
    WHERE $where" . ($fPr ? " AND a.practitioner_id = ?" : '') . "
    ORDER BY $order LIMIT 300");
$s->execute($fPr ? [$fPr] : []);
$appts = $s->fetchAll();
$byDay = [];
foreach ($appts as $r) $byDay[date('Y-m-d', strtotime($tab === 'done' ? $r['completed_at'] : $r['scheduled_at']))][] = $r;
$practitioners = practitionerOptions($db);

$pageTitle    = 'Appointments';
$pageSubtitle = $counts['today'] . ' today · ' . $counts['upcoming'] . ' upcoming' . ($counts['overdue'] ? ' · <span class="text-amber fw-600">' . $counts['overdue'] . ' need completing</span>' : '');
require_once __DIR__ . '/../includes/header.php';
$url = fn(array $c) => '?' . http_build_query(array_filter(array_merge(['tab' => $tab, 'pr' => $fPr ?: ''], $c), fn($v) => $v !== '' && $v !== 0));
?>
<div class="toolbar">
  <div class="segmented" role="tablist" aria-label="Show">
    <?php foreach ($tabs as $k => [$label]): ?>
    <a href="<?= $url(['tab' => $k]) ?>" class="<?= $tab === $k ? 'active' : '' ?>" role="tab" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= $label ?> <span class="count"><?= $counts[$k] ?></span></a>
    <?php endforeach; ?>
  </div>
  <select class="form-select form-select-sm" aria-label="Practitioner" onchange="location='<?= $url(['pr' => '']) ?>'+(this.value?'&pr='+this.value:'&pr=0')">
    <option value="">All practitioners</option>
    <?php foreach ($practitioners as $pr): ?><option value="<?= $pr['id'] ?>" <?= $fPr === (int)$pr['id'] ? 'selected' : '' ?>><?= sanitize($pr['name']) ?></option><?php endforeach; ?>
  </select>
</div>

<?php if ($tab === 'overdue' && $appts): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i><div>These appointments have passed but were never completed, so their stock is still reserved. Complete each one, or mark it cancelled or no-show to release the stock.</div></div>
<?php endif; ?>

<?php foreach ($byDay as $day => $rows): ?>
<div class="card-crm page-section">
  <div class="card-header-crm"><span><i class="bi bi-calendar3"></i> <?= $day === date('Y-m-d') ? 'Today' : ($day === date('Y-m-d', strtotime('+1 day')) ? 'Tomorrow' : date('l d M Y', strtotime($day))) ?></span><span class="card-header-sub"><?= count($rows) ?> appointment<?= count($rows) == 1 ? '' : 's' ?></span></div>
  <div class="list-rows">
    <?php foreach ($rows as $r): $open = in_array($r['status'], ['booked', 'confirmed', 'arrived', 'in_triage', 'with_doctor'], true); $late = $open && strtotime($r['scheduled_at']) < time() - 3600; ?>
    <a class="list-row" href="/pages/appointment.php?id=<?= $r['id'] ?>">
      <span class="tabular fw-600" style="width:48px"><?= date('H:i', strtotime($r['scheduled_at'])) ?></span>
      <div class="list-row-main">
        <div class="list-row-title"><?= sanitize($r['lead_name']) ?> · <span class="fw-500"><?= sanitize($r['service_name']) ?></span></div>
        <div class="list-row-sub"><?= sanitize((string)$r['lead_phone']) ?> · <?= (int)$r['duration_min'] ?> min · <?= sanitize($r['practitioner_name'] ?? 'No practitioner yet') ?></div>
      </div>
      <div class="list-row-end">
        <?php if ($open && (int)$r['short_items'] > 0): ?><span class="chip aging-critical" title="Not enough usable stock for this date"><i class="bi bi-box-seam"></i> Stock short</span><?php endif; ?>
        <?php if ($late): ?><span class="chip aging-overdue">Needs completing</span><?php endif; ?>
        <?= apptBadge($r['status']) ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
<?php if (!$appts): ?>
<div class="card-crm"><div class="empty-state"><i class="bi bi-calendar-check"></i><?= [
    'today' => 'No treatments today.', 'upcoming' => 'No upcoming treatments. Book one in the scheduler or from a lead’s page.',
    'overdue' => 'Nothing waiting to be completed.', 'done' => 'No completed treatments yet.', 'closed' => 'No cancelled appointments.'][$tab] ?></div></div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
