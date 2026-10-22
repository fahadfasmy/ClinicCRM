<?php
// signup.php — a new clinic signs up on the platform's address: clinic name, web address, DHA licence, TRN, owner name,
// email, mobile, and acceptance of the terms, privacy policy and data processing agreement. We email a verification
// link (verify.php); the clinic is created only when it is clicked. 14-day Growth trial, no card. Rate-limited per IP.
const PLATFORM_REQUEST = true;   // the platform's address, not a clinic's
require_once __DIR__ . '/includes/platform_auth.php';
require_once __DIR__ . '/includes/legal.php';
platformPage();

$p = platformDB();
$err = ''; $sent = false;
$v = fn(string $k) => trim((string)($_POST[$k] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pCsrfCheck();
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $n = $p->prepare("SELECT COUNT(*) FROM signup_requests WHERE ip = ? AND created_at > NOW() - INTERVAL 1 HOUR"); $n->execute([$ip]);
        if ($n->fetchColumn() >= 5) throw new RuntimeException('Too many sign-ups from this connection. Please try again in an hour.');
        $d = ['name' => mb_substr($v('name'), 0, 150), 'slug' => strtolower($v('slug')), 'dha_license' => mb_substr($v('dha_license'), 0, 40), 'trn' => preg_replace('/\D/', '', $v('trn')),
              'owner_name' => mb_substr($v('owner_name'), 0, 120), 'mobile' => mb_substr($v('mobile'), 0, 30)];
        $email = strtolower($v('email'));
        if (mb_strlen($d['name']) < 2 || mb_strlen($d['owner_name']) < 2) throw new RuntimeException('Enter the clinic name and your name.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
        if (!preg_match('/^[a-z][a-z0-9-]{1,38}[a-z0-9]$/', $d['slug'])) throw new RuntimeException('The web address: 3-40 lower-case letters, digits and dashes, starting with a letter.');
        if (in_array($d['slug'], ['app', 'www', 'admin', 'platform', 'api', 'mail', 'support', 'help', 'status', 'billing'], true) || tenantBySlug($d['slug'])) throw new RuntimeException('That web address is taken. Try another.');
        if ($d['dha_license'] === '') throw new RuntimeException('Enter the clinic’s DHA licence number.');
        if ($d['trn'] !== '' && strlen($d['trn']) !== 15) throw new RuntimeException('A TRN has 15 digits (or leave it empty if the clinic isn’t VAT-registered).');
        if (!preg_match('/^\+?[0-9 ()-]{7,20}$/', $d['mobile'])) throw new RuntimeException('Enter a mobile number.');
        if (empty($_POST['accept'])) throw new RuntimeException('Please accept the Terms of Service, Privacy Policy and Data Processing Agreement.');
        $token = bin2hex(random_bytes(32));
        $p->prepare("INSERT INTO signup_requests (token_hash, email, data, ip) VALUES (?,?,?,?)")->execute([hash('sha256', $token), $email, json_encode($d, JSON_UNESCAPED_UNICODE), $ip]);
        $sid = (int)$p->lastInsertId();
        recordLegalAcceptance($p, null, $sid, $email, $ip);
        $link = platformUrl('/verify.php?t=' . $token);
        platformMail($email, 'Confirm your email to create ' . $d['name'],
            '<p>Hello ' . e($d['owner_name']) . ',</p><p>Confirm your email to create <b>' . e($d['name']) . '</b> on Clinic CRM:</p><p><a href="' . e($link) . '">' . e($link) . '</a></p>'
            . '<p>The link works for 24 hours. If you didn’t sign up, ignore this email.</p>');
        $sent = true;
    } catch (RuntimeException $x) { $err = $x->getMessage(); }
}
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Start your free trial · Clinic CRM</title>
<style>body{font:16px/1.5 system-ui,Segoe UI,Arial,sans-serif;background:#f7f3ee;color:#1d1b18;margin:0}main{max-width:560px;margin:40px auto;padding:0 18px}
.card{background:#fff;border-radius:14px;padding:26px;box-shadow:0 2px 14px rgba(0,0,0,.06)}label{display:block;font-weight:600;font-size:14px;margin:12px 0 4px}
input{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #d8cfc2;border-radius:8px;font:inherit}.row{display:flex;gap:10px}.row>div{flex:1}
button{margin-top:18px;width:100%;padding:12px;border:0;border-radius:8px;background:#b8925a;color:#fff;font:600 16px system-ui;cursor:pointer}.err{background:#fde8e8;color:#8a1f1f;padding:10px 12px;border-radius:8px}
.hint{color:#6b6359;font-size:13px}.check{display:flex;gap:8px;align-items:flex-start;margin-top:14px}.check input{width:auto;margin-top:4px}a{color:#8a6a3a}</style></head><body><main>
<h1>Start your free trial</h1><p class="hint">14 days of the Growth plan, no card needed. Your clinic’s data stays in the UAE.</p>
<div class="card">
<?php if ($sent): ?>
  <h2>Check your email</h2><p>We sent a link to <b><?= e($email) ?></b>. Click it to create your clinic. It works for 24 hours.</p>
<?php else: ?>
  <?php if ($err): ?><p class="err" role="alert"><?= e($err) ?></p><?php endif; ?>
  <form method="POST" novalidate><?= pCsrfField() ?>
    <label for="sName">Clinic name</label><input id="sName" name="name" required maxlength="150" value="<?= e($v('name')) ?>" autocomplete="organization">
    <label for="sSlug">Web address</label><input id="sSlug" name="slug" required maxlength="40" pattern="[a-z][a-z0-9-]{1,38}[a-z0-9]" value="<?= e($v('slug')) ?>" aria-describedby="slugHint" dir="ltr">
    <p class="hint" id="slugHint">Your clinic will be at <b><span id="slugShow"><?= e($v('slug') ?: 'yourclinic') ?></span>.<?= e(defined('PRODUCT_DOMAIN') ? PRODUCT_DOMAIN : '') ?></b></p>
    <div class="row"><div><label for="sDha">DHA licence no.</label><input id="sDha" name="dha_license" required maxlength="40" value="<?= e($v('dha_license')) ?>"></div>
      <div><label for="sTrn">TRN (optional)</label><input id="sTrn" name="trn" inputmode="numeric" maxlength="15" value="<?= e($v('trn')) ?>" dir="ltr"></div></div>
    <label for="sOwner">Your name</label><input id="sOwner" name="owner_name" required maxlength="120" value="<?= e($v('owner_name')) ?>" autocomplete="name">
    <div class="row"><div><label for="sEmail">Email</label><input id="sEmail" name="email" type="email" required value="<?= e($v('email')) ?>" autocomplete="email" dir="ltr"></div>
      <div><label for="sMob">Mobile</label><input id="sMob" name="mobile" type="tel" required value="<?= e($v('mobile')) ?>" autocomplete="tel" dir="ltr" placeholder="+971 50 …"></div></div>
    <div class="check"><input type="checkbox" id="sAcc" name="accept" value="1" required><label for="sAcc" style="margin:0;font-weight:400">I accept the <a href="/terms.php" target="_blank">Terms of Service</a>, the <a href="/privacy.php" target="_blank">Privacy Policy</a> and the <a href="/dpa.php" target="_blank">Data Processing Agreement</a> on behalf of the clinic.</label></div>
    <button>Create my clinic</button>
  </form>
<?php endif; ?>
</div></main>
<script>
(function () {
  var n = document.getElementById('sName'), s = document.getElementById('sSlug'), show = document.getElementById('slugShow'); if (!s) return;
  var touched = s.value !== '';
  s.addEventListener('input', function () { touched = true; show.textContent = s.value || 'yourclinic'; });
  n.addEventListener('input', function () { if (touched) return; s.value = n.value.toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').replace(/^[^a-z]+/, '').slice(0, 40); show.textContent = s.value || 'yourclinic'; });
})();
</script></body></html>
