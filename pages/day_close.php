<?php
// pages/day_close.php — Billing → Day close: the cashier counts their drawer. Expected per method = their receipts at
// this branch for the day, less refunds they paid out; a difference needs a note. Closing locks those receipts
// (afterwards only an admin can void them). Below: recent closes.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/billing.php';
requirePermission('billing.day_close');

$db = getDB();
$branches = branchList($db);
$home = $db->prepare("SELECT branch_id FROM staff WHERE id = ?"); $home->execute([currentStaffId()]);
$branch = (int)($_REQUEST['branch'] ?? 0) ?: ((int)$home->fetchColumn() ?: (int)array_key_first($branches));
if (!isset($branches[$branch])) $branch = (int)array_key_first($branches);
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_REQUEST['date'] ?? '') && $_REQUEST['date'] <= date('Y-m-d') ? $_REQUEST['date'] : date('Y-m-d');
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        closeDay($db, $branch, $date, (array)($_POST['counted'] ?? []), (string)($_POST['note'] ?? ''));
        $_SESSION['bill_flash'] = ['success', 'Day closed.'];
        header("Location: /pages/day_close.php?branch=$branch&date=$date"); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$flash = $_SESSION['bill_flash'] ?? null;
unset($_SESSION['bill_flash']);
$e = dayCloseExpected($db, $branch, currentStaffId(), $date);
$mine = can('billing.void') ? '' : ' WHERE d.staff_id = ' . currentStaffId();
$history = $db->query("SELECT d.*, s.name AS staff_name, b.name AS branch_name FROM day_closes d JOIN staff s ON s.id = d.staff_id JOIN branches b ON b.id = d.branch_id$mine ORDER BY d.closed_at DESC LIMIT 30")->fetchAll();

$pageTitle = 'Day close';
$pageSubtitle = sanitize(staffName()) . ' · ' . date('D d M Y', strtotime($date));
$pageBack = ['href' => '/pages/receipts.php', 'label' => 'Receipts'];
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?> alert-auto"><i class="bi bi-check-circle"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i><div><strong><?= sanitize($error) ?></strong></div></div><?php endif; ?>
<form method="GET" class="card-crm page-section mb-3"><div class="card-body-crm d-flex flex-wrap gap-2 align-items-end">
  <div><label class="form-label" for="dBr"><?= t('Branch') ?></label><select class="form-select" id="dBr" name="branch"><?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $branch === (int)$b['id'] ? 'selected' : '' ?>><?= sanitize($b['name']) ?></option><?php endforeach; ?></select></div>
  <div><label class="form-label" for="dDate"><?= t('Day') ?></label><input class="form-control" type="date" id="dDate" name="date" value="<?= $date ?>" max="<?= date('Y-m-d') ?>"></div>
  <button class="btn btn-ghost"><?= t('Show') ?></button></div></form>

