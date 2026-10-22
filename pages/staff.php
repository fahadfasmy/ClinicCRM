<?php
// pages/staff.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventory.php';
require_once __DIR__ . '/../includes/staff_history.php';
require_once __DIR__ . '/../includes/security.php';
requirePermission('staff.manage');

$db = getDB();
ensureInventorySchema($db);   // adds the Inventory and Practitioner roles
ensurePermissionSchema($db);
ensureSecuritySchema($db);
$success = $error = '';
if (!empty($_SESSION['staff_flash'])) { $error = (string)$_SESSION['staff_flash']; unset($_SESSION['staff_flash']); }

/** Active admins other than this person; the clinic must always keep at least one. */
function otherActiveAdmins(PDO $db, int $staffId): int {
    $s = $db->prepare("SELECT COUNT(*) FROM staff WHERE role = 'admin' AND is_active = 1 AND id <> ?");
    $s->execute([$staffId]);
    return (int)$s->fetchColumn();
}

// Every change on this page is a POST carrying the form token, so a link or another site can't make it
if ($_SERVER['REQUEST_METHOD'] === 'POST') requireCsrf();

// Activate / deactivate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['security_policy', 'signout_all', 'reset_2fa'], true)) {
    $act = $_POST['action'];
    if ($act === 'security_policy') {
        mailSaveSetting($db, 'require_2fa', isset($_POST['require_2fa']) ? '1' : '0');
        $roles = array_values(array_intersect((array)($_POST['roles_2fa'] ?? []), array_keys(ROLES)));
        mailSaveSetting($db, 'require_2fa_roles', count($roles) === count(ROLES) ? '' : json_encode($roles));   // all ticked = everyone
        $idle = in_array((int)($_POST['idle_minutes'] ?? 0), [0, 15, 30, 60, 120, 240], true) ? (int)$_POST['idle_minutes'] : 0;
        mailSaveSetting($db, 'idle_minutes', (string)$idle);
        logActivity(currentStaffId(), 'security_policy_changed', null, null, '2-step sign-in ' . (isset($_POST['require_2fa']) ? 'required' . ($roles && count($roles) < count(ROLES) ? ' for ' . implode(', ', $roles) : '') : 'off') . '; idle sign-out ' . ($idle ? "$idle min" : 'off'));
        auditLog($db, 'setting', 0, 'security_policy', ['require_2fa' => isset($_POST['require_2fa']), 'roles' => $roles, 'idle_minutes' => $idle]);
        header('Location: /pages/staff.php?sec=policy#security'); exit;
    }
    $tid = (int)($_POST['staff_id'] ?? 0);
    $s = $db->prepare("SELECT name FROM staff WHERE id = ?");
    $s->execute([$tid]);
    if ($tname = $s->fetchColumn()) {
        if ($act === 'signout_all') secSignOutEverywhere($db, $tid, $tid === currentStaffId());
        else secResetTwoStep($db, $tid);
        if ($act === 'reset_2fa' && $tid === currentStaffId()) secSignOutEverywhere($db, $tid, true);
        logActivity(currentStaffId(), $act === 'signout_all' ? 'staff_signed_out' : 'staff_2fa_reset', null, (string)$tid, $tname);
    }
    header("Location: /pages/staff.php?edit=$tid&sec=$act"); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    $tid = (int)($_POST['staff_id'] ?? 0);
    $s = $db->prepare("SELECT name, role, is_active FROM staff WHERE id = ?");
    $s->execute([$tid]);
    $t = $s->fetch();
    if ($t && !$t['is_active']) { try { planCheck($db, 'staff_seats'); } catch (PlanLimitException $e) { $_SESSION['staff_flash'] = $e->getMessage(); header('Location: /pages/staff.php'); exit; } }
    if ($t && $tid !== currentStaffId() && !($t['is_active'] && $t['role'] === 'admin' && !otherActiveAdmins($db, $tid))) {
        $db->prepare("UPDATE staff SET is_active = 1 - is_active WHERE id = ?")->execute([$tid]);
        logActivity(currentStaffId(), $t['is_active'] ? 'staff_deactivated' : 'staff_activated', null, (string)$tid, $t['name']);
    }
    header('Location: /pages/staff.php');
    exit;
}

$branches = $db->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);

