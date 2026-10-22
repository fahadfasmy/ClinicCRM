<?php
// pages/email_settings.php — Admin → Email: the clinic mailbox the CRM sends every email from, a test send,
// and a log of recent emails. See includes/mailer.php.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventory.php';     // CSRF helpers
require_once __DIR__ . '/../includes/mailer.php';
requirePermission('setup.email');

$db = getDB();
ensureMailSchema($db);
$flash = $_SESSION['mail_flash'] ?? null;
unset($_SESSION['mail_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $say = fn(string $type, string $msg) => $_SESSION['mail_flash'] = compact('type', 'msg');
    try {
        if (($_POST['action'] ?? '') === 'save') {
            $host = strtolower(trim($_POST['host'] ?? ''));
            $port = (int)($_POST['port'] ?? 0);
            $security = $_POST['security'] ?? '';
            $fromEmail = trim($_POST['from_email'] ?? '');
            $replyTo = trim($_POST['reply_to'] ?? '');
            if (!preg_match('/^[a-z0-9.-]+$/', $host)) throw new RuntimeException('Enter the mail server name, e.g. smtp.hostinger.com.');
            if ($port < 1 || $port > 65535) throw new RuntimeException('Enter a valid port, e.g. 465.');
            if (!in_array($security, ['ssl', 'tls', 'none'], true)) throw new RuntimeException('Choose the security type.');
            if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid “From” email address.');
            if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid reply-to address, or leave it empty.');
            mailSaveSetting($db, 'host', $host);
            mailSaveSetting($db, 'port', (string)$port);
            mailSaveSetting($db, 'security', $security);
            mailSaveSetting($db, 'username', trim($_POST['username'] ?? ''));
            mailSaveSetting($db, 'from_email', $fromEmail);
            mailSaveSetting($db, 'from_name', mb_substr(trim(str_replace(["\r", "\n"], ' ', $_POST['from_name'] ?? '')), 0, 100));
            mailSaveSetting($db, 'reply_to', $replyTo);
            if (($_POST['smtp_password'] ?? '') !== '') mailSaveSetting($db, 'password', $_POST['smtp_password']);
            logActivity(currentStaffId(), 'email_settings_changed', null, null, "$host:$port ($security) as " . trim($_POST['username'] ?? ''));
            $say('success', mailConfigured($db) ? 'Saved. Send a test email below to check it works.' : 'Saved. Add the mailbox password to start sending.');
        } elseif (($_POST['action'] ?? '') === 'test') {
            $to = trim($_POST['test_to'] ?? '');
            sendEmail($db, $to, 'Test email from Clinic CRM',
                '<p>Hello,</p><p>This is a test email from the Clinic CRM. If you can read this, email sending works.</p><p>Sent ' . date('d M Y, h:i A') . ' from ' . htmlspecialchars(mailSetting($db, 'from_email')) . '.</p>',
                '', 'Test email');
            logActivity(currentStaffId(), 'email_settings_changed', null, null, "Test email sent to $to");
            $say('success', "Test email sent to $to. Check the inbox, and the spam folder too.");
        }
    } catch (Throwable $e) {
        $say('danger', $e->getMessage());
    }
    header('Location: /pages/email_settings.php'); exit;
}

$m = fn($k) => mailSetting($db, $k);
$log = $db->query("SELECT e.*, l.name AS lead_name FROM email_log e LEFT JOIN leads l ON l.id = e.lead_id ORDER BY e.id DESC LIMIT 30")->fetchAll();
$counts = $db->query("SELECT status, COUNT(*) FROM email_log WHERE created_at >= NOW() - INTERVAL 30 DAY GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle    = 'Email';
$pageSubtitle = mailConfigured($db) ? 'Sending from ' . $m('from_email') . ' · ' . (int)($counts['sent'] ?? 0) . ' sent in the last 30 days' : 'Not set up yet';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] ?>"><i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i><div><strong><?= sanitize($flash['msg']) ?></strong></div></div>
<?php endif; ?>

<div class="row g-3 page-section">
  <div class="col-xl-7">
    <div class="card-crm h-100">
      <div class="card-header-crm"><span><i class="bi bi-clock-history"></i> Recent emails</span><span class="card-header-sub">Latest 30<?= !empty($counts['error']) ? ' · <span class="text-red">' . (int)$counts['error'] . ' failed in 30 days</span>' : '' ?></span></div>
      <?php if (!$log): ?>
        <div class="empty-state"><i class="bi bi-envelope"></i><div class="empty-state-title">No emails yet</div><p>Every email the CRM sends is listed here.</p></div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover">
          <thead><tr><th>When</th><th>To</th><th>What</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($log as $e): ?>
          <tr>
            <td class="text-nowrap fs-13"><?= date('d M, h:i A', strtotime($e['created_at'])) ?></td>
            <td class="fs-13"><?= sanitize($e['to_email']) ?><?php if ($e['lead_id']): ?><br><a href="/pages/lead_view.php?id=<?= (int)$e['lead_id'] ?>" class="fs-12"><?= sanitize($e['lead_name'] ?? 'Lead #' . $e['lead_id']) ?></a><?php endif; ?></td>
            <td class="fs-13"><?= sanitize($e['context'] ?: '—') ?><br><span class="fs-12 text-tx-3"><?= sanitize($e['subject']) ?></span></td>
            <td><?php if ($e['status'] === 'sent'): ?><span class="chip aging-fresh">Sent</span><?php else: ?><span class="chip aging-critical">Failed</span><div class="fs-12 text-red mt-1"><?= sanitize($e['error'] ?? '') ?></div><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-xl-5">
    <form method="POST" class="card-crm" autocomplete="off">
      <?= csrfField() ?><input type="hidden" name="action" value="save">
      <div class="card-header-crm"><span><i class="bi bi-envelope-at"></i> Clinic mailbox</span></div>
      <div class="card-body-crm">
        <p class="fs-13 text-tx-2">Every email the CRM sends (e.g. the call-back email to new leads) goes out from this mailbox. For Hostinger email keep the server and port as they are, and type the mailbox’s password.</p>
        <div class="row g-2 mb-3">
          <div class="col-7"><label class="form-label" for="eHost">Mail server (SMTP)</label><input class="form-control" id="eHost" name="host" value="<?= sanitize($m('host')) ?>" required></div>
          <div class="col-5"><label class="form-label" for="ePort">Port</label><input class="form-control" id="ePort" name="port" inputmode="numeric" value="<?= sanitize($m('port')) ?>" required></div>
        </div>
        <label class="form-label" for="eSec">Security</label>
        <select class="form-select mb-3" id="eSec" name="security">
          <?php foreach (['ssl' => 'SSL (port 465) – recommended', 'tls' => 'STARTTLS (port 587)', 'none' => 'None (not secure)'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= $m('security') === $v ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
        <label class="form-label" for="eUser">Username <span class="text-tx-3 fw-normal">(usually the full email address)</span></label>
        <input class="form-control mb-3" id="eUser" name="username" value="<?= sanitize($m('username')) ?>" autocomplete="off">
        <label class="form-label" for="ePass">Password</label>
        <input class="form-control mb-3" id="ePass" name="smtp_password" type="password" autocomplete="new-password"
               placeholder="<?= mailConfigured($db) ? 'Saved – type a new one to change it' : 'The mailbox password' ?>">
        <hr>
        <label class="form-label" for="eFrom">“From” email address</label>
        <input class="form-control mb-3" id="eFrom" name="from_email" type="email" value="<?= sanitize($m('from_email')) ?>" required>
        <label class="form-label" for="eName">“From” name</label>
        <input class="form-control mb-3" id="eName" name="from_name" value="<?= sanitize($m('from_name')) ?>">
        <label class="form-label" for="eReply">Replies go to <span class="text-tx-3 fw-normal">(leave empty to use the “From” address)</span></label>
        <input class="form-control" id="eReply" name="reply_to" type="email" value="<?= sanitize($m('reply_to') === $m('from_email') ? '' : $m('reply_to')) ?>">
      </div>
      <div class="form-actions"><button class="btn btn-gold ms-auto"><i class="bi bi-check-lg"></i>Save</button></div>
    </form>

    <?php if (mailConfigured($db)): ?>
    <form method="POST" class="card-crm mt-3">
      <?= csrfField() ?><input type="hidden" name="action" value="test">
      <div class="card-body-crm d-flex gap-2 align-items-end">
        <div class="flex-grow-1"><label class="form-label" for="eTest">Send a test email to</label>
          <input class="form-control" id="eTest" name="test_to" type="email" required placeholder="you@example.com"></div>
        <button class="btn btn-ghost"><i class="bi bi-send"></i>Send test</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
