<?php
// Phase 2: gapless document numbers (also under two competing processes), weekly schedules, free slots around time off,
// and a pre-migrations clinic database adopting the baseline. Scratch database clinic_crm_test_setup.
require_once __DIR__ . '/../includes/clinic_setup.php';

/** A fresh scratch clinic database with every tenant migration applied, plus one branch and one practitioner. */
function setupDb(): PDO {
    static $done = false;
    $db = testDb('clinic_crm_test_setup', !$done);
    if (!$done) {
        migrateApply($db, __DIR__ . '/../migrations/tenant', fn($l) => null);
        $db->exec("UPDATE branches SET invoice_prefix = 'TST' WHERE id = 1");
        $db->exec("INSERT INTO staff (name, email, password, role, branch_id) VALUES ('Dr Test', 'drtest@example.test', 'x', 'practitioner', 1)");
        $done = true;
    }
    return $db;
}

function test_numbers_are_sequential_per_branch_and_year_and_gapless() {
    $db = setupDb();
    $db->exec("INSERT INTO branches (name, invoice_prefix) VALUES ('Second', 'TWO')");
    $two = (int)$db->lastInsertId();
    $take = function (string $type, int $branch, bool $commit = true) use ($db) {
        $db->beginTransaction(); $n = nextDocumentNumber($db, $type, $branch); $commit ? $db->commit() : $db->rollBack(); return $n;
    };
    $y = (new DateTimeImmutable('now', new DateTimeZone(CLINIC_TZ)))->format('Y');
    check($take('invoice', 1) === "TST-INV-$y-000001", 'first invoice number');
    check($take('invoice', 1) === "TST-INV-$y-000002", 'next one');
    check($take('invoice', $two) === "TWO-INV-$y-000001", 'each branch counts on its own');
    check($take('receipt', 1) === "TST-RCT-$y-000001", 'each document type counts on its own');
    $take('invoice', 1, false);
    check($take('invoice', 1) === "TST-INV-$y-000003", 'a rolled-back invoice gives its number back (no gap)');
    $db->beginTransaction(); $m = nextDocumentNumber($db, 'mrn', 0); $db->commit();
    check($m === 'MRN-000001', 'MRN is one clinic-wide sequence');
    checkThrows(fn() => nextSequenceNo($db, 'invoice', 1, 2026), 'inside the transaction', 'numbers are refused outside a transaction');
    $db->beginTransaction();
    checkThrows(fn() => nextDocumentNumber($db, 'invoice', 999), 'Unknown branch', 'unknown branch is refused');
    $db->rollBack();
}

