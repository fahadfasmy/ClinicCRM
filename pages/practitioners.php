<?php
// pages/practitioners.php — Admin → Practitioners: each doctor's and nurse's profile (DHA licence, specialty, department,
// calendar colour, bookable), weekly working hours per branch (split shifts allowed) and time off (leave, breaks,
// meetings). The scheduler only offers times inside the working hours and outside time off (freeSlots()).
// People appear here when their role is Practitioner or Nurse (Staff page).
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventory.php';     // CSRF helpers
require_once __DIR__ . '/../includes/clinic_setup.php';
requirePermission('setup.clinic');

$db = getDB();
$flash = $_SESSION['setup_flash'] ?? null;
unset($_SESSION['setup_flash']);
$branches = branchList($db);
$people = array_column(practitionerList($db), null, 'id');
$sid = (int)($_GET['id'] ?? $_POST['staff_id'] ?? 0);
if ($sid && !isset($people[$sid])) $sid = 0;
$bid = (int)($_GET['branch'] ?? $_POST['branch_id'] ?? 0);
if (!isset($branches[$bid])) $bid = (int)($people[$sid]['branch_id'] ?? 0) ?: (int)array_key_first($branches);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sid) {
    requireCsrf();
    $name = $people[$sid]['name'];
    try {
        $act = $_POST['action'] ?? '';
        if ($act === 'profile') {
            $color = preg_match('/^#[0-9a-f]{6}$/i', $_POST['color'] ?? '') ? strtolower($_POST['color']) : '#b8925a';
            $dept = (int)($_POST['department_id'] ?? 0) ?: null;
            if ($dept) { $d = $db->prepare("SELECT COUNT(*) FROM departments WHERE id = ?"); $d->execute([$dept]); if (!$d->fetchColumn()) $dept = null; }
            $has = $db->prepare("SELECT COUNT(*) FROM practitioner_profiles WHERE staff_id = ?"); $has->execute([(int)($_POST['staff_id'] ?? 0)]);
            if (!$has->fetchColumn()) planCheck($db, 'practitioners');   // a new practitioner counts against the plan
            $db->prepare("INSERT INTO practitioner_profiles (staff_id, dha_license, specialty, department_id, color, is_bookable, sort_order) VALUES (?,?,?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE dha_license = VALUES(dha_license), specialty = VALUES(specialty), department_id = VALUES(department_id),
                          color = VALUES(color), is_bookable = VALUES(is_bookable), sort_order = VALUES(sort_order)")
               ->execute([$sid, mb_substr(trim($_POST['dha_license'] ?? ''), 0, 40) ?: null, mb_substr(trim($_POST['specialty'] ?? ''), 0, 100) ?: null,
                          $dept, $color, isset($_POST['is_bookable']) ? 1 : 0, (int)($_POST['sort_order'] ?? 0)]);
            logActivity(currentStaffId(), 'practitioner_profile_changed', null, (string)$sid, $name);
            $_SESSION['setup_flash'] = ['success', 'Profile saved.'];
        } elseif ($act === 'schedule') {
            $rows = [];
            foreach (array_keys(WEEKDAYS) as $d) foreach ([0, 1] as $k)
                $rows[] = [$d, (string)($_POST['from'][$d][$k] ?? ''), (string)($_POST['to'][$d][$k] ?? '')];
            savePractitionerSchedule($db, $sid, $bid, $rows);
            logActivity(currentStaffId(), 'practitioner_schedule_changed', null, (string)$sid, $name . ' at ' . $branches[$bid]['name']);
            $_SESSION['setup_flash'] = ['success', 'Working hours saved for ' . $branches[$bid]['name'] . '.'];
        } elseif ($act === 'block_add') {
            $tz = new DateTimeZone(CLINIC_TZ);
            $from = DateTimeImmutable::createFromFormat('Y-m-d H:i', ($_POST['from_date'] ?? '') . ' ' . ($_POST['from_time'] ?: '00:00'), $tz);
            $to = DateTimeImmutable::createFromFormat('Y-m-d H:i', ($_POST['to_date'] ?: ($_POST['from_date'] ?? '')) . ' ' . ($_POST['to_time'] ?: '23:59'), $tz);
            if (!$from || !$to || $to <= $from) throw new RuntimeException('Choose when the time off starts and ends (the end after the start).');
            $db->prepare("INSERT INTO schedule_blocks (staff_id, branch_id, starts_at, ends_at, reason, created_by) VALUES (?,?,?,?,?,?)")
               ->execute([$sid, $bid, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'), mb_substr(trim($_POST['reason'] ?? ''), 0, 150), currentStaffId()]);
            logActivity(currentStaffId(), 'schedule_block_added', null, (string)$sid, $name . ': ' . $from->format('d M H:i') . ' – ' . $to->format('d M H:i'));
            $_SESSION['setup_flash'] = ['success', 'Time off added.'];
        } elseif ($act === 'block_delete') {
            $db->prepare("DELETE FROM schedule_blocks WHERE id = ? AND staff_id = ?")->execute([(int)($_POST['block_id'] ?? 0), $sid]);
            logActivity(currentStaffId(), 'schedule_block_removed', null, (string)$sid, $name);
            $_SESSION['setup_flash'] = ['success', 'Time off removed.'];
        }
    } catch (Throwable $e) {
        $_SESSION['setup_flash'] = ['danger', $e->getMessage()];
    }
    header("Location: /pages/practitioners.php?id=$sid&branch=$bid"); exit;
}

$departments = $db->query("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
// Working days per person, for the list
$days = [];
foreach ($db->query("SELECT staff_id, COUNT(DISTINCT CONCAT(branch_id, '-', weekday)) n FROM practitioner_schedules GROUP BY staff_id") as $r) $days[(int)$r['staff_id']] = (int)$r['n'];

if ($sid) {
    $p = $people[$sid];
    $sched = practitionerSchedule($db, $sid)[$bid] ?? [];
    $blocks = $db->prepare("SELECT b.*, br.name AS branch_name FROM schedule_blocks b JOIN branches br ON br.id = b.branch_id
                            WHERE b.staff_id = ? AND b.ends_at >= NOW() ORDER BY b.starts_at LIMIT 50");
    $blocks->execute([$sid]);
    $blocks = $blocks->fetchAll();
    $pageTitle = $p['name'];
    $pageSubtitle = (ROLES[$p['role']][0] ?? '') . ($p['specialty'] ? ' · ' . $p['specialty'] : '');
    $pageBack = ['href' => '/pages/practitioners.php', 'label' => 'Practitioners'];
} else {
    $pageTitle = 'Practitioners';
    $pageSubtitle = count($people) . ' doctor' . (count($people) == 1 ? '' : 's') . ' and nurses';
    $pageBack = ['href' => '/pages/settings_clinic.php', 'label' => 'Clinic setup'];
}
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash[0] ?>"><i class="bi bi-<?= $flash[0] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div>
<?php endif; ?>

<?php if (!$sid): ?>
<div class="card-crm page-section">
  <?php if (!$people): ?>
  <div class="empty-state"><i class="bi bi-heart-pulse"></i><div class="empty-state-title">No practitioners yet</div>
    <p>Give staff the Practitioner or Nurse role on the <a href="/pages/staff.php">Staff page</a> and they appear here.</p></div>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-hover mb-0">
    <thead><tr><th>Name</th><th>Role</th><th>Specialty</th><th>Department</th><th>DHA licence</th><th class="text-end">Working days</th><th>Bookable</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($people as $p): ?>
      <tr>
        <td><span class="d-inline-block rounded-circle me-2 align-middle" style="width:10px;height:10px;background:<?= sanitize($p['color']) ?>"></span>
          <a href="?id=<?= (int)$p['id'] ?>" class="fw-semibold"><?= sanitize($p['name']) ?></a></td>
        <td><?= sanitize(ROLES[$p['role']][0] ?? $p['role']) ?></td>
        <td><?= sanitize($p['specialty'] ?? '') ?></td>
        <td><?= sanitize($departments[$p['department_id']] ?? '') ?></td>
        <td><?= sanitize($p['dha_license'] ?? '') ?: '<span class="text-amber fs-12">Missing</span>' ?></td>
        <td class="text-end"><?= $days[(int)$p['id']] ?? '<span class="text-amber fs-12">No hours</span>' ?></td>
        <td><?= ($p['is_bookable'] ?? 1) ? 'Yes' : 'No' ?></td>
        <td class="text-end"><a href="?id=<?= (int)$p['id'] ?>" class="btn btn-ghost btn-sm"><i class="bi bi-pencil"></i>Edit</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="row g-3 page-section">
  <div class="col-xl-5">
    <form method="POST" class="card-crm mb-3">
      <?= csrfField() ?><input type="hidden" name="action" value="profile"><input type="hidden" name="staff_id" value="<?= $sid ?>"><input type="hidden" name="branch_id" value="<?= $bid ?>">
      <div class="card-header-crm"><span><i class="bi bi-person-vcard"></i> Profile</span></div>
      <div class="card-body-crm">
        <div class="row g-2 mb-3">
          <div class="col-6"><label class="form-label" for="pDha">DHA licence no.</label><input class="form-control" id="pDha" name="dha_license" value="<?= sanitize($p['dha_license'] ?? '') ?>"></div>
          <div class="col-6"><label class="form-label" for="pSpec">Specialty</label><input class="form-control" id="pSpec" name="specialty" value="<?= sanitize($p['specialty'] ?? '') ?>" placeholder="e.g. Dermatologist"></div>
        </div>
        <label class="form-label" for="pDept">Department</label>
        <select class="form-select mb-3" id="pDept" name="department_id"><option value="">—</option>
          <?php foreach ($departments as $did => $dn): ?><option value="<?= $did ?>" <?= (int)$p['department_id'] === $did ? 'selected' : '' ?>><?= sanitize($dn) ?></option><?php endforeach; ?></select>
        <div class="row g-2 mb-3 align-items-end">
          <div class="col-4"><label class="form-label" for="pColor">Calendar colour</label><input class="form-control form-control-color" type="color" id="pColor" name="color" value="<?= sanitize($p['color']) ?>"></div>
          <div class="col-4"><label class="form-label" for="pSort">List order</label><input class="form-control" type="number" id="pSort" name="sort_order" value="<?= (int)$p['sort_order'] ?>"></div>
          <div class="col-4"><label class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" name="is_bookable" <?= ($p['is_bookable'] ?? 1) ? 'checked' : '' ?>>
            <span class="form-check-label">Bookable</span></label></div>
        </div>
      </div>
      <div class="form-actions"><button class="btn btn-gold ms-auto"><i class="bi bi-check-lg"></i>Save profile</button></div>
    </form>

    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-calendar-x"></i> Time off</span><span class="card-header-sub">Leave, breaks, meetings</span></div>
      <div class="list-group list-group-flush">
        <?php foreach ($blocks as $b): ?>
        <form method="POST" class="list-group-item d-flex align-items-center gap-2">
          <?= csrfField() ?><input type="hidden" name="action" value="block_delete"><input type="hidden" name="staff_id" value="<?= $sid ?>"><input type="hidden" name="branch_id" value="<?= $bid ?>"><input type="hidden" name="block_id" value="<?= (int)$b['id'] ?>">
          <span class="fs-13"><strong><?= date('D d M, H:i', strtotime($b['starts_at'])) ?></strong> – <?= date(substr($b['starts_at'], 0, 10) === substr($b['ends_at'], 0, 10) ? 'H:i' : 'D d M, H:i', strtotime($b['ends_at'])) ?>
            <span class="text-tx-3">· <?= sanitize($b['branch_name']) ?><?= $b['reason'] !== '' ? ' · ' . sanitize($b['reason']) : '' ?></span></span>
          <button class="btn btn-ghost btn-sm ms-auto" data-confirm="Remove this time off?" aria-label="Remove time off"><i class="bi bi-trash"></i></button>
        </form>
        <?php endforeach; ?>
        <?php if (!$blocks): ?><div class="list-group-item text-tx-3 fs-13">No time off coming up.</div><?php endif; ?>
      </div>
      <form method="POST" class="card-body-crm border-top">
        <?= csrfField() ?><input type="hidden" name="action" value="block_add"><input type="hidden" name="staff_id" value="<?= $sid ?>"><input type="hidden" name="branch_id" value="<?= $bid ?>">
        <div class="row g-2 mb-2">
          <div class="col-7"><label class="form-label" for="bFromD">From</label><input class="form-control" type="date" id="bFromD" name="from_date" required></div>
          <div class="col-5"><label class="form-label" for="bFromT">time</label><input class="form-control" type="time" id="bFromT" name="from_time"></div>
          <div class="col-7"><label class="form-label" for="bToD">Until</label><input class="form-control" type="date" id="bToD" name="to_date"></div>
          <div class="col-5"><label class="form-label" for="bToT">time</label><input class="form-control" type="time" id="bToT" name="to_time"></div>
        </div>
        <input class="form-control mb-2" name="reason" placeholder="Reason (e.g. Annual leave)" aria-label="Reason">
        <div class="d-flex"><span class="fs-12 text-tx-3">Empty times = the whole day. At <?= sanitize($branches[$bid]['name'] ?? '') ?>.</span>
          <button class="btn btn-gold btn-sm ms-auto"><i class="bi bi-plus-lg"></i>Add time off</button></div>
      </form>
    </div>
  </div>

  <div class="col-xl-7">
    <form method="POST" class="card-crm">
      <?= csrfField() ?><input type="hidden" name="action" value="schedule"><input type="hidden" name="staff_id" value="<?= $sid ?>"><input type="hidden" name="branch_id" value="<?= $bid ?>">
      <div class="card-header-crm"><span><i class="bi bi-clock"></i> Working hours</span>
        <?php if (count($branches) > 1): ?><span class="ms-auto d-flex gap-1"><?php foreach ($branches as $b): ?>
          <a href="?id=<?= $sid ?>&amp;branch=<?= (int)$b['id'] ?>" class="btn btn-sm <?= (int)$b['id'] === $bid ? 'btn-gold' : 'btn-ghost' ?>"><?= sanitize($b['name']) ?></a><?php endforeach; ?></span>
        <?php else: ?><span class="card-header-sub"><?= sanitize($branches[$bid]['name'] ?? '') ?></span><?php endif; ?></div>
      <div class="card-body-crm">
        <p class="fs-13 text-tx-2">Leave a day empty when they don’t work. Use the second pair for a split shift (e.g. 10:00–14:00 and 17:00–21:00).</p>
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th>Day</th><th>From</th><th>To</th><th>From</th><th>To</th></tr></thead>
          <tbody>
          <?php foreach (WEEKDAYS as $d => $day): $sh = $sched[$d] ?? []; ?>
            <tr><td><?= $day ?></td>
              <?php foreach ([0, 1] as $k): ?>
              <td><input class="form-control form-control-sm" type="time" name="from[<?= $d ?>][<?= $k ?>]" value="<?= sanitize($sh[$k][0] ?? '') ?>" aria-label="<?= $day ?> start <?= $k + 1 ?>"></td>
              <td><input class="form-control form-control-sm" type="time" name="to[<?= $d ?>][<?= $k ?>]" value="<?= sanitize($sh[$k][1] ?? '') ?>" aria-label="<?= $day ?> end <?= $k + 1 ?>"></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="form-actions"><button class="btn btn-gold ms-auto"><i class="bi bi-check-lg"></i>Save working hours</button></div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
