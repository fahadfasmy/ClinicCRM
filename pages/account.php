<?php
// pages/account.php — My account: change my password, backup codes for 2-step sign-in, trusted devices,
// recent sign-ins, and sign out everywhere else. Every staff member can use it for their own account only.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventory.php';     // CSRF helpers
require_once __DIR__ . '/../includes/security.php';
requireLogin();

$db = getDB();
ensureSecuritySchema($db);
$me = currentStaffId();
$load = function () use ($db, $me) { $s = $db->prepare("SELECT * FROM staff WHERE id = ?"); $s->execute([$me]); return $s->fetch(); };
$staff = $load();
$say = fn(string $type, string $msg) => $_SESSION['acct_flash'] = compact('type', 'msg');
$newCodes = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = $_POST['action'] ?? '';
    try {
        if ($act === 'password') {
            $cur = (string)($_POST['current_password'] ?? '');
            $pw = (string)($_POST['new_password'] ?? '');
            if (!password_verify($cur, $staff['password'])) {
                secLog($db, $me, $staff['email'], 'bad_password');
                throw new RuntimeException('Your current password isn’t right.');
            }
            $problems = secPasswordProblems($pw, $staff, $staff['password']);
            if ($pw !== (string)($_POST['confirm_password'] ?? '')) $problems[] = 'The two new passwords don’t match.';
            if ($problems) throw new RuntimeException(implode(' ', $problems));
            secSetPassword($db, $me, $pw);
            secSignOutEverywhere($db, $me, true);    // keep this session; every other one ends
            secLog($db, $me, $staff['email'], 'password_changed');
            logActivity($me, 'password_changed', null, null, null);
            if (mailConfigured($db)) {
                try { sendEmail($db, $staff['email'], 'Your Clinic CRM password was changed',
                    '<p>Your Clinic CRM password was just changed from ' . htmlspecialchars(secDeviceLabel(secAgent()) . ' (' . secIp() . ')') . ' on ' . date('d M Y, h:i A') . '.</p><p>' . t('If this wasn’t you, reset your password from the sign-in page straight away and tell an admin.') . '</p>',
                    '', 'Password changed'); } catch (Throwable $e) {}
            }
            $say('success', 'Password changed. You’re still signed in here; everywhere else has been signed out.');
        } elseif ($act === 'codes') {
            $newCodes = secNewBackupCodes($db, $me);
            logActivity($me, 'backup_codes_created', null, null, null);
        } elseif ($act === 'forget_device') {
            $db->prepare("DELETE FROM trusted_devices WHERE id = ? AND staff_id = ?")->execute([(int)($_POST['device_id'] ?? 0), $me]);
            $say('success', 'That device will need a code next time.');
        } elseif ($act === 'forget_all') {
            $db->prepare("DELETE FROM trusted_devices WHERE staff_id = ?")->execute([$me]);
            $say('success', 'All devices will need a code next time.');
        } elseif ($act === 'signout_others') {
            secSignOutEverywhere($db, $me, true);
            logActivity($me, 'signed_out_everywhere', null, null, null);
            $say('success', 'Signed out everywhere else. You’re still signed in here.');
        }
    } catch (Throwable $e) {
        $say('danger', $e->getMessage());
    }
    if ($newCodes === null) { header('Location: /pages/account.php'); exit; }
}

$flash = $_SESSION['acct_flash'] ?? null;
unset($_SESSION['acct_flash']);
$staff = $load();
$twoStep = secTwoStepRequired($db, currentRole());
$codesLeft = secBackupCodesLeft($db, $me);
$devices = $db->prepare("SELECT * FROM trusted_devices WHERE staff_id = ? AND expires_at > NOW() ORDER BY COALESCE(last_used, created_at) DESC");
$devices->execute([$me]);
$devices = $devices->fetchAll();
$signins = $db->prepare("SELECT * FROM login_log WHERE staff_id = ? ORDER BY id DESC LIMIT 15");
$signins->execute([$me]);
$signins = $signins->fetchAll();
$resultLabel = ['success' => ['Signed in', 'aging-fresh'], 'bad_password' => ['Wrong password', 'aging-critical'], 'bad_code' => ['Wrong code', 'aging-critical'],
                'password_ok' => ['Password OK, code sent', 'aging-due'], 'locked' => ['Blocked (too many tries)', 'aging-critical'],
                'password_changed' => ['Password changed', ''], 'password_reset' => ['Password reset by email', '']];

$pageTitle    = 'My account';
$pageSubtitle = sanitize($staff['name'] . ' · ' . $staff['email']);
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] ?>"><i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i><div><strong><?= sanitize($flash['msg']) ?></strong></div></div>
<?php endif; ?>

<?php if ($newCodes): ?>
<div class="card-crm page-section">
  <div class="card-header-crm"><span><i class="bi bi-key"></i> <?= t('Your new backup codes') ?></span><span class="card-header-sub"><?= t('Shown only now') ?></span></div>
  <div class="card-body-crm">
    <p class="fs-13"><?= t('Each code signs you in once if the email code doesn’t arrive. Save them somewhere safe (a password manager, or printed and locked away). Your old codes no longer work.') ?></p>
    <div class="d-flex flex-wrap gap-2 font-monospace fs-5 mb-3" id="codes"><?php foreach ($newCodes as $c): ?><span class="chip chip-lg"><?= $c ?></span><?php endforeach; ?></div>
    <button type="button" class="btn btn-ghost btn-sm" onclick="navigator.clipboard?.writeText(<?= htmlspecialchars(json_encode(implode("\n", $newCodes)), ENT_QUOTES) ?>); this.textContent='Copied'"><i class="bi bi-copy"></i><?= t('Copy all') ?></button>
  </div>
