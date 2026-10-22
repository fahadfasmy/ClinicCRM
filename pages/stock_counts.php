<?php
// pages/stock_counts.php — shelf counts. Counting is blind (system quantities stay hidden until review);
// differences need a reason; large or prescription-only ones need an admin who didn't submit the count.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';
requirePermission('inventory.view');

$db = getDB();
ensureInventorySchema($db);
$error = '';
$id = (int)($_GET['id'] ?? 0);
$count = $id ? getStockCount($db, $id) : null;
if ($id && !$count) { http_response_code(404); die('Count not found.'); }

const COUNT_STATUS = [
    'counting'          => ['Counting', 'aging-due'],
    'awaiting_approval' => ['Waiting for approval', 'aging-overdue'],
    'closed'            => ['Closed', 'aging-fresh'],
    'abandoned'         => ['Abandoned', ''],
];
const LINE_STATUS = [
    'posted'   => ['Applied', 'aging-fresh'],
    'approved' => ['Approved', 'aging-fresh'],
    'pending'  => ['Needs approval', 'aging-overdue'],
    'rejected' => ['Rejected', 'aging-critical'],
];

/** Counted quantity from the "sealed + loose" inputs of one line ('' when both are empty). */
function countedFromPost(array $ln): string {
    $sealed = trim((string)($_POST['sealed'][$ln['id']] ?? ''));
    $loose  = trim((string)($_POST['loose'][$ln['id']] ?? ''));
    if ($sealed === '' && $loose === '') return '';
    if (($sealed !== '' && !is_numeric($sealed)) || ($loose !== '' && !is_numeric($loose))) return 'x';
    return (string)round((float)$sealed * (float)$ln['units_per_stock'] + (float)$loose, 3);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'start') {
            requirePermission('inventory.count');
            $newId = startStockCount($db, trim($_POST['category'] ?? '') ?: null, $_POST['note'] ?? '');
            header('Location: /pages/stock_counts.php?id=' . $newId); exit;
        }
        if ($count && in_array($action, ['save', 'review', 'submit', 'reasons'], true)) {
            requirePermission('inventory.count');
            $entries = [];
            foreach (stockCountLines($db, $id) as $ln) {
                if ($action === 'save' || $action === 'review') $entries[$ln['id']] = ['counted' => countedFromPost($ln)];
                if (($action === 'submit' || $action === 'reasons') && isset($_POST['reason'][$ln['id']])) {
                    $entries[$ln['id']] = ['reason' => $_POST['reason'][$ln['id']], 'note' => $_POST['note'][$ln['id']] ?? ''];
                }
            }
            saveCountEntries($db, $id, $entries);
            if ($action === 'submit') {
                $r = submitStockCount($db, $id);
                $_SESSION['count_flash'] = $r;
                header('Location: /pages/stock_counts.php?id=' . $id); exit;
            }
            header('Location: /pages/stock_counts.php?id=' . $id . ($action === 'review' || $action === 'reasons' ? '&step=review' : '&saved=1')); exit;
        }
        if ($count && $action === 'abandon') {
            requirePermission('inventory.count');
            abandonStockCount($db, $id);
            header('Location: /pages/stock_counts.php'); exit;
        }
        if ($count && ($action === 'approve' || $action === 'reject')) {
            requirePermission('inventory.approve');
            decideCountLine($db, (int)($_POST['line_id'] ?? 0), $action === 'approve', $_POST['decision_note'] ?? '');
            header('Location: /pages/stock_counts.php?id=' . $id . '&decided=1'); exit;
        }
    } catch (StockException $e) {
        $error = $e->getMessage();
        if ($count && in_array($action, ['submit', 'reasons'], true)) $_GET['step'] = 'review';
    }
}

