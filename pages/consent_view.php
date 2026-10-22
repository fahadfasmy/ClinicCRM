<?php
// pages/consent_view.php?id= — a signed consent as the patient saw it (the frozen copy), with the signatures and the
// record of when, where and on which device it was signed. Printable. Every opening is logged.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/consents.php';
requirePermission('patients.view');

$db = getDB();
$c = findConsent($db, (int)($_GET['id'] ?? 0));
if (!$c) { http_response_code(404); exit('Not found'); }
$p = findPatient($db, (int)$c['patient_id']);
logPatientAccess($db, (int)$c['patient_id'], 'consent:' . $c['id']);
sendStaffPageHeaders();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= sanitize($c['name']) ?> · <?= sanitize($p['mrn']) ?></title>
<link rel="stylesheet" href="/assets/vendor/fonts/fonts.css">
<style>
  @page { size: A4; margin: 14mm; }
  body { font-family: 'DM Sans', 'IBM Plex Sans Arabic', Arial, sans-serif; color: #1d1b18; font-size: 13px; margin: 0; padding: 24px; background: #fff; }
  .page { max-width: 760px; margin: 0 auto; }
  .head { display: flex; justify-content: space-between; gap: 16px; border-bottom: 2px solid #b8925a; padding-bottom: 10px; margin-bottom: 14px; }
  .clinic { font-size: 18px; font-weight: 700; }
  .muted { color: #6b645b; font-size: 11.5px; }
  h2 { font-size: 16px; }
  [lang="ar"] { font-family: 'IBM Plex Sans Arabic', 'DM Sans', sans-serif; margin-top: 18px; }
  .sigs { display: flex; gap: 24px; flex-wrap: wrap; margin-top: 18px; }
  .sig img { height: 90px; border-bottom: 1px solid #999; display: block; }
  .revoked { border: 2px solid #b42318; color: #b42318; padding: 8px 12px; font-weight: 700; margin-bottom: 12px; }
  table { border-collapse: collapse; margin-top: 18px; } td { padding: 2px 12px 2px 0; vertical-align: top; }
  .noprint { margin-bottom: 14px; } @media print { .noprint { display: none; } body { padding: 0; } }
</style>
</head>
<body><div class="page">
  <div class="noprint"><button onclick="window.print()">Print</button></div>
  <div class="head"><div><div class="clinic"><?= sanitize(clinicName()) ?></div><div class="muted">Consent form · version <?= (int)$c['template_version'] ?></div></div>
    <div style="text-align:right"><strong><?= sanitize(patientName($p)) ?></strong><div class="muted"><?= sanitize($p['mrn']) ?><?= $p['dob'] ? ' · born ' . date('d M Y', strtotime($p['dob'])) : '' ?></div></div></div>
  <?php if ($c['revoked_at']): ?><div class="revoked">Withdrawn on <?= date('d M Y H:i', strtotime($c['revoked_at'])) ?><?= $c['revoked_by_name'] ? ' (recorded by ' . sanitize($c['revoked_by_name']) . ')' : '' ?>: <?= sanitize((string)$c['revoke_reason']) ?></div><?php endif; ?>
  <?= $c['snapshot_html'] /* built by consentSnapshot() from escaped text at signing time */ ?>
  <div class="sigs">
    <div class="sig"><img src="/pages/download.php?consent=<?= (int)$c['id'] ?>" alt="Patient signature"><div class="muted">Patient · <?= sanitize(patientName($p)) ?></div></div>
    <?php if ($c['witness_signature_path'] || $c['witness_name']): ?><div class="sig"><?php if ($c['witness_signature_path']): ?><img src="/pages/download.php?consent=<?= (int)$c['id'] ?>&amp;part=witness" alt="Witness signature"><?php endif; ?><div class="muted">Witness · <?= sanitize((string)$c['witness_name']) ?></div></div><?php endif; ?>
  </div>
  <table class="muted">
    <tr><td>Signed</td><td><?= date('d M Y H:i', strtotime($c['signed_at'])) ?></td></tr>
    <tr><td>Handed over by</td><td><?= sanitize((string)$c['collected_by_name']) ?></td></tr>
    <tr><td>Device</td><td><?= sanitize((string)$c['user_agent']) ?> · <?= sanitize((string)$c['ip']) ?></td></tr>
  </table>
</div></body>
</html>
