<?php
// includes/functions.php
require_once __DIR__ . '/db.php';

// ─────────────────────────────────────────────
// LEAD SCORING ENGINE (0–100)
// ─────────────────────────────────────────────
function calculateLeadScore(array $lead, PDO $db): int {
    $score = 0;
    $sourcePoints = ['referral'=>20,'walkin'=>18,'whatsapp'=>14,'phone_call'=>14,'instagram'=>10,'facebook'=>10,'meta_form'=>8,'other'=>5];
    $score += $sourcePoints[$lead['source']] ?? 5;
    $budgetPoints = ['3000_plus'=>20,'1000_3000'=>15,'500_1000'=>10,'under_500'=>5,'unknown'=>0];
    $score += $budgetPoints[$lead['budget_range']] ?? 0;
    $urgencyPoints = ['immediate'=>20,'within_month'=>15,'3_months'=>8,'just_browsing'=>2,'unknown'=>0];
    $score += $urgencyPoints[$lead['urgency']] ?? 0;
    $stmt = $db->prepare("SELECT COUNT(*) FROM followups WHERE lead_id = ?");
    $stmt->execute([$lead['id']]);
    $score += min((int)$stmt->fetchColumn() * 3, 15);
    if (!empty($lead['email'])) $score += 5;
    $stmt = $db->prepare("SELECT COUNT(*) as cnt, SUM(t.avg_price) as total_value FROM lead_treatments lt JOIN treatments t ON t.id=lt.treatment_id WHERE lt.lead_id=?");
    $stmt->execute([$lead['id']]);
    $trow = $stmt->fetch();
    if ($trow['cnt'] > 0) {
        $score += min((int)$trow['cnt'] * 2, 10);
        if ($trow['total_value'] >= 3000) $score += 5;
    }
    if (!empty($lead['last_contacted']) && !empty($lead['created_at'])) {
        $diff = strtotime($lead['last_contacted']) - strtotime($lead['created_at']);
        if ($diff <= 7200) $score += 5;
    }
    return min($score, 100);
}

function updateLeadScore(int $leadId, PDO $db): void {
    $stmt = $db->prepare("SELECT * FROM leads WHERE id = ?");
    $stmt->execute([$leadId]);
    $lead = $stmt->fetch();
    if (!$lead) return;
    $newScore = calculateLeadScore($lead, $db);
    $oldScore = (int)$lead['score'];
    if ($newScore !== $oldScore) {
        $db->prepare("UPDATE leads SET score=?, score_updated=NOW() WHERE id=?")->execute([$newScore,$leadId]);
        $db->prepare("INSERT INTO lead_score_log (lead_id,old_score,new_score,reason) VALUES (?,?,?,?)")->execute([$leadId,$oldScore,$newScore,'Auto-recalculated']);
    }
}

function getScoreLabel(int $score): string {
    if ($score >= 86) return 'Priority';
    if ($score >= 61) return 'Hot';
    if ($score >= 31) return 'Warm';
    return 'Cold';
}

function getScoreBadgeClass(int $score): string {
    if ($score >= 86) return 'score-chip score-priority';
    if ($score >= 61) return 'score-chip score-hot';
    if ($score >= 31) return 'score-chip score-warm';
    return 'score-chip score-cold';
}

// ─────────────────────────────────────────────
// CONVERSION DATE — set when stage → converted
// ─────────────────────────────────────────────
function setConvertedAt(int $leadId, string $newStage, string $oldStage, PDO $db): void {
    if ($newStage !== 'converted' || $oldStage === 'converted') return;
    try {
        $db->query("SELECT converted_at FROM leads LIMIT 1");
        $db->prepare("UPDATE leads SET converted_at=NOW() WHERE id=? AND (converted_at IS NULL OR converted_at='0000-00-00 00:00:00')")
           ->execute([$leadId]);
    } catch (PDOException $e) {
        // Column doesn't exist yet — skip
    }
}

