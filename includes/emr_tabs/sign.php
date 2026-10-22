<?php
// Sign tab of pages/encounter.php: required sections still missing, sign (locks the note and stores its SHA-256), the
// integrity check of a signed note, addenda, and the note's change log.
if (!isset($e)) { http_response_code(404); exit; }
$missing = $e['status'] === 'open' ? missingSections($db, $e) : [];
$required = requiredSections($db, $e);
$intact = encounterIntact($db, $e);
$am = $db->prepare("SELECT a.*, s.name AS by_name FROM encounter_amendments a LEFT JOIN staff s ON s.id = a.staff_id WHERE a.encounter_id = ? ORDER BY a.id");
$am->execute([$id]); $am = $am->fetchAll();
$log = $db->prepare("SELECT a.action, a.at, s.name AS by_name FROM audit_log a LEFT JOIN staff s ON s.id = a.staff_id WHERE a.entity = 'encounter' AND a.entity_id = ? ORDER BY a.id DESC LIMIT 40");
$log->execute([$id]); $log = $log->fetchAll();
$mayAmend = in_array($e['status'], ['signed', 'amended'], true) && can('emr.amend') && ((int)$e['practitioner_id'] === currentStaffId() || can('emr.all'));
?>
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card-crm mb-3">
      <div class="card-header-crm"><span><i class="bi bi-pen"></i> <?= $e['status'] === 'open' ? 'Sign the note' : 'Signed' ?></span></div>
      <div class="card-body-crm">
        <?php if ($e['status'] === 'open'): ?>
          <p class="fs-13 text-tx-2">Required for <?= sanitize($e['department_name'] ?? 'this clinic') ?>: <?= implode(', ', array_map(fn($s) => EMR_SECTIONS[$s], $required)) ?: 'nothing' ?>.</p>
          <?php if ($missing): ?>
          <div class="alert alert-warning"><i class="bi bi-list-check"></i><div><strong>Still empty:</strong> <?= sanitize(implode(', ', $missing)) ?></div></div>
          <?php else: ?><div class="alert alert-success"><i class="bi bi-check2-all"></i>Every required section is filled in.</div><?php endif; ?>
          <?php if (can('emr.sign') && ((int)$e['practitioner_id'] === currentStaffId() || !$e['practitioner_id'] || can('emr.all'))): ?>
          <form method="POST"><?= $hidden('sign') ?>
            <p class="fs-13 text-tx-2">Signing locks the note. After that, corrections can only be added as dated addenda.</p>
            <button class="btn btn-gold" <?= $missing ? 'disabled' : '' ?> data-confirm="Sign and lock this note?"><i class="bi bi-pen"></i>Sign and lock</button>
          </form>
          <?php else: ?><p class="fs-13 text-tx-3 mb-0">The treating doctor signs this note.</p><?php endif; ?>
        <?php else: ?>
          <div class="kv"><span class="kv-label">Signed by</span><span class="kv-value"><?= sanitize($e['signed_by_name'] ?? '') ?></span></div>
          <div class="kv"><span class="kv-label">Signed</span><span class="kv-value"><?= date('d M Y, H:i', strtotime($e['signed_at'])) ?></span></div>
          <div class="kv"><span class="kv-label">Integrity</span><span class="kv-value"><?= $intact ? '<i class="bi bi-shield-check text-green"></i> Unchanged since signing' : '<i class="bi bi-shield-exclamation text-red"></i> <strong class="text-red">Content differs from what was signed</strong>' ?></span></div>
          <div class="fs-12 text-tx-3 mt-1">SHA-256 <code><?= sanitize(substr((string)$e['content_hash'], 0, 16)) ?>…</code></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-journal-plus"></i> Addenda</span></div>
      <div class="list-group list-group-flush">
        <?php foreach ($am as $a): ?><div class="list-group-item"><div class="fs-12 text-tx-3"><?= date('d M Y, H:i', strtotime($a['at'])) ?> · <?= sanitize($a['by_name'] ?? '') ?> · <strong>Reason:</strong> <?= sanitize($a['reason']) ?></div><div class="fs-13" style="white-space:pre-line"><?= sanitize($a['text']) ?></div></div><?php endforeach; ?>
        <?php if (!$am): ?><div class="list-group-item text-tx-3 fs-13">None.</div><?php endif; ?>
      </div>
      <?php if ($mayAmend): ?>
      <form method="POST" class="card-body-crm border-top"><?= $hidden('amend') ?>
        <label class="form-label" for="amR">Reason</label><input class="form-control mb-2" id="amR" name="reason" maxlength="255" required placeholder="e.g. Wrong dose recorded">
        <label class="form-label" for="amT">Correction</label><textarea class="form-control mb-2" id="amT" name="text" rows="3" required></textarea>
        <div class="d-flex"><button class="btn btn-gold ms-auto"><i class="bi bi-plus-lg"></i>Add addendum</button></div>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-clock-history"></i> Changes</span></div>
      <div class="list-group list-group-flush">
        <?php foreach ($log as $l): ?><div class="list-group-item fs-13"><?= sanitize(str_replace('_', ' ', $l['action'])) ?> · <?= date('d M H:i', strtotime($l['at'])) ?> · <?= sanitize($l['by_name'] ?? '') ?></div><?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
