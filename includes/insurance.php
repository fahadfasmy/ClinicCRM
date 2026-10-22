<?php
// includes/insurance.php — insurance (Phase 9, manual workflow ready for DHA eClaimLink).
// Companies (payers / TPAs) → plans (co-pay %, deductible, cap per visit, services needing approval) → the plan's price
// list. Patient cards. Prior approvals. An insurance invoice splits each line into the patient's share and the
// insurer's; issuing it creates the claim. Claims are batched per company, exported as eClaimLink XML or CSV for the
// portal, marked submitted, then accepted / rejected (denial code) / paid through remittances (insurer receipts,
// payment method "insurance"). Direct web-service submission is outside the MVP.
require_once __DIR__ . '/billing.php';

const CLAIM_STATUS = ['draft' => ['Needs details', 'tone-amber'], 'ready' => ['Ready to send', 'tone-blue'], 'submitted' => ['Submitted', 'tone-violet'],
                      'accepted' => ['Accepted', 'tone-teal'], 'rejected' => ['Rejected', 'tone-red'], 'partially_paid' => ['Part paid', 'tone-orange'],
                      'paid' => ['Paid', 'tone-green'], 'resubmitted' => ['Resubmitted', 'tone-violet'], 'written_off' => ['Written off', 'tone-slate']];
const PA_STATUS = ['requested' => ['Requested', 'tone-amber'], 'approved' => ['Approved', 'tone-green'], 'partially_approved' => ['Partly approved', 'tone-teal'],
                   'rejected' => ['Rejected', 'tone-red'], 'expired' => ['Expired', 'tone-slate'], 'cancelled' => ['Cancelled', 'tone-slate']];
const EID_UNKNOWN = '999-9999-9999999-9';   // eClaimLink's code for "Emirates ID not available"; the insurance officer should confirm

// ── Companies, plans, price lists ──

function insuranceCompanies(PDO $db, bool $activeOnly = false): array {
    return $db->query("SELECT * FROM insurance_companies" . ($activeOnly ? ' WHERE is_active = 1' : '') . " ORDER BY is_active DESC, name")->fetchAll();
}

function saveInsuranceCompany(PDO $db, int $id, array $in): int {
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Give the company a name.');
    $f = [mb_substr($name, 0, 150), ($in['type'] ?? '') === 'tpa' ? 'tpa' : 'payer', mb_substr(trim((string)($in['payer_id'] ?? '')), 0, 30) ?: null,
          mb_substr(trim((string)($in['receiver_id'] ?? '')), 0, 30) ?: null, mb_substr(trim((string)($in['phone'] ?? '')), 0, 40) ?: null,
          mb_substr(trim((string)($in['email'] ?? '')), 0, 150) ?: null, empty($in['is_active']) ? 0 : 1];
    if ($id) $db->prepare("UPDATE insurance_companies SET name = ?, type = ?, payer_id = ?, receiver_id = ?, phone = ?, email = ?, is_active = ? WHERE id = ?")->execute([...$f, $id]);
    else { $db->prepare("INSERT INTO insurance_companies (name, type, payer_id, receiver_id, phone, email, is_active) VALUES (?,?,?,?,?,?,?)")->execute($f); $id = (int)$db->lastInsertId(); }
    auditLog($db, 'insurance_company', $id, 'saved', ['name' => $name]);
    return $id;
}

function insurancePlans(PDO $db, bool $activeOnly = false): array {
    return $db->query("SELECT p.*, c.name AS company, c.payer_id, c.receiver_id FROM insurance_plans p JOIN insurance_companies c ON c.id = p.company_id"
        . ($activeOnly ? ' WHERE p.is_active = 1 AND c.is_active = 1' : '') . " ORDER BY c.name, p.name")->fetchAll();
}

