<?php
// pages/encounter_print.php?id=&doc=rx&rx=&lang=en|ar|both — a prescription to print (A4/A5), in English, Arabic or both;
// ?doc=summary — the visit summary. Printed from the browser (a stored PDF comes with the document layouts phase).
// Same access rules as the note; every print is logged.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/emr.php';
requirePermission('emr.view');

$db = getDB();
$e = findEncounter($db, (int)($_GET['id'] ?? 0));
if (!$e || !canSeeEncounter($e)) { http_response_code(404); exit('Not found'); }
$p = findPatient($db, (int)$e['patient_id']);
$doc = ($_GET['doc'] ?? '') === 'rx' ? 'rx' : 'summary';
$lang = in_array($_GET['lang'] ?? '', ['en', 'ar', 'both'], true) ? $_GET['lang'] : 'en';
if ($doc === 'summary') $lang = 'en';
$ar = $lang !== 'en'; $en = $lang !== 'ar';
// Label in the chosen language(s): English / عربي
$L = function (string $en_, string $ar_) use ($lang): string {
    return $lang === 'en' ? $en_ : ($lang === 'ar' ? $ar_ : $en_ . ' / ' . $ar_);
};
const RX_ROUTES_AR = ['Oral' => 'عن طريق الفم', 'Topical' => 'موضعي', 'Intradermal' => 'داخل الأدمة', 'Subcutaneous' => 'تحت الجلد', 'Intramuscular' => 'في العضل',
                      'Intravenous' => 'في الوريد', 'Inhaled' => 'استنشاق', 'Nasal' => 'عن طريق الأنف', 'Ophthalmic' => 'في العين', 'Otic' => 'في الأذن', 'Rectal' => 'شرجي',
                      'Sublingual' => 'تحت اللسان', 'Vaginal' => 'مهبلي'];
$tr = function (?string $v, array $map) use ($lang): string {
    $v = (string)$v;
    if ($v === '' || $lang === 'en' || !isset($map[$v])) return sanitize($v);
    return $lang === 'ar' ? sanitize($map[$v]) : sanitize($v) . '<br><span class="ar">' . sanitize($map[$v]) . '</span>';
};

