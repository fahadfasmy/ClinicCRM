<?php
// includes/billing.php — the money side of a visit (Phase 7).
// All arithmetic is in whole fils (1 AED = 100 fils, PHP ints); amounts are stored as DECIMAL(12,2).
// VAT is worked out per line and rounded half-up; invoice totals are the sums of the lines. Whether entered prices
// already include VAT is the clinic's choice (Payment & tax settings) and is frozen on each invoice when it is created.
// Invoices: draft → issued (number taken inside the issuing transaction) → partially_paid → paid; or void.
// Issued invoices never change: corrections are credit notes. Receipts mix methods and are allocated across invoices;
// money left over goes to the patient's wallet, a ledger whose balance is always its sum.
require_once __DIR__ . '/patients.php';
require_once __DIR__ . '/inventory.php';      // stockTx, addMovement, csrf helpers
require_once __DIR__ . '/clinic_setup.php';   // nextDocumentNumber, branchList
require_once __DIR__ . '/scheduler.php';      // APPT_OPEN

const PAY_METHODS = ['insurance' => 'Insurance', 'cash' => 'Cash', 'card' => 'Card', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'tabby' => 'Tabby', 'tamara' => 'Tamara',
                     'stripe' => 'Payment link', 'wallet' => 'Patient balance', 'voucher' => 'Voucher'];
const DRAWER_METHODS = ['cash', 'card', 'bank_transfer', 'cheque', 'tabby', 'tamara', 'stripe'];   // counted at day close
const REFUND_METHODS = ['cash' => 'Cash', 'card' => 'Back to card', 'bank_transfer' => 'Bank transfer'];
const INVOICE_STATUS = ['draft' => ['Draft', 'tone-slate'], 'issued' => ['Unpaid', 'tone-amber'], 'partially_paid' => ['Part paid', 'tone-orange'],
                        'paid' => ['Paid', 'tone-green'], 'void' => ['Void', 'tone-red']];
const DISCOUNT_LIMIT_DEFAULTS = ['receptionist' => 10, 'finance' => 20];

// ── Money ──

function fils($amount): int { return (int)round(round((float)$amount, 2) * 100); }
function aed(int $fils): string { return number_format($fils / 100, 2, '.', ''); }
function money($amount): string { return 'AED ' . number_format((float)$amount, 2); }

/** One line's amounts in fils. $discount is in the same terms as the price (VAT-inclusive or not).
 *  Returns [gross, discount, taxable, vat, total]. */
function lineAmounts(float $qty, int $unitFils, int $discountFils, float $vatRate, bool $inclusive): array {
    $gross = (int)round($qty * $unitFils);
    $disc = max(0, min($discountFils, $gross));
    $amt = $gross - $disc;
    if ($inclusive) { $vat = (int)round($amt * $vatRate / (100 + $vatRate)); return [$gross, $disc, $amt - $vat, $vat, $amt]; }
    $vat = (int)round($amt * $vatRate / 100);
    return [$gross, $disc, $amt, $vat, $amt + $vat];
}

/** A Payment & tax setting (integration_settings billing.*). */
function billingSetting(PDO $db, string $k, string $default = ''): string {
    $s = $db->prepare("SELECT v FROM integration_settings WHERE k = ?");
    $s->execute(['billing.' . $k]);
    $v = $s->fetchColumn();
    return $v === false || $v === null ? $default : (string)$v;
}

function pricesIncludeVat(PDO $db): bool { return billingSetting($db, 'prices_include_vat', '0') === '1'; }

/** The biggest discount (percent of the line) this person may give without an approval. */
function discountLimitPct(PDO $db): float {
    if (can('billing.discount') || isAdmin()) return 100.0;
    $role = currentRole();
    return (float)billingSetting($db, "discount_limit.$role", (string)(DISCOUNT_LIMIT_DEFAULTS[$role] ?? 0));
}

// ── Invoices ──

function findInvoice(PDO $db, int $id, bool $forUpdate = false): ?array {
    $s = $db->prepare("SELECT i.*, b.name AS branch_name FROM invoices i LEFT JOIN branches b ON b.id = i.branch_id WHERE i.id = ?" . ($forUpdate ? ' FOR UPDATE' : ''));
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function invoiceLines(PDO $db, int $invoiceId): array {
    $s = $db->prepare("SELECT l.*, s.name AS practitioner_name, (SELECT COALESCE(SUM(c.qty), 0) FROM credit_note_lines c WHERE c.invoice_line_id = l.id) AS credited_qty
                       FROM invoice_lines l LEFT JOIN staff s ON s.id = l.practitioner_id WHERE l.invoice_id = ? ORDER BY l.id");
    $s->execute([$invoiceId]);
    return $s->fetchAll();
}

/** What is still owed on an invoice, in fils (negative = overpaid). */
function invoiceBalance(array $inv): int {
    return fils($inv['total']) - fils($inv['credited']) - (fils($inv['paid']) - fils($inv['refunded']));
}

/** What the patient still owes on an invoice, in fils: everything for cash invoices, their share for insurance ones. */
function invoicePatientDue(array $inv): int {
    $bal = invoiceBalance($inv);
    if ($inv['type'] !== 'insurance') return $bal;
    return max(0, min($bal, fils($inv['patient_share']) - (fils($inv['paid']) - fils($inv['insurer_paid']))));
}

function assertDraft(array $inv): void {
    if ($inv['status'] !== 'draft') throw new RuntimeException('This invoice is issued. Changes are made with a credit note.');
}

/** A new draft invoice. Returns its id. */
function createInvoice(PDO $db, int $patientId, ?int $branchId = null, array $opt = []): int {
    $p = findPatient($db, $patientId);
    if (!$p || (int)$p['id'] !== $patientId || $p['status'] === 'merged') throw new RuntimeException('Patient not found.');
    $branchId = $branchId ?: (int)($p['branch_id'] ?: array_key_first(branchList($db)));
    if (!isset(branchList($db)[$branchId])) throw new RuntimeException('Choose a branch.');
    $db->prepare("INSERT INTO invoices (branch_id, patient_id, appointment_id, encounter_id, type, vat_inclusive, created_by) VALUES (?,?,?,?, 'cash', ?, ?)")
       ->execute([$branchId, $patientId, $opt['appointment_id'] ?? null, $opt['encounter_id'] ?? null, pricesIncludeVat($db) ? 1 : 0, currentStaffId() ?: null]);
    return (int)$db->lastInsertId();
}

/** The draft (or issued) invoice for a visit, made from its services, the doctor's procedures and package sessions. */
function invoiceFromVisit(PDO $db, int $apptId): int {
    $s = $db->prepare("SELECT id FROM invoices WHERE appointment_id = ? AND status <> 'void' ORDER BY id LIMIT 1");
    $s->execute([$apptId]);
    if ($id = (int)$s->fetchColumn()) return $id;
    $a = $db->prepare("SELECT * FROM appointments WHERE id = ?");
    $a->execute([$apptId]);
    $a = $a->fetch();
    if (!$a || !$a['patient_id']) throw new RuntimeException('This appointment has no patient record yet. Register the patient first.');
    if (in_array($a['status'], ['cancelled', 'no_show', 'rescheduled'], true)) throw new RuntimeException('This visit did not happen.');
    $enc = $db->prepare("SELECT id FROM encounters WHERE appointment_id = ?");
    $enc->execute([$apptId]);
    $encId = (int)$enc->fetchColumn() ?: null;
    return stockTx($db, function () use ($db, $a, $apptId, $encId) {
        $id = createInvoice($db, (int)$a['patient_id'], (int)$a['branch_id'] ?: null, ['appointment_id' => $apptId, 'encounter_id' => $encId]);
        // The doctor's recorded procedures win; otherwise the services that were booked
        $svc = [];
        if ($encId) { $q = $db->prepare("SELECT service_id, qty, area FROM encounter_procedures WHERE encounter_id = ? AND service_id IS NOT NULL"); $q->execute([$encId]); foreach ($q as $r) $svc[] = [(int)$r['service_id'], (float)$r['qty'], $r['area']]; }
        if (!$svc) { $q = $db->prepare("SELECT service_id FROM appointment_services WHERE appointment_id = ? UNION SELECT service_id FROM appointments WHERE id = ? AND service_id IS NOT NULL"); $q->execute([$apptId, $apptId]); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $sid) $svc[] = [(int)$sid, 1.0, null]; }
        $used = [];
        if ($a['patient_package_id']) { $q = $db->prepare("SELECT service_id FROM package_usages WHERE patient_package_id = ? AND appointment_id = ?"); $q->execute([$a['patient_package_id'], $apptId]); $used = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)); }
        foreach ($svc as [$sid, $qty, $area]) {
            $fromPackage = in_array($sid, $used, true);
            addInvoiceLine($db, $id, ['kind' => 'service', 'service_id' => $sid, 'qty' => $qty, 'practitioner_id' => $a['practitioner_id'], 'note' => $area,
                                      'patient_package_id' => $fromPackage ? (int)$a['patient_package_id'] : null], false);
        }
        recalcInvoice($db, $id);
        return $id;
    });
}