// Handle add/edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $staffId  = (int)($_POST['staff_id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $role     = isset(ROLES[$_POST['role'] ?? '']) ? $_POST['role'] : 'consultant';
    $branchId = (int)($_POST['branch_id'] ?? 0) ?: null;   // home branch (checked against the branch list below)
    $language = ($_POST['language'] ?? '') === 'ar' ? 'ar' : 'en';
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($branchId && !isset($branches[$branchId])) $branchId = null;
    if (!$name || !$email) {
        $error = 'Name and email are required.';
    } elseif ($password !== '' && ($pwProblems = secPasswordProblems($password, ['name' => $name, 'email' => $email]))) {
        $error = implode(' ', $pwProblems);
    } elseif ($action === 'add') {
        if (!$password) { $error = 'Password is required for new staff.'; }
        else {
            // Check duplicate email
            $exists = $db->prepare("SELECT id FROM staff WHERE email=?");
            $exists->execute([$email]);
            if ($exists->fetch()) {
                $error = 'Email already exists.';
            } elseif (planLimit('staff_seats') !== null && planUsage($db, 'staff_seats') >= planLimit('staff_seats')) {
                $error = 'Your ' . tenantPlan()['name'] . ' plan includes ' . planLimit('staff_seats') . ' staff seats. Upgrade on the Subscription page, or deactivate someone who has left.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $db->prepare("INSERT INTO staff (name,email,password,role,phone,branch_id,language) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$name,$email,$hash,$role,$phone,$branchId,$language]);
                logActivity(currentStaffId(), 'staff_added', null, (string)$db->lastInsertId(), $name . ' as ' . ucfirst($role));
                $success = "Staff member '{$name}' added.";
            }
        }
    } elseif ($action === 'edit' && $staffId) {
        $s = $db->prepare("SELECT role, is_active FROM staff WHERE id=?");
        $s->execute([$staffId]);
        $before = $s->fetch();
        $dup = $db->prepare("SELECT id FROM staff WHERE email=? AND id<>?");
        $dup->execute([$email, $staffId]);
        if ($staffId === currentStaffId()) { $role = $before['role'] ?? $role; $isActive = 1; }   // can't demote or switch off yourself
        if (!$before) {
            $error = 'Staff member not found.';
        } elseif ($dup->fetch()) {
            $error = 'Another staff member already uses that email.';
        } elseif ($before['role'] === 'admin' && $before['is_active'] && ($role !== 'admin' || !$isActive) && !otherActiveAdmins($db, $staffId)) {
            $error = 'This is the only active admin. Make someone else an admin first.';
        } else {
        $db->prepare("UPDATE staff SET name=?,email=?,role=?,phone=?,is_active=?,branch_id=?,language=? WHERE id=?")
           ->execute([$name,$email,$role,$phone,$isActive,$branchId,$language,$staffId]);
        // Access changes that now match the new role's defaults are no longer changes
        foreach (staffPermissionOverrides($staffId, true) as $perm => $allowed) {
            if ($role === 'admin' || $allowed === roleCan($role, $perm)) {
                $db->prepare("DELETE FROM staff_permissions WHERE staff_id=? AND permission=?")->execute([$staffId, $perm]);
            }
        }
        if ($password) {
            // New password from an admin: that person is signed out everywhere (an admin changing their own stays signed in here)
            secSetPassword($db, $staffId, $password);
            if ($staffId === currentStaffId()) secSignOutEverywhere($db, $staffId, true);
            logActivity(currentStaffId(), 'staff_password_set', null, (string)$staffId, $name);
        }
        if ($before['role'] !== $role) logActivity(currentStaffId(), 'staff_role_changed', null, (string)$staffId, $name . ': ' . ucfirst($before['role']) . ' → ' . ucfirst($role));
        $success = "Staff member updated.";
        }
    }
}

// Fetch all staff with lead stats
$staffList = $db->query("
    SELECT s.*,
           COUNT(DISTINCT l.id) as total_leads,
           SUM(l.stage='converted') as converted
    FROM staff s
    LEFT JOIN leads l ON l.assigned_to = s.id
    GROUP BY s.id ORDER BY s.is_active DESC, s.name
")->fetchAll();
$customAccess = $db->query("SELECT staff_id, COUNT(*) FROM staff_permissions GROUP BY staff_id")->fetchAll(PDO::FETCH_KEY_PAIR);

// Edit mode
$editStaff = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM staff WHERE id=?");
    $stmt->execute([(int)$_GET['edit']]);
    $editStaff = $stmt->fetch();
}

$roleInfo = ROLES;
$activeCount = count(array_filter($staffList, fn($s) => $s['is_active']));
$showAll = ($_GET['history'] ?? '') === 'all';
$history = staffHistory($db, $showAll ? 200 : 8);