// ─────────── LIST ───────────
if (!$count) {
    $counts = $db->query("
        SELECT c.*, a.name AS created_by_name, b.name AS submitted_by_name,
               COUNT(cl.id) AS line_total,
               SUM(cl.counted_qty IS NOT NULL) AS counted,
               SUM(cl.status IN ('posted','approved','pending','rejected')) AS differences,
               SUM(cl.status = 'pending') AS pending,
               SUM(CASE WHEN cl.status IN ('posted','approved') THEN cl.diff_qty * COALESCE(l.unit_cost,0) / NULLIF(p.units_per_stock,0) END) AS net_value
        FROM stock_counts c
        LEFT JOIN stock_count_lines cl ON cl.count_id = c.id
        LEFT JOIN stock_lots l ON l.id = cl.lot_id
        LEFT JOIN products p ON p.id = cl.product_id
        LEFT JOIN staff a ON a.id = c.created_by
        LEFT JOIN staff b ON b.id = c.submitted_by
        GROUP BY c.id ORDER BY c.id DESC LIMIT 100")->fetchAll();
    $inProgress = array_values(array_filter($counts, fn($c) => $c['status'] === 'counting'));
    $cats = $db->query("SELECT DISTINCT p.category FROM products p JOIN stock_lots l ON l.product_id = p.id WHERE l.qty_on_hand > 0 AND p.category IS NOT NULL AND p.is_active = 1 ORDER BY p.category")->fetchAll(PDO::FETCH_COLUMN);

    $pageTitle    = 'Stock counts';
    $pageSubtitle = 'Count the shelf, compare with the records, explain every difference';
    require_once __DIR__ . '/../includes/header.php';
    echo inventoryTabs('counts');
    ?>
    <?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><?= sanitize($error) ?></div><?php endif; ?>
    <div class="row g-3 page-section">
      <?php if (can('inventory.count')): ?>
      <div class="col-lg-5">
        <?php if ($inProgress): ?>
        <div class="card-crm h-100"><div class="card-body-crm">
          <h3 class="form-section-title">Count in progress</h3>
          <p class="text-tx-2 fs-13">Started <?= date('d M Y, H:i', strtotime($inProgress[0]['created_at'])) ?> by <?= sanitize($inProgress[0]['created_by_name'] ?? '–') ?>
            · <?= (int)$inProgress[0]['counted'] ?> of <?= (int)$inProgress[0]['line_total'] ?> batches counted.</p>
          <a class="btn btn-gold" href="?id=<?= $inProgress[0]['id'] ?>"><i class="bi bi-arrow-right"></i>Continue counting</a>
        </div></div>
        <?php else: ?>
        <form method="POST" class="card-crm h-100">
          <?= csrfField() ?><input type="hidden" name="action" value="start">
          <div class="card-body-crm">
            <h3 class="form-section-title">Start a count</h3>
            <p class="form-section-sub">Lists every batch with stock on record. You enter what is physically on the shelf; the system quantities stay hidden until you review.</p>
            <div class="mb-3"><label class="form-label" for="cCat">What to count</label>
              <select class="form-select" id="cCat" name="category"><option value="">Everything</option>
                <?php foreach ($cats as $c): ?><option value="<?= sanitize($c) ?>"><?= sanitize($c) ?> only</option><?php endforeach; ?></select></div>
            <div><label class="form-label" for="cNote">Note</label><input class="form-control" id="cNote" name="note" maxlength="255" placeholder="e.g. Month-end count, September"></div>
          </div>
          <div class="form-actions"><button class="btn btn-gold" type="submit"><i class="bi bi-clipboard-check"></i>Start count</button></div>
        </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="<?= can('inventory.count') ? 'col-lg-7' : 'col-12' ?>">
        <div class="card-crm h-100"><div class="card-body-crm">
          <h3 class="form-section-title">How approval works</h3>
          <ul class="text-tx-2 fs-13 mb-0 ps-3 d-flex flex-column gap-1">
            <li>Every difference between the shelf and the records needs a reason.</li>
            <li>Small differences are applied as soon as the count is submitted.</li>
            <li>A difference worth more than <strong>AED <?= number_format(COUNT_APPROVAL_AED) ?></strong> at batch cost, or on any <strong>prescription-only</strong> item, waits for an admin.</li>
            <li>The person who submits a count can’t approve it. The inventory team counts; an admin approves.</li>
          </ul>
        </div></div>
      </div>
    </div>

    <div class="card-crm">
      <div class="table-responsive">
      <table class="table table-hover">
        <thead><tr><th>Count</th><th>Started</th><th>Submitted</th><th class="text-end">Batches</th><th class="text-end">Differences</th><th class="text-end">Net change</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($counts as $c): [$sl, $sc] = COUNT_STATUS[$c['status']]; ?>
          <tr class="row-link" data-href="?id=<?= $c['id'] ?>">
            <td><a class="cell-title" href="?id=<?= $c['id'] ?>">#<?= $c['id'] ?> <?= sanitize($c['category'] ?: 'Everything') ?></a><?php if ($c['note']): ?><span class="cell-sub"><?= sanitize($c['note']) ?></span><?php endif; ?></td>
            <td class="text-nowrap"><?= date('d M Y', strtotime($c['created_at'])) ?><span class="cell-sub"><?= sanitize($c['created_by_name'] ?? '–') ?></span></td>
            <td class="text-nowrap"><?= $c['submitted_at'] ? date('d M Y', strtotime($c['submitted_at'])) . '<span class="cell-sub">' . sanitize($c['submitted_by_name'] ?? '–') . '</span>' : '<span class="text-tx-3">–</span>' ?></td>
            <td class="text-end tabular"><?= $c['status'] === 'counting' ? (int)$c['counted'] . ' / ' : '' ?><?= (int)$c['line_total'] ?></td>
            <td class="text-end tabular"><?= $c['submitted_at'] ? (int)$c['differences'] : '–' ?></td>
            <td class="text-end tabular text-nowrap <?= (float)$c['net_value'] < 0 ? 'text-danger' : '' ?>"><?= $c['submitted_at'] && (float)$c['net_value'] ? 'AED ' . number_format((float)$c['net_value'], 0) : '–' ?></td>
            <td><span class="chip <?= $sc ?>"><?= $sl ?></span><?php if ((int)$c['pending']): ?> <span class="chip aging-overdue"><?= (int)$c['pending'] ?> to approve</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$counts): ?><tr><td colspan="7" class="table-empty">No counts yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// ─────────── ONE COUNT ───────────
$lines = stockCountLines($db, $id);
$isCounting = $count['status'] === 'counting';
$canCount = can('inventory.count');
$step = $isCounting && $canCount && ($_GET['step'] ?? '') === 'review' ? 'review' : ($isCounting ? 'enter' : 'result');
$flash = $_SESSION['count_flash'] ?? null;
unset($_SESSION['count_flash']);
[$sl, $sc] = COUNT_STATUS[$count['status']];
$countedN = count(array_filter($lines, fn($l) => $l['counted_qty'] !== null));

$pageTitle    = 'Count #' . $id . ' · ' . ($count['category'] ?: 'Everything');
$pageBack     = ['href' => '/pages/stock_counts.php', 'label' => 'Stock counts'];
$pageSubtitle = '<span class="chip ' . $sc . '">' . $sl . '</span> &nbsp;Started ' . date('d M Y, H:i', strtotime($count['created_at'])) . ' by ' . sanitize($count['created_by_name'] ?? '–')
              . ($count['submitted_at'] ? ' · submitted ' . date('d M Y, H:i', strtotime($count['submitted_at'])) . ' by ' . sanitize($count['submitted_by_name'] ?? '–') : '');
require_once __DIR__ . '/../includes/header.php';
$unitsHint = fn($ln) => (float)$ln['units_per_stock'] > 1 ? '1 ' . sanitize($ln['stock_unit']) . ' = ' . qtyUnit($ln['units_per_stock'], $ln['usage_unit']) : '';
?>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i> Progress saved.</div><?php endif; ?>
<?php if (isset($_GET['decided'])): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i> Decision saved.</div><?php endif; ?>
<?php if ($flash): ?>
  <div class="alert alert-success"><i class="bi bi-check-circle"></i><div><strong>Count submitted.</strong>
    <?= $flash['same'] ?> batch<?= $flash['same'] == 1 ? '' : 'es' ?> matched the records; <?= $flash['posted'] ?> difference<?= $flash['posted'] == 1 ? ' was' : 's were' ?> applied<?= $flash['pending'] ? '; ' . $flash['pending'] . ' wait' . ($flash['pending'] == 1 ? 's' : '') . ' for an admin' : '' ?>.</div></div>
<?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><?= sanitize($error) ?></div><?php endif; ?>
<?php if ($count['note']): ?><p class="text-tx-2"><?= sanitize($count['note']) ?></p><?php endif; ?>

<?php if ($step === 'enter'): ?>
  <?php if (!$canCount): ?>
    <div class="card-crm"><div class="empty-state"><i class="bi bi-clipboard-check"></i>The inventory team is counting: <?= $countedN ?> of <?= count($lines) ?> batches done.</div></div>
  <?php else: ?>
  <form method="POST" class="card-crm" id="countForm">
    <?= csrfField() ?>
    <div class="card-header-crm"><span><i class="bi bi-clipboard-check"></i> What is on the shelf?</span><span class="card-header-sub"><span id="cProgress"><?= $countedN ?></span> of <?= count($lines) ?> batches counted</span></div>
    <p class="fs-12 text-tx-3 px-3 pt-2 mb-0">Count each batch and enter what you see, including opened vials. Enter 0 if the batch isn’t there. The records are shown only after you review.</p>
    <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>Product</th><th>Batch</th><th>Expiry</th><th style="min-width:260px">Counted</th></tr></thead>
      <tbody>
      <?php $prev = null; foreach ($lines as $ln):
        $per = (float)$ln['units_per_stock'];
        $cq = $ln['counted_qty'] !== null ? (float)$ln['counted_qty'] : null;
        $sealedV = $cq === null ? '' : ($per > 1 ? floor(round($cq / $per, 6)) : $cq);
        $looseV = $cq === null || $per <= 1 ? '' : round($cq - $sealedV * $per, 3);
        if ($ln['category'] !== $prev): $prev = $ln['category']; ?>
        <tr class="table-group"><td colspan="4" class="fw-600 text-tx-2 fs-12 text-uppercase"><?= sanitize($ln['category'] ?: 'Uncategorised') ?></td></tr>
      <?php endif; ?>
        <tr>
          <td><span class="fw-600"><?= sanitize($ln['product_name']) ?></span><?= $ln['prescription_only'] ? ' <span class="chip aging-due">Rx</span>' : '' ?><span class="cell-sub"><?= $unitsHint($ln) ?></span></td>
          <td class="text-nowrap"><?= sanitize($ln['lot_number'] ?: 'No batch number') ?><?= $ln['lot_status'] !== 'available' ? '<span class="cell-sub">' . ($ln['lot_status'] === 'quarantined' ? 'On hold' : 'Recalled') . '</span>' : '' ?></td>
          <td class="text-nowrap"><?= $ln['expiry_date'] ? date('M Y', strtotime($ln['expiry_date'])) : '–' ?></td>
          <td>
            <div class="d-flex gap-2 count-inputs">
              <div class="input-group input-group-sm"><input type="number" class="form-control count-in" name="sealed[<?= $ln['id'] ?>]" min="0" step="<?= $per > 1 ? '1' : 'any' ?>" value="<?= $sealedV === '' ? '' : fmtQty($sealedV) ?>" aria-label="<?= sanitize($ln['product_name']) ?> <?= $per > 1 ? 'sealed ' . sanitize(unitWord($ln['stock_unit'], 2)) : '' ?>">
                <span class="input-group-text"><?= sanitize($per > 1 ? unitWord($ln['stock_unit'], 2) : $ln['usage_unit']) ?></span></div>
              <?php if ($per > 1): ?>
              <div class="input-group input-group-sm"><span class="input-group-text">+</span><input type="number" class="form-control count-in" name="loose[<?= $ln['id'] ?>]" min="0" step="any" value="<?= $looseV === '' ? '' : fmtQty($looseV) ?>" aria-label="<?= sanitize($ln['product_name']) ?> opened or loose <?= sanitize($ln['usage_unit']) ?>">
                <span class="input-group-text"><?= sanitize(unitWord($ln['usage_unit'], 2)) ?></span></div>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="form-actions">
      <button class="btn btn-ghost me-auto" type="submit" name="action" value="abandon" formnovalidate onclick="return confirm('Abandon this count? Nothing is changed in stock.')">Abandon count</button>
      <button class="btn btn-ghost" type="submit" name="action" value="save"><i class="bi bi-floppy"></i>Save progress</button>
      <button class="btn btn-gold" type="submit" name="action" value="review"><i class="bi bi-arrow-right"></i>Save and review differences</button>
    </div>
  </form>
  <?php endif; ?>

<?php elseif ($step === 'review'):
  $missing = array_filter($lines, fn($l) => $l['counted_qty'] === null);
  $diffs = array_filter($lines, fn($l) => $l['counted_qty'] !== null && abs((float)$l['counted_qty'] - (float)$l['system_qty']) >= 0.0005);
?>
  <?php if ($missing): ?>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i><div><strong><?= count($missing) ?> batch<?= count($missing) == 1 ? ' is' : 'es are' ?> not counted yet.</strong> Every batch needs a count (0 if it isn’t there) before you can submit.
    <a href="?id=<?= $id ?>">Back to counting</a></div></div>
  <?php endif; ?>
  <form method="POST" class="card-crm">
    <?= csrfField() ?>
    <div class="card-header-crm"><span><i class="bi bi-arrow-left-right"></i> Differences from the records</span><span class="card-header-sub"><?= count($diffs) ?> of <?= count($lines) ?> batches differ</span></div>
    <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>Product and batch</th><th class="text-end">Records</th><th class="text-end">Counted</th><th class="text-end">Difference</th><th style="min-width:230px">Reason</th></tr></thead>
      <tbody>
      <?php foreach ($diffs as $ln):
        $d = round((float)$ln['counted_qty'] - (float)$ln['system_qty'], 3);
        $val = abs($d) * costPerUsageUnit($ln);
        $needs = countNeedsApproval($ln, $d); ?>
        <tr>
          <td><span class="fw-600"><?= sanitize($ln['product_name']) ?></span><span class="cell-sub">Batch <?= sanitize($ln['lot_number'] ?: '–') ?><?= $ln['expiry_date'] ? ' · exp ' . date('M Y', strtotime($ln['expiry_date'])) : '' ?></span></td>
          <td class="text-end tabular text-nowrap"><?= fmtStock($ln['system_qty'], $ln) ?></td>
          <td class="text-end tabular text-nowrap"><?= fmtStock($ln['counted_qty'], $ln) ?></td>
          <td class="text-end tabular text-nowrap fw-600 <?= $d < 0 ? 'text-danger' : 'text-green' ?>"><?= sprintf('%+g', $d) ?>
            <span class="cell-sub"><?= $val > 0 ? 'AED ' . number_format($val, 0) : 'no cost entered' ?></span>
            <?php if ($needs): ?><span class="chip aging-overdue mt-1">Needs admin approval</span><?php endif; ?></td>
          <td>
            <select class="form-select form-select-sm" name="reason[<?= $ln['id'] ?>]" required aria-label="Reason for <?= sanitize($ln['product_name']) ?>">
              <option value="">Choose a reason…</option>
              <?php foreach (COUNT_REASONS as $k => $l): ?><option value="<?= $k ?>" <?= $ln['reason'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
            </select>
            <input class="form-control form-control-sm mt-1" name="note[<?= $ln['id'] ?>]" maxlength="255" value="<?= sanitize($ln['note'] ?? '') ?>" placeholder="Note (needed for Other)" aria-label="Note">
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$diffs): ?><tr><td colspan="5" class="table-empty"><?= $missing ? 'No differences in the batches counted so far.' : 'Every batch matches the records.' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
    <div class="form-actions">
      <a class="btn btn-ghost me-auto" href="?id=<?= $id ?>"><i class="bi bi-arrow-left"></i>Back to counting</a>
      <?php if ($diffs): ?><button class="btn btn-ghost" type="submit" name="action" value="reasons" formnovalidate>Save reasons</button><?php endif; ?>
      <button class="btn btn-gold" type="submit" name="action" value="submit" <?= $missing ? 'disabled' : '' ?> onclick="return confirm('Submit the count? Differences that don’t need approval are applied to stock now.')"><i class="bi bi-check2-circle"></i>Submit count</button>
    </div>
  </form>

<?php else:
  $diffLines = array_filter($lines, fn($l) => isset(LINE_STATUS[$l['status']]));
  $same = count(array_filter($lines, fn($l) => $l['status'] === 'no_difference'));
  $mine = (int)$count['submitted_by'] === currentStaffId();
?>
  <?php if ($count['status'] === 'awaiting_approval' && can('inventory.approve') && $mine): ?>
  <div class="alert alert-info"><i class="bi bi-info-circle"></i>You submitted this count, so another admin has to approve its differences.</div>
  <?php endif; ?>
  <div class="card-crm">
    <div class="card-header-crm"><span><i class="bi bi-arrow-left-right"></i> Differences</span><span class="card-header-sub"><?= $same ?> of <?= count($lines) ?> batches matched the records</span></div>
    <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>Product and batch</th><th class="text-end">Records</th><th class="text-end">Counted</th><th class="text-end">Difference</th><th>Reason</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($diffLines as $ln): [$ls, $lc] = LINE_STATUS[$ln['status']]; ?>
        <tr>
          <td><span class="fw-600"><?= sanitize($ln['product_name']) ?></span><?= $ln['prescription_only'] ? ' <span class="chip aging-due">Rx</span>' : '' ?><span class="cell-sub">Batch <?= sanitize($ln['lot_number'] ?: '–') ?></span></td>
          <td class="text-end tabular text-nowrap"><?= fmtStock($ln['expected_qty'], $ln) ?></td>
          <td class="text-end tabular text-nowrap"><?= fmtStock($ln['counted_qty'], $ln) ?></td>
          <td class="text-end tabular text-nowrap fw-600 <?= (float)$ln['diff_qty'] < 0 ? 'text-danger' : 'text-green' ?>"><?= sprintf('%+g', (float)$ln['diff_qty']) ?><span class="cell-sub"><?= (float)$ln['diff_value'] ? 'AED ' . number_format((float)$ln['diff_value'], 0) : '–' ?></span></td>
          <td><?= sanitize(reasonLabel($ln['reason'])) ?><?php if ($ln['note']): ?><span class="cell-sub"><?= sanitize($ln['note']) ?></span><?php endif; ?></td>
          <td>
            <span class="chip <?= $lc ?>"><?= $ls ?></span>
            <?php if ($ln['decided_by_name']): ?><span class="cell-sub"><?= sanitize($ln['decided_by_name']) ?>, <?= date('d M', strtotime($ln['decided_at'])) ?><?= $ln['decision_note'] ? ': ' . sanitize($ln['decision_note']) : '' ?></span><?php endif; ?>
            <?php if ($ln['status'] === 'pending' && can('inventory.approve') && !$mine): ?>
            <form method="POST" class="d-flex flex-column gap-1 mt-2" style="min-width:200px"><?= csrfField() ?><input type="hidden" name="line_id" value="<?= $ln['id'] ?>">
              <input class="form-control form-control-sm" name="decision_note" maxlength="255" placeholder="Note (needed to reject)" aria-label="Decision note">
              <div class="d-flex gap-1">
                <button class="btn btn-gold btn-sm flex-fill" type="submit" name="action" value="approve">Approve</button>
                <button class="btn btn-ghost btn-sm flex-fill" type="submit" name="action" value="reject">Reject</button>
              </div>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$diffLines): ?><tr><td colspan="6" class="table-empty"><?= $count['status'] === 'abandoned' ? 'This count was abandoned; nothing was changed.' : 'The shelf matched the records for every batch.' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
    <?php if (array_filter($diffLines, fn($l) => $l['status'] === 'rejected')): ?>
    <div class="card-body-crm border-top fs-12 text-tx-3">Rejected differences were not applied. Count those batches again in a new count.</div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php
$extraJs = "
const cf = document.getElementById('countForm');
if (cf) {
  const rows = [...cf.querySelectorAll('.count-inputs')];
  const upd = () => document.getElementById('cProgress').textContent = rows.filter(r => [...r.querySelectorAll('input')].some(i => i.value !== '')).length;
  cf.addEventListener('input', upd);
  let dirty = false;
  cf.addEventListener('input', () => dirty = true);
  cf.addEventListener('submit', () => dirty = false);
  window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
}";
require_once __DIR__ . '/../includes/footer.php';