</div>
<?php endif; ?>

<div class="row g-3 page-section">
  <div class="col-xl-6 d-flex flex-column gap-3">
    <form method="POST" class="card-crm">
      <?= csrfField() ?><input type="hidden" name="action" value="password">
      <div class="card-header-crm"><span><i class="bi bi-lock"></i> <?= t('Change my password') ?></span>
        <span class="card-header-sub"><?= $staff['password_changed_at'] ? 'Last changed ' . timeSince($staff['password_changed_at']) : '' ?></span></div>
      <div class="card-body-crm">
        <label class="form-label" for="cur"><?= t('Current password') ?></label>
        <input class="form-control mb-3" type="password" id="cur" name="current_password" required autocomplete="current-password">
        <label class="form-label" for="npw"><?= t('New password') ?></label>
        <input class="form-control mb-1" type="password" id="npw" name="new_password" required minlength="10" autocomplete="new-password">
        <div class="fs-12 text-tx-3 mb-3"><?= t('At least 10 characters. Not your name, email or a common password. A short sentence is easy to remember and hard to guess.') ?></div>
        <label class="form-label" for="cpw"><?= t('New password again') ?></label>
        <input class="form-control" type="password" id="cpw" name="confirm_password" required minlength="10" autocomplete="new-password">
        <p class="fs-12 text-tx-3 mt-2 mb-0"><?= t('Changing it signs you out on every other computer and phone.') ?></p>
      </div>
      <div class="form-actions"><button class="btn btn-gold ms-auto"><i class="bi bi-check-lg"></i><?= t('Change password') ?></button></div>
    </form>

    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-shield-check"></i> <?= t('2-step sign-in') ?></span></div>
      <div class="card-body-crm">
        <?php if ($twoStep): ?>
          <p class="mb-2"><i class="bi bi-check-circle-fill text-green"></i> <strong><?= t('On.') ?></strong> After your password, a code is emailed to <?= sanitize($staff['email']) ?>. Devices you trust skip it for <?= SEC_TRUST_DAYS ?> days.</p>
        <?php else: ?>
          <p class="mb-2 text-tx-2"><i class="bi bi-hourglass"></i> <?= t('Not active yet: it starts as soon as the clinic’s email sending is set up (Admin → Email).') ?></p>
        <?php endif; ?>
        <p class="fs-13 mb-0"><strong><?= t('Backup codes:') ?></strong> <?= $codesLeft ? "$codesLeft unused." : 'none yet.' ?> They get you in if the email code doesn’t arrive.</p>
      </div>
      <form method="POST" class="form-actions">
        <?= csrfField() ?><input type="hidden" name="action" value="codes">
        <button class="btn btn-ghost ms-auto" <?= $codesLeft ? 'data-confirm="Create new backup codes? Your current ones will stop working."' : '' ?>><i class="bi bi-key"></i><?= $codesLeft ? 'Create new backup codes' : 'Create backup codes' ?></button>
      </form>
    </div>
  </div>

  <div class="col-xl-6 d-flex flex-column gap-3">
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-laptop"></i> <?= t('Trusted devices') ?></span><span class="card-header-sub">Skip the code for <?= SEC_TRUST_DAYS ?> days</span></div>
      <?php if (!$devices): ?>
        <div class="card-body-crm fs-13 text-tx-3"><?= t('None. Tick “Trust this device” when you enter a code to add one.') ?></div>
      <?php else: ?>
      <div class="table-responsive"><table class="table">
        <tbody>
        <?php foreach ($devices as $d): ?>
        <tr><td class="fs-13"><?= sanitize($d['label']) ?><br><span class="fs-12 text-tx-3">Added <?= formatDate($d['created_at']) ?><?= $d['last_used'] ? ' · last used ' . timeSince($d['last_used']) : '' ?></span></td>
            <td class="text-end"><form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="forget_device"><input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>">
              <button class="btn btn-ghost btn-sm"><?= t('Forget') ?></button></form></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <form method="POST" class="form-actions"><?= csrfField() ?><input type="hidden" name="action" value="forget_all"><button class="btn btn-ghost btn-sm ms-auto"><?= t('Forget all devices') ?></button></form>
      <?php endif; ?>
    </div>

    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-clock-history"></i> <?= t('Recent sign-in activity') ?></span></div>
      <?php if (!$signins): ?>
        <div class="card-body-crm fs-13 text-tx-3"><?= t('Nothing recorded yet.') ?></div>
      <?php else: ?>
      <div class="table-responsive"><table class="table">
        <thead><tr><th><?= t('When') ?></th><th><?= t('Device') ?></th><th><?= t('Result') ?></th></tr></thead>
        <tbody>
        <?php foreach ($signins as $l): $rl = $resultLabel[$l['result']] ?? [$l['result'], '']; ?>
        <tr><td class="fs-13 text-nowrap"><?= formatDate($l['created_at']) ?></td>
            <td class="fs-13"><?= sanitize(secDeviceLabel($l['user_agent'])) ?><br><span class="fs-12 text-tx-3"><?= sanitize($l['ip']) ?></span></td>
            <td><span class="chip <?= $rl[1] ?>"><?= sanitize($rl[0]) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
      <form method="POST" class="form-actions">
        <?= csrfField() ?><input type="hidden" name="action" value="signout_others">
        <span class="fs-12 text-tx-3 me-auto"><?= t('Something you don’t recognise? Change your password and tell an admin.') ?></span>
        <button class="btn btn-ghost btn-sm" data-confirm="Sign out on every other computer and phone?"><i class="bi bi-box-arrow-right"></i><?= t('Sign out everywhere else') ?></button>
      </form>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
