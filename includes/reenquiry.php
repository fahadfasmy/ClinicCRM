<?php
// includes/reenquiry.php — a person who is already a lead enquires again (Meta form, website form or WhatsApp).
// The enquiry is kept on the lead, the lead is flagged "Returning" until someone next logs contact with them,
// the owner (or the first admin) gets a "call now" reminder, and a Lost/Cold lead reopens as New.
require_once __DIR__ . '/meta_leads.php';

function ensureReEnquirySchema(PDO $db): void {
    static $done = false;
    if ($done) return;
    if (!$db->query("SHOW COLUMNS FROM leads LIKE 'reenquiry_at'")->fetch()) {
        $db->exec("ALTER TABLE leads ADD COLUMN reenquiry_at DATETIME NULL, ADD COLUMN reenquiry_note VARCHAR(255) NULL");
    }
    $done = true;
}

/** SQL condition: the lead enquired again and nobody has logged contact since. */
const REENQUIRY_OPEN_SQL = "l.reenquiry_at IS NOT NULL AND (l.last_contacted IS NULL OR l.reenquiry_at > l.last_contacted)";

function isReEnquiryOpen(array $lead): bool {
    return !empty($lead['reenquiry_at']) && (empty($lead['last_contacted']) || $lead['reenquiry_at'] > $lead['last_contacted']);
}

/** "Returning" chip for lists and cards (empty when the lead has no open re-enquiry). */
function returningChip(array $lead, string $class = ''): string {
    if (!isReEnquiryOpen($lead)) return '';
    return '<span class="chip stage-qualified ' . $class . '" title="' . htmlspecialchars('Enquired again ' . date('d M, h:i A', strtotime($lead['reenquiry_at'])) . ': ' . ($lead['reenquiry_note'] ?? ''), ENT_QUOTES) . '"><i class="bi bi-arrow-repeat"></i>Returning</span>';
}

/**
 * Records a new enquiry from an existing lead. $what is a short label ("Website form – dental"),
 * $details the answers / message. $meta = ['lead_id' => , 'form_id' => , 'ad_id' => ] for a Meta re-enquiry:
 * the lead then points at the new Meta lead, so stage updates count towards the ad that brought them back.
 * Call it outside a transaction or inside one that ensureReEnquirySchema() already ran before.
 */
function recordReEnquiry(PDO $db, int $leadId, string $what, string $details = '', ?array $meta = null): void {
    ensureReEnquirySchema($db);
    $s = $db->prepare("SELECT stage, assigned_to FROM leads WHERE id = ?");
    $s->execute([$leadId]);
    if (!$lead = $s->fetch()) return;

    $stage = $lead['stage'];
    $label = mb_substr($what, 0, 200);
    $note  = "↩ New enquiry · " . date('d M Y, h:i A') . " · $label" . ($details !== '' ? "\n$details" : '');
    $flag  = $stage === 'converted' ? "Existing patient – new interest: $label" : $label;

    $db->prepare("UPDATE leads SET reenquiry_at = NOW(), reenquiry_note = ?, updated_at = NOW(),
                  notes = CONCAT(COALESCE(notes, ''), IF(COALESCE(notes, '') = '', '', '\n\n'), ?) WHERE id = ?")
       ->execute([mb_substr($flag, 0, 255), $note, $leadId]);

    // Lost or Cold people who come back are live again; every other stage is left as it is
    if (in_array($stage, ['lost', 'cold'], true)) {
        $db->prepare("UPDATE leads SET stage = 'new', aging_status = 'fresh' WHERE id = ?")->execute([$leadId]);
        $db->prepare("INSERT INTO activity_log (staff_id, lead_id, action, old_value, new_value) VALUES (NULL, ?, 'stage_changed', ?, 'new')")->execute([$leadId, $stage]);
    }

    if ($meta && !empty($meta['lead_id'])) {
        $db->prepare("UPDATE leads SET meta_lead_id = ?, meta_form_id = ?, meta_ad_id = ? WHERE id = ?")
           ->execute([$meta['lead_id'], $meta['form_id'] ?? null, $meta['ad_id'] ?? null, $leadId]);
        // The new Meta lead starts its own funnel: "Lead" and the current stage are queued again for it
        $db->prepare("DELETE FROM meta_capi_events WHERE lead_id = ?")->execute([$leadId]);
    }

    foreach (metaMatchTreatments($db, [$what, $details]) as $tid) {
        $db->prepare("INSERT INTO lead_treatments (lead_id, treatment_id) SELECT ?, ? FROM DUAL
                      WHERE NOT EXISTS (SELECT 1 FROM lead_treatments WHERE lead_id = ? AND treatment_id = ?)")->execute([$leadId, $tid, $leadId, $tid]);
    }

    $remindTo = (int)$lead['assigned_to'] ?: (int)$db->query("SELECT id FROM staff WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    if ($remindTo) $db->prepare("INSERT INTO reminders (lead_id, staff_id, message, remind_at, type) VALUES (?,?,?,?,'followup_due')")
                      ->execute([$leadId, $remindTo, mb_substr("Returning enquiry ($label) — call now!", 0, 500), date('Y-m-d H:i:s', strtotime('+30 minutes'))]);
    metaActivity($db, 'reenquiry', $leadId, $flag);
}