/** Adds a line to a draft. $in: kind, service_id | package_id | product_id | description, qty, unit_price (manual or
 *  override), discount (amount), discount_pct, discount_rule_id, practitioner_id, patient_package_id (session: price 0). */
function addInvoiceLine(PDO $db, int $invoiceId, array $in, bool $recalc = true): int {
    $inv = findInvoice($db, $invoiceId);
    if (!$inv) throw new RuntimeException('Invoice not found.');
    assertDraft($inv);
    $kind = $in['kind'] ?? 'service';
    $qty = round((float)($in['qty'] ?? 1), 2);
    if ($qty <= 0 || $qty > 9999) throw new RuntimeException('Enter a quantity above zero.');
    $l = ['service_id' => null, 'package_id' => null, 'product_id' => null, 'patient_package_id' => null, 'cpt_code' => null];
    switch ($kind) {
        case 'service':
            $s = $db->prepare("SELECT * FROM services WHERE id = ?"); $s->execute([(int)($in['service_id'] ?? 0)]); $r = $s->fetch();
            if (!$r) throw new RuntimeException('Choose a treatment.');
            $l += ['description' => $r['name'] . (!empty($in['note']) ? ' – ' . $in['note'] : ''), 'price' => $r['price'], 'vat_rate' => $r['vat_rate']];
            $l['service_id'] = (int)$r['id']; $l['cpt_code'] = $r['cpt_code'];
            if ($inv['type'] === 'insurance' && $inv['insurance_plan_id']) {   // the plan's own price and code, when it has one
                $pp = $db->prepare("SELECT i.price, i.cpt_code FROM price_lists p JOIN price_list_items i ON i.price_list_id = p.id WHERE p.insurance_plan_id = ? AND i.service_id = ?
                                    AND (p.valid_from IS NULL OR p.valid_from <= CURDATE()) AND (p.valid_to IS NULL OR p.valid_to >= CURDATE())");
                $pp->execute([$inv['insurance_plan_id'], $r['id']]);
                if ($x = $pp->fetch()) { $l['price'] = $x['price']; $l['cpt_code'] = $x['cpt_code'] ?: $l['cpt_code']; }
            }
            if (!empty($in['patient_package_id'])) {
                $pp = $db->prepare("SELECT pk.name FROM patient_packages p JOIN packages pk ON pk.id = p.package_id WHERE p.id = ? AND p.patient_id = ?");
                $pp->execute([(int)$in['patient_package_id'], $inv['patient_id']]);
                if (!($pkName = $pp->fetchColumn())) throw new RuntimeException('That package belongs to another patient.');
                $l['patient_package_id'] = (int)$in['patient_package_id']; $l['price'] = 0; $l['description'] .= ' (package: ' . $pkName . ')';
            }
            break;
        case 'package':
            $s = $db->prepare("SELECT * FROM packages WHERE id = ? AND is_active = 1"); $s->execute([(int)($in['package_id'] ?? 0)]); $r = $s->fetch();
            if (!$r) throw new RuntimeException('Choose a package.');
            $l += ['description' => 'Package: ' . $r['name'], 'price' => $r['price'], 'vat_rate' => $r['vat_rate']];
            $l['package_id'] = (int)$r['id'];
            break;
        case 'product':
            $s = $db->prepare("SELECT * FROM products WHERE id = ? AND is_active = 1 AND sell_price IS NOT NULL"); $s->execute([(int)($in['product_id'] ?? 0)]); $r = $s->fetch();
            if (!$r) throw new RuntimeException('Choose a product that has a selling price.');
            if (isMultiDose($r)) throw new RuntimeException('Products used from opened vials can’t be sold.');
            $l += ['description' => $r['name'] . ($r['brand'] ? ' (' . $r['brand'] . ')' : ''), 'price' => $r['sell_price'], 'vat_rate' => $r['vat_rate']];
            $l['product_id'] = (int)$r['id'];
            break;
        case 'manual':
            $d = trim((string)($in['description'] ?? ''));
            if ($d === '') throw new RuntimeException('Describe the item.');
            $l += ['description' => $d, 'price' => $in['unit_price'] ?? 0, 'vat_rate' => $in['vat_rate'] ?? billingSetting($db, 'vat_rate', '5')];
            break;
        default: throw new RuntimeException('Unknown line type.');
    }
    if (isset($in['unit_price']) && $in['unit_price'] !== '' && $kind !== 'manual' && !$l['patient_package_id']) $l['price'] = $in['unit_price'];
    if (fils($l['price']) < 0) throw new RuntimeException('The price can’t be negative.');
    $vatRate = (float)$l['vat_rate'];
    if (!in_array($vatRate, [0.0, 5.0], true)) throw new RuntimeException('VAT must be 5% or 0%.');
    $db->prepare("INSERT INTO invoice_lines (invoice_id, kind, service_id, package_id, product_id, patient_package_id, practitioner_id, description, cpt_code, qty, unit_price, vat_rate)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$invoiceId, $kind, $l['service_id'], $l['package_id'], $l['product_id'], $l['patient_package_id'], (int)($in['practitioner_id'] ?? 0) ?: null,
                  mb_substr($l['description'], 0, 200), $l['cpt_code'], $qty, aed(fils($l['price'])), $vatRate]);
    $lineId = (int)$db->lastInsertId();
    if (!empty($in['discount']) || !empty($in['discount_pct']) || !empty($in['discount_rule_id'])) setLineDiscount($db, $lineId, $in, false);
    if ($recalc) recalcInvoice($db, $invoiceId);
    return $lineId;
}

/** A line's discount: an amount, a percent, or a discount rule. Staff are held to their role's limit; rules are pre-approved. */
function setLineDiscount(PDO $db, int $lineId, array $in, bool $recalc = true): void {
    $s = $db->prepare("SELECT l.*, i.status, i.id AS inv FROM invoice_lines l JOIN invoices i ON i.id = l.invoice_id WHERE l.id = ?");
    $s->execute([$lineId]);
    $l = $s->fetch();
    if (!$l) throw new RuntimeException('Line not found.');
    assertDraft($l);
    $gross = (int)round((float)$l['qty'] * fils($l['unit_price']));
    $ruleId = (int)($in['discount_rule_id'] ?? 0) ?: null;
    if ($ruleId) {
        $r = discountRule($db, $ruleId);
        if (!$r) throw new RuntimeException('That discount isn’t available today.');
        $ids = $r['service_ids'] ? json_decode($r['service_ids'], true) : null;
        if ($ids && !in_array((int)$l['service_id'], array_map('intval', $ids), true)) throw new RuntimeException("“{$r['name']}” doesn’t apply to this item.");
        $disc = $r['kind'] === 'percent' ? (int)round($gross * (float)$r['value'] / 100) : fils($r['value']);
        if ($r['max_amount'] !== null) $disc = min($disc, fils($r['max_amount']));
    } else {
        $disc = !empty($in['discount_pct']) ? (int)round($gross * (float)$in['discount_pct'] / 100) : fils($in['discount'] ?? 0);
        if ($disc < 0) throw new RuntimeException('A discount can’t be negative.');
        $limit = discountLimitPct($db);
        if ($gross > 0 && $disc > (int)round($gross * $limit / 100)) throw new RuntimeException('That is more than your discount limit of ' . rtrim(rtrim(number_format($limit, 2), '0'), '.') . '%. Ask a manager.');
    }
    $db->prepare("UPDATE invoice_lines SET discount = ?, discount_rule_id = ? WHERE id = ?")->execute([aed(min($disc, $gross)), $ruleId, $lineId]);
    if ($recalc) recalcInvoice($db, (int)$l['inv']);
}