// ─────────────────────────────────────────────
// AGING STATUS
// ─────────────────────────────────────────────
function updateAgingStatuses(PDO $db): void {
    $db->exec("UPDATE leads SET aging_status='fresh' WHERE stage NOT IN('converted','lost','cold') AND (last_contacted>=DATE_SUB(NOW(),INTERVAL 24 HOUR) OR (last_contacted IS NULL AND created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)))");
    $db->exec("UPDATE leads SET aging_status='due' WHERE stage NOT IN('converted','lost','cold') AND (last_contacted<DATE_SUB(NOW(),INTERVAL 2 DAY) OR (last_contacted IS NULL AND created_at<DATE_SUB(NOW(),INTERVAL 2 DAY))) AND (last_contacted>=DATE_SUB(NOW(),INTERVAL 3 DAY) OR (last_contacted IS NULL AND created_at>=DATE_SUB(NOW(),INTERVAL 3 DAY)))");
    $db->exec("UPDATE leads SET aging_status='overdue' WHERE stage NOT IN('converted','lost','cold') AND (last_contacted<DATE_SUB(NOW(),INTERVAL 3 DAY) OR (last_contacted IS NULL AND created_at<DATE_SUB(NOW(),INTERVAL 3 DAY))) AND (last_contacted>=DATE_SUB(NOW(),INTERVAL 7 DAY) OR (last_contacted IS NULL AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)))");
    $db->exec("UPDATE leads SET aging_status='critical' WHERE stage NOT IN('converted','lost','cold') AND (last_contacted<DATE_SUB(NOW(),INTERVAL 7 DAY) OR (last_contacted IS NULL AND created_at<DATE_SUB(NOW(),INTERVAL 7 DAY)))");
}

// ─────────────────────────────────────────────
// CURRENT FOLLOW-UPS
// A lead's "current" follow-up is its most recent follow-up entry; older
// entries were superseded when the next contact was logged. Closed leads
// (converted / lost / cold) have nothing due.
// Usage: "FROM followups f " . currentFollowupJoin() . " JOIN leads l ..."
// ─────────────────────────────────────────────
function currentFollowupJoin(string $alias = 'f'): string {
    return "JOIN (SELECT lead_id, MAX(id) AS max_id FROM followups GROUP BY lead_id) cur_fu ON cur_fu.max_id = {$alias}.id";
}
const OPEN_STAGES_SQL = "('new','contacted','qualified','booked')";

// ─────────────────────────────────────────────
// ACTIVITY LOG
// ─────────────────────────────────────────────
function logActivity(int $staffId, string $action, ?int $leadId=null, ?string $oldVal=null, ?string $newVal=null): void {
    $db = getDB();
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    // The columns hold 255 characters; a strict database would refuse longer text
    $fit = fn(?string $v) => $v === null || mb_strlen($v) <= 255 ? $v : mb_substr($v, 0, 254) . '…';
    $db->prepare("INSERT INTO activity_log (staff_id,lead_id,action,old_value,new_value,ip_address) VALUES (?,?,?,?,?,?)")
       ->execute([$staffId,$leadId,$action,$fit($oldVal),$fit($newVal),$ip]);
}

// ─────────────────────────────────────────────
// DUPLICATE DETECTION
// ─────────────────────────────────────────────
function findDuplicateLead(string $phone, PDO $db, ?int $excludeId=null): ?array {
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    $sql = "SELECT id,name,stage FROM leads WHERE REPLACE(REPLACE(phone,' ',''),'-','')=?";
    $params = [$phone];
    if ($excludeId) { $sql .= " AND id!=?"; $params[] = $excludeId; }
    $sql .= " LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

// ─────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────
function stageBadge(string $stage): string {
    $labels = ['new'=>'New','contacted'=>'Contacted','qualified'=>'Qualified','booked'=>'Booked','converted'=>'Converted','lost'=>'Lost','cold'=>'Cold'];
    $label = $labels[$stage] ?? ucfirst($stage);
    return "<span class=\"stage-badge stage-{$stage}\">{$label}</span>";
}

function getScoreChipClass(int $score): string {
    if ($score >= 86) return 'score-priority';
    if ($score >= 61) return 'score-hot';
    if ($score >= 31) return 'score-warm';
    return 'score-cold';
}

function sourceBadge(string $source): string {
    static $sourceMap = null;
    if ($sourceMap === null) {
        $sourceMap = ['meta_form'=>['Meta Form','🎯'],'whatsapp'=>['WhatsApp','💬'],'instagram'=>['Instagram','📸'],'facebook'=>['Facebook','👍'],'phone_call'=>['Phone Call','📞'],'walkin'=>['Walk-in','🚶'],'referral'=>['Referral','🤝'],'other'=>['Other','📌']];
        try {
            $rows = getDB()->query("SELECT slug,name,icon FROM lead_sources WHERE is_active=1")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) $sourceMap[$r['slug']] = [$r['name'],$r['icon']];
        } catch (\Exception $e) {}
    }
    [$label,$icon] = $sourceMap[$source] ?? [ucfirst(str_replace('_',' ',$source)),'📌'];
    return htmlspecialchars($icon,ENT_QUOTES,'UTF-8').' '.htmlspecialchars($label,ENT_QUOTES,'UTF-8');
}

