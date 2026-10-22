<?php
// pages/encounter.php?id= — one visit's clinical note (EMR). Tabs: Note (vitals, allergies, history, the doctor's note,
// diagnoses, procedures), Aesthetic (injection map, laser settings, skin type), Dental (FDI chart), Prescriptions, Sign.
// POST action=open with appointment_id or patient_id starts the note (one per visit). Every opening is logged; signed
// notes are read-only and corrections are addenda. Tab bodies: includes/emr_tabs/*.php.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/emr.php';
require_once __DIR__ . '/../includes/consents.php';
requirePermission('emr.view');

$db = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'open') {
    requireCsrf();
    requirePermission('emr.write');
    try {
        $id = openEncounter($db, (int)($_POST['patient_id'] ?? 0), (int)($_POST['appointment_id'] ?? 0) ?: null);
        header("Location: /pages/encounter.php?id=$id"); exit;
    } catch (Throwable $e) {
        $_SESSION['patient_flash'] = ['danger', $e->getMessage()];
        header('Location: ' . (!empty($_POST['patient_id']) ? '/pages/patient.php?id=' . (int)$_POST['patient_id'] . '&tab=clinical' : '/pages/appointments.php')); exit;
    }
}

$id = (int)($_GET['id'] ?? 0);
$e = findEncounter($db, $id);
if (!$e || !canSeeEncounter($e)) { http_response_code(404); $pageTitle = 'Note not found'; require __DIR__ . '/../includes/header.php';
    echo '<div class="empty-state"><i class="bi bi-clipboard2-x"></i><div class="empty-state-title">' . t('Clinical note not found') . '</div><p>' . t('It doesn’t exist, or it isn’t one of your patients.') . '</p></div>';
    require __DIR__ . '/../includes/footer.php'; exit; }
$p = findPatient($db, (int)$e['patient_id']);
$tabs = ['note' => ['Note', 'bi-journal-medical'], 'aesthetic' => ['Aesthetic', 'bi-person-bounding-box'], 'dental' => ['Dental', 'bi-grid-3x3'], 'rx' => ['Prescriptions', 'bi-capsule'], 'sign' => ['Sign', 'bi-pen']];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'note';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $a = $_POST['action'] ?? '';
    $pid = (int)$e['patient_id'];
    try {
        switch ($a) {
            case 'vitals': saveVitals($db, $e, $_POST); break;
            case 'note':
                $f = array_intersect_key($_POST, EMR_NOTE_FIELDS);
                if (isset($_POST['allergies_reviewed_sent'])) $f['allergies_reviewed'] = !empty($_POST['allergies_reviewed']);
                if (isset($_POST['followup_date'])) $f['data'] = ['followup_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['followup_date']) ? $_POST['followup_date'] : null];
                saveNote($db, $e, $f); break;
            case 'aesthetic':
                $laser = [];
                foreach (LASER_FIELDS as $k => $_) $laser[$k] = mb_substr(trim((string)($_POST['laser'][$k] ?? '')), 0, 60);
                saveNote($db, $e, ['data' => ['injections' => cleanInjections((array)($_POST['inj'] ?? [])), 'laser' => array_filter($laser, fn($v) => $v !== ''),
                    'fitzpatrick' => isset(FITZPATRICK[$_POST['fitzpatrick'] ?? '']) ? $_POST['fitzpatrick'] : null, 'post_care' => mb_substr(trim((string)($_POST['post_care'] ?? '')), 0, 2000)]]);
                break;
            case 'template': $n = applyTemplate($db, $e, (int)($_POST['template_id'] ?? 0)); $_SESSION['emr_flash'] = ['success', $n ? "Template applied to $n empty section(s)." : 'Nothing to fill: those sections already have text.']; break;
            case 'allergy_add': addAllergy($db, $pid, $_POST); break;
            case 'allergy_status': setAllergyStatus($db, $pid, (int)$_POST['allergy_id'], (string)$_POST['status']); break;
            case 'history_add': addHistory($db, $pid, (string)($_POST['type'] ?? ''), (string)($_POST['detail'] ?? '')); break;
            case 'history_remove': removeHistory($db, $pid, (int)$_POST['history_id']); break;
            case 'dx_add': addDiagnosis($db, $e, (string)($_POST['code'] ?? ''), !empty($_POST['is_primary']), (string)($_POST['kind'] ?? 'final')); break;
            case 'dx_remove': removeEncounterLine($db, $e, 'encounter_diagnoses', (int)$_POST['line_id']); break;
            case 'proc_add': addProcedure($db, $e, $_POST); break;
            case 'proc_remove': removeEncounterLine($db, $e, 'encounter_procedures', (int)$_POST['line_id']); break;
            case 'rx_add': savePrescription($db, $e, (array)($_POST['items'] ?? []), (string)($_POST['rx_notes'] ?? '')); break;
            case 'rx_remove': removePrescription($db, $e, (int)$_POST['rx_id']); break;
            case 'dental_add': addDentalEntry($db, $e, $_POST); break;
            case 'dental_remove': removeDentalEntry($db, $e, (int)$_POST['entry_id']); break;
            case 'sign': signEncounter($db, $id); $_SESSION['emr_flash'] = ['success', 'Signed. The note is now read-only.']; break;
            case 'amend': addAmendment($db, $id, (string)($_POST['reason'] ?? ''), (string)($_POST['text'] ?? '')); $_SESSION['emr_flash'] = ['success', 'Addendum added.']; break;
            default: throw new RuntimeException('Unknown action.');
        }
        $_SESSION['emr_flash'] ??= ['success', 'Saved.'];
    } catch (Throwable $x) {
        $_SESSION['emr_flash'] = ['danger', $x->getMessage()];
    }
    header("Location: /pages/encounter.php?id=$id&tab=" . (isset($tabs[$_POST['tab'] ?? '']) ? $_POST['tab'] : $tab) . (!empty($_POST['anchor']) ? '#' . preg_replace('/[^a-z_]/', '', $_POST['anchor']) : '')); exit;
}
$flash = $_SESSION['emr_flash'] ?? null;
unset($_SESSION['emr_flash']);
logPatientAccess($db, (int)$e['patient_id'], 'encounter:' . $tab, $id);

