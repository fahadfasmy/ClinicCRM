<?php
// pages/reports_dha.php — Insights → DHA statistics (reports.dha): the counts DHA asks for, for a period and branch.
// Out-patient visits (completed appointments) new and follow-up by nationality, sex and age group; patients by
// nationality and diagnosis; waiting time to see a physician; treatments in a department (dental, skin care…) by
// treatment and nationality; UAE nationals, residents and tourists. A "new" visit is the patient's first completed visit.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/../includes/report_ui.php';
requirePermission('reports.dha');

$db = getDB();
$tabs = ['visits' => 'Out-patient visits', 'diagnosis' => 'Nationality & diagnosis', 'waiting' => 'Waiting time', 'department' => 'Department treatments', 'residency' => 'Nationals, residents, tourists'];
$r = isset($tabs[$_GET['r'] ?? '']) ? $_GET['r'] : 'visits';
$_GET['r'] = $r;
$f = rptFilters();
[$from, $to, $br] = [$f['from'], $f['to'], $f['branch']];
$opt = ['tabs' => $tabs];
$nf = ['name' => ['', 'text'], 'new' => ['New', 'int'], 'follow_up' => ['Follow-up', 'int'], 'total' => ['Total', 'int']];
$sections = [];

switch ($r) {
    case 'visits':
        $v = rptDhaVisits($db, $from, $to, $br);
        $sections[] = rptSection('By nationality', ['name' => ['Nationality', 'text']] + $nf, $v['nationality'], ['total' => true, 'chart' => ['type' => 'bar', 'label' => 'name', 'values' => ['new', 'follow_up']]]);
        $sections[] = rptSection('By sex', ['name' => ['Sex', 'text']] + $nf, $v['sex'], ['total' => true, 'half' => true]);
        $sections[] = rptSection('By age group', ['name' => ['Age at visit', 'text']] + $nf, $v['age'], ['total' => true, 'half' => true]);
        $opt['tiles'] = [['Out-patient visits', number_format($v['total']['new'] + $v['total']['follow_up'])], ['New', number_format($v['total']['new'])], ['Follow-up', number_format($v['total']['follow_up'])]];
        break;
    case 'diagnosis':
        $sections[] = rptSection('Patients by nationality and primary diagnosis', ['nationality' => ['Nationality', 'text'], 'code' => ['ICD-10', 'text'], 'description' => ['Diagnosis', 'text'], 'patients' => ['Patients', 'int']],
            rptDhaNationalityDiagnosis($db, $from, $to, $br), ['note' => 'Clinical notes started in the period, by the visit’s primary diagnosis.']);
        break;
    case 'waiting':
        $w = rptDhaWaiting($db, $from, $to, $br);
        $n = array_sum(array_column($w, 'visits'));
        $avg = $n ? round(array_sum(array_map(fn($x) => $x['avg_min'] * $x['visits'], $w)) / $n, 1) : null;
        $pct = fn($k) => $n ? round(array_sum(array_column($w, $k)) / $n * 100) . '%' : '–';
        $sections[] = rptSection('Waiting time to see a physician', ['practitioner' => ['Practitioner', 'text'], 'visits' => ['Visits', 'int'], 'avg_min' => ['Average', 'min'], 'max_min' => ['Longest', 'min'],
            'within_15' => ['Seen within 15 min', 'int'], 'within_30' => ['Within 30 min', 'int'], 'within_60' => ['Within 60 min', 'int']], $w,
            ['chart' => ['type' => 'bar', 'label' => 'practitioner', 'values' => 'avg_min'], 'note' => 'From arrival (checked in) to the doctor starting the visit, for visits where both times were recorded.']);
        $opt['tiles'] = [['Average wait', $avg === null ? '–' : "$avg min"], ['Seen within 15 min', $pct('within_15')], ['Within 30 min', $pct('within_30')], ['Visits timed', number_format($n)]];
        break;
    case 'department':
        $depts = $db->query("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
        $dept = (int)($_GET['dept'] ?? 0);
        if (!isset($depts[$dept])) $dept = (int)(array_key_first(array_filter($depts, fn($n) => stripos($n, 'dent') !== false)) ?? array_key_first($depts) ?? 0);
        $opt['extra_filters'] = '<div><label class="form-label" for="rdp">Department</label><select class="form-select" id="rdp" name="dept">'
            . implode('', array_map(fn($id, $nm) => '<option value="' . (int)$id . '"' . ($id === $dept ? ' selected' : '') . '>' . sanitize($nm) . '</option>', array_keys($depts), $depts)) . '</select></div>';
        if ($dept) {
            $d = rptDhaDepartment($db, $dept, $from, $to, $br);
            $sections[] = rptSection($depts[$dept] . ': treatments', ['name' => ['Treatment', 'text'], 'treatments' => ['Treatments', 'int'], 'patients' => ['Patients', 'int']], $d['treatments'], ['total' => true, 'no_total' => ['patients'], 'half' => true]);
            $sections[] = rptSection($depts[$dept] . ': by nationality', ['nationality' => ['Nationality', 'text'], 'treatments' => ['Treatments', 'int'], 'patients' => ['Patients', 'int']], $d['nationality'], ['total' => true, 'half' => true]);
            $opt['tiles'] = [[$depts[$dept] . ' patients', number_format($d['patients'])], ['Treatments', number_format(array_sum(array_column($d['treatments'], 'treatments')))]];
        } else $opt['before'] = '<div class="alert alert-info">Add departments in Setup, and give practitioners their department, to see this report.</div>';
        $opt['subtitle'] = 'Completed visits with a practitioner of the department · ' . date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to));
        break;
    case 'residency':
        $sections[] = rptSection('UAE nationals, residents and tourists', ['name' => ['', 'text'], 'patients' => ['Patients', 'int'], 'visits' => ['Visits', 'int']], rptDhaResidency($db, $from, $to, $br),
            ['total' => true, 'chart' => ['type' => 'doughnut', 'label' => 'name', 'values' => 'patients'], 'note' => 'From the residency on each patient’s record; UAE nationality counts as a national. Record it at registration to keep “Not recorded” small.']);
        break;
}
rptOutput('DHA statistics · ' . $tabs[$r], $f, $sections, $opt);