function findPlan(PDO $db, int $id): ?array {
    $s = $db->prepare("SELECT p.*, c.name AS company, c.payer_id, c.receiver_id FROM insurance_plans p JOIN insurance_companies c ON c.id = p.company_id WHERE p.id = ?");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function saveInsurancePlan(PDO $db, int $id, array $in): int {
    $name = trim((string)($in['name'] ?? ''));
    $co = (int)($in['company_id'] ?? 0);
    if ($name === '' || !$db->query("SELECT COUNT(*) FROM insurance_companies WHERE id = $co")->fetchColumn()) throw new RuntimeException('Choose the company and name the plan.');
    $copay = round((float)($in['copay_percent'] ?? 0), 2); $ded = round((float)($in['deductible'] ?? 0), 2);
    $max = ($in['max_copay'] ?? '') !== '' ? round((float)$in['max_copay'], 2) : null;
    if ($copay < 0 || $copay > 100 || $ded < 0 || ($max !== null && $max < 0)) throw new RuntimeException('Co-pay is 0–100%; deductible and cap can’t be negative.');
    $pre = array_values(array_filter(array_map('intval', (array)($in['preauth'] ?? []))));
    $f = [$co, mb_substr($name, 0, 150), mb_substr(trim((string)($in['network'] ?? '')), 0, 80) ?: null, $copay, $ded, $max, $pre ? json_encode($pre) : null, empty($in['is_active']) ? 0 : 1];
    if ($id) $db->prepare("UPDATE insurance_plans SET company_id = ?, name = ?, network = ?, copay_percent = ?, deductible = ?, max_copay = ?, preauth_service_ids = ?, is_active = ? WHERE id = ?")->execute([...$f, $id]);
    else { $db->prepare("INSERT INTO insurance_plans (company_id, name, network, copay_percent, deductible, max_copay, preauth_service_ids, is_active) VALUES (?,?,?,?,?,?,?,?)")->execute($f); $id = (int)$db->lastInsertId(); }
    auditLog($db, 'insurance_plan', $id, 'saved', ['name' => $name, 'copay' => $copay, 'deductible' => $ded, 'max' => $max]);
    return $id;
}

/** The plan's prices: [service_id => ['price' => , 'cpt_code' => ]]. */
function planPrices(PDO $db, int $planId): array {
    $s = $db->prepare("SELECT i.service_id, i.price, i.cpt_code FROM price_lists p JOIN price_list_items i ON i.price_list_id = p.id WHERE p.insurance_plan_id = ?");
    $s->execute([$planId]);
    $out = [];
    foreach ($s as $r) $out[(int)$r['service_id']] = ['price' => $r['price'], 'cpt_code' => $r['cpt_code']];
    return $out;
}

/** Saves the plan's price list. $rows = [service_id => ['price' => '' (= cash price) | amount, 'cpt_code' => ]]. */
function savePlanPrices(PDO $db, int $planId, array $rows, ?string $from = null, ?string $to = null): void {
    $plan = findPlan($db, $planId);
    if (!$plan) throw new RuntimeException('Plan not found.');
    stockTx($db, function () use ($db, $plan, $rows, $from, $to) {
        $db->prepare("INSERT INTO price_lists (name, insurance_plan_id, valid_from, valid_to) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name = VALUES(name), valid_from = VALUES(valid_from), valid_to = VALUES(valid_to)")
           ->execute([$plan['company'] . ' – ' . $plan['name'], $plan['id'], $from ?: null, $to ?: null]);
        $pl = (int)$db->query("SELECT id FROM price_lists WHERE insurance_plan_id = " . (int)$plan['id'])->fetchColumn();
        $db->prepare("DELETE FROM price_list_items WHERE price_list_id = ?")->execute([$pl]);
        $ins = $db->prepare("INSERT INTO price_list_items (price_list_id, service_id, price, cpt_code) VALUES (?,?,?,?)");
        foreach ($rows as $sid => $r) {
            if (trim((string)($r['price'] ?? '')) === '') continue;
            if ((float)$r['price'] < 0) throw new RuntimeException('Prices can’t be negative.');
            $ins->execute([$pl, (int)$sid, round((float)$r['price'], 2), mb_substr(trim((string)($r['cpt_code'] ?? '')), 0, 10) ?: null]);
        }
        auditLog($db, 'insurance_plan', (int)$plan['id'], 'prices_saved', ['items' => count(array_filter($rows, fn($r) => trim((string)($r['price'] ?? '')) !== ''))]);
    });
}

/**
 * The patient's part of each line, in fils. Covered lines: the deductible first, then the co-pay % of the rest, the
 * total limited by the plan's cap per visit, spread over the covered lines by size (the last takes the rounding).
 * Lines not covered are the patient's in full. $rows = [['id', 'total', 'covered']].
 */
function patientShares(array $rows, array $plan): array {
    $out = [];
    $covered = array_values(array_filter($rows, fn($r) => $r['covered']));
    $base = array_sum(array_column($covered, 'total'));
    $ded = min($base, fils($plan['deductible']));
    $pat = $ded + (int)round(($base - $ded) * (float)$plan['copay_percent'] / 100);
    if ($plan['max_copay'] !== null && $plan['max_copay'] !== '') $pat = min($pat, fils($plan['max_copay']));
    $pat = min($pat, $base);
    $left = $pat;
    foreach ($covered as $i => $r) {
        $share = $i === count($covered) - 1 ? $left : ($base ? (int)round($pat * $r['total'] / $base) : 0);
        $share = max(0, min($share, $r['total'], $left));
        $out[$r['id']] = $share; $left -= $share;
    }
    foreach ($rows as $r) if (!$r['covered']) $out[$r['id']] = $r['total'];
    return $out;
}

// ── Patient cards ──

function patientCards(PDO $db, int $patientId, bool $usableOnly = false): array {
    $s = $db->prepare("SELECT pi.*, p.name AS plan_name, p.copay_percent, c.name AS company FROM patient_insurance pi JOIN insurance_plans p ON p.id = pi.plan_id JOIN insurance_companies c ON c.id = p.company_id
                       WHERE pi.patient_id = ?" . ($usableOnly ? " AND pi.is_active = 1 AND (pi.valid_to IS NULL OR pi.valid_to >= CURDATE()) AND (pi.valid_from IS NULL OR pi.valid_from <= CURDATE())" : '') . " ORDER BY pi.is_active DESC, pi.is_primary DESC, pi.id DESC");
    $s->execute([$patientId]);
    return $s->fetchAll();
}

function savePatientCard(PDO $db, int $patientId, int $id, array $in): int {
    $plan = findPlan($db, (int)($in['plan_id'] ?? 0));
    $member = trim((string)($in['member_no'] ?? ''));
    if (!$plan || $member === '') throw new RuntimeException('Choose the plan and enter the member number.');
    $from = ($in['valid_from'] ?? '') ?: null; $to = ($in['valid_to'] ?? '') ?: null;
    if ($from && $to && $to < $from) throw new RuntimeException('The card ends before it starts.');
    $docs = [];
    foreach (['card_front_doc_id', 'card_back_doc_id'] as $k) {
        $d = (int)($in[$k] ?? 0) ?: null;
        if ($d) { $s = $db->prepare("SELECT COUNT(*) FROM patient_documents WHERE id = ? AND patient_id = ?"); $s->execute([$d, $patientId]); if (!$s->fetchColumn()) throw new RuntimeException('That card image belongs to another patient.'); }
        $docs[] = $d;
    }
    $primary = empty($in['is_primary']) ? 0 : 1;
    if ($id) { $s = $db->prepare("SELECT COUNT(*) FROM patient_insurance WHERE id = ? AND patient_id = ?"); $s->execute([$id, $patientId]); if (!$s->fetchColumn()) throw new RuntimeException('Card not found.'); }
    if ($primary) $db->prepare("UPDATE patient_insurance SET is_primary = 0 WHERE patient_id = ?")->execute([$patientId]);
    $f = [$plan['id'], mb_substr($member, 0, 60), mb_substr(trim((string)($in['policy_no'] ?? '')), 0, 60) ?: null, $from, $to, $primary, $docs[0], $docs[1], isset($in['is_active']) && !$in['is_active'] ? 0 : 1];
    if ($id) $db->prepare("UPDATE patient_insurance SET plan_id = ?, member_no = ?, policy_no = ?, valid_from = ?, valid_to = ?, is_primary = ?, card_front_doc_id = ?, card_back_doc_id = ?, is_active = ? WHERE id = ?")->execute([...$f, $id]);
    else { $db->prepare("INSERT INTO patient_insurance (plan_id, member_no, policy_no, valid_from, valid_to, is_primary, card_front_doc_id, card_back_doc_id, is_active, patient_id) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([...$f, $patientId]); $id = (int)$db->lastInsertId(); }
    auditLog($db, 'patient', $patientId, 'insurance_card_saved', ['card' => $id, 'plan' => $plan['company'] . ' – ' . $plan['name']]);
    return $id;
}

/** Warnings about a patient's cards: expired, or ending within 30 days. */
function cardAlerts(PDO $db, int $patientId): array {
    $out = [];
    foreach (patientCards($db, $patientId) as $c) {
        if (!$c['is_active'] || !$c['valid_to']) continue;
        if ($c['valid_to'] < date('Y-m-d')) $out[] = "Insurance card expired ({$c['company']}, " . date('d M Y', strtotime($c['valid_to'])) . ')';
        elseif ($c['valid_to'] <= date('Y-m-d', strtotime('+30 days'))) $out[] = "Insurance card ends " . date('d M Y', strtotime($c['valid_to'])) . " ({$c['company']})";
    }
    return $out;
}

// ── Insurance on an invoice ──

/** Makes a draft an insurance invoice on one of the patient's valid cards (or back to cash with null), repricing treatments. */
function setInvoiceInsurance(PDO $db, int $invoiceId, ?int $cardId): void {
    $inv = findInvoice($db, $invoiceId);
    if (!$inv) throw new RuntimeException('Invoice not found.');
    assertDraft($inv);
    if ($cardId) {
        $card = array_values(array_filter(patientCards($db, (int)$inv['patient_id'], true), fn($c) => (int)$c['id'] === $cardId))[0] ?? null;
        if (!$card) throw new RuntimeException('That card isn’t valid today. Check its dates on the patient’s Insurance tab.');
        $pa = $inv['appointment_id'] ? $db->query("SELECT prior_auth_id FROM appointments WHERE id = " . (int)$inv['appointment_id'])->fetchColumn() : null;
        $db->prepare("UPDATE invoices SET type = 'insurance', insurance_plan_id = ?, patient_insurance_id = ?, prior_auth_id = ? WHERE id = ?")->execute([$card['plan_id'], $cardId, $pa ?: null, $invoiceId]);
    } else {
        $db->prepare("UPDATE invoices SET type = 'cash', insurance_plan_id = NULL, patient_insurance_id = NULL, prior_auth_id = NULL WHERE id = ?")->execute([$invoiceId]);
    }
    // Treatments take the plan's price (or the cash price again); packages, products and other items keep theirs
    $prices = $cardId ? planPrices($db, (int)$card['plan_id']) : [];
    $up = $db->prepare("UPDATE invoice_lines l JOIN services s ON s.id = l.service_id SET l.unit_price = ?, l.cpt_code = ? WHERE l.id = ?");
    foreach (invoiceLines($db, $invoiceId) as $l) {
        if ($l['kind'] !== 'service' || $l['patient_package_id']) continue;
        $svc = $db->query("SELECT price, cpt_code FROM services WHERE id = " . (int)$l['service_id'])->fetch();
        $p = $prices[(int)$l['service_id']] ?? null;
        $up->execute([$p ? $p['price'] : $svc['price'], ($p['cpt_code'] ?? null) ?: $svc['cpt_code'], $l['id']]);
    }
    recalcInvoice($db, $invoiceId);
    auditLog($db, 'invoice', $invoiceId, $cardId ? 'insurance_set' : 'insurance_removed', $cardId ? ['card' => $cardId] : []);
}

function setLineCovered(PDO $db, int $invoiceId, int $lineId, bool $covered): void {
    $inv = findInvoice($db, $invoiceId);
    assertDraft($inv);
    $db->prepare("UPDATE invoice_lines SET covered = ? WHERE id = ? AND invoice_id = ?")->execute([$covered ? 1 : 0, $lineId, $invoiceId]);
    recalcInvoice($db, $invoiceId);
}

// ── Prior approvals ──

function findPriorAuth(PDO $db, int $id): ?array {
    $s = $db->prepare("SELECT pa.*, p.name AS plan_name, c.name AS company, pi.member_no FROM prior_auths pa JOIN insurance_plans p ON p.id = pa.plan_id JOIN insurance_companies c ON c.id = p.company_id
                       LEFT JOIN patient_insurance pi ON pi.id = pa.patient_insurance_id WHERE pa.id = ?");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** A new request: the patient's card, the services, ICD-10 codes, optionally the appointment. */
function requestPriorAuth(PDO $db, int $patientId, int $cardId, array $serviceIds, array $codes, ?int $apptId = null, string $notes = ''): int {
    $card = array_values(array_filter(patientCards($db, $patientId), fn($c) => (int)$c['id'] === $cardId))[0] ?? null;
    if (!$card) throw new RuntimeException('Choose one of the patient’s insurance cards.');
    $serviceIds = array_values(array_unique(array_filter(array_map('intval', $serviceIds))));
    if (!$serviceIds) throw new RuntimeException('Choose the treatments to approve.');
    $codes = array_values(array_unique(array_filter(array_map(fn($c) => strtoupper(trim((string)$c)), $codes))));
    foreach ($codes as $c) if (!preg_match('/^[A-Z][0-9][0-9A-Z](\.[0-9A-Z]{1,4})?$/', $c)) throw new RuntimeException("“{$c}” doesn’t look like an ICD-10 code (e.g. L70.0).");
    if ($apptId) { $s = $db->prepare("SELECT patient_id FROM appointments WHERE id = ?"); $s->execute([$apptId]); if ((int)$s->fetchColumn() !== $patientId) throw new RuntimeException('That appointment belongs to another patient.'); }
    $db->prepare("INSERT INTO prior_auths (patient_id, patient_insurance_id, plan_id, appointment_id, services, diagnosis_codes, requested_by, notes) VALUES (?,?,?,?,?,?,?,?)")
       ->execute([$patientId, $cardId, $card['plan_id'], $apptId, json_encode($serviceIds), $codes ? json_encode($codes) : null, currentStaffId() ?: null, mb_substr(trim($notes), 0, 500) ?: null]);
    $id = (int)$db->lastInsertId();
    if ($apptId) $db->prepare("UPDATE appointments SET prior_auth_id = ? WHERE id = ?")->execute([$id, $apptId]);
    auditLog($db, 'prior_auth', $id, 'requested', ['patient' => $patientId, 'services' => $serviceIds]);
    return $id;
}

/** The insurer's answer. Approved needs the approval number; it is valid until the given date. */
function decidePriorAuth(PDO $db, int $id, string $status, array $in = []): void {
    if (!isset(PA_STATUS[$status]) || $status === 'requested') throw new RuntimeException('Choose the decision.');
    $pa = findPriorAuth($db, $id);
    if (!$pa) throw new RuntimeException('Approval request not found.');
    $no = trim((string)($in['approval_no'] ?? ''));
    if (in_array($status, ['approved', 'partially_approved'], true) && $no === '') throw new RuntimeException('Enter the approval number from the insurer.');
    $amt = ($in['approved_amount'] ?? '') !== '' ? round((float)$in['approved_amount'], 2) : null;
    $until = ($in['valid_until'] ?? '') ?: null;
    $db->prepare("UPDATE prior_auths SET status = ?, approval_no = ?, approved_amount = ?, valid_until = ?, decided_at = NOW(), notes = CONCAT_WS(' · ', notes, ?) WHERE id = ?")
       ->execute([$status, $no ?: $pa['approval_no'], $amt, $until, mb_substr(trim((string)($in['note'] ?? '')), 0, 200) ?: null, $id]);
    auditLog($db, 'prior_auth', $id, 'decided', ['status' => $status, 'approval_no' => $no]);
}

/** Treatments of a visit that the plan wants approved but have no valid approval: [service_id => name]. */
function servicesNeedingApproval(PDO $db, int $patientId, int $planId, array $serviceIds): array {
    $plan = findPlan($db, $planId);
    if (!$plan || !$serviceIds) return [];
    $need = array_map('intval', $plan['preauth_service_ids'] ? json_decode($plan['preauth_service_ids'], true) : []);
    $ids = implode(',', array_map('intval', $serviceIds));
    $need = array_merge($need, array_map('intval', $db->query("SELECT id FROM services WHERE requires_prior_auth = 1 AND id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN)));
    $need = array_intersect(array_map('intval', $serviceIds), $need);
    if (!$need) return [];
    $s = $db->prepare("SELECT services FROM prior_auths WHERE patient_id = ? AND plan_id = ? AND status IN ('approved','partially_approved') AND (valid_until IS NULL OR valid_until >= CURDATE())");
    $s->execute([$patientId, $planId]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $j) $need = array_diff($need, array_map('intval', json_decode($j, true) ?: []));
    return $need ? $db->query("SELECT id, name FROM services WHERE id IN (" . implode(',', $need) . ")")->fetchAll(PDO::FETCH_KEY_PAIR) : [];
}

function expirePriorAuths(PDO $db): int {
    return $db->exec("UPDATE prior_auths SET status = 'expired' WHERE status IN ('approved','partially_approved','requested') AND valid_until IS NOT NULL AND valid_until < CURDATE()");
}

// ── Claims ──

function findClaim(PDO $db, int $id): ?array {
    $s = $db->prepare("SELECT cl.*, i.number AS invoice_number, i.issued_at, i.patient_id, i.branch_id, i.appointment_id, i.encounter_id, i.total, i.patient_share, i.insurance_share, i.prior_auth_id,
                              p.name AS plan_name, c.id AS company_id, c.name AS company, c.payer_id, c.receiver_id, pi.member_no, pi.policy_no
                       FROM claims cl JOIN invoices i ON i.id = cl.invoice_id JOIN insurance_plans p ON p.id = cl.plan_id JOIN insurance_companies c ON c.id = p.company_id
                       LEFT JOIN patient_insurance pi ON pi.id = cl.patient_insurance_id WHERE cl.id = ?");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function claimEvent(PDO $db, int $claimId, ?string $from, string $to, ?int $amountFils = null, string $note = ''): void {
    $db->prepare("INSERT INTO claim_events (claim_id, from_status, to_status, amount, note, staff_id) VALUES (?,?,?,?,?,?)")
       ->execute([$claimId, $from, $to, $amountFils === null ? null : aed($amountFils), mb_substr($note, 0, 500) ?: null, currentStaffId() ?: null]);
}

/** Called inside issueInvoice: the claim for an insurance invoice, ready to send when nothing is missing. */
function createClaimForInvoice(PDO $db, int $invoiceId): int {
    $inv = findInvoice($db, $invoiceId);
    if (!$inv['insurance_plan_id']) throw new RuntimeException('Choose the patient’s insurance card for this invoice.');
    $number = nextDocumentNumber($db, 'claim', (int)$inv['branch_id']);
    $db->prepare("INSERT INTO claims (number, invoice_id, plan_id, patient_insurance_id, claimed) VALUES (?,?,?,?,?)")->execute([$number, $invoiceId, $inv['insurance_plan_id'], $inv['patient_insurance_id'], $inv['insurance_share']]);
    $id = (int)$db->lastInsertId();
    claimEvent($db, $id, null, 'draft', fils($inv['insurance_share']), 'From invoice ' . $inv['number']);
    refreshClaimReadiness($db, $id);
    return $id;
}

/** What a claim still lacks before it can be sent (empty = ready). */
function claimProblems(PDO $db, array $cl): array {
    $p = [];
    if (!$cl['member_no']) $p[] = 'The patient’s member number is missing (Insurance tab).';
    if (!$cl['payer_id']) $p[] = "The payer ID of {$cl['company']} is missing (Insurance setup).";
    $b = $db->query("SELECT dha_license FROM branches WHERE id = " . (int)$cl['branch_id'])->fetchColumn();
    if (!$b) $p[] = 'The branch’s DHA facility licence is missing (Clinic setup → Branch).';
    if (!claimDiagnoses($db, $cl)) $p[] = 'No diagnosis: add an ICD-10 diagnosis to the visit’s clinical note.';
    foreach (claimActivities($db, $cl) as $a) {
        if (!$a['cpt_code']) $p[] = "“{$a['description']}” has no CPT code (Treatment menu or the plan’s price list).";
        if (!$a['clinician']) $p[] = "“{$a['description']}” has no practitioner with a DHA licence.";
    }
    return array_values(array_unique($p));
}

/** Draft ↔ ready, depending on whether anything is missing. */
function refreshClaimReadiness(PDO $db, int $id): array {
    $cl = findClaim($db, $id);
    $problems = claimProblems($db, $cl);
    $to = $problems ? 'draft' : 'ready';
    if (in_array($cl['status'], ['draft', 'ready'], true) && $cl['status'] !== $to) {
        $db->prepare("UPDATE claims SET status = ? WHERE id = ?")->execute([$to, $id]);
        claimEvent($db, $id, $cl['status'], $to, null, $problems ? implode(' ', $problems) : 'All details present');
    }
    return $problems;
}

function claimDiagnoses(PDO $db, array $cl): array {
    $enc = $cl['encounter_id'] ?: ($cl['appointment_id'] ? $db->query("SELECT id FROM encounters WHERE appointment_id = " . (int)$cl['appointment_id'])->fetchColumn() : null);
    $out = [];
    if ($enc) { $s = $db->prepare("SELECT icd10_code, is_primary FROM encounter_diagnoses WHERE encounter_id = ? ORDER BY is_primary DESC, id"); $s->execute([$enc]); $out = $s->fetchAll(); }
    if (!$out && $cl['prior_auth_id']) foreach (json_decode((string)$db->query("SELECT diagnosis_codes FROM prior_auths WHERE id = " . (int)$cl['prior_auth_id'])->fetchColumn(), true) ?: [] as $i => $c) $out[] = ['icd10_code' => $c, 'is_primary' => $i === 0];
    return $out;
}

/** The covered lines as claim activities, with the practitioner's DHA licence. */
function claimActivities(PDO $db, array $cl): array {
    $s = $db->prepare("SELECT l.*, pp.dha_license AS clinician FROM invoice_lines l LEFT JOIN practitioner_profiles pp ON pp.staff_id = l.practitioner_id WHERE l.invoice_id = ? AND l.covered = 1 AND l.insurance_share > 0 ORDER BY l.id");
    $s->execute([$cl['invoice_id']]);
    return $s->fetchAll();
}

function claimTransition(PDO $db, int $id, string $to, array $in = []): void {
    if (!can('insurance.claims')) throw new RuntimeException('You don’t have permission to work on claims.');
    $cl = findClaim($db, $id);
    if (!$cl) throw new RuntimeException('Claim not found.');
    $from = $cl['status'];
    $ok = ['accepted' => ['submitted', 'resubmitted'], 'rejected' => ['submitted', 'resubmitted', 'accepted', 'partially_paid'], 'ready' => ['rejected'],
           'written_off' => ['draft', 'ready', 'submitted', 'resubmitted', 'accepted', 'rejected', 'partially_paid']][$to] ?? [];
    if (!in_array($from, $ok, true)) throw new RuntimeException('A claim that is ' . strtolower(CLAIM_STATUS[$from][0]) . ' can’t become ' . strtolower(CLAIM_STATUS[$to][0] ?? $to) . '.');
    $note = trim((string)($in['note'] ?? ''));
    $set = ['status' => $to];
    if ($to === 'rejected') {
        $code = trim((string)($in['denial_code'] ?? ''));
        if ($code === '') throw new RuntimeException('Choose or type the denial code.');
        $set['denial_code'] = mb_substr($code, 0, 20);
    }
    if ($to === 'accepted' && ($in['approved'] ?? '') !== '') $set['approved'] = round((float)$in['approved'], 2);
    if (in_array($to, ['written_off', 'ready'], true) && $note === '') throw new RuntimeException($to === 'ready' ? 'Say what was corrected.' : 'Say why it is written off.');
    if ($to === 'ready' && ($problems = claimProblems($db, $cl))) throw new RuntimeException('Still missing: ' . implode(' ', $problems));
    $db->prepare("UPDATE claims SET " . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ", notes = CONCAT_WS(' · ', notes, ?) WHERE id = ?")->execute([...array_values($set), $note ?: null, $id]);
    claimEvent($db, $id, $from, $to, null, trim(($set['denial_code'] ?? '') . ' ' . $note));
    auditLog($db, 'claim', $id, $to, ['from' => $from, 'note' => $note] + (isset($set['denial_code']) ? ['denial' => $set['denial_code']] : []));
}

/** Money from the insurer: an insurer receipt allocated to the claim's invoice; the claim becomes part paid or paid. */
function recordRemittance(PDO $db, int $id, $amount, string $date, string $ref, string $denial = ''): int {
    if (!can('insurance.claims')) throw new RuntimeException('You don’t have permission to work on claims.');
    $cl = findClaim($db, $id);
    if (!$cl || !in_array($cl['status'], ['submitted', 'resubmitted', 'accepted', 'partially_paid', 'rejected'], true)) throw new RuntimeException('Only a submitted claim can receive a payment.');
    $amt = fils($amount);
    $owed = fils($cl['claimed']) - fils($cl['paid']);
    if ($amt <= 0 || $amt > $owed) throw new RuntimeException('Enter the amount paid (at most ' . money($owed / 100) . ').');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) throw new RuntimeException('Enter the remittance date.');
    if (trim($ref) === '') throw new RuntimeException('Enter the remittance reference (payment advice or transfer number).');
    return stockTx($db, function () use ($db, $cl, $id, $amt, $owed, $date, $ref, $denial) {
        $rid = createReceipt($db, (int)$cl['patient_id'], (int)$cl['branch_id'], [['method' => 'insurance', 'amount' => aed($amt), 'reference' => mb_substr(trim($ref), 0, 100)]], [$cl['invoice_id'] => aed($amt)],
                             ['claim' => true, 'received_at' => $date . ' ' . date('H:i:s'), 'notes' => 'Remittance for claim ' . $cl['number']]);
        $paid = fils($cl['paid']) + $amt;
        $to = $paid >= fils($cl['claimed']) ? 'paid' : 'partially_paid';
        $db->prepare("UPDATE claims SET paid = ?, status = ?, remitted_at = ?, denial_code = COALESCE(?, denial_code) WHERE id = ?")->execute([aed($paid), $to, $date, trim($denial) ?: null, $id]);
        claimEvent($db, $id, $cl['status'], $to, $amt, 'Remittance ' . trim($ref) . (trim($denial) ? ' · denial ' . trim($denial) : ''));
        auditLog($db, 'claim', $id, 'remitted', ['amount' => aed($amt), 'ref' => $ref]);
        return $rid;
    });
}

// ── Batches and export ──

/** Groups ready claims of one company into a batch to export. */
function createClaimBatch(PDO $db, int $companyId, array $claimIds): int {
    if (!can('insurance.claims')) throw new RuntimeException('You don’t have permission to work on claims.');
    $claimIds = array_values(array_unique(array_filter(array_map('intval', $claimIds))));
    if (!$claimIds) throw new RuntimeException('Choose the claims to send.');
    return stockTx($db, function () use ($db, $companyId, $claimIds) {
        $s = $db->query("SELECT cl.id, cl.status, p.company_id FROM claims cl JOIN insurance_plans p ON p.id = cl.plan_id WHERE cl.id IN (" . implode(',', $claimIds) . ") FOR UPDATE")->fetchAll();
        if (count($s) !== count($claimIds)) throw new RuntimeException('A claim was not found.');
        foreach ($s as $r) {
            if ((int)$r['company_id'] !== $companyId) throw new RuntimeException('A batch is for one insurance company only.');
            if ($r['status'] !== 'ready') throw new RuntimeException('Only claims that are ready to send can go in a batch.');
        }
        $db->prepare("INSERT INTO claim_batches (company_id, created_by) VALUES (?, ?)")->execute([$companyId, currentStaffId() ?: null]);
        $bid = (int)$db->lastInsertId();
        $db->exec("UPDATE claims SET batch_id = $bid WHERE id IN (" . implode(',', $claimIds) . ")");
        auditLog($db, 'claim_batch', $bid, 'created', ['claims' => $claimIds]);
        return $bid;
    });
}

function batchClaims(PDO $db, int $batchId): array {
    $ids = $db->query("SELECT id FROM claims WHERE batch_id = $batchId ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    return array_map(fn($i) => findClaim($db, (int)$i), $ids);
}

/** The batch as an eClaimLink Claim.Submission XML file (also stored in the clinic's storage folder). */
function exportBatchXml(PDO $db, int $batchId): string {
    $b = $db->query("SELECT b.*, c.payer_id, c.receiver_id FROM claim_batches b JOIN insurance_companies c ON c.id = b.company_id WHERE b.id = $batchId")->fetch();
    if (!$b) throw new RuntimeException('Batch not found.');
    $claims = batchClaims($db, $batchId);
    $dt = fn($v) => date('d/m/Y H:i', strtotime((string)$v));
    $x = new DOMDocument('1.0', 'utf-8');
    $x->formatOutput = true;
    $root = $x->appendChild($x->createElement('Claim.Submission'));
    $el = function (DOMNode $parent, string $name, $value = null) use ($x) { $e = $parent->appendChild($x->createElement($name)); if ($value !== null) $e->appendChild($x->createTextNode((string)$value)); return $e; };
    $h = $el($root, 'Header');
    $facility = '';
    if ($claims) $facility = (string)$db->query("SELECT dha_license FROM branches WHERE id = " . (int)$claims[0]['branch_id'])->fetchColumn();
    $el($h, 'SenderID', $facility); $el($h, 'ReceiverID', $b['receiver_id'] ?: $b['payer_id']); $el($h, 'TransactionDate', $dt('now'));
    $el($h, 'RecordCount', count($claims)); $el($h, 'DispositionFlag', billingSetting($db, 'eclaim_disposition', 'TEST') === 'PRODUCTION' ? 'PRODUCTION' : 'TEST');
    foreach ($claims as $cl) {
        $p = findPatient($db, (int)$cl['patient_id']);
        $eid = $p['eid_enc'] ? tenantDecrypt($p['eid_enc']) : '';
        $eid = preg_match('/^(\d{3})(\d{4})(\d{7})(\d)$/', preg_replace('/\D/', '', $eid), $m) ? "$m[1]-$m[2]-$m[3]-$m[4]" : EID_UNKNOWN;
        $fac = (string)$db->query("SELECT dha_license FROM branches WHERE id = " . (int)$cl['branch_id'])->fetchColumn();
        $acts = claimActivities($db, $cl);
        $gross = array_sum(array_map(fn($a) => fils($a['line_total']), $acts));
        $net = array_sum(array_map(fn($a) => fils($a['insurance_share']), $acts));
        $appt = $cl['appointment_id'] ? $db->query("SELECT scheduled_at, ends_at FROM appointments WHERE id = " . (int)$cl['appointment_id'])->fetch() : null;
        $start = $appt['scheduled_at'] ?? $cl['issued_at']; $end = $appt['ends_at'] ?? $start;
        $pa = $cl['prior_auth_id'] ? (string)$db->query("SELECT approval_no FROM prior_auths WHERE id = " . (int)$cl['prior_auth_id'])->fetchColumn() : '';
        $c = $el($root, 'Claim');
        $el($c, 'ID', $cl['number']); $el($c, 'IDPayer', $cl['submission_ref'] ?: $cl['number']); $el($c, 'MemberID', $cl['member_no']); $el($c, 'PayerID', $cl['payer_id']);
        $el($c, 'ProviderID', $fac); $el($c, 'EmiratesIDNumber', $eid);
        $el($c, 'Gross', aed($gross)); $el($c, 'PatientShare', aed($gross - $net)); $el($c, 'Net', aed($net));
        $e = $el($c, 'Encounter');
        $el($e, 'FacilityID', $fac); $el($e, 'Type', 1); $el($e, 'PatientID', $p['mrn']); $el($e, 'Start', $dt($start)); $el($e, 'End', $dt($end)); $el($e, 'StartType', 1); $el($e, 'EndType', 1);
        foreach (claimDiagnoses($db, $cl) as $i => $d) { $dg = $el($c, 'Diagnosis'); $el($dg, 'Type', $i === 0 ? 'Principal' : 'Secondary'); $el($dg, 'Code', $d['icd10_code']); }
        foreach ($acts as $a) {
            $ac = $el($c, 'Activity');
            $el($ac, 'ID', $cl['number'] . '-' . $a['id']); $el($ac, 'Start', $dt($start)); $el($ac, 'Type', 3); $el($ac, 'Code', $a['cpt_code']);
            $el($ac, 'Quantity', rtrim(rtrim(number_format((float)$a['qty'], 2, '.', ''), '0'), '.')); $el($ac, 'Net', $a['insurance_share']); $el($ac, 'Clinician', $a['clinician']);
            if ($pa !== '') $el($ac, 'PriorAuthorizationID', $pa);
        }
        if ($db->query("SELECT COUNT(*) FROM claim_events WHERE claim_id = " . (int)$cl['id'] . " AND to_status = 'rejected'")->fetchColumn()) {
            $r = $el($c, 'Resubmission'); $el($r, 'Type', 'correction');
            $el($r, 'Comment', (string)$db->query("SELECT note FROM claim_events WHERE claim_id = " . (int)$cl['id'] . " AND to_status = 'ready' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 'Corrected');
        }
    }
    $xml = $x->saveXML();
    $rel = 'claims/batch-' . $batchId . '-' . date('Ymd-His') . '.xml';
    $full = tenantStoragePath() . '/' . $rel;
    if (!is_dir(dirname($full))) mkdir(dirname($full), 0750, true);
    file_put_contents($full, $xml);
    $db->prepare("UPDATE claim_batches SET exported_at = NOW(), file_path = ? WHERE id = ?")->execute([$rel, $batchId]);
    auditLog($db, 'claim_batch', $batchId, 'exported', ['file' => $rel]);
    return $xml;
}

function exportBatchCsv(PDO $db, int $batchId): string {
    $out = fopen('php://temp', 'w+');
    fputcsv($out, ['Claim', 'Invoice', 'Date', 'MRN', 'Member no', 'Policy', 'CPT', 'Description', 'Qty', 'Line total', 'Patient share', 'Claimed', 'Clinician', 'Diagnoses']);
    foreach (batchClaims($db, $batchId) as $cl) {
        $p = findPatient($db, (int)$cl['patient_id']);
        $dx = implode(' ', array_column(claimDiagnoses($db, $cl), 'icd10_code'));
        foreach (claimActivities($db, $cl) as $a) fputcsv($out, [$cl['number'], $cl['invoice_number'], substr((string)$cl['issued_at'], 0, 10), $p['mrn'], $cl['member_no'], $cl['policy_no'], $a['cpt_code'], $a['description'], $a['qty'], $a['line_total'], $a['patient_share'], $a['insurance_share'], $a['clinician'], $dx]);
    }
    rewind($out);
    return "\xEF\xBB\xBF" . stream_get_contents($out);
}

/** After uploading the file in the eClaimLink portal: the batch's claims become submitted (or resubmitted). */
function markBatchSubmitted(PDO $db, int $batchId, string $ref, string $date): void {
    if (!can('insurance.claims')) throw new RuntimeException('You don’t have permission to work on claims.');
    if (trim($ref) === '') throw new RuntimeException('Enter the portal’s submission reference.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) throw new RuntimeException('Enter the date it was submitted.');
    stockTx($db, function () use ($db, $batchId, $ref, $date) {
        $b = $db->query("SELECT * FROM claim_batches WHERE id = $batchId FOR UPDATE")->fetch();
        if (!$b || !$b['exported_at']) throw new RuntimeException('Export the batch before marking it submitted.');
        if ($b['submitted_at']) throw new RuntimeException('This batch was already submitted.');
        $db->prepare("UPDATE claim_batches SET submitted_at = ?, submission_ref = ? WHERE id = ?")->execute([$date . ' ' . date('H:i:s'), mb_substr(trim($ref), 0, 100), $batchId]);
        foreach (batchClaims($db, $batchId) as $cl) {
            if ($cl['status'] !== 'ready') continue;
            $to = $db->query("SELECT COUNT(*) FROM claim_events WHERE claim_id = " . (int)$cl['id'] . " AND to_status = 'rejected'")->fetchColumn() ? 'resubmitted' : 'submitted';
            $db->prepare("UPDATE claims SET status = ?, submitted_at = ?, submission_ref = ? WHERE id = ?")->execute([$to, $date . ' ' . date('H:i:s'), mb_substr(trim($ref), 0, 100), $cl['id']]);
            claimEvent($db, (int)$cl['id'], 'ready', $to, null, 'Batch ' . $batchId . ' · ' . trim($ref));
        }
        auditLog($db, 'claim_batch', $batchId, 'submitted', ['ref' => $ref]);
    });
}

// ── Reports ──

/** Claims not yet sent, by how long ago their invoice was issued: [7 => [n, fils], 30 => .., 45 => .., 'all' => ..]. */
function claimAgeing(PDO $db): array {
    $out = [];
    foreach ([7, 30, 45] as $d) {
        $r = $db->query("SELECT COUNT(*), COALESCE(SUM(cl.claimed), 0) FROM claims cl JOIN invoices i ON i.id = cl.invoice_id WHERE cl.status IN ('draft','ready') AND i.issued_at < NOW() - INTERVAL $d DAY")->fetch(PDO::FETCH_NUM);
        $out[$d] = [(int)$r[0], fils($r[1])];
    }
    $r = $db->query("SELECT COUNT(*), COALESCE(SUM(claimed), 0) FROM claims WHERE status IN ('draft','ready')")->fetch(PDO::FETCH_NUM);
    $out['all'] = [(int)$r[0], fils($r[1])];
    return $out;
}

/** Money still expected from each company (claims sent and not settled). */
function insuranceOutstanding(PDO $db): array {
    return $db->query("SELECT c.id, c.name, COUNT(*) AS claims, SUM(cl.claimed) AS claimed, SUM(cl.paid) AS paid, SUM(cl.claimed - cl.paid) AS outstanding,
                              MIN(cl.submitted_at) AS oldest
                       FROM claims cl JOIN insurance_plans p ON p.id = cl.plan_id JOIN insurance_companies c ON c.id = p.company_id
                       WHERE cl.status IN ('submitted','resubmitted','accepted','partially_paid','rejected') GROUP BY c.id, c.name ORDER BY outstanding DESC")->fetchAll();
}

function denialCodes(PDO $db): array { return $db->query("SELECT code, description FROM denial_codes ORDER BY code")->fetchAll(PDO::FETCH_KEY_PAIR); }
