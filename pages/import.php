<?php
// pages/import.php — Admin → Import from ClinicSoft (data.import). Choose what to import, upload the CSV export, match
// its columns (most are recognised), run a check that saves nothing and lists every row that needs attention, then
// import the rows that are fine. Do them in order: patients first, then balances, packages, visits, invoices, notes and
// future appointments. Running the same file again adds nothing twice. Every run and its report is kept.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/importer.php';
requirePermission('data.import');

$db = getDB();
$dir = tenantStoragePath() . '/imports';
$up = $_SESSION['import'] ?? null;
$err = '';

if (isset($_GET['template']) && isset(IMPORT_KINDS[$_GET['template']])) {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="clinicsoft-' . $_GET['template'] . '-columns.csv"');
    exit(importTemplateCsv($_GET['template']));
}
if (isset($_GET['run'], $_GET['csv'])) {
    $r = $db->prepare("SELECT * FROM import_runs WHERE id = ?"); $r->execute([(int)$_GET['run']]); $r = $r->fetch();
    if (!$r) { http_response_code(404); exit; }
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="import-' . (int)$r['id'] . '-rows-to-check.csv"');
    exit(importReportCsv(json_decode((string)$r['report'], true) ?: ['rows' => []]));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'upload') {
            $kind = (string)($_POST['kind'] ?? '');
            if (!isset(IMPORT_KINDS[$kind])) throw new RuntimeException('Choose what you are importing.');
            $f = $_FILES['file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('Choose the CSV file (up to 50 MB).');
            if ($f['size'] > 50 * 1048576) throw new RuntimeException('The file is larger than 50 MB: split it.');
            $hash = hash_file('sha256', $f['tmp_name']);
            if (!is_dir($dir) && !mkdir($dir, 0750, true)) throw new RuntimeException('Can’t create the import folder.');
            move_uploaded_file($f['tmp_name'], "$dir/$hash.csv");
            [$headers] = importRead("$dir/$hash.csv");
            $_SESSION['import'] = ['kind' => $kind, 'hash' => $hash, 'name' => mb_substr(basename((string)$f['name']), 0, 200), 'map' => importGuessMapping($kind, $headers)];
            header('Location: /pages/import.php?step=map'); exit;
        }
        if ($act === 'cancel') { if ($up) @unlink("$dir/{$up['hash']}.csv"); unset($_SESSION['import']); header('Location: /pages/import.php'); exit; }
        if (in_array($act, ['dry_run', 'commit'], true)) {
            if (!$up || !is_file("$dir/{$up['hash']}.csv")) throw new RuntimeException('Upload the file again.');
            if ($act === 'dry_run') {
                $map = [];
                foreach ((array)($_POST['map'] ?? []) as $field => $col) if ($col !== '' && isset(IMPORT_KINDS[$up['kind']]['fields'][$field])) $map[$field] = (int)$col;
                $_SESSION['import']['map'] = $map;
            } else {
                $map = $up['map'];
                $chk = $db->prepare("SELECT COUNT(*) FROM import_runs WHERE file_hash = ? AND kind = ? AND status = 'dry_run' AND mapping = ?");
                $chk->execute([$up['hash'], $up['kind'], json_encode($map)]);
                if (!$chk->fetchColumn()) throw new RuntimeException('Run the check first.');
            }
            [$headers, $rows] = importRead("$dir/{$up['hash']}.csv");
            $db->prepare("INSERT INTO import_runs (kind, file_name, file_hash, mapping, status, rows_total, created_by) VALUES (?,?,?,?,?,?,?)")
               ->execute([$up['kind'], $up['name'], $up['hash'], json_encode($map), $act === 'commit' ? 'committed' : 'dry_run', count($rows), currentStaffId()]);
            $runId = (int)$db->lastInsertId();
            try { $rep = importRun($db, $up['kind'], $headers, $rows, $map, $act === 'commit', $runId); }
            catch (Throwable $e) { $db->prepare("UPDATE import_runs SET status = 'failed', report = ? WHERE id = ?")->execute([json_encode(['error' => $e->getMessage(), 'rows' => []]), $runId]); throw $e; }
            $db->prepare("UPDATE import_runs SET rows_ok = ?, rows_skipped = ?, rows_error = ?, report = ?, committed_at = IF(status = 'committed', NOW(), NULL) WHERE id = ?")
               ->execute([$rep['ok'], $rep['skipped'], $rep['error'], json_encode($rep, JSON_UNESCAPED_UNICODE), $runId]);
            auditLog($db, 'import', $runId, $act === 'commit' ? 'imported' : 'checked', ['kind' => $up['kind'], 'file' => $up['name'], 'ok' => $rep['ok'], 'skipped' => $rep['skipped'], 'errors' => $rep['error']]);
            if ($act === 'commit') { @unlink("$dir/{$up['hash']}.csv"); unset($_SESSION['import']); }   // the data is in now; don't keep a copy of the file
            header("Location: /pages/import.php?run=$runId"); exit;
        }
        throw new RuntimeException('Unknown action.');
    } catch (RuntimeException $e) { $err = $e->getMessage(); }
}

