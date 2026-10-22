<?php
// local/seed.php — LOCAL DEVELOPMENT ONLY.
// Fills clinic_crm_local with fake staff, leads and follow-ups so every screen has data.
// Usage: C:\xampp\php\php.exe local\seed.php
// Test logins are written to local/test_accounts.txt

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (currentTenant()['db_name'] !== 'clinic_crm_local') die("Refusing to seed a non-local database.\n");

$db = getDB();
mt_srand(42);

$db->exec("SET FOREIGN_KEY_CHECKS=0");
foreach (['activity_log','followups','lead_phones','lead_score_log','lead_treatments','leads','meta_webhook_log','reminders','staff'] as $t) {
    $db->exec("TRUNCATE TABLE `$t`");
}
$db->exec("SET FOREIGN_KEY_CHECKS=1");

// ── Staff ──
$password = 'Test-' . bin2hex(random_bytes(4));
$hash = password_hash($password, PASSWORD_BCRYPT);
$staff = [
    ['Test Admin',       'admin@clinic.test',     'admin'],
    ['Layla Consultant', 'layla@clinic.test',     'consultant'],
    ['Omar Consultant',  'omar@clinic.test',      'consultant'],
    ['Nadia Reception',  'reception@clinic.test', 'receptionist'],
];
$ins = $db->prepare("INSERT INTO staff (name,email,password,role,phone) VALUES (?,?,?,?,?)");
foreach ($staff as $s) $ins->execute([$s[0], $s[1], $hash, $s[2], '+97150' . mt_rand(1000000, 9999999)]);
$staffIds = $db->query("SELECT id FROM staff WHERE role!='admin'")->fetchAll(PDO::FETCH_COLUMN);
$allStaff = $db->query("SELECT id FROM staff")->fetchAll(PDO::FETCH_COLUMN);

file_put_contents(__DIR__ . '/test_accounts.txt',
    "Local test accounts (all share one password)\n" .
    "admin@clinic.test / layla@clinic.test / omar@clinic.test / reception@clinic.test\n" .
    "Password: $password\n");

// ── Leads ──
$first = ['Aisha','Fatima','Mariam','Sara','Noor','Hessa','Reem','Latifa','Priya','Anna','Olga','Elena','Chloe','Hannah','Zainab','Yasmin','Dana','Lina','Rania','Mohammed','Ahmed','Khalid','Omar','Yousef','James','Daniel','Ravi','Sofia','Amira','Salma'];
$last  = ['Al Mansoori','Khan','Haddad','Petrova','Smith','Nair','Rahman','Al Falasi','Ivanova','Hassan','Farouk','Sharma','Brown','Al Suwaidi','Aziz','Ali'];
$sources   = ['meta_form'=>30,'whatsapp'=>25,'instagram'=>12,'facebook'=>6,'phone_call'=>8,'walkin'=>7,'referral'=>7,'other'=>5];
$stages    = ['new'=>22,'contacted'=>20,'qualified'=>14,'booked'=>10,'converted'=>12,'lost'=>10,'cold'=>12];
$budgets   = ['under_500'=>15,'500_1000'=>25,'1000_3000'=>25,'3000_plus'=>10,'unknown'=>25];
$urgencies = ['immediate'=>25,'within_month'=>30,'3_months'=>15,'just_browsing'=>10,'unknown'=>20];
$campaigns = ['Hydrafacial Summer','Dental Veneers UAE','Botox Promo','Laser Hair Removal','Hair PRP Launch',null];
$lostReasons = ['Too expensive','Chose another clinic','Not reachable','Just browsing'];
$channels = ['whatsapp','phone_call','in_person','email','sms'];
$outcomes = ['interested','needs_info','price_objection','no_answer','not_interested','booked'];
$remarks  = ['Asked about price and downtime.','Will confirm after checking schedule.','Sent before/after photos on WhatsApp.','Prefers weekend appointment.','Wants to compare with another clinic.','No answer, left a voicemail.','Interested in a package deal.','Booked consultation with doctor.'];
$treatmentIds = $db->query("SELECT id FROM treatments")->fetchAll(PDO::FETCH_COLUMN);

function pick(array $weighted): string {
    $r = mt_rand(1, array_sum($weighted));
    foreach ($weighted as $k => $w) { if (($r -= $w) <= 0) return $k; }
    return array_key_first($weighted);
}

