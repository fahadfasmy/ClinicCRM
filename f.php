<?php
// f.php?t= — the patient's feedback link: a score from 0 to 10 and a comment, once. 9 or 10 shows the clinic's Google
// review link (if set); 6 or less alerts the managers. No sign-in: the random token is the key. Rate-limited.
require_once __DIR__ . '/includes/messaging.php';

$db = getDB();
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; form-action 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
$t = (string)($_POST['t'] ?? $_GET['t'] ?? '');
$f = rateLimitOk($db, 'feedback:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 30, 600) ? feedbackFromToken($db, $t) : null;
$err = null;
if ($f && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try { submitFeedback($db, $f, (int)($_POST['score'] ?? -1), (string)($_POST['comment'] ?? '')); } catch (Throwable $e) { $err = $e->getMessage(); }
    $f = feedbackFromToken($db, $t);
}
$p = $f && $f['patient_id'] ? findPatient($db, (int)$f['patient_id']) : null;
$ar = ($p['preferred_language'] ?? '') === 'ar';
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$L = fn(string $en, string $arT) => $ar ? $arT : $en;
$review = msgSetting($db, 'google_review_url');
?><!DOCTYPE html>
<html lang="<?= $ar ? 'ar' : 'en' ?>" dir="<?= $ar ? 'rtl' : 'ltr' ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title><?= $e(clinicName()) ?></title><link rel="stylesheet" href="/assets/vendor/fonts/fonts.css">
<style>body{margin:0;font:16px/1.5 'DM Sans','IBM Plex Sans Arabic',Arial,sans-serif;background:#faf7f2;color:#1d1b18}.box{max-width:480px;margin:40px auto;background:#fff;border-radius:14px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
h1{font-size:20px;margin:0 0 12px}.scores{display:grid;grid-template-columns:repeat(11,1fr);gap:4px;margin:12px 0}.scores label{display:block}.scores input{position:absolute;opacity:0}
.scores span{display:flex;align-items:center;justify-content:center;min-height:44px;border:1px solid #ddd3c4;border-radius:8px;cursor:pointer}.scores input:checked+span{background:#b8925a;color:#fff;border-color:#b8925a}.scores input:focus-visible+span{outline:2px solid #1d1b18}
textarea{width:100%;box-sizing:border-box;min-height:90px;border:1px solid #ddd3c4;border-radius:8px;padding:8px;font:inherit}button,.btn{display:block;width:100%;min-height:48px;margin-top:12px;border:0;border-radius:10px;background:#b8925a;color:#fff;font:inherit;font-weight:600;text-align:center;line-height:48px;text-decoration:none}
.muted{color:#6b645b;font-size:14px}.err{background:#fbeaea;border-radius:8px;padding:10px}</style></head>
<body><div class="box"><h1><?= $e(clinicName()) ?></h1>
<?php if (!$f): ?><p><?= $L('This link has expired or is not valid.', 'انتهت صلاحية هذا الرابط أو أنه غير صالح.') ?></p>
<?php elseif ($f['submitted_at']): ?>
  <p><?= $L('Thank you for your feedback!', 'شكراً لملاحظاتك!') ?></p>
  <?php if ((int)$f['score'] >= 9 && $review): ?><p><?= $L('Would you share it on Google too? It helps others find us.', 'هل تشاركنا رأيك على جوجل أيضاً؟ ذلك يساعد الآخرين على إيجادنا.') ?></p><a class="btn" href="<?= $e($review) ?>" rel="noopener"><?= $L('Write a Google review', 'اكتب تقييماً على جوجل') ?></a><?php endif; ?>
<?php else: ?>
  <form method="POST"><input type="hidden" name="t" value="<?= $e($t) ?>">
    <p><?= $L('How likely are you to recommend us to a friend?', 'ما مدى احتمال أن توصي بنا لصديق؟') ?></p>
    <?php if ($err): ?><p class="err"><?= $e($err) ?></p><?php endif; ?>
    <fieldset style="border:0;padding:0;margin:0"><legend class="muted"><?= $L('0 = not at all, 10 = definitely', '0 = إطلاقاً، 10 = بالتأكيد') ?></legend>
      <div class="scores" dir="ltr"><?php for ($i = 0; $i <= 10; $i++): ?><label><input type="radio" name="score" value="<?= $i ?>" required aria-label="<?= $i ?>"><span><?= $i ?></span></label><?php endfor; ?></div></fieldset>
    <label for="c"><?= $L('Anything you’d like to tell us? (optional)', 'هل تود إخبارنا بشيء؟ (اختياري)') ?></label>
    <textarea id="c" name="comment" maxlength="1000"></textarea>
    <button><?= $L('Send', 'إرسال') ?></button>
  </form>
<?php endif; ?>
</div></body></html>
