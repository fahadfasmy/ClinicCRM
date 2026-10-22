<?php
// includes/lead_form.php — shared Add / Edit lead form.
// Expects: $formMode ('add'|'edit'), $f (field values), $formPhones (string[]), $selectedTreats (int[]),
//          $sources (rows: slug,name,icon), $allStaff, $allTreatments, $cancelHref
$isEdit = ($formMode === 'edit');
$v = fn(string $k, $default = '') => $f[$k] ?? $default;

$genderOpts  = ['unknown'=>'Prefer not to say','female'=>'Female','male'=>'Male','other'=>'Other'];
$ageOpts     = ['unknown'=>'Unknown','18-24'=>'18–24','25-34'=>'25–34','35-44'=>'35–44','45-54'=>'45–54','55+'=>'55+'];
$budgetOpts  = ['unknown'=>'Unknown','under_500'=>'Under AED 500','500_1000'=>'AED 500 – 1,000','1000_3000'=>'AED 1,000 – 3,000','3000_plus'=>'AED 3,000+'];
$urgencyOpts = ['unknown'=>'Unknown','immediate'=>'Immediate','within_month'=>'Within a month','3_months'=>'Within 3 months','just_browsing'=>'Just browsing'];
$stageOpts   = ['new'=>'New','contacted'=>'Contacted','qualified'=>'Qualified','booked'=>'Booked','converted'=>'Converted','lost'=>'Lost','cold'=>'Cold'];
if (!$isEdit) unset($stageOpts['lost'], $stageOpts['cold']);

// Group treatments by category (case-insensitive)
$treatGroups = [];
foreach ($allTreatments as $t) {
    $cat = ucwords(strtolower(trim($t['category'] ?: 'Other')));
    $treatGroups[$cat][] = $t;
}
ksort($treatGroups);
$selectedTreats = array_map('intval', $selectedTreats);

