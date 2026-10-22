<?php
// Prescriptions tab of pages/encounter.php: the visit's prescriptions (print in English, Arabic or both) and a form for a
// new one with up to 6 medicines. Drug names suggest from earlier prescriptions of this clinic.
if (!isset($e)) { http_response_code(404); exit; }
$s = $db->prepare("SELECT r.*, s.name AS by_name FROM prescriptions r LEFT JOIN staff s ON s.id = r.prescribed_by WHERE r.encounter_id = ? ORDER BY r.id");
$s->execute([$id]);
$rxs = $s->fetchAll();
$items = [];
if ($rxs) {
    $it = $db->query("SELECT * FROM prescription_items WHERE prescription_id IN (" . implode(',', array_map(fn($r) => (int)$r['id'], $rxs)) . ") ORDER BY id")->fetchAll();
    foreach ($it as $i) $items[(int)$i['prescription_id']][] = $i;
}
$canRx = $editable && can('emr.prescribe');
$drugs = $canRx ? $db->query("SELECT DISTINCT drug FROM prescription_items ORDER BY drug LIMIT 500")->fetchAll(PDO::FETCH_COLUMN) : [];
?>
<div class="row g-3">
  <div class="col-xl-<?= $canRx ? 6 : 12 ?>">
    <?php foreach ($rxs as $n => $r): ?>
    <div class="card-crm mb-3">
      <div class="card-header-crm"><span><i class="bi bi-capsule"></i> Prescription <?= $n + 1 ?></span><span class="card-header-sub"><?= sanitize($r['by_name'] ?? '') ?> · <?= formatDate($r['created_at']) ?></span></div>
      <div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>Medicine</th><th>Dose</th><th>Route</th><th>How often</th><th>For</th><th>Qty</th></tr></thead><tbody>
        <?php foreach ($items[(int)$r['id']] ?? [] as $i): ?><tr><td><strong><?= sanitize($i['drug']) ?></strong> <?= sanitize((string)$i['strength']) ?><?= $i['instructions'] ? '<div class="fs-12 text-tx-3">' . sanitize($i['instructions']) . '</div>' : '' ?></td>
          <td><?= sanitize((string)$i['dose']) ?></td><td><?= sanitize((string)$i['route']) ?></td><td><?= sanitize((string)$i['frequency']) ?></td><td><?= sanitize((string)$i['duration']) ?></td><td><?= sanitize((string)$i['qty']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php if ($r['notes']): ?><div class="card-body-crm fs-13 border-top"><?= nl2br(sanitize($r['notes'])) ?></div><?php endif; ?>
      <div class="form-actions">
        <?php foreach (['en' => 'English', 'ar' => 'العربية', 'both' => 'Both'] as $lang => $l): ?><a class="btn btn-ghost btn-sm" target="_blank" href="/pages/encounter_print.php?id=<?= $id ?>&amp;doc=rx&amp;rx=<?= (int)$r['id'] ?>&amp;lang=<?= $lang ?>"><i class="bi bi-printer"></i><?= $l ?></a><?php endforeach; ?>
        <?php if ($canRx): ?><form method="POST" class="ms-auto"><?= $hidden('rx_remove') ?><input type="hidden" name="rx_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-ghost btn-sm" data-confirm="Delete this prescription?"><i class="bi bi-trash"></i>Delete</button></form><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (!$rxs): ?><div class="card-crm"><div class="empty-state"><i class="bi bi-capsule"></i><div class="empty-state-title">No prescriptions</div><?= !can('emr.prescribe') ? '<p>Only doctors prescribe.</p>' : '' ?></div></div><?php endif; ?>
  </div>
  <?php if ($canRx): ?>
  <div class="col-xl-6">
    <form method="POST" class="card-crm"><?= $hidden('rx_add') ?>
      <div class="card-header-crm"><span><i class="bi bi-plus-lg"></i> New prescription</span></div>
      <div class="card-body-crm">
        <?php if (patientAllergies($db, (int)$e['patient_id'])): ?><div class="alert alert-danger py-2 fs-13"><i class="bi bi-exclamation-octagon"></i>Check the allergies above before prescribing.</div><?php endif; ?>
        <?php for ($n = 0; $n < 6; $n++): ?>
        <fieldset class="rx-item mb-3 <?= $n ? 'rx-more' : '' ?>" <?= $n ? 'hidden' : '' ?>>
          <legend class="fs-12 fw-600 text-tx-3 mb-1">Medicine <?= $n + 1 ?></legend>
          <div class="row g-2">
            <div class="col-8"><input class="form-control form-control-sm" name="items[<?= $n ?>][drug]" list="rxDrugs" placeholder="Medicine (generic name)" aria-label="Medicine <?= $n + 1 ?>" <?= $n ? '' : 'required' ?>></div>
            <div class="col-4"><input class="form-control form-control-sm" name="items[<?= $n ?>][strength]" placeholder="Strength, e.g. 500 mg" aria-label="Strength"></div>
            <div class="col-4"><input class="form-control form-control-sm" name="items[<?= $n ?>][dose]" placeholder="Dose, e.g. 1 tablet" aria-label="Dose"></div>
            <div class="col-4"><select class="form-select form-select-sm" name="items[<?= $n ?>][route]" aria-label="Route"><?php foreach (RX_ROUTES as $r): ?><option><?= $r ?></option><?php endforeach; ?></select></div>
            <div class="col-4"><select class="form-select form-select-sm" name="items[<?= $n ?>][frequency]" aria-label="How often"><?php foreach (RX_FREQUENCIES as $f => $_): ?><option><?= $f ?></option><?php endforeach; ?></select></div>
            <div class="col-4"><input class="form-control form-control-sm" name="items[<?= $n ?>][duration]" placeholder="For, e.g. 7 days" aria-label="Duration"></div>
            <div class="col-3"><input class="form-control form-control-sm" name="items[<?= $n ?>][qty]" placeholder="Qty" aria-label="Quantity"></div>
            <div class="col-5"><input class="form-control form-control-sm" name="items[<?= $n ?>][instructions]" placeholder="Instructions, e.g. after food" aria-label="Instructions"></div>
          </div>
        </fieldset>
        <?php endfor; ?>
        <button type="button" class="btn btn-ghost btn-sm mb-3" id="rxMore"><i class="bi bi-plus-lg"></i>Another medicine</button>
        <label class="form-label" for="rxNotes">Notes on the prescription</label><textarea class="form-control" id="rxNotes" name="rx_notes" rows="2" maxlength="500"></textarea>
        <datalist id="rxDrugs"><?php foreach ($drugs as $d): ?><option value="<?= sanitize($d) ?>"><?php endforeach; ?></datalist>
      </div>
      <div class="form-actions"><button class="btn btn-gold ms-auto"><i class="bi bi-check-lg"></i>Save prescription</button></div>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php if ($canRx) $extraJs = "document.getElementById('rxMore').addEventListener('click', () => { const next = document.querySelector('.rx-more[hidden]'); if (next) next.hidden = false; if (!document.querySelector('.rx-more[hidden]')) document.getElementById('rxMore').hidden = true; });";
