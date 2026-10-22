<?php
// pages/message_templates.php — Admin → Messaging → Templates: the text of each automatic message per channel and
// language, the matching WhatsApp template name and its approval status at Meta. Fields: {first_name} {date} {time}
// {branch} {map_link} {link} {clinic}. {service} {practitioner} {amount} are health information: only in templates the
// clinic owner has cleared, with an acknowledgement that is audit-logged.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/messaging.php';
requirePermission('setup.messaging');

$db = getDB();
ensureDefaultTemplates($db);
$owner = (bool)$db->query("SELECT is_owner FROM staff WHERE id = " . currentStaffId())->fetchColumn();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try { $id = saveTemplate($db, (int)($_POST['id'] ?? 0), $_POST); $_SESSION['setup_flash'] = ['success', 'Template saved.']; header("Location: /pages/message_templates.php#t$id"); exit; }
    catch (Throwable $e) { $_SESSION['setup_flash'] = ['danger', $e->getMessage()]; header('Location: /pages/message_templates.php?' . (empty($_POST['id']) ? 'new=1' : 'id=' . (int)$_POST['id'])); exit; }
}
$flash = $_SESSION['setup_flash'] ?? null;
unset($_SESSION['setup_flash']);
$edit = isset($_GET['new']) ? ['id' => 0, 'channel' => 'whatsapp', 'purpose' => 'custom', 'name' => '', 'language' => 'en', 'subject' => '', 'body' => '', 'wa_template_name' => '', 'wa_status' => 'none', 'contains_health_info' => 0, 'is_active' => 1]
      : (isset($_GET['id']) ? findTemplate($db, (int)$_GET['id']) : null);
$rows = $db->query("SELECT t.*, s.name AS ack_name FROM message_templates t LEFT JOIN staff s ON s.id = t.health_ack_by ORDER BY FIELD(t.purpose, 'confirmation','reminder_24h','reminder_2h','cancelled','feedback','package_expiry','birthday','custom'), t.channel, t.language")->fetchAll();

$pageTitle = 'Message templates';
$pageBack = ['href' => '/pages/messaging_settings.php', 'label' => 'Messaging'];
$pageActions = $edit ? '' : '<a class="btn btn-gold" href="?new=1"><i class="bi bi-plus-lg"></i>New template</a>';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?> <?= $flash[0] === 'success' ? 'alert-auto' : '' ?>"><i class="bi bi-info-circle"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<?php if ($edit): ?>
<form method="POST" class="card-crm" style="max-width:820px"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
  <div class="card-body-crm"><div class="row g-2">
    <div class="col-md-4"><label class="form-label" for="tc">Channel</label><select class="form-select" id="tc" name="channel"><?php foreach (MSG_CHANNELS as $k => $l): ?><option value="<?= $k ?>" <?= $edit['channel'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-md-5"><label class="form-label" for="tp">Used for</label><select class="form-select" id="tp" name="purpose"><?php foreach (MSG_PURPOSES as $k => $l): ?><option value="<?= $k ?>" <?= $edit['purpose'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label" for="tl">Language</label><select class="form-select" id="tl" name="language"><option value="en">English</option><option value="ar" <?= $edit['language'] === 'ar' ? 'selected' : '' ?>>Arabic</option></select></div>
    <div class="col-md-6"><label class="form-label" for="tn">Name</label><input class="form-control" id="tn" name="name" maxlength="100" required value="<?= sanitize($edit['name']) ?>"></div>
    <div class="col-md-6"><label class="form-label" for="ts">Email subject</label><input class="form-control" id="ts" name="subject" maxlength="150" value="<?= sanitize((string)$edit['subject']) ?>"></div>
    <div class="col-12"><label class="form-label" for="tb">Text</label><textarea class="form-control" id="tb" name="body" rows="4" required <?= $edit['language'] === 'ar' ? 'dir="rtl"' : '' ?>><?= sanitize($edit['body']) ?></textarea>
      <div class="fs-12 text-tx-3 mt-1">Fields: {first_name} {date} {time} {branch} {map_link} {link} {clinic}. Health information (owner only): {service} {practitioner} {amount}.</div></div>
    <div class="col-md-6"><label class="form-label" for="tw">WhatsApp template name at Meta</label><input class="form-control" id="tw" name="wa_template_name" maxlength="100" value="<?= sanitize((string)$edit['wa_template_name']) ?>"></div>
    <div class="col-md-6"><label class="form-label" for="twa">Meta approval</label><select class="form-select" id="twa" name="wa_status"><?php foreach (['none' => 'Not sent to Meta', 'pending' => 'Waiting for Meta', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $k => $l): ?><option value="<?= $k ?>" <?= $edit['wa_status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  </div>
  <fieldset class="mt-3 p-2 border rounded"><legend class="form-label px-1">Health information</legend>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="th" name="contains_health_info" value="1" <?= $edit['contains_health_info'] ? 'checked' : '' ?> <?= !$owner && !$edit['contains_health_info'] ? 'disabled' : '' ?>><label class="form-check-label" for="th">This template contains health information (treatment, practitioner or amounts)</label></div>
    <?php if (!$edit['contains_health_info']): ?>
      <?php if ($owner): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="tha" name="health_ack" value="1"><label class="form-check-label" for="tha">I am the clinic owner and I understand this sends health information through a provider outside the UAE (WhatsApp, SMS or email).</label></div>
      <?php else: ?><div class="fs-12 text-tx-3">Only the clinic owner can switch this on.</div><?php endif; ?>
    <?php endif; ?>
  </fieldset>
  <div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" role="switch" id="tact" name="is_active" value="1" <?= $edit['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="tact">In use</label></div>
  </div><div class="form-actions"><a class="btn btn-ghost" href="/pages/message_templates.php">Cancel</a><button class="btn btn-gold ms-auto">Save</button></div></form>
<?php else: ?>
<div class="card-crm"><div class="table-responsive"><table class="table mb-0 align-middle"><thead><tr><th>Used for</th><th>Channel</th><th>Text</th><th>WhatsApp</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $t): ?><tr id="t<?= (int)$t['id'] ?>" class="<?= $t['is_active'] ? '' : 'text-tx-3' ?>"><td><?= MSG_PURPOSES[$t['purpose']] ?? sanitize($t['purpose']) ?><span class="cell-sub"><?= strtoupper($t['language']) ?></span></td>
    <td><?= MSG_CHANNELS[$t['channel']] ?></td><td class="fs-13" <?= $t['language'] === 'ar' ? 'dir="rtl"' : '' ?>><?= sanitize(mb_strimwidth($t['body'], 0, 110, '…')) ?><?= $t['contains_health_info'] ? '<div class="fs-12 text-danger" dir="ltr"><i class="bi bi-shield-exclamation"></i> Health information · cleared by ' . sanitize((string)$t['ack_name']) . '</div>' : '' ?></td>
    <td class="fs-13"><?= $t['channel'] === 'whatsapp' ? sanitize((string)$t['wa_template_name']) . '<span class="cell-sub">' . ['none' => 'not sent', 'pending' => 'waiting', 'approved' => 'approved', 'rejected' => 'rejected'][$t['wa_status']] . '</span>' : '' ?></td>
    <td><a class="btn btn-ghost btn-sm" href="?id=<?= (int)$t['id'] ?>">Edit</a></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
