<?php
// pages/onboarding.php — "Set up your clinic": the five steps a new clinic goes through after signing up, each ticked
// when it is really done (read from the data, not a checkbox): clinic profile, first branch and hours, treatments,
// staff and practitioners, WhatsApp or email. Admins see a reminder above every page until all five are done.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
requireLogin();
if (!isAdmin()) { header('Location: /pages/dashboard.php'); exit; }
$db = getDB();
$steps = onboardingSteps($db);
$done = count(array_filter(array_column($steps, 'done')));
$pageTitle = 'Set up your clinic';
$pageSubtitle = t('{n} of 5 done', ['n' => $done]);
require_once __DIR__ . '/../includes/header.php';
?>
<div class="card-crm" style="max-width:760px"><ol class="list-group list-group-flush list-group-numbered">
  <?php foreach ($steps as $s): ?>
  <li class="list-group-item d-flex gap-3 align-items-start">
    <div class="flex-grow-1"><div class="fw-600"><?= $s['done'] ? '<i class="bi bi-check-circle-fill text-green" aria-hidden="true"></i> ' : '' ?><?= sanitize(t($s['title'])) ?><?= $s['done'] ? ' <span class="visually-hidden">(' . t('done') . ')</span>' : '' ?></div>
      <div class="fs-13 text-tx-3"><?= sanitize(t($s['help'])) ?></div></div>
    <a class="btn <?= $s['done'] ? 'btn-ghost' : 'btn-gold' ?> btn-sm" href="<?= sanitize($s['link']) ?>"><?= $s['done'] ? t('Review') : t('Start') ?></a>
  </li>
  <?php endforeach; ?>
</ol></div>
<?php if ($done === 5): ?><p class="mt-3"><i class="bi bi-stars"></i> <?= t('All set. Book your first appointment from the Scheduler.') ?></p><?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
