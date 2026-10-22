<?php
// pages/consent_sign.php — the consent screen handed to the patient on a tablet.
// Staff start it (POST action=start, consents.collect) from the patient, appointment or note page. The device is then
// locked to this screen (requireLogin sends every other staff page here) until staff unlock it with their password;
// 5 wrong passwords sign the staff member out. The hand-over token lives only in the session (never in the address),
// is used once and expires after 15 minutes. The patient reads the form (English and Arabic), signs in the box; a
// witness can sign too. The signature is drawn with a small canvas script (no library).
define('KIOSK_PAGE', true);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';     // CSRF helpers
require_once __DIR__ . '/../includes/consents.php';
requireLogin();

$db = getDB();
$go = function (string $to) { header('Location: ' . $to); exit; };
// The page we return to shows the message (the appointment page has no message area for it)
$flashTo = function (string $return, array $msg) {
    if (str_contains($return, 'encounter.php')) $_SESSION['emr_flash'] = $msg;
    elseif (str_contains($return, 'patient.php')) $_SESSION['patient_flash'] = $msg;
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'start') {
        requirePermission('consents.collect');
        $pid = (int)($_POST['patient_id'] ?? 0); $appt = (int)($_POST['appointment_id'] ?? 0) ?: null; $enc = (int)($_POST['encounter_id'] ?? 0) ?: null;
        $return = $enc ? "/pages/encounter.php?id=$enc" : ($appt ? "/pages/appointment.php?id=$appt" : "/pages/patient.php?id=$pid&tab=consents");
        try {
            if (!empty($_SESSION['kiosk_lock'])) throw new RuntimeException('This device is already showing a consent.');
            $_SESSION['kiosk_lock'] = ['token' => startConsentHandoff($db, $pid, (int)($_POST['template_id'] ?? 0), $appt, $enc), 'fails' => 0, 'return' => $return, 'patient_id' => $pid];
            session_regenerate_id(true);
        } catch (Throwable $e) {
            $flashTo($return, ['danger', $e->getMessage()]);
            $go($return);
        }
        $go('/pages/consent_sign.php');
    }
    $k = &$_SESSION['kiosk_lock'];
    if (empty($k)) $go('/pages/dashboard.php');
    if ($action === 'sign' && $k['token']) {
        try {
            $k['done'] = signConsent($db, $k['token'], (string)($_POST['signature'] ?? ''), (string)($_POST['witness_name'] ?? ''), (string)($_POST['witness_signature'] ?? ''));
            $k['token'] = null;
            unset($k['error']);
        } catch (Throwable $e) {
            $k['error'] = $e->getMessage();
        }
    } elseif ($action === 'decline') {
        $k['token'] = null; $k['declined'] = true;
    } elseif ($action === 'unlock') {
        $s = $db->prepare("SELECT password FROM staff WHERE id = ?");
        $s->execute([currentStaffId()]);
        if (password_verify((string)($_POST['password'] ?? ''), (string)$s->fetchColumn())) {
            $return = $k['return']; $msg = !empty($k['done']) ? ['success', 'Consent signed.'] : ['warning', 'The consent was not signed.'];
            unset($k); unset($_SESSION['kiosk_lock']);
            session_regenerate_id(true);
            $flashTo($return, $msg);
            $go($return);
        }
        if (++$k['fails'] >= 5) { logoutStaff(); $go('/login.php'); }
        $k['unlock_error'] = 'That password is not right.';
    }
    $go('/pages/consent_sign.php');
}

