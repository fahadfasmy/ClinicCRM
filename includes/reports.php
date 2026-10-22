<?php
// includes/reports.php — the numbers behind the owner dashboard and the finance, clinical and DHA reports.
// Every function takes a period [$from, $to] (both days included, Y-m-d) and an optional branch (0 = all) and returns
// plain rows, so pages can show them as a table, a chart, CSV or a print view (includes/report_ui.php).
// Money: revenue is the taxable amount (after discount, before VAT) of issued invoices, by issue date; collections are
// receipt payments by receipt date. Voided invoices and receipts are left out.
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/countries.php';

const RPT_MONEY_METHODS = ['cash', 'card', 'bank_transfer', 'cheque', 'tabby', 'tamara', 'stripe'];
const RPT_METHOD_LABELS = ['cash' => 'Cash', 'card' => 'Card', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'tabby' => 'Tabby', 'tamara' => 'Tamara',
    'stripe' => 'Online (Stripe)', 'wallet' => 'Patient wallet', 'voucher' => 'Gift voucher', 'loyalty' => 'Loyalty points', 'insurance' => 'Insurance remittance'];
const RPT_AGE_GROUPS = [[0, 0, 'Under 1'], [1, 4, '1-4'], [5, 14, '5-14'], [15, 24, '15-24'], [25, 44, '25-44'], [45, 64, '45-64'], [65, 200, '65+']];

/** "AND x.branch_id = n" or nothing. */
function rptBranch(string $col, int $branch): string { return $branch ? " AND $col = $branch" : ''; }

/** The SQL condition for a datetime column inside the period, with its two parameters. */
function rptIn(string $col): string { return "$col >= ? AND $col < ? + INTERVAL 1 DAY"; }

function rptAll(PDO $db, string $sql, array $args = []): array { $s = $db->prepare($sql); $s->execute($args); return $s->fetchAll(); }

// ── Owner dashboard ──

