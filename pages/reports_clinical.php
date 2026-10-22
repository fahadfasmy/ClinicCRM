<?php
// pages/reports_clinical.php — Insights → Clinical reports (reports.clinical): diagnoses, treatments done, prescriptions,
// notes not signed, consent forms missing, and patients not seen for 30 / 90 / 180 / 360 days. Admins see everyone (and
// can pick a practitioner); anyone else given this report sees only their own patients and figures.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/../includes/report_ui.php';
requirePermission('reports.clinical');

$db = getDB();
$tabs = ['diagnoses' => 'Diagnoses', 'procedures' => 'Treatments done', 'prescriptions' => 'Prescriptions', 'unsigned' => 'Unsigned notes', 'consents' => 'Consents missing', 'not_visited' => 'Not seen lately'];
$r = isset($tabs[$_GET['r'] ?? '']) ? $_GET['r'] : 'diagnoses';
$_GET['r'] = $r;
$f = rptFilters();
[$from, $to, $br] = [$f['from'], $f['to'], $f['branch']];
$pracs = $db->query("SELECT s.id, s.name FROM staff s JOIN practitioner_profiles pp ON pp.staff_id = s.id WHERE s.is_active = 1 ORDER BY s.name")->fetchAll(PDO::FETCH_KEY_PAIR);
$prac = isAdmin() ? (int)($_GET['prac'] ?? 0) : currentStaffId();   // own figures only unless admin
$opt = ['tabs' => $tabs];
if (isAdmin()) $opt['extra_filters'] = '<div><label class="form-label" for="rp">Practitioner</label><select class="form-select" id="rp" name="prac"><option value="">Everyone</option>'
    . implode('', array_map(fn($id, $n) => '<option value="' . (int)$id . '"' . ($prac === (int)$id ? ' selected' : '') . '>' . sanitize($n) . '</option>', array_keys($pracs), $pracs)) . '</select></div>';
else $opt['subtitle'] = 'Your patients only · ' . date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to));
$pt = ['/pages/patient.php?id=', 'id'];
$sections = [];

switch ($r) {
    case 'diagnoses':
        $sections[] = rptSection('Diagnoses', ['code' => ['ICD-10', 'text'], 'description' => ['Diagnosis', 'text'], 'encounters' => ['Visits', 'int'], 'patients' => ['Patients', 'int']],
            rptDiagnoses($db, $from, $to, $br, $prac), ['chart' => ['type' => 'bar', 'label' => 'code', 'values' => 'patients'], 'note' => 'From clinical notes started in the period, provisional and final.']);
        break;
    case 'procedures':
        $sections[] = rptSection('Treatments done', ['name' => ['Treatment', 'text'], 'cpt_code' => ['CPT', 'text'], 'category' => ['Category', 'text'], 'visits' => ['Visits', 'int'], 'qty' => ['Qty', 'int'], 'patients' => ['Patients', 'int']],
            rptProcedures($db, $from, $to, $br, $prac), ['total' => true, 'no_total' => ['patients'], 'chart' => ['type' => 'bar', 'label' => 'name', 'values' => 'qty']]);
        break;
    case 'prescriptions':
        $sections[] = rptSection('Prescriptions', ['drug' => ['Medicine', 'text'], 'strength' => ['Strength', 'text'], 'items' => ['Times prescribed', 'int'], 'patients' => ['Patients', 'int']],
            rptPrescriptions($db, $from, $to, $br, $prac), ['total' => true, 'no_total' => ['patients']]);
        break;
    case 'unsigned':
        $sections[] = rptSection('Clinical notes not signed', ['created_at' => ['Started', 'datetime'], 'patient' => ['Patient', 'text'], 'mrn' => ['MRN', 'text'], 'practitioner' => ['Practitioner', 'text'], 'hours' => ['Hours open', 'int']],
            rptUnsignedNotes($db, $br, $prac), ['links' => ['patient' => ['/pages/encounter.php?id=', 'id']], 'empty' => 'Every note is signed.']);
        $opt['no_period'] = true;
        break;
    case 'consents':
        $sections[] = rptSection('Treatments done without a signed consent', ['scheduled_at' => ['Visit', 'datetime'], 'patient' => ['Patient', 'text'], 'mrn' => ['MRN', 'text'], 'treatment' => ['Treatment', 'text'],
            'consent' => ['Consent form needed', 'text'], 'practitioner' => ['Practitioner', 'text']], rptConsentsMissing($db, $from, $to, $br, $prac),
            ['links' => ['patient' => ['/pages/appointment.php?id=', 'appointment_id']], 'empty' => 'None: every treatment that needs a consent form has one signed.', 'note' => 'Completed visits for treatments set to need a consent form, where the patient has no signed (and not withdrawn) copy.']);
        break;
    case 'not_visited':
        $days = in_array((int)($_GET['days'] ?? 90), [30, 90, 180, 360], true) ? (int)($_GET['days'] ?? 90) : 90;
        $sections[] = rptSection('Patients by time since their last visit', ['bucket' => ['Last visit', 'text'], 'patients' => ['Patients', 'int']], rptNotVisited($db, $br, $prac), ['total' => true, 'half' => true,
            'chart' => ['type' => 'bar', 'label' => 'bucket', 'values' => 'patients']]);
        $list = array_map(fn($x) => $x + ['consent' => $x['marketing_consent'] ? 'Yes' : 'No'], rptNotVisitedList($db, $days, $br, $prac));
        $sections[] = rptSection("Not seen for more than $days days", ['patient' => ['Patient', 'text'], 'mrn' => ['MRN', 'text'], 'last_visit' => ['Last visit', 'date'], 'days' => ['Days', 'int'], 'consent' => ['Marketing OK', 'text']],
            $list, ['links' => ['patient' => $pt], 'note' => 'Up to 1,000, most recent first. Use Marketing → Recalls or Broadcasts to bring them back.']);
        $opt['extra_filters'] = ($opt['extra_filters'] ?? '') . '<div><label class="form-label" for="rd">Not seen for</label><select class="form-select" id="rd" name="days">'
            . implode('', array_map(fn($d) => "<option value=\"$d\"" . ($d === $days ? ' selected' : '') . ">$d+ days</option>", [30, 90, 180, 360])) . '</select></div>';
        $opt['no_period'] = true;
        break;
}
rptOutput('Clinical reports · ' . $tabs[$r], $f, $sections, $opt);
