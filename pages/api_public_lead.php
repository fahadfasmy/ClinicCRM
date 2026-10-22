<?php
// pages/api_public_lead.php — enquiry forms on the clinic's landing pages (hydrafacial.html, dental.html) post here.
// Saves the lead, answers the visitor straight away, then tells Meta about the enquiry (Conversions API) using the
// CRM's saved Meta connection. The page sends the same event from the browser pixel with the same event_id.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/phone_helpers.php';
require_once __DIR__ . '/../includes/reenquiry.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$db = getDB();
$landing = preg_replace('/[^a-z]/', '', strtolower((string)($_POST['landing'] ?? '')));
$eventId = preg_replace('/[^A-Za-z0-9_.:-]/', '', (string)($_POST['event_id'] ?? ''));
$primaryPhone = '';

try {
    $name   = trim($_POST['name'] ?? '');
    $phones = $_POST['phones'] ?? [];
    $source = trim($_POST['source'] ?? 'meta_form');
    $notes  = trim($_POST['notes'] ?? '');

    if (!is_array($phones)) {
        $phones = [$phones];
    }
    $phones = array_values(array_filter(array_map('trim', $phones)));
    $primaryPhone = $phones[0] ?? '';

    if (!$name || empty($phones)) {
        echo json_encode(['error' => 'Name and phone are required.']);
        exit;
    }

    if ($dup = findDuplicateByAnyPhone($phones, $db)) {
        // Someone already in the CRM enquired again: keep the enquiry on their lead and flag it
        recordReEnquiry($db, (int)$dup['id'], 'Website form' . ($landing !== '' ? " – $landing" : ''), $notes);
        $out = ['success' => true, 'message' => 'Thank you, we have your enquiry.'];
    } else {
        $db->prepare("INSERT INTO leads (name, phone, source, stage, assigned_to, notes) VALUES (?, ?, ?, 'new', NULL, ?)")
           ->execute([$name, $primaryPhone, $source, $notes]);
        $leadId = (int)$db->lastInsertId();
        saveLeadPhones($leadId, $phones, $db);
        updateLeadScore($leadId, $db);
        $out = ['success' => true, 'lead_id' => $leadId];
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
    exit;
}

// Answer the visitor first, so Meta's response time never slows the form
echo json_encode($out);
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();

// Every enquiry counts as a Lead for the ads, including people who had enquired before
if (isset(metaWebPixels()[$landing])) {
    try { metaSendWebLead($db, $landing, $eventId, $primaryPhone, (string)($_SERVER['HTTP_REFERER'] ?? '')); } catch (Throwable $e) {}
}
