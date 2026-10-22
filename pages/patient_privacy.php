<?php
// pages/patient_privacy.php?id= — a patient's data request (patients.privacy, UAE PDPL): download everything held about
// them (JSON, or a printable copy to save as PDF), or anonymise their record. Both are written to the audit and
// chart-access logs. Anonymising keeps invoices and receipts (tax record) and clinical records (medical record law).
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
requirePermission('patients.privacy');

$db = getDB();
$pid = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$s = $db->prepare("SELECT * FROM patients WHERE id = ?"); $s->execute([$pid]);
$p = $s->fetch();
if (!$p) { http_response_code(404); exit('Patient not found.'); }
$name = trim($p['first_name'] . ' ' . $p['last_name']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        anonymisePatient($db, $pid, (string)($_POST['reason'] ?? ''), (string)($_POST['confirm'] ?? ''));
        $_SESSION['pp_flash'] = ['success', 'The record is anonymised.'];
    } catch (RuntimeException $e) { $_SESSION['pp_flash'] = ['danger', $e->getMessage()]; }
    header("Location: /pages/patient_privacy.php?id=$pid"); exit;
}

if (isset($_GET['export'])) {
    $data = patientExport($db, $pid);
    if ($_GET['export'] === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="patient-' . preg_replace('/[^A-Za-z0-9-]/', '', $p['mrn']) . '-' . date('Ymd') . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    // Printable copy (the browser saves it as PDF)
    $cell = fn($v) => sanitize(is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . sanitize("Patient data · {$p['mrn']}") . '</title><style>body{font:12px/1.4 system-ui,Segoe UI,Arial,sans-serif;margin:24px;color:#111}'
       . 'h1{font-size:18px}h2{font-size:14px;margin:18px 0 6px;text-transform:capitalize}table{border-collapse:collapse;width:100%;margin-bottom:6px}th,td{border:1px solid #bbb;padding:3px 5px;text-align:left;vertical-align:top;word-break:break-word}th{background:#f1f1f1}'
       . '@media print{.noprint{display:none}}</style></head><body><button class="noprint" onclick="print()">Print / save as PDF</button>'
       . '<h1>' . sanitize(clinicName() . ' — data held about ' . $name . ' (MRN ' . $p['mrn'] . ')') . '</h1><p>Prepared ' . date('d M Y H:i') . '.</p>';
    foreach ($data as $k => $v) {
        if (!is_array($v)) continue;
        echo '<h2>' . sanitize(str_replace('_', ' ', $k)) . '</h2>';
        $rows = array_is_list($v) ? $v : [$v];
        if (!$rows) continue;
        $cols = array_keys($rows[0]);
        if (!array_is_list($v)) { echo '<table>'; foreach ($v as $ck => $cv) echo '<tr><th style="width:25%">' . sanitize((string)$ck) . '</th><td>' . $cell($cv) . '</td></tr>'; echo '</table>'; continue; }
        echo '<table><thead><tr>' . implode('', array_map(fn($c) => '<th>' . sanitize((string)$c) . '</th>', $cols)) . '</tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr>' . implode('', array_map(fn($c) => '<td>' . $cell($r[$c] ?? '') . '</td>', $cols)) . '</tr>';
        echo '</tbody></table>';
    }
    exit('</body></html>');
}

$flash = $_SESSION['pp_flash'] ?? null; unset($_SESSION['pp_flash']);
$future = $db->prepare("SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND scheduled_at > NOW() AND status IN ('booked','confirmed')"); $future->execute([$pid]);
$future = (int)$future->fetchColumn();
$pageTitle = 'Data request';
$pageSubtitle = $name . ' · MRN ' . $p['mrn'];
$pageBack = ['href' => "/pages/patient.php?id=$pid", 'label' => 'Back to the patient'];
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?>" role="<?= $flash[0] === 'danger' ? 'alert' : 'status' ?>"><?= sanitize($flash[1]) ?></div><?php endif; ?>
<div class="row g-3">
  <div class="col-lg-6"><div class="card-crm h-100"><div class="card-header-crm"><span><i class="bi bi-download"></i> Give the patient a copy of their data</span></div><div class="card-body-crm fs-13">
    <p>Everything held about them: their details, phone numbers, appointments, clinical notes and diagnoses, prescriptions, consents, invoices, receipts, packages, wallet, insurance, messages and who opened their chart. Emirates ID shows its last 4 digits only; scans and photos are listed, not included.</p>
    <p>Check who is asking before you hand it over. Each download is logged.</p>
    <div class="d-flex gap-2 flex-wrap"><a class="btn btn-gold" href="?id=<?= $pid ?>&amp;export=json"><i class="bi bi-filetype-json"></i>Download (JSON)</a>
      <a class="btn btn-ghost" target="_blank" rel="noopener" href="?id=<?= $pid ?>&amp;export=print"><i class="bi bi-printer"></i>Printable copy / PDF</a></div>
  </div></div></div>
  <div class="col-lg-6"><div class="card-crm h-100"><div class="card-header-crm"><span><i class="bi bi-person-x"></i> Anonymise this record</span></div><div class="card-body-crm fs-13">
    <?php if ($p['status'] === 'anonymised'): ?><p class="mb-0"><span class="chip tone-slate">Anonymised</span> This record no longer holds the person’s identity.</p>
    <?php else: ?>
    <p><b>Removed:</b> name (shown as “Anonymised #MRN”), Arabic name, exact birth date (year kept), email, phone numbers, Emirates ID and passport (and their scans), emergency contact, photo, notes, tags, consent to marketing and WhatsApp, the text of their messages, and the lead they came from.</p>
    <p><b>Kept, because the law requires it:</b> invoices, receipts and credit notes as issued (tax record, including the name printed on them), clinical notes, prescriptions, consent forms and clinical photos (medical record), and insurance claims. This can’t be undone.</p>
    <?php if ($future): ?><div class="alert alert-warning py-2"><?= $future ?> future appointment<?= $future === 1 ? '' : 's' ?>: cancel <?= $future === 1 ? 'it' : 'them' ?> first.</div><?php endif; ?>
    <form method="POST"><?= csrfField() ?><input type="hidden" name="id" value="<?= $pid ?>">
      <label class="form-label" for="ppR">Why (who asked, when, how)</label><input class="form-control mb-2" id="ppR" name="reason" required minlength="5" maxlength="300">
      <label class="form-label" for="ppC">Type the MRN <b><?= sanitize($p['mrn']) ?></b> to confirm</label><input class="form-control mb-2" id="ppC" name="confirm" required autocomplete="off">
      <button class="btn btn-danger" <?= $future ? 'disabled' : '' ?>><i class="bi bi-person-x"></i>Anonymise</button></form>
    <?php endif; ?>
  </div></div></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
