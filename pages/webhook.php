<?php
// pages/webhook.php — Meta Leads: connect Meta Lead Ads, import leads, and send lead stages back to Meta.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventory.php';     // CSRF helpers
require_once __DIR__ . '/../includes/meta_leads.php';
requirePermission('setup.webhook');

$db = getDB();
ensureMetaSchema($db);
$flash = $_SESSION['meta_flash'] ?? null;
unset($_SESSION['meta_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = $_POST['action'] ?? '';
    $say = fn(string $type, string $msg, array $list = []) => $_SESSION['meta_flash'] = compact('type', 'msg', 'list');
    try {
        if ($act === 'connection') {
            metaSaveSetting($db, 'page_id', preg_replace('/\D/', '', $_POST['page_id'] ?? ''));
            foreach (['token', 'app_secret'] as $k) if (trim($_POST[$k] ?? '') !== '') metaSaveSetting($db, $k, trim($_POST[$k]));
            if (trim($_POST['verify_token'] ?? '') !== '') metaSaveSetting($db, 'verify_token', trim($_POST['verify_token']));
            logActivity(currentStaffId(), 'meta_settings_changed', null, null, 'Connection');
            $t = metaConfigured($db) ? metaTestConnection($db) : ['ok' => [], 'problems' => ['Add the page ID and access token.']];
            if (!$t['problems'] && $t['ok']) {
                metaSaveSetting($db, 'page_name', preg_replace('/^Facebook page: /', '', $t['ok'][0]));
                try { $t['ok'][] = metaSubscribePage($db); } catch (MetaApiException $e) { $t['problems'][] = 'Instant notifications: ' . $e->getMessage() . ' (the hourly check still imports leads)'; }
            }
            $say($t['problems'] ? 'warning' : 'success', $t['problems'] ? 'Saved, but the connection isn’t working yet:' : 'Saved and connected.', array_merge($t['ok'], $t['problems']));
        } elseif ($act === 'test') {
            $t = metaTestConnection($db);
            $say($t['problems'] ? 'warning' : 'success', $t['problems'] ? 'Some checks failed:' : 'Everything is working.', array_merge($t['ok'], $t['problems']));
        } elseif ($act === 'assign') {
            $a = $_POST['assign'] ?? '';
            metaSaveSetting($db, 'assign', in_array($a, ['', 'rr'], true) || ctype_digit($a) ? $a : '');
            $say('success', 'New Meta leads will be assigned as chosen.');
        } elseif ($act === 'import') {
            set_time_limit(600);
            $days = max(1, min(90, (int)($_POST['days'] ?? 30)));
            $r = metaPullLeads($db, time() - $days * 86400, 'import');
            sendQueuedLeadEmails($db);
            logActivity(currentStaffId(), 'meta_leads_imported', null, null, "Last $days days: {$r['created']} new, {$r['updated']} matched");
            $say($r['errors'] ? 'warning' : 'success', "Checked {$r['forms']} form" . ($r['forms'] == 1 ? '' : 's') . " for the last $days days: {$r['created']} new lead" . ($r['created'] == 1 ? '' : 's') . ", {$r['updated']} added to leads you already had, {$r['duplicate']} already in the CRM.", array_slice($r['errors'], 0, 8));
        } elseif ($act === 'poll') {
            $r = metaPollNow($db);
            sendQueuedLeadEmails($db);
            $say(!empty($r['errors']) ? 'warning' : 'success', isset($r['skipped']) ? 'Connect Meta first.' : "Checked now: {$r['created']} new, {$r['updated']} matched, {$r['duplicate']} already in the CRM.", array_slice($r['errors'] ?? [], 0, 8));
        } elseif ($act === 'capi') {
            metaSaveSetting($db, 'dataset_id', preg_replace('/\D/', '', $_POST['dataset_id'] ?? ''));
            if (trim($_POST['capi_token'] ?? '') !== '') metaSaveSetting($db, 'capi_token', trim($_POST['capi_token']));
            if (isset($_POST['clear_capi_token'])) metaSaveSetting($db, 'capi_token', '');
            metaSaveSetting($db, 'test_event_code', trim($_POST['test_event_code'] ?? ''));
            metaSaveSetting($db, 'capi_enabled', isset($_POST['capi_enabled']) && metaSetting($db, 'dataset_id') !== '' ? '1' : '0');
            logActivity(currentStaffId(), 'meta_settings_changed', null, null, 'Stage updates ' . (metaSetting($db, 'capi_enabled') === '1' ? 'on' : 'off'));
            $say('success', metaSetting($db, 'capi_enabled') === '1' ? 'Stage updates are on. They are sent every hour; use “Send now” to send straight away.' : 'Saved. Stage updates are off.');
        } elseif ($act === 'mail') {
            if (isset($_POST['mail_enabled']) && !mailConfigured($db)) throw new RuntimeException('Set up the clinic mailbox in Admin → Email first.');
            metaSaveSetting($db, 'mail_enabled', isset($_POST['mail_enabled']) ? '1' : '0');
            logActivity(currentStaffId(), 'meta_settings_changed', null, null, 'Lead emails ' . (metaSetting($db, 'mail_enabled') === '1' ? 'on' : 'off'));
            $say('success', metaSetting($db, 'mail_enabled') === '1' ? 'Saved. New Meta leads with an email address now get the call-back email.' : 'Saved. Lead emails are off.');
        } elseif ($act === 'mail_test') {
            $to = trim($_POST['test_to'] ?? '');
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address to send the test to.');
            // A sample of the real email, as a lead arriving right now would get it
            [$subject, $html, $text] = buildLeadEmail(['name' => 'Aisha Khan', 'phone' => '+971501234567'], ['HydraFacial'], false, new DateTimeImmutable());
            sendEmail($db, $to, '[TEST] ' . $subject, $html, $text, 'Call-back email (sample)');
            logActivity(currentStaffId(), 'meta_settings_changed', null, null, "Test lead email sent to $to");
            $say('success', "Test email sent to $to. Check the inbox (and the spam folder).");
        } elseif ($act === 'send') {
            $q = metaQueueStageEvents($db);
            $r = metaSendStageEvents($db);
            $say($r['error'] ? 'warning' : 'success', !empty($r['skipped']) ? 'Turn stage updates on and add the dataset ID first.'
                : (!empty($r['test']) ? "Sent {$r['sent']} test event" . ($r['sent'] == 1 ? '' : 's') . ' to Meta. Check Events Manager → Test Events. Nothing was used up: remove the test code to send for real.'
                : "Sent {$r['sent']} stage update" . ($r['sent'] == 1 ? '' : 's') . " to Meta" . ($r['failed'] ? ", {$r['failed']} failed" : '') . '.'), $r['error'] ? [$r['error']] : []);
        }
    } catch (Throwable $e) {
        $say('danger', $e->getMessage());
    }
    header('Location: /pages/webhook.php'); exit;
}

// ── Page data ──
$connected = metaConfigured($db);
$s = fn($k) => metaSetting($db, $k);
$logs = $db->query("SELECT w.id, w.status, w.via, w.lead_id, w.error_msg, w.received_at, l.name AS lead_name
                    FROM meta_webhook_log w LEFT JOIN leads l ON l.id = w.lead_id ORDER BY w.id DESC LIMIT 40")->fetchAll();
$week = $db->query("SELECT COUNT(*) FROM leads WHERE meta_lead_id IS NOT NULL AND meta_lead_id <> '' AND created_at >= NOW() - INTERVAL 7 DAY")->fetchColumn();
$linked = (int)$db->query("SELECT COUNT(*) FROM leads WHERE meta_lead_id IS NOT NULL AND meta_lead_id <> ''")->fetchColumn();
$errors24 = (int)$db->query("SELECT COUNT(*) FROM meta_webhook_log WHERE status = 'error' AND received_at >= NOW() - INTERVAL 1 DAY")->fetchColumn();
$capiCounts = $db->query("SELECT status, COUNT(*) FROM meta_capi_events GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$capiRecent = $db->query("SELECT e.*, l.name AS lead_name FROM meta_capi_events e LEFT JOIN leads l ON l.id = e.lead_id ORDER BY e.id DESC LIMIT 15")->fetchAll();
$lastPoll = json_decode($s('last_poll_result') ?: 'null', true);
$staff = $db->query("SELECT id, name, role FROM staff WHERE is_active = 1 ORDER BY name")->fetchAll();
$webhookUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com') . '/pages/meta_receiver.php';

$pageTitle    = 'Meta Leads';
$pageSubtitle = $connected ? 'Connected' . ($s('page_name') ? ' to ' . $s('page_name') : '') . ' · ' . $linked . ' leads linked to Meta' : 'Not connected yet';
$pageActions  = $connected ? '<form method="POST" class="d-inline">' . csrfField() . '<button class="btn btn-ghost" name="action" value="test"><i class="bi bi-plug"></i>Test connection</button>'
              . '<button class="btn btn-ghost" name="action" value="poll"><i class="bi bi-arrow-repeat"></i>Check for new leads</button></form>' : '';
require_once __DIR__ . '/../includes/header.php';
$viaLabel = ['webhook' => 'Instant', 'hourly' => 'Hourly check', 'import' => 'Import'];
$statusTone = ['processed' => 'aging-fresh', 'duplicate' => 'aging-due', 'error' => 'aging-critical', 'received' => '', 'queued' => 'aging-due', 'sent' => 'aging-fresh'];
?>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] ?>"><i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i><div><strong><?= sanitize($flash['msg']) ?></strong>
  <?php if ($flash['list']): ?><ul class="mb-0 mt-1"><?php foreach ($flash['list'] as $li): ?><li><?= sanitize($li) ?></li><?php endforeach; ?></ul><?php endif; ?></div></div>
<?php endif; ?>

<div class="row g-3 page-section">
  <?php foreach ([
    ['Connection', $connected ? 'On' : 'Off', 'bi-plug', $connected ? 'tone-green' : 'tone-slate', $connected ? ($s('page_name') ?: 'Page ' . $s('page_id')) : 'Set it up on the right'],
    ['Meta leads, last 7 days', number_format($week), 'bi-people', 'tone-blue', $linked . ' linked in total'],
    ['Last hourly check', $lastPoll ? timeSince(date('Y-m-d H:i:s', $lastPoll['at'])) : '—', 'bi-arrow-repeat', !empty($lastPoll['errors']) ? 'tone-amber' : 'tone-slate', $lastPoll ? ($lastPoll['created'] ?? 0) . ' new, ' . ($lastPoll['updated'] ?? 0) . ' matched' : 'Runs with the hourly cron job'],
    ['Stage updates to Meta', $s('capi_enabled') === '1' ? number_format($capiCounts['sent'] ?? 0) . ' sent' : 'Off', 'bi-send', ($capiCounts['error'] ?? 0) ? 'tone-red' : ($s('capi_enabled') === '1' ? 'tone-green' : 'tone-slate'),
      ($capiCounts['queued'] ?? 0) . ' waiting' . (($capiCounts['error'] ?? 0) ? ', ' . $capiCounts['error'] . ' failed' : '')],
  ] as [$label, $val, $icon, $tone, $sub]): ?>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-top"><div class="stat-label"><?= $label ?></div><span class="stat-icon <?= $tone ?>"><i class="bi <?= $icon ?>"></i></span></div>
      <div class="stat-value"><?= sanitize((string)$val) ?></div>
      <div class="fs-12 text-tx-3 mt-1"><?= sanitize($sub) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php if ($errors24): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i><?= $errors24 ?> lead<?= $errors24 == 1 ? '' : 's' ?> couldn’t be imported in the last 24 hours; the reason is in the list below. Leads that failed because Meta couldn’t be reached are picked up again by the hourly check.</div><?php endif; ?>

<div class="row g-3">
  <div class="col-xl-7 d-flex flex-column gap-3">
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-cloud-download"></i> Leads received from Meta</span><span class="card-header-sub">Latest 40</span></div>
      <?php if (!$logs): ?>
      <div class="empty-state"><i class="bi bi-inbox"></i><div class="empty-state-title">Nothing received yet</div><p>Once Meta is connected, every Lead Ads form submission appears here.</p></div>
      <?php else: ?>
      <div class="table-responsive"><table class="table">
        <thead><tr><th>When</th><th>How</th><th>Result</th><th>Lead</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
        <tr>
          <td class="text-nowrap fs-12 text-tx-2"><?= formatDate($log['received_at']) ?></td>
          <td class="fs-12 text-tx-2 text-nowrap"><?= $viaLabel[$log['via']] ?? sanitize($log['via']) ?></td>
          <td><span class="chip <?= $statusTone[$log['status']] ?? '' ?>"><?= ['processed' => 'Imported', 'duplicate' => 'Already in CRM', 'error' => 'Problem', 'received' => 'Received'][$log['status']] ?? $log['status'] ?></span></td>
          <td><?= $log['lead_name'] ? '<a href="/pages/lead_view.php?id=' . (int)$log['lead_id'] . '" class="cell-title">' . sanitize($log['lead_name']) . '</a>' : '<span class="text-tx-3">—</span>' ?></td>
          <td class="fs-12 <?= $log['status'] === 'error' ? 'text-red' : 'text-tx-3' ?>" style="max-width:260px"><div class="text-truncate" title="<?= sanitize($log['error_msg'] ?? '') ?>"><?= sanitize($log['error_msg'] ?? '') ?></div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>

    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-send"></i> Stage updates sent to Meta</span><span class="card-header-sub">Latest 15</span></div>
      <?php if (!$capiRecent): ?>
      <div class="empty-state"><i class="bi bi-arrow-left-right"></i><div class="empty-state-title">None yet</div><p>When a lead that came from Meta moves to Contacted, Qualified, Booked or Converted, the stage is sent to Meta and shows in Leads Center.</p></div>
      <?php else: ?>
      <div class="table-responsive"><table class="table">
        <thead><tr><th>Lead</th><th>Stage sent</th><th>Status</th><th>When</th></tr></thead>
        <tbody>
        <?php foreach ($capiRecent as $e): ?>
        <tr>
          <td><a class="cell-title" href="/pages/lead_view.php?id=<?= (int)$e['lead_id'] ?>"><?= sanitize($e['lead_name'] ?? '#' . $e['lead_id']) ?></a></td>
          <td><?= sanitize($e['event_name']) ?></td>
          <td><span class="chip <?= $statusTone[$e['status']] ?? '' ?>" title="<?= sanitize($e['error'] ?? '') ?>"><?= ['queued' => 'Waiting', 'sent' => 'Sent', 'error' => 'Failed'][$e['status']] ?></span><?php if ($e['error']): ?><div class="fs-12 text-red text-truncate" style="max-width:220px" title="<?= sanitize($e['error']) ?>"><?= sanitize($e['error']) ?></div><?php endif; ?></td>
          <td class="fs-12 text-tx-2 text-nowrap"><?= formatDate($e['sent_at'] ?? $e['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-xl-5 d-flex flex-column gap-3">
    <form method="POST" class="card-crm">
      <?= csrfField() ?><input type="hidden" name="action" value="connection">
      <div class="card-header-crm"><span><i class="bi bi-plug"></i> 1. Connect your Facebook page</span></div>
      <div class="card-body-crm">
        <label class="form-label" for="mPage">Facebook page ID</label>
        <input class="form-control mb-3" id="mPage" name="page_id" inputmode="numeric" value="<?= sanitize($s('page_id')) ?>" placeholder="e.g. 102345678901234">
        <label class="form-label" for="mToken">Access token <span class="text-tx-3 fw-normal">(system user, never expires)</span></label>
        <input class="form-control mb-3 font-monospace fs-12" id="mToken" name="token" type="password" autocomplete="off" placeholder="<?= $s('token') ? 'Saved ' . metaMask($s('token')) . ' — paste a new one to replace it' : 'Paste the token' ?>">
        <label class="form-label" for="mSecret">App secret <span class="text-tx-3 fw-normal">(proves deliveries come from Meta)</span></label>
        <input class="form-control mb-3 font-monospace fs-12" id="mSecret" name="app_secret" type="password" autocomplete="off" placeholder="<?= $s('app_secret') ? 'Saved ' . metaMask($s('app_secret')) . ' — paste a new one to replace it' : 'From the app’s Basic settings' ?>">
        <details class="fs-13">
          <summary class="text-tx-2" style="cursor:pointer">Webhook details for the Meta app</summary>
          <label class="form-label mt-2">Callback URL</label>
          <div class="input-group mb-2"><input type="text" class="form-control font-monospace fs-12" value="<?= sanitize($webhookUrl) ?>" readonly><button class="btn btn-ghost" type="button" onclick="copyVal(this)" title="Copy"><i class="bi bi-copy"></i></button></div>
          <label class="form-label" for="mVerify">Verify token</label>
          <div class="input-group"><input type="text" class="form-control font-monospace fs-12" id="mVerify" name="verify_token" value="<?= sanitize($s('verify_token')) ?>"><button class="btn btn-ghost" type="button" onclick="copyVal(this)" title="Copy"><i class="bi bi-copy"></i></button></div>
          <ol class="steps mt-2 mb-0">
            <li>Meta app → <strong>Webhooks</strong> → <em>Page</em>: paste the callback URL and verify token, then <strong>Verify and save</strong>.</li>
            <li>Subscribe to the <code>leadgen</code> field, and add your page under the app’s Lead Ads / page subscriptions.</li>
            <li>Send a test with Meta’s <strong>Lead Ads Testing Tool</strong>; it should appear on the left within seconds.</li>
          </ol>
        </details>
      </div>
      <div class="form-actions"><button class="btn btn-gold"><i class="bi bi-check-lg"></i>Save and test</button></div>
    </form>

    <?php if ($connected): ?>
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-person-check"></i> 2. New leads</span></div>
      <div class="card-body-crm">
        <form method="POST" class="d-flex gap-2 align-items-end mb-3">
          <?= csrfField() ?><input type="hidden" name="action" value="assign">
          <div class="flex-grow-1"><label class="form-label" for="mAssign">Assign new Meta leads to</label>
            <select class="form-select" id="mAssign" name="assign">
              <option value="" <?= $s('assign') === '' ? 'selected' : '' ?>>Nobody (team picks them up)</option>
              <option value="rr" <?= $s('assign') === 'rr' ? 'selected' : '' ?>>Consultants in turn (fewest new leads first)</option>
              <?php foreach ($staff as $st): ?><option value="<?= $st['id'] ?>" <?= $s('assign') === (string)$st['id'] ? 'selected' : '' ?>><?= sanitize($st['name']) ?></option><?php endforeach; ?>
            </select></div>
          <button class="btn btn-ghost">Save</button>
        </form>
        <form method="POST" class="d-flex gap-2 align-items-end">
          <?= csrfField() ?><input type="hidden" name="action" value="import">
          <div class="flex-grow-1"><label class="form-label" for="mDays">Import past leads from Meta</label>
            <select class="form-select" id="mDays" name="days"><?php foreach ([7, 30, 60, 90] as $d): ?><option value="<?= $d ?>" <?= $d === 90 ? 'selected' : '' ?>>Last <?= $d ?> days</option><?php endforeach; ?></select></div>
          <button class="btn btn-ghost" data-confirm="Import Meta leads from this period? Leads already in the CRM (same Meta id, phone or email) are linked, not duplicated."><i class="bi bi-cloud-download"></i>Import</button>
        </form>
        <p class="fs-12 text-tx-3 mt-2 mb-0">Meta keeps leads for 90 days. People you already have (same phone or email) are linked to Meta instead of added twice, and their form answers are added to the notes.</p>
      </div>
    </div>
    <?php endif; ?>

    <form method="POST" class="card-crm">
      <?= csrfField() ?><input type="hidden" name="action" value="capi">
      <div class="card-header-crm"><span><i class="bi bi-send"></i> 3. Send lead stages to Meta</span></div>
      <div class="card-body-crm">
        <p class="fs-13 text-tx-2">Meta’s Leads Center then shows each lead’s stage, and your ads can be set to find people who <em>book</em>, not just people who fill in forms (“conversion leads”).</p>
        <label class="form-label" for="mDataset">Dataset ID <span class="text-tx-3 fw-normal">(Events Manager → your CRM dataset)</span></label>
        <input class="form-control mb-3" id="mDataset" name="dataset_id" inputmode="numeric" value="<?= sanitize($s('dataset_id')) ?>" placeholder="e.g. 1234567890123456">
        <label class="form-label" for="mCapiToken">Dataset access token <span class="text-tx-3 fw-normal">(leave empty to use the token above)</span></label>
        <input class="form-control mb-1 font-monospace fs-12" id="mCapiToken" name="capi_token" type="password" autocomplete="off" placeholder="<?= $s('capi_token') ? 'Saved ' . metaMask($s('capi_token')) : 'Optional' ?>">
        <?php if ($s('capi_token')): ?><label class="form-check fs-12 mb-2"><input class="form-check-input" type="checkbox" name="clear_capi_token"> <span class="form-check-label">Remove it and use the token above</span></label><?php endif; ?>
        <label class="form-label mt-2" for="mTest">Test event code <span class="text-tx-3 fw-normal">(only while testing in Events Manager)</span></label>
        <input class="form-control mb-3" id="mTest" name="test_event_code" value="<?= sanitize($s('test_event_code')) ?>" placeholder="e.g. TEST12345">
        <label class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" role="switch" name="capi_enabled" <?= $s('capi_enabled') === '1' ? 'checked' : '' ?>>
          <span class="form-check-label">Send stage changes to Meta</span></label>
        <div class="fs-12 text-tx-3">Stages sent (once each, for leads that came from Meta):
          <?php foreach (META_STAGE_EVENTS as $stage => $ev): ?><span class="chip me-1"><?= sanitize($ev) ?></span><?php endforeach; ?></div>
        <?php $web = json_decode($s('last_web_lead') ?: 'null', true); ?>
        <div class="fs-12 text-tx-3 mt-3"><i class="bi bi-globe2"></i> Website enquiry forms also report each enquiry to their own pixel
          (<?= implode(', ', array_map(fn($p, $id) => sanitize($p) . ' → ' . $id, array_keys(metaWebPixels()), metaWebPixels())) ?>).
          <?php if ($web): ?>Last: <?= sanitize($web['page']) ?>, <?= timeSince(date('Y-m-d H:i:s', $web['at'])) ?>,
            <?= $web['error'] ? '<span class="text-red">' . sanitize($web['error']) . '</span>' : '<span class="text-green">accepted</span>' ?><?php else: ?>None yet.<?php endif; ?></div>
      </div>
      <div class="form-actions">
        <?php if ($s('capi_enabled') === '1'): ?><button class="btn btn-ghost me-auto" name="action" value="send" formnovalidate><i class="bi bi-send"></i>Send now</button><?php endif; ?>
        <button class="btn btn-gold"><i class="bi bi-check-lg"></i>Save</button>
      </div>
    </form>

    <?php ensureLeadEmailSchema($db); $mailCounts = $db->query("SELECT status, COUNT(*) FROM lead_emails WHERE created_at >= NOW() - INTERVAL 30 DAY GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
          $mailLast = json_decode($s('last_mail') ?: 'null', true); ?>
    <form method="POST" class="card-crm">
      <?= csrfField() ?><input type="hidden" name="action" value="mail">
      <div class="card-header-crm"><span><i class="bi bi-envelope"></i> 4. Call-back email to new leads</span></div>
      <div class="card-body-crm">
        <p class="fs-13 text-tx-2">New Meta leads who give an email address get a thank-you email straight away, saying when the team will call (calls 11:00 AM – 9:30 PM, UAE time). Leads older than 24 hours (catch-up imports) are never emailed.</p>
        <?php if (mailConfigured($db)): ?>
          <p class="fs-13 mb-3"><i class="bi bi-check-circle text-green"></i> Sent from <strong><?= sanitize(mailSetting($db, 'from_email')) ?></strong> <?php if (can('setup.email')): ?>· <a href="/pages/email_settings.php">Email settings</a><?php endif; ?></p>
        <?php else: ?>
          <p class="fs-13 mb-3 text-red"><i class="bi bi-exclamation-circle"></i> The clinic mailbox isn’t set up yet<?= can('setup.email') ? ': <a href="/pages/email_settings.php">set it up in Admin → Email</a>' : '' ?>.</p>
        <?php endif; ?>
        <label class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" role="switch" name="mail_enabled" <?= $s('mail_enabled') === '1' ? 'checked' : '' ?> <?= mailConfigured($db) ? '' : 'disabled' ?>>
          <span class="form-check-label">Send the call-back email to new Meta leads</span></label>
        <div class="fs-12 text-tx-3">Last 30 days: <?= (int)($mailCounts['sent'] ?? 0) ?> sent<?= !empty($mailCounts['error']) ? ', <span class="text-red">' . (int)$mailCounts['error'] . ' failed (retrying)</span>' : '' ?><?= !empty($mailCounts['expired']) ? ', ' . (int)$mailCounts['expired'] . ' given up after 24h' : '' ?>.
          <?php if (!empty($mailLast['error'])): ?><br><span class="text-red">Last error: <?= sanitize($mailLast['error']) ?></span><?php endif; ?></div>
      </div>
      <div class="form-actions">
        <button class="btn btn-gold ms-auto"><i class="bi bi-check-lg"></i>Save</button>
      </div>
    </form>
    <?php if (mailConfigured($db)): ?>
    <form method="POST" class="card-crm">
      <?= csrfField() ?><input type="hidden" name="action" value="mail_test">
      <div class="card-body-crm d-flex gap-2 align-items-end">
        <div class="flex-grow-1"><label class="form-label" for="mTestTo">Send a sample of this email to</label>
          <input class="form-control" id="mTestTo" name="test_to" type="email" required placeholder="you@example.com"></div>
        <button class="btn btn-ghost"><i class="bi bi-send"></i>Send sample</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php
$extraJs = "
function copyVal(btn) {
  const input = btn.parentElement.querySelector('input');
  navigator.clipboard?.writeText(input.value).then(() => { const i = btn.querySelector('i'); i.className = 'bi bi-check2'; setTimeout(() => i.className = 'bi bi-copy', 1200); });
}
";
require_once __DIR__ . '/../includes/footer.php';
