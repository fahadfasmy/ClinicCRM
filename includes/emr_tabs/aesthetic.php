<?php
// Aesthetic tab of pages/encounter.php: injection map (click a zone on the face to fill its row), laser settings, skin type
// (Fitzpatrick), aftercare, and the products and batches used in this visit (from the stock records of the appointment).
if (!isset($e)) { http_response_code(404); exit; }
$inj = (array)($e['data']['injections'] ?? []);
$laser = (array)($e['data']['laser'] ?? []);
$used = [];
if ($e['appointment_id']) {
    $items = array_column(appointmentItems($db, (int)$e['appointment_id']), null, 'product_id');
    foreach (appointmentLotsUsed($db, (int)$e['appointment_id']) as $pid => $lots) foreach ($lots as $l) $used[] = ['product' => $items[$pid]['product_name'] ?? '', 'unit' => $items[$pid]['usage_unit'] ?? '', 'lot' => $l['lot_number'], 'expiry' => $l['expiry_date'], 'qty' => $l['qty']];
}
$total = array_sum(array_map(fn($r) => (float)($r['units'] ?? 0), $inj));
?>
<form method="POST" class="row g-3">
  <?= $hidden('aesthetic') ?>
  <div class="col-xl-7">
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-person-bounding-box"></i> Injection map</span><span class="card-header-sub"><?= $total ? 'Total ' . (float)$total : 'Click a zone to fill it in' ?></span></div>
      <div class="card-body-crm d-flex flex-wrap gap-3">
        <svg viewBox="0 0 200 262" width="220" class="face-map" role="img" aria-label="Face map: filled zones are highlighted">
          <ellipse cx="100" cy="125" rx="72" ry="105" fill="none" stroke="currentColor" stroke-opacity=".35" stroke-width="2"/>
          <?php foreach (FACE_ZONES as $z => [$label, $path]): ?>
          <path d="<?= $path ?>" data-zone="<?= $z ?>" class="face-zone <?= isset($inj[$z]) ? 'filled' : '' ?>"><title><?= sanitize($label) ?><?= isset($inj[$z]['units']) ? ': ' . (float)$inj[$z]['units'] : '' ?></title></path>
          <?php endforeach; ?>
        </svg>
        <div class="flex-grow-1 table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Zone</th><th style="width:90px">Units / ml</th><th>Product</th><th>Note</th></tr></thead>
            <tbody>
            <?php foreach (FACE_ZONES as $z => [$label]): $r = $inj[$z] ?? []; ?>
              <tr data-row="<?= $z ?>" class="<?= $r ? '' : 'face-empty' ?>">
                <td class="fs-13 text-nowrap"><?= sanitize($label) ?></td>
                <?php if ($editable): ?>
                <td><input class="form-control form-control-sm" name="inj[<?= $z ?>][units]" value="<?= sanitize((string)($r['units'] ?? '')) ?>" inputmode="decimal" aria-label="<?= sanitize($label) ?> amount"></td>
                <td><input class="form-control form-control-sm" name="inj[<?= $z ?>][product]" value="<?= sanitize((string)($r['product'] ?? '')) ?>" list="injProducts" aria-label="<?= sanitize($label) ?> product"></td>
                <td><input class="form-control form-control-sm" name="inj[<?= $z ?>][note]" value="<?= sanitize((string)($r['note'] ?? '')) ?>" aria-label="<?= sanitize($label) ?> note"></td>
                <?php else: ?><td><?= isset($r['units']) ? (float)$r['units'] : '' ?></td><td class="fs-13"><?= sanitize($r['product'] ?? '') ?></td><td class="fs-13"><?= sanitize($r['note'] ?? '') ?></td><?php endif; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <datalist id="injProducts"><?php foreach (array_unique(array_column($used, 'product')) as $pn): ?><option value="<?= sanitize($pn) ?>"><?php endforeach; ?></datalist>
          <?php if ($editable): ?><button type="button" class="btn btn-ghost btn-sm mt-2" id="showAllZones">Show all zones</button><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card-crm mb-3">
      <div class="card-header-crm"><span><i class="bi bi-lightning"></i> Laser settings</span></div>
      <div class="card-body-crm row g-2">
        <?php foreach (LASER_FIELDS as $k => $l): ?>
        <div class="col-6"><label class="form-label fs-12" for="l_<?= $k ?>"><?= $l ?></label>
          <input class="form-control form-control-sm" id="l_<?= $k ?>" name="laser[<?= $k ?>]" value="<?= sanitize((string)($laser[$k] ?? '')) ?>" <?= $editable ? '' : 'readonly' ?>></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card-crm mb-3">
      <div class="card-header-crm"><span><i class="bi bi-palette2"></i> Skin and aftercare</span></div>
      <div class="card-body-crm">
        <label class="form-label" for="fitz">Skin type (Fitzpatrick)</label>
        <select class="form-select mb-3" id="fitz" name="fitzpatrick" <?= $editable ? '' : 'disabled' ?>><option value="">—</option>
          <?php foreach (FITZPATRICK as $k => $l): ?><option value="<?= $k ?>" <?= ($e['data']['fitzpatrick'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
        <label class="form-label" for="pc">Aftercare given</label>
        <textarea class="form-control" id="pc" name="post_care" rows="3" <?= $editable ? '' : 'readonly' ?>><?= sanitize((string)($e['data']['post_care'] ?? '')) ?></textarea>
      </div>
      <?php if ($editable): ?><div class="form-actions"><button class="btn btn-gold ms-auto"><i class="bi bi-check-lg"></i>Save</button></div><?php endif; ?>
    </div>
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-box-seam"></i> Products used in this visit</span></div>
      <div class="list-group list-group-flush">
        <?php foreach ($used as $u): ?><div class="list-group-item fs-13"><strong><?= sanitize($u['product']) ?></strong> · <?= fmtQty($u['qty']) ?> <?= sanitize($u['unit']) ?> · batch <?= sanitize($u['lot'] ?: '–') ?><?= $u['expiry'] ? ' · exp ' . date('M Y', strtotime($u['expiry'])) : '' ?></div><?php endforeach; ?>
        <?php if (!$used): ?><div class="list-group-item fs-13 text-tx-3"><?= $e['appointment_id'] ? 'Recorded when the treatment is completed (appointment page).' : 'Walk-in note: no appointment stock.' ?></div><?php endif; ?>
      </div>
    </div>
  </div>
</form>
<?php $extraJs = <<<'JS'
(() => {
  const rows = document.querySelectorAll('[data-row]');
  const empty = [...rows].filter(r => r.classList.contains('face-empty'));
  const show = (all) => empty.forEach(r => r.hidden = !all);
  if (empty.length < rows.length) show(false);   // only filled zones until someone clicks a zone or "show all"
  document.querySelectorAll('.face-zone').forEach(z => z.addEventListener('click', () => {
    const r = document.querySelector('[data-row="' + z.dataset.zone + '"]');
    if (!r) return; r.hidden = false; r.scrollIntoView({ block: 'nearest' });
    const i = r.querySelector('input'); if (i) i.focus(); else { r.classList.add('table-active'); setTimeout(() => r.classList.remove('table-active'), 1200); }
  }));
  document.getElementById('showAllZones')?.addEventListener('click', () => show(true));
  document.querySelectorAll('[data-row] input').forEach(i => i.addEventListener('input', () => {
    const z = i.closest('[data-row]').dataset.row, filled = [...i.closest('[data-row]').querySelectorAll('input')].some(x => x.value.trim());
    document.querySelector('.face-zone[data-zone="' + z + '"]')?.classList.toggle('filled', filled);
  }));
})();
JS;
