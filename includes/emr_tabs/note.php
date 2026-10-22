<?php
// Note tab of pages/encounter.php: vitals, the doctor's note, ICD-10 diagnoses, procedures, allergies and history.
// Expects $db, $e, $p, $editable, $hidden, $id from the page.
if (!isset($e)) { http_response_code(404); exit; }
$vit = $db->prepare("SELECT v.*, s.name AS by_name FROM vitals v LEFT JOIN staff s ON s.id = v.recorded_by WHERE v.encounter_id = ? ORDER BY v.id DESC");
$vit->execute([$id]); $vit = $vit->fetchAll();
$dx = $db->prepare("SELECT * FROM encounter_diagnoses WHERE encounter_id = ? ORDER BY is_primary DESC, id"); $dx->execute([$id]); $dx = $dx->fetchAll();
$pr = $db->prepare("SELECT x.*, s.name AS by_name FROM encounter_procedures x LEFT JOIN staff s ON s.id = x.performed_by WHERE x.encounter_id = ? ORDER BY x.id"); $pr->execute([$id]); $pr = $pr->fetchAll();
$allAllergies = patientAllergies($db, (int)$p['id'], false);
$history = patientHistory($db, (int)$p['id']);
$services = $db->query("SELECT id, name FROM services WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$templates = $editable ? templatesFor($db, $e) : [];
$txt = fn($v) => $v !== null && $v !== '' ? nl2br(sanitize($v)) : '<span class="text-tx-3">—</span>';
?>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card-crm mb-3" id="vitals">
      <div class="card-header-crm"><span><i class="bi bi-heart-pulse"></i> Vitals</span><span class="card-header-sub">Triage</span></div>
      <?php if ($vit): ?><div class="table-responsive"><table class="table table-sm mb-0 tabular">
        <thead><tr><th>Time</th><th>BP</th><th>Pulse</th><th>Temp</th><th>RR</th><th>SpO₂</th><th>Weight</th><th>Height</th><th>BMI</th><th>Pain</th><th>By</th></tr></thead><tbody>
        <?php foreach ($vit as $v): ?><tr><td><?= date('H:i', strtotime($v['recorded_at'])) ?></td><td><?= $v['bp_sys'] ? (int)$v['bp_sys'] . '/' . (int)$v['bp_dia'] : '–' ?></td><td><?= $v['pulse'] ?? '–' ?></td>
          <td><?= $v['temp_c'] ?? '–' ?></td><td><?= $v['resp_rate'] ?? '–' ?></td><td><?= $v['spo2'] !== null ? $v['spo2'] . '%' : '–' ?></td><td><?= $v['weight_kg'] !== null ? (float)$v['weight_kg'] . ' kg' : '–' ?></td>
          <td><?= $v['height_cm'] !== null ? (float)$v['height_cm'] . ' cm' : '–' ?></td><td><?= $v['bmi'] ?? '–' ?></td><td><?= $v['pain_score'] ?? '–' ?></td><td class="fs-12"><?= sanitize($v['by_name'] ?? '') ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
      <?php if ($editable): ?>
      <form method="POST" class="card-body-crm border-top">
        <?= $hidden('vitals', 'vitals') ?>
        <div class="row g-2">
          <?php foreach (['bp_sys' => ['BP systolic', 'mmHg'], 'bp_dia' => ['BP diastolic', 'mmHg'], 'pulse' => ['Pulse', 'bpm'], 'temp_c' => ['Temp', '°C'], 'resp_rate' => ['Resp. rate', '/min'],
                          'spo2' => ['SpO₂', '%'], 'weight_kg' => ['Weight', 'kg'], 'height_cm' => ['Height', 'cm'], 'pain_score' => ['Pain', '0–10']] as $k => [$l, $u]): ?>
          <div class="col-6 col-md-4 col-lg-2"><label class="form-label fs-12" for="v_<?= $k ?>"><?= $l ?> <span class="text-tx-3"><?= $u ?></span></label>
            <input class="form-control form-control-sm" id="v_<?= $k ?>" name="<?= $k ?>" inputmode="decimal"></div>
          <?php endforeach; ?>
          <div class="col-6 col-md-4 col-lg-2 d-flex align-items-end"><button class="btn btn-gold btn-sm w-100"><i class="bi bi-plus-lg"></i>Record</button></div>
        </div>
      </form>
      <?php elseif (!$vit): ?><div class="card-body-crm text-tx-3 fs-13">No vitals recorded.</div><?php endif; ?>
    </div>

    <div class="card-crm mb-3" id="note">
      <div class="card-header-crm"><span><i class="bi bi-journal-text"></i> Doctor’s note</span>
        <?php if ($templates): ?><form method="POST" class="d-flex gap-1 ms-auto"><?= $hidden('template', 'note') ?>
          <select class="form-select form-select-sm" name="template_id" aria-label="Template"><?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>"><?= sanitize($t['name']) ?></option><?php endforeach; ?></select>
          <button class="btn btn-ghost btn-sm text-nowrap">Use template</button></form><?php endif; ?></div>
      <?php if ($editable): ?>
      <form method="POST" class="card-body-crm">
        <?= $hidden('note', 'note') ?><input type="hidden" name="allergies_reviewed_sent" value="1">
        <?php foreach (EMR_NOTE_FIELDS as $k => $l): ?>
        <label class="form-label" for="n_<?= $k ?>"><?= $l ?></label>
        <textarea class="form-control mb-3" id="n_<?= $k ?>" name="<?= $k ?>" rows="<?= in_array($k, ['chief_complaint', 'followup_instruction'], true) ? 2 : 3 ?>"><?= sanitize((string)$e[$k]) ?></textarea>
        <?php endforeach; ?>
        <div class="row g-2 align-items-end mb-2">
          <div class="col-md-5"><label class="form-label" for="n_fd">Follow-up date <span class="text-tx-3 fw-normal">(goes on the waiting list when signed)</span></label>
            <input type="date" class="form-control" id="n_fd" name="followup_date" value="<?= sanitize((string)($e['data']['followup_date'] ?? '')) ?>" min="<?= date('Y-m-d') ?>"></div>
          <div class="col-md-7"><label class="form-check"><input class="form-check-input" type="checkbox" name="allergies_reviewed" value="1" <?= $e['allergies_reviewed'] ? 'checked' : '' ?>>
            <span class="form-check-label">Allergies checked with the patient (list on the right is up to date)</span></label></div>
        </div>
        <div class="d-flex"><button class="btn btn-gold ms-auto"><i class="bi bi-check-lg"></i>Save note</button></div>
      </form>
      <?php else: ?>
      <div class="card-body-crm"><?php foreach (EMR_NOTE_FIELDS as $k => $l): ?><div class="mb-2"><div class="fs-12 fw-600 text-tx-3"><?= $l ?></div><div><?= $txt($e[$k]) ?></div></div><?php endforeach; ?>
        <?php if (!empty($e['data']['followup_date'])): ?><div class="fs-13">Follow-up: <?= formatDate($e['data']['followup_date']) ?></div><?php endif; ?></div>
      <?php endif; ?>
    </div>

    <div class="card-crm mb-3" id="dx">
      <div class="card-header-crm"><span><i class="bi bi-clipboard2-check"></i> Diagnoses (ICD-10)</span></div>
      <div class="list-group list-group-flush">
        <?php foreach ($dx as $d): ?>
        <div class="list-group-item d-flex gap-2 align-items-center"><code><?= sanitize(icdFormat($d['icd10_code'])) ?></code><span><?= sanitize($d['description']) ?></span>
          <?php if ($d['is_primary']): ?><span class="chip tone-gold">Primary</span><?php endif; ?><?php if ($d['kind'] === 'provisional'): ?><span class="chip tone-slate">Provisional</span><?php endif; ?>
          <?php if ($editable): ?><form method="POST" class="ms-auto"><?= $hidden('dx_remove', 'dx') ?><input type="hidden" name="line_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-ghost btn-sm" aria-label="Remove diagnosis"><i class="bi bi-x-lg"></i></button></form><?php endif; ?></div>
        <?php endforeach; ?>
        <?php if (!$dx): ?><div class="list-group-item text-tx-3 fs-13">None yet.</div><?php endif; ?>
      </div>
      <?php if ($editable): ?>
      <form method="POST" class="card-body-crm border-top position-relative" id="dxForm">
        <?= $hidden('dx_add', 'dx') ?><input type="hidden" name="code" id="dxCode">
        <div class="row g-2 align-items-end">
          <div class="col-md-6 position-relative"><label class="form-label" for="dxSearch">Search code or words</label>
            <input class="form-control" id="dxSearch" placeholder="e.g. L70 or acne" autocomplete="off" aria-controls="dxResults">
            <div class="list-group sched-results" id="dxResults" role="listbox" hidden></div></div>
          <div class="col-6 col-md-2"><select class="form-select" name="kind" aria-label="Kind"><option value="final">Final</option><option value="provisional">Provisional</option></select></div>
          <div class="col-6 col-md-2"><label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_primary" value="1"> <span class="form-check-label">Primary</span></label></div>
          <div class="col-md-2"><button class="btn btn-gold w-100" id="dxAdd" disabled><i class="bi bi-plus-lg"></i>Add</button></div>
        </div>
        <div class="fs-12 text-tx-3 mt-1" id="dxChosen" aria-live="polite"></div>
      </form>
      <?php endif; ?>
    </div>

    <div class="card-crm" id="proc">
      <div class="card-header-crm"><span><i class="bi bi-bandaid"></i> Procedures</span></div>
      <div class="list-group list-group-flush">
        <?php foreach ($pr as $x): ?>
        <div class="list-group-item d-flex gap-2 align-items-center"><span class="fw-600"><?= sanitize($x['description']) ?></span>
          <span class="fs-12 text-tx-3"><?= $x['cpt_code'] ? 'CPT ' . sanitize($x['cpt_code']) . ' · ' : '' ?><?= $x['area'] ? sanitize($x['area']) . ' · ' : '' ?><?= $x['tooth'] ? 'tooth ' . sanitize($x['tooth']) . ' · ' : '' ?>× <?= (float)$x['qty'] ?><?= $x['by_name'] ? ' · ' . sanitize($x['by_name']) : '' ?></span>
          <?php if ($editable): ?><form method="POST" class="ms-auto"><?= $hidden('proc_remove', 'proc') ?><input type="hidden" name="line_id" value="<?= (int)$x['id'] ?>"><button class="btn btn-ghost btn-sm" aria-label="Remove procedure"><i class="bi bi-x-lg"></i></button></form><?php endif; ?></div>
        <?php endforeach; ?>
        <?php if (!$pr): ?><div class="list-group-item text-tx-3 fs-13">None yet.</div><?php endif; ?>
      </div>
      <?php if ($editable): ?>
      <form method="POST" class="card-body-crm border-top"><?= $hidden('proc_add', 'proc') ?>
        <div class="row g-2 align-items-end">
          <div class="col-md-4"><label class="form-label fs-12" for="pSvc">Treatment</label><select class="form-select form-select-sm" id="pSvc" name="service_id"><option value="">Other (describe)</option><?php foreach ($services as $sid => $sn): ?><option value="<?= $sid ?>"><?= sanitize($sn) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-4"><label class="form-label fs-12" for="pDesc">Description</label><input class="form-control form-control-sm" id="pDesc" name="description" maxlength="150"></div>
          <div class="col-4 col-md-2"><label class="form-label fs-12" for="pCpt">CPT</label><input class="form-control form-control-sm" id="pCpt" name="cpt_code" maxlength="10"></div>
          <div class="col-4 col-md-2"><label class="form-label fs-12" for="pQty">Qty</label><input class="form-control form-control-sm" id="pQty" name="qty" value="1" inputmode="decimal"></div>
          <div class="col-4 col-md-4"><label class="form-label fs-12" for="pArea">Area</label><input class="form-control form-control-sm" id="pArea" name="area" maxlength="80"></div>
          <div class="col-4 col-md-2"><label class="form-label fs-12" for="pTooth">Tooth</label><input class="form-control form-control-sm" id="pTooth" name="tooth" maxlength="2" inputmode="numeric" placeholder="FDI"></div>
          <div class="col-8 col-md-4"><label class="form-label fs-12" for="pNotes">Notes</label><input class="form-control form-control-sm" id="pNotes" name="notes" maxlength="255"></div>
          <div class="col-md-2"><button class="btn btn-gold btn-sm w-100"><i class="bi bi-plus-lg"></i>Add</button></div>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="card-crm mb-3" id="allergies">
      <div class="card-header-crm"><span><i class="bi bi-exclamation-octagon"></i> Allergies</span><span class="card-header-sub">All visits</span></div>
      <div class="list-group list-group-flush">
        <?php foreach ($allAllergies as $al): ?>
        <div class="list-group-item d-flex gap-2 align-items-start <?= $al['status'] === 'resolved' ? 'text-tx-3' : '' ?>">
          <div><span class="fw-600 <?= $al['status'] === 'resolved' ? 'text-decoration-line-through' : '' ?>"><?= sanitize($al['allergen']) ?></span> <span class="chip <?= $al['severity'] === 'severe' ? 'aging-critical' : 'tone-slate' ?>"><?= ALLERGY_SEVERITY[$al['severity']] ?></span>
            <div class="fs-12"><?= ALLERGY_TYPES[$al['type']] ?><?= $al['reaction'] ? ' · ' . sanitize($al['reaction']) : '' ?></div></div>
          <?php if (can('emr.write')): ?><form method="POST" class="ms-auto"><?= $hidden('allergy_status', 'allergies') ?><input type="hidden" name="allergy_id" value="<?= (int)$al['id'] ?>"><input type="hidden" name="status" value="<?= $al['status'] === 'active' ? 'resolved' : 'active' ?>">
            <button class="btn btn-ghost btn-sm"><?= $al['status'] === 'active' ? 'Resolved' : 'Active again' ?></button></form><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (!$allAllergies): ?><div class="list-group-item text-tx-3 fs-13">None recorded.</div><?php endif; ?>
      </div>
      <?php if (can('emr.write')): ?>
      <form method="POST" class="card-body-crm border-top"><?= $hidden('allergy_add', 'allergies') ?>
        <input class="form-control form-control-sm mb-2" name="allergen" placeholder="Allergen (e.g. Penicillin, Lidocaine, Latex)" required aria-label="Allergen">
        <div class="d-flex gap-2 mb-2"><select class="form-select form-select-sm" name="type" aria-label="Type"><?php foreach (ALLERGY_TYPES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select>
          <select class="form-select form-select-sm" name="severity" aria-label="Severity"><?php foreach (ALLERGY_SEVERITY as $k => $l): ?><option value="<?= $k ?>" <?= $k === 'moderate' ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="d-flex gap-2"><input class="form-control form-control-sm" name="reaction" placeholder="Reaction (e.g. rash)" aria-label="Reaction"><button class="btn btn-gold btn-sm"><i class="bi bi-plus-lg"></i>Add</button></div>
      </form>
      <?php endif; ?>
    </div>

    <div class="card-crm" id="history">
      <div class="card-header-crm"><span><i class="bi bi-clock-history"></i> History and medication</span><span class="card-header-sub">All visits</span></div>
      <div class="list-group list-group-flush">
        <?php foreach ($history as $h): ?>
        <div class="list-group-item d-flex gap-2"><div><div class="fs-12 fw-600 text-tx-3"><?= HISTORY_TYPES[$h['type']] ?></div><div class="fs-13"><?= nl2br(sanitize($h['detail'])) ?></div></div>
          <?php if (can('emr.write')): ?><form method="POST" class="ms-auto"><?= $hidden('history_remove', 'history') ?><input type="hidden" name="history_id" value="<?= (int)$h['id'] ?>"><button class="btn btn-ghost btn-sm" data-confirm="Remove this history entry?" aria-label="Remove"><i class="bi bi-x-lg"></i></button></form><?php endif; ?></div>
        <?php endforeach; ?>
        <?php if (!$history): ?><div class="list-group-item text-tx-3 fs-13">None recorded.</div><?php endif; ?>
      </div>
      <?php if (can('emr.write')): ?>
      <form method="POST" class="card-body-crm border-top"><?= $hidden('history_add', 'history') ?>
        <select class="form-select form-select-sm mb-2" name="type" aria-label="Kind"><?php foreach (HISTORY_TYPES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select>
        <textarea class="form-control form-control-sm mb-2" name="detail" rows="2" required aria-label="Details" placeholder="e.g. Hypothyroidism, on levothyroxine 50 mcg"></textarea>
        <div class="d-flex"><button class="btn btn-gold btn-sm ms-auto"><i class="bi bi-plus-lg"></i>Add</button></div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php if ($editable) $extraJs = <<<'JS'
(() => {
  const inp = document.getElementById('dxSearch'), box = document.getElementById('dxResults'), code = document.getElementById('dxCode'), add = document.getElementById('dxAdd'), chosen = document.getElementById('dxChosen');
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let t = null;
  inp.addEventListener('input', () => {
    clearTimeout(t); code.value = ''; add.disabled = true; chosen.textContent = '';
    const q = inp.value.trim(); if (q.length < 2) { box.hidden = true; return; }
    t = setTimeout(async () => {
      const r = await fetch('/pages/emr_api.php?action=icd&q=' + encodeURIComponent(q), { credentials: 'same-origin' });
      const list = await r.json().catch(() => []);
      box.innerHTML = list.length ? list.map((d, i) => '<button type="button" class="list-group-item list-group-item-action" data-i="' + i + '"><code>' + esc(d.label) + '</code> ' + esc(d.description) + '</button>').join('')
        : '<div class="list-group-item fs-13 text-tx-3">No ICD-10 code found.' + (list.empty ? ' The code list hasn’t been imported yet (Clinical settings).' : '') + '</div>';
      box.hidden = false;
      box.querySelectorAll('[data-i]').forEach(b => b.onclick = () => { const d = list[+b.dataset.i]; code.value = d.code; inp.value = d.label + ' ' + d.description; box.hidden = true; add.disabled = false; chosen.textContent = 'Chosen: ' + d.label; });
    }, 250);
  });
})();
JS;
