<?php
// pages/api_whatsapp.php — endpoint for the "Clinic CRM – WhatsApp capture" Chrome extension.
// Auth: X-CRM-Key header (create keys on the WhatsApp Capture page).
//
//   GET  ?action=ping                         → {ok, label}
//   GET  ?action=lookup&phone=+9715...        → {found, lead:{id,name,stage,url}}
//   POST ?action=capture  JSON {phone, name, message, auto}
//        → {created:true, lead}  or  {created:false, existing:true, lead}
//   POST ?action=rename   JSON {phone, name, force}
//        → {updated:true|false, lead}   (placeholder names update without force)
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/api_keys.php';
require_once __DIR__ . '/../includes/phone_helpers.php';
require_once __DIR__ . '/../includes/reenquiry.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$db  = getDB();
$key = apiKeyFromRequest($db);
if (!$key) out(['error' => 'Invalid or missing API key'], 401);

$action = $_GET['action'] ?? '';
$base   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');

/** True when the lead's name is a stand-in (e.g. "WhatsApp +9715…", "unknown", or just the number). */
function isPlaceholderName(string $name): bool {
    $n = trim($name);
    return $n === ''
        || preg_match('/^(unknown|n\/?a|-+)$/i', $n)
        || preg_match('/^[?\s.\-]+$/', $n)   // "??? ?????" = Arabic name lost in the March 2026 import
        || preg_match('/^whatsapp\s*\+?[\d\s\-()]*$/i', $n)
        || preg_match('/^\+?[\d\s\-().]+$/', $n);
}

function leadOut(array $l, string $base): array {
    return [
        'id'    => (int)$l['id'],
        'name'  => $l['name'],
        'placeholder' => isPlaceholderName((string)$l['name']),
        'stage' => $l['stage'],
        'owner' => $l['staff_name'] ?? null,
        'url'   => $base . '/pages/lead_view.php?id=' . (int)$l['id'],
    ];
}

switch ($action) {

    case 'ping':
        out(['ok' => true, 'label' => $key['label']]);

    case 'lookup':
        $phone = trim($_GET['phone'] ?? '');
        if (strlen(phoneDigits($phone)) < 7) out(['error' => 'Invalid phone'], 422);
        $lead = findLeadByPhoneLoose($db, $phone);
        out($lead ? ['found' => true, 'lead' => leadOut($lead, $base)] : ['found' => false]);

    case 'capture':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'POST required'], 405);
        $in = json_decode(file_get_contents('php://input'), true) ?: [];

        $digits = phoneDigits((string)($in['phone'] ?? ''));
        if (strlen($digits) < 8 || strlen($digits) > 15) out(['error' => 'Invalid phone'], 422);
        $phone   = '+' . $digits;
        $name    = trim(mb_substr((string)($in['name'] ?? ''), 0, 120));
        $message = trim(mb_substr((string)($in['message'] ?? ''), 0, 1000));
        $auto    = !empty($in['auto']);

        if ($existing = findLeadByPhoneLoose($db, $phone)) {
            // A closed lead (lost, cold or already a patient) messaging again after a quiet month is a new enquiry;
            // ongoing chats are not, and one flag a week is enough
            ensureReEnquirySchema($db);
            $q = $db->prepare("SELECT 1 FROM leads WHERE id = ? AND stage IN ('lost','cold','converted')
                               AND (last_contacted IS NULL OR last_contacted < NOW() - INTERVAL 30 DAY)
                               AND (reenquiry_at IS NULL OR reenquiry_at < NOW() - INTERVAL 7 DAY)");
            $q->execute([(int)$existing['id']]);
            if ($q->fetchColumn()) recordReEnquiry($db, (int)$existing['id'], 'WhatsApp message', $message !== '' ? "\"$message\"" : '');
            out(['created' => false, 'existing' => true, 'lead' => leadOut($existing, $base)]);
        }

        if ($name === '' || preg_match('/^[\d\s+\-()]+$/', $name)) $name = 'WhatsApp ' . $phone;
        $notes = $message !== '' ? "First WhatsApp message: \"$message\"" : '';
        $assignTo = $key['assign_to'] ? (int)$key['assign_to'] : null;

        $db->prepare("
            INSERT INTO leads (name, phone, source, source_campaign, stage, assigned_to, notes)
            VALUES (?, ?, 'whatsapp', ?, 'new', ?, ?)
        ")->execute([$name, $phone, $auto ? 'WhatsApp Web (auto)' : 'WhatsApp Web', $assignTo, $notes]);
        $leadId = (int)$db->lastInsertId();

        saveLeadPhones($leadId, [$phone], $db);
        updateLeadScore($leadId, $db);
        $db->prepare("INSERT INTO activity_log (staff_id, lead_id, action, new_value, ip_address) VALUES (?,?,?,?,?)")
           ->execute([$key['created_by'], $leadId, 'lead_created_whatsapp', $key['label'], $_SERVER['REMOTE_ADDR'] ?? null]);

        $stmt = $db->prepare("SELECT l.id, l.name, l.stage, s.name AS staff_name FROM leads l LEFT JOIN staff s ON s.id = l.assigned_to WHERE l.id = ?");
        $stmt->execute([$leadId]);
        out(['created' => true, 'lead' => leadOut($stmt->fetch(), $base)], 201);

    case 'rename':
        // Update a lead's name from the WhatsApp contact name.
        // Placeholder names are replaced automatically; real names only with "force".
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'POST required'], 405);
        $in    = json_decode(file_get_contents('php://input'), true) ?: [];
        $name  = trim(mb_substr((string)($in['name'] ?? ''), 0, 120));
        $force = !empty($in['force']);
        if ($name === '' || isPlaceholderName($name)) out(['error' => 'Invalid name'], 422);
        $lead = findLeadByPhoneLoose($db, (string)($in['phone'] ?? ''));
        if (!$lead) out(['error' => 'Lead not found'], 404);
        if ($lead['name'] === $name) out(['updated' => false, 'lead' => leadOut($lead, $base)]);
        if (!$force && !isPlaceholderName((string)$lead['name'])) {
            out(['updated' => false, 'reason' => 'has_name', 'lead' => leadOut($lead, $base)]);
        }
        $db->prepare("UPDATE leads SET name = ?, updated_at = NOW() WHERE id = ?")->execute([$name, $lead['id']]);
        $db->prepare("INSERT INTO activity_log (staff_id, lead_id, action, old_value, new_value, ip_address) VALUES (?,?,?,?,?,?)")
           ->execute([$key['created_by'], $lead['id'], 'lead_renamed_whatsapp', $lead['name'], $name, $_SERVER['REMOTE_ADDR'] ?? null]);
        $lead['name'] = $name;
        out(['updated' => true, 'lead' => leadOut($lead, $base)]);

    default:
        out(['error' => 'Unknown action'], 400);
}
