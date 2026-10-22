<?php
// pages/staff_access.php — switch CRM features on or off for one staff member.
// Starts from their role's defaults; only the differences are saved (staff_permissions).
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventory.php';
require_once __DIR__ . '/../includes/staff_history.php';
requirePermission('staff.manage');

$db = getDB();
ensurePermissionSchema($db);

$s = $db->prepare("SELECT * FROM staff WHERE id = ?");
$s->execute([(int)($_GET['id'] ?? 0)]);
$member = $s->fetch();
if (!$member) { header('Location: /pages/staff.php'); exit; }
$sid  = (int)$member['id'];
$role = $member['role'];
$roleLabel  = ROLES[$role][0] ?? ucfirst($role);
$grantable  = array_filter(PERMISSIONS, fn($p) => $p[0] !== null);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role !== 'admin') {
    requireCsrf();
    if (($_POST['action'] ?? '') === 'reset') {
        $db->prepare("DELETE FROM staff_permissions WHERE staff_id = ?")->execute([$sid]);
        logActivity(currentStaffId(), 'staff_access_reset', null, (string)$sid, $member['name'] . ' to the ' . $roleLabel . ' defaults');
        header("Location: /pages/staff_access.php?id=$sid&saved=reset"); exit;
    }
    $want = [];
    foreach ($grantable as $key => $_) $want[$key] = isset($_POST['perm'][$key]);
    // A feature can't be on without the one it depends on
    foreach (PERMISSION_REQUIRES as $key => $needs) {
        if ($want[$key] ?? false) { for ($n = $needs; $n; $n = PERMISSION_REQUIRES[$n] ?? null) $want[$n] = true; }
    }
    $old = staffPermissionOverrides($sid, true);
    $db->beginTransaction();
    $db->prepare("DELETE FROM staff_permissions WHERE staff_id = ?")->execute([$sid]);
    $ins = $db->prepare("INSERT INTO staff_permissions (staff_id, permission, allowed, updated_by) VALUES (?,?,?,?)");
    $changes = [];
    foreach ($want as $key => $on) {
        if ($on !== roleCan($role, $key)) $ins->execute([$sid, $key, $on ? 1 : 0, currentStaffId()]);
        $was = $old[$key] ?? roleCan($role, $key);
        if ($was !== $on) $changes[] = ($on ? '+' : '−') . PERMISSIONS[$key][1];
    }
    $db->commit();
    if ($changes) {
        // The log holds 255 characters: list what fits, then "and N more"
        $shown = $changes;
        while (count($shown) > 1 && mb_strlen($member['name'] . ': ' . implode(', ', $shown) . ' and 99 more') > 255) array_pop($shown);
        $more = count($changes) - count($shown);
        logActivity(currentStaffId(), 'staff_access_changed', null, (string)$sid, $member['name'] . ': ' . implode(', ', $shown) . ($more ? " and $more more" : ''));
    }
    header("Location: /pages/staff_access.php?id=$sid&saved=" . count($changes)); exit;
}

$overrides = staffPermissionOverrides($sid, true);
$groups = [];
foreach ($grantable as $key => [$group, $label, $help]) $groups[$group][$key] = [$label, $help];
$groupIcons = ['Leads' => 'bi-people', 'Patients' => 'bi-person-vcard', 'Appointments' => 'bi-calendar-check', 'Clinical records' => 'bi-clipboard2-pulse',
               'Billing' => 'bi-receipt', 'Insurance' => 'bi-shield-plus', 'Inventory' => 'bi-box-seam', 'Reports' => 'bi-bar-chart-line', 'Settings' => 'bi-gear'];

$pageTitle    = 'Access for ' . $member['name'];
$pageBack     = ['href' => '/pages/staff.php', 'label' => 'Staff'];
$pageSubtitle = $roleLabel . ($role === 'admin' ? ' · has every feature' : ' · ' . (count($overrides) ? count($overrides) . ' change' . (count($overrides) == 1 ? '' : 's') . ' from the role defaults' : 'using the role defaults'));
require_once __DIR__ . '/../includes/header.php';
?>
<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i>
  <?= $_GET['saved'] === 'reset' ? 'Back to the ' . sanitize($roleLabel) . ' defaults.' : ((int)$_GET['saved'] ? 'Access saved. It applies from their next click; no need to sign out.' : 'Nothing changed.') ?></div>