$rx = null; $items = [];
if ($doc === 'rx') {
    $s = $db->prepare("SELECT * FROM prescriptions WHERE id = ? AND encounter_id = ?"); $s->execute([(int)($_GET['rx'] ?? 0), $e['id']]);
    $rx = $s->fetch();
    if (!$rx) { http_response_code(404); exit('Prescription not found'); }
    $s = $db->prepare("SELECT * FROM prescription_items WHERE prescription_id = ? ORDER BY id"); $s->execute([$rx['id']]);
    $items = $s->fetchAll();
    $db->prepare("UPDATE prescriptions SET printed_at = COALESCE(printed_at, NOW()) WHERE id = ?")->execute([$rx['id']]);
}
logPatientAccess($db, (int)$p['id'], 'print:' . $doc, (int)$e['id']);
$allergies = patientAllergies($db, (int)$p['id']);
$age = $p['dob'] ? (new DateTimeImmutable($p['dob']))->diff(new DateTimeImmutable('today'))->y : null;
$clinic = clinicName();
$date = date('d/m/Y', strtotime($rx['created_at'] ?? ($e['scheduled_at'] ?: $e['created_at'])));
sendStaffPageHeaders();
?><!DOCTYPE html>
<html lang="<?= $lang === 'ar' ? 'ar' : 'en' ?>" dir="<?= $lang === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $doc === 'rx' ? 'Prescription' : 'Visit summary' ?> · <?= sanitize($p['mrn']) ?></title>
<link rel="stylesheet" href="/assets/vendor/fonts/fonts.css">
<style>
  @page { size: A4; margin: 14mm; }
  * { box-sizing: border-box; }
  body { font-family: 'DM Sans', 'IBM Plex Sans Arabic', Arial, sans-serif; color: #1d1b18; font-size: 12.5px; margin: 0; padding: 24px; background: #fff; }
  .ar { font-family: 'IBM Plex Sans Arabic', 'DM Sans', sans-serif; }
  .page { max-width: 760px; margin: 0 auto; }
  .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #b8925a; padding-bottom: 10px; margin-bottom: 14px; gap: 16px; }
  .clinic { font-size: 18px; font-weight: 700; }
  .muted { color: #6b645b; font-size: 11.5px; }
  h1 { font-size: 16px; margin: 0 0 10px; letter-spacing: .02em; }
  table { width: 100%; border-collapse: collapse; }
  .info td { padding: 3px 6px; vertical-align: top; }
  .info td.k { color: #6b645b; width: 1%; white-space: nowrap; }
  .items { margin-top: 12px; }
  .items th, .items td { border: 1px solid #d9d2c6; padding: 6px; text-align: start; vertical-align: top; }
  .items th { background: #f5efe5; font-size: 11.5px; }
  .drug { font-weight: 700; }
  .alert { border: 1px solid #c0392b; color: #8e2a1f; padding: 6px 8px; margin: 10px 0; border-radius: 4px; }
  .sig { margin-top: 36px; display: flex; justify-content: space-between; gap: 20px; }
  .sig .line { border-top: 1px solid #1d1b18; padding-top: 4px; min-width: 220px; }
  .section { margin-top: 12px; } .section h2 { font-size: 13px; margin: 0 0 4px; color: #8a6934; }
  .toolbar { text-align: center; margin-bottom: 16px; } .toolbar button { padding: 6px 14px; }
  bdi { unicode-bidi: isolate; }
  @media print { .toolbar { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Print</button></div>
<div class="page">
  <div class="head">
    <div><div class="clinic"><?= sanitize($clinic) ?></div>
      <?php if ($ar && $e['branch_name_ar']): ?><div class="clinic ar" dir="rtl"><?= sanitize($e['branch_name_ar']) ?></div><?php endif; ?>
      <div class="muted"><?= sanitize(tenantSetting('clinic.address')) ?></div>
      <div class="muted"><bdi><?= sanitize(tenantSetting('clinic.phone')) ?></bdi></div></div>
    <div style="text-align:end"><h1><?= $doc === 'rx' ? $L('Prescription', 'وصفة طبية') : 'Visit summary' ?></h1><div class="muted"><?= $L('Date', 'التاريخ') ?>: <bdi><?= $date ?></bdi></div></div>
  </div>

  <table class="info">
    <tr><td class="k"><?= $L('Patient', 'المريض') ?></td><td><strong><?= sanitize(patientName($p)) ?></strong><?= $ar && $p['name_ar'] ? ' <span class="ar">' . sanitize($p['name_ar']) . '</span>' : '' ?></td>
        <td class="k"><?= $L('File no.', 'رقم الملف') ?></td><td><bdi><?= sanitize($p['mrn']) ?></bdi></td></tr>
    <tr><td class="k"><?= $L('Age / sex', 'العمر / الجنس') ?></td><td><?= $age !== null ? $age . ($lang === 'ar' ? ' سنة' : ' yrs') : '–' ?> · <?= $p['gender'] === 'female' ? $L('Female', 'أنثى') : ($p['gender'] === 'male' ? $L('Male', 'ذكر') : '–') ?></td>
        <td class="k"><?= $L('Doctor', 'الطبيب') ?></td><td><?= sanitize($e['practitioner_name'] ?? '–') ?></td></tr>
  </table>
  <?php if ($allergies): ?><div class="alert"><strong><?= $L('Allergies', 'الحساسية') ?>:</strong> <?= sanitize(implode(', ', array_column($allergies, 'allergen'))) ?></div><?php endif; ?>

  <?php if ($doc === 'rx'): ?>
  <table class="items">
    <thead><tr><th>#</th><th><?= $L('Medicine', 'الدواء') ?></th><th><?= $L('Dose', 'الجرعة') ?></th><th><?= $L('Route', 'طريقة الاستعمال') ?></th>
      <th><?= $L('How often', 'عدد المرات') ?></th><th><?= $L('For', 'المدة') ?></th><th><?= $L('Qty', 'الكمية') ?></th></tr></thead>
    <tbody>
    <?php foreach ($items as $n => $i): ?>
      <tr><td><?= $n + 1 ?></td>
        <td><span class="drug" dir="ltr"><bdi><?= sanitize($i['drug']) ?> <?= sanitize((string)$i['strength']) ?></bdi></span><?= $i['instructions'] ? '<div class="muted">' . sanitize($i['instructions']) . '</div>' : '' ?></td>
        <td><bdi><?= sanitize((string)$i['dose']) ?></bdi></td><td><?= $tr($i['route'], RX_ROUTES_AR) ?></td><td><?= $tr($i['frequency'], RX_FREQUENCIES) ?></td>
        <td><bdi><?= sanitize((string)$i['duration']) ?></bdi></td><td><bdi><?= sanitize((string)$i['qty']) ?></bdi></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($rx['notes']): ?><div class="section"><?= nl2br(sanitize($rx['notes'])) ?></div><?php endif; ?>
  <?php else:
    $v = $db->prepare("SELECT * FROM vitals WHERE encounter_id = ? ORDER BY id DESC LIMIT 1"); $v->execute([$e['id']]); $v = $v->fetch();
    $dx = $db->prepare("SELECT * FROM encounter_diagnoses WHERE encounter_id = ? ORDER BY is_primary DESC, id"); $dx->execute([$e['id']]);
    $pr = $db->prepare("SELECT * FROM encounter_procedures WHERE encounter_id = ? ORDER BY id"); $pr->execute([$e['id']]);
    $am = $db->prepare("SELECT a.*, s.name AS by_name FROM encounter_amendments a LEFT JOIN staff s ON s.id = a.staff_id WHERE a.encounter_id = ? ORDER BY a.id"); $am->execute([$e['id']]); ?>
    <?php if ($v): ?><div class="section"><h2>Vitals</h2><?= implode(' · ', array_filter([$v['bp_sys'] ? 'BP ' . $v['bp_sys'] . '/' . $v['bp_dia'] : '', $v['pulse'] ? 'Pulse ' . $v['pulse'] : '', $v['temp_c'] ? 'Temp ' . $v['temp_c'] . ' °C' : '',
        $v['spo2'] ? 'SpO₂ ' . $v['spo2'] . '%' : '', $v['weight_kg'] ? 'Weight ' . (float)$v['weight_kg'] . ' kg' : '', $v['bmi'] ? 'BMI ' . $v['bmi'] : ''])) ?></div><?php endif; ?>
    <?php foreach (EMR_NOTE_FIELDS as $k => $l): if (trim((string)$e[$k]) === '') continue; ?><div class="section"><h2><?= $l ?></h2><?= nl2br(sanitize($e[$k])) ?></div><?php endforeach; ?>
    <?php $dxs = $dx->fetchAll(); if ($dxs): ?><div class="section"><h2>Diagnoses</h2><?php foreach ($dxs as $d): ?><div><?= sanitize(icdFormat($d['icd10_code'])) ?> – <?= sanitize($d['description']) ?><?= $d['is_primary'] ? ' (primary)' : '' ?><?= $d['kind'] === 'provisional' ? ' (provisional)' : '' ?></div><?php endforeach; ?></div><?php endif; ?>
    <?php $prs = $pr->fetchAll(); if ($prs): ?><div class="section"><h2>Procedures</h2><?php foreach ($prs as $x): ?><div><?= sanitize($x['description']) ?><?= $x['area'] ? ' – ' . sanitize($x['area']) : '' ?><?= $x['tooth'] ? ' – tooth ' . sanitize($x['tooth']) : '' ?> × <?= (float)$x['qty'] ?></div><?php endforeach; ?></div><?php endif; ?>
    <?php foreach ($am->fetchAll() as $a): ?><div class="section"><h2>Addendum <?= date('d/m/Y H:i', strtotime($a['at'])) ?> – <?= sanitize($a['by_name'] ?? '') ?></h2><em><?= sanitize($a['reason']) ?></em><br><?= nl2br(sanitize($a['text'])) ?></div><?php endforeach; ?>
  <?php endif; ?>

  <div class="sig">
    <div class="line"><strong><?= sanitize($e['practitioner_name'] ?? '') ?></strong><br>
      <?= $e['specialty'] ? sanitize($e['specialty']) . '<br>' : '' ?><?= $e['dha_license'] ? $L('DHA licence', 'ترخيص هيئة الصحة بدبي') . ': <bdi>' . sanitize($e['dha_license']) . '</bdi>' : '' ?></div>
    <div class="line"><?= $L('Signature', 'التوقيع') ?><?= $e['signed_at'] ? '<br><span class="muted">' . $L('Signed electronically', 'موقّع إلكترونياً') . ' <bdi>' . date('d/m/Y H:i', strtotime($e['signed_at'])) . '</bdi></span>' : '' ?></div>
  </div>
</div>
</body>
</html>
