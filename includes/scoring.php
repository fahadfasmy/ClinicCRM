<?php
// includes/scoring.php
// Standalone scoring breakdown - used to explain a lead's score components

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/**
 * Returns a detailed breakdown of a lead's score components
 */
function getScoreBreakdown(int $leadId, PDO $db): array {
    $stmt = $db->prepare("SELECT * FROM leads WHERE id = ?");
    $stmt->execute([$leadId]);
    $lead = $stmt->fetch();
    if (!$lead) return [];

    $breakdown = [];
    $total = 0;

    // Source
    $sourcePoints = [
        'referral' => 20, 'walkin' => 18, 'whatsapp' => 14,
        'phone_call' => 14, 'instagram' => 10, 'facebook' => 10,
        'meta_form' => 8, 'other' => 5,
    ];
    $sp = $sourcePoints[$lead['source']] ?? 5;
    $breakdown[] = ['label' => 'Source: ' . ucfirst(str_replace('_',' ',$lead['source'])), 'points' => $sp, 'max' => 20];
    $total += $sp;

    // Budget
    $budgetPoints = ['3000_plus' => 20, '1000_3000' => 15, '500_1000' => 10, 'under_500' => 5, 'unknown' => 0];
    $bp = $budgetPoints[$lead['budget_range']] ?? 0;
    $breakdown[] = ['label' => 'Budget: ' . str_replace(['_','plus'],['–','+'],$lead['budget_range']), 'points' => $bp, 'max' => 20];
    $total += $bp;

    // Urgency
    $urgencyPoints = ['immediate' => 20, 'within_month' => 15, '3_months' => 8, 'just_browsing' => 2, 'unknown' => 0];
    $up = $urgencyPoints[$lead['urgency']] ?? 0;
    $breakdown[] = ['label' => 'Urgency: ' . str_replace('_',' ',$lead['urgency']), 'points' => $up, 'max' => 20];
    $total += $up;

    // Follow-ups
    $stmt = $db->prepare("SELECT COUNT(*) FROM followups WHERE lead_id = ?");
    $stmt->execute([$leadId]);
    $fuCount = (int)$stmt->fetchColumn();
    $fup = min($fuCount * 3, 15);
    $breakdown[] = ['label' => "Follow-ups: {$fuCount} done", 'points' => $fup, 'max' => 15];
    $total += $fup;

    // Email
    $ep = !empty($lead['email']) ? 5 : 0;
    $breakdown[] = ['label' => 'Email provided', 'points' => $ep, 'max' => 5];
    $total += $ep;

    // Treatments
    $stmt = $db->prepare("SELECT COUNT(*) as cnt, SUM(t.avg_price) as val FROM lead_treatments lt JOIN treatments t ON t.id=lt.treatment_id WHERE lt.lead_id=?");
    $stmt->execute([$leadId]);
    $trow = $stmt->fetch();
    $tp = min((int)($trow['cnt'] ?? 0) * 2, 10) + ($trow['val'] >= 3000 ? 5 : 0);
    $breakdown[] = ['label' => "Treatments: {$trow['cnt']} selected", 'points' => min($tp,15), 'max' => 15];
    $total += min($tp,15);

    // Response speed
    $rp = 0;
    if (!empty($lead['last_contacted']) && !empty($lead['created_at'])) {
        $diff = strtotime($lead['last_contacted']) - strtotime($lead['created_at']);
        if ($diff <= 7200) { $rp = 5; }
    }
    $breakdown[] = ['label' => 'Fast response (< 2h)', 'points' => $rp, 'max' => 5];
    $total += $rp;

    return ['breakdown' => $breakdown, 'total' => min($total, 100)];
}
