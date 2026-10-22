<?php
// pages/branch.php?id= — one branch: details (Arabic name, DHA facility licence, TRN, contact), opening hours, the
// number prefix for its invoices and receipts, and its rooms. Part of Admin → Clinic setup.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventory.php';     // CSRF helpers
require_once __DIR__ . '/../includes/clinic_setup.php';
requirePermission('setup.clinic');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$s = $db->prepare("SELECT * FROM branches WHERE id = ?");
$s->execute([$id]);
$branch = $s->fetch();
if (!$branch) { http_response_code(404); $pageTitle = 'Branch not found'; require __DIR__ . '/../includes/header.php';
    echo '<div class="empty-state"><i class="bi bi-shop"></i><div class="empty-state-title">Branch not found</div><a href="/pages/settings_clinic.php">Back to Clinic setup</a></div>';
    require __DIR__ . '/../includes/footer.php'; exit; }
$flash = $_SESSION['setup_flash'] ?? null;
unset($_SESSION['setup_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $one = fn(string $k, int $max) => mb_substr(trim(str_replace(["\r", "\n"], ' ', (string)($_POST[$k] ?? ''))), 0, $max);
    try {
        $act = $_POST['action'] ?? '';
        if ($act === 'save') {
            $prefix = strtoupper($one('invoice_prefix', 6));
            $active = isset($_POST['is_active']) ? 1 : 0;
            if ($one('name', 120) === '') throw new RuntimeException('Enter the branch name.');
            if (!preg_match('/^[A-Z0-9]{2,6}$/', $prefix)) throw new RuntimeException('The number prefix is 2–6 capital letters or digits, e.g. JVC.');
            $dup = $db->prepare("SELECT COUNT(*) FROM branches WHERE invoice_prefix = ? AND id <> ?"); $dup->execute([$prefix, $id]);
            if ($dup->fetchColumn()) throw new RuntimeException("Another branch already uses the prefix $prefix.");
            // Numbers already issued keep their prefix, so the prefix is fixed once a document has been numbered
            $used = $db->prepare("SELECT COUNT(*) FROM number_sequences WHERE branch_id = ?"); $used->execute([$id]);
            if ($prefix !== $branch['invoice_prefix'] && $used->fetchColumn()) throw new RuntimeException('This branch has already numbered documents, so its prefix can no longer change.');
            if (!$active && $branch['is_active'] && $db->query("SELECT COUNT(*) FROM branches WHERE is_active = 1")->fetchColumn() <= 1) throw new RuntimeException('This is the only open branch.');
            if ($one('email', 190) !== '' && !filter_var($one('email', 190), FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid branch email, or leave it empty.');
            $hours = [];
            foreach (array_keys(WEEKDAYS) as $d) {
                if (empty($_POST['open'][$d])) continue;
                $from = (string)($_POST['from'][$d] ?? ''); $to = (string)($_POST['to'][$d] ?? '');
                validateScheduleRows([[$d, $from, $to]]);   // same time checks as practitioner schedules
                $hours[$d] = [$from, $to];
            }
            $db->prepare("UPDATE branches SET name=?, name_ar=?, dha_license=?, trn=?, address=?, phone=?, email=?, invoice_prefix=?, working_hours=?, is_active=? WHERE id=?")
               ->execute([$one('name', 120), $one('name_ar', 120) ?: null, $one('dha_license', 40) ?: null, $one('trn', 20) ?: null, $one('address', 255) ?: null,
                          $one('phone', 40) ?: null, $one('email', 190) ?: null, $prefix, json_encode((object)$hours), $active, $id]);
            logActivity(currentStaffId(), 'branch_changed', null, null, $one('name', 120));
            $_SESSION['setup_flash'] = ['success', 'Branch saved.'];
        } elseif ($act === 'room_save') {
            $rid = (int)($_POST['room_id'] ?? 0);
            $name = $one('room_name', 80);
            $type = isset(ROOM_TYPES[$_POST['type'] ?? '']) ? $_POST['type'] : 'other';
            if ($name === '') throw new RuntimeException('Enter the room name.');
            $dup = $db->prepare("SELECT COUNT(*) FROM rooms WHERE branch_id = ? AND name = ? AND id <> ?"); $dup->execute([$id, $name, $rid]);
            if ($dup->fetchColumn()) throw new RuntimeException("This branch already has a room called $name.");
            if ($rid) $db->prepare("UPDATE rooms SET name = ?, type = ?, is_active = ? WHERE id = ? AND branch_id = ?")->execute([$name, $type, isset($_POST['is_active']) ? 1 : 0, $rid, $id]);
            else $db->prepare("INSERT INTO rooms (branch_id, name, type) VALUES (?, ?, ?)")->execute([$id, $name, $type]);
            logActivity(currentStaffId(), $rid ? 'room_changed' : 'room_added', null, null, $branch['name'] . ': ' . $name);
            $_SESSION['setup_flash'] = ['success', 'Room saved.'];
        }
    } catch (Throwable $e) {
        $_SESSION['setup_flash'] = ['danger', $e->getMessage()];
    }
    header("Location: /pages/branch.php?id=$id"); exit;
}

$hours = json_decode($branch['working_hours'] ?? '{}', true) ?: [];
$rooms = $db->prepare("SELECT * FROM rooms WHERE branch_id = ? ORDER BY is_active DESC, name");
$rooms->execute([$id]);
$rooms = $rooms->fetchAll();
$editRoom = (int)($_GET['room'] ?? 0);
$v = fn(string $k) => sanitize((string)($branch[$k] ?? ''));

$pageTitle = $branch['name'];
$pageSubtitle = 'Branch · ' . count(array_filter($rooms, fn($r) => $r['is_active'])) . ' room' . (count($rooms) == 1 ? '' : 's');
$pageBack = ['href' => '/pages/settings_clinic.php', 'label' => 'Clinic setup'];
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash[0] ?>"><i class="bi bi-<?= $flash[0] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div>
<?php endif; ?>

<div class="row g-3 page-section">
  <div class="col-xl-7">
    <form method="POST" class="card-crm" autocomplete="off">
      <?= csrfField() ?><input type="hidden" name="action" value="save">
      <div class="card-header-crm"><span><i class="bi bi-shop"></i> Branch details</span></div>
      <div class="card-body-crm">
        <div class="row g-2 mb-3">
          <div class="col-md-6"><label class="form-label" for="bName">Name</label><input class="form-control" id="bName" name="name" value="<?= $v('name') ?>" maxlength="120" required></div>
          <div class="col-md-6"><label class="form-label" for="bNameAr">Arabic name</label><input class="form-control" id="bNameAr" name="name_ar" value="<?= $v('name_ar') ?>" dir="rtl" lang="ar"></div>
        </div>
        <div class="row g-2 mb-3">
          <div class="col-md-4"><label class="form-label" for="bDha">DHA facility licence</label><input class="form-control" id="bDha" name="dha_license" value="<?= $v('dha_license') ?>"></div>
          <div class="col-md-4"><label class="form-label" for="bTrn">TRN (VAT number)</label><input class="form-control" id="bTrn" name="trn" value="<?= $v('trn') ?>" inputmode="numeric"></div>
          <div class="col-md-4"><label class="form-label" for="bPrefix">Number prefix</label><input class="form-control text-uppercase" id="bPrefix" name="invoice_prefix" value="<?= $v('invoice_prefix') ?>" pattern="[A-Za-z0-9]{2,6}" required>
            <div class="form-text">Invoices: <?= $v('invoice_prefix') ?>-INV-<?= date('Y') ?>-000001</div></div>
        </div>
        <label class="form-label" for="bAddr">Address</label>
        <input class="form-control mb-3" id="bAddr" name="address" value="<?= $v('address') ?>" maxlength="255">
        <div class="row g-2 mb-3">
          <div class="col-md-6"><label class="form-label" for="bPhone">Phone</label><input class="form-control" id="bPhone" name="phone" value="<?= $v('phone') ?>"></div>
          <div class="col-md-6"><label class="form-label" for="bEmail">Email</label><input class="form-control" id="bEmail" name="email" type="email" value="<?= $v('email') ?>"></div>
        </div>
        <label class="form-label">Opening hours</label>
        <table class="table table-sm align-middle mb-3"><tbody>
          <?php foreach (WEEKDAYS as $d => $day): $h = $hours[$d] ?? null; ?>
          <tr>
            <td style="width:40%"><label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="open[<?= $d ?>]" value="1" <?= $h ? 'checked' : '' ?>>
              <span class="form-check-label"><?= $day ?></span></label></td>
            <td><input class="form-control form-control-sm" type="time" name="from[<?= $d ?>]" value="<?= sanitize($h[0] ?? '10:00') ?>" aria-label="<?= $day ?> opens"></td>
            <td><input class="form-control form-control-sm" type="time" name="to[<?= $d ?>]" value="<?= sanitize($h[1] ?? '22:00') ?>" aria-label="<?= $day ?> closes"></td>
          </tr>
          <?php endforeach; ?>
        </tbody></table>
        <label class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="is_active" <?= $branch['is_active'] ? 'checked' : '' ?>>
          <span class="form-check-label">Open (shown for bookings)</span></label>
      </div>
      <div class="form-actions"><button class="btn btn-gold ms-auto"><i class="bi bi-check-lg"></i>Save branch</button></div>
    </form>
  </div>

  <div class="col-xl-5">
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-door-open"></i> Rooms</span></div>
      <div class="list-group list-group-flush">
        <?php foreach ($rooms as $r): if ($editRoom === (int)$r['id']): ?>
        <form method="POST" class="list-group-item d-flex flex-wrap gap-2 align-items-center">
          <?= csrfField() ?><input type="hidden" name="action" value="room_save"><input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
          <input class="form-control form-control-sm" style="max-width:160px" name="room_name" value="<?= sanitize($r['name']) ?>" required aria-label="Room name">
          <select class="form-select form-select-sm" style="max-width:150px" name="type" aria-label="Room type">
            <?php foreach (ROOM_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $r['type'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
          <label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="is_active" <?= $r['is_active'] ? 'checked' : '' ?>> <span class="form-check-label">In use</span></label>
          <button class="btn btn-gold btn-sm"><i class="bi bi-check-lg"></i>Save</button>
          <a href="?id=<?= $id ?>" class="btn btn-ghost btn-sm">Cancel</a>
        </form>
        <?php else: ?>
        <div class="list-group-item d-flex align-items-center gap-2">
          <span class="fw-semibold <?= $r['is_active'] ? '' : 'text-tx-3 text-decoration-line-through' ?>"><?= sanitize($r['name']) ?></span>
          <span class="chip"><?= ROOM_TYPES[$r['type']] ?></span>
          <a href="?id=<?= $id ?>&amp;room=<?= (int)$r['id'] ?>" class="btn btn-ghost btn-sm ms-auto" aria-label="Edit <?= sanitize($r['name']) ?>"><i class="bi bi-pencil"></i></a>
        </div>
        <?php endif; endforeach; ?>
        <?php if (!$rooms): ?><div class="list-group-item text-tx-3 fs-13">No rooms yet.</div><?php endif; ?>
      </div>
      <form method="POST" class="form-actions flex-wrap gap-2">
        <?= csrfField() ?><input type="hidden" name="action" value="room_save">
        <input class="form-control" style="max-width:170px" name="room_name" placeholder="Room name" required aria-label="New room name">
        <select class="form-select" style="max-width:160px" name="type" aria-label="Room type"><?php foreach (ROOM_TYPES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select>
        <button class="btn btn-gold"><i class="bi bi-plus-lg"></i>Add room</button>
      </form>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