<form method="POST" class="card-crm mb-3" id="closeForm"><?= csrfField() ?><input type="hidden" name="branch" value="<?= $branch ?>"><input type="hidden" name="date" value="<?= $date ?>">
  <div class="card-header-crm"><span><i class="bi bi-safe"></i> <?= t('Your drawer') ?></span><span class="fs-12 text-tx-3 ms-auto"><?= $e['receipts'] ?> receipt<?= $e['receipts'] == 1 ? '' : 's' ?> not yet closed</span></div>
  <div class="table-responsive"><table class="table mb-0 align-middle">
    <thead><tr><th><?= t('Method') ?></th><th class="text-end"><?= t('Expected') ?></th><th class="text-end" style="width:170px"><?= t('Counted') ?></th><th class="text-end"><?= t('Difference') ?></th></tr></thead>
    <tbody><?php foreach (DRAWER_METHODS as $m): $exp = $e['methods'][$m]; ?>
      <tr><td><label for="c_<?= $m ?>"><?= PAY_METHODS[$m] ?></label></td><td class="text-end tabular" data-exp="<?= aed($exp) ?>"><?= aed($exp) ?></td>
        <td><input class="form-control text-end cnt" type="number" step="0.01" id="c_<?= $m ?>" name="counted[<?= $m ?>]" value="<?= sanitize((string)($_POST['counted'][$m] ?? ($exp && $m !== 'cash' ? aed($exp) : ''))) ?>" placeholder="0.00"></td>
        <td class="text-end tabular diff">–</td></tr>
    <?php endforeach; ?></tbody>
    <tfoot><tr class="fw-700"><td><?= t('Total') ?></td><td class="text-end tabular"><?= aed(array_sum($e['methods'])) ?></td><td class="text-end tabular" id="cntTotal"></td><td class="text-end tabular" id="diffTotal"></td></tr></tfoot>
  </table></div>
  <div class="card-body-crm"><label class="form-label" for="cNote"><?= t('Note') ?> <span class="fw-normal text-tx-3"><?= t('(required if the count is over or short)') ?></span></label>
    <input class="form-control" id="cNote" name="note" maxlength="500" value="<?= sanitize((string)($_POST['note'] ?? '')) ?>"></div>
  <div class="form-actions"><span class="fs-12 text-tx-3">Card, Tabby and other non-cash amounts are filled in from the receipts; check them against the terminal totals.</span>
    <button class="btn btn-gold ms-auto" onclick="return confirm('Close the day? These receipts will be locked.')"><i class="bi bi-lock"></i><?= t('Close the day') ?></button></div>
</form>
<script>
(function () { var f = document.getElementById('closeForm');
  function upd() { var t = 0, d = 0; f.querySelectorAll('tbody tr').forEach(function (r) { var e = parseFloat(r.querySelector('[data-exp]').dataset.exp) || 0, c = parseFloat(r.querySelector('.cnt').value) || 0;
      var x = c - e; t += c; d += x; var cell = r.querySelector('.diff'); cell.textContent = x.toFixed(2); cell.classList.toggle('text-danger', Math.abs(x) > 0.004); });
    document.getElementById('cntTotal').textContent = t.toFixed(2); var dt = document.getElementById('diffTotal'); dt.textContent = d.toFixed(2); dt.classList.toggle('text-danger', Math.abs(d) > 0.004); }
  f.addEventListener('input', upd); upd(); })();
</script>

<div class="card-crm"><div class="card-header-crm"><span><i class="bi bi-clock-history"></i> <?= t('Recent closes') ?></span></div>
  <div class="table-responsive"><table class="table mb-0"><thead><tr><th><?= t('Day') ?></th><th><?= t('Cashier') ?></th><th><?= t('Branch') ?></th><th class="text-end"><?= t('Expected') ?></th><th class="text-end"><?= t('Counted') ?></th><th class="text-end"><?= t('Difference') ?></th><th><?= t('Note') ?></th></tr></thead>
  <tbody><?php foreach ($history as $h): $ex = array_sum(array_map('floatval', json_decode($h['expected'], true) ?: [])); $co = array_sum(array_map('floatval', json_decode($h['counted'], true) ?: [])); ?>
    <tr><td><?= date('d M Y', strtotime($h['business_date'])) ?><span class="cell-sub"><?= date('H:i', strtotime($h['closed_at'])) ?></span></td><td><?= sanitize($h['staff_name']) ?></td><td><?= sanitize($h['branch_name']) ?></td>
      <td class="text-end tabular"><?= number_format($ex, 2) ?></td><td class="text-end tabular"><?= number_format($co, 2) ?></td>
      <td class="text-end tabular <?= (float)$h['difference'] ? 'text-danger fw-600' : '' ?>"><?= number_format($h['difference'], 2) ?></td><td class="fs-13"><?= sanitize((string)$h['note']) ?></td></tr>
  <?php endforeach; ?><?php if (!$history): ?><tr><td colspan="7" class="text-tx-3"><?= t('No closes yet.') ?></td></tr><?php endif; ?></tbody></table></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
