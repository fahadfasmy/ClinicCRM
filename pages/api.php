<?php
// pages/api.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

require_once __DIR__ . '/../includes/inventory.php';

$db     = getDB();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
// Anything that changes data must come from a CRM page (token in the X-CSRF-Token header or _csrf field)
if ($_SERVER['REQUEST_METHOD'] === 'POST') requireCsrf();

/** Sidebar badges for the Clinic group: treatments today (+ ones waiting to be completed) and stock alerts. */
function clinicNavCounts(PDO $db): array {
    try {
        ensureInventorySchema($db);
        $out = [];
        if (can('appointments.view')) {
            $out['appointments_today'] = (int)$db->query("SELECT COUNT(*) FROM appointments WHERE status IN ('booked','confirmed','arrived','in_triage','with_doctor') AND DATE(scheduled_at) = CURDATE()")->fetchColumn();
            $out['appointments_overdue'] = (int)$db->query("SELECT COUNT(*) FROM appointments WHERE status IN ('booked','confirmed','arrived','in_triage','with_doctor') AND scheduled_at < NOW() - INTERVAL 1 HOUR")->fetchColumn();
        }
        if (can('inventory.view')) $out['inventory_alerts'] = inventoryAlertCounts($db)['total'];
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

header('Content-Type: application/json');

switch ($action) {

    // ── Nav badge counts ──
    case 'nav_counts':
        $sf = can('leads.all') ? '' : ('AND assigned_to = ' . currentStaffId());
        $fuSf = can('leads.all') ? '' : ('AND l.assigned_to = ' . currentStaffId());
        echo json_encode([
            'hot_leads'      => (int)$db->query("SELECT COUNT(*) FROM leads WHERE score>=61 AND stage NOT IN('converted','lost') $sf")->fetchColumn(),
            'due_followups'  => (int)$db->query("SELECT COUNT(*) FROM followups f " . currentFollowupJoin() . " JOIN leads l ON l.id=f.lead_id WHERE f.next_followup_at <= NOW() AND l.stage IN " . OPEN_STAGES_SQL . " $fuSf")->fetchColumn(),
            'critical_leads' => (int)$db->query("SELECT COUNT(*) FROM leads WHERE aging_status='critical' AND stage NOT IN('converted','lost','cold') $sf")->fetchColumn(),
        ] + clinicNavCounts($db));
        break;

    // ── Live duplicate phone check (used by lead_add.php) ──
    case 'check_duplicate':
        $phone = trim($_GET['phone'] ?? '');
        if (!$phone) { echo json_encode(['duplicate'=>false]); break; }
        $dup = findDuplicateLead($phone, $db);
        if ($dup) {
            echo json_encode(['duplicate'=>true, 'id'=>$dup['id'], 'name'=>$dup['name'], 'stage'=>$dup['stage']]);
        } else {
            echo json_encode(['duplicate'=>false]);
        }
        break;

    // ── Quick stage change ──
    case 'change_stage':
        $leadId   = (int)($_POST['lead_id'] ?? 0);
        $newStage = $_POST['stage'] ?? '';
        $allowed  = ['new','contacted','qualified','booked','converted','lost','cold'];
        if (!$leadId || !in_array($newStage, $allowed)) { echo json_encode(['error'=>'Invalid']); break; }
        $lead = $db->prepare("SELECT assigned_to, stage FROM leads WHERE id=?");
        $lead->execute([$leadId]);
        $lead = $lead->fetch();
        if (!$lead) { echo json_encode(['error'=>'Not found']); break; }
        if (!can('leads.all') && $lead['assigned_to'] != currentStaffId()) { echo json_encode(['error'=>'Denied']); break; }
        if ($lead['stage'] === $newStage) { echo json_encode(['success'=>true,'unchanged'=>true]); break; }
        $lostReason = trim(mb_substr((string)($_POST['lost_reason'] ?? ''), 0, 255));
        if ($newStage === 'lost' && $lostReason !== '') {
            $db->prepare("UPDATE leads SET stage=?, lost_reason=?, updated_at=NOW() WHERE id=?")->execute([$newStage,$lostReason,$leadId]);
        } else {
            $db->prepare("UPDATE leads SET stage=?, updated_at=NOW() WHERE id=?")->execute([$newStage,$leadId]);
        }
        setConvertedAt($leadId, $newStage, $lead['stage'], $db);
        try { updateLeadScore($leadId, $db); } catch (Exception $e) {}
        logActivity(currentStaffId(), 'stage_changed', $leadId, $lead['stage'], $newStage);
        $cancelled = 0;
        if (in_array($newStage, ['lost', 'cold'], true) && ($_POST['cancel_appointments'] ?? '') === '1' && can('appointments.book')) {
            $cancelled = cancelOpenAppointmentsForLead($db, $leadId, 'Lead marked ' . $newStage);
        }
        $score = (int)$db->query("SELECT score FROM leads WHERE id=" . $leadId)->fetchColumn();
        echo json_encode(['success'=>true, 'score'=>$score, 'score_html'=>scoreChip($score), 'cancelled_appointments'=>$cancelled]);
        break;

    // ── Tick follow-up tracker (1, 2 or 3) ──
    case 'tick_followup':
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $fuNum  = (int)($_POST['fu_num']  ?? 0);
        if (!$leadId || !in_array($fuNum,[1,2,3])) { echo json_encode(['error'=>'Invalid']); break; }
        $lRow = $db->prepare("SELECT assigned_to, followup_1_done, followup_2_done, followup_3_done FROM leads WHERE id=?");
        $lRow->execute([$leadId]);
        $lRow = $lRow->fetch();
        if (!$lRow) { echo json_encode(['error'=>'Not found']); break; }
        if (!can('leads.all') && !empty($lRow['assigned_to']) && (int)$lRow['assigned_to'] !== currentStaffId()) {
            echo json_encode(['error'=>'Denied']); break;
        }
        if ($fuNum===2 && !$lRow['followup_1_done']) { echo json_encode(['error'=>'Complete FU 1 first']); break; }
        if ($fuNum===3 && !$lRow['followup_2_done']) { echo json_encode(['error'=>'Complete FU 2 first']); break; }
        if ($lRow["followup_{$fuNum}_done"]) { echo json_encode(['success'=>true,'already_done'=>true]); break; }
        $db->prepare("UPDATE leads SET followup_{$fuNum}_done=1, followup_{$fuNum}_at=NOW(), followup_count=followup_count+1, last_contacted=NOW(), updated_at=NOW() WHERE id=?")
           ->execute([$leadId]);
        logActivity(currentStaffId(), "followup_{$fuNum}_ticked", $leadId);
        echo json_encode(['success'=>true]);
        break;

    // ── Dashboard stats ──
    case 'dashboard_stats':
        $sf = can('leads.all') ? '' : ('AND assigned_to = ' . currentStaffId());
        echo json_encode([
            'new_today' => (int)$db->query("SELECT COUNT(*) FROM leads WHERE DATE(created_at)=CURDATE() $sf")->fetchColumn(),
            'hot'       => (int)$db->query("SELECT COUNT(*) FROM leads WHERE score>=61 AND stage NOT IN('converted','lost') $sf")->fetchColumn(),
            'converted' => (int)$db->query("SELECT COUNT(*) FROM leads WHERE stage='converted' $sf")->fetchColumn(),
            'critical'  => (int)$db->query("SELECT COUNT(*) FROM leads WHERE aging_status='critical' AND stage NOT IN('converted','lost','cold') $sf")->fetchColumn(),
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error'=>'Unknown action']);
}