$k = $_SESSION['kiosk_lock'] ?? null;
if (!$k) $go('/pages/dashboard.php');
$h = $k['token'] ? handoffFromToken($db, $k['token']) : null;
$error = $k['error'] ?? null; $unlockError = $k['unlock_error'] ?? null;
unset($_SESSION['kiosk_lock']['error'], $_SESSION['kiosk_lock']['unlock_error']);
if ($h) {
    $vars = consentVars($db, $h['patient'], $h['appointment_id'] ? (int)$h['appointment_id'] : null, $h['encounter_id'] ? (int)$h['encounter_id'] : null);
    logPatientAccess($db, (int)$h['patient_id'], 'consent_screen:' . $h['template_id']);
}
sendStaffPageHeaders();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Consent · <?= sanitize(clinicName()) ?></title>
<link rel="stylesheet" href="/assets/vendor/bootstrap-5.3.2/bootstrap.min.css">
<link rel="stylesheet" href="/assets/vendor/bootstrap-icons-1.11.3/bootstrap-icons.min.css">
<link rel="stylesheet" href="/assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="/assets/css/app.css?v=9">
<?= brandStyleTag() ?>
<style>
  body { background: var(--bg, #faf8f5); }
  .kiosk { max-width: 980px; margin: 0 auto; padding: 20px 16px 40px; }
  .consent-cols { display: grid; gap: 20px; grid-template-columns: 1fr; }
  @media (min-width: 900px) { .consent-cols.two { grid-template-columns: 1fr 1fr; } }
  .consent-text { font-size: 17px; line-height: 1.65; }
  .consent-text h2 { font-size: 20px; }
  [lang="ar"] { font-family: 'IBM Plex Sans Arabic', 'DM Sans', sans-serif; }
  .sig-box { position: relative; border: 2px dashed var(--border-strong, #c9bfb1); border-radius: 12px; background: #fff; touch-action: none; }
  .sig-box canvas { display: block; width: 100%; height: 220px; cursor: crosshair; }
  .sig-box .sig-hint { position: absolute; inset: auto 0 10px; text-align: center; color: #9a9187; pointer-events: none; font-size: 14px; }
  .kiosk .btn { min-height: 48px; }
</style>
</head>
<body>
<main class="kiosk">
  <div class="d-flex align-items-center gap-2 mb-3"><i class="bi bi-shield-lock fs-4 text-gold"></i><strong class="fs-5"><?= sanitize(clinicName()) ?></strong></div>
<?php if ($h): ?>
  <div class="card-crm mb-3"><div class="card-body-crm">
    <div class="fs-13 text-tx-3">Patient / المريض</div>
    <div class="fs-5 fw-600"><?= sanitize(patientName($h['patient'])) ?><?= $h['patient']['name_ar'] ? ' · <bdi lang="ar">' . sanitize($h['patient']['name_ar']) . '</bdi>' : '' ?></div>
  </div></div>
  <?php if ($error): ?><div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle"></i><strong><?= sanitize($error) ?></strong></div><?php endif; ?>
  <div class="card-crm mb-3"><div class="card-body-crm consent-cols <?= $h['template']['body_ar'] ? 'two' : '' ?> consent-text">
    <?= consentSnapshot($h['template'], $vars) ?>
  </div></div>
  <form method="POST" id="signForm" class="card-crm">
    <?= csrfField() ?><input type="hidden" name="action" value="sign"><input type="hidden" name="signature" id="sigData"><input type="hidden" name="witness_signature" id="witData">
    <div class="card-body-crm">
      <div class="form-check mb-3 fs-6"><input class="form-check-input" type="checkbox" id="agree" required>
        <label class="form-check-label" for="agree">I have read and understood this form and I agree. <span lang="ar" dir="rtl">لقد قرأت هذا النموذج وفهمته وأوافق عليه.</span></label></div>
      <label class="form-label fw-600" id="sigLabel">Your signature / توقيعك</label>
      <div class="sig-box" data-for="sigData"><canvas aria-labelledby="sigLabel" role="img"></canvas><div class="sig-hint">Sign here with your finger / وقّع هنا</div></div>
      <button type="button" class="btn btn-ghost btn-sm mt-2" data-clear="sigData"><i class="bi bi-eraser"></i>Clear / مسح</button>
      <details class="mt-3"><summary class="fw-600">Witness (optional) / الشاهد (اختياري)</summary>
        <label class="form-label mt-2" for="witName">Witness name</label><input class="form-control mb-2" id="witName" name="witness_name" maxlength="150" autocomplete="off">
        <div class="sig-box" data-for="witData"><canvas aria-label="Witness signature" role="img"></canvas><div class="sig-hint">Witness signs here</div></div>
        <button type="button" class="btn btn-ghost btn-sm mt-2" data-clear="witData"><i class="bi bi-eraser"></i>Clear</button>
      </details>
    </div>
    <div class="form-actions">
      <button type="submit" form="declineForm" class="btn btn-ghost">I don’t want to sign / لا أرغب في التوقيع</button>
      <button class="btn btn-gold ms-auto" id="signBtn"><i class="bi bi-pen"></i>Sign / توقيع</button>
    </div>
  </form>
  <form method="POST" id="declineForm"><?= csrfField() ?><input type="hidden" name="action" value="decline"></form>
<?php else: ?>
  <div class="card-crm mx-auto" style="max-width:520px"><div class="card-body-crm text-center">
    <?php if (!empty($k['done'])): ?><i class="bi bi-check-circle fs-1 text-success"></i><h1 class="fs-4 mt-2">Thank you. <bdi lang="ar">شكراً لك.</bdi></h1>
    <?php elseif (!empty($k['declined'])): ?><i class="bi bi-x-circle fs-1 text-tx-3"></i><h1 class="fs-4 mt-2">Not signed. <bdi lang="ar">لم يتم التوقيع.</bdi></h1>
    <?php else: ?><i class="bi bi-hourglass-bottom fs-1 text-tx-3"></i><h1 class="fs-4 mt-2">This consent screen has expired.</h1><?php endif; ?>
    <p class="fs-5">Please hand the device back to the staff.<br><span lang="ar" dir="rtl">يرجى إعادة الجهاز إلى الموظفين.</span></p>
  </div>
  <form method="POST" class="card-body-crm border-top text-start">
    <?= csrfField() ?><input type="hidden" name="action" value="unlock">
    <?php if ($unlockError): ?><div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i><?= sanitize($unlockError) ?></div><?php endif; ?>
    <label class="form-label" for="pw">Staff: your password to unlock (<?= sanitize(staffName()) ?>)</label>
    <input class="form-control mb-2" type="password" id="pw" name="password" autocomplete="current-password" required>
    <button class="btn btn-gold w-100"><i class="bi bi-unlock"></i>Unlock</button>
  </form></div>
<?php endif; ?>
</main>
<?php if ($h): ?>
<script>
// Signature boxes: pointer events (finger, pen, mouse), drawn at the screen's pixel density, sent as a PNG.
document.querySelectorAll('.sig-box').forEach(function (box) {
  var c = box.querySelector('canvas'), ctx = c.getContext('2d'), out = document.getElementById(box.dataset.for), last = null;
  box.inked = false;
  function size() { var r = c.getBoundingClientRect(), d = Math.min(window.devicePixelRatio || 1, 2); c.width = r.width * d; c.height = r.height * d;
    ctx.setTransform(d, 0, 0, d, 0, 0); ctx.lineWidth = 2.4; ctx.lineCap = ctx.lineJoin = 'round'; ctx.strokeStyle = '#111'; box.inked = false; out.value = ''; box.querySelector('.sig-hint').hidden = false; }
  function pt(e) { var r = c.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; }
  c.addEventListener('pointerdown', function (e) { c.setPointerCapture(e.pointerId); last = pt(e); ctx.beginPath(); ctx.arc(last[0], last[1], 1.1, 0, 7); ctx.fillStyle = '#111'; ctx.fill(); });
  c.addEventListener('pointermove', function (e) { if (!last) return; var p = pt(e); ctx.beginPath(); ctx.moveTo(last[0], last[1]); ctx.lineTo(p[0], p[1]); ctx.stroke(); last = p; box.inked = true; box.querySelector('.sig-hint').hidden = true; });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (t) { c.addEventListener(t, function () { if (last && box.inked) out.value = c.toDataURL('image/png'); last = null; }); });
  document.querySelector('[data-clear="' + box.dataset.for + '"]').addEventListener('click', function () { ctx.clearRect(0, 0, c.width, c.height); size(); });
  box.closest('details') ? box.closest('details').addEventListener('toggle', size) : null;
  size();
});
document.getElementById('signForm').addEventListener('submit', function (e) {
  if (!document.getElementById('sigData').value) { e.preventDefault(); alert('Please sign in the box. / يرجى التوقيع في المربع.'); return; }
  document.getElementById('signBtn').disabled = true;
});
</script>
<?php endif; ?>
</body>
</html>