$leadIns = $db->prepare("INSERT INTO leads (name,phone,email,gender,age_range,source,source_campaign,stage,lost_reason,assigned_to,budget_range,urgency,notes,last_contacted,next_followup,created_at,followup_count,followup_1_done,followup_1_at,converted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$fuIns   = $db->prepare("INSERT INTO followups (lead_id,staff_id,channel,outcome,remarks,next_followup_at,next_action,created_at) VALUES (?,?,?,?,?,?,?,?)");
$phIns   = $db->prepare("INSERT INTO lead_phones (lead_id,phone) VALUES (?,?)");
$ltIns   = $db->prepare("INSERT IGNORE INTO lead_treatments (lead_id,treatment_id) VALUES (?,?)");
$remIns  = $db->prepare("INSERT INTO reminders (lead_id,staff_id,message,remind_at,is_sent,type) VALUES (?,?,?,?,?,?)");

$now = time();
for ($i = 0; $i < 240; $i++) {
    $name    = $first[array_rand($first)] . ' ' . $last[array_rand($last)];
    $phone   = '+9715' . mt_rand(0, 8) . mt_rand(1000000, 9999999);
    $created = $now - mt_rand(0, 150 * 86400);
    $stage   = pick($stages);
    $source  = pick($sources);
    $assigned = mt_rand(1, 10) > 1 ? $staffIds[array_rand($staffIds)] : null;
    $fuCount = $stage === 'new' ? 0 : mt_rand(1, 4);
    $lastContacted = $fuCount ? min($now, $created + mt_rand(600, 20 * 86400)) : null;
    $nextFu = in_array($stage, ['contacted','qualified','booked']) ? $now + mt_rand(-5 * 86400, 7 * 86400) : null;
    $convertedAt = $stage === 'converted' ? date('Y-m-d H:i:s', min($now, $created + mt_rand(2, 30) * 86400)) : null;

    $leadIns->execute([
        $name, $phone, mt_rand(0, 2) ? strtolower(str_replace(' ', '.', $name)) . '@example.com' : null,
        ['female','female','female','male','unknown'][mt_rand(0, 4)],
        ['18-24','25-34','35-44','45-54','55+','unknown'][mt_rand(0, 5)],
        $source, $source === 'meta_form' ? $campaigns[array_rand($campaigns)] : null,
        $stage, $stage === 'lost' ? $lostReasons[array_rand($lostReasons)] : null,
        $assigned, pick($budgets), pick($urgencies),
        mt_rand(0, 2) ? null : $remarks[array_rand($remarks)],
        $lastContacted ? date('Y-m-d H:i:s', $lastContacted) : null,
        $nextFu ? date('Y-m-d H:i:s', $nextFu) : null,
        date('Y-m-d H:i:s', $created),
        min($fuCount, 3), $fuCount ? 1 : 0, $lastContacted ? date('Y-m-d H:i:s', $lastContacted) : null,
        $convertedAt,
    ]);
    $leadId = (int)$db->lastInsertId();

    $phIns->execute([$leadId, $phone]);
    if (mt_rand(1, 6) === 1) $phIns->execute([$leadId, '+9715' . mt_rand(0, 8) . mt_rand(1000000, 9999999)]);

    foreach ((array)array_rand(array_flip($treatmentIds), mt_rand(1, 3)) as $tid) $ltIns->execute([$leadId, $tid]);

    $t = $created;
    for ($f = 0; $f < $fuCount; $f++) {
        $t = min($now, $t + mt_rand(3600, 6 * 86400));
        $isLast = $f === $fuCount - 1;
        $fuIns->execute([
            $leadId, $assigned ?? $allStaff[0], $channels[array_rand($channels)],
            $isLast && $stage === 'booked' ? 'booked' : ($isLast && $stage === 'converted' ? 'converted' : $outcomes[array_rand($outcomes)]),
            $remarks[array_rand($remarks)],
            $isLast && $nextFu ? date('Y-m-d H:i:s', $nextFu) : null,
            $isLast && $nextFu ? 'Call back to confirm' : null,
            date('Y-m-d H:i:s', $t),
        ]);
    }

    if ($assigned && $nextFu && mt_rand(0, 1)) {
        $remIns->execute([$leadId, $assigned, "Follow up with $name", date('Y-m-d H:i:s', $nextFu), $nextFu < $now ? mt_rand(0, 1) : 0, 'followup_due']);
    }

    updateLeadScore($leadId, $db);
}

updateAgingStatuses($db);
echo "Seeded 240 leads. Logins in local/test_accounts.txt\n";
