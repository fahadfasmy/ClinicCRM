<?php
// pages/claim.php?id= — one claim: what is claimed per line, what is missing before it can be sent, its history, and
// the follow-up: accepted, rejected (denial code), corrected and ready again, remittance (money from the insurer),
// written off.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/insurance.php';
requirePermission('insurance.view');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        $a = (string)($_POST['action'] ?? '');
        if ($a === 'recheck') { $p = refreshClaimReadiness($db, $id); $_SESSION['ins_flash'] = $p ? ['danger', 'Still missing: ' . implode(' ', $p)] : ['success', 'Ready to send.']; }
        elseif ($a === 'remit') { recordRemittance($db, $id, $_POST['amount'] ?? 0, (string)($_POST['date'] ?? ''), (string)($_POST['ref'] ?? ''), (string)($_POST['denial_code'] ?? '')); $_SESSION['ins_flash'] = ['success', 'Payment recorded.']; }
        elseif (in_array($a, ['accepted', 'rejected', 'ready', 'written_off'], true)) { claimTransition($db, $id, $a, $_POST); $_SESSION['ins_flash'] = ['success', 'Claim updated.']; }
        else throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) { $_SESSION['ins_flash'] = ['danger', $e->getMessage()]; }
    header("Location: /pages/claim.php?id=$id"); exit;
}
$flash = $_SESSION['ins_flash'] ?? null;
unset($_SESSION['ins_flash']);
$cl = findClaim($db, $id);
if (!$cl) { http_response_code(404); exit('Claim not found'); }
$p = findPatient($db, (int)$cl['patient_id']);
logPatientAccess($db, (int)$p['id'], 'claim:' . $id);
$acts = claimActivities($db, $cl);
$dx = claimDiagnoses($db, $cl);
$problems = in_array($cl['status'], ['draft', 'ready', 'rejected'], true) ? claimProblems($db, $cl) : [];
$ev = $db->prepare("SELECT e.*, s.name AS staff FROM claim_events e LEFT JOIN staff s ON s.id = e.staff_id WHERE e.claim_id = ? ORDER BY e.id DESC"); $ev->execute([$id]);
$denials = denialCodes($db);
$work = can('insurance.claims');
[$sl, $tone] = CLAIM_STATUS[$cl['status']];
$owed = fils($cl['claimed']) - fils($cl['paid']);