$pageTitle    = 'Staff';
$pageSubtitle = $activeCount . ' active team member' . ($activeCount == 1 ? '' : 's');
$pageActions  = $editStaff ? '<a href="/pages/staff.php" class="btn btn-gold"><i class="bi bi-person-plus"></i>Add staff</a>' : '';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($success): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i><?= sanitize($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><?= sanitize($error) ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-people"></i> Team members</span></div>
      <div class="table-responsive">
      <table class="table table-hover">
        <thead>
          <tr><th>Name</th><th>Role</th><th class="text-end">Leads</th><th class="text-end">Converted</th><th>Status</th><th class="text-end"></th></tr>
        </thead>
        <tbody>
        <?php foreach ($staffList as $s): [$roleLabel, $roleTone] = $roleInfo[$s['role']] ?? [ucfirst($s['role']), 'tone-slate']; ?>
        <tr class="<?= $s['is_active'] ? '' : 'opacity-50' ?> <?= $editStaff && $editStaff['id'] == $s['id'] ? 'table-active' : '' ?>">
          <td>
            <div class="d-flex align-items-center gap-2">
              <span class="avatar avatar-sm"><?= sanitize(initials($s['name'])) ?></span>
              <div class="min-w-0">
                <span class="cell-title"><?= sanitize($s['name']) ?></span>
                <?php if ((int)$s['id'] === currentStaffId()): ?><span class="chip ms-1">You</span><?php endif; ?>
                <span class="cell-sub"><?= sanitize($s['email']) ?><?= $s['phone'] ? ' · ' . sanitize($s['phone']) : '' ?></span>
              </div>
            </div>
          </td>
          <td><span class="chip <?= $roleTone ?>"><?= $roleLabel ?></span><?php if ($s['role'] !== 'admin' && !empty($customAccess[$s['id']])): ?>
            <a href="/pages/staff_access.php?id=<?= $s['id'] ?>" class="chip ms-1 text-decoration-none" title="Access differs from the role defaults">Custom access</a><?php endif; ?></td>
          <td class="text-end tabular"><?= (int)$s['total_leads'] ?></td>
          <td class="text-end tabular text-green fw-600"><?= (int)$s['converted'] ?></td>
          <td><span class="chip <?= $s['is_active'] ? 'aging-fresh' : '' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span></td>
          <td class="text-end">
            <div class="row-actions">
              <a href="/pages/staff_access.php?id=<?= $s['id'] ?>" class="btn-icon" title="Access" aria-label="Access for <?= sanitize($s['name']) ?>"><i class="bi bi-shield-lock"></i></a>
              <a href="?edit=<?= $s['id'] ?>" class="btn-icon" title="Edit" aria-label="Edit <?= sanitize($s['name']) ?>"><i class="bi bi-pencil"></i></a>
              <?php if ((int)$s['id'] !== currentStaffId()): ?>
              <form method="POST" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="staff_id" value="<?= $s['id'] ?>">
                <button type="submit" class="btn-icon border-0 <?= $s['is_active'] ? 'danger' : 'success' ?>" title="<?= $s['is_active'] ? 'Deactivate' : 'Activate' ?>" aria-label="<?= ($s['is_active'] ? 'Deactivate ' : 'Activate ') . sanitize($s['name']) ?>"
                   data-confirm="<?= $s['is_active'] ? 'Deactivate ' . sanitize($s['name']) . '? They will no longer be able to sign in.' : 'Activate ' . sanitize($s['name']) . '?' ?>">
                  <i class="bi bi-<?= $s['is_active'] ? 'person-dash' : 'person-check' ?>"></i>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>

    <div class="card-crm mt-3">
      <div class="card-header-crm"><span><i class="bi bi-shield-check"></i> What each role can do</span></div>
      <div class="card-body-crm">
        <?php foreach ($roleInfo as [$lbl, $tone, $desc]): ?>
        <div class="kv"><span class="kv-label"><span class="chip <?= $tone ?>"><?= $lbl ?></span></span><span class="kv-value fw-normal text-tx-2"><?= $desc ?></span></div>
        <?php endforeach; ?>
        <p class="fs-12 text-tx-3 mt-3 mb-0"><i class="bi bi-shield-lock"></i> These are the starting points. To give one person more or less, use the shield button next to their name.</p>
      </div>
    </div>

    <div class="card-crm mt-3" id="history">
      <div class="card-header-crm"><span><i class="bi bi-clock-history"></i> Recent changes</span>
        <span class="card-header-sub"><?= $showAll ? 'Last ' . count($history) . ' changes' : '<a href="?history=all#history">Show all</a>' ?></span></div>
      <div class="list-rows">
        <?php foreach ($history as $h) echo staffHistoryRow($h); ?>
        <?php if (!$history): ?><div class="list-row"><span class="text-tx-3 fs-13">No changes recorded yet. Adding staff, switching them on or off, and changing roles or access will show here.</span></div><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="card-crm form-side">
      <div class="card-header-crm"><span><i class="bi bi-<?= $editStaff ? 'pencil' : 'person-plus' ?>"></i> <?= $editStaff ? 'Edit ' . sanitize($editStaff['name']) : 'Add staff member' ?></span></div>
      <form method="POST">
        <?= csrfField() ?>
        <div class="card-body-crm">
          <input type="hidden" name="action" value="<?= $editStaff ? 'edit' : 'add' ?>">
          <?php if ($editStaff): ?><input type="hidden" name="staff_id" value="<?= $editStaff['id'] ?>"><?php endif; ?>
          <div class="mb-3">
            <label class="form-label" for="sName">Full name <span class="req">*</span></label>
            <input type="text" name="name" id="sName" class="form-control" required value="<?= sanitize($editStaff['name'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label" for="sEmail">Email <span class="req">*</span></label>
            <input type="email" name="email" id="sEmail" class="form-control" required value="<?= sanitize($editStaff['email'] ?? '') ?>">
          </div>
          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label" for="sPhone">Phone</label>
              <input type="tel" name="phone" id="sPhone" class="form-control" value="<?= sanitize($editStaff['phone'] ?? '') ?>">
            </div>
            <div class="col-6">
              <label class="form-label" for="sRole">Role</label>
              <select name="role" id="sRole" class="form-select">
                <?php foreach ($roleInfo as $r => [$lbl]): ?>
                <option value="<?= $r ?>" <?= ($editStaff['role'] ?? 'consultant') === $r ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-7">
              <label class="form-label" for="sBranch">Home branch</label>
              <select name="branch_id" id="sBranch" class="form-select">
                <option value="">—</option>
                <?php foreach ($branches as $bid => $bname): ?>
                <option value="<?= $bid ?>" <?= (int)($editStaff['branch_id'] ?? 0) === $bid ? 'selected' : '' ?>><?= sanitize($bname) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-5">
              <label class="form-label" for="sLang">Language</label>
              <select name="language" id="sLang" class="form-select">
                <option value="en">English</option>
                <option value="ar" <?= ($editStaff['language'] ?? 'en') === 'ar' ? 'selected' : '' ?>>العربية</option>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="sPass">Password <?= $editStaff ? '' : '<span class="req">*</span>' ?></label>
            <input type="password" name="password" id="sPass" class="form-control" <?= $editStaff ? '' : 'required' ?> minlength="10" autocomplete="new-password"
                   placeholder="<?= $editStaff ? 'Leave blank to keep current' : 'At least 10 characters' ?>">
          </div>
          <?php if ($editStaff): $isSelf = (int)$editStaff['id'] === currentStaffId(); ?>
          <label class="form-check mb-0">
            <input type="checkbox" class="form-check-input" name="is_active" <?= $editStaff['is_active'] || $isSelf ? 'checked' : '' ?> <?= $isSelf ? 'disabled' : '' ?>>
            <span class="form-check-label">Active (can sign in)</span>
          </label>
          <?php if ($isSelf): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
          <a href="/pages/staff_access.php?id=<?= $editStaff['id'] ?>" class="btn btn-ghost btn-sm mt-3"><i class="bi bi-shield-lock"></i>Feature access</a>
          <?php endif; ?>
        </div>
        <div class="form-actions">
          <?php if ($editStaff): ?><a href="/pages/staff.php" class="btn btn-ghost">Cancel</a><?php endif; ?>
          <button type="submit" class="btn btn-gold"><i class="bi bi-check-lg"></i><?= $editStaff ? 'Save changes' : 'Add staff member' ?></button>
        </div>
      </form>
    </div>

    <?php if ($editStaff):
      $eid = (int)$editStaff['id'];
      $last = $db->prepare("SELECT * FROM login_log WHERE staff_id = ? AND result = 'success' ORDER BY id DESC LIMIT 1"); $last->execute([$eid]); $last = $last->fetch();
      $fails = $db->prepare("SELECT COUNT(*) FROM login_log WHERE staff_id = ? AND result IN ('bad_password','bad_code') AND created_at > NOW() - INTERVAL 7 DAY"); $fails->execute([$eid]); $fails = (int)$fails->fetchColumn();
      $devs = $db->prepare("SELECT COUNT(*) FROM trusted_devices WHERE staff_id = ? AND expires_at > NOW()"); $devs->execute([$eid]); $devs = (int)$devs->fetchColumn(); ?>
    <div class="card-crm mt-3">
      <div class="card-header-crm"><span><i class="bi bi-shield-lock"></i> Sign-in security</span></div>
      <div class="card-body-crm fs-13">
        <?php if (($_GET['sec'] ?? '') === 'signout_all'): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i>Signed out everywhere.</div><?php endif; ?>
        <?php if (($_GET['sec'] ?? '') === 'reset_2fa'): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i>2-step reset: trusted devices and backup codes removed, signed out everywhere.</div><?php endif; ?>
        <div class="kv"><span class="kv-label">Last sign-in</span><span class="kv-value"><?= $last ? formatDate($last['created_at']) . ' · ' . sanitize(secDeviceLabel($last['user_agent'])) : 'Not since this was added' ?></span></div>
        <div class="kv"><span class="kv-label">Failed tries (7 days)</span><span class="kv-value <?= $fails >= 5 ? 'text-red' : '' ?>"><?= $fails ?></span></div>
        <div class="kv"><span class="kv-label">Trusted devices</span><span class="kv-value"><?= $devs ?></span></div>
        <div class="kv"><span class="kv-label">Backup codes left</span><span class="kv-value"><?= secBackupCodesLeft($db, $eid) ?></span></div>
        <div class="kv"><span class="kv-label">Password changed</span><span class="kv-value"><?= $editStaff['password_changed_at'] ? timeSince($editStaff['password_changed_at']) : 'Not recorded' ?></span></div>
      </div>
      <form method="POST" class="form-actions">
        <?= csrfField() ?><input type="hidden" name="staff_id" value="<?= $eid ?>">
        <button class="btn btn-ghost btn-sm" name="action" value="signout_all" data-confirm="Sign <?= sanitize($editStaff['name']) ?> out on every computer and phone?"><i class="bi bi-box-arrow-right"></i>Sign out everywhere</button>
        <button class="btn btn-ghost btn-sm" name="action" value="reset_2fa" data-confirm="Reset 2-step for <?= sanitize($editStaff['name']) ?>? Their trusted devices and backup codes are removed and they are signed out; next sign-in needs an email code."><i class="bi bi-arrow-counterclockwise"></i>Reset 2-step</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="card-crm mt-3" id="security">
      <div class="card-header-crm"><span><i class="bi bi-shield-check"></i> Sign-in security</span></div>
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="action" value="security_policy">
        <div class="card-body-crm fs-13">
          <?php if (($_GET['sec'] ?? '') === 'policy'): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i>Saved.</div><?php endif; ?>
          <label class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" name="require_2fa" <?= mailSetting($db, 'require_2fa') !== '0' ? 'checked' : '' ?>>
            <span class="form-check-label">Ask for a code sent by email after the password</span></label>
          <?php if (!mailConfigured($db)): ?>
            <p class="text-red mb-0"><i class="bi bi-exclamation-circle"></i> Waiting for email: codes can’t be sent until the clinic mailbox is set up in <a href="/pages/email_settings.php">Admin → Email</a>. Until then everyone signs in with just the password.</p>
          <?php else: ?>
            <p class="text-tx-3 mb-0">Trusted devices skip the code for <?= SEC_TRUST_DAYS ?> days. Turn this off only in an emergency (for example, if the clinic mailbox stops working).</p>
          <?php endif; ?>
          <?php $r2 = secTwoStepRoles($db); ?>
          <fieldset class="mt-3"><legend class="fs-13 fw-600 mb-1">Who needs the code</legend>
            <div class="d-flex flex-wrap gap-3"><?php foreach (ROLES as $rk => $rv): ?>
              <label class="form-check"><input class="form-check-input" type="checkbox" name="roles_2fa[]" value="<?= $rk ?>" <?= !$r2 || in_array($rk, $r2, true) ? 'checked' : '' ?>><span class="form-check-label"><?= sanitize($rv[0]) ?></span></label>
            <?php endforeach; ?></div>
            <p class="text-tx-3 mb-0">Everyone ticked by default. Anyone who opens patient records or money should keep it.</p></fieldset>
          <label class="form-label fw-600 mt-3" for="idleMin">Sign out after no activity for</label>
          <select class="form-select" id="idleMin" name="idle_minutes" style="max-width:220px"><?php $im = secIdleMinutes($db); foreach ([0 => 'Never', 15 => '15 minutes', 30 => '30 minutes', 60 => '1 hour', 120 => '2 hours', 240 => '4 hours'] as $k => $l): ?><option value="<?= $k ?>" <?= $im === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
          <p class="text-tx-3 mb-0 mt-1">Useful on shared front-desk computers. A screen left open signs itself out.</p>
        </div>
        <div class="form-actions"><button class="btn btn-ghost btn-sm ms-auto">Save</button></div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