$pageTitle = 'Import from ClinicSoft';
$pageSubtitle = t('Bring patients and their history across, so ClinicSoft can be switched off');
require_once __DIR__ . '/../includes/header.php';
if ($err) echo '<div class="alert alert-danger" role="alert">' . sanitize($err) . '</div>';

if (isset($_GET['run'])):
    $r = $db->prepare("SELECT r.*, s.name AS by_name FROM import_runs r LEFT JOIN staff s ON s.id = r.created_by WHERE r.id = ?"); $r->execute([(int)$_GET['run']]); $r = $r->fetch();
    if (!$r) { echo '<p>' . t('Not found.') . '</p>'; require_once __DIR__ . '/../includes/footer.php'; exit; }
    $rep = json_decode((string)$r['report'], true) ?: ['rows' => []];
    $dry = $r['status'] === 'dry_run';
    $labels = ['added' => t('Added'), 'linked' => t('Linked'), 'skipped' => t('Skipped'), 'error' => t('Needs fixing')];
?>
<div class="alert <?= $r['status'] === 'failed' ? 'alert-danger' : ($dry ? 'alert-info' : 'alert-success') ?>" role="status">
  <strong><?= sanitize(t(IMPORT_KINDS[$r['kind']]['label'])) ?> · <?= sanitize($r['file_name']) ?></strong> —
  <?= $r['status'] === 'failed' ? sanitize($rep['error'] ?? t('Failed')) : ($dry ? t('Check only: nothing was saved.') : t('Imported.')) ?>
  <?= number_format((int)$r['rows_total']) ?> <?= t('rows') ?>: <?= number_format((int)$r['rows_ok']) ?> <?= $dry ? t('can be imported') : t('imported') ?>, <?= number_format((int)$r['rows_skipped']) ?> <?= t('skipped') ?>, <?= number_format((int)$r['rows_error']) ?> <?= t('need fixing') ?>.
</div>
<div class="d-flex gap-2 mb-3 flex-wrap">
  <?php if ($dry && $up && $up['hash'] === $r['file_hash'] && (int)$r['rows_ok'] > 0): ?>
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="commit"><button class="btn btn-gold" onclick="return confirm(<?= sanitize(json_encode(t('Import the rows that are fine now?'))) ?>)"><i class="bi bi-cloud-upload"></i><?= t('Import the {n} rows that are fine', ['n' => number_format((int)$r['rows_ok'])]) ?></button></form>
  <a class="btn btn-ghost" href="/pages/import.php?step=map"><?= t('Change the column matching') ?></a>
  <?php endif; ?>
  <?php if ($rep['rows']): ?><a class="btn btn-ghost" href="?run=<?= (int)$r['id'] ?>&amp;csv=1"><i class="bi bi-download"></i><?= t('Rows to check (CSV)') ?></a><?php endif; ?>
  <a class="btn btn-ghost" href="/pages/import.php"><?= t('Back to imports') ?></a>
</div>
<?php if ($rep['rows']): ?><div class="card-crm"><div class="table-responsive"><table class="table mb-0 fs-13"><thead><tr><th><?= t('Line') ?></th><th><?= t('Result') ?></th><th><?= t('Details') ?></th></tr></thead><tbody>
  <?php foreach (array_slice($rep['rows'], 0, 500) as [$line, $res, $msg]): ?><tr class="<?= $res === 'error' ? 'table-danger' : '' ?>"><td class="tabular"><?= (int)$line ?></td><td><?= sanitize($labels[$res] ?? $res) ?></td><td><?= sanitize($msg) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php if (count($rep['rows']) > 500): ?><div class="card-body-crm fs-13 text-tx-3"><?= t('The first 500 are shown; the CSV has them all.') ?></div><?php endif; ?></div><?php endif; ?>

<?php elseif (($_GET['step'] ?? '') === 'map' && $up && is_file("$dir/{$up['hash']}.csv")):
    [$headers, $rows] = importRead("$dir/{$up['hash']}.csv");
    $fields = IMPORT_KINDS[$up['kind']]['fields'];
?>
<form method="POST" class="card-crm mb-3"><?= csrfField() ?><input type="hidden" name="action" value="dry_run">
  <div class="card-header-crm"><span><?= sanitize(t(IMPORT_KINDS[$up['kind']]['label'])) ?> · <?= sanitize($up['name']) ?> · <?= number_format(count($rows)) ?> <?= t('rows') ?></span></div>
  <div class="card-body-crm"><p class="fs-13 text-tx-3"><?= t('Match each field to a column of the file. Recognised columns are filled in already; fields marked * are needed.') ?></p>
    <div class="row g-2"><?php foreach ($fields as $k => [$label, $req]): ?>
      <div class="col-md-4"><label class="form-label" for="m_<?= $k ?>"><?= sanitize(t($label)) ?><?= $req ? ' *' : '' ?></label>
        <select class="form-select" id="m_<?= $k ?>" name="map[<?= $k ?>]"><option value=""><?= t('— not in the file —') ?></option>
          <?php foreach ($headers as $i => $h): ?><option value="<?= $i ?>" <?= isset($up['map'][$k]) && $up['map'][$k] === $i ? 'selected' : '' ?>><?= sanitize($h) ?></option><?php endforeach; ?></select></div>
    <?php endforeach; ?></div></div>
  <div class="form-actions"><button type="submit" form="cancelForm" class="btn btn-ghost"><?= t('Cancel') ?></button><button class="btn btn-gold ms-auto"><i class="bi bi-search"></i><?= t('Check the file (saves nothing)') ?></button></div>
