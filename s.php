<?php
// s.php?t= — the patient's link from a booking message: see the appointment (first name, date, time, branch only) and
// confirm it, cancel it (until the clinic's cut-off) or ask to reschedule. No sign-in: the random token is the key;
// a newer message's link replaces it. Rate-limited per address. English and Arabic.
require_once __DIR__ . '/includes/messaging.php';

$db = getDB();
header("Content-Security-Policy: default-src 'self'; script-src 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; form-action 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
$t = (string)($_POST['t'] ?? $_GET['t'] ?? '');
$a = rateLimitOk($db, 'self:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 30, 600) ? appointmentFromSelfToken($db, $t) : null;
$msg = null; $err = null;
if ($a && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        selfServiceAct($db, $a, (string)($_POST['do'] ?? ''));
        $msg = ['confirm' => ['Thank you, your appointment is confirmed.', 'شكراً، تم تأكيد موعدك.'], 'cancel' => ['Your appointment is cancelled.', 'تم إلغاء موعدك.'],
                'reschedule' => ['Thank you. The clinic will contact you to find a new time.', 'شكراً. ستتواصل معك العيادة لتحديد موعد جديد.']][$_POST['do']] ?? null;
        $a = appointmentFromSelfToken($db, $t);
    } catch (Throwable $e) { $err = $e->getMessage(); }
}
$p = $a && $a['patient_id'] ? findPatient($db, (int)$a['patient_id']) : null;
$ar = ($p['preferred_language'] ?? '') === 'ar';
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$L = fn(string $en, string $arT) => $ar ? $arT : $en;
$when = $a ? (new DateTimeImmutable($a['scheduled_at']))->setTimezone(new DateTimeZone(CLINIC_TZ)) : null;
$acts = $a ? selfServiceActions($db, $a) : [];
?><!DOCTYPE html>
<html lang="<?= $ar ? 'ar' : 'en' ?>" dir="<?= $ar ? 'rtl' : 'ltr' ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title><?= $e(clinicName()) ?></title><link rel="stylesheet" href="/assets/vendor/fonts/fonts.css">
<style>body{margin:0;font:16px/1.5 'DM Sans','IBM Plex Sans Arabic',Arial,sans-serif;background:#faf7f2;color:#1d1b18}.box{max-width:440px;margin:40px auto;background:#fff;border-radius:14px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
h1{font-size:20px;margin:0 0 12px}.when{font-size:18px;font-weight:600;margin:12px 0}button{display:block;width:100%;min-height:48px;margin:8px 0;border:0;border-radius:10px;font:inherit;font-weight:600;cursor:pointer}
.ok{background:#b8925a;color:#fff}.alt{background:#f1ece4;color:#1d1b18}.msg{background:#e8f4ec;border-radius:8px;padding:10px}.err{background:#fbeaea;border-radius:8px;padding:10px}.muted{color:#6b645b;font-size:14px}</style></head>
<body><div class="box">
  <h1><?= $e(clinicName()) ?></h1>
  <?php if (!$a): ?><p><?= $L('This link has expired or is not valid. Please contact the clinic.', 'انتهت صلاحية هذا الرابط أو أنه غير صالح. يرجى التواصل مع العيادة.') ?></p>
  <?php else: ?>
    <p><?= $L('Hello', 'مرحباً') ?> <?= $e($p['first_name'] ?? '') ?>,</p>
    <div class="when"><?= $e($when->format('l j F Y')) ?> · <?= $e($when->format('g:i A')) ?></div>
    <p class="muted"><?= $e((string)$a['branch_name']) ?><?= ($ml = msgSetting($db, 'map_link')) ? ' · <a href="' . $e($ml) . '" rel="noopener">' . $L('Map', 'الخريطة') . '</a>' : '' ?></p>
    <?php if ($msg): ?><p class="msg"><?= $e($L($msg[0], $msg[1])) ?></p><?php endif; ?>
    <?php if ($err): ?><p class="err"><?= $e($err) ?></p><?php endif; ?>
    <?php if ($a['status'] === 'cancelled'): ?><p class="muted"><?= $L('This appointment is cancelled.', 'هذا الموعد ملغى.') ?></p><?php endif; ?>
    <?php foreach ($acts as $do): ?><form method="POST"><input type="hidden" name="t" value="<?= $e($t) ?>"><input type="hidden" name="do" value="<?= $do ?>">
      <button class="<?= $do === 'confirm' ? 'ok' : 'alt' ?>" <?= $do === 'cancel' ? 'onclick="return confirm(\'' . $e($L('Cancel this appointment?', 'إلغاء هذا الموعد؟')) . '\')"' : '' ?>>
        <?= ['confirm' => $L('Confirm', 'تأكيد'), 'cancel' => $L('Cancel appointment', 'إلغاء الموعد'), 'reschedule' => $L('Ask to change the time', 'طلب تغيير الموعد')][$do] ?></button></form><?php endforeach; ?>
    <?php if (!$acts && !$msg && $a['status'] !== 'cancelled'): ?><p class="muted"><?= $L('To make changes, please call the clinic.', 'لإجراء أي تغيير يرجى الاتصال بالعيادة.') ?></p><?php endif; ?>
  <?php endif; ?>
</div></body></html>