function test_two_processes_never_get_the_same_number() {
    setupDb();
    $dir = tempDir('seq');
    // Each worker takes 40 numbers; every 7th is rolled back (an abandoned invoice) and must be reused, not lost
    file_put_contents("$dir/w.php", '<?php require ' . var_export(realpath(__DIR__ . '/lib.php'), true) . '; require ' . var_export(realpath(__DIR__ . '/../includes/clinic_setup.php'), true) . ';
        $db = testDb("clinic_crm_test_setup"); $out = [];
        for ($i = 1; $i <= 40; $i++) { $db->beginTransaction(); $n = nextSequenceNo($db, "claim", 1, 2030); usleep(random_int(0, 3000));
            if ($i % 7 === 0) $db->rollBack(); else { $db->commit(); $out[] = $n; } }
        echo json_encode($out);');
    $procs = []; $pipes = [];
    foreach ([0, 1] as $k) $procs[$k] = proc_open([phpBin(), "$dir/w.php"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k]);
    $all = [];
    foreach ([0, 1] as $k) { $all = array_merge($all, json_decode(stream_get_contents($pipes[$k][1]), true) ?: []); proc_close($procs[$k]); }
    sort($all);
    check(count($all) === 70, 'both workers finished (' . count($all) . ' committed numbers)');
    check($all === range(1, count($all)), 'every committed number is unique and there are no gaps');
    rmTree($dir);
}

function test_schedule_rows_are_checked() {
    check(validateScheduleRows([[1, '10:00', '14:00'], [1, '17:00', '21:00'], [2, '', '']]) === [[1, '10:00', '14:00'], [1, '17:00', '21:00']], 'split shift accepted, empty day skipped');
    checkThrows(fn() => validateScheduleRows([[1, '14:00', '10:00']]), 'must be after', 'end before start refused');
    checkThrows(fn() => validateScheduleRows([[3, '10:00', '15:00'], [3, '14:00', '18:00']]), 'overlap', 'overlapping stretches refused');
    checkThrows(fn() => validateScheduleRows([[1, '9am', '14:00']]), 'look like', 'bad time format refused');
}

function test_free_slots_follow_the_schedule_and_skip_time_off() {
    $db = setupDb();
    $doc = (int)$db->query("SELECT id FROM staff WHERE email = 'drtest@example.test'")->fetchColumn();
    $tz = new DateTimeZone(CLINIC_TZ);
    $day = (new DateTimeImmutable('next monday', $tz))->format('Y-m-d');
    savePractitionerSchedule($db, $doc, 1, [[1, '10:00', '12:00'], [1, '17:00', '18:00']]);
    $slots = freeSlots($db, $doc, 1, $day, 30, 30);
    check($slots === ["$day 10:00", "$day 10:30", "$day 11:00", "$day 11:30", "$day 17:00", "$day 17:30"], 'slots only inside both stretches: ' . implode(',', array_map(fn($s) => substr($s, 11), $slots)));
    check(freeSlots($db, $doc, 1, (new DateTimeImmutable('next tuesday', $tz))->format('Y-m-d'), 30) === [], 'no slots on a day off');
    check(freeSlots($db, $doc, 2, $day, 30) === [], 'no slots at a branch they don’t work at');
    check(count(freeSlots($db, $doc, 1, $day, 60, 15)) === 6, 'a longer treatment must fit before the end (60 min: 10:00–11:00 by 15, 17:00)');
    $db->prepare("INSERT INTO schedule_blocks (staff_id, branch_id, starts_at, ends_at, reason) VALUES (?, 1, ?, ?, 'Meeting')")->execute([$doc, "$day 10:45:00", "$day 11:15:00"]);
    $slots = freeSlots($db, $doc, 1, $day, 30, 15);
    check(!array_intersect($slots, ["$day 10:30", "$day 10:45", "$day 11:00"]) && in_array("$day 10:15", $slots) && in_array("$day 11:15", $slots), 'their own time off is skipped, times either side stay');
    $db->prepare("INSERT INTO schedule_blocks (staff_id, room_id, branch_id, starts_at, ends_at, reason) VALUES (NULL, NULL, 1, ?, ?, 'Branch closed')")->execute(["$day 17:00:00", "$day 18:00:00"]);
    check(!array_filter(freeSlots($db, $doc, 1, $day, 30), fn($s) => $s >= "$day 17:00"), 'a whole-branch closure blocks everyone');
    $past = (new DateTimeImmutable('last monday', $tz))->format('Y-m-d');
    check(freeSlots($db, $doc, 1, $past, 30) === [], 'nothing in the past');
}

function test_an_existing_clinic_database_adopts_the_baseline() {
    $db = testDb('clinic_crm_test_adopt', true);
    $dir = __DIR__ . '/../migrations/tenant';
    check(!adoptExistingDatabase($db, $dir), 'an empty database is not adopted (it gets the baseline instead)');
    $db->exec("CREATE TABLE leads (id INT)");
    check(adoptExistingDatabase($db, $dir), 'a database with leads but no migrations is adopted');
    check(array_key_first(migrationStatus($db, $dir)['pending']) === '0001_staff_is_owner', 'only the later migrations are left to run');
    check(!adoptExistingDatabase($db, $dir), 'adopting happens once');
}
