<?php
// login.php — sign in (email + password), then a code sent by email unless this device is trusted (2-step sign-in),
// plus "forgot password" (emailed link) and choosing a new password from that link. See includes/security.php.
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/inventory.php';     // CSRF helpers
require_once __DIR__ . '/includes/security.php';

if (!empty($_SESSION['staff_id'])) { header('Location: /pages/dashboard.php'); exit; }
// The language link on this page: remembered in this browser until someone signs in (then their own choice applies)
if (isset($_GET['lang'])) { uiRememberLang((string)$_GET['lang']); header('Location: /login.php'); exit; }

$db = getDB();
ensureSecuritySchema($db);
$error = $notice = '';
$mode = 'login';
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$baseUrl = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

/** The person who passed the password step and still owes a code (for 15 minutes). */
$pending = function () use ($db): ?array {
    if (empty($_SESSION['pending_staff']) || ($_SESSION['pending_at'] ?? 0) < time() - 900) return null;
    $s = $db->prepare("SELECT * FROM staff WHERE id = ? AND is_active = 1");
    $s->execute([(int)$_SESSION['pending_staff']]);
    return $s->fetch() ?: null;
};
$finish = function (array $staff) use ($db) {
    unset($_SESSION['pending_staff'], $_SESSION['pending_at']);
    loginStaff($staff);
    secLog($db, (int)$staff['id'], $staff['email'], 'success');
    secNewDeviceAlert($db, $staff);
    header('Location: /pages/dashboard.php');
    exit;
};

if (isset($_GET['signed_out'])) $notice = t('You were signed out because your password changed or an admin signed you out. Please sign in again.');
if (isset($_GET['idle'])) $notice = t('You were signed out after a time without activity. Please sign in again.');
if (isset($_GET['reset_done'])) $notice = t('Your password has been changed. Sign in with the new one.');
if (isset($_GET['forgot'])) $mode = 'forgot';
if (isset($_GET['reset'])) { $mode = 'reset'; $resetStaff = secResetStaff($db, (string)$_GET['reset']); if (!$resetStaff) { $mode = 'forgot'; $error = t('That reset link has expired or was already used. Ask for a new one below.'); } }
if (isset($_GET['cancel'])) { unset($_SESSION['pending_staff'], $_SESSION['pending_at']); header('Location: /login.php'); exit; }
if ($mode === 'login' && $pending()) $mode = 'code';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $step = $_POST['step'] ?? 'password';

    if ($step === 'password') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($mins = secLockedMinutes($db, $email)) {
            $error = "Too many unsuccessful attempts. Try again in $mins minute" . ($mins == 1 ? '' : 's') . '.';
            secLog($db, null, $email, 'locked');
        } else {
            $s = $db->prepare("SELECT * FROM staff WHERE email = ? AND is_active = 1 AND COALESCE(is_support, 0) = 0 LIMIT 1");   // support accounts open only through a grant
            $s->execute([$email]);
            $staff = $s->fetch();
            if ($email !== '' && $password !== '' && $staff && password_verify($password, $staff['password'])) {
                if (!secTwoStepRequired($db, $staff['role']) || secDeviceTrusted($db, (int)$staff['id'])) $finish($staff);
                session_regenerate_id(true);
                $_SESSION['pending_staff'] = (int)$staff['id'];
                $_SESSION['pending_at'] = time();
                secLog($db, (int)$staff['id'], $email, 'password_ok');
                try { secSendCode($db, $staff, true); }
                catch (Throwable $e) { $error = 'We couldn’t email your code (' . $e->getMessage() . '). Use one of your backup codes, or ask an admin.'; }
                $mode = 'code';
            } else {
                password_verify($password, '$2y$10$PocD3ILVMiVaWuoI3oQxZ.wp1kiDHgQxSgnHCvJcTLqGSAdCEGtKS');   // same delay whether or not the email exists
                secLog($db, $staff ? (int)$staff['id'] : null, $email, 'bad_password');
                $error = t('That email and password don’t match an active account.');
            }
        }
    } elseif ($step === 'code' && ($staff = $pending())) {
        $mode = 'code';
        $r = secCheckCode($db, (int)$staff['id'], (string)($_POST['code'] ?? ''));
        if ($r === 'ok') {
            if (!empty($_POST['trust'])) secTrustThisDevice($db, (int)$staff['id']);
            $finish($staff);
        }
        secLog($db, (int)$staff['id'], $staff['email'], 'bad_code');
        $error = ['bad' => 'That code isn’t right. Check the latest email and try again.',
                  'expired' => 'That code has expired. Send a new one below.',
                  'locked' => 'Too many wrong codes. Send a new code below.'][$r];
    } elseif ($step === 'resend' && ($staff = $pending())) {
        $mode = 'code';
        try { secSendCode($db, $staff); $notice = 'A new code is on its way to ' . $staff['email'] . '.'; }
        catch (Throwable $e) { $error = $e->getMessage(); }
    } elseif ($step === 'forgot') {
        $mode = 'forgot';
        if (!mailConfigured($db)) $error = t('Email isn’t set up yet, so a reset link can’t be sent. Ask a clinic admin to reset your password.');
        else {
            try { secRequestReset($db, (string)($_POST['email'] ?? ''), $baseUrl); } catch (Throwable $e) {}
            $notice = 'If that email belongs to an active account, a reset link is on its way. It works for ' . SEC_RESET_MINUTES . ' minutes.';
        }
    } elseif ($step === 'reset' && isset($resetStaff)) {
        $pw = (string)($_POST['new_password'] ?? '');
        $problems = secPasswordProblems($pw, $resetStaff, $resetStaff['password']);
        if ($pw !== (string)($_POST['confirm_password'] ?? '')) $problems[] = 'The two passwords don’t match.';
        if ($problems) $error = implode(' ', $problems);
        else {
            secSetPassword($db, (int)$resetStaff['id'], $pw);
            secUseReset($db, (string)$_GET['reset'], (int)$resetStaff['id']);
            $db->prepare("DELETE FROM trusted_devices WHERE staff_id = ?")->execute([$resetStaff['id']]);
            secLog($db, (int)$resetStaff['id'], $resetStaff['email'], 'password_reset');
            logActivity((int)$resetStaff['id'], 'password_reset_by_email', null, null, null);
            header('Location: /login.php?reset_done=1'); exit;
        }
    } else {
        $error = t('That sign-in attempt expired. Please start again.');
        $mode = 'login';
    }
}
$codeStaff = $mode === 'code' ? $pending() : null;
if ($mode === 'code' && !$codeStaff) $mode = 'login';
$maskEmail = fn(string $e) => preg_replace('/^(.).*?(.)?@/', '$1•••$2@', $e);
sendStaffPageHeaders();
?>
<!DOCTYPE html>
<html lang="<?= uiLang() ?>" dir="<?= uiDir() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= t('Sign in') ?> · Clinic CRM</title>
<?php include __DIR__ . "/includes/theme_head.php"; ?>
<link rel="stylesheet" href="/assets/vendor/bootstrap-5.3.2/bootstrap<?= uiDir() === 'rtl' ? '.rtl' : '' ?>.min.css">
<link rel="stylesheet" href="/assets/vendor/bootstrap-icons-1.11.3/bootstrap-icons.min.css">
<link rel="stylesheet" href="/assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="/assets/css/app.css?v=10">
<?= brandStyleTag() ?>
</head>
<body class="auth-page">

