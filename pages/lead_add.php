<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/phone_helpers.php';
requireLogin();

$db = getDB();

$prefill = isset($_GET['prefill']);
$pre = [
    'name'   => trim($_GET['name']   ?? ''),
    'phone'  => trim($_GET['phone']  ?? ''),
    'source' => trim($_GET['source'] ?? 'other'),
];

$allStaff      = $db->query("SELECT id,name FROM staff WHERE is_active=1 ORDER BY name")->fetchAll();
$allTreatments = $db->query("SELECT * FROM treatments WHERE is_active=1 ORDER BY sort_order")->fetchAll();

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
$dupLead = null;
$canUpdateDup = false;

/** True for stand-in names such as "WhatsApp +9715…", "unknown", "????" or just a number. */
function isPlaceholderLeadName(string $name): bool {
    $n = trim($name);
    return $n === '' || preg_match('/^(unknown|n\/?a)$/i', $n) || preg_match('/^[?\s.\-]+$/', $n)
        || preg_match('/^whatsapp\s*\+?[\d\s\-()]*$/i', $n) || preg_match('/^\+?[\d\s\-().]+$/', $n);
}

/**
 * Adds what was typed on the New lead form to an existing lead with the same phone.
 * Keeps everything already recorded: only fills empty details, adds phones / interests / notes,
 * moves the stage forward (never back) and changes the name only when asked.
 */