<?php endif; ?>

<?php if ($role === 'admin'): ?>
<div class="alert alert-info" style="max-width:820px"><i class="bi bi-shield-check"></i><div><strong><?= sanitize($member['name']) ?> is an admin, so they have every feature.</strong>
  To limit what they can do, change their role on the <a href="/pages/staff.php?edit=<?= $sid ?>">Staff page</a> first, then come back here.</div></div>
<?php else: ?>
<form method="POST" style="max-width:820px" id="accessForm">
  <?= csrfField() ?>
  <p class="fs-13 text-tx-2 mb-3">Ticked features are what <?= sanitize($member['name']) ?> can use. They start from the <strong><?= sanitize($roleLabel) ?></strong> role;
    anything you change is marked <span class="chip aging-fresh">Added</span> or <span class="chip aging-critical">Removed</span>. Managing staff stays with admins only.</p>
  <?php foreach ($groups as $group => $items): ?>
  <div class="card-crm mb-3">
    <div class="card-header-crm"><span><i class="bi <?= $groupIcons[$group] ?? 'bi-toggles' ?>"></i> <?= sanitize($group) ?></span></div>
    <div class="list-rows">
      <?php foreach ($items as $key => [$label, $help]): $def = roleCan($role, $key); $on = $overrides[$key] ?? $def; ?>
      <label class="list-row" style="cursor:pointer">
        <div class="form-check form-switch mb-0 me-1">
          <input class="form-check-input perm" type="checkbox" role="switch" name="perm[<?= $key ?>]" value="1" data-key="<?= $key ?>" data-default="<?= $def ? 1 : 0 ?>"
                 data-needs="<?= PERMISSION_REQUIRES[$key] ?? '' ?>" <?= $on ? 'checked' : '' ?>>
        </div>
        <div class="list-row-main">
          <div class="list-row-title"><?= sanitize($label) ?></div>
          <div class="list-row-sub"><?= sanitize($help) ?></div>
        </div>
        <span class="chip perm-tag"></span>
      </label>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <div class="form-actions">
    <?php if ($overrides): ?>
    <button class="btn btn-ghost me-auto" name="action" value="reset" data-confirm="Undo all changes and go back to the <?= sanitize($roleLabel) ?> defaults?"><i class="bi bi-arrow-counterclockwise"></i>Reset to <?= sanitize($roleLabel) ?> defaults</button>
    <?php endif; ?>
    <a href="/pages/staff.php" class="btn btn-ghost">Cancel</a>
    <button class="btn btn-gold" name="action" value="save"><i class="bi bi-check-lg"></i>Save access</button>
  </div>
</form>
<script>
(() => {
  const boxes = [...document.querySelectorAll('#accessForm .perm')];
  const byKey = Object.fromEntries(boxes.map((b) => [b.dataset.key, b]));
  const tag = (b) => {
    const t = b.closest('.list-row').querySelector('.perm-tag');
    const changed = b.checked !== (b.dataset.default === '1');
    t.textContent = changed ? (b.checked ? 'Added' : 'Removed') : 'Role default';
    t.className = 'chip perm-tag ' + (changed ? (b.checked ? 'aging-fresh' : 'aging-critical') : 'text-tx-3');
  };
  boxes.forEach((b) => b.addEventListener('change', () => {
    // Switching a feature on switches on what it needs; switching off takes away what depends on it
    if (b.checked) for (let n = b.dataset.needs; n && byKey[n]; n = byKey[n].dataset.needs) byKey[n].checked = true;
    else { let again = true; while (again) { again = false; boxes.forEach((d) => { if (d.checked && d.dataset.needs && !byKey[d.dataset.needs].checked) { d.checked = false; again = true; } }); } }
    boxes.forEach(tag);
  }));
  boxes.forEach(tag);
})();
</script>
<?php endif; ?>

<?php $history = staffHistory($db, 30, $member); ?>
<div class="card-crm mt-3" style="max-width:820px">
  <div class="card-header-crm"><span><i class="bi bi-clock-history"></i> History for <?= sanitize($member['name']) ?></span></div>
  <div class="list-rows">
    <?php foreach ($history as $h) echo staffHistoryRow($h); ?>
    <?php if (!$history): ?><div class="list-row"><span class="text-tx-3 fs-13">No changes recorded for <?= sanitize($member['name']) ?> yet.</span></div><?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
