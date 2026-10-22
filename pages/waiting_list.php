<?php
// pages/waiting_list.php — people who want an earlier or particular slot. Add someone (patient, treatment, practitioner,
// preferred dates); "Book" opens the scheduler with them filled in, and booking resolves the entry.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';     // CSRF helpers
require_once __DIR__ . '/../includes/scheduler.php';
requirePermission('scheduler.view');

$db = getDB();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    requirePermission('scheduler.book');
    try {
        if (($_POST['action'] ?? '') === 'add') {
            $s = $db->prepare("SELECT id FROM patients WHERE mrn = ? AND status = 'active'");
            $s->execute([trim((string)($_POST['mrn'] ?? ''))]);
            $pid = (int)$s->fetchColumn();
            if (!$pid) throw new RuntimeException('No active patient with that file number.');
            $d = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null;
            $db->prepare("INSERT INTO waiting_list (patient_id, branch_id, service_id, staff_id, preferred_from, preferred_to, notes, created_by) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$pid, (int)($_POST['branch_id'] ?? 0) ?: null, (int)($_POST['service_id'] ?? 0) ?: null, (int)($_POST['staff_id'] ?? 0) ?: null,
                          $d('preferred_from'), $d('preferred_to'), mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255) ?: null, currentStaffId()]);
        } elseif (($_POST['action'] ?? '') === 'remove') {
            $db->prepare("UPDATE waiting_list SET resolved_at = NOW(), resolved_note = 'removed' WHERE id = ? AND resolved_at IS NULL")->execute([(int)($_POST['id'] ?? 0)]);
        }
        header('Location: /pages/waiting_list.php'); exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$rows = $db->query("SELECT w.*, p.mrn, p.first_name, p.middle_name, p.last_name, p.title, s.name AS service_name, st.name AS staff_name, b.name AS branch_name,
                           (SELECT phone FROM patient_phones x WHERE x.patient_id = p.id ORDER BY is_primary DESC, id LIMIT 1) AS phone
                    FROM waiting_list w JOIN patients p ON p.id = w.patient_id LEFT JOIN services s ON s.id = w.service_id
                    LEFT JOIN staff st ON st.id = w.staff_id LEFT JOIN branches b ON b.id = w.branch_id
                    WHERE w.resolved_at IS NULL ORDER BY w.created_at")->fetchAll();
$services = $db->query("SELECT id, name FROM services WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$pracs = practitionerList($db, true);
$branches = branchList($db);

$pageTitle = 'Waiting list';
$pageSubtitle = count($rows) . ' waiting';
$pageBack = ['href' => '/pages/scheduler.php', 'label' => 'Scheduler'];
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i><?= sanitize($error) ?></div><?php endif; ?>
<div class="row g-3 page-section">
  <div class="col-xl-8"><div class="card-crm">
    <?php if (!$rows): ?><div class="empty-state"><i class="bi bi-hourglass"></i><div class="empty-state-title"><?= t('Nobody waiting') ?></div></div>
    <?php else: ?><div class="table-responsive"><table class="table mb-0">
      <thead><tr><th><?= t('Patient') ?></th><th><?= t('Wants') ?></th><th><?= t('When') ?></th><th><?= t('Added') ?></th><th></th></tr></thead><tbody>
      <?php foreach ($rows as $w): ?><tr>
        <td><a href="/pages/patient.php?id=<?= (int)$w['patient_id'] ?>" class="fw-semibold"><?= sanitize(patientName($w)) ?></a><div class="fs-12 text-tx-3"><?= sanitize($w['mrn']) ?><?= $w['phone'] ? ' · ' . sanitize($w['phone']) : '' ?></div></td>
        <td class="fs-13"><?= sanitize($w['service_name'] ?? 'Any treatment') ?><div class="text-tx-3"><?= sanitize($w['staff_name'] ?? 'Any practitioner') ?><?= $w['branch_name'] ? ' · ' . sanitize($w['branch_name']) : '' ?></div><?= $w['notes'] ? '<div class="text-tx-2">' . sanitize($w['notes']) . '</div>' : '' ?></td>
        <td class="fs-13 text-nowrap"><?= $w['preferred_from'] ? formatDate($w['preferred_from']) : 'Any day' ?><?= $w['preferred_to'] ? ' – ' . formatDate($w['preferred_to']) : '' ?></td>
        <td class="fs-13 text-nowrap"><?= timeSince($w['created_at']) ?></td>
        <td class="text-end text-nowrap">
          <?php if (can('scheduler.book')): ?>
          <a class="btn btn-gold btn-sm" href="/pages/scheduler.php?waitlist=<?= (int)$w['id'] ?><?= $w['branch_id'] ? '&amp;branch=' . (int)$w['branch_id'] : '' ?><?= $w['preferred_from'] && $w['preferred_from'] > date('Y-m-d') ? '&amp;date=' . sanitize($w['preferred_from']) : '' ?>"><?= t('Book') ?></a>
          <form method="POST" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
            <button class="btn btn-ghost btn-sm" data-confirm="Remove from the waiting list?" aria-label="<?= t('Remove') ?>"><i class="bi bi-x-lg"></i></button></form>
          <?php endif; ?>
        </td>
      </tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div></div>
  <?php if (can('scheduler.book')): ?>
  <div class="col-xl-4"><form method="POST" class="card-crm">
    <?= csrfField() ?><input type="hidden" name="action" value="add">
    <div class="card-header-crm"><span><i class="bi bi-plus-lg"></i> <?= t('Add to the waiting list') ?></span></div>
    <div class="card-body-crm">
      <label class="form-label" for="wMrn"><?= t('Patient file number (MRN)') ?></label><input class="form-control mb-3" id="wMrn" name="mrn" required placeholder="MRN-000123">
      <label class="form-label" for="wSvc"><?= t('Treatment') ?></label><select class="form-select mb-3" id="wSvc" name="service_id"><option value=""><?= t('Any') ?></option><?php foreach ($services as $id => $n): ?><option value="<?= $id ?>"><?= sanitize($n) ?></option><?php endforeach; ?></select>
      <label class="form-label" for="wPr"><?= t('Practitioner') ?></label><select class="form-select mb-3" id="wPr" name="staff_id"><option value=""><?= t('Any') ?></option><?php foreach ($pracs as $p): ?><option value="<?= (int)$p['id'] ?>"><?= sanitize($p['name']) ?></option><?php endforeach; ?></select>
      <?php if (count($branches) > 1): ?><label class="form-label" for="wBr"><?= t('Branch') ?></label><select class="form-select mb-3" id="wBr" name="branch_id"><?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= sanitize($b['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
      <div class="row g-2 mb-3"><div class="col-6"><label class="form-label" for="wF"><?= t('From') ?></label><input type="date" class="form-control" id="wF" name="preferred_from"></div>
        <div class="col-6"><label class="form-label" for="wT"><?= t('Until') ?></label><input type="date" class="form-control" id="wT" name="preferred_to"></div></div>
      <label class="form-label" for="wN"><?= t('Notes') ?></label><input class="form-control" id="wN" name="notes" maxlength="255" placeholder="<?= t('e.g. Weekday mornings only') ?>">
    </div>
    <div class="form-actions"><button class="btn btn-gold ms-auto"><i class="bi bi-plus-lg"></i><?= t('Add') ?></button></div>
  </form></div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
