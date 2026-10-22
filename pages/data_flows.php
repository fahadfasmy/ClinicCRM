<?php
// pages/data_flows.php — Admin → Data flows & backups (data.flows.view). Every place data can leave the clinic (the
// register in includes/data_flows.php) with whether this clinic has it switched on, what it sends and where the provider
// processes it; the UAE residency check; templates allowed to carry health information; nightly backups; restore
// drills (recorded here so they can be shown to DHA auditors).
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/data_flows.php';
require_once __DIR__ . '/../includes/messaging.php';
require_once __DIR__ . '/../includes/meta_leads.php';
require_once __DIR__ . '/../includes/gcal.php';
requirePermission('data.flows.view');

$db = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try { recordRestoreDrill($db, $_POST); $_SESSION['df_flash'] = ['success', 'Restore drill recorded.']; }
    catch (RuntimeException $e) { $_SESSION['df_flash'] = ['danger', $e->getMessage()]; }
    header('Location: /pages/data_flows.php#drills'); exit;
}
$flash = $_SESSION['df_flash'] ?? null; unset($_SESSION['df_flash']);

$on = [
    'smtp' => mailConfigured($db), 'meta_graph' => metaSetting($db, 'token') !== '', 'google_oauth' => gcalConfigured($db), 'google_calendar' => gcalConfigured($db),
    'whatsapp_cloud' => waReady($db), 'sms' => !in_array(smsProvider($db), ['none', 'mock'], true),
];
$problems = residencyCheck($db);
$health = $db->query("SELECT name, channel FROM message_templates WHERE contains_health_info = 1 AND is_active = 1 ORDER BY name")->fetchAll();
$backup = backupStatus($db);
$last = $backup['last'];
$drills = $db->query("SELECT d.*, s.name AS staff FROM restore_drills d LEFT JOIN staff s ON s.id = d.staff_id ORDER BY d.drilled_on DESC, d.id DESC LIMIT 20")->fetchAll();
$lastDrill = $drills[0]['drilled_on'] ?? null;
$healthTone = ['no' => ['No', 'tone-green'], 'low' => ['Low', 'tone-amber'], 'yes' => ['Yes', 'tone-red']];

$pageTitle = 'Data flows & backups';
$pageSubtitle = 'Where data leaves the clinic, and how it is kept safe · data region ' . (defined('DATA_REGION') ? DATA_REGION : 'AE');
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?>" role="<?= $flash[0] === 'danger' ? 'alert' : 'status' ?>"><?= sanitize($flash[1]) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card-crm h-100"><div class="card-body-crm">
    <div class="fs-12 text-tx-3 text-uppercase fw-600">UAE residency check</div>
    <?php if ($problems): ?><div class="fs-5 fw-700 text-danger"><i class="bi bi-x-circle"></i> <?= count($problems) ?> problem<?= count($problems) === 1 ? '' : 's' ?></div>
      <ul class="fs-13 mb-0 mt-1"><?php foreach ($problems as $p): ?><li><?= sanitize($p) ?></li><?php endforeach; ?></ul>
    <?php else: ?><div class="fs-5 fw-700 text-green"><i class="bi bi-check-circle"></i> Passed</div><div class="fs-13 text-tx-3">Every configured service is in the register below, and no staff or patient page loads files from another site.</div><?php endif; ?>
  </div></div></div>
  <div class="col-md-4"><div class="card-crm h-100"><div class="card-body-crm">
    <div class="fs-12 text-tx-3 text-uppercase fw-600">Last nightly backup</div>
    <?php if (!empty($last['file'])): ?><div class="fs-5 fw-700"><?= sanitize(date('d M Y H:i', strtotime($last['at']))) ?></div>
      <div class="fs-13 text-tx-3"><?= number_format((int)$last['rows']) ?> rows in <?= (int)$last['tables'] ?> tables · <?= number_format($last['size'] / 1048576, 1) ?> MB · <?= $backup['count'] ?> backups kept</div>
    <?php else: ?><div class="fs-5 fw-700 text-amber">None yet</div><div class="fs-13 text-tx-3">The first runs tonight after 02:00 (cron.php). <?= $backup['count'] ?> backup file(s) found.</div><?php endif; ?>
    <?php if (!empty($last['error'])): ?><div class="fs-13 text-danger mt-1"><i class="bi bi-exclamation-triangle"></i> Last attempt failed <?= sanitize(timeSince($last['error_at'])) ?>: <?= sanitize($last['error']) ?></div><?php endif; ?>
  </div></div></div>
  <div class="col-md-4"><div class="card-crm h-100"><div class="card-body-crm">
    <div class="fs-12 text-tx-3 text-uppercase fw-600">Last restore drill</div>
    <?php if ($lastDrill): $age = (int)((time() - strtotime($lastDrill)) / 86400); ?>
      <div class="fs-5 fw-700 <?= $drills[0]['result'] === 'pass' ? '' : 'text-danger' ?>"><?= date('d M Y', strtotime($lastDrill)) ?> · <?= $drills[0]['result'] === 'pass' ? 'passed' : 'FAILED' ?></div>
      <div class="fs-13 <?= $age > 92 ? 'text-danger' : 'text-tx-3' ?>"><?= $age ?> days ago<?= $age > 92 ? ' — one is due (every 3 months)' : '' ?></div>
    <?php else: ?><div class="fs-5 fw-700 text-amber">None recorded</div><div class="fs-13 text-tx-3">Restore a backup every 3 months and record it below (docs/RESTORE_DRILL.md).</div><?php endif; ?>
  </div></div></div>
