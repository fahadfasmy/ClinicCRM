<?php
// pages/audit.php — Admin → Audit log (audit.view), read-only: changes (audit_log: patients, notes, invoices, receipts,
// credit notes, insurance, settings, permissions…), who opened which chart (patient_access_log) and lead activity
// (activity_log). Filters, CSV and print come from the report layout. Nothing here can change or delete an entry.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/billing.php';
require_once __DIR__ . '/../includes/report_ui.php';
require_once __DIR__ . '/../includes/compliance.php';
requirePermission('audit.view');

$db = getDB();
$tabs = ['changes' => 'Changes', 'access' => 'Chart access', 'leads' => 'Lead activity'];
$r = isset($tabs[$_GET['r'] ?? '']) ? $_GET['r'] : 'changes';
$_GET['r'] = $r;
$f = rptFilters();
if (!isset($_GET['from'])) $f['from'] = date('Y-m-d', strtotime('-30 days'));
$q = ['from' => $f['from'], 'to' => $f['to'], 'staff' => (int)($_GET['staff'] ?? 0)];
$staff = $db->query("SELECT id, name FROM staff ORDER BY is_active DESC, name")->fetchAll(PDO::FETCH_KEY_PAIR);
$sel = fn(string $name, string $label, array $opts, $cur) => '<div><label class="form-label" for="a' . $name . '">' . $label . '</label><select class="form-select" id="a' . $name . '" name="' . $name . '"><option value="">All</option>'
    . implode('', array_map(fn($k, $v) => '<option value="' . sanitize((string)$k) . '"' . ((string)$k === (string)$cur ? ' selected' : '') . '>' . sanitize((string)$v) . '</option>', array_keys($opts), $opts)) . '</select></div>';
$txt = fn(string $name, string $label, string $ph = '') => '<div><label class="form-label" for="a' . $name . '">' . $label . '</label><input class="form-control" id="a' . $name . '" name="' . $name . '" value="' . sanitize((string)($_GET[$name] ?? '')) . '" placeholder="' . $ph . '" style="max-width:160px"></div>';
$filters = $sel('staff', 'Staff', $staff, $q['staff']);
$limit = 500;
$sections = [];

switch ($r) {
    case 'changes':
        $q += ['entity' => (string)($_GET['entity'] ?? ''), 'entity_id' => (int)($_GET['entity_id'] ?? 0), 'action' => trim((string)($_GET['action'] ?? ''))];
        $entities = $db->query("SELECT DISTINCT entity FROM audit_log ORDER BY entity")->fetchAll(PDO::FETCH_COLUMN);
        $filters .= $sel('entity', 'What', array_combine($entities, array_map(fn($e) => ucwords(str_replace('_', ' ', $e)), $entities)), $q['entity']) . $txt('entity_id', 'Record no.') . $txt('action', 'Action', 'e.g. voided');
        $csv = ($_GET['export'] ?? '') === 'csv';
        $rows = array_map(function ($a) use ($csv) {
            $c = (string)$a['changes'];
            $a['what'] = ucwords(str_replace('_', ' ', $a['entity'])) . ' #' . $a['entity_id'];
            $a['detail'] = $csv || mb_strlen($c) <= 220 ? $c : mb_substr($c, 0, 220) . '…';
            return $a;
        }, auditSearch($db, $q, $limit));
        $sections[] = rptSection('Changes', ['at' => ['When', 'datetime'], 'staff' => ['Who', 'text'], 'what' => ['Record', 'text'], 'action' => ['Action', 'text'], 'detail' => ['Details', 'text'], 'ip' => ['IP', 'text']],
            $rows, ['empty' => 'No changes match.', 'note' => count($rows) === $limit ? "The newest $limit; narrow the filters to see older ones. Export has the full details." : 'Export has the full details.']);
        break;
    case 'access':
        $q += ['patient' => trim((string)($_GET['patient'] ?? '')), 'what' => (string)($_GET['what'] ?? '')];
        $whats = $db->query("SELECT DISTINCT what FROM patient_access_log ORDER BY what")->fetchAll(PDO::FETCH_COLUMN);
        $filters .= $txt('patient', 'Patient (MRN or no.)') . $sel('what', 'Opened', array_combine($whats, $whats), $q['what']);
        $rows = accessSearch($db, $q, $limit);
        $sections[] = rptSection('Who opened which chart', ['at' => ['When', 'datetime'], 'staff' => ['Who', 'text'], 'patient' => ['Patient', 'text'], 'mrn' => ['MRN', 'text'], 'what' => ['Opened', 'text'],
            'encounter_id' => ['Note no.', 'int'], 'ip' => ['IP', 'text']], $rows, ['links' => ['patient' => ['/pages/patient.php?id=', 'patient_id']], 'empty' => 'No chart opened matches.',
            'note' => count($rows) === $limit ? "The newest $limit; narrow the filters to see older ones." : null]);
        break;
    case 'leads':
        [$w, $a] = auditFilters($q, 'l.created_at', 'l.staff_id');
        if ((int)($_GET['lead'] ?? 0)) { $w[] = 'l.lead_id = ?'; $a[] = (int)$_GET['lead']; }
        $filters .= $txt('lead', 'Lead no.');
        $s = $db->prepare("SELECT l.*, s.name AS staff, ld.name AS lead FROM activity_log l LEFT JOIN staff s ON s.id = l.staff_id LEFT JOIN leads ld ON ld.id = l.lead_id"
                          . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY l.id DESC LIMIT $limit");
        $s->execute($a);
        $sections[] = rptSection('Lead activity', ['created_at' => ['When', 'datetime'], 'staff' => ['Who', 'text'], 'lead' => ['Lead', 'text'], 'action' => ['Action', 'text'], 'old_value' => ['From', 'text'],
            'new_value' => ['To', 'text'], 'ip_address' => ['IP', 'text']], $s->fetchAll(), ['links' => ['lead' => ['/pages/lead_view.php?id=', 'lead_id']], 'empty' => 'No lead activity matches.']);
        break;
}
rptOutput('Audit log · ' . $tabs[$r], $f, $sections, ['tabs' => $tabs, 'extra_filters' => $filters, 'subtitle' => 'Read-only. ' . date('d M Y', strtotime($f['from'])) . ' – ' . date('d M Y', strtotime($f['to']))]);