$editable = encounterEditable($e) && can('emr.write');
$allergies = patientAllergies($db, (int)$e['patient_id']);
$age = $p['dob'] ? (new DateTimeImmutable($p['dob']))->diff(new DateTimeImmutable('today'))->y : null;
$statusChip = ['open' => ['Open', 'stage-contacted'], 'signed' => ['Signed', 'stage-converted'], 'amended' => ['Signed + addendum', 'stage-converted']][$e['status']];

$pageTitle = patientName($p);
$pageSubtitle = '<span class="stage-badge ' . $statusChip[1] . '">' . $statusChip[0] . '</span>' . ($e['locked_at'] && $e['status'] === 'open' ? ' <span class="chip tone-slate">' . t('Locked') . '</span>' : '')
    . ' · ' . sanitize($p['mrn']) . ($age !== null ? ' · ' . $age . ' yrs' : '') . ($p['gender'] !== 'unknown' ? ' · ' . PATIENT_GENDERS[$p['gender']] : '')
    . ' · ' . date('d M Y', strtotime($e['scheduled_at'] ?: $e['created_at'])) . ($e['practitioner_name'] ? ' · ' . sanitize($e['practitioner_name']) : '')
    . ((json_decode((string)$e['data'], true)['source'] ?? '') === 'clinicsoft' ? ' · <span class="chip tone-slate">' . t('From ClinicSoft (read-only)') . '</span>' . (($au = json_decode((string)$e['data'], true)['author'] ?? '') !== '' ? ' · ' . sanitize($au) : '') : '');
$pageBack = ['href' => '/pages/patient.php?id=' . (int)$p['id'] . '&tab=clinical', 'label' => 'Patient'];
$pageActions = '<a class="btn btn-ghost" href="/pages/encounter_print.php?id=' . $id . '&amp;doc=summary" target="_blank"><i class="bi bi-printer"></i>' . t('Visit summary') . '</a>'
    . (can('emr.photos') ? ' <a class="btn btn-ghost" href="/pages/patient.php?id=' . (int)$p['id'] . '&amp;tab=photos&amp;encounter=' . $id . '"><i class="bi bi-camera"></i>' . t('Photos') . '</a>' : '');
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?> <?= $flash[0] === 'success' ? 'alert-auto' : '' ?>"><i class="bi bi-<?= $flash[0] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<?php if ($allergies): ?>
<div class="alert alert-danger py-2" role="alert"><i class="bi bi-exclamation-octagon"></i><div><strong><?= t('Allergies:') ?></strong>
  <?= implode(' · ', array_map(fn($al) => sanitize($al['allergen']) . ($al['reaction'] ? ' (' . sanitize($al['reaction']) . ')' : '') . ($al['severity'] === 'severe' ? ' <strong>SEVERE</strong>' : ''), $allergies)) ?></div></div>
<?php elseif ($e['allergies_reviewed']): ?><div class="alert alert-success py-2"><i class="bi bi-shield-check"></i><?= t('No known allergies (checked with the patient).') ?></div><?php endif; ?>
<?php foreach (missingConsents($db, (int)$e['patient_id'], visitServiceIds($db, $e['appointment_id'] ? (int)$e['appointment_id'] : null, $id)) as $tplId => [$cName, $cFor]): ?>
<div class="alert alert-warning py-2 d-flex flex-wrap align-items-center gap-2" role="alert"><i class="bi bi-pen"></i><div class="flex-grow-1"><strong><?= t('Consent not signed:') ?></strong> <?= sanitize($cName) ?> <span class="fs-12">(required for <?= sanitize($cFor) ?>)</span></div>
  <?php if (can('consents.collect')): ?><form method="POST" action="/pages/consent_sign.php"><?= csrfField() ?><input type="hidden" name="action" value="start"><input type="hidden" name="patient_id" value="<?= (int)$e['patient_id'] ?>"><input type="hidden" name="encounter_id" value="<?= $id ?>"><input type="hidden" name="appointment_id" value="<?= (int)$e['appointment_id'] ?>"><input type="hidden" name="template_id" value="<?= $tplId ?>">
    <button class="btn btn-ghost btn-sm"><i class="bi bi-tablet"></i><?= t('Hand to patient') ?></button></form><?php endif; ?></div>
<?php endforeach; ?>

<ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto" role="tablist">
  <?php foreach ($tabs as $k => [$label, $icon]): ?>
  <li class="nav-item"><a class="nav-link text-nowrap <?= $tab === $k ? 'active' : '' ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?> href="?id=<?= $id ?>&amp;tab=<?= $k ?>"><i class="bi <?= $icon ?>"></i> <?= $label ?></a></li>
  <?php endforeach; ?>
</ul>
<?php
$hidden = fn(string $action, string $anchor = '') => csrfField() . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="tab" value="' . $tab . '">' . ($anchor ? '<input type="hidden" name="anchor" value="' . $anchor . '">' : '');
require __DIR__ . '/../includes/emr_tabs/' . $tab . '.php';
require_once __DIR__ . '/../includes/footer.php';
