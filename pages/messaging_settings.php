<?php
// pages/messaging_settings.php — Admin → Messaging: the WhatsApp Cloud API connection (the token and app secret are
// stored encrypted; blank keeps the saved one; a value in the config file wins), the SMS provider (only the local test
// service until one is chosen), the automations' switches, quiet hours, the cancel cut-off for patient links, and the
// addresses used in messages.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/messaging.php';
requirePermission('setup.messaging');

$db = getDB();
ensureDefaultTemplates($db);
if (msgSetting($db, 'base_url') === '' && isset($_SERVER['HTTP_HOST'])) msgSaveSetting($db, 'base_url', appUrl());
if (msgSetting($db, 'wa_verify_token') === '') msgSaveSetting($db, 'wa_verify_token', bin2hex(random_bytes(12)));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        if (($_POST['action'] ?? '') === 'whatsapp') {
            msgSaveSetting($db, 'wa_enabled', empty($_POST['wa_enabled']) ? '0' : '1');
            msgSaveSetting($db, 'wa_phone_number_id', preg_replace('/\D/', '', (string)($_POST['wa_phone_number_id'] ?? '')));
            msgSaveSetting($db, 'wa_business_number', mb_substr(trim((string)($_POST['wa_business_number'] ?? '')), 0, 30));
            if (trim((string)($_POST['wa_token'] ?? '')) !== '') msgSaveSetting($db, 'wa_token', trim($_POST['wa_token']), true);
            if (trim((string)($_POST['wa_app_secret'] ?? '')) !== '') msgSaveSetting($db, 'wa_app_secret', trim($_POST['wa_app_secret']), true);
            if (APP_ENV === 'local' && isset($_POST['wa_graph_base'])) msgSaveSetting($db, 'wa_graph_base', trim($_POST['wa_graph_base']) ?: 'https://graph.facebook.com');
            if (!empty($_POST['wa_enabled']) && !waReady($db)) throw new RuntimeException('Saved, but WhatsApp stays off until the phone number ID and access token are filled in.');
            auditLog($db, 'setting', 0, 'whatsapp_settings', ['enabled' => !empty($_POST['wa_enabled'])]);
        } elseif (($_POST['action'] ?? '') === 'sms') {
            $prov = in_array($_POST['sms_provider'] ?? '', APP_ENV === 'local' ? ['none', 'mock'] : ['none'], true) ? $_POST['sms_provider'] : 'none';
            msgSaveSetting($db, 'sms_provider', $prov);
        } elseif (($_POST['action'] ?? '') === 'booking') {
            msgSaveSetting($db, 'online_booking', empty($_POST['online_booking']) ? '0' : '1');
            msgSaveSetting($db, 'booking_days_ahead', (string)max(1, min(180, (int)($_POST['booking_days_ahead'] ?? 30))));
            msgSaveSetting($db, 'booking_notice_hours', (string)max(0, min(72, (int)($_POST['booking_notice_hours'] ?? 2))));
            auditLog($db, 'setting', 0, 'online_booking', ['on' => !empty($_POST['online_booking'])]);
            if (!empty($_POST['online_booking']) && !waReady($db) && !smsReady($db)) throw new RuntimeException('Saved, but patients can’t get their code until WhatsApp (with an approved one-time-code template) or SMS is set up.');
        } elseif (($_POST['action'] ?? '') === 'automations') {
            foreach (AUTOMATIONS as $k => [, , $def]) {
                $cfg = $def;
                if ($k === 'feedback') $cfg['hours_after'] = max(1, min(72, (int)($_POST['feedback_hours'] ?? 3)));
                $db->prepare("REPLACE INTO automations (`key`, is_active, config) VALUES (?, ?, ?)")->execute([$k, empty($_POST['auto'][$k]) ? 0 : 1, json_encode($cfg ?: new stdClass())]);
            }
            foreach (['quiet_from', 'quiet_to'] as $k) { $v = (string)($_POST[$k] ?? ''); if (!preg_match('/^\d{2}:\d{2}$/', $v)) throw new RuntimeException('Quiet hours are times like 21:00.'); msgSaveSetting($db, $k, $v); }
            msgSaveSetting($db, 'cancel_cutoff_hours', (string)max(0, min(168, (int)($_POST['cancel_cutoff_hours'] ?? 24))));
            foreach (['map_link', 'booking_link', 'google_review_url', 'base_url'] as $k) {
                $v = trim((string)($_POST[$k] ?? ''));
                if ($v !== '' && !preg_match('#^https?://[^\s<>"]+$#', $v)) throw new RuntimeException('Links must start with https://');
                msgSaveSetting($db, $k, rtrim($v, $k === 'base_url' ? '/' : ''));
            }
            auditLog($db, 'setting', 0, 'automations', array_keys(array_filter((array)($_POST['auto'] ?? []))));
        }
        $_SESSION['setup_flash'] = ['success', 'Saved.'];
    } catch (Throwable $e) { $_SESSION['setup_flash'] = ['danger', $e->getMessage()]; }
    header('Location: /pages/messaging_settings.php'); exit;
}
$flash = $_SESSION['setup_flash'] ?? null;
unset($_SESSION['setup_flash']);
$wa = waConfig($db);
$auto = $db->query("SELECT `key`, is_active, config FROM automations")->fetchAll(PDO::FETCH_UNIQUE);
$fbHours = (int)(json_decode((string)($auto['feedback']['config'] ?? '{}'), true)['hours_after'] ?? 3);
$sent7 = $db->query("SELECT channel, status, COUNT(*) FROM messages WHERE direction = 'out' AND created_at > NOW() - INTERVAL 7 DAY GROUP BY channel, status")->fetchAll(PDO::FETCH_NUM);

