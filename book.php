<?php
// book.php — public online booking: branch → treatment → practitioner (or anyone) → day → time → name and mobile →
// a one-time code by WhatsApp or SMS → the appointment (booked online), with a link to confirm or change it.
// Only treatments marked "online" in the Treatment menu, only free times, never inside the notice period. No sign-in;
// rate-limited; English / Arabic (?lang=ar). The clinic switches it on in Messaging settings.
require_once __DIR__ . '/includes/auth.php';      // session + CSRF token (no sign-in needed here)
require_once __DIR__ . '/includes/crm.php';

$db = getDB();
header("Content-Security-Policy: default-src 'self'; script-src 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; form-action 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin'); header('Cache-Control: no-store');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ar = ($_GET['lang'] ?? $_SESSION['book_lang'] ?? '') === 'ar';
$_SESSION['book_lang'] = $ar ? 'ar' : 'en';
$L = fn(string $en, string $a) => $ar ? $a : $en;
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$err = null; $done = null;
$on = onlineBookingOn($db) && rateLimitOk($db, "book_view:$ip", 120, 600);
$b = $_SESSION['book'] ?? [];
if ($on && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCsrf();
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'code') {
            if (!rateLimitOk($db, "book_code:$ip", 10, 3600)) throw new RuntimeException($L('Too many tries. Please call the clinic.', 'محاولات كثيرة. يرجى الاتصال بالعيادة.'));
            $name = trim((string)($_POST['name'] ?? ''));
            if (mb_strlen($name) < 2) throw new RuntimeException($L('Enter your name.', 'أدخل اسمك.'));
            $b = ['branch_id' => (int)$_POST['branch_id'], 'service_id' => (int)$_POST['service_id'], 'staff_id' => (int)($_POST['staff_id'] ?? 0), 'time' => (string)$_POST['time'],
                  'name' => mb_substr($name, 0, 80), 'phone' => (string)$_POST['phone'], 'whatsapp_opt_in' => !empty($_POST['wa'])];
            $free = array_column(onlineSlots($db, $b['branch_id'], $b['service_id'], $b['staff_id'], substr($b['time'], 0, 10)), 'time');
            if (!in_array($b['time'], $free, true)) throw new RuntimeException($L('That time was just taken. Please choose another.', 'تم حجز هذا الوقت للتو. يرجى اختيار وقت آخر.'));
            otpSend($db, $b['phone']);
            $_SESSION['book'] = $b + ['code_sent' => time()];
            $b = $_SESSION['book'];
        } elseif ($act === 'verify' && !empty($b['code_sent'])) {
            if (!otpVerify($db, $b['phone'], (string)($_POST['code'] ?? ''))) throw new RuntimeException($L('That code is not right or has expired.', 'الرمز غير صحيح أو انتهت صلاحيته.'));
            [$apptId] = onlineBook($db, $b);
            unset($_SESSION['book']);
            $done = ['when' => $b['time'], 'link' => appointmentSelfLink($db, $apptId)];
        } elseif ($act === 'restart') { unset($_SESSION['book']); header('Location: /book.php'); exit; }
    } catch (Throwable $x) { $err = $x->getMessage(); }
}
$branches = branchList($db);
$branch = (int)($_GET['branch'] ?? 0) ?: (count($branches) === 1 ? (int)array_key_first($branches) : 0);
$services = bookableServices($db);
$service = (int)($_GET['service'] ?? 0);
$staff = (int)($_GET['staff'] ?? 0);
$tz = new DateTimeZone(CLINIC_TZ);
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : (new DateTimeImmutable('today', $tz))->format('Y-m-d');
$time = (string)($_GET['time'] ?? '');
$pracs = $branch ? $db->query("SELECT DISTINCT s.id, s.name FROM practitioner_profiles pp JOIN staff s ON s.id = pp.staff_id AND s.is_active = 1 JOIN practitioner_schedules ps ON ps.staff_id = s.id AND ps.branch_id = $branch WHERE pp.is_bookable = 1 ORDER BY s.name")->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$slots = $on && $branch && $service ? onlineSlots($db, $branch, $service, $staff, $date) : [];
$q = fn(array $o) => '?' . http_build_query(array_filter(array_merge(['branch' => $branch ?: null, 'service' => $service ?: null, 'staff' => $staff ?: null, 'date' => $date, 'lang' => $ar ? 'ar' : null], $o), fn($v) => $v !== null && $v !== ''));
$fmt = fn(string $t) => (new DateTimeImmutable($t, $tz))->format('D j M · g:i A');
?><!DOCTYPE html>
<html lang="<?= $ar ? 'ar' : 'en' ?>" dir="<?= $ar ? 'rtl' : 'ltr' ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $L('Book online', 'احجز موعدك') ?> · <?= $e(clinicName()) ?></title><link rel="stylesheet" href="/assets/vendor/fonts/fonts.css">
<style>body{margin:0;font:16px/1.5 'DM Sans','IBM Plex Sans Arabic',Arial,sans-serif;background:#faf7f2;color:#1d1b18}.box{max-width:560px;margin:24px auto;background:#fff;border-radius:14px;padding:22px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
h1{font-size:22px;margin:0 0 4px}h2{font-size:15px;margin:18px 0 8px}.muted{color:#6b645b;font-size:14px}label{display:block;font-size:14px;margin:10px 0 4px}
select,input{width:100%;box-sizing:border-box;min-height:44px;border:1px solid #ddd3c4;border-radius:8px;padding:8px;font:inherit;background:#fff}button,.btn{display:inline-block;min-height:44px;border:0;border-radius:10px;background:#b8925a;color:#fff;font:inherit;font-weight:600;padding:10px 16px;text-decoration:none;cursor:pointer}
.slots{display:grid;grid-template-columns:repeat(auto-fill,minmax(88px,1fr));gap:6px}.slots a{display:block;text-align:center;padding:10px 4px;border:1px solid #ddd3c4;border-radius:8px;color:#1d1b18;text-decoration:none}.slots a.on{background:#b8925a;color:#fff;border-color:#b8925a}
.err{background:#fbeaea;border-radius:8px;padding:10px;margin:10px 0}.ok{background:#e8f4ec;border-radius:8px;padding:12px}.days{display:flex;gap:6px;overflow-x:auto;padding-bottom:4px}.days a{flex:0 0 auto;padding:8px 10px;border:1px solid #ddd3c4;border-radius:8px;color:#1d1b18;text-decoration:none;text-align:center;font-size:14px}.days a.on{background:#1d1b18;color:#fff}
.check{display:flex;gap:8px;align-items:flex-start}.check input{width:auto;min-height:auto;margin-top:4px}</style></head>
<body><main class="box">
  <div style="display:flex;justify-content:space-between;align-items:center"><h1><?= $e(clinicName()) ?></h1><a class="muted" href="<?= $e($q(['lang' => $ar ? 'en' : 'ar'])) ?>"><?= $ar ? 'English' : 'العربية' ?></a></div>
  <p class="muted"><?= $L('Book an appointment online', 'احجز موعدك عبر الإنترنت') ?></p>
  <?php if ($err): ?><div class="err" role="alert"><?= $e($err) ?></div><?php endif; ?>
  <?php if (!$on): ?><p><?= $L('Online booking isn’t available right now. Please call or WhatsApp the clinic.', 'الحجز عبر الإنترنت غير متاح حالياً. يرجى الاتصال بالعيادة أو مراسلتها عبر واتساب.') ?></p>
  <?php elseif ($done): ?><div class="ok" role="status"><strong><?= $L('You’re booked!', 'تم الحجز!') ?></strong><br><?= $e($fmt($done['when'])) ?><br><a href="<?= $e($done['link']) ?>"><?= $L('See or change your appointment', 'عرض موعدك أو تغييره') ?></a></div>
  <?php elseif (!empty($b['code_sent'])): ?>
    <h2><?= $L('Enter the code we sent to', 'أدخل الرمز المرسل إلى') ?> <bdi><?= $e($b['phone']) ?></bdi></h2>
    <p class="muted"><?= $e($fmt($b['time'])) ?></p>
    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="verify"><label for="code"><?= $L('6-digit code', 'الرمز المكوّن من 6 أرقام') ?></label>
      <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" required autofocus><p><button><?= $L('Book', 'احجز') ?></button></p></form>
    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="restart"><button style="background:#f1ece4;color:#1d1b18"><?= $L('Start again', 'ابدأ من جديد') ?></button></form>
  <?php else: ?>
    <form method="GET"><?php if ($ar): ?><input type="hidden" name="lang" value="ar"><?php endif; ?>
      <?php if (count($branches) > 1): ?><label for="br"><?= $L('Branch', 'الفرع') ?></label><select id="br" name="branch" onchange="this.form.submit()"><option value=""><?= $L('Choose…', 'اختر…') ?></option><?php foreach ($branches as $br): ?><option value="<?= (int)$br['id'] ?>" <?= $branch === (int)$br['id'] ? 'selected' : '' ?>><?= $e($ar && $br['name_ar'] ? $br['name_ar'] : $br['name']) ?></option><?php endforeach; ?></select>
      <?php else: ?><input type="hidden" name="branch" value="<?= $branch ?>"><?php endif; ?>
      <label for="sv"><?= $L('Treatment', 'العلاج') ?></label><select id="sv" name="service" onchange="this.form.submit()"><option value=""><?= $L('Choose…', 'اختر…') ?></option><?php foreach ($services as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $service === (int)$s['id'] ? 'selected' : '' ?>><?= $e($ar && $s['name_ar'] ? $s['name_ar'] : $s['name']) ?> (<?= (int)$s['duration_min'] ?> <?= $L('min', 'دقيقة') ?>)</option><?php endforeach; ?></select>
      <?php if ($pracs): ?><label for="st"><?= $L('With', 'مع') ?></label><select id="st" name="staff" onchange="this.form.submit()"><option value=""><?= $L('Anyone available', 'أي طبيب متاح') ?></option><?php foreach ($pracs as $k => $n): ?><option value="<?= (int)$k ?>" <?= $staff === (int)$k ? 'selected' : '' ?>><?= $e($n) ?></option><?php endforeach; ?></select><?php endif; ?>
      <input type="hidden" name="date" value="<?= $e($date) ?>"><noscript><p><button><?= $L('Show times', 'عرض الأوقات') ?></button></p></noscript>
    </form>
    <?php if ($branch && $service): ?>
      <h2><?= $L('Day', 'اليوم') ?></h2><div class="days"><?php for ($i = 0; $i < 14; $i++): $d = (new DateTimeImmutable('today', $tz))->modify("+$i days"); ?><a class="<?= $d->format('Y-m-d') === $date ? 'on' : '' ?>" href="<?= $e($q(['date' => $d->format('Y-m-d'), 'time' => null])) ?>"><?= $e($d->format('D')) ?><br><?= $e($d->format('j M')) ?></a><?php endfor; ?></div>
      <h2><?= $L('Time', 'الوقت') ?></h2>
      <?php if ($slots): ?><div class="slots"><?php foreach ($slots as $sl): ?><a class="<?= $sl['time'] === $time ? 'on' : '' ?>" href="<?= $e($q(['time' => $sl['time']])) ?>#you"><?= $e((new DateTimeImmutable($sl['time'], $tz))->format('g:i A')) ?></a><?php endforeach; ?></div>
      <?php else: ?><p class="muted"><?= $L('No free times on this day. Try another day.', 'لا توجد أوقات متاحة في هذا اليوم. جرب يوماً آخر.') ?></p><?php endif; ?>
      <?php if ($time && in_array($time, array_column($slots, 'time'), true)): ?>
      <form method="POST" id="you"><?= csrfField() ?><input type="hidden" name="action" value="code"><input type="hidden" name="branch_id" value="<?= $branch ?>"><input type="hidden" name="service_id" value="<?= $service ?>"><input type="hidden" name="staff_id" value="<?= $staff ?>"><input type="hidden" name="time" value="<?= $e($time) ?>">
        <h2><?= $e($fmt($time)) ?></h2>
        <label for="nm"><?= $L('Your name', 'اسمك') ?></label><input id="nm" name="name" required maxlength="80" autocomplete="name">
        <label for="ph"><?= $L('Mobile number', 'رقم الجوال') ?></label><input id="ph" name="phone" type="tel" required autocomplete="tel" placeholder="05x xxx xxxx" dir="ltr">
        <div class="check" style="margin-top:10px"><input type="checkbox" id="wa" name="wa" value="1" checked><label for="wa" style="margin:0"><?= $L('Send me booking updates on WhatsApp', 'أرسل لي تحديثات الحجز عبر واتساب') ?></label></div>
        <p><button><?= $L('Send me a code', 'أرسل لي رمز التحقق') ?></button></p></form>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</main></body></html>