function mergeIntoExistingLead(PDO $db, array $lead, array $in): void {
    $id = (int)$lead['id'];
    $fill = fn($field, $new, $empty = ['', null, 'unknown']) => in_array($lead[$field] ?? null, $empty, true) && !in_array($new, $empty, true) ? $new : $lead[$field];
    $pipeline = ['new', 'contacted', 'qualified', 'booked', 'converted'];
    $oldIdx = array_search($lead['stage'], $pipeline, true);
    $newIdx = array_search($in['stage'], $pipeline, true);
    $stage  = ($oldIdx !== false && $newIdx !== false && $newIdx > $oldIdx) ? $in['stage'] : $lead['stage'];
    $notes  = trim((string)$lead['notes']);
    if ($in['notes'] !== '') $notes = trim($notes . "\n\n" . date('d M Y') . ' (' . staffName() . '): ' . $in['notes']);
    $name   = $in['rename'] && $in['name'] !== '' ? $in['name'] : $lead['name'];

    $db->prepare("UPDATE leads SET name=?, email=?, source_campaign=?, referrer_name=?, stage=?, assigned_to=?, budget_range=?, urgency=?,
                  gender=?, age_range=?, notes=?, updated_at=NOW() WHERE id=?")
       ->execute([$name, $fill('email', $in['email']), $fill('source_campaign', $in['campaign']), $fill('referrer_name', $in['referrer']),
                  $stage, $lead['assigned_to'] ?: $in['assigned_to'], $fill('budget_range', $in['budget_range']), $fill('urgency', $in['urgency']),
                  $fill('gender', $in['gender']), $fill('age_range', $in['age_range']), $notes ?: null, $id]);

    // Keep existing numbers first, then any new ones
    $existing = array_column(getLeadPhones($id, $db), 'phone');
    $norm = fn($p) => preg_replace('/\D/', '', $p);
    $known = array_map($norm, $existing);
    foreach ($in['phones'] as $p) if (!in_array($norm($p), $known, true)) { $existing[] = $p; $known[] = $norm($p); }
    saveLeadPhones($id, $existing, $db);

    $insT = $db->prepare("INSERT IGNORE INTO lead_treatments (lead_id, treatment_id) VALUES (?, ?)");
    foreach ($in['treatments'] as $tid) $insT->execute([$id, (int)$tid]);

    if ($stage === 'converted' && $lead['stage'] !== 'converted') setConvertedAt($id, 'converted', $lead['stage'], $db);
    updateLeadScore($id, $db);
    logActivity(currentStaffId(), 'lead_merged_from_new', $id, $lead['name'], $name);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = trim($_POST['name']            ?? '');
    $phones     = $_POST['phones']               ?? []; // array of phone numbers
    $email      = trim($_POST['email']           ?? '');
    $source     = $_POST['source']               ?? 'other';
    $campaign   = trim($_POST['source_campaign'] ?? '');
    $referrer   = trim($_POST['referrer_name']   ?? '');
    $stage      = $_POST['stage']                ?? 'new';
    $assignedTo = (int)($_POST['assigned_to']    ?? 0) ?: null;
    $budget     = $_POST['budget_range']         ?? 'unknown';
    $urgency    = $_POST['urgency']              ?? 'unknown';
    $gender     = $_POST['gender']               ?? 'unknown';
    $ageRange   = $_POST['age_range']            ?? 'unknown';
    $notes      = trim($_POST['notes']           ?? '');
    $treats     = $_POST['treatments']           ?? [];

    // Clean phones array
    $phones = array_values(array_filter(array_map('trim', $phones)));
    $primaryPhone = $phones[0] ?? '';

    if (!$name)          $errors[] = 'Name is required.';
    if (empty($phones))  $errors[] = 'At least one phone number is required.';

    // Duplicate check across all phones
    if (!empty($phones)) {
        $dup = findDuplicateByAnyPhone($phones, $db);
        if ($dup) {
            $s = $db->prepare("SELECT l.*, s.name AS staff_name FROM leads l LEFT JOIN staff s ON s.id = l.assigned_to WHERE l.id = ?");
            $s->execute([(int)$dup['id']]);
            $dupLead = $s->fetch();
            $canUpdateDup = $dupLead && (can('leads.all') || empty($dupLead['assigned_to']) || (int)$dupLead['assigned_to'] === currentStaffId());

            // "Update this lead": merge what was typed into the existing lead instead of creating a duplicate
            if ($dupLead && (int)($_POST['update_existing'] ?? 0) === (int)$dupLead['id'] && $canUpdateDup && $name) {
                mergeIntoExistingLead($db, $dupLead, [
                    'name' => $name, 'rename' => !empty($_POST['update_name']), 'phones' => $phones, 'email' => $email,
                    'campaign' => $campaign, 'referrer' => $referrer, 'stage' => $stage, 'assigned_to' => $assignedTo,
                    'budget_range' => $budget, 'urgency' => $urgency, 'gender' => $gender, 'age_range' => $ageRange,
                    'notes' => $notes, 'treatments' => $treats,
                ]);
                header('Location: /pages/lead_view.php?id=' . (int)$dupLead['id'] . '&merged=1');
                exit;
            }
            $errors[] = 'This phone number already belongs to <a href="/pages/lead_view.php?id=' . (int)$dup['id'] . '" target="_blank">' . sanitize($dup['name']) . '</a> (' . sanitize($dup['stage']) . ').';
        }
    }

    if (empty($errors)) {
        $db->prepare("
            INSERT INTO leads
              (name,phone,email,source,source_campaign,referrer_name,
               stage,assigned_to,budget_range,urgency,gender,age_range,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([$name,$primaryPhone,$email,$source,$campaign,$referrer,
                     $stage,$assignedTo,$budget,$urgency,$gender,$ageRange,$notes]);

        $leadId = (int)$db->lastInsertId();

        // Save all phone numbers
        saveLeadPhones($leadId, $phones, $db);

        // Save treatments
        if (!empty($treats)) {
            $insT = $db->prepare("INSERT IGNORE INTO lead_treatments (lead_id,treatment_id) VALUES (?,?)");
            foreach ($treats as $tid) $insT->execute([$leadId,(int)$tid]);
        }

        updateLeadScore($leadId, $db);
        logActivity(currentStaffId(), 'lead_created', $leadId, null, $name);

        $redirectUrl = '/pages/lead_view.php?id='.$leadId.'&created=1';
        if (!headers_sent()) {
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            echo '<script>window.location.href="' . $redirectUrl . '";</script>';
            exit;
        }
    }

    $pre['name']  = $name;
    $pre['phone'] = $primaryPhone;
    $pre['source']= $source;
}

// Pre-fill phones from URL or POST
$initialPhones = [];
if (!empty($_POST['phones'])) {
    $initialPhones = array_filter(array_map('trim', $_POST['phones']));
} elseif ($pre['phone']) {
    $initialPhones = [$pre['phone']];
} else {
    $initialPhones = [''];
}

$f = [
    'name' => $pre['name'], 'source' => $pre['source'],
    'email' => $_POST['email'] ?? '', 'source_campaign' => $_POST['source_campaign'] ?? '', 'referrer_name' => $_POST['referrer_name'] ?? '',
    'gender' => $_POST['gender'] ?? 'unknown', 'age_range' => $_POST['age_range'] ?? 'unknown',
    'budget_range' => $_POST['budget_range'] ?? 'unknown', 'urgency' => $_POST['urgency'] ?? 'unknown',
    'stage' => $_POST['stage'] ?? 'new', 'assigned_to' => $_POST['assigned_to'] ?? (can('leads.all') ? '' : currentStaffId()),
    'notes' => $_POST['notes'] ?? '',
];
$formMode       = 'add';
$formPhones     = $initialPhones;
$selectedTreats = $_POST['treatments'] ?? [];
$cancelHref     = '/pages/leads.php';

$pageTitle    = 'New lead';
$pageBack     = ['href' => '/pages/leads.php', 'label' => 'All leads'];
$pageSubtitle = 'Only name and phone are required. You can fill in the rest later.';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($prefill && ($pre['name'] || $pre['phone'])): ?>
<div class="alert alert-info alert-auto"><i class="bi bi-whatsapp"></i><?= t('Contact details pre-filled from WhatsApp.') ?></div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill"></i><div><?= implode('<br>', $errors) ?></div></div>
<?php endif; ?>

<?php if ($dupLead): $typedName = trim($_POST['name'] ?? ''); $nameDiffers = $typedName !== '' && $typedName !== $dupLead['name']; ?>
<div class="card-crm page-section dup-card">
  <div class="card-body-crm d-flex flex-wrap align-items-center gap-3">
    <span class="avatar"><?= sanitize(initials($dupLead['name'])) ?></span>
    <div class="flex-grow-1 min-w-0">
      <div class="fw-600"><?= sanitize($dupLead['name']) ?> <?= stageBadge($dupLead['stage']) ?></div>
      <div class="fs-12 text-tx-3"><?= sanitize($dupLead['phone']) ?> · <?= $dupLead['staff_name'] ? 'Owner: ' . sanitize($dupLead['staff_name']) : 'Unassigned' ?> · added <?= date('d M Y', strtotime($dupLead['created_at'])) ?></div>
      <?php if ($canUpdateDup): ?>
      <div class="fs-12 text-tx-2 mt-1"><?= t('Update adds the new phone numbers, interests and notes, fills in any details that are empty, and moves the stage forward if you picked a later one. Nothing already recorded is overwritten.') ?></div>
      <?php if ($nameDiffers): ?>
      <div class="form-check mt-2 mb-0">
        <input class="form-check-input" type="checkbox" form="leadForm" name="update_name" value="1" id="updName" <?= isPlaceholderLeadName($dupLead['name']) ? 'checked' : '' ?>>
        <label class="form-check-label fs-13" for="updName"><?= t('Change the name to') ?> <strong><?= sanitize($typedName) ?></strong></label>
      </div>
      <?php endif; ?>
      <?php else: ?>
      <div class="fs-12 text-amber mt-1">This lead belongs to <?= sanitize($dupLead['staff_name'] ?? 'another team member') ?>. Ask them or an admin to update it.</div>
      <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
      <a href="/pages/lead_view.php?id=<?= (int)$dupLead['id'] ?>" class="btn btn-ghost" target="_blank"><i class="bi bi-box-arrow-up-right"></i><?= t('Open lead') ?></a>
      <?php if ($canUpdateDup): ?>
      <button type="submit" form="leadForm" name="update_existing" value="<?= (int)$dupLead['id'] ?>" class="btn btn-gold"><i class="bi bi-arrow-repeat"></i><?= t('Update this lead') ?></button>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
require __DIR__ . '/../includes/lead_form.php';
require_once __DIR__ . '/../includes/footer.php';
?>
