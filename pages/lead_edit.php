<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/phone_helpers.php';
require_once __DIR__ . '/../includes/inventory.php';
requireLogin();

$db = getDB();
ensureInventorySchema($db);
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /pages/leads.php'); exit; }

$stmt = $db->prepare("SELECT * FROM leads WHERE id=?");
$stmt->execute([$id]);
$lead = $stmt->fetch();
if (!$lead) { http_response_code(404); die('Lead not found.'); }
if (!can('leads.all') && !empty($lead['assigned_to']) && (int)$lead['assigned_to'] !== currentStaffId()) {
    http_response_code(403); die('Access denied.');
}

$allStaff      = $db->query("SELECT id,name FROM staff WHERE is_active=1 ORDER BY name")->fetchAll();
$allTreatments = $db->query("SELECT * FROM treatments WHERE is_active=1 ORDER BY sort_order")->fetchAll();
$ctStmt        = $db->prepare("SELECT treatment_id FROM lead_treatments WHERE lead_id=?");
$ctStmt->execute([$id]);
$currentTreats = array_column($ctStmt->fetchAll(), 'treatment_id');

// Get existing phones
$existingPhones = getLeadPhones($id, $db);
if (empty($existingPhones)) {
    $existingPhones = [['id' => 0, 'phone' => $lead['phone']]];
}

$sources = [];
try {
    $sources = $db->query("SELECT slug,name,icon FROM lead_sources WHERE is_active=1 ORDER BY sort_order")->fetchAll();
} catch (PDOException $e) {
    $sources = [
        ['slug'=>'meta_form','name'=>'Meta Form','icon'=>'🎯'],
        ['slug'=>'whatsapp','name'=>'WhatsApp','icon'=>'💬'],
        ['slug'=>'instagram','name'=>'Instagram','icon'=>'📸'],
        ['slug'=>'facebook','name'=>'Facebook','icon'=>'👍'],
        ['slug'=>'phone_call','name'=>'Phone Call','icon'=>'📞'],
        ['slug'=>'walkin','name'=>'Walk-in','icon'=>'🚶'],
        ['slug'=>'referral','name'=>'Referral','icon'=>'🤝'],
        ['slug'=>'other','name'=>'Other','icon'=>'📌'],
    ];
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = trim($_POST['name']            ?? '');
    $phones     = $_POST['phones']               ?? [];
    $email      = trim($_POST['email']           ?? '');
    $source     = $_POST['source']               ?? 'other';
    $campaign   = trim($_POST['source_campaign'] ?? '');
    $referrer   = trim($_POST['referrer_name']   ?? '');
    $stage      = $_POST['stage']                ?? 'new';
    $lostReason = trim($_POST['lost_reason']     ?? '');
    $assignedTo = (int)($_POST['assigned_to']    ?? 0) ?: null;
    $budget     = $_POST['budget_range']         ?? 'unknown';
    $urgency    = $_POST['urgency']              ?? 'unknown';
    $gender     = $_POST['gender']               ?? 'unknown';
    $ageRange   = $_POST['age_range']            ?? 'unknown';
    $notes      = trim($_POST['notes']           ?? '');
    $treats     = $_POST['treatments']           ?? [];

    $phones = array_values(array_filter(array_map('trim', $phones)));
    $primaryPhone = $phones[0] ?? $lead['phone'];

    if (!$name)         $errors[] = 'Name required.';
    if (empty($phones)) $errors[] = 'At least one phone number required.';

    if (empty($errors)) {
        $oldStage = $lead['stage'];
        $db->prepare("UPDATE leads SET name=?,phone=?,email=?,source=?,source_campaign=?,referrer_name=?,stage=?,lost_reason=?,assigned_to=?,budget_range=?,urgency=?,gender=?,age_range=?,notes=?,updated_at=NOW() WHERE id=?")
           ->execute([trim($name),$primaryPhone,trim($email),$source,trim($campaign),trim($referrer),$stage,trim($lostReason),$assignedTo,$budget,$urgency,$gender,$ageRange,trim($notes),$id]);

        // Save all phone numbers
        saveLeadPhones($id, $phones, $db);

        // Save treatments
        $db->prepare("DELETE FROM lead_treatments WHERE lead_id=?")->execute([$id]);
        if (!empty($treats)) {
            $ins = $db->prepare("INSERT IGNORE INTO lead_treatments (lead_id,treatment_id) VALUES (?,?)");
            foreach ($treats as $tid) $ins->execute([$id,(int)$tid]);
        }

        setConvertedAt($id, $stage, $oldStage, $db);
        updateLeadScore($id, $db);
        logActivity(currentStaffId(), 'lead_updated', $id, $oldStage, $stage);
        if (in_array($stage, ['lost', 'cold'], true) && !empty($_POST['cancel_appointments']) && can('appointments.book')) {
            cancelOpenAppointmentsForLead($db, $id, 'Lead marked ' . $stage);
        }

        $redirectUrl = '/pages/lead_view.php?id='.$id.'&updated=1';
        if (!headers_sent()) {
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            echo '<script>window.location.href="' . $redirectUrl . '";</script>';
            exit;
        }
    }

    // Re-populate on error
    $existingPhones = array();
    foreach ($phones as $p) {
        $existingPhones[] = array('id' => 0, 'phone' => $p);
    }
    $lead = array_merge($lead, ['name'=>$name,'email'=>$email,'source'=>$source,'source_campaign'=>$campaign,'referrer_name'=>$referrer,'stage'=>$stage,'lost_reason'=>$lostReason,'assigned_to'=>$assignedTo,'budget_range'=>$budget,'urgency'=>$urgency,'gender'=>$gender,'age_range'=>$ageRange,'notes'=>$notes]);
    $currentTreats = array_map('intval', $treats);
}

$f = $lead;
$formOpenAppts = can('appointments.book') ? openAppointmentCount($db, $id) : 0;
$formMode       = 'edit';
$formPhones     = array_column($existingPhones, 'phone') ?: [''];
$selectedTreats = $currentTreats;
$cancelHref     = '/pages/lead_view.php?id=' . $id;

$pageTitle = 'Edit lead';
$pageBack  = ['href' => $cancelHref, 'label' => $lead['name']];
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($errors): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill"></i><div><?= implode('<br>', array_map('sanitize', $errors)) ?></div></div>
<?php endif; ?>

<?php
require __DIR__ . '/../includes/lead_form.php';
require_once __DIR__ . '/../includes/footer.php';
?>