/** Invoiced, revenue, VAT, credit notes, collected (money), insurance remittances, wallet/voucher use, in the period. */
function rptMoneySummary(PDO $db, string $from, string $to, int $branch = 0): array {
    $inv = rptAll($db, "SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS invoiced, COALESCE(SUM(net), 0) AS revenue, COALESCE(SUM(vat), 0) AS vat
                        FROM invoices WHERE status NOT IN ('draft','void') AND imported IS NULL AND " . rptIn('issued_at') . rptBranch('branch_id', $branch), [$from, $to])[0];
    $cn = rptAll($db, "SELECT COUNT(*) AS n, COALESCE(SUM(gross), 0) AS net, COALESCE(SUM(total), 0) AS total FROM credit_notes WHERE " . rptIn('issued_at') . rptBranch('branch_id', $branch), [$from, $to])[0];
    $by = [];
    foreach (rptCollectionsByMethod($db, $from, $to, $branch) as $r) $by[$r['method']] = (float)$r['amount'];
    $money = array_sum(array_intersect_key($by, array_flip(RPT_MONEY_METHODS)));
    return ['invoices' => (int)$inv['n'], 'invoiced' => (float)$inv['invoiced'], 'revenue' => (float)$inv['revenue'], 'vat' => (float)$inv['vat'],
            'credit_notes' => (int)$cn['n'], 'credited' => (float)$cn['total'], 'net_revenue' => round((float)$inv['revenue'] - (float)$cn['net'], 2),
            'collected' => round($money, 2), 'insurance' => (float)($by['insurance'] ?? 0), 'wallet_voucher' => round(($by['wallet'] ?? 0) + ($by['voucher'] ?? 0) + ($by['loyalty'] ?? 0), 2)];
}

function rptCollectionsByMethod(PDO $db, string $from, string $to, int $branch = 0): array {
    $rows = rptAll($db, "SELECT m.method, COUNT(DISTINCT r.id) AS receipts, SUM(m.amount) AS amount FROM receipts r JOIN receipt_methods m ON m.receipt_id = r.id
                         WHERE r.status = 'valid' AND " . rptIn('r.received_at') . rptBranch('r.branch_id', $branch) . " GROUP BY m.method ORDER BY amount DESC", [$from, $to]);
    foreach ($rows as &$r) $r['label'] = RPT_METHOD_LABELS[$r['method']] ?? $r['method'];
    return $rows;
}

/** Revenue (taxable amount of issued invoice lines) by department, practitioner, service or insurance company. */
function rptRevenueBy(PDO $db, string $by, string $from, string $to, int $branch = 0): array {
    $key = match ($by) {
        'department' => "COALESCE(d.name, 'No department')",
        'practitioner' => "COALESCE(st.name, 'No practitioner')",
        'service' => "COALESCE(sv.name, l.description)",
        'insurance' => "CASE WHEN i.type = 'insurance' THEN COALESCE(ic.name, 'Insurance (company not set)') ELSE 'Self-pay' END",
        default => throw new InvalidArgumentException('Unknown grouping.'),
    };
    return rptAll($db, "SELECT $key AS name, COUNT(DISTINCT i.id) AS invoices, SUM(l.qty) AS qty, SUM(l.taxable) AS revenue, SUM(l.vat_amount) AS vat
        FROM invoice_lines l JOIN invoices i ON i.id = l.invoice_id
        LEFT JOIN services sv ON sv.id = l.service_id LEFT JOIN staff st ON st.id = l.practitioner_id
        LEFT JOIN encounters e ON e.id = i.encounter_id LEFT JOIN practitioner_profiles pp ON pp.staff_id = l.practitioner_id
        LEFT JOIN departments d ON d.id = COALESCE(e.department_id, pp.department_id)
        LEFT JOIN insurance_plans ip ON ip.id = i.insurance_plan_id LEFT JOIN insurance_companies ic ON ic.id = ip.company_id
        WHERE i.status NOT IN ('draft','void') AND i.imported IS NULL AND " . rptIn('i.issued_at') . rptBranch('i.branch_id', $branch) . "
        GROUP BY name ORDER BY revenue DESC", [$from, $to]);
}

/** Money taken in advance (wallet deposits, vouchers sold) and money given back (refunds), in the period. */
function rptAdvancesRefunds(PDO $db, string $from, string $to, int $branch = 0): array {
    $one = fn(string $sql) => (float)rptAll($db, $sql, [$from, $to])[0]['v'];
    return [
        'deposits' => $one("SELECT COALESCE(SUM(amount), 0) AS v FROM patient_wallet WHERE type = 'deposit' AND " . rptIn('at') . rptBranch('branch_id', $branch)),
        'vouchers_sold' => $one("SELECT COALESCE(SUM(total), 0) AS v FROM receipts WHERE status = 'valid' AND voucher_id IS NOT NULL AND " . rptIn('received_at') . rptBranch('branch_id', $branch)),
        'refunds_credit_notes' => $one("SELECT COALESCE(SUM(refunded), 0) AS v FROM credit_notes WHERE refund_method IN ('cash','card','bank_transfer') AND " . rptIn('issued_at') . rptBranch('branch_id', $branch)),
        'refunds_wallet' => -$one("SELECT COALESCE(SUM(amount), 0) AS v FROM patient_wallet WHERE type = 'refund' AND " . rptIn('at') . rptBranch('branch_id', $branch)),
    ];
}

/** Appointments in the period: by status, by visit type, by department; no-show rate and average wait (arrived → doctor). */
function rptAppointments(PDO $db, string $from, string $to, int $branch = 0): array {
    $w = rptIn('a.scheduled_at') . rptBranch('a.branch_id', $branch) . " AND a.status <> 'rescheduled'";
    $out = [];
    foreach (['status' => 'a.status', 'visit_type' => 'a.visit_type', 'department' => "COALESCE(d.name, 'No department')"] as $k => $col)
        $out[$k] = rptAll($db, "SELECT $col AS name, COUNT(*) AS n FROM appointments a LEFT JOIN practitioner_profiles pp ON pp.staff_id = a.practitioner_id
                                LEFT JOIN departments d ON d.id = pp.department_id WHERE $w GROUP BY name ORDER BY n DESC", [$from, $to]);
    $r = rptAll($db, "SELECT SUM(a.status = 'no_show') AS no_show, SUM(a.status NOT IN ('cancelled')) AS kept_or_missed,
                             AVG(CASE WHEN a.arrived_at IS NOT NULL AND a.doctor_in_at >= a.arrived_at THEN TIMESTAMPDIFF(MINUTE, a.arrived_at, a.doctor_in_at) END) AS wait
                      FROM appointments a WHERE $w AND a.scheduled_at < NOW()", [$from, $to])[0];
    $out['no_show_rate'] = $r['kept_or_missed'] ? round($r['no_show'] / $r['kept_or_missed'] * 100, 1) : null;
    $out['avg_wait_min'] = $r['wait'] !== null ? round((float)$r['wait'], 1) : null;
    return $out;
}

/** Patients seen (completed visits) in the period: new (first completed visit ever) or returning. */
function rptNewReturning(PDO $db, string $from, string $to, int $branch = 0): array {
    $r = rptAll($db, "SELECT COUNT(*) AS seen, SUM(f.first_visit >= ?) AS new_patients FROM (
                         SELECT a.patient_id, (SELECT MIN(a2.scheduled_at) FROM appointments a2 WHERE a2.patient_id = a.patient_id AND a2.status = 'completed') AS first_visit
                         FROM appointments a WHERE a.status = 'completed' AND a.patient_id IS NOT NULL AND " . rptIn('a.scheduled_at') . rptBranch('a.branch_id', $branch) . "
                         GROUP BY a.patient_id) f", [$from, $from, $to])[0];
    return ['seen' => (int)$r['seen'], 'new' => (int)$r['new_patients'], 'returning' => (int)$r['seen'] - (int)$r['new_patients']];
}

/** Insurance cards that run out within $days days (active cards of active patients). */
function rptCardsExpiring(PDO $db, int $days = 30): array {
    return rptAll($db, "SELECT pi.patient_id, CONCAT_WS(' ', p.first_name, p.last_name) AS patient, p.mrn, ic.name AS company, pi.member_no, pi.valid_to
        FROM patient_insurance pi JOIN patients p ON p.id = pi.patient_id AND p.status = 'active' LEFT JOIN insurance_plans ip ON ip.id = pi.plan_id
        LEFT JOIN insurance_companies ic ON ic.id = ip.company_id
        WHERE pi.is_active = 1 AND pi.valid_to BETWEEN CURDATE() AND CURDATE() + INTERVAL $days DAY ORDER BY pi.valid_to");
}

// ── Finance ──

/** Money received per day and method. */
function rptDailyCollection(PDO $db, string $from, string $to, int $branch = 0): array {
    $rows = [];
    foreach (rptAll($db, "SELECT DATE(r.received_at) AS day, m.method, SUM(m.amount) AS amount FROM receipts r JOIN receipt_methods m ON m.receipt_id = r.id
                          WHERE r.status = 'valid' AND " . rptIn('r.received_at') . rptBranch('r.branch_id', $branch) . " GROUP BY day, m.method ORDER BY day", [$from, $to]) as $r) {
        $rows[$r['day']] ??= ['day' => $r['day']] + array_fill_keys(array_keys(RPT_METHOD_LABELS), 0.0) + ['total' => 0.0];
        $rows[$r['day']][$r['method']] = (float)$r['amount'];
        $rows[$r['day']]['total'] = round($rows[$r['day']]['total'] + (float)$r['amount'], 2);
    }
    return array_values($rows);
}

function rptDayCloses(PDO $db, string $from, string $to, int $branch = 0): array {
    return rptAll($db, "SELECT dc.id, dc.business_date, b.name AS branch, s.name AS cashier, dc.difference, dc.note, dc.closed_at, ap.name AS approved_by
        FROM day_closes dc LEFT JOIN branches b ON b.id = dc.branch_id LEFT JOIN staff s ON s.id = dc.staff_id LEFT JOIN staff ap ON ap.id = dc.approved_by
        WHERE dc.business_date BETWEEN ? AND ?" . rptBranch('dc.branch_id', $branch) . " ORDER BY dc.business_date DESC", [$from, $to]);
}

/**
 * The UAE VAT return (FTA VAT 201) boxes for the period. Sales from issued invoices by issue date (standard-rated = lines
 * with VAT, zero-rated = 0% lines); credit notes issued in the period are the adjustments; input VAT from the purchase
 * VAT register by supplier invoice date. All sales go to the emirate given (the clinic's emirate).
 */
function rptVatReturn(PDO $db, string $from, string $to, int $branch = 0): array {
    $sales = rptAll($db, "SELECT SUM(CASE WHEN l.vat_rate > 0 THEN l.taxable ELSE 0 END) AS std, SUM(CASE WHEN l.vat_rate > 0 THEN l.vat_amount ELSE 0 END) AS std_vat,
                                 SUM(CASE WHEN l.vat_rate = 0 THEN l.taxable ELSE 0 END) AS zero
                          FROM invoice_lines l JOIN invoices i ON i.id = l.invoice_id
                          WHERE i.status NOT IN ('draft','void') AND i.imported IS NULL AND " . rptIn('i.issued_at') . rptBranch('i.branch_id', $branch), [$from, $to])[0];
    $adj = rptAll($db, "SELECT SUM(CASE WHEN l.vat_rate > 0 THEN c.amount ELSE 0 END) AS std, SUM(CASE WHEN l.vat_rate > 0 THEN c.vat_amount ELSE 0 END) AS std_vat,
                               SUM(CASE WHEN l.vat_rate = 0 THEN c.amount ELSE 0 END) AS zero
                        FROM credit_note_lines c JOIN credit_notes cn ON cn.id = c.credit_note_id JOIN invoice_lines l ON l.id = c.invoice_line_id
                        WHERE " . rptIn('cn.issued_at') . rptBranch('cn.branch_id', $branch), [$from, $to])[0];
    $in = rptAll($db, "SELECT COUNT(*) AS n, COALESCE(SUM(net), 0) AS net, COALESCE(SUM(vat), 0) AS vat FROM purchase_vat WHERE invoice_date BETWEEN ? AND ?"
                       . ($branch ? " AND (branch_id = $branch OR branch_id IS NULL)" : ''), [$from, $to])[0];
    $f = fn($v) => round((float)$v, 2);
    $box1 = ['amount' => $f($sales['std']), 'vat' => $f($sales['std_vat']), 'adj_amount' => -$f($adj['std']), 'adj_vat' => -$f($adj['std_vat'])];
    $box4 = ['amount' => $f($sales['zero']), 'adj_amount' => -$f($adj['zero'])];
    $out8 = ['amount' => $f($box1['amount'] + $box1['adj_amount'] + $box4['amount'] + $box4['adj_amount']), 'vat' => $f($box1['vat'] + $box1['adj_vat'])];
    $box9 = ['amount' => $f($in['net']), 'vat' => $f($in['vat']), 'invoices' => (int)$in['n']];
    return ['box1' => $box1, 'box4' => $box4, 'box8' => $out8, 'box9' => $box9, 'box11' => ['amount' => $box9['amount'], 'vat' => $box9['vat']],
            'box12' => $out8['vat'], 'box13' => $box9['vat'], 'box14' => $f($out8['vat'] - $box9['vat'])];
}

/** The VAT return as rows for a table / CSV. */
function rptVatRows(array $v, string $emirate): array {
    return [
        ['box' => '1', 'item' => "Standard-rated supplies in $emirate", 'amount' => $v['box1']['amount'], 'vat' => $v['box1']['vat'], 'adjustment' => $v['box1']['adj_amount'], 'adj_vat' => $v['box1']['adj_vat']],
        ['box' => '4', 'item' => 'Zero-rated supplies', 'amount' => $v['box4']['amount'], 'vat' => null, 'adjustment' => $v['box4']['adj_amount'], 'adj_vat' => null],
        ['box' => '8', 'item' => 'Totals (sales)', 'amount' => $v['box8']['amount'], 'vat' => $v['box8']['vat'], 'adjustment' => null, 'adj_vat' => null],
        ['box' => '9', 'item' => 'Standard-rated expenses (' . $v['box9']['invoices'] . ' supplier invoices)', 'amount' => $v['box9']['amount'], 'vat' => $v['box9']['vat'], 'adjustment' => null, 'adj_vat' => null],
        ['box' => '11', 'item' => 'Totals (expenses)', 'amount' => $v['box11']['amount'], 'vat' => $v['box11']['vat'], 'adjustment' => null, 'adj_vat' => null],
        ['box' => '12', 'item' => 'Total value of due tax for the period', 'amount' => null, 'vat' => $v['box12'], 'adjustment' => null, 'adj_vat' => null],
        ['box' => '13', 'item' => 'Total value of recoverable tax for the period', 'amount' => null, 'vat' => $v['box13'], 'adjustment' => null, 'adj_vat' => null],
        ['box' => '14', 'item' => $v['box14'] >= 0 ? 'Payable tax for the period' : 'Tax to be reclaimed', 'amount' => null, 'vat' => $v['box14'], 'adjustment' => null, 'adj_vat' => null],
    ];
}

/** A supplier tax invoice for the purchase VAT register. */
function savePurchaseVat(PDO $db, array $in): int {
    $date = (string)($in['invoice_date'] ?? '');
    $supplier = trim((string)($in['supplier'] ?? '')); $ref = trim((string)($in['invoice_ref'] ?? ''));
    $net = round((float)($in['net'] ?? 0), 2); $vat = round((float)($in['vat'] ?? 0), 2);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) throw new RuntimeException('Enter the supplier invoice date (not in the future).');
    if ($supplier === '' || $ref === '') throw new RuntimeException('Enter the supplier and their invoice number.');
    if ($net <= 0 || $vat < 0 || $vat > round($net * 0.05, 2) + 0.05) throw new RuntimeException('Enter the amount before VAT and the VAT (at most 5% of it).');
    $trn = preg_replace('/\D/', '', (string)($in['supplier_trn'] ?? ''));
    if ($trn !== '' && strlen($trn) !== 15) throw new RuntimeException('A TRN has 15 digits.');
    try {
        $db->prepare("INSERT INTO purchase_vat (branch_id, invoice_date, supplier, supplier_trn, invoice_ref, net, vat, note, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([(int)($in['branch_id'] ?? 0) ?: null, $date, mb_substr($supplier, 0, 150), $trn ?: null, mb_substr($ref, 0, 60), $net, $vat,
                      mb_substr(trim((string)($in['note'] ?? '')), 0, 255) ?: null, currentStaffId() ?: null]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') throw new RuntimeException('This supplier invoice is already in the register.');
        throw $e;
    }
    $id = (int)$db->lastInsertId();
    auditLog($db, 'purchase_vat', $id, 'created', ['supplier' => $supplier, 'ref' => $ref, 'net' => $net, 'vat' => $vat]);
    return $id;
}

function rptPurchaseVat(PDO $db, string $from, string $to): array {
    return rptAll($db, "SELECT pv.*, s.name AS entered_by FROM purchase_vat pv LEFT JOIN staff s ON s.id = pv.created_by WHERE pv.invoice_date BETWEEN ? AND ? ORDER BY pv.invoice_date, pv.id", [$from, $to]);
}

function rptInvoiceRegister(PDO $db, string $from, string $to, int $branch = 0): array {
    return rptAll($db, "SELECT i.id, i.number, i.issued_at, CONCAT_WS(' ', p.first_name, p.last_name) AS patient, p.mrn, i.type, i.net, i.vat, i.total, i.paid, i.credited, i.refunded,
                               i.total - i.credited - (i.paid - i.refunded) AS balance, i.status
        FROM invoices i JOIN patients p ON p.id = i.patient_id WHERE i.status <> 'draft' AND i.imported IS NULL AND " . rptIn('i.issued_at') . rptBranch('i.branch_id', $branch) . " ORDER BY i.issued_at, i.id", [$from, $to]);
}

/** What patients still owe today, by invoice, with its age (days since issue). */
function rptPatientReceivables(PDO $db, int $branch = 0): array {
    $out = [];
    foreach (rptAll($db, "SELECT i.*, CONCAT_WS(' ', p.first_name, p.last_name) AS patient, p.mrn FROM invoices i JOIN patients p ON p.id = i.patient_id
                          WHERE i.status IN ('issued','partially_paid')" . rptBranch('i.branch_id', $branch) . " ORDER BY i.issued_at") as $i) {
        $due = invoicePatientDue($i);
        if ($due <= 0) continue;
        $age = (int)floor((time() - strtotime($i['issued_at'])) / 86400);
        $out[] = ['id' => $i['id'], 'number' => $i['number'], 'issued_at' => $i['issued_at'], 'patient' => $i['patient'], 'mrn' => $i['mrn'], 'due' => aed($due), 'age' => $age,
                  'bucket' => $age <= 30 ? '0-30' : ($age <= 60 ? '31-60' : ($age <= 90 ? '61-90' : '90+'))];
    }
    return $out;
}

function rptCreditNotes(PDO $db, string $from, string $to, int $branch = 0): array {
    return rptAll($db, "SELECT cn.id, cn.number, cn.issued_at, i.number AS invoice, CONCAT_WS(' ', p.first_name, p.last_name) AS patient, cn.reason, cn.gross, cn.vat, cn.total, cn.refund_method, cn.refunded
        FROM credit_notes cn JOIN invoices i ON i.id = cn.invoice_id JOIN patients p ON p.id = i.patient_id WHERE " . rptIn('cn.issued_at') . rptBranch('cn.branch_id', $branch) . " ORDER BY cn.issued_at", [$from, $to]);
}

/** Each live voucher: value, used, left. */
function rptVoucherLiability(PDO $db): array {
    return rptAll($db, "SELECT v.id, v.code, v.recipient, v.initial, v.expires_on,
                               COALESCE((SELECT SUM(m.amount) FROM receipt_methods m JOIN receipts r ON r.id = m.receipt_id AND r.status = 'valid' WHERE m.voucher_id = v.id), 0) AS used,
                               v.initial - COALESCE((SELECT SUM(m.amount) FROM receipt_methods m JOIN receipts r ON r.id = m.receipt_id AND r.status = 'valid' WHERE m.voucher_id = v.id), 0) AS balance
        FROM vouchers v WHERE v.status = 'active' AND v.expires_on >= CURDATE() HAVING balance > 0 ORDER BY v.expires_on");
}

// ── Clinical ── ($prac > 0 limits to one practitioner: practitioners see their own figures only)

function rptDiagnoses(PDO $db, string $from, string $to, int $branch = 0, int $prac = 0): array {
    return rptAll($db, "SELECT d.icd10_code AS code, MAX(d.description) AS description, COUNT(DISTINCT e.id) AS encounters, COUNT(DISTINCT e.patient_id) AS patients
        FROM encounter_diagnoses d JOIN encounters e ON e.id = d.encounter_id WHERE " . rptIn('e.created_at') . rptBranch('e.branch_id', $branch) . rptBranch('e.practitioner_id', $prac) . "
        GROUP BY d.icd10_code ORDER BY encounters DESC", [$from, $to]);
}

/** Treatments done: completed visits' services. */
function rptProcedures(PDO $db, string $from, string $to, int $branch = 0, int $prac = 0): array {
    return rptAll($db, "SELECT sv.name, sv.cpt_code, sv.category, COUNT(DISTINCT a.id) AS visits, SUM(COALESCE(x.qty, 1)) AS qty, COUNT(DISTINCT a.patient_id) AS patients
        FROM appointments a JOIN appointment_services x ON x.appointment_id = a.id JOIN services sv ON sv.id = x.service_id
        WHERE a.status = 'completed' AND " . rptIn('a.scheduled_at') . rptBranch('a.branch_id', $branch) . rptBranch('a.practitioner_id', $prac) . "
        GROUP BY sv.id ORDER BY qty DESC", [$from, $to]);
}

function rptPrescriptions(PDO $db, string $from, string $to, int $branch = 0, int $prac = 0): array {
    return rptAll($db, "SELECT pi.drug, pi.strength, COUNT(*) AS items, COUNT(DISTINCT pr.patient_id) AS patients
        FROM prescription_items pi JOIN prescriptions pr ON pr.id = pi.prescription_id LEFT JOIN encounters e ON e.id = pr.encounter_id
        WHERE " . rptIn('pr.created_at') . rptBranch('e.branch_id', $branch) . rptBranch('pr.prescribed_by', $prac) . " GROUP BY pi.drug, pi.strength ORDER BY items DESC", [$from, $to]);
}

/** Notes not signed yet (any date), oldest first. */
function rptUnsignedNotes(PDO $db, int $branch = 0, int $prac = 0): array {
    return rptAll($db, "SELECT e.id, e.created_at, CONCAT_WS(' ', p.first_name, p.last_name) AS patient, p.mrn, s.name AS practitioner, TIMESTAMPDIFF(HOUR, e.created_at, NOW()) AS hours
        FROM encounters e JOIN patients p ON p.id = e.patient_id LEFT JOIN staff s ON s.id = e.practitioner_id
        WHERE e.status = 'open'" . rptBranch('e.branch_id', $branch) . rptBranch('e.practitioner_id', $prac) . " ORDER BY e.created_at");
}

/** Completed visits for a treatment that needs a consent form, where the patient has no signed (unrevoked) copy. */
function rptConsentsMissing(PDO $db, string $from, string $to, int $branch = 0, int $prac = 0): array {
    return rptAll($db, "SELECT a.id AS appointment_id, a.scheduled_at, CONCAT_WS(' ', p.first_name, p.last_name) AS patient, p.mrn, sv.name AS treatment, ct.name AS consent, s.name AS practitioner
        FROM appointments a JOIN appointment_services x ON x.appointment_id = a.id JOIN services sv ON sv.id = x.service_id AND sv.requires_consent_template_id IS NOT NULL
        JOIN consent_templates ct ON ct.id = sv.requires_consent_template_id JOIN patients p ON p.id = a.patient_id LEFT JOIN staff s ON s.id = a.practitioner_id
        WHERE a.status = 'completed' AND " . rptIn('a.scheduled_at') . rptBranch('a.branch_id', $branch) . rptBranch('a.practitioner_id', $prac) . "
          AND NOT EXISTS (SELECT 1 FROM patient_consents pc WHERE pc.patient_id = a.patient_id AND pc.template_id = ct.id AND pc.signed_at IS NOT NULL AND pc.revoked_at IS NULL)
        ORDER BY a.scheduled_at", [$from, $to]);
}

/** Active patients by how long since their last completed visit: 30-89, 90-179, 180-359, 360+ days. */
function rptNotVisited(PDO $db, int $branch = 0, int $prac = 0): array {
    $last = "SELECT a.patient_id, MAX(a.scheduled_at) AS last_visit FROM appointments a WHERE a.status = 'completed'" . rptBranch('a.branch_id', $branch) . rptBranch('a.practitioner_id', $prac) . " GROUP BY a.patient_id";
    $out = [];
    foreach ([[30, 89], [90, 179], [180, 359], [360, 100000]] as [$a, $b])
        $out[] = ['bucket' => $b > 1000 ? "$a+ days" : "$a-$b days", 'days' => $a,
                  'patients' => (int)$db->query("SELECT COUNT(*) FROM ($last) l JOIN patients p ON p.id = l.patient_id AND p.status = 'active'
                                                 WHERE l.last_visit < CURDATE() - INTERVAL $a DAY AND l.last_visit >= CURDATE() - INTERVAL " . ($b + 1) . " DAY")->fetchColumn()];
    return $out;
}

function rptNotVisitedList(PDO $db, int $days, int $branch = 0, int $prac = 0): array {
    return rptAll($db, "SELECT p.id, p.mrn, CONCAT_WS(' ', p.first_name, p.last_name) AS patient, l.last_visit, DATEDIFF(CURDATE(), l.last_visit) AS days, p.marketing_consent
        FROM (SELECT a.patient_id, MAX(a.scheduled_at) AS last_visit FROM appointments a WHERE a.status = 'completed'" . rptBranch('a.branch_id', $branch) . rptBranch('a.practitioner_id', $prac) . " GROUP BY a.patient_id) l
        JOIN patients p ON p.id = l.patient_id AND p.status = 'active' WHERE l.last_visit < CURDATE() - INTERVAL $days DAY ORDER BY l.last_visit DESC LIMIT 1000");
}

// ── DHA statistics ──

/** Age group label for an age in years (null = not recorded). */
function rptAgeGroup(?int $age): string {
    if ($age === null) return 'Not recorded';
    foreach (RPT_AGE_GROUPS as [$a, $b, $l]) if ($age >= $a && $age <= $b) return $l;
    return 'Not recorded';
}

function rptCountry(?string $cc): string { return $cc ? (COUNTRIES[$cc] ?? $cc) : 'Not recorded'; }

/**
 * Out-patient visits (completed appointments) in the period, each marked new (the patient's first completed visit ever)
 * or follow-up, with nationality, sex and age at the visit. Grouped by nationality, sex and age group.
 */
function rptDhaVisits(PDO $db, string $from, string $to, int $branch = 0): array {
    $rows = rptAll($db, "SELECT a.id, p.nationality, p.gender, TIMESTAMPDIFF(YEAR, p.dob, a.scheduled_at) AS age,
                                NOT EXISTS (SELECT 1 FROM appointments a2 WHERE a2.patient_id = a.patient_id AND a2.status = 'completed' AND (a2.scheduled_at < a.scheduled_at OR (a2.scheduled_at = a.scheduled_at AND a2.id < a.id))) AS is_new
                         FROM appointments a JOIN patients p ON p.id = a.patient_id WHERE a.status = 'completed' AND " . rptIn('a.scheduled_at') . rptBranch('a.branch_id', $branch), [$from, $to]);
    $out = ['nationality' => [], 'sex' => [], 'age' => [], 'total' => ['new' => 0, 'follow_up' => 0]];
    foreach (array_merge([['Not recorded', null]], array_map(fn($g) => [$g[2], null], RPT_AGE_GROUPS)) as [$l]) $out['age'][$l] = ['name' => $l, 'new' => 0, 'follow_up' => 0, 'total' => 0];
    foreach ($rows as $r) {
        $k = $r['is_new'] ? 'new' : 'follow_up';
        foreach (['nationality' => rptCountry($r['nationality']), 'sex' => (['female' => 'Female', 'male' => 'Male', 'other' => 'Other'][$r['gender']] ?? 'Not recorded'), 'age' => rptAgeGroup($r['age'] === null ? null : (int)$r['age'])] as $g => $name) {
            $out[$g][$name] ??= ['name' => $name, 'new' => 0, 'follow_up' => 0, 'total' => 0];
            $out[$g][$name][$k]++; $out[$g][$name]['total']++;
        }
        $out['total'][$k]++;
    }
    uasort($out['nationality'], fn($a, $b) => $b['total'] <=> $a['total']);
    $out['age'] = array_filter($out['age'], fn($r) => $r['total'] || $r['name'] !== 'Not recorded');
    foreach (['nationality', 'sex', 'age'] as $g) $out[$g] = array_values($out[$g]);
    return $out;
}

/** Patients (distinct) by nationality and primary diagnosis, encounters in the period. */
function rptDhaNationalityDiagnosis(PDO $db, string $from, string $to, int $branch = 0): array {
    $rows = rptAll($db, "SELECT p.nationality, d.icd10_code AS code, MAX(d.description) AS description, COUNT(DISTINCT e.patient_id) AS patients
        FROM encounters e JOIN patients p ON p.id = e.patient_id JOIN encounter_diagnoses d ON d.encounter_id = e.id AND d.is_primary = 1
        WHERE " . rptIn('e.created_at') . rptBranch('e.branch_id', $branch) . " GROUP BY p.nationality, d.icd10_code ORDER BY patients DESC", [$from, $to]);
    foreach ($rows as &$r) $r['nationality'] = rptCountry($r['nationality']);
    return $rows;
}

/** Minutes from arrival to seeing the doctor: overall and by practitioner, with the share seen within 15/30/60 minutes. */
function rptDhaWaiting(PDO $db, string $from, string $to, int $branch = 0): array {
    return rptAll($db, "SELECT COALESCE(s.name, 'No practitioner') AS practitioner, COUNT(*) AS visits, ROUND(AVG(w), 1) AS avg_min, MAX(w) AS max_min,
                               SUM(w <= 15) AS within_15, SUM(w <= 30) AS within_30, SUM(w <= 60) AS within_60
        FROM (SELECT a.practitioner_id, TIMESTAMPDIFF(MINUTE, a.arrived_at, a.doctor_in_at) AS w FROM appointments a
              WHERE a.arrived_at IS NOT NULL AND a.doctor_in_at >= a.arrived_at AND " . rptIn('a.scheduled_at') . rptBranch('a.branch_id', $branch) . ") t
        LEFT JOIN staff s ON s.id = t.practitioner_id GROUP BY t.practitioner_id ORDER BY visits DESC", [$from, $to]);
}

/** Treatments done in one department (by the practitioner's department), by treatment and by nationality. */
function rptDhaDepartment(PDO $db, int $dept, string $from, string $to, int $branch = 0): array {
    $base = "FROM appointments a JOIN appointment_services x ON x.appointment_id = a.id JOIN services sv ON sv.id = x.service_id JOIN patients p ON p.id = a.patient_id
             JOIN practitioner_profiles pp ON pp.staff_id = a.practitioner_id AND pp.department_id = $dept
             WHERE a.status = 'completed' AND " . rptIn('a.scheduled_at') . rptBranch('a.branch_id', $branch);
    $byNat = rptAll($db, "SELECT p.nationality, COUNT(DISTINCT a.patient_id) AS patients, SUM(COALESCE(x.qty, 1)) AS treatments $base GROUP BY p.nationality ORDER BY treatments DESC", [$from, $to]);
    foreach ($byNat as &$r) $r['nationality'] = rptCountry($r['nationality']);
    return ['treatments' => rptAll($db, "SELECT sv.name, COUNT(DISTINCT a.patient_id) AS patients, SUM(COALESCE(x.qty, 1)) AS treatments $base GROUP BY sv.id ORDER BY treatments DESC", [$from, $to]),
            'nationality' => $byNat,
            'patients' => (int)rptAll($db, "SELECT COUNT(DISTINCT a.patient_id) AS n $base", [$from, $to])[0]['n']];
}

/** Patients seen in the period: UAE nationals, residents, tourists/visitors (from residency; nationality AE counts as national). */
function rptDhaResidency(PDO $db, string $from, string $to, int $branch = 0): array {
    $rows = rptAll($db, "SELECT CASE WHEN p.residency = 'citizen' OR p.nationality = 'AE' THEN 'UAE national' WHEN p.residency = 'resident' THEN 'Resident'
                                     WHEN p.residency = 'visitor' THEN 'Tourist / visitor' ELSE 'Not recorded' END AS name, COUNT(DISTINCT p.id) AS patients, COUNT(*) AS visits
        FROM appointments a JOIN patients p ON p.id = a.patient_id WHERE a.status = 'completed' AND " . rptIn('a.scheduled_at') . rptBranch('a.branch_id', $branch) . "
        GROUP BY name ORDER BY patients DESC", [$from, $to]);
    return $rows;
}
