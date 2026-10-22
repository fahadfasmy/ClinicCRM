<?php
// pages/overview.php — Insights → Clinic overview: the owner's dashboard for a period and branch. Invoiced against
// collected, collections by payment method, revenue by department / practitioner / treatment / insurance, advances and
// refunds, appointments (status, type, department, no-shows, waiting time), new against returning patients, the lead
// funnel by source, insurance claims not sent, cards expiring and stock alerts. Export and print like every report.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/../includes/report_ui.php';
require_once __DIR__ . '/../includes/crm.php';
require_once __DIR__ . '/../includes/insurance.php';
requirePermission('reports.finance');

$db = getDB();
$f = rptFilters();
[$from, $to, $br] = [$f['from'], $f['to'], $f['branch']];
$m = rptMoneySummary($db, $from, $to, $br);
$ap = rptAppointments($db, $from, $to, $br);
$nr = rptNewReturning($db, $from, $to, $br);
$adv = rptAdvancesRefunds($db, $from, $to, $br);
$aging = claimAgeing($db);
$stock = can('inventory.view') ? inventoryAlertCounts($db) : null;
$cards = rptCardsExpiring($db, 30);

$money = ['name' => ['Name', 'text'], 'invoices' => ['Invoices', 'int'], 'qty' => ['Qty', 'int'], 'revenue' => ['Revenue (AED, before VAT)', 'money']];
$count = fn(string $l) => ['name' => [$l, 'text'], 'n' => ['Appointments', 'int']];
$funnel = array_map(fn($r) => $r + ['label' => $r['name']], funnelReport($db, $from, $to, 'source'));
$sections = [
    rptSection('Invoiced and collected', ['item' => ['', 'text'], 'amount' => ['AED', 'money']], [
        ['item' => "Invoiced ({$m['invoices']} invoices, with VAT)", 'amount' => $m['invoiced']], ['item' => 'Revenue before VAT', 'amount' => $m['revenue']],
        ['item' => "Credit notes ({$m['credit_notes']})", 'amount' => -$m['credited']], ['item' => 'Collected from patients (money)', 'amount' => $m['collected']],
        ['item' => 'Paid by insurance companies', 'amount' => $m['insurance']], ['item' => 'Paid from wallet, vouchers, points', 'amount' => $m['wallet_voucher']]]),
    rptSection('Collections by payment method', ['label' => ['Method', 'text'], 'receipts' => ['Receipts', 'int'], 'amount' => ['AED', 'money']],
        rptCollectionsByMethod($db, $from, $to, $br), ['total' => true, 'no_total' => ['receipts'], 'chart' => ['type' => 'doughnut', 'label' => 'label', 'values' => 'amount'], 'half' => true]),
    rptSection('Advances and refunds', ['item' => ['', 'text'], 'amount' => ['AED', 'money']], [
        ['item' => 'Wallet deposits (paid in advance)', 'amount' => $adv['deposits']], ['item' => 'Gift vouchers sold', 'amount' => $adv['vouchers_sold']],
        ['item' => 'Refunded on credit notes', 'amount' => $adv['refunds_credit_notes']], ['item' => 'Refunded from wallets', 'amount' => $adv['refunds_wallet']]], ['half' => true]),
];
foreach (['department' => 'Revenue by department', 'practitioner' => 'Revenue by practitioner', 'service' => 'Revenue by treatment', 'insurance' => 'Revenue by insurance company'] as $by => $t)
    $sections[] = rptSection($t, $money, rptRevenueBy($db, $by, $from, $to, $br), ['total' => true, 'half' => true, 'chart' => $by === 'service' ? null : ['type' => 'bar', 'label' => 'name', 'values' => 'revenue']]);
$sections[] = rptSection('Appointments by status', $count('Status'), array_map(fn($r) => ['name' => ucwords(str_replace('_', ' ', $r['name']))] + $r, $ap['status']), ['total' => true, 'half' => true]);
$sections[] = rptSection('Appointments by type', $count('Visit type'), array_map(fn($r) => ['name' => ucwords(str_replace('_', ' ', (string)$r['name']))] + $r, $ap['visit_type']), ['total' => true, 'half' => true]);
$sections[] = rptSection('Appointments by department', $count('Department'), $ap['department'], ['total' => true, 'half' => true]);
$sections[] = rptSection('Lead funnel and revenue by source', ['label' => ['Source', 'text'], 'leads' => ['Leads', 'int'], 'booked' => ['Booked', 'int'], 'showed' => ['Came', 'int'],
    'patients' => ['Patients', 'int'], 'revenue' => ['Revenue', 'money']], $funnel, ['total' => true, 'note' => 'Leads created in the period and what became of them (Marketing → Campaigns has the detail).']);
$sections[] = rptSection('Insurance claims not sent', ['age' => ['Invoice older than', 'text'], 'n' => ['Claims', 'int'], 'amount' => ['Claimed (AED)', 'money']],
    array_map(fn($d) => ['age' => "$d days", 'n' => $aging[$d][0], 'amount' => aed($aging[$d][1])], [7, 30, 45]), ['half' => true]);
$sections[] = rptSection('Insurance cards expiring in 30 days', ['patient' => ['Patient', 'text'], 'mrn' => ['MRN', 'text'], 'company' => ['Company', 'text'], 'valid_to' => ['Valid to', 'date']],
    $cards, ['half' => true, 'empty' => 'None.', 'links' => ['patient' => ['/pages/patient.php?id=', 'patient_id']]]);

$tiles = [
    ['Invoiced', money($m['invoiced']), 'Revenue before VAT ' . number_format($m['revenue'], 2)],
    ['Collected', money($m['collected']), 'plus insurance ' . number_format($m['insurance'], 2)],
    ['Patients seen', number_format($nr['seen']), "{$nr['new']} new · {$nr['returning']} returning"],
    ['No-show rate', $ap['no_show_rate'] === null ? '–' : $ap['no_show_rate'] . '%', 'Average wait ' . ($ap['avg_wait_min'] === null ? '–' : $ap['avg_wait_min'] . ' min')],
];
if ($stock) $tiles[] = ['Stock alerts', number_format($stock['short'] + $stock['low']), "{$stock['expiring']} expiring soon · {$stock['expired_lots']} expired lots"];
$tiles[] = ['Claims not sent', number_format($aging['all'][0]), 'over 7 days: ' . $aging[7][0]];
$tiles[] = ['Cards expiring', number_format(count($cards)), 'next 30 days'];
rptOutput('Clinic overview', $f, $sections, ['tiles' => $tiles]);
