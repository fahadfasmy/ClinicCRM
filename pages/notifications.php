<?php
// pages/notifications.php — the bell: things for this person, or for everyone who can act on them (patient requests from
// the self-service link, alerts). "Done" closes it for everyone it was sent to and records who did it.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
requireLogin();

$db = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $n = notifMarkRead($db, ($_POST['id'] ?? '') === 'all' ? null : max(1, (int)($_POST['id'] ?? 0)));
    $_SESSION['notif_flash'] = $n ? ($n === 1 ? 'Marked as done.' : "$n marked as done.") : 'Nothing to mark.';
    header('Location: /pages/notifications.php' . (isset($_GET['all']) ? '?all=1' : '')); exit;
}
$all = isset($_GET['all']);
$rows = notifList($db, !$all);
$open = notifCount($db);
$flash = $_SESSION['notif_flash'] ?? null; unset($_SESSION['notif_flash']);

$pageTitle = 'Notifications';
$pageSubtitle = $open ? "$open to look at" : 'Nothing new';
$pageActions = ($open ? '<form method="POST" class="d-inline">' . csrfField() . '<input type="hidden" name="id" value="all"><button class="btn btn-ghost"><i class="bi bi-check2-all"></i>' . t('Mark all done') . '</button></form>' : '')
    . '<a class="btn btn-ghost" href="' . ($all ? '/pages/notifications.php">Open only' : '?all=1">Show done too') . '</a>';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-success alert-auto" role="status"><i class="bi bi-check-circle"></i><?= sanitize($flash) ?></div><?php endif; ?>
<div class="card-crm"><ul class="list-group list-group-flush">
  <?php foreach ($rows as $n): ?>
  <li class="list-group-item d-flex gap-3 align-items-start <?= $n['read_at'] ? 'text-tx-3' : '' ?>">
    <i class="bi <?= $n['read_at'] ? 'bi-check-circle' : 'bi-bell-fill text-gold' ?> mt-1" aria-hidden="true"></i>
    <div class="flex-grow-1">
      <div class="fw-600"><?= $n['link'] ? '<a href="' . sanitize($n['link']) . '">' . sanitize($n['title']) . '</a>' : sanitize($n['title']) ?></div>
      <?php if ($n['body']): ?><div class="fs-13"><?= nl2br(sanitize($n['body'])) ?></div><?php endif; ?>
      <div class="fs-12 text-tx-3"><?= timeSince($n['created_at']) ?><?= $n['read_at'] ? ' · done by ' . sanitize($n['read_by_name'] ?? 'someone') . ' ' . timeSince($n['read_at']) : '' ?></div>
    </div>
    <?php if (!$n['read_at']): ?><form method="POST"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="btn btn-ghost btn-sm" aria-label="Mark “<?= sanitize($n['title']) ?>” as done"><i class="bi bi-check2"></i><?= t('Done') ?></button></form><?php endif; ?>
  </li>
  <?php endforeach; ?>
  <?php if (!$rows): ?><li class="list-group-item text-tx-3">No notifications<?= $all ? '' : ' to look at' ?>.</li><?php endif; ?>
</ul></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
