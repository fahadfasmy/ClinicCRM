<?php
// pages/support_access.php — Admin → Support access. Platform support can't open this clinic's data unless an admin
// approves it here: a reason, how long (up to 8 hours), read-only (default) or allowed to make changes. Grants can be
// revoked at once. Everything support did is listed below (it is also in the audit log under "Support · name").
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';
requireLogin();
if (!isAdmin() || !empty($_SESSION['support'])) { http_response_code(403); exit('Only the clinic’s own admins manage support access.'); }
$db = getDB();
$pageTitle = 'Support access';
if (!multiTenant()) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="card-crm"><div class="card-body-crm">' . t('Support access is used when the clinic runs on the shared platform.') . '</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}
require_once __DIR__ . '/../includes/platform_billing.php';
$p = platformDB();
$tid = currentTenant()['id'];
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        if (($_POST['action'] ?? '') === 'revoke') {
            $p->prepare("UPDATE support_grants SET revoked_at = NOW() WHERE id = ? AND tenant_id = ? AND revoked_at IS NULL")->execute([(int)$_POST['id'], $tid]);
            auditLog($db, 'support_grant', (int)$_POST['id'], 'revoked', []);
            platformAudit(null, $tid, 'support_grant_revoked', ['grant' => (int)$_POST['id'], 'by' => staffName()]);
        } else {
            $reason = trim((string)($_POST['reason'] ?? ''));
            $hours = (int)($_POST['hours'] ?? 0);
            if (mb_strlen($reason) < 5) throw new RuntimeException('Say what support should look at.');
            if ($hours < 1 || $hours > 8) throw new RuntimeException('Choose between 1 and 8 hours.');
            $mode = ($_POST['mode'] ?? '') === 'read_write' ? 'read_write' : 'read_only';
            $p->prepare("INSERT INTO support_grants (tenant_id, reason, granted_by_staff_id, granted_by_name, mode, starts_at, expires_at) VALUES (?,?,?,?,?, NOW(), NOW() + INTERVAL $hours HOUR)")
              ->execute([$tid, mb_substr($reason, 0, 500), currentStaffId(), staffName(), $mode]);
            $gid = (int)$p->lastInsertId();
            auditLog($db, 'support_grant', $gid, 'granted', ['hours' => $hours, 'mode' => $mode, 'reason' => $reason]);
            platformAudit(null, $tid, 'support_grant_created', ['grant' => $gid, 'hours' => $hours, 'mode' => $mode, 'by' => staffName()]);
        }
        header('Location: /pages/support_access.php'); exit;
    } catch (RuntimeException $e) { $err = $e->getMessage(); }
}
$grants = $p->prepare("SELECT g.*, a.name AS admin_name FROM support_grants g LEFT JOIN platform_admins a ON a.id = g.platform_admin_id WHERE g.tenant_id = ? ORDER BY g.id DESC LIMIT 30");
$grants->execute([$tid]);
$grants = $grants->fetchAll();
$actions = $db->query("SELECT a.at, a.entity, a.entity_id, a.action, s.name FROM audit_log a JOIN staff s ON s.id = a.staff_id AND s.is_support = 1 ORDER BY a.id DESC LIMIT 100")->fetchAll();
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($err): ?><div class="alert alert-danger" role="alert"><?= sanitize($err) ?></div><?php endif; ?>
<div class="row g-3">
  <div class="col-lg-5"><form method="POST" class="card-crm"><?= csrfField() ?>
    <div class="card-header-crm"><span><i class="bi bi-life-preserver"></i> <?= t('Let support look in') ?></span></div>
    <div class="card-body-crm">
      <p class="fs-13 text-tx-3"><?= t('Support can only open this clinic’s screens while a grant is open. Every page they open and everything they change is recorded.') ?></p>
      <label class="form-label" for="gR"><?= t('What should they look at?') ?></label><input class="form-control mb-2" id="gR" name="reason" required minlength="5" maxlength="500">
      <div class="row g-2"><div class="col-6"><label class="form-label" for="gH"><?= t('For') ?></label><select class="form-select" id="gH" name="hours"><?php foreach ([1, 2, 4, 8] as $h): ?><option value="<?= $h ?>"><?= $h ?> <?= t($h === 1 ? 'hour' : 'hours') ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label" for="gM"><?= t('Access') ?></label><select class="form-select" id="gM" name="mode"><option value="read_only"><?= t('Look only') ?></option><option value="read_write"><?= t('Can make changes') ?></option></select></div></div>
    </div><div class="form-actions"><button class="btn btn-gold ms-auto"><?= t('Allow support') ?></button></div></form></div>
  <div class="col-lg-7">
    <div class="card-crm mb-3"><div class="card-header-crm"><span><?= t('Grants') ?></span></div>
      <ul class="list-group list-group-flush fs-13">
        <?php foreach ($grants as $g): $open = !$g['revoked_at'] && strtotime($g['expires_at']) > time(); ?>
        <li class="list-group-item d-flex gap-2 align-items-start"><div class="flex-grow-1">
          <div class="fw-600"><?= sanitize($g['reason']) ?><?= $g['emergency'] ? ' <span class="chip tone-red">' . t('Emergency') . '</span>' : '' ?></div>
          <div class="text-tx-3"><?= sanitize($g['mode'] === 'read_write' ? t('Can make changes') : t('Look only')) ?> · <?= sanitize(substr($g['starts_at'], 0, 16)) ?> → <?= sanitize(substr($g['expires_at'], 0, 16)) ?> · <?= t('by') ?> <?= sanitize($g['granted_by_name'] ?: t('platform owner')) ?>
            <?= $g['revoked_at'] ? ' · ' . t('revoked') : (!$open ? ' · ' . t('ended') : '') ?></div></div>
          <?php if ($open): ?><form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><button class="btn btn-ghost btn-sm"><?= t('Revoke now') ?></button></form><?php endif; ?>
        </li>
        <?php endforeach; ?>
        <?php if (!$grants): ?><li class="list-group-item text-tx-3"><?= t('Support has never had access.') ?></li><?php endif; ?>
      </ul></div>
    <div class="card-crm"><div class="card-header-crm"><span><?= t('What support did') ?></span></div>
      <div class="table-responsive"><table class="table mb-0 fs-13"><thead><tr><th><?= t('When') ?></th><th><?= t('Who') ?></th><th><?= t('Record') ?></th><th><?= t('Action') ?></th></tr></thead><tbody>
        <?php foreach ($actions as $a): ?><tr><td><?= sanitize(substr($a['at'], 0, 16)) ?></td><td><?= sanitize($a['name']) ?></td><td><?= sanitize($a['entity'] . ' #' . $a['entity_id']) ?></td><td><?= sanitize($a['action']) ?></td></tr><?php endforeach; ?>
        <?php if (!$actions): ?><tr><td colspan="4" class="text-tx-3"><?= t('Nothing yet.') ?></td></tr><?php endif; ?>
      </tbody></table></div></div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
