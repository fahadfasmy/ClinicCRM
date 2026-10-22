<?php
// Dental tab of pages/encounter.php: FDI chart (permanent and primary teeth) with every finding / planned / done entry per
// tooth across visits; this visit's entries can be removed while the note is open. Planned work becomes invoice lines
// with billing (Phase 7).
if (!isset($e)) { http_response_code(404); exit; }
$s = $db->prepare("SELECT d.*, s.name AS by_name FROM dental_chart d LEFT JOIN staff s ON s.id = d.recorded_by WHERE d.patient_id = ? ORDER BY d.at DESC, d.id DESC");
$s->execute([$e['patient_id']]);
$entries = $s->fetchAll();
$byTooth = [];
foreach ($entries as $x) $byTooth[$x['tooth']][] = $x;
$state = function (string $tooth) use ($byTooth): string {
    $types = array_column($byTooth[$tooth] ?? [], 'entry_type');
    return in_array('planned', $types, true) ? 'planned' : (in_array('condition', $types, true) ? 'condition' : ($types ? 'done' : ''));
};
?>
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-grid-3x3"></i> Dental chart (FDI)</span>
        <span class="card-header-sub"><span class="tooth-key condition"></span> finding <span class="tooth-key planned"></span> planned <span class="tooth-key done"></span> done</span></div>
      <div class="card-body-crm">
        <?php foreach ([false => 'Permanent teeth', true => 'Primary (milk) teeth'] as $primary => $label): ?>
        <div class="fs-12 fw-600 text-tx-3 mb-1"><?= $label ?></div>
        <div class="tooth-chart mb-3" role="group" aria-label="<?= $label ?>">
          <?php foreach (fdiTeeth((bool)$primary) as $row): ?><div class="tooth-row">
            <?php foreach ($row as $i => $t): ?><button type="button" class="tooth <?= $state((string)$t) ?> <?= $i === count($row) / 2 ? 'midline' : '' ?>" data-tooth="<?= $t ?>" aria-label="Tooth <?= $t ?><?= isset($byTooth[$t]) ? ', ' . count($byTooth[$t]) . ' entries' : '' ?>"><?= $t ?></button><?php endforeach; ?>
          </div><?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <?php if ($editable): ?>
        <form method="POST" class="border-top pt-3" id="toothForm"><?= $hidden('dental_add') ?>
          <div class="row g-2 align-items-end">
            <div class="col-3 col-md-2"><label class="form-label fs-12" for="dTooth">Tooth</label><input class="form-control form-control-sm" id="dTooth" name="tooth" inputmode="numeric" maxlength="2" required></div>
            <div class="col-9 col-md-3"><label class="form-label fs-12" for="dType">Entry</label><select class="form-select form-select-sm" id="dType" name="entry_type"><?php foreach (DENTAL_TYPES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="col-md-7"><label class="form-label fs-12" for="dCode">Finding / procedure</label><input class="form-control form-control-sm" id="dCode" name="code" list="dentalCodes" maxlength="60" required placeholder="e.g. Caries, Composite filling, Root canal"></div>
            <div class="col-md-7"><span class="form-label fs-12 d-block">Surfaces</span><?php foreach (DENTAL_SURFACES as $k => $l): ?><label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="surfaces[]" value="<?= $k ?>"> <span class="form-check-label" title="<?= $l ?>"><?= $k ?></span></label><?php endforeach; ?></div>
            <div class="col-md-5"><label class="form-label fs-12" for="dNote">Note</label><input class="form-control form-control-sm" id="dNote" name="note" maxlength="255"></div>
            <div class="col-12 d-flex"><button class="btn btn-gold btn-sm ms-auto"><i class="bi bi-plus-lg"></i>Add to chart</button></div>
          </div>
          <datalist id="dentalCodes"><?php foreach (['Caries', 'Missing', 'Fractured', 'Composite filling', 'Amalgam filling', 'Crown', 'Bridge', 'Root canal treatment', 'Extraction', 'Implant', 'Scaling', 'Veneer', 'Whitening', 'Sealant'] as $c): ?><option value="<?= $c ?>"><?php endforeach; ?></datalist>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-list-ul"></i> Entries</span><span class="card-header-sub" id="toothFilter">All teeth</span></div>
      <div class="list-group list-group-flush" id="toothEntries">
        <?php foreach ($entries as $x): ?>
        <div class="list-group-item d-flex gap-2 align-items-start" data-tooth="<?= sanitize($x['tooth']) ?>">
          <span class="tooth <?= $x['entry_type'] ?> small-tooth"><?= sanitize($x['tooth']) ?></span>
          <div class="fs-13"><strong><?= sanitize($x['code']) ?></strong> <span class="text-tx-3"><?= DENTAL_TYPES[$x['entry_type']] ?><?= $x['surfaces'] ? ' · ' . sanitize($x['surfaces']) : '' ?></span>
            <div class="fs-12 text-tx-3"><?= formatDate($x['at']) ?><?= $x['by_name'] ? ' · ' . sanitize($x['by_name']) : '' ?><?= $x['note'] ? ' · ' . sanitize($x['note']) : '' ?></div></div>
          <?php if ($editable && (int)$x['encounter_id'] === $id): ?><form method="POST" class="ms-auto"><?= $hidden('dental_remove') ?><input type="hidden" name="entry_id" value="<?= (int)$x['id'] ?>"><button class="btn btn-ghost btn-sm" aria-label="Remove entry"><i class="bi bi-x-lg"></i></button></form><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (!$entries): ?><div class="list-group-item text-tx-3 fs-13">No dental entries yet.</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php $extraJs = <<<'JS'
document.querySelectorAll('.tooth-chart .tooth').forEach(b => b.addEventListener('click', () => {
  const t = b.dataset.tooth, f = document.getElementById('dTooth');
  if (f) { f.value = t; document.getElementById('dCode').focus(); }
  document.querySelectorAll('#toothEntries [data-tooth]').forEach(r => r.hidden = r.dataset.tooth !== t);
  document.getElementById('toothFilter').innerHTML = 'Tooth ' + t + ' · <a href="#" id="allTeeth">all teeth</a>';
  document.getElementById('allTeeth').onclick = (e) => { e.preventDefault(); document.querySelectorAll('#toothEntries [data-tooth]').forEach(r => r.hidden = false); document.getElementById('toothFilter').textContent = 'All teeth'; };
}));
JS;
