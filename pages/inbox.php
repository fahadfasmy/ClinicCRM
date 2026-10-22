<?php
// pages/inbox.php — Messages: conversations by phone number (WhatsApp and SMS, in and out), one conversation with a reply
// box (WhatsApp free text only within 24 hours of the patient's last message), and patient requests (online
// cancellations, reschedule requests, low feedback scores) to deal with.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/messaging.php';
requirePermission('messages.view');

$db = getDB();
$tab = ($_GET['tab'] ?? '') === 'requests' ? 'requests' : 'chats';
$with = (string)($_GET['with'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        if (($_POST['action'] ?? '') === 'reply') {
            $last = $db->prepare("SELECT channel, patient_id, lead_id FROM messages WHERE to_from = ? ORDER BY id DESC LIMIT 1"); $last->execute([$with]); $l = $last->fetch();
            if (!$l) throw new RuntimeException('Conversation not found.');
            sendReply($db, $l['channel'] === 'email' ? 'email' : $l['channel'], $with, (string)($_POST['text'] ?? ''), $l['patient_id'] ? (int)$l['patient_id'] : null, $l['lead_id'] ? (int)$l['lead_id'] : null);
            $_SESSION['msg_flash'] = ['success', 'Sent.'];
        } elseif (($_POST['action'] ?? '') === 'done') {
            $db->prepare("UPDATE staff_notifications SET read_at = NOW(), read_by = ? WHERE id = ?")->execute([currentStaffId(), (int)$_POST['id']]);
        }
    } catch (Throwable $e) { $_SESSION['msg_flash'] = ['danger', $e->getMessage()]; }
    header('Location: /pages/inbox.php?' . http_build_query(array_filter(['tab' => $tab === 'requests' ? 'requests' : null, 'with' => $with ?: null]))); exit;
}
$flash = $_SESSION['msg_flash'] ?? null;
unset($_SESSION['msg_flash']);
$chats = $db->query("SELECT m.to_from, m.channel, m.body, m.direction, m.created_at, m.patient_id, m.lead_id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', p.first_name, p.last_name)), ''), l.name) AS who,
                            (SELECT COUNT(*) FROM messages x WHERE x.to_from = m.to_from AND x.direction = 'in' AND x.created_at > NOW() - INTERVAL 1 DAY) AS recent_in
                     FROM messages m JOIN (SELECT to_from, MAX(id) AS id FROM messages WHERE channel IN ('whatsapp','sms') AND status <> 'skipped' GROUP BY to_from) last ON last.id = m.id
                     LEFT JOIN patients p ON p.id = m.patient_id LEFT JOIN leads l ON l.id = m.lead_id ORDER BY m.id DESC LIMIT 100")->fetchAll();
$thread = [];
if ($with !== '') {
    $s = $db->prepare("SELECT m.*, s.name AS staff FROM messages m LEFT JOIN staff s ON s.id = m.staff_id WHERE m.to_from = ? AND m.status <> 'skipped' ORDER BY m.id DESC LIMIT 200"); $s->execute([$with]);
    $thread = array_reverse($s->fetchAll());
    if ($thread && ($pid = end($thread)['patient_id'])) logPatientAccess($db, (int)$pid, 'messages');
}
$requests = $db->query("SELECT * FROM staff_notifications WHERE read_at IS NULL ORDER BY id DESC LIMIT 100")->fetchAll();
$requests = array_values(array_filter($requests, fn($r) => !$r['permission'] || can($r['permission'])));

$pageTitle = 'Messages';
$pageSubtitle = count($requests) . ' patient request' . (count($requests) === 1 ? '' : 's') . ' waiting';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?> <?= $flash[0] === 'success' ? 'alert-auto' : '' ?>"><i class="bi bi-info-circle"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<div class="toolbar mb-3"><div class="segmented" role="tablist">
  <a href="?" class="<?= $tab === 'chats' ? 'active' : '' ?>" role="tab" aria-selected="<?= $tab === 'chats' ? 'true' : 'false' ?>"><?= t('Conversations') ?></a>
  <a href="?tab=requests" class="<?= $tab === 'requests' ? 'active' : '' ?>" role="tab" aria-selected="<?= $tab === 'requests' ? 'true' : 'false' ?>"><?= t('Patient requests') ?> <span class="count"><?= count($requests) ?></span></a></div></div>
<?php if ($tab === 'requests'): ?>
<div class="card-crm"><div class="list-group list-group-flush">
  <?php foreach ($requests as $r): ?><div class="list-group-item d-flex gap-2 align-items-center"><span class="flex-grow-1"><span class="fw-600"><?= sanitize($r['title']) ?></span>
    <span class="d-block fs-12 text-tx-3"><?= sanitize((string)$r['body']) ?> · <?= timeSince($r['created_at']) ?></span></span>
    <?php if ($r['link']): ?><a class="btn btn-ghost btn-sm" href="<?= sanitize($r['link']) ?>"><?= t('Open') ?></a><?php endif; ?>
    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="done"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-ghost btn-sm"><?= t('Done') ?></button></form></div><?php endforeach; ?>
  <?php if (!$requests): ?><div class="list-group-item text-tx-3"><?= t('Nothing waiting.') ?></div><?php endif; ?></div></div>
<?php else: ?>
<div class="row g-3">
  <div class="col-lg-4"><div class="card-crm"><div class="list-group list-group-flush">
    <?php foreach ($chats as $c): ?><a class="list-group-item list-group-item-action <?= $c['to_from'] === $with ? 'active' : '' ?>" href="?with=<?= urlencode($c['to_from']) ?>">
      <div class="d-flex"><span class="fw-600"><?= sanitize((string)($c['who'] ?: $c['to_from'])) ?></span><span class="ms-auto fs-12"><?= timeSince($c['created_at']) ?></span></div>
      <div class="fs-12 text-truncate"><?= $c['direction'] === 'in' ? '' : '<i class="bi bi-reply"></i> ' ?><?= sanitize(mb_strimwidth($c['body'], 0, 60, '…')) ?></div></a><?php endforeach; ?>
    <?php if (!$chats): ?><div class="list-group-item text-tx-3 fs-13"><?= t('No conversations yet. Messages appear here once WhatsApp or SMS is connected.') ?></div><?php endif; ?>
  </div></div></div>
  <div class="col-lg-8"><?php if ($thread): $last = end($thread); ?>
    <div class="card-crm"><div class="card-header-crm"><span><?= sanitize($with) ?></span><?php if ($last['patient_id']): ?><a class="btn btn-ghost btn-sm ms-auto" href="/pages/patient.php?id=<?= (int)$last['patient_id'] ?>"><?= t('Patient') ?></a><?php elseif ($last['lead_id']): ?><a class="btn btn-ghost btn-sm ms-auto" href="/pages/lead_view.php?id=<?= (int)$last['lead_id'] ?>"><?= t('Lead') ?></a><?php endif; ?></div>
      <div class="card-body-crm" style="max-height:60vh;overflow:auto">
        <?php foreach ($thread as $m): ?><div class="d-flex mb-2 <?= $m['direction'] === 'out' ? 'justify-content-end' : '' ?>"><div class="p-2 rounded fs-14" style="max-width:75%;background:<?= $m['direction'] === 'out' ? '#f1e8da' : '#eef2f5' ?>">
          <div style="white-space:pre-wrap"><?= sanitize($m['body']) ?></div><div class="fs-12 text-tx-3 mt-1"><?= MSG_CHANNELS[$m['channel']] ?> · <?= date('d M H:i', strtotime($m['created_at'])) ?><?= $m['direction'] === 'out' ? ' · ' . sanitize($m['status']) . ($m['staff'] ? ' · ' . sanitize($m['staff']) : ($m['automation_key'] ? ' · automatic' : '')) : '' ?></div></div></div><?php endforeach; ?>
      </div>
      <?php if (can('messages.send')): ?><form method="POST" class="card-body-crm border-top d-flex gap-2"><?= csrfField() ?><input type="hidden" name="action" value="reply">
        <label class="visually-hidden" for="rt"><?= t('Reply') ?></label><textarea class="form-control" id="rt" name="text" rows="2" maxlength="1000" required placeholder="<?= t('Reply…') ?>"></textarea><button class="btn btn-gold"><?= t('Send') ?></button></form><?php endif; ?>
    </div>
  <?php else: ?><div class="card-crm"><div class="empty-state"><i class="bi bi-chat-dots"></i><div class="empty-state-title"><?= t('Choose a conversation') ?></div></div></div><?php endif; ?></div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