function agingBadge(string $status): string {
    $labels = ['fresh'=>'Fresh','due'=>'Due','overdue'=>'Overdue','critical'=>'Critical'];
    $label = $labels[$status] ?? 'Unknown';
    $cls = isset($labels[$status]) ? "aging-{$status}" : '';
    return "<span class=\"chip {$cls}\">{$label}</span>";
}

function scoreChip(int $score): string {
    return '<span class="' . getScoreBadgeClass($score) . '" title="' . getScoreLabel($score) . '">' . $score . '</span>';
}

function channelLabel(?string $channel): string {
    $map = ['whatsapp'=>['WhatsApp','bi-whatsapp'],'phone_call'=>['Phone call','bi-telephone'],'in_person'=>['In person','bi-person'],
            'email'=>['Email','bi-envelope'],'sms'=>['SMS','bi-chat-dots'],'other'=>['Other','bi-three-dots']];
    [$label,$icon] = $map[$channel] ?? [ucfirst(str_replace('_',' ',(string)$channel)),'bi-three-dots'];
    return "<span class=\"source-label\"><i class=\"bi {$icon}\"></i>{$label}</span>";
}

function outcomeLabel(?string $outcome): string {
    $map = ['interested'=>['Interested','green'],'needs_info'=>['Needs info','blue'],'price_objection'=>['Price objection','amber'],
            'no_answer'=>['No answer','slate'],'wrong_number'=>['Wrong number','red'],'not_interested'=>['Not interested','red'],
            'booked'=>['Booked','amber'],'converted'=>['Converted','green']];
    [$label,$tone] = $map[$outcome] ?? [ucfirst(str_replace('_',' ',(string)$outcome)),'slate'];
    return "<span class=\"chip tone-{$tone}\">{$label}</span>";
}

function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $out = mb_substr($parts[0] ?? '', 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '');
    return mb_strtoupper($out ?: '?');
}

function sanitize(string $val): string {
    return htmlspecialchars(trim($val),ENT_QUOTES,'UTF-8');
}

function formatDate(?string $date): string {
    if (!$date) return '—';
    return date('d M Y, h:i A', strtotime($date));
}

function timeSince(?string $date): string {
    if (!$date) return 'Never';
    $diff = time() - strtotime($date);
    if ($diff < 60)    return 'Just now';
    if ($diff < 3600)  return floor($diff/60).'m ago';
    if ($diff < 86400) return floor($diff/3600).'h ago';
    return floor($diff/86400).'d ago';
}

function jsonResponse(mixed $data, int $code=200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ─────────────────────────────────────────────
// CSV UPLOADS
// ─────────────────────────────────────────────
/**
 * Opens an uploaded CSV as UTF-8 whatever Excel saved it as, so Arabic survives:
 * UTF-8 (with/without BOM), UTF-16 ("Unicode text") or Windows-1256 (Arabic Windows "CSV").
 * Returns a readable stream; $encoding reports what the file was.
 */
function openCsvAsUtf8(string $path, ?string &$encoding = null) {
    $raw = @file_get_contents($path);
    if ($raw === false) return false;
    $encoding = 'UTF-8';
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    } elseif (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
        $encoding = str_starts_with($raw, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE';
        $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', $encoding);
        // Excel's "Unicode text" is tab-separated
        if (!str_contains(strtok($raw, "\n"), ',') && str_contains(strtok($raw, "\n"), "\t")) {
            $raw = implode("\n", array_map(function ($line) {
                $cells = explode("\t", rtrim($line, "\r"));
                $h = fopen('php://temp', 'w+'); fputcsv($h, $cells); rewind($h);
                $csv = rtrim(stream_get_contents($h), "\n"); fclose($h);
                return $csv;
            }, explode("\n", $raw)));
        }
    } elseif (!mb_check_encoding($raw, 'UTF-8')) {
        $converted = function_exists('iconv') ? @iconv('CP1256', 'UTF-8//IGNORE', $raw) : false;
        $encoding  = 'Windows-1256 (Arabic)';
        if ($converted === false || $converted === '') {
            $converted = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
            $encoding  = 'Windows-1252';
        }
        $raw = $converted;
    }
    $h = fopen('php://temp', 'w+');
    fwrite($h, $raw);
    rewind($h);
    return $h;
}