if (!function_exists('formOptions')) {
function formOptions(array $opts, $selected): string {
    $html = '';
    foreach ($opts as $val => $label) {
        $html .= '<option value="' . sanitize((string)$val) . '"' . ((string)$selected === (string)$val ? ' selected' : '') . '>' . sanitize($label) . '</option>';
    }
    return $html;
}
}
?>
<form method="POST" id="leadForm" novalidate>
<div class="row g-3">

  <!-- ── Main ── -->
  <div class="col-xl-8">
    <div class="card-crm">

      <div class="form-section">
        <h2 class="form-section-title">Contact</h2>
        <p class="form-section-sub">Who is this lead and how do we reach them?</p>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="fName">Full name <span class="req">*</span></label>
            <input type="text" name="name" id="fName" class="form-control" required <?= $isEdit ? '' : 'autofocus' ?>
                   value="<?= sanitize($v('name')) ?>" placeholder="Client full name">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="fEmail">Email</label>
            <input type="email" name="email" id="fEmail" class="form-control" value="<?= sanitize($v('email')) ?>" placeholder="client@email.com">
          </div>
          <div class="col-md-6">
            <label class="form-label">Phone number(s) <span class="req">*</span></label>
            <div id="phonesContainer">
              <?php foreach ($formPhones as $i => $ph): ?>
              <div class="phone-row mb-2">
                <div class="d-flex gap-2">
                  <input type="tel" name="phones[]" class="form-control phone-input tabular" value="<?= sanitize($ph) ?>" placeholder="+971 50 123 4567" <?= $i === 0 ? 'required' : '' ?>>
                  <?php if ($i > 0): ?><button type="button" class="btn-icon danger flex-shrink-0 mt-1" onclick="removePhone(this)" title="Remove number" aria-label="Remove number"><i class="bi bi-x-lg"></i></button><?php endif; ?>
                </div>
                <div class="dup-warning form-text text-red d-none"></div>
              </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-subtle btn-sm" onclick="addPhone()"><i class="bi bi-plus-lg"></i>Add another number</button>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="fGender">Gender</label>
            <select name="gender" id="fGender" class="form-select"><?= formOptions($genderOpts, $v('gender', 'unknown')) ?></select>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="fAge">Age range</label>
            <select name="age_range" id="fAge" class="form-select"><?= formOptions($ageOpts, $v('age_range', 'unknown')) ?></select>
          </div>
        </div>
      </div>

      <div class="form-section">
        <h2 class="form-section-title">Source</h2>
        <p class="form-section-sub">Where did this lead come from?</p>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="sourceSelect">Lead source <span class="req">*</span></label>
            <select name="source" class="form-select" id="sourceSelect" onchange="toggleSourceFields()">
              <?php foreach ($sources as $src): ?>
              <option value="<?= sanitize($src['slug']) ?>" <?= $v('source', 'other') === $src['slug'] ? 'selected' : '' ?>>
                <?= htmlspecialchars(trim(($src['icon'] ?? '') . ' ' . $src['name']), ENT_QUOTES, 'UTF-8') ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6" id="campaignField">
            <label class="form-label" for="fCampaign">Campaign / ad name</label>
            <input type="text" name="source_campaign" id="fCampaign" class="form-control" value="<?= sanitize($v('source_campaign')) ?>" placeholder="e.g. Summer Botox Ad">
          </div>
          <div class="col-md-6" id="referrerField">
            <label class="form-label" for="fReferrer">Referred by</label>
            <input type="text" name="referrer_name" id="fReferrer" class="form-control" value="<?= sanitize($v('referrer_name')) ?>" placeholder="Name of the person who referred them">
          </div>
        </div>
      </div>

      <div class="form-section">
        <h2 class="form-section-title">Qualification</h2>
        <p class="form-section-sub">Budget, urgency and treatments raise or lower the lead score.</p>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="fBudget">Budget</label>
            <select name="budget_range" id="fBudget" class="form-select"><?= formOptions($budgetOpts, $v('budget_range', 'unknown')) ?></select>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="fUrgency">Urgency</label>
            <select name="urgency" id="fUrgency" class="form-select"><?= formOptions($urgencyOpts, $v('urgency', 'unknown')) ?></select>
          </div>
          <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">
              <label class="form-label mb-0">Treatment interest <span class="text-tx-3 fw-normal" id="treatCount"></span></label>
              <input type="search" class="form-control form-control-sm" id="treatFilter" placeholder="Find a treatment…" style="max-width:220px">
            </div>
            <div class="treat-picker" id="treatPicker">
              <?php foreach ($treatGroups as $cat => $items): ?>
              <div class="treat-group">
                <div class="treat-group-label"><?= sanitize($cat) ?></div>
                <div class="d-flex flex-wrap gap-2">
                  <?php foreach ($items as $t): ?>
                  <label class="pill-check" data-name="<?= sanitize(strtolower($t['name'])) ?>">
                    <input type="checkbox" name="treatments[]" value="<?= (int)$t['id'] ?>" <?= in_array((int)$t['id'], $selectedTreats, true) ? 'checked' : '' ?>>
                    <span><?= sanitize($t['name']) ?><?php if ((float)$t['avg_price'] > 0): ?> <small class="text-tx-3">AED <?= number_format($t['avg_price'], 0) ?></small><?php endif; ?></span>
                  </label>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="form-section">
        <h2 class="form-section-title">Notes</h2>
        <textarea name="notes" class="form-control mt-2" rows="3" placeholder="Anything the team should know…"><?= sanitize($v('notes')) ?></textarea>
      </div>
    </div>
  </div>

  <!-- ── Side ── -->
  <div class="col-xl-4">
    <div class="card-crm form-side">
      <div class="card-header-crm"><span><i class="bi bi-kanban"></i> Pipeline</span></div>
      <div class="card-body-crm">
        <div class="mb-3">
          <label class="form-label" for="stageSelect">Stage</label>
          <select name="stage" class="form-select" id="stageSelect" onchange="toggleLostReason()"><?= formOptions($stageOpts, $v('stage', 'new')) ?></select>
          <?php if (!empty($formOpenAppts)): ?>
          <div class="form-check mt-2 <?= in_array($v('stage'), ['lost', 'cold'], true) ? '' : 'd-none' ?>" id="cancelApptsField">
            <input class="form-check-input" type="checkbox" name="cancel_appointments" id="cancelAppts" value="1" checked>
            <label class="form-check-label fs-12" for="cancelAppts">Cancel <?= (int)$formOpenAppts ?> booked treatment<?= $formOpenAppts > 1 ? 's' : '' ?> and release the stock</label>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($isEdit): ?>
        <div class="mb-3 <?= $v('stage') === 'lost' ? '' : 'd-none' ?>" id="lostReasonField">
          <label class="form-label" for="fLost">Lost reason</label>
          <input type="text" name="lost_reason" id="fLost" class="form-control" value="<?= sanitize($v('lost_reason')) ?>" placeholder="Why didn't this lead convert?">
        </div>
        <?php endif; ?>
        <div>
          <label class="form-label" for="fAssigned">Owner</label>
          <select name="assigned_to" id="fAssigned" class="form-select">
            <option value="">Unassigned</option>
            <?php foreach ($allStaff as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= (int)$v('assigned_to', 0) === (int)$s['id'] ? 'selected' : '' ?>><?= sanitize($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-actions">
        <a href="<?= sanitize($cancelHref) ?>" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-gold"><i class="bi bi-check-lg"></i><?= $isEdit ? 'Save changes' : 'Create lead' ?></button>
      </div>
    </div>
  </div>

</div>
</form>

<?php
$extraJs = ($extraJs ?? '') . "
const phoneTpl = () => `<div class=\"phone-row mb-2\"><div class=\"d-flex gap-2\">
  <input type=\"tel\" name=\"phones[]\" class=\"form-control phone-input tabular\" placeholder=\"+971 50 123 4567\">
  <button type=\"button\" class=\"btn-icon danger flex-shrink-0 mt-1\" onclick=\"removePhone(this)\" title=\"Remove number\" aria-label=\"Remove number\"><i class=\"bi bi-x-lg\"></i></button>
  </div><div class=\"dup-warning form-text text-red d-none\"></div></div>`;
function addPhone() {
  const c = document.getElementById('phonesContainer');
  c.insertAdjacentHTML('beforeend', phoneTpl());
  c.lastElementChild.querySelector('input').focus();
}
function removePhone(btn) { btn.closest('.phone-row').remove(); }

// Live duplicate check
const leadId = " . (int)($f['id'] ?? 0) . ";
let dupTimer;
document.getElementById('phonesContainer').addEventListener('input', e => {
  if (!e.target.classList.contains('phone-input')) return;
  const input = e.target, warn = input.closest('.phone-row').querySelector('.dup-warning');
  clearTimeout(dupTimer);
  if (input.value.replace(/\\D/g, '').length < 7) { warn.classList.add('d-none'); return; }
  dupTimer = setTimeout(async () => {
    try {
      const d = await (await fetch('/pages/api.php?action=check_duplicate&phone=' + encodeURIComponent(input.value))).json();
      if (d.duplicate && d.id != leadId) {
        warn.textContent = '';
        warn.append('Possible duplicate: ');
        const a = document.createElement('a'); a.href = '/pages/lead_view.php?id=' + d.id; a.target = '_blank'; a.textContent = d.name;
        warn.append(a, ' (' + d.stage + ') already has this number.');
        warn.classList.remove('d-none');
      } else warn.classList.add('d-none');
    } catch (err) {}
  }, 450);
});

function toggleSourceFields() {
  const src = document.getElementById('sourceSelect').value;
  document.getElementById('campaignField').classList.toggle('d-none', src === 'referral' || src === 'walkin');
  document.getElementById('referrerField').classList.toggle('d-none', src !== 'referral' && !document.getElementById('fReferrer').value);
}
function toggleLostReason() {
  const f = document.getElementById('lostReasonField');
  if (f) f.classList.toggle('d-none', document.getElementById('stageSelect').value !== 'lost');
  const c = document.getElementById('cancelApptsField');
  if (c) c.classList.toggle('d-none', !['lost', 'cold'].includes(document.getElementById('stageSelect').value));
}
toggleSourceFields();

// Treatment picker: filter + count
const picker = document.getElementById('treatPicker');
function updateTreatCount() {
  const n = picker.querySelectorAll('input:checked').length;
  document.getElementById('treatCount').textContent = n ? '· ' + n + ' selected' : '';
}
picker.addEventListener('change', updateTreatCount); updateTreatCount();
document.getElementById('treatFilter').addEventListener('input', e => {
  const q = e.target.value.trim().toLowerCase();
  picker.querySelectorAll('.pill-check').forEach(p => p.classList.toggle('d-none', q && !p.dataset.name.includes(q)));
  picker.querySelectorAll('.treat-group').forEach(g => g.classList.toggle('d-none', !g.querySelector('.pill-check:not(.d-none)')));
});
";