</div>

<div class="card-crm mb-3"><div class="card-header-crm"><span><i class="bi bi-diagram-3"></i> Where data can go</span></div>
  <div class="table-responsive"><table class="table mb-0 align-middle fs-13">
    <thead><tr><th>Service</th><th>On here</th><th>What it sends</th><th>Health information</th><th>Where it is processed</th><th>Note</th></tr></thead>
    <tbody><?php foreach (DATA_FLOWS as $k => $fl): ?><tr>
      <td><div class="fw-600"><?= sanitize($fl['name']) ?></div><div class="text-tx-3"><?= sanitize(implode(', ', $fl['hosts']) ?: '—') ?></div></td>
      <td><?php if (array_key_exists($k, $on)): ?><span class="chip <?= $on[$k] ? 'tone-green' : 'tone-slate' ?>"><?= $on[$k] ? 'On' : 'Off' ?></span><?php else: ?><span class="text-tx-3"><?= sanitize($fl['side'] === 'inbound' ? 'Receives only' : 'In the page code') ?></span><?php endif; ?></td>
      <td><?= sanitize($fl['sends']) ?></td>
      <td><span class="chip <?= $healthTone[$fl['health']][1] ?>"><?= $healthTone[$fl['health']][0] ?></span></td>
      <td><?= sanitize($fl['where']) ?></td>
      <td class="text-tx-3"><?= sanitize($fl['action']) ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <div class="card-body-crm fs-13 text-tx-3">Health information: <b>No</b> = nothing about a person’s health; <b>Low</b> = that someone enquired or has an appointment, without treatment or clinical details; <b>Yes</b> = treatment or clinical details. Patient data itself stays in this system’s database and files.</div>
</div>

<div class="card-crm mb-3"><div class="card-header-crm"><span><i class="bi bi-chat-square-text"></i> Message templates allowed to carry health information</span></div><div class="card-body-crm fs-13">
  <?php if ($health): ?><p class="mb-1">The owner switched these on (with the acknowledgement, in the audit log):</p><ul class="mb-0"><?php foreach ($health as $h): ?><li><?= sanitize($h['name']) ?> (<?= sanitize($h['channel']) ?>)</li><?php endforeach; ?></ul>
  <?php else: ?><p class="mb-0 text-tx-3">None. Messages carry only first name, date, time, branch and links.</p><?php endif; ?>
</div></div>

<div class="card-crm mb-3" id="drills"><div class="card-header-crm"><span><i class="bi bi-arrow-counterclockwise"></i> Backups and restore drills</span></div>
  <div class="card-body-crm fs-13">
    <p>Every night after 02:00 the database is backed up to a folder outside the website (<?= sanitize(BACKUP_PATH) ?>), with a checksum. Kept: the last 14 days, and the first backup of each month for 12 months. Uploaded files (scans, photos, signatures) are in the storage folder and need the host’s own backups as well.</p>
    <?php if ($backup['files']): ?><details class="mb-3"><summary>Backup files (newest <?= count($backup['files']) ?>)</summary><ul class="mb-0 mt-1"><?php foreach ($backup['files'] as $bf): ?><li><?= sanitize($bf['name']) ?> · <?= number_format($bf['size'] / 1048576, 1) ?> MB · <?= sanitize($bf['at']) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
    <form method="POST" class="row g-2 align-items-end"><?= csrfField() ?>
      <div class="col-6 col-md-2"><label class="form-label" for="dOn">Drill day</label><input class="form-control" type="date" id="dOn" name="drilled_on" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label" for="dFile">Backup restored</label><input class="form-control" id="dFile" name="backup_file" required maxlength="200" placeholder="…_20261101_020001.sql.gz"></div>
      <div class="col-4 col-md-1"><label class="form-label" for="dTab">Tables</label><input class="form-control" type="number" min="0" id="dTab" name="tables_count"></div>
      <div class="col-4 col-md-2"><label class="form-label" for="dRows">Rows</label><input class="form-control" type="number" min="0" id="dRows" name="rows_count"></div>
      <div class="col-4 col-md-1"><label class="form-label" for="dRes">Result</label><select class="form-select" id="dRes" name="result" required><option value="pass">Passed</option><option value="fail">Failed</option></select></div>
      <div class="col-12 col-md-2"><button class="btn btn-gold w-100">Record drill</button></div>
      <div class="col-12"><label class="form-label" for="dNotes">Notes</label><input class="form-control" id="dNotes" name="notes" maxlength="500" placeholder="Where it was restored, what was checked, how long it took"></div>
    </form>
  </div>
  <?php if ($drills): ?><div class="table-responsive"><table class="table mb-0 fs-13"><thead><tr><th>Day</th><th>Backup</th><th>Result</th><th class="text-end">Tables</th><th class="text-end">Rows</th><th>Notes</th><th>Recorded by</th></tr></thead><tbody>
    <?php foreach ($drills as $d): ?><tr><td><?= sanitize($d['drilled_on']) ?></td><td><?= sanitize($d['backup_file']) ?></td><td><span class="chip <?= $d['result'] === 'pass' ? 'tone-green' : 'tone-red' ?>"><?= $d['result'] === 'pass' ? 'Passed' : 'Failed' ?></span></td>
      <td class="text-end"><?= $d['tables_count'] !== null ? number_format((int)$d['tables_count']) : '–' ?></td><td class="text-end"><?= $d['rows_count'] !== null ? number_format((int)$d['rows_count']) : '–' ?></td><td><?= sanitize((string)$d['notes']) ?></td><td><?= sanitize((string)$d['staff']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