$pageTitle = 'Claim ' . $cl['number'];
$pageSubtitle = '<span class="chip ' . $tone . '">' . $sl . '</span> · ' . sanitize($cl['company'] . ' – ' . $cl['plan_name']) . ' · <a href="/pages/patient.php?id=' . (int)$p['id'] . '&amp;tab=insurance">' . sanitize(patientName($p)) . '</a> · member ' . sanitize((string)$cl['member_no']);
$pageBack = ['href' => '/pages/claims.php', 'label' => 'Claims'];
$pageActions = '<a class="btn btn-ghost" href="/pages/invoice.php?id=' . (int)$cl['invoice_id'] . '"><i class="bi bi-receipt"></i>' . sanitize($cl['invoice_number']) . '</a>';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?>"><i class="bi bi-info-circle"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<?php if ($problems): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i><div><strong>Before this claim can be sent:</strong><ul class="mb-1"><?php foreach ($problems as $x): ?><li><?= sanitize($x) ?></li><?php endforeach; ?></ul>
  <?php if ($work && $cl['status'] === 'draft'): ?><form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="recheck"><button class="btn btn-ghost btn-sm">Check again</button></form><?php endif; ?></div></div><?php endif; ?>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card-crm mb-3"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>CPT</th><th>Item</th><th class="text-end">Qty</th><th class="text-end">Total</th><th class="text-end">Patient</th><th class="text-end">Claimed</th><th>Clinician</th></tr></thead>
      <tbody><?php foreach ($acts as $a): ?><tr><td class="tabular"><?= sanitize((string)$a['cpt_code']) ?: '<span class="text-danger">missing</span>' ?></td><td><?= sanitize($a['description']) ?></td><td class="text-end"><?= fmtQty($a['qty']) ?></td>
        <td class="text-end tabular"><?= number_format($a['line_total'], 2) ?></td><td class="text-end tabular"><?= number_format($a['patient_share'], 2) ?></td><td class="text-end tabular fw-600"><?= number_format($a['insurance_share'], 2) ?></td><td class="fs-13"><?= sanitize((string)$a['clinician']) ?: '<span class="text-danger">missing</span>' ?></td></tr><?php endforeach; ?></tbody>
      <tfoot><tr class="fw-700"><td colspan="5">Claimed</td><td class="text-end tabular"><?= money($cl['claimed']) ?></td><td></td></tr><tr><td colspan="5">Paid by the insurer</td><td class="text-end tabular"><?= money($cl['paid']) ?></td><td></td></tr></tfoot></table></div>
      <div class="card-body-crm fs-13">Diagnoses: <?= $dx ? sanitize(implode(', ', array_column($dx, 'icd10_code'))) : '<span class="text-danger">none</span>' ?><?= $cl['denial_code'] ? ' · Denial: <strong>' . sanitize($cl['denial_code']) . '</strong> ' . sanitize($denials[$cl['denial_code']] ?? '') : '' ?>
        <?= $cl['submission_ref'] ? ' · Submitted ' . formatDate($cl['submitted_at']) . ' (' . sanitize($cl['submission_ref']) . ')' : '' ?></div></div>
    <div class="card-crm"><div class="card-header-crm"><span><i class="bi bi-clock-history"></i> History</span></div><div class="list-group list-group-flush">
      <?php foreach ($ev as $e): ?><div class="list-group-item fs-13"><strong><?= sanitize(CLAIM_STATUS[$e['to_status']][0] ?? $e['to_status']) ?></strong> · <?= formatDate($e['at']) ?> <?= date('H:i', strtotime($e['at'])) ?> · <?= sanitize((string)$e['staff']) ?><?= $e['amount'] !== null ? ' · ' . money($e['amount']) : '' ?><?= $e['note'] ? '<div class="text-tx-2">' . sanitize($e['note']) . '</div>' : '' ?></div><?php endforeach; ?>
    </div></div>
  </div>
  <?php if ($work): ?>
  <div class="col-xl-4">
    <?php if (in_array($cl['status'], ['submitted', 'resubmitted', 'accepted', 'partially_paid', 'rejected'], true) && $owed > 0): ?>
    <form method="POST" class="card-crm mb-3"><?= csrfField() ?><input type="hidden" name="action" value="remit">
      <div class="card-header-crm"><span><i class="bi bi-bank"></i> Record a payment from the insurer</span></div><div class="card-body-crm">
        <div class="row g-2"><div class="col-6"><label class="form-label" for="ra">Amount</label><input class="form-control" id="ra" name="amount" type="number" min="0.01" max="<?= aed($owed) ?>" step="0.01" value="<?= aed($owed) ?>" required></div>
          <div class="col-6"><label class="form-label" for="rd">Date</label><input class="form-control" type="date" id="rd" name="date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div></div>
        <label class="form-label mt-2" for="rr">Remittance reference</label><input class="form-control" id="rr" name="ref" maxlength="100" required>
        <label class="form-label mt-2" for="rdc">Denial code for any part not paid</label><input class="form-control" id="rdc" name="denial_code" list="dcl" maxlength="20">
      </div><div class="form-actions"><button class="btn btn-gold ms-auto">Record payment</button></div></form>
    <?php endif; ?>
    <?php if (in_array($cl['status'], ['submitted', 'resubmitted'], true)): ?>
    <form method="POST" class="card-crm mb-3"><?= csrfField() ?><input type="hidden" name="action" value="accepted"><div class="card-body-crm d-flex gap-2 align-items-end">
      <div class="flex-grow-1"><label class="form-label" for="aa">Accepted, approved amount</label><input class="form-control" id="aa" name="approved" type="number" min="0" step="0.01" value="<?= sanitize($cl['claimed']) ?>"></div><button class="btn btn-ghost">Accepted</button></div></form>
    <?php endif; ?>
    <?php if (in_array($cl['status'], ['submitted', 'resubmitted', 'accepted', 'partially_paid'], true)): ?>
    <form method="POST" class="card-crm mb-3"><?= csrfField() ?><input type="hidden" name="action" value="rejected"><div class="card-body-crm">
      <label class="form-label" for="rjc">Rejected: denial code</label><input class="form-control mb-2" id="rjc" name="denial_code" list="dcl" required maxlength="20">
      <label class="form-label" for="rjn">Note</label><input class="form-control" id="rjn" name="note" maxlength="300"></div><div class="form-actions"><button class="btn btn-ghost ms-auto">Rejected</button></div></form>
    <?php endif; ?>
    <?php if ($cl['status'] === 'rejected'): ?>
    <form method="POST" class="card-crm mb-3"><?= csrfField() ?><input type="hidden" name="action" value="ready"><div class="card-body-crm">
      <label class="form-label" for="rwn">Corrected: what changed</label><input class="form-control" id="rwn" name="note" required maxlength="300"></div>
      <div class="form-actions"><span class="fs-12 text-tx-3">It goes into the next batch as a resubmission.</span><button class="btn btn-gold ms-auto">Ready to resend</button></div></form>
    <?php endif; ?>
    <?php if (!in_array($cl['status'], ['paid', 'written_off'], true)): ?>
    <details class="card-crm"><summary class="card-header-crm"><span><i class="bi bi-x-circle"></i> Write off</span></summary>
      <form method="POST" class="card-body-crm d-flex gap-2"><?= csrfField() ?><input type="hidden" name="action" value="written_off"><label class="visually-hidden" for="wo">Why</label>
        <input class="form-control" id="wo" name="note" placeholder="Why it won’t be paid" required maxlength="300"><button class="btn btn-ghost" onclick="return confirm('Write this claim off?')">Write off</button></form></details>
    <?php endif; ?>
    <datalist id="dcl"><?php foreach ($denials as $code => $d): ?><option value="<?= sanitize($code) ?>"><?= sanitize($d) ?></option><?php endforeach; ?></datalist>
  </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
