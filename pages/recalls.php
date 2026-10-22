<?php
// pages/recalls.php — Marketing → Recalls: patients due for a visit by the clinic's rules ("not visited for 90 days",
// "Botox every 120 days", "package sessions left"), to call or message and mark contacted / booked / dismissed.
// Consultants see the recalls assigned to them (and unassigned ones) unless they have "All leads". New recalls are
// found every hour; rules marked "send a message" also go out by the Recall automation (Messaging).
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/crm.php';
requirePermission('marketing.recalls');

$db = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        switch ($_POST['action'] ?? '') {
            case 'rule': if (!isAdmin()) throw new RuntimeException('Only admins change recall rules.'); saveRecallRule($db, (int)($_POST['id'] ?? 0), $_POST); $msg = 'Rule saved.'; break;
            case 'run': $n = generateRecalls($db); $msg = "$n new recall(s)."; break;
            case 'status': updateRecall($db, (int)$_POST['id'], (string)$_POST['status'], (string)($_POST['note'] ?? '')); $msg = 'Updated.'; break;
            default: throw new RuntimeException('Unknown action.');
        }
        $_SESSION['crm_flash'] = ['success', $msg];
    } catch (Throwable $e) { $_SESSION['crm_flash'] = ['danger', $e->getMessage()]; }
    header('Location: /pages/recalls.php' . (!empty($_POST['qs']) ? '?' . preg_replace('/[^a-z0-9_=&]/i', '', $_POST['qs']) : '')); exit;
}
$flash = $_SESSION['crm_flash'] ?? null;
unset($_SESSION['crm_flash']);
$st = isset(RECALL_STATUS[$_GET['status'] ?? '']) ? $_GET['status'] : 'open';
$mine = can('leads.all') ? '' : ' AND (r.assigned_to = ' . currentStaffId() . ' OR r.assigned_to IS NULL)';
$s = $db->prepare("SELECT r.*, rr.name AS rule, CONCAT_WS(' ', p.first_name, p.last_name) AS patient, p.mrn, s.name AS owner,
                          (SELECT phone FROM patient_phones x WHERE x.patient_id = p.id ORDER BY is_primary DESC LIMIT 1) AS phone,
                          (SELECT MAX(a.scheduled_at) FROM appointments a WHERE a.patient_id = p.id AND a.status = 'completed') AS last_visit
                   FROM recalls r JOIN recall_rules rr ON rr.id = r.rule_id JOIN patients p ON p.id = r.patient_id LEFT JOIN staff s ON s.id = r.assigned_to
                   WHERE r.status = ?$mine ORDER BY r.due_on, r.id LIMIT 500");
$s->execute([$st]);
$rows = $s->fetchAll();
$rules = $db->query("SELECT rr.*, sv.name AS service, (SELECT COUNT(*) FROM recalls r WHERE r.rule_id = rr.id AND r.status = 'open') AS open FROM recall_rules rr LEFT JOIN services sv ON sv.id = rr.service_id ORDER BY rr.is_active DESC, rr.name")->fetchAll();
$services = $db->query("SELECT id, name FROM services WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Recalls';
$pageSubtitle = count($rows) . ' ' . strtolower(RECALL_STATUS[$st][0]);
$pageActions = '<form method="POST" class="d-inline">' . csrfField() . '<input type="hidden" name="action" value="run"><button class="btn btn-ghost"><i class="bi bi-arrow-repeat"></i>Check now</button></form>';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?> <?= $flash[0] === 'success' ? 'alert-auto' : '' ?>"><i class="bi bi-info-circle"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<div class="toolbar mb-3"><div class="segmented" role="tablist"><?php foreach (RECALL_STATUS as $k => [$l]): ?><a href="?status=<?= $k ?>" class="<?= $st === $k ? 'active' : '' ?>" role="tab" aria-selected="<?= $st === $k ? 'true' : 'false' ?>"><?= $l ?></a><?php endforeach; ?></div></div>
<div class="row g-3">
  <div class="col-xl-8"><div class="card-crm"><div class="list-group list-group-flush">
    <?php foreach ($rows as $r): ?><div class="list-group-item">
      <div class="d-flex flex-wrap gap-2 align-items-center"><a class="fw-600" href="/pages/patient.php?id=<?= (int)$r['patient_id'] ?>"><?= sanitize($r['patient']) ?></a><span class="fs-12 text-tx-3"><?= sanitize($r['mrn']) ?> · <?= sanitize((string)$r['phone']) ?></span>
        <span class="ms-auto fs-12 text-tx-3"><?= sanitize($r['rule']) ?> · last visit <?= $r['last_visit'] ? formatDate($r['last_visit']) : '—' ?><?= $r['owner'] ? ' · ' . sanitize($r['owner']) : '' ?></span></div>
      <?php if ($r['note']): ?><div class="fs-13 text-tx-2"><?= sanitize($r['note']) ?></div><?php endif; ?>
      <form method="POST" class="d-flex flex-wrap gap-1 mt-1"><?= csrfField() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="qs" value="status=<?= $st ?>">
        <label class="visually-hidden" for="rn<?= (int)$r['id'] ?>">Note</label><input class="form-control form-control-sm" style="max-width:260px" id="rn<?= (int)$r['id'] ?>" name="note" placeholder="Note" maxlength="255">
        <?php foreach (array_diff(array_keys(RECALL_STATUS), [$st]) as $to): ?><button class="btn btn-ghost btn-sm" name="status" value="<?= $to ?>"><?= RECALL_STATUS[$to][0] ?></button><?php endforeach; ?>
        <?php if (can('scheduler.book')): ?><a class="btn btn-ghost btn-sm" href="/pages/scheduler.php?patient=<?= (int)$r['patient_id'] ?>"><i class="bi bi-calendar-plus"></i>Book</a><?php endif; ?></form>
    </div><?php endforeach; ?>
    <?php if (!$rows): ?><div class="list-group-item text-tx-3">Nobody here.</div><?php endif; ?>
  </div></div></div>
  <div class="col-xl-4">
    <div class="card-crm mb-3"><div class="card-header-crm"><span><i class="bi bi-sliders"></i> Rules</span></div><div class="list-group list-group-flush">
      <?php foreach ($rules as $r): ?><<?= isAdmin() ? 'a href="?status=' . $st . '&amp;rule=' . (int)$r['id'] . '"' : 'div' ?> class="list-group-item list-group-item-action fs-13 <?= $r['is_active'] ? '' : 'text-tx-3' ?>"><span class="fw-600"><?= sanitize($r['name']) ?></span>
        <span class="d-block fs-12 text-tx-3"><?= RECALL_KINDS[$r['kind']] ?> <?= (int)$r['days_after'] ?> days<?= $r['service'] ? ' · ' . sanitize($r['service']) : '' ?> · <?= (int)$r['open'] ?> open<?= $r['send_message'] ? ' · sends a message' : '' ?></span></<?= isAdmin() ? 'a' : 'div' ?>><?php endforeach; ?>
      <?php if (!$rules): ?><div class="list-group-item fs-13 text-tx-3">No rules yet.</div><?php endif; ?></div></div>
    <?php if (isAdmin()): $er = isset($_GET['rule']) ? (array_values(array_filter($rules, fn($r) => (int)$r['id'] === (int)$_GET['rule']))[0] ?? null) : null; ?>
    <form method="POST" class="card-crm"><?= csrfField() ?><input type="hidden" name="action" value="rule"><input type="hidden" name="id" value="<?= (int)($er['id'] ?? 0) ?>">
      <div class="card-header-crm"><span><?= $er ? 'Edit rule' : 'New rule' ?></span></div><div class="card-body-crm">
        <label class="form-label" for="rnm">Name</label><input class="form-control mb-2" id="rnm" name="name" required value="<?= sanitize((string)($er['name'] ?? '')) ?>" placeholder="e.g. Botox every 4 months">
        <div class="row g-2 mb-2"><div class="col-7"><label class="form-label" for="rk">When</label><select class="form-select" id="rk" name="kind"><?php foreach (RECALL_KINDS as $k => $l): ?><option value="<?= $k ?>" <?= ($er['kind'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="col-5"><label class="form-label" for="rd">Days</label><input class="form-control" id="rd" name="days_after" type="number" min="1" max="3650" required value="<?= (int)($er['days_after'] ?? 90) ?>"></div></div>
        <label class="form-label" for="rs">Treatment <span class="fw-normal text-tx-3">(for “treatment due again”)</span></label><select class="form-select mb-2" id="rs" name="service_id"><option value="">—</option><?php foreach ($services as $k => $n): ?><option value="<?= (int)$k ?>" <?= (int)($er['service_id'] ?? 0) === (int)$k ? 'selected' : '' ?>><?= sanitize($n) ?></option><?php endforeach; ?></select>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="rm" name="send_message" value="1" <?= !empty($er['send_message']) ? 'checked' : '' ?>><label class="form-check-label" for="rm">Also send the recall message (when the Recall automation is on)</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="ra" name="is_active" value="1" <?= !$er || $er['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="ra">Active</label></div>
      </div><div class="form-actions"><button class="btn btn-gold ms-auto">Save rule</button></div></form>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