</form>
<form method="POST" id="cancelForm"><?= csrfField() ?><input type="hidden" name="action" value="cancel"></form>
<div class="card-crm"><div class="card-header-crm"><span><?= t('First rows of the file') ?></span></div><div class="table-responsive"><table class="table mb-0 fs-12"><thead><tr><?php foreach ($headers as $h): ?><th><?= sanitize($h) ?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach (array_slice($rows, 0, 5) as $row): ?><tr><?php foreach ($headers as $i => $h): ?><td><?= sanitize((string)($row[$i] ?? '')) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div></div>

<?php else:
    $runs = $db->query("SELECT r.*, s.name AS by_name FROM import_runs r LEFT JOIN staff s ON s.id = r.created_by ORDER BY r.id DESC LIMIT 50")->fetchAll();
    $done = $db->query("SELECT kind, SUM(rows_ok) FROM import_runs WHERE status = 'committed' GROUP BY kind")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<div class="alert alert-info fs-13"><?= t('Export each list from ClinicSoft as CSV (or Excel saved as CSV). Import patients first; everything else finds patients by their ClinicSoft file number. Each import starts with a check that saves nothing.') ?></div>
<div class="row g-3 mb-3"><?php $n = 0; foreach (IMPORT_KINDS as $k => $kd): $n++; ?>
  <div class="col-md-6 col-xl-3"><form method="POST" enctype="multipart/form-data" class="card-crm h-100"><?= csrfField() ?><input type="hidden" name="action" value="upload"><input type="hidden" name="kind" value="<?= $k ?>">
    <div class="card-body-crm"><div class="fw-600"><?= $n ?>. <?= sanitize(t($kd['label'])) ?><?= !empty($done[$k]) ? ' <span class="chip tone-green">' . number_format((int)$done[$k]) . ' ' . t('in') . '</span>' : '' ?></div>
      <p class="fs-13 text-tx-3 mb-2"><?= sanitize(t($kd['help'])) ?></p>
      <label class="visually-hidden" for="f_<?= $k ?>"><?= t('CSV file') ?></label><input class="form-control form-control-sm mb-2" type="file" id="f_<?= $k ?>" name="file" accept=".csv,text/csv" required>
      <div class="d-flex gap-2"><button class="btn btn-gold btn-sm"><?= t('Upload') ?></button><a class="btn btn-ghost btn-sm" href="?template=<?= $k ?>"><?= t('Columns we read') ?></a></div></div></form></div>
<?php endforeach; ?></div>
<p class="fs-13 text-tx-3"><?= t('Scanned documents (ID copies, reports) come from a folder of files named by file number, with the command tools/import_documents.php; ask your administrator.') ?></p>
<div class="card-crm"><div class="card-header-crm"><span><?= t('Imports so far') ?></span></div><div class="table-responsive"><table class="table mb-0 fs-13">
  <thead><tr><th><?= t('When') ?></th><th><?= t('What') ?></th><th><?= t('File') ?></th><th><?= t('Result') ?></th><th class="text-end"><?= t('Rows') ?></th><th class="text-end"><?= t('OK') ?></th><th class="text-end"><?= t('Skipped') ?></th><th class="text-end"><?= t('To fix') ?></th><th><?= t('By') ?></th></tr></thead><tbody>
  <?php foreach ($runs as $r): ?><tr><td><a href="?run=<?= (int)$r['id'] ?>"><?= sanitize(substr($r['created_at'], 0, 16)) ?></a></td><td><?= sanitize(t(IMPORT_KINDS[$r['kind']]['label'] ?? $r['kind'])) ?></td><td><?= sanitize($r['file_name']) ?></td>
    <td><span class="chip <?= ['dry_run' => 'tone-blue', 'committed' => 'tone-green', 'failed' => 'tone-red'][$r['status']] ?>"><?= sanitize(['dry_run' => t('Check'), 'committed' => t('Imported'), 'failed' => t('Failed')][$r['status']]) ?></span></td>
    <td class="text-end tabular"><?= number_format((int)$r['rows_total']) ?></td><td class="text-end tabular"><?= number_format((int)$r['rows_ok']) ?></td><td class="text-end tabular"><?= number_format((int)$r['rows_skipped']) ?></td><td class="text-end tabular"><?= number_format((int)$r['rows_error']) ?></td><td><?= sanitize((string)$r['by_name']) ?></td></tr><?php endforeach; ?>
  <?php if (!$runs): ?><tr><td colspan="9" class="text-tx-3"><?= t('Nothing imported yet.') ?></td></tr><?php endif; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