<main class="auth-shell">
  <section class="auth-brand" aria-hidden="true">
    <div class="auth-brand-inner">
      <span class="brand-mark brand-mark-lg"><i class="bi bi-heart-pulse-fill"></i></span>
      <h2><?= sanitize(clinicName()) ?></h2>
      <p><?= t('Every enquiry, follow-up and booking in one place.') ?></p>
      <ul>
        <li><i class="bi bi-lightning-charge"></i> <?= t('Leads from Meta, WhatsApp and walk-ins') ?></li>
        <li><i class="bi bi-bell"></i> <?= t('Follow-up reminders so nobody is forgotten') ?></li>
        <li><i class="bi bi-graph-up-arrow"></i> <?= t('Conversion analytics for the whole team') ?></li>
      </ul>
    </div>
  </section>

  <section class="auth-form">
    <div class="auth-card">
      <div class="d-flex align-items-center gap-2 mb-4 d-lg-none">
        <span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span>
        <span class="brand-name"><?= t('Clinic CRM') ?></span>
      </div>

      <?php if ($mode === 'code'): ?>
      <h1 class="page-title mb-1"><?= t('Check your email') ?></h1>
      <p class="page-subtitle mb-4"><?= t('We sent a 6-digit code to') ?> <strong><?= htmlspecialchars($maskEmail($codeStaff['email'])) ?></strong>.</p>
      <?php elseif ($mode === 'forgot'): ?>
      <h1 class="page-title mb-1"><?= t('Forgot your password?') ?></h1>
      <p class="page-subtitle mb-4"><?= t('We’ll email you a link to choose a new one.') ?></p>
      <?php elseif ($mode === 'reset'): ?>
      <h1 class="page-title mb-1"><?= t('Choose a new password') ?></h1>
      <p class="page-subtitle mb-4">For <?= htmlspecialchars($resetStaff['email']) ?></p>
      <?php else: ?>
      <h1 class="page-title mb-1"><?= t('Welcome back') ?></h1>
      <p class="page-subtitle mb-4"><?= t('Sign in to Clinic CRM') ?></p>
      <?php endif; ?>

      <?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if ($notice): ?><div class="alert alert-success"><i class="bi bi-check-circle"></i><?= htmlspecialchars($notice) ?></div><?php endif; ?>

      <?php if ($mode === 'code'): ?>
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="step" value="code">
        <div class="mb-3">
          <label class="form-label" for="code"><?= t('Code') ?></label>
          <input type="text" name="code" id="code" class="form-control form-control-lg text-center tabular" style="letter-spacing:.3em" required autofocus
                 inputmode="numeric" autocomplete="one-time-code" maxlength="11" placeholder="000000">
          <div class="fs-12 text-tx-3 mt-1"><?= t('No email? A backup code works here too.') ?></div>
        </div>
        <label class="form-check mb-4"><input class="form-check-input" type="checkbox" name="trust" value="1" checked>
          <span class="form-check-label">Trust this device for <?= SEC_TRUST_DAYS ?> days (don’t tick on a shared computer)</span></label>
        <button type="submit" class="btn btn-gold btn-lg w-100"><?= t('Verify and sign in') ?></button>
      </form>
      <form method="POST" class="d-flex justify-content-between mt-3 fs-13">
        <?= csrfField() ?><input type="hidden" name="step" value="resend">
        <button class="btn btn-link p-0 fs-13"><?= t('Send a new code') ?></button>
        <a href="/login.php?cancel=1"><?= t('Use a different account') ?></a>
      </form>

      <?php elseif ($mode === 'forgot'): ?>
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="step" value="forgot">
        <div class="mb-4">
          <label class="form-label" for="femail"><?= t('Your work email') ?></label>
          <input type="email" name="email" id="femail" class="form-control form-control-lg" required autofocus autocomplete="username" placeholder="you@clinic.com">
        </div>
        <button type="submit" class="btn btn-gold btn-lg w-100"><?= t('Email me a reset link') ?></button>
      </form>
      <p class="fs-13 text-center mt-4 mb-0"><a href="/login.php"><?= t('Back to sign in') ?></a></p>

      <?php elseif ($mode === 'reset'): ?>
      <?php if (isset($_GET['welcome'])): ?><div class="alert alert-success"><i class="bi bi-stars"></i><?= t('Your clinic is ready. Choose a password, then sign in to set it up.') ?></div><?php endif; ?>
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="step" value="reset">
        <div class="mb-3">
          <label class="form-label" for="npw"><?= t('New password') ?></label>
          <input type="password" name="new_password" id="npw" class="form-control form-control-lg" required minlength="10" autocomplete="new-password" autofocus>
          <div class="fs-12 text-tx-3 mt-1"><?= t('At least 10 characters. Not your name, email or a common password.') ?></div>
        </div>
        <div class="mb-4">
          <label class="form-label" for="cpw"><?= t('Type it again') ?></label>
          <input type="password" name="confirm_password" id="cpw" class="form-control form-control-lg" required minlength="10" autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-gold btn-lg w-100"><?= t('Save new password') ?></button>
      </form>

      <?php else: ?>
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="step" value="password">
        <div class="mb-3">
          <label class="form-label" for="email"><?= t('Email') ?></label>
          <input type="email" name="email" id="email" class="form-control form-control-lg" required autocomplete="username" autofocus
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="you@clinic.com">
        </div>
        <div class="mb-4">
          <label class="form-label" for="password"><?= t('Password') ?></label>
          <div class="position-relative">
            <input type="password" name="password" id="password" class="form-control form-control-lg pe-5" required autocomplete="current-password">
            <button type="button" class="btn-icon position-absolute top-50 end-0 translate-middle-y me-1" onclick="togglePw(this)" aria-label="<?= t('Show password') ?>"><i class="bi bi-eye"></i></button>
          </div>
        </div>
        <button type="submit" class="btn btn-gold btn-lg w-100"><?= t('Sign in') ?></button>
      </form>
      <p class="fs-13 text-center mt-4 mb-0"><a href="/login.php?forgot=1"><?= t('Forgot your password?') ?></a></p>
      <?php endif; ?>
      <p class="fs-13 text-center mt-3 mb-0"><?= uiLang() === 'ar' ? '<a href="/login.php?lang=en" lang="en">English</a>' : '<a href="/login.php?lang=ar" lang="ar">العربية</a>' ?></p>
    </div>
  </section>
</main>

<script>
function togglePw(btn) {
  const i = document.getElementById('password'), show = i.type === 'password';
  i.type = show ? 'text' : 'password';
  btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
  btn.setAttribute('aria-label', show ? <?= json_encode(t('Hide password')) ?> : <?= json_encode(t('Show password')) ?>);
}
</script>
</body>
</html>