function removeInvoiceLine(PDO $db, int $invoiceId, int $lineId): void {
    $inv = findInvoice($db, $invoiceId);
    if (!$inv) throw new RuntimeException('Invoice not found.');
    assertDraft($inv);
    $db->prepare("DELETE FROM invoice_lines WHERE id = ? AND invoice_id = ?")->execute([$lineId, $invoiceId]);
    recalcInvoice($db, $invoiceId);
}

/** Recomputes every line and the invoice totals of a draft from qty, price, discount and VAT rate. */
function recalcInvoice(PDO $db, int $invoiceId): void {
    $inv = findInvoice($db, $invoiceId);
    assertDraft($inv);
    $t = ['gross' => 0, 'discount' => 0, 'net' => 0, 'vat' => 0, 'total' => 0];
    $rows = [];
    foreach (invoiceLines($db, $invoiceId) as $l) {
        [$g, $d, $net, $vat, $tot] = lineAmounts((float)$l['qty'], fils($l['unit_price']), fils($l['discount']), (float)$l['vat_rate'], (bool)$inv['vat_inclusive']);
        $rows[] = ['id' => (int)$l['id'], 'net' => $net, 'vat' => $vat, 'total' => $tot, 'covered' => (bool)$l['covered']];
        foreach (['gross' => $g, 'discount' => $d, 'net' => $net, 'vat' => $vat, 'total' => $tot] as $k => $v) $t[$k] += $v;
    }
    // Insurance invoices: the plan decides the patient's part (insurance.php); cash invoices: the patient pays it all
    $plan = $inv['type'] === 'insurance' && $inv['insurance_plan_id'] ? $db->query("SELECT * FROM insurance_plans WHERE id = " . (int)$inv['insurance_plan_id'])->fetch() : null;
    if ($plan) require_once __DIR__ . '/insurance.php';
    $patient = $plan ? patientShares($rows, $plan) : array_column($rows, 'total', 'id');
    $up = $db->prepare("UPDATE invoice_lines SET taxable = ?, vat_amount = ?, line_total = ?, patient_share = ?, insurance_share = ? WHERE id = ?");
    foreach ($rows as $r) $up->execute([aed($r['net']), aed($r['vat']), aed($r['total']), aed($patient[$r['id']]), aed($r['total'] - $patient[$r['id']]), $r['id']]);
    $ps = array_sum($patient);
    $db->prepare("UPDATE invoices SET gross = ?, discount = ?, net = ?, vat = ?, total = ?, patient_share = ?, insurance_share = ? WHERE id = ?")
       ->execute([aed($t['gross']), aed($t['discount']), aed($t['net']), aed($t['vat']), aed($t['total']), aed($ps), aed($t['total'] - $ps), $invoiceId]);
}

/** Issues a draft: takes the next number for its branch, sells its packages and products, stores the printed copy. */
function issueInvoice(PDO $db, int $invoiceId): string {
    return stockTx($db, function () use ($db, $invoiceId) {
        $inv = findInvoice($db, $invoiceId, true);
        if (!$inv) throw new RuntimeException('Invoice not found.');
        assertDraft($inv);
        $lines = invoiceLines($db, $invoiceId);
        if (!$lines) throw new RuntimeException('Add at least one item.');
        $number = nextDocumentNumber($db, 'invoice', (int)$inv['branch_id']);
        $db->prepare("UPDATE invoices SET number = ?, status = 'issued', issued_at = NOW(), issued_by = ? WHERE id = ?")->execute([$number, currentStaffId() ?: null, $invoiceId]);
        foreach ($lines as $l) {
            if ($l['kind'] === 'package') for ($n = 0; $n < (int)$l['qty']; $n++) sellPackage($db, (int)$inv['patient_id'], (int)$l['package_id'], (int)$l['id'], intdiv(fils($l['line_total']), max(1, (int)$l['qty'])));
            if ($l['kind'] === 'product') sellProduct($db, (int)$l['product_id'], (float)$l['qty'], $number);
        }
        refreshInvoice($db, $invoiceId);
        if ($inv['type'] === 'insurance') { require_once __DIR__ . '/insurance.php'; createClaimForInvoice($db, $invoiceId); }
        storeRendition($db, 'invoice', $invoiceId);
        auditLog($db, 'invoice', $invoiceId, 'issued', ['number' => $number, 'total' => $inv['total']]);
        return $number;
    });
}

/** Takes sold retail stock (stock units) from the earliest-expiring usable batches. */
function sellProduct(PDO $db, int $productId, float $qtyStockUnits, string $ref): void {
    $p = lockProduct($db, $productId);
    $want = round($qtyStockUnits * max(1, (float)$p['units_per_stock']), 3);
    $lots = usableLots($db, $productId);
    if (array_sum(array_column($lots, 'qty_on_hand')) + 0.0005 < $want) throw new RuntimeException("Not enough {$p['name']} in stock to sell " . fmtQty($qtyStockUnits) . '.');
    foreach ($lots as $lot) {
        if ($want <= 0.0005) break;
        $take = min($want, (float)$lot['qty_on_hand']);
        addMovement($db, ['product_id' => $productId, 'lot_id' => $lot['id'], 'type' => 'consume', 'qty_change' => -$take, 'reason' => 'sale', 'note' => $ref]);
        $want = round($want - $take, 3);
    }
}

