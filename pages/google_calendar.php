<?php
// pages/google_calendar.php — each staff member connects their own Google Calendar so the appointments assigned to
// them appear there. Admins (setup.gcal) also enter the Google app's client ID/secret and see who is connected.
// Google sends people back here after sign-in (?code=…&state=…), so this address is the OAuth redirect URI.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventory.php';     // CSRF helpers + gcal.php
requireLogin();

$db = getDB();
ensureGcalSchema($db);
$me = (int)currentStaffId();
$say = fn(string $type, string $msg) => $_SESSION['gcal_flash'] = compact('type', 'msg');

// ── Back from Google ──
if (isset($_GET['code']) || isset($_GET['error'])) {
    $expected = $_SESSION['gcal_state'] ?? '';
    unset($_SESSION['gcal_state']);
    if (isset($_GET['error'])) {
        $say('warning', 'Google Calendar wasn’t connected (' . ($_GET['error'] === 'access_denied' ? 'you cancelled on Google’s screen' : (string)$_GET['error']) . ').');
    } elseif ($expected === '' || !hash_equals($expected, (string)($_GET['state'] ?? ''))) {
        $say('danger', 'That sign-in link has expired. Click “Connect Google Calendar” again.');
    } else {
        try {
            $email = gcalConnect($db, $me, (string)$_GET['code']);
            $n = gcalBackfill($db, $me);
            logActivity($me, 'gcal_connected', null, null, $email);
            $say('success', 'Connected' . ($email ? " to $email" : '') . '. ' . ($n ? "$n upcoming appointment" . ($n == 1 ? '' : 's') . ' added to your calendar.' : 'New appointments assigned to you will appear in your calendar.'));
        } catch (Throwable $e) {
            $say('danger', 'Couldn’t connect: ' . $e->getMessage());
        }
    }
    header('Location: /pages/google_calendar.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = $_POST['action'] ?? '';
    try {
        if ($act === 'connect') {
            if (!gcalConfigured($db)) throw new RuntimeException('An admin needs to add the Google app details first.');
            $_SESSION['gcal_state'] = bin2hex(random_bytes(16));
            header('Location: ' . gcalAuthUrl($db, $_SESSION['gcal_state'])); exit;
        } elseif ($act === 'disconnect') {
            gcalDisconnect($db, $me);
            logActivity($me, 'gcal_disconnected', null, null, null);
            $say('success', 'Disconnected. Events already in your Google Calendar stay there; new bookings won’t be added.');
        } elseif ($act === 'resync') {
            $n = gcalBackfill($db, $me);
            $left = $db->prepare("SELECT COUNT(*) FROM appointment_gcal g JOIN appointments a ON a.id = g.appointment_id WHERE g.pending = 1 AND a.practitioner_id = ?");
            $left->execute([$me]);
            $failing = (int)$left->fetchColumn();
            $say($failing ? 'warning' : 'success', "Checked $n upcoming appointment" . ($n == 1 ? '' : 's') . '.' . ($failing ? " $failing couldn’t be updated; see below." : ''));
        } elseif ($act === 'settings' && can('setup.gcal')) {
            $id = trim($_POST['client_id'] ?? '');
            if ($id !== '' && !preg_match('/^[\w.-]+\.apps\.googleusercontent\.com$/', $id)) throw new RuntimeException('The client ID ends in “.apps.googleusercontent.com”. Copy it again from Google Cloud.');
            if ($id !== '') gcalSaveSetting($db, 'client_id', $id);
            if (trim($_POST['client_secret'] ?? '') !== '') gcalSaveSetting($db, 'client_secret', trim($_POST['client_secret']));
            gcalSaveSetting($db, 'crm_host', (string)($_SERVER['HTTP_HOST'] ?? ''));
            logActivity($me, 'gcal_settings_changed', null, null, null);
            $say('success', gcalConfigured($db) ? 'Saved. Staff can now connect their Google Calendars here.' : 'Saved. Add both the client ID and the client secret.');
        }
    } catch (Throwable $e) {
        $say('danger', $e->getMessage());
    }
    header('Location: /pages/google_calendar.php'); exit;
}

$flash = $_SESSION['gcal_flash'] ?? null;
unset($_SESSION['gcal_flash']);
$mine = gcalStaff($db, $me);
$myProblems = $db->prepare("SELECT a.id, a.scheduled_at, COALESCE(l.name, CONCAT('Appointment #', a.id)) AS name, g.error FROM appointment_gcal g JOIN appointments a ON a.id = g.appointment_id LEFT JOIN leads l ON l.id = a.lead_id
                            WHERE g.pending = 1 AND a.practitioner_id = ? AND a.scheduled_at >= CURDATE() ORDER BY a.scheduled_at LIMIT 20");
$myProblems->execute([$me]);
$myProblems = $myProblems->fetchAll();
$myUpcoming = $db->prepare("SELECT COUNT(*) FROM appointments WHERE practitioner_id = ? AND status IN ('booked','confirmed','arrived','in_triage','with_doctor') AND scheduled_at >= NOW()");
$myUpcoming->execute([$me]);
$myUpcoming = (int)$myUpcoming->fetchColumn();
$isAdmin = can('setup.gcal');
$team = $isAdmin ? $db->query("SELECT s.id, s.name, s.role, g.google_email, g.connected_at, g.last_error,
        (SELECT COUNT(*) FROM appointment_gcal x JOIN appointments a ON a.id = x.appointment_id WHERE x.pending = 1 AND a.practitioner_id = s.id AND a.scheduled_at >= CURDATE()) AS failing
        FROM staff s LEFT JOIN staff_google g ON g.staff_id = s.id WHERE s.is_active = 1 ORDER BY g.staff_id IS NULL, s.name")->fetchAll() : [];

$pageTitle    = 'Google Calendar';
$pageSubtitle = $mine ? 'Connected to ' . sanitize($mine['google_email'] ?: 'your Google account') : 'Not connected';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] ?>"><i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i><div><strong><?= sanitize($flash['msg']) ?></strong></div></div>
<?php endif; ?>

<div class="row g-3 page-section">
  <div class="col-xl-7 d-flex flex-column gap-3">
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-calendar2-week"></i> My Google Calendar</span></div>
      <div class="card-body-crm">
        <p class="fs-13 text-tx-2">Connect your own Google account and every appointment where <strong>you are the practitioner</strong> appears in your Google Calendar: added when booked, moved when rescheduled, removed when cancelled or given to someone else. Keep booking and changing appointments here in the CRM; changes made in Google aren’t copied back.</p>
        <?php if ($mine): ?>
          <p class="mb-2"><i class="bi bi-check-circle-fill text-green"></i> Connected to <strong><?= sanitize($mine['google_email'] ?: 'your Google account') ?></strong> since <?= formatDate($mine['connected_at']) ?>. <?= $myUpcoming ?> upcoming appointment<?= $myUpcoming == 1 ? '' : 's' ?> assigned to you.</p>
          <?php if ($mine['last_error']): ?><div class="alert alert-danger mb-2"><i class="bi bi-exclamation-triangle"></i><?= sanitize($mine['last_error']) ?></div><?php endif; ?>
          <p class="fs-12 text-tx-3 mb-0">Events show as “Appointment #123 – Name I.” with a link to the CRM. Treatment, phone and notes stay in the CRM for patient privacy.</p>
        <?php elseif (!gcalConfigured($db)): ?>
          <p class="text-tx-3 mb-0"><i class="bi bi-hourglass"></i> Not available yet: an admin needs to set up the Google app first<?= $isAdmin ? ' (below)' : '' ?>.</p>
        <?php else: ?>
          <p class="fs-13 mb-0">You’ll sign in with Google and allow the CRM to manage events in your calendar. Google may say the app isn’t verified: it’s the clinic’s own app, so choose <em>Advanced → Go to …</em> to continue.</p>
        <?php endif; ?>
      </div>
      <?php if (gcalConfigured($db)): ?>
      <form method="POST" class="form-actions">
        <?= csrfField() ?>
        <?php if ($mine): ?>
          <button class="btn btn-ghost me-auto" name="action" value="disconnect" data-confirm="Disconnect your Google Calendar? Events already there stay; new bookings won’t be added."><i class="bi bi-x-circle"></i>Disconnect</button>
          <button class="btn btn-ghost" name="action" value="resync"><i class="bi bi-arrow-repeat"></i>Update my calendar now</button>
          <?php if ($mine['last_error']): ?><button class="btn btn-gold" name="action" value="connect"><i class="bi bi-google"></i>Connect again</button><?php endif; ?>
        <?php else: ?>
          <button class="btn btn-gold ms-auto" name="action" value="connect"><i class="bi bi-google"></i>Connect Google Calendar</button>
        <?php endif; ?>
      </form>
      <?php endif; ?>
    </div>

    <?php if ($myProblems): ?>
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-exclamation-triangle"></i> Not yet in your Google Calendar</span><span class="card-header-sub">Retried every hour</span></div>
      <div class="table-responsive"><table class="table">
        <thead><tr><th>Appointment</th><th>When</th><th>Problem</th></tr></thead>
        <tbody>
        <?php foreach ($myProblems as $p): ?>
        <tr><td><a href="/pages/appointment.php?id=<?= (int)$p['id'] ?>" class="cell-title">#<?= (int)$p['id'] ?> – <?= sanitize($p['name']) ?></a></td>
            <td class="fs-13 text-nowrap"><?= formatDate($p['scheduled_at']) ?></td>
            <td class="fs-12 text-red"><?= sanitize($p['error'] ?? 'Waiting') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-people"></i> Team</span><span class="card-header-sub">Only practitioners’ calendars receive appointments</span></div>
      <div class="table-responsive"><table class="table">
        <thead><tr><th>Staff</th><th>Google Calendar</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($team as $t): ?>
        <tr><td><?= sanitize($t['name']) ?> <span class="fs-12 text-tx-3"><?= sanitize(ucfirst($t['role'])) ?></span></td>
            <td class="fs-13"><?php if ($t['connected_at']): ?><i class="bi bi-check-circle-fill text-green"></i> <?= sanitize($t['google_email'] ?: 'Connected') ?><?php else: ?><span class="text-tx-3">Not connected</span><?php endif; ?></td>
            <td class="fs-12"><?php if ($t['last_error']): ?><span class="text-red">Needs to connect again</span><?php elseif ($t['failing']): ?><span class="text-red"><?= (int)$t['failing'] ?> not updated</span><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($isAdmin): ?>
  <div class="col-xl-5">
    <form method="POST" class="card-crm" autocomplete="off">
      <?= csrfField() ?><input type="hidden" name="action" value="settings">
      <div class="card-header-crm"><span><i class="bi bi-gear"></i> Google app (admin)</span></div>
      <div class="card-body-crm">
        <p class="fs-13 text-tx-2">Set up once in Google Cloud: a project with the <strong>Google Calendar API</strong> on, and an OAuth client of type <strong>Web application</strong> with this redirect URI:</p>
        <div class="input-group mb-3"><input type="text" class="form-control font-monospace fs-12" value="<?= sanitize(gcalRedirectUri()) ?>" readonly><button class="btn btn-ghost" type="button" onclick="navigator.clipboard?.writeText(this.previousElementSibling.value)" title="Copy"><i class="bi bi-copy"></i></button></div>
        <label class="form-label" for="gId">Client ID</label>
        <input class="form-control mb-3 font-monospace fs-12" id="gId" name="client_id" value="<?= sanitize(gcalSetting($db, 'client_id')) ?>" placeholder="…apps.googleusercontent.com">
        <label class="form-label" for="gSecret">Client secret</label>
        <input class="form-control font-monospace fs-12" id="gSecret" name="client_secret" type="password" autocomplete="new-password" placeholder="<?= gcalSetting($db, 'client_secret') !== '' ? 'Saved – paste a new one to change it' : 'From the same OAuth client' ?>">
        <p class="fs-12 text-tx-3 mt-2 mb-0">Publish the app (OAuth consent screen → <em>In production</em>); in “Testing” Google cuts every connection after 7 days.</p>
      </div>
      <div class="form-actions"><button class="btn btn-gold ms-auto"><i class="bi bi-check-lg"></i>Save</button></div>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