$pageTitle = 'Messaging';
$pageSubtitle = 'WhatsApp, SMS and the automatic messages to patients';
$pageBack = ['href' => '/pages/settings_clinic.php', 'label' => 'Clinic setup'];
$pageActions = '<a class="btn btn-ghost" href="/pages/message_templates.php"><i class="bi bi-chat-square-text"></i>Templates</a>';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?> <?= $flash[0] === 'success' ? 'alert-auto' : '' ?>"><i class="bi bi-info-circle"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<?php if ($sent7): ?><div class="d-flex flex-wrap gap-2 mb-3"><span class="fs-13 text-tx-3">Last 7 days:</span><?php foreach ($sent7 as [$ch, $st, $n]): ?><span class="chip <?= in_array($st, ['failed', 'skipped'], true) ? 'tone-amber' : 'tone-slate' ?>"><?= MSG_CHANNELS[$ch] ?> <?= sanitize($st) ?>: <?= (int)$n ?></span><?php endforeach; ?></div><?php endif; ?>
<div class="row g-3">
  <div class="col-xl-6">
    <form method="POST" class="card-crm mb-3" autocomplete="off"><?= csrfField() ?><input type="hidden" name="action" value="whatsapp">
      <div class="card-header-crm"><span><i class="bi bi-whatsapp"></i> WhatsApp Cloud API</span><span class="ms-auto chip <?= waReady($db) ? 'tone-green' : 'tone-slate' ?>"><?= waReady($db) ? 'Connected' : 'Off' ?></span></div>
      <div class="card-body-crm">
        <p class="fs-13 text-tx-3">From Meta Business → WhatsApp Manager / the Meta app. Messages the clinic starts must use templates approved by Meta with the same names as here (Templates page).</p>
        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="we" name="wa_enabled" value="1" <?= msgSetting($db, 'wa_enabled') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="we">Send WhatsApp messages</label></div>
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label" for="wp">Phone number ID</label><input class="form-control" id="wp" name="wa_phone_number_id" value="<?= sanitize($wa['phone_number_id']) ?>" inputmode="numeric"></div>
          <div class="col-md-6"><label class="form-label" for="wn">Business number (shown to staff)</label><input class="form-control" id="wn" name="wa_business_number" value="<?= sanitize(msgSetting($db, 'wa_business_number')) ?>"></div>
          <div class="col-md-6"><label class="form-label" for="wt">Access token <span class="fw-normal text-tx-3"><?= $wa['token'] !== '' ? '(saved; leave empty to keep)' : '' ?></span></label><input class="form-control" type="password" id="wt" name="wa_token" autocomplete="new-password"></div>
          <div class="col-md-6"><label class="form-label" for="ws">App secret <span class="fw-normal text-tx-3"><?= $wa['app_secret'] !== '' ? '(saved; leave empty to keep)' : '' ?></span></label><input class="form-control" type="password" id="ws" name="wa_app_secret" autocomplete="new-password"></div>
          <?php if (APP_ENV === 'local'): ?><div class="col-12"><label class="form-label" for="wb">Graph address (local testing)</label><input class="form-control" id="wb" name="wa_graph_base" value="<?= sanitize($wa['base']) ?>"></div><?php endif; ?>
        </div>
        <div class="fs-13 mt-3 p-2 rounded" style="background:var(--surface-2,#f6f3ee)"><strong>Webhook for Meta:</strong><br>Callback URL: <code><?= sanitize(appUrl()) ?>/pages/api_wa_cloud.php</code><br>Verify token: <code><?= sanitize($wa['verify_token']) ?></code><br>Subscribe to: <em>messages</em>.</div>
      </div><div class="form-actions"><button class="btn btn-gold ms-auto">Save</button></div></form>
    <form method="POST" class="card-crm"><?= csrfField() ?><input type="hidden" name="action" value="sms">
      <div class="card-header-crm"><span><i class="bi bi-phone"></i> SMS</span></div><div class="card-body-crm">
        <p class="fs-13 text-tx-3">Used when a patient hasn’t opted in to WhatsApp. No provider is chosen yet; SMS stays off until one is set up.</p>
        <label class="form-label" for="sp">Provider</label><select class="form-select" id="sp" name="sms_provider"><option value="none">None (off)</option><?php if (APP_ENV === 'local'): ?><option value="mock" <?= smsProvider($db) === 'mock' ? 'selected' : '' ?>>Local test service</option><?php endif; ?></select>
      </div><div class="form-actions"><button class="btn btn-ghost ms-auto">Save</button></div></form>
    <form method="POST" class="card-crm mt-3"><?= csrfField() ?><input type="hidden" name="action" value="booking">
      <div class="card-header-crm"><span><i class="bi bi-globe"></i> Online booking</span><span class="ms-auto chip <?= msgSetting($db, 'online_booking') === '1' ? 'tone-green' : 'tone-slate' ?>"><?= msgSetting($db, 'online_booking') === '1' ? 'On' : 'Off' ?></span></div><div class="card-body-crm">
        <p class="fs-13 text-tx-3">Patients book at <code><?= sanitize(appUrl()) ?>/book.php</code>: only treatments ticked “Online” in the Treatment menu, with bookable practitioners who have a schedule. They confirm with a one-time code by WhatsApp or SMS.</p>
        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="ob" name="online_booking" value="1" <?= msgSetting($db, 'online_booking') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="ob">Online booking on</label></div>
        <div class="row g-2"><div class="col-6"><label class="form-label" for="bd">Days ahead</label><input class="form-control" type="number" min="1" max="180" id="bd" name="booking_days_ahead" value="<?= sanitize(msgSetting($db, 'booking_days_ahead', '30')) ?>"></div>
          <div class="col-6"><label class="form-label" for="bnh">Earliest, hours from now</label><input class="form-control" type="number" min="0" max="72" id="bnh" name="booking_notice_hours" value="<?= sanitize(msgSetting($db, 'booking_notice_hours', '2')) ?>"></div></div>
      </div><div class="form-actions"><button class="btn btn-ghost ms-auto">Save</button></div></form>
  </div>
  <div class="col-xl-6">
    <form method="POST" class="card-crm"><?= csrfField() ?><input type="hidden" name="action" value="automations">
      <div class="card-header-crm"><span><i class="bi bi-lightning-charge"></i> Automatic messages</span></div><div class="card-body-crm">
        <p class="fs-13 text-tx-3">Checked every 5 minutes. Each message is sent once; WhatsApp only to patients who opted in; birthday greetings only with marketing consent and never in quiet hours.</p>
        <?php foreach (AUTOMATIONS as $k => [$name]): ?><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="au_<?= $k ?>" name="auto[<?= $k ?>]" value="1" <?= !empty($auto[$k]['is_active']) ? 'checked' : '' ?>><label class="form-check-label" for="au_<?= $k ?>"><?= $name ?></label></div><?php endforeach; ?>
        <div class="row g-2 mt-2">
          <div class="col-4"><label class="form-label" for="fh">Feedback after (hours)</label><input class="form-control" type="number" min="1" max="72" id="fh" name="feedback_hours" value="<?= $fbHours ?>"></div>
          <div class="col-4"><label class="form-label" for="qf">Quiet from</label><input class="form-control" type="time" id="qf" name="quiet_from" value="<?= sanitize(msgSetting($db, 'quiet_from', '21:00')) ?>"></div>
          <div class="col-4"><label class="form-label" for="qt">Quiet until</label><input class="form-control" type="time" id="qt" name="quiet_to" value="<?= sanitize(msgSetting($db, 'quiet_to', '09:00')) ?>"></div>
          <div class="col-6"><label class="form-label" for="cc">Patients can cancel online until (hours before)</label><input class="form-control" type="number" min="0" max="168" id="cc" name="cancel_cutoff_hours" value="<?= sanitize(msgSetting($db, 'cancel_cutoff_hours', '24')) ?>"></div>
          <div class="col-6"><label class="form-label" for="ml">Map link</label><input class="form-control" id="ml" name="map_link" value="<?= sanitize(msgSetting($db, 'map_link')) ?>" placeholder="https://maps.app.goo.gl/…"></div>
          <div class="col-6"><label class="form-label" for="bl">Booking link (for package reminders)</label><input class="form-control" id="bl" name="booking_link" value="<?= sanitize(msgSetting($db, 'booking_link')) ?>"></div>
          <div class="col-6"><label class="form-label" for="gr">Google review link</label><input class="form-control" id="gr" name="google_review_url" value="<?= sanitize(msgSetting($db, 'google_review_url')) ?>"></div>
          <div class="col-12"><label class="form-label" for="bu">This system’s address (for links in messages)</label><input class="form-control" id="bu" name="base_url" value="<?= sanitize(msgSetting($db, 'base_url')) ?>"></div>
        </div>
      </div><div class="form-actions"><button class="btn btn-gold ms-auto">Save</button></div></form>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
