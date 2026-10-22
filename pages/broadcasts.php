<?php
// pages/broadcasts.php — Marketing → Broadcasts: one message to a group of patients who agreed to marketing (branch,
// gender, not visited for N days, had a treatment, has a package, tag). Shows how many it will reach before it starts;
// cron sends 200 at a time, never in quiet hours, never twice to the same patient; results by status.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/crm.php';
requirePermission('marketing.broadcasts');

$db = getDB();
ensureDefaultTemplates($db);
$seg = fn(array $in) => array_filter(['branch_id' => (int)($in['branch_id'] ?? 0) ?: null, 'gender' => in_array($in['gender'] ?? '', ['female', 'male'], true) ? $in['gender'] : null,
    'not_visited_days' => (int)($in['not_visited_days'] ?? 0) ?: null, 'service_id' => (int)($in['service_id'] ?? 0) ?: null, 'has_package' => !empty($in['has_package']) ? 1 : null,
    'tag' => trim((string)($in['tag'] ?? '')) ?: null], fn($v) => $v !== null);
if (($_GET['count'] ?? '') === '1') { header('Content-Type: application/json'); echo json_encode(['n' => count(segmentPatientIds($db, $seg($_GET)))]); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        if (($_POST['action'] ?? '') === 'create') { $id = createBroadcast($db, (string)($_POST['name'] ?? ''), (int)($_POST['template_id'] ?? 0), $seg($_POST)); $_SESSION['crm_flash'] = ['success', 'Saved. Check the numbers, then start it.']; }
        elseif (($_POST['action'] ?? '') === 'start') { startBroadcast($db, (int)$_POST['id']); $_SESSION['crm_flash'] = ['success', 'Started. Messages go out over the next minutes (not in quiet hours).']; }
        elseif (($_POST['action'] ?? '') === 'cancel') { $db->prepare("UPDATE broadcasts SET status = 'cancelled' WHERE id = ? AND status IN ('draft','sending')")->execute([(int)$_POST['id']]); $_SESSION['crm_flash'] = ['success', 'Stopped.']; }
    } catch (Throwable $e) { $_SESSION['crm_flash'] = ['danger', $e->getMessage()]; }
    header('Location: /pages/broadcasts.php'); exit;
}
$flash = $_SESSION['crm_flash'] ?? null;
unset($_SESSION['crm_flash']);
$list = $db->query("SELECT b.*, t.name AS template, t.channel FROM broadcasts b JOIN message_templates t ON t.id = b.template_id ORDER BY b.id DESC LIMIT 50")->fetchAll();
$templates = $db->query("SELECT id, name, channel FROM message_templates WHERE is_active = 1 AND purpose IN ('custom','birthday','recall') ORDER BY channel, name")->fetchAll();
$services = $db->query("SELECT id, name FROM services WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$consented = (int)$db->query("SELECT COUNT(*) FROM patients WHERE status = 'active' AND marketing_consent = 1")->fetchColumn();

$pageTitle = 'Broadcasts';
$pageSubtitle = number_format($consented) . ' patients agreed to marketing';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?> <?= $flash[0] === 'success' ? 'alert-auto' : '' ?>"><i class="bi bi-info-circle"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<div class="row g-3">
  <div class="col-xl-7"><div class="card-crm"><div class="list-group list-group-flush">
    <?php foreach ($list as $b): $res = broadcastResults($db, (int)$b['id']); ?><div class="list-group-item">
      <div class="d-flex gap-2 align-items-center"><span class="fw-600"><?= sanitize($b['name']) ?></span><span class="chip <?= ['draft' => 'tone-slate', 'sending' => 'tone-blue', 'sent' => 'tone-green', 'cancelled' => 'tone-slate'][$b['status']] ?>"><?= ucfirst($b['status']) ?></span>
        <span class="ms-auto fs-12 text-tx-3"><?= MSG_CHANNELS[$b['channel']] ?> · <?= sanitize($b['template']) ?> · <?= formatDate($b['created_at']) ?></span></div>
      <div class="fs-13 mt-1"><?= (int)$b['recipients'] ?> patients<?php foreach ($res as $k => $n): ?> · <?= sanitize($k) ?> <?= (int)$n ?><?php endforeach; ?></div>
      <?php if (in_array($b['status'], ['draft', 'sending'], true)): ?><form method="POST" class="d-flex gap-1 mt-1"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
        <?php if ($b['status'] === 'draft'): ?><button class="btn btn-gold btn-sm" name="action" value="start" onclick="return confirm('Send to <?= (int)$b['recipients'] ?> patients?')">Start sending</button><?php endif; ?>
        <button class="btn btn-ghost btn-sm" name="action" value="cancel">Stop</button></form><?php endif; ?>
    </div><?php endforeach; ?>
    <?php if (!$list): ?><div class="list-group-item text-tx-3">No broadcasts yet.</div><?php endif; ?>
  </div></div></div>
  <div class="col-xl-5"><form method="POST" class="card-crm" id="bcForm"><?= csrfField() ?><input type="hidden" name="action" value="create">
    <div class="card-header-crm"><span><i class="bi bi-megaphone"></i> New broadcast</span></div><div class="card-body-crm">
      <label class="form-label" for="bn">Name</label><input class="form-control mb-2" id="bn" name="name" required maxlength="150">
      <label class="form-label" for="bt">Message template</label><select class="form-select mb-2" id="bt" name="template_id" required><?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>"><?= MSG_CHANNELS[$t['channel']] ?>: <?= sanitize($t['name']) ?></option><?php endforeach; ?></select>
      <div class="row g-2">
        <div class="col-6"><label class="form-label" for="bb">Branch</label><select class="form-select" id="bb" name="branch_id"><option value="">All</option><?php foreach (branchList($db) as $br): ?><option value="<?= (int)$br['id'] ?>"><?= sanitize($br['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label" for="bg">Gender</label><select class="form-select" id="bg" name="gender"><option value="">Any</option><option value="female">Female</option><option value="male">Male</option></select></div>
        <div class="col-6"><label class="form-label" for="bv">Not visited for (days)</label><input class="form-control" type="number" min="0" id="bv" name="not_visited_days"></div>
        <div class="col-6"><label class="form-label" for="bs">Had this treatment</label><select class="form-select" id="bs" name="service_id"><option value="">Any</option><?php foreach ($services as $k => $n): ?><option value="<?= (int)$k ?>"><?= sanitize($n) ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label" for="btg">Tag</label><input class="form-control" id="btg" name="tag"></div>
        <div class="col-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" id="bp" name="has_package" value="1"><label class="form-check-label" for="bp">Has an active package</label></div></div>
      </div>
      <p class="mt-3 mb-0 fw-600" role="status" aria-live="polite">Reaches <span id="bcCount">…</span> patients (marketing consent only).</p>
    </div><div class="form-actions"><button class="btn btn-gold ms-auto">Save</button></div></form>
    <script>
    (function () { var f = document.getElementById('bcForm'), t;
      function count() { clearTimeout(t); t = setTimeout(function () { var q = new URLSearchParams(new FormData(f)); q.delete('_csrf'); q.set('count', '1');
        fetch('/pages/broadcasts.php?' + q, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { document.getElementById('bcCount').textContent = j.n; }); }, 300); }
      f.addEventListener('input', count); f.addEventListener('change', count); count(); })();
    </script></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