/** paid / credited / refunded / status from the receipts and credit notes behind an issued invoice. */
function refreshInvoice(PDO $db, int $invoiceId): void {
    $db->prepare("UPDATE invoices i SET
        paid = (SELECT COALESCE(SUM(a.amount), 0) FROM receipt_allocations a JOIN receipts r ON r.id = a.receipt_id AND r.status = 'valid' WHERE a.invoice_id = i.id),
        credited = (SELECT COALESCE(SUM(c.total), 0) FROM credit_notes c WHERE c.invoice_id = i.id),
        refunded = (SELECT COALESCE(SUM(c.refunded), 0) FROM credit_notes c WHERE c.invoice_id = i.id),
        insurer_paid = (SELECT COALESCE(SUM(a.amount), 0) FROM receipt_allocations a JOIN receipts r ON r.id = a.receipt_id AND r.status = 'valid'
                        WHERE a.invoice_id = i.id AND EXISTS (SELECT 1 FROM receipt_methods m WHERE m.receipt_id = r.id AND m.method = 'insurance'))
        WHERE i.id = ? AND i.status <> 'draft'")->execute([$invoiceId]);
    $inv = findInvoice($db, $invoiceId);
    if (in_array($inv['status'], ['draft', 'void'], true)) return;
    $due = fils($inv['total']) - fils($inv['credited']);
    $in = fils($inv['paid']) - fils($inv['refunded']);
    $status = $in <= 0 && $due > 0 ? 'issued' : ($in < $due ? 'partially_paid' : 'paid');
    $db->prepare("UPDATE invoices SET status = ? WHERE id = ?")->execute([$status, $invoiceId]);
    if ($status === 'paid' && $inv['status'] !== 'paid' && empty($inv['imported'])) {   // Meta "Purchase" for a lead-sourced patient's first paid invoice (net amount only)
        $lead = $db->query("SELECT lead_id FROM patients WHERE id = " . (int)$inv['patient_id'])->fetchColumn();
        if ($lead) { try { require_once __DIR__ . '/meta_leads.php'; metaQueueEvent($db, (int)$lead, 'purchase', 'Purchase', (float)$inv['net']); } catch (Throwable $e) { appLog('Meta Purchase event: ' . $e->getMessage()); } }
    }
}

/** Deletes a draft (it has no number, so nothing is lost from the sequence). */
function deleteDraftInvoice(PDO $db, int $invoiceId): void {
    stockTx($db, function () use ($db, $invoiceId) {
        $inv = findInvoice($db, $invoiceId, true);
        if (!$inv) throw new RuntimeException('Invoice not found.');
        assertDraft($inv);
        $db->prepare("DELETE FROM invoice_lines WHERE invoice_id = ?")->execute([$invoiceId]);
        $db->prepare("DELETE FROM invoices WHERE id = ?")->execute([$invoiceId]);
    });
}

/** Voids an issued invoice that has no payments or credit notes (billing.void). Its packages must be unused. */
function voidInvoice(PDO $db, int $invoiceId, string $reason): void {
    if (!can('billing.void')) throw new RuntimeException('You don’t have permission to void invoices.');
    $reason = trim($reason);
    if ($reason === '') throw new RuntimeException('Say why the invoice is void.');
    if ($db->query("SELECT imported FROM invoices WHERE id = " . (int)$invoiceId)->fetchColumn() === 'history') throw new RuntimeException('This invoice was issued in ClinicSoft: void it there. Here it is a record only.');
    stockTx($db, function () use ($db, $invoiceId, $reason) {
        $inv = findInvoice($db, $invoiceId, true);
        if (!$inv || $inv['status'] === 'void') throw new RuntimeException('Invoice not found or already void.');
        if ($inv['status'] === 'draft') throw new RuntimeException('A draft has no number yet: delete it instead.');
        if (fils($inv['paid']) > 0 || fils($inv['credited']) > 0) throw new RuntimeException('This invoice has payments or credit notes. Void the receipt first, or issue a credit note.');
        $used = $db->prepare("SELECT COUNT(*) FROM package_usages u JOIN patient_packages p ON p.id = u.patient_package_id JOIN invoice_lines l ON l.id = p.invoice_line_id WHERE l.invoice_id = ?");
        $used->execute([$invoiceId]);
        if ($used->fetchColumn()) throw new RuntimeException('A package on this invoice has been used. Issue a credit note instead.');
        $db->prepare("UPDATE patient_packages p JOIN invoice_lines l ON l.id = p.invoice_line_id SET p.status = 'cancelled' WHERE l.invoice_id = ?")->execute([$invoiceId]);
        foreach (invoiceLines($db, $invoiceId) as $l) if ($l['kind'] === 'product') returnSoldProduct($db, (int)$l['product_id'], $inv['number']);
        $cl = $db->prepare("SELECT status FROM claims WHERE invoice_id = ?"); $cl->execute([$invoiceId]); $cs = $cl->fetchColumn();
        if ($cs !== false && !in_array($cs, ['draft', 'ready'], true)) throw new RuntimeException('Its claim was already sent to the insurer. Settle the claim first.');
        if ($cs !== false) $db->prepare("UPDATE claims SET status = 'written_off', notes = CONCAT_WS(' · ', notes, 'Invoice void') WHERE invoice_id = ?")->execute([$invoiceId]);
        $db->prepare("UPDATE invoices SET status = 'void', void_reason = ?, voided_by = ?, voided_at = NOW() WHERE id = ?")->execute([mb_substr($reason, 0, 255), currentStaffId() ?: null, $invoiceId]);
        auditLog($db, 'invoice', $invoiceId, 'voided', ['number' => $inv['number'], 'reason' => $reason]);
    });
}

/** Puts sold stock back into the batches it came from (void / credit of a product line). */
function returnSoldProduct(PDO $db, int $productId, string $ref, ?float $qtyStockUnits = null): void {
    $p = lockProduct($db, $productId);
    $s = $db->prepare("SELECT lot_id, -SUM(qty_change) AS q FROM stock_movements WHERE product_id = ? AND reason = 'sale' AND note = ? GROUP BY lot_id HAVING q > 0");
    $s->execute([$productId, $ref]);
    $left = $qtyStockUnits === null ? INF : $qtyStockUnits * max(1, (float)$p['units_per_stock']);
    foreach ($s as $r) {
        $back = min((float)$r['q'], $left);
        if ($back <= 0.0005) break;
        addMovement($db, ['product_id' => $productId, 'lot_id' => $r['lot_id'], 'type' => 'return', 'qty_change' => $back, 'reason' => 'sale_return', 'note' => $ref]);
        $left -= $back;
    }
}

// ── Receipts and the wallet ──

function walletBalance(PDO $db, int $patientId): int {
    $s = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM patient_wallet WHERE patient_id = ?");
    $s->execute([$patientId]);
    return fils($s->fetchColumn());
}

function walletEntry(PDO $db, int $patientId, string $type, int $amountFils, array $ref = []): void {
    $db->prepare("INSERT INTO patient_wallet (patient_id, type, amount, method, receipt_id, invoice_id, credit_note_id, branch_id, note, staff_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
       ->execute([$patientId, $type, aed($amountFils), $ref['method'] ?? null, $ref['receipt_id'] ?? null, $ref['invoice_id'] ?? null, $ref['credit_note_id'] ?? null,
                  $ref['branch_id'] ?? null, $ref['note'] ?? null, currentStaffId() ?: null]);
}

/** What a voucher has left, in fils (its value less what valid receipts took from it). */
function voucherBalance(PDO $db, int $voucherId): int {
    $s = $db->prepare("SELECT v.initial - COALESCE((SELECT SUM(m.amount) FROM receipt_methods m JOIN receipts r ON r.id = m.receipt_id AND r.status = 'valid' WHERE m.voucher_id = v.id), 0) FROM vouchers v WHERE v.id = ?");
    $s->execute([$voucherId]);
    return fils($s->fetchColumn());
}

function findVoucher(PDO $db, string $code): ?array {
    $s = $db->prepare("SELECT * FROM vouchers WHERE code = ?");
    $s->execute([strtoupper(trim($code))]);
    return $s->fetch() ?: null;
}

/**
 * Takes a payment. $methods = [['method' => , 'amount' => , 'reference' => , 'cheque_date' => , 'voucher_code' => ]],
 * $alloc = [invoice_id => amount]. Whatever isn't allocated goes to the patient's wallet. Returns the receipt id.
 * $opt: voucher_id (this receipt sells a voucher: no allocation, no wallet), notes, received_at (tests).
 */
function createReceipt(PDO $db, ?int $patientId, int $branchId, array $methods, array $alloc, array $opt = []): int {
    $methods = array_values(array_filter($methods, fn($m) => fils($m['amount'] ?? 0) !== 0));
    if (!$methods) throw new RuntimeException('Enter the amount received.');
    $total = 0;
    foreach ($methods as $m) {
        if (!isset(PAY_METHODS[$m['method'] ?? ''])) throw new RuntimeException('Choose how the patient paid.');
        if (fils($m['amount']) <= 0) throw new RuntimeException('Amounts must be above zero.');
        $total += fils($m['amount']);
    }
    $alloc = array_filter(array_map('fils', $alloc), fn($v) => $v !== 0);
    if (array_filter($alloc, fn($v) => $v < 0)) throw new RuntimeException('Allocations can’t be negative.');
    $allocated = array_sum($alloc);
    if ($allocated > $total) throw new RuntimeException('More is allocated to invoices than was received.');
    if (!empty($opt['voucher_id']) && $alloc) throw new RuntimeException('A voucher sale is not paid against invoices.');
    if (!$patientId && empty($opt['voucher_id'])) throw new RuntimeException('Choose the patient.');
    $walletUse = array_sum(array_map(fn($m) => $m['method'] === 'wallet' ? fils($m['amount']) : 0, $methods));
    $insurer = in_array('insurance', array_column($methods, 'method'), true);
    if ($insurer && (count($methods) > 1 || empty($opt['claim']))) throw new RuntimeException('Insurance payments are recorded on the claim (remittance).');
    if ($walletUse > $allocated) throw new RuntimeException('Money from the patient’s balance can only pay invoices.');

    return stockTx($db, function () use ($db, $patientId, $branchId, $methods, $alloc, $opt, $total, $allocated, $walletUse, $insurer) {
        if ($patientId) $db->prepare("SELECT id FROM patients WHERE id = ? FOR UPDATE")->execute([$patientId]);   // one payment per patient at a time (wallet)
        if ($walletUse && $walletUse > walletBalance($db, $patientId)) throw new RuntimeException('The patient’s balance is only ' . money(walletBalance($db, $patientId) / 100) . '.');
        foreach ($alloc as $invId => $amt) {
            $inv = findInvoice($db, (int)$invId, true);
            if (!$inv || (int)$inv['patient_id'] !== $patientId || in_array($inv['status'], ['draft', 'void'], true)) throw new RuntimeException('An invoice in this payment is not open for this patient.');
            $limit = $insurer ? invoiceBalance($inv) : invoicePatientDue($inv);   // the patient pays their share; the insurer the rest
            if ($amt > $limit) throw new RuntimeException("{$inv['number']}: only " . money($limit / 100) . ' is owed' . ($inv['type'] === 'insurance' && !$insurer ? ' by the patient' : '') . '.');
        }
        $number = nextDocumentNumber($db, 'receipt', $branchId);
        $db->prepare("INSERT INTO receipts (number, branch_id, patient_id, voucher_id, total, received_at, received_by, notes) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$number, $branchId, $patientId, $opt['voucher_id'] ?? null, aed($total), $opt['received_at'] ?? date('Y-m-d H:i:s'), currentStaffId() ?: null, mb_substr(trim((string)($opt['notes'] ?? '')), 0, 255) ?: null]);
        $rid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO receipt_methods (receipt_id, method, amount, reference, cheque_date, voucher_id) VALUES (?,?,?,?,?,?)");
        foreach ($methods as $m) {
            $vid = null;
            if ($m['method'] === 'voucher') {
                $v = findVoucher($db, (string)($m['voucher_code'] ?? $m['reference'] ?? ''));
                if (!$v || $v['status'] !== 'active' || $v['expires_on'] < date('Y-m-d')) throw new RuntimeException('That voucher code is not valid or has expired.');
                $db->prepare("SELECT id FROM vouchers WHERE id = ? FOR UPDATE")->execute([$v['id']]);
                if (fils($m['amount']) > voucherBalance($db, (int)$v['id'])) throw new RuntimeException("Voucher {$v['code']} has only " . money(voucherBalance($db, (int)$v['id']) / 100) . ' left.');
                $vid = (int)$v['id']; $m['reference'] = $v['code'];
            }
            if ($m['method'] === 'cheque' && empty($m['reference'])) throw new RuntimeException('Enter the cheque number.');
            $ins->execute([$rid, $m['method'], aed(fils($m['amount'])), mb_substr(trim((string)($m['reference'] ?? '')), 0, 100) ?: null, ($m['cheque_date'] ?? '') ?: null, $vid]);
        }
        $insA = $db->prepare("INSERT INTO receipt_allocations (receipt_id, invoice_id, amount) VALUES (?,?,?)");
        foreach ($alloc as $invId => $amt) { $insA->execute([$rid, $invId, aed($amt)]); refreshInvoice($db, (int)$invId); }
        if ($walletUse) walletEntry($db, $patientId, 'allocation', -$walletUse, ['receipt_id' => $rid, 'branch_id' => $branchId, 'note' => 'Paid invoices from balance']);
        if ($insurer && $total !== $allocated) throw new RuntimeException('An insurance payment must be allocated to the claim’s invoice.');
        if (empty($opt['voucher_id']) && $total - $allocated > 0) walletEntry($db, $patientId, 'deposit', $total - $allocated, ['receipt_id' => $rid, 'branch_id' => $branchId, 'note' => 'Advance / not allocated']);
        storeRendition($db, 'receipt', $rid);
        auditLog($db, 'receipt', $rid, 'received', ['number' => $number, 'total' => aed($total)]);
        return $rid;
    });
}

function findReceipt(PDO $db, int $id): ?array {
    $s = $db->prepare("SELECT r.*, s.name AS received_by_name, b.name AS branch_name FROM receipts r LEFT JOIN staff s ON s.id = r.received_by LEFT JOIN branches b ON b.id = r.branch_id WHERE r.id = ?");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** Voids a receipt (billing.void; after the day was closed, admins only). Its allocations and wallet entries are reversed. */
function voidReceipt(PDO $db, int $receiptId, string $reason): void {
    if (!can('billing.void')) throw new RuntimeException('You don’t have permission to void receipts.');
    $reason = trim($reason);
    if ($reason === '') throw new RuntimeException('Say why the receipt is void.');
    stockTx($db, function () use ($db, $receiptId, $reason) {
        $r = $db->prepare("SELECT * FROM receipts WHERE id = ? FOR UPDATE");
        $r->execute([$receiptId]);
        $r = $r->fetch();
        if (!$r || $r['status'] !== 'valid') throw new RuntimeException('Receipt not found or already void.');
        if ($r['day_close_id'] && !isAdmin()) throw new RuntimeException('That day is closed. Only an admin can void this receipt.');
        if ($r['patient_id']) {
            $db->prepare("SELECT id FROM patients WHERE id = ? FOR UPDATE")->execute([$r['patient_id']]);
            $w = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM patient_wallet WHERE receipt_id = ?");
            $w->execute([$receiptId]);
            $net = fils($w->fetchColumn());
            if ($net > 0 && walletBalance($db, (int)$r['patient_id']) < $net) throw new RuntimeException('The advance from this receipt has already been spent. Void the later receipt first.');
            if ($net !== 0) walletEntry($db, (int)$r['patient_id'], 'adjustment', -$net, ['receipt_id' => $receiptId, 'branch_id' => $r['branch_id'], 'note' => 'Receipt ' . $r['number'] . ' void']);
        }
        if ($r['voucher_id']) {
            $used = $db->prepare("SELECT COUNT(*) FROM receipt_methods m JOIN receipts x ON x.id = m.receipt_id AND x.status = 'valid' WHERE m.voucher_id = ?");
            $used->execute([$r['voucher_id']]);
            if ($used->fetchColumn()) throw new RuntimeException('The voucher sold on this receipt has been used.');
            $db->prepare("UPDATE vouchers SET status = 'void' WHERE id = ?")->execute([$r['voucher_id']]);
        }
        $db->prepare("UPDATE receipts SET status = 'void', void_reason = ?, voided_by = ?, voided_at = NOW() WHERE id = ?")->execute([mb_substr($reason, 0, 255), currentStaffId() ?: null, $receiptId]);
        $a = $db->prepare("SELECT invoice_id FROM receipt_allocations WHERE receipt_id = ?");
        $a->execute([$receiptId]);
        foreach ($a->fetchAll(PDO::FETCH_COLUMN) as $invId) refreshInvoice($db, (int)$invId);
        auditLog($db, 'receipt', $receiptId, 'voided', ['number' => $r['number'], 'reason' => $reason]);
    });
}

/** Pays money out of the patient's balance (billing.refund). */
function refundWallet(PDO $db, int $patientId, int $branchId, $amount, string $method, string $note): void {
    if (!can('billing.refund')) throw new RuntimeException('You don’t have permission to refund.');
    if (!isset(REFUND_METHODS[$method])) throw new RuntimeException('Choose how the money is given back.');
    $amt = fils($amount);
    if ($amt <= 0) throw new RuntimeException('Enter the amount to refund.');
    stockTx($db, function () use ($db, $patientId, $branchId, $amt, $method, $note) {
        $db->prepare("SELECT id FROM patients WHERE id = ? FOR UPDATE")->execute([$patientId]);
        if ($amt > walletBalance($db, $patientId)) throw new RuntimeException('That is more than the patient’s balance.');
        walletEntry($db, $patientId, 'refund', -$amt, ['method' => $method, 'branch_id' => $branchId, 'note' => mb_substr(trim($note), 0, 255) ?: 'Refund']);
        auditLog($db, 'patient', $patientId, 'wallet_refund', ['amount' => aed($amt), 'method' => $method]);
    });
}

// ── Credit notes ──

/**
 * Credits lines of an issued invoice (billing.refund). $lines = [invoice_line_id => qty]. $settle: what happens to money
 * already paid beyond the new amount due: 'cash' | 'card' | 'bank_transfer' (given back) or 'wallet' (kept as balance).
 */
function issueCreditNote(PDO $db, int $invoiceId, array $lines, string $reason, string $settle = 'wallet'): int {
    if (!can('billing.refund')) throw new RuntimeException('You don’t have permission to issue credit notes.');
    $reason = trim($reason);
    if ($reason === '') throw new RuntimeException('Say why you are crediting this invoice.');
    if ($db->query("SELECT imported FROM invoices WHERE id = " . (int)$invoiceId)->fetchColumn()) throw new RuntimeException('Imported from ClinicSoft: no credit note here. Correct an opening balance by voiding it and importing the right amount.');
    if (!in_array($settle, ['cash', 'card', 'bank_transfer', 'wallet'], true)) throw new RuntimeException('Choose how to settle money already paid.');
    return stockTx($db, function () use ($db, $invoiceId, $lines, $reason, $settle) {
        $inv = findInvoice($db, $invoiceId, true);
        if (!$inv || in_array($inv['status'], ['draft', 'void'], true)) throw new RuntimeException('Only issued invoices can be credited.');
        $byId = array_column(invoiceLines($db, $invoiceId), null, 'id');
        $rows = []; $net = 0; $vat = 0;
        foreach ($lines as $lineId => $qty) {
            $qty = round((float)$qty, 2);
            if ($qty <= 0) continue;
            $l = $byId[(int)$lineId] ?? null;
            if (!$l) throw new RuntimeException('A line is not on this invoice.');
            if ($qty > (float)$l['qty'] - (float)$l['credited_qty'] + 0.001) throw new RuntimeException("“{$l['description']}”: only " . fmtQty((float)$l['qty'] - (float)$l['credited_qty']) . ' left to credit.');
            // The share of the line's taxable amount and VAT; the last part takes what is left so the line credits exactly
            $last = abs((float)$l['qty'] - (float)$l['credited_qty'] - $qty) < 0.001;
            $prev = $db->prepare("SELECT COALESCE(SUM(amount), 0), COALESCE(SUM(vat_amount), 0) FROM credit_note_lines WHERE invoice_line_id = ?");
            $prev->execute([$l['id']]);
            [$pa, $pv] = $prev->fetch(PDO::FETCH_NUM);
            $a = $last ? fils($l['taxable']) - fils($pa) : (int)round(fils($l['taxable']) * $qty / (float)$l['qty']);
            $v = $last ? fils($l['vat_amount']) - fils($pv) : (int)round(fils($l['vat_amount']) * $qty / (float)$l['qty']);
            $rows[] = [$l, $qty, $a, $v, $last];
            $net += $a; $vat += $v;
        }
        if (!$rows) throw new RuntimeException('Choose what to credit.');
        $total = $net + $vat;
        // Money already paid beyond what is now due is given back or kept as balance
        $dueAfter = fils($inv['total']) - fils($inv['credited']) - $total;
        $excess = max(0, fils($inv['paid']) - fils($inv['refunded']) - max(0, $dueAfter));
        $number = nextDocumentNumber($db, 'credit_note', (int)$inv['branch_id']);
        $db->prepare("INSERT INTO credit_notes (number, invoice_id, branch_id, reason, gross, vat, total, refund_method, refunded, issued_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$number, $invoiceId, $inv['branch_id'], mb_substr($reason, 0, 255), aed($net), aed($vat), aed($total), $excess ? $settle : null, aed($excess), currentStaffId() ?: null]);
        $cn = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO credit_note_lines (credit_note_id, invoice_line_id, qty, amount, vat_amount) VALUES (?,?,?,?,?)");
        foreach ($rows as [$l, $qty, $a, $v, $last]) {
            $ins->execute([$cn, $l['id'], $qty, aed($a), aed($v)]);
            if ($l['kind'] === 'product') returnSoldProduct($db, (int)$l['product_id'], $inv['number'], $qty);
            if ($l['kind'] === 'package' && $last) $db->prepare("UPDATE patient_packages SET status = 'cancelled' WHERE invoice_line_id = ? AND status = 'active'")->execute([$l['id']]);
        }
        if ($excess && $settle === 'wallet') walletEntry($db, (int)$inv['patient_id'], 'deposit', $excess, ['credit_note_id' => $cn, 'invoice_id' => $invoiceId, 'branch_id' => $inv['branch_id'], 'note' => 'Credit note ' . $number]);
        refreshInvoice($db, $invoiceId);
        storeRendition($db, 'credit_note', $cn);
        auditLog($db, 'invoice', $invoiceId, 'credited', ['credit_note' => $number, 'total' => aed($total), 'reason' => $reason, 'refunded' => aed($excess), 'settle' => $settle]);
        return $cn;
    });
}

// ── Packages ──

function packageList(PDO $db, bool $activeOnly = true): array {
    return $db->query("SELECT p.*, (SELECT GROUP_CONCAT(CONCAT(i.sessions, ' × ', s.name) ORDER BY s.name SEPARATOR ', ') FROM package_items i JOIN services s ON s.id = i.service_id WHERE i.package_id = p.id) AS contents
                       FROM packages p" . ($activeOnly ? ' WHERE p.is_active = 1' : '') . " ORDER BY p.is_active DESC, p.name")->fetchAll();
}

/** Creates or updates a package. $items = [service_id => sessions]. */
function savePackage(PDO $db, int $id, array $in, array $items): int {
    if (!can('billing.packages_manage')) throw new RuntimeException('You don’t have permission to manage packages.');
    $name = trim((string)($in['name'] ?? ''));
    $items = array_filter(array_map('intval', $items), fn($n) => $n > 0);
    if ($name === '' || !$items) throw new RuntimeException('Give the package a name and at least one treatment with sessions.');
    $price = fils($in['price'] ?? 0); $days = (int)($in['validity_days'] ?? 365); $vat = (float)($in['vat_rate'] ?? 5);
    if ($price <= 0 || $days < 1 || $days > 3650 || !in_array($vat, [0.0, 5.0], true)) throw new RuntimeException('Check the price, VAT (5% or 0%) and validity (1–3650 days).');
    return stockTx($db, function () use ($db, $id, $in, $items, $name, $price, $days, $vat) {
        $f = [$name, trim((string)($in['name_ar'] ?? '')) ?: null, aed($price), $vat, $days, empty($in['is_active']) ? 0 : 1];
        if ($id) $db->prepare("UPDATE packages SET name = ?, name_ar = ?, price = ?, vat_rate = ?, validity_days = ?, is_active = ? WHERE id = ?")->execute([...$f, $id]);
        else { $db->prepare("INSERT INTO packages (name, name_ar, price, vat_rate, validity_days, is_active) VALUES (?,?,?,?,?,?)")->execute($f); $id = (int)$db->lastInsertId(); }
        $db->prepare("DELETE FROM package_items WHERE package_id = ?")->execute([$id]);
        $ins = $db->prepare("INSERT INTO package_items (package_id, service_id, sessions) VALUES (?,?,?)");
        foreach ($items as $sid => $n) $ins->execute([$id, $sid, $n]);
        return $id;
    });
}

/** At issue: the patient gets the package's sessions. $valueFils = what one package cost (with VAT). */
function sellPackage(PDO $db, int $patientId, int $packageId, int $lineId, int $valueFils): int {
    $p = $db->prepare("SELECT * FROM packages WHERE id = ?");
    $p->execute([$packageId]);
    $p = $p->fetch();
    $db->prepare("INSERT INTO patient_packages (patient_id, package_id, invoice_line_id, value, starts_on, expires_on) VALUES (?,?,?,?, CURDATE(), CURDATE() + INTERVAL ? DAY)")
       ->execute([$patientId, $packageId, $lineId, aed($valueFils), (int)$p['validity_days']]);
    $ppId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO patient_package_sessions (patient_package_id, service_id, total) SELECT ?, service_id, sessions FROM package_items WHERE package_id = ?")->execute([$ppId, $packageId]);
    return $ppId;
}

/** A patient's packages with what is left of each service. $activeOnly: usable today. */
function patientPackages(PDO $db, int $patientId, bool $activeOnly = false): array {
    $s = $db->prepare("SELECT pp.*, pk.name, (SELECT GROUP_CONCAT(CONCAT(sv.name, ': ', ss.total - ss.used, ' of ', ss.total, ' left') SEPARATOR ' · ') FROM patient_package_sessions ss JOIN services sv ON sv.id = ss.service_id WHERE ss.patient_package_id = pp.id) AS sessions
                       FROM patient_packages pp JOIN packages pk ON pk.id = pp.package_id WHERE pp.patient_id = ?" . ($activeOnly ? " AND pp.status = 'active' AND pp.expires_on >= CURDATE()" : '') . " ORDER BY pp.status = 'active' DESC, pp.expires_on");
    $s->execute([$patientId]);
    return $s->fetchAll();
}

/** Books a visit against one of the patient's packages (or clears it with null). The package must cover a booked service. */
function setAppointmentPackage(PDO $db, int $apptId, ?int $ppId): void {
    $a = $db->prepare("SELECT * FROM appointments WHERE id = ?");
    $a->execute([$apptId]);
    $a = $a->fetch();
    if (!$a || !in_array($a['status'], APPT_OPEN, true)) throw new RuntimeException('Only open appointments can use a package.');
    if ($ppId) {
        $s = $db->prepare("SELECT COUNT(*) FROM patient_packages pp JOIN patient_package_sessions ss ON ss.patient_package_id = pp.id
                           WHERE pp.id = ? AND pp.patient_id = ? AND pp.status = 'active' AND pp.expires_on >= CURDATE() AND ss.used < ss.total
                             AND ss.service_id IN (SELECT service_id FROM appointment_services WHERE appointment_id = ? UNION SELECT ?)");
        $s->execute([$ppId, $a['patient_id'], $apptId, $a['service_id']]);
        if (!$s->fetchColumn()) throw new RuntimeException('That package has no sessions left for this visit’s treatments.');
    }
    $db->prepare("UPDATE appointments SET patient_package_id = ? WHERE id = ?")->execute([$ppId ?: null, $apptId]);
}

/** Marks packages past their date as expired (cron). Returns how many. */
function expirePackages(PDO $db): int {
    return $db->exec("UPDATE patient_packages SET status = 'expired' WHERE status = 'active' AND expires_on < CURDATE()");
}

/** Unused package value: what was paid × sessions left / sessions bought, per active package. */
function packageLiability(PDO $db): array {
    return $db->query("SELECT pp.id, pp.patient_id, pp.expires_on, pk.name, pp.value, SUM(ss.total) AS total, SUM(ss.used) AS used,
                              ROUND(pp.value * (SUM(ss.total) - SUM(ss.used)) / NULLIF(SUM(ss.total), 0), 2) AS unused_value
                       FROM patient_packages pp JOIN packages pk ON pk.id = pp.package_id JOIN patient_package_sessions ss ON ss.patient_package_id = pp.id
                       WHERE pp.status = 'active' GROUP BY pp.id HAVING total > used ORDER BY pp.expires_on")->fetchAll();
}

// ── Vouchers ──

/** Sells a voucher: a receipt for its value (no VAT now: VAT is charged on what it is spent on). Returns [voucher id, code]. */
function sellVoucher(PDO $db, int $branchId, $value, string $expiresOn, array $methods, ?int $patientId = null, string $recipient = ''): array {
    $v = fils($value);
    if ($v <= 0) throw new RuntimeException('Enter the voucher value.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresOn) || $expiresOn <= date('Y-m-d')) throw new RuntimeException('Choose an expiry date in the future.');
    if (array_sum(array_map(fn($m) => fils($m['amount'] ?? 0), $methods)) !== $v) throw new RuntimeException('The payment must equal the voucher value.');
    foreach ($methods as $m) if (in_array($m['method'] ?? '', ['wallet', 'voucher'], true)) throw new RuntimeException('Vouchers are paid in money.');
    return stockTx($db, function () use ($db, $branchId, $v, $expiresOn, $methods, $patientId, $recipient) {
        do { $code = 'GV' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8)); } while (findVoucher($db, $code));
        $db->prepare("INSERT INTO vouchers (code, initial, expires_on, patient_id, recipient, created_by) VALUES (?,?,?,?,?,?)")
           ->execute([$code, aed($v), $expiresOn, $patientId, mb_substr(trim($recipient), 0, 150) ?: null, currentStaffId() ?: null]);
        $vid = (int)$db->lastInsertId();
        createReceipt($db, $patientId, $branchId, $methods, [], ['voucher_id' => $vid, 'notes' => 'Gift voucher ' . $code]);
        return [$vid, $code];
    });
}

function voucherLiability(PDO $db): int {
    $sum = 0;
    foreach ($db->query("SELECT id FROM vouchers WHERE status = 'active' AND expires_on >= CURDATE()")->fetchAll(PDO::FETCH_COLUMN) as $id) $sum += voucherBalance($db, (int)$id);
    return $sum;
}

// ── Discount rules ──

/** A rule that can be used today, or null. */
function discountRule(PDO $db, int $id): ?array {
    $s = $db->prepare("SELECT * FROM discount_rules WHERE id = ? AND is_active = 1 AND (valid_from IS NULL OR valid_from <= CURDATE()) AND (valid_to IS NULL OR valid_to >= CURDATE())");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function activeDiscountRules(PDO $db): array {
    return $db->query("SELECT * FROM discount_rules WHERE is_active = 1 AND (valid_from IS NULL OR valid_from <= CURDATE()) AND (valid_to IS NULL OR valid_to >= CURDATE()) ORDER BY name")->fetchAll();
}

// ── Day close ──

/** What should be in the drawer: per method, the receipts this cashier took at this branch that day (not yet closed),
 *  less refunds they paid out. Values in fils. Returns ['methods' => [method => fils], 'receipts' => n]. */
function dayCloseExpected(PDO $db, int $branchId, int $staffId, string $date): array {
    $exp = array_fill_keys(DRAWER_METHODS, 0);
    $s = $db->prepare("SELECT m.method, SUM(m.amount) AS amt, COUNT(DISTINCT r.id) AS n FROM receipts r JOIN receipt_methods m ON m.receipt_id = r.id
                       WHERE r.branch_id = ? AND r.received_by = ? AND DATE(r.received_at) = ? AND r.status = 'valid' AND r.day_close_id IS NULL GROUP BY m.method");
    $s->execute([$branchId, $staffId, $date]);
    $n = 0;
    foreach ($s as $r) { if (isset($exp[$r['method']])) $exp[$r['method']] += fils($r['amt']); $n = max($n, (int)$r['n']); }
    // Insurer payments (remittances) never pass through a drawer
    $c = $db->prepare("SELECT COUNT(*) FROM receipts WHERE branch_id = ? AND received_by = ? AND DATE(received_at) = ? AND status = 'valid' AND day_close_id IS NULL AND NOT EXISTS (SELECT 1 FROM receipt_methods xm WHERE xm.receipt_id = receipts.id AND xm.method = 'insurance')");
    $c->execute([$branchId, $staffId, $date]);
    foreach ([["SELECT method, SUM(-amount) FROM patient_wallet WHERE type = 'refund' AND branch_id = ? AND staff_id = ? AND DATE(at) = ? AND day_close_id IS NULL GROUP BY method"],
              ["SELECT refund_method, SUM(refunded) FROM credit_notes WHERE refund_method IN ('cash','card','bank_transfer') AND branch_id = ? AND issued_by = ? AND DATE(issued_at) = ? AND day_close_id IS NULL GROUP BY refund_method"]] as [$sql]) {
        $q = $db->prepare($sql); $q->execute([$branchId, $staffId, $date]);
        foreach ($q->fetchAll(PDO::FETCH_NUM) as [$m, $amt]) if (isset($exp[$m])) $exp[$m] -= fils($amt);
    }
    return ['methods' => $exp, 'receipts' => (int)$c->fetchColumn()];
}

/** Closes the cashier's day: counted vs expected per method; a difference needs a note. Locks the receipts. */
function closeDay(PDO $db, int $branchId, string $date, array $counted, string $note = ''): int {
    if (!can('billing.day_close')) throw new RuntimeException('You don’t have permission to close the day.');
    $staffId = currentStaffId();
    return stockTx($db, function () use ($db, $branchId, $staffId, $date, $counted, $note) {
        $db->prepare("SELECT id FROM branches WHERE id = ? FOR UPDATE")->execute([$branchId]);   // one close at a time per branch
        $e = dayCloseExpected($db, $branchId, $staffId, $date);
        $cnt = []; $diff = 0;
        foreach (DRAWER_METHODS as $m) { $cnt[$m] = fils($counted[$m] ?? 0); $diff += $cnt[$m] - $e['methods'][$m]; }
        if (!$e['receipts'] && !array_filter($e['methods']) && !array_filter($cnt)) throw new RuntimeException('Nothing to close: no payments taken.');
        if ($diff !== 0 && trim($note) === '') throw new RuntimeException('The count is ' . ($diff > 0 ? 'over' : 'short') . ' by ' . money(abs($diff) / 100) . '. Add a note explaining why.');
        $db->prepare("INSERT INTO day_closes (branch_id, staff_id, business_date, expected, counted, difference, note) VALUES (?,?,?,?,?,?,?)")
           ->execute([$branchId, $staffId, $date, json_encode(array_map('aed', $e['methods'])), json_encode(array_map('aed', $cnt)), aed($diff), mb_substr(trim($note), 0, 500) ?: null]);
        $id = (int)$db->lastInsertId();
        $db->prepare("UPDATE receipts SET day_close_id = ? WHERE branch_id = ? AND received_by = ? AND DATE(received_at) = ? AND day_close_id IS NULL AND NOT EXISTS (SELECT 1 FROM receipt_methods xm WHERE xm.receipt_id = receipts.id AND xm.method = 'insurance')")->execute([$id, $branchId, $staffId, $date]);
        $db->prepare("UPDATE patient_wallet SET day_close_id = ? WHERE type = 'refund' AND branch_id = ? AND staff_id = ? AND DATE(at) = ? AND day_close_id IS NULL")->execute([$id, $branchId, $staffId, $date]);
        $db->prepare("UPDATE credit_notes SET day_close_id = ? WHERE branch_id = ? AND issued_by = ? AND DATE(issued_at) = ? AND day_close_id IS NULL")->execute([$id, $branchId, $staffId, $date]);
        auditLog($db, 'day_close', $id, 'closed', ['date' => $date, 'difference' => aed($diff)]);
        return $id;
    });
}

// ── Commission ──

/** The rule for a practitioner and service: the service's own rule, else their rule for every service. */
function commissionRule(array $rules, int $staffId, ?int $serviceId): ?array {
    return $rules[$staffId][$serviceId ?? 0] ?? $rules[$staffId][0] ?? null;
}

/**
 * Commission for invoices issued in a month (Y-m). Percent rules are paid on the net (taxable) amount actually collected
 * (the invoice's paid share, after credit notes), less the visit's consumables cost when the rule says so; fixed rules
 * pay per unit of fully paid lines. Returns rows per practitioner with the lines behind them.
 */
function commissionReport(PDO $db, string $month): array {
    $rules = [];
    foreach ($db->query("SELECT * FROM commission_rules") as $r) $rules[(int)$r['staff_id']][(int)$r['service_id']] = $r;
    $s = $db->prepare("SELECT l.*, i.number, i.issued_at, i.total, i.paid, i.refunded, i.credited, i.appointment_id, st.name AS staff_name,
                              (SELECT COALESCE(SUM(c.amount), 0) FROM credit_note_lines c WHERE c.invoice_line_id = l.id) AS credited_net,
                              (SELECT COALESCE(SUM(c.qty), 0) FROM credit_note_lines c WHERE c.invoice_line_id = l.id) AS credited_qty
                       FROM invoice_lines l JOIN invoices i ON i.id = l.invoice_id JOIN staff st ON st.id = l.practitioner_id
                       WHERE i.status IN ('issued','partially_paid','paid') AND i.imported IS NULL AND DATE_FORMAT(i.issued_at, '%Y-%m') = ? ORDER BY st.name, i.issued_at");
    $s->execute([$month]);
    $consumables = [];
    $out = [];
    foreach ($s as $l) {
        $rule = commissionRule($rules, (int)$l['practitioner_id'], $l['service_id'] ? (int)$l['service_id'] : null);
        if (!$rule) continue;
        $due = fils($l['total']) - fils($l['credited']);
        $ratio = $due > 0 ? max(0, min(1, (fils($l['paid']) - fils($l['refunded'])) / $due)) : 0;
        $net = fils($l['taxable']) - fils($l['credited_net']);
        if ($rule['kind'] === 'percent_net') {
            $base = (int)round($net * $ratio);
            if ($rule['deduct_consumables'] && $l['appointment_id'] && $l['kind'] === 'service') {
                $consumables[$l['appointment_id']] ??= appointmentConsumablesCost($db, (int)$l['appointment_id']);
                $svcNet = $db->prepare("SELECT SUM(taxable) FROM invoice_lines WHERE invoice_id = ? AND kind = 'service'");
                $svcNet->execute([$l['invoice_id']]);
                $share = fils($svcNet->fetchColumn());
                $base -= $share > 0 ? (int)round($consumables[$l['appointment_id']] * fils($l['taxable']) / $share * $ratio) : 0;
            }
            $amt = (int)round(max(0, $base) * (float)$rule['value'] / 100);
        } else {
            $amt = $ratio >= 1 ? (int)round(fils($rule['value']) * ((float)$l['qty'] - (float)$l['credited_qty'])) : 0;
        }
        $pid = (int)$l['practitioner_id'];
        $out[$pid] ??= ['name' => $l['staff_name'], 'net' => 0, 'commission' => 0, 'lines' => []];
        $out[$pid]['net'] += $net; $out[$pid]['commission'] += $amt;
        $out[$pid]['lines'][] = ['number' => $l['number'], 'date' => $l['issued_at'], 'description' => $l['description'], 'net' => $net, 'collected' => $ratio, 'commission' => $amt];
    }
    return $out;
}

/** Cost of the stock used on a visit, in fils (from the batches' purchase cost). */
function appointmentConsumablesCost(PDO $db, int $apptId): int {
    $s = $db->prepare("SELECT COALESCE(SUM(-m.qty_change * l.unit_cost / NULLIF(p.units_per_stock, 0)), 0) FROM stock_movements m JOIN stock_lots l ON l.id = m.lot_id JOIN products p ON p.id = m.product_id
                       WHERE m.appointment_id = ? AND m.type = 'consume'");
    $s->execute([$apptId]);
    return fils($s->fetchColumn());
}

// ── Printed documents (frozen at issue) ──

/** Renders a document with its default layout and stores it as the original; reprints show it (doc_layouts.php). */
function storeRendition(PDO $db, string $type, int $id): void {
    require_once __DIR__ . '/doc_layouts.php';
    storeDocumentRendition($db, $type, $id, 'issue');
}

function documentRendition(PDO $db, string $type, int $id): ?array {
    $s = $db->prepare("SELECT * FROM document_renditions WHERE doc_type = ? AND doc_id = ? ORDER BY is_original DESC, id LIMIT 1");
    $s->execute([$type, $id]);
    return $s->fetch() ?: null;
}
