<?php
// pages/whatsapp.php — WhatsApp Web capture: API keys, extension download, captured leads (admin)
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/api_keys.php';
requirePermission('setup.whatsapp');

$db = getDB();
ensureApiKeysTable($db);
$extDir = realpath(__DIR__ . '/../whatsapp-extension');

// ── Download extension as zip ──
if (isset($_GET['download'])) {
    if (!$extDir) { http_response_code(500); die('Extension files are missing on the server (whatsapp-extension folder).'); }
    $tmp = tempnam(sys_get_temp_dir(), 'ext') . '.zip';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extDir, FilesystemIterator::SKIP_DOTS));
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $file) {
            $zip->addFile($file->getPathname(), 'clinic-crm-whatsapp/' . str_replace('\\', '/', substr($file->getPathname(), strlen($extDir) + 1)));
        }
        $zip->close();
    } else {
        // Fallback when the zip extension is disabled
        $zip = new PharData($tmp, 0, null, Phar::ZIP);
        foreach ($files as $file) {
            $zip->addFile($file->getPathname(), 'clinic-crm-whatsapp/' . str_replace('\\', '/', substr($file->getPathname(), strlen($extDir) + 1)));
        }
        unset($zip);
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="clinic-crm-whatsapp.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

$newKey = null;
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $label = trim($_POST['label'] ?? '');
        $assign = (int)($_POST['assign_to'] ?? 0) ?: null;
        if ($label === '') {
            $error = 'Give the key a name, e.g. "Reception PC".';
        } else {
            $newKey = createApiKey($db, $label, $assign, currentStaffId());
            logActivity(currentStaffId(), 'api_key_created', null, null, $label);
        }
    } elseif ($action === 'toggle') {
        $db->prepare("UPDATE api_keys SET is_active = 1 - is_active WHERE id = ?")->execute([(int)$_POST['key_id']]);
        header('Location: /pages/whatsapp.php'); exit;
    } elseif ($action === 'assign') {
        $db->prepare("UPDATE api_keys SET assign_to = ? WHERE id = ?")->execute([(int)$_POST['assign_to'] ?: null, (int)$_POST['key_id']]);
        header('Location: /pages/whatsapp.php'); exit;
    }
}

$keys = $db->query("
    SELECT k.*, s.name AS assign_name, c.name AS creator_name
    FROM api_keys k
    LEFT JOIN staff s ON s.id = k.assign_to
    LEFT JOIN staff c ON c.id = k.created_by
    ORDER BY k.is_active DESC, k.created_at DESC
")->fetchAll();
$allStaff = $db->query("SELECT id, name FROM staff WHERE is_active=1 ORDER BY name")->fetchAll();

$captured = $db->query("
    SELECT l.id, l.name, l.phone, l.stage, l.created_at, l.source_campaign, s.name AS staff_name
    FROM leads l LEFT JOIN staff s ON s.id = l.assigned_to
    WHERE l.source = 'whatsapp' AND l.source_campaign LIKE 'WhatsApp Web%'
    ORDER BY l.created_at DESC LIMIT 25
")->fetchAll();
$capturedToday = (int)$db->query("SELECT COUNT(*) FROM leads WHERE source='whatsapp' AND source_campaign LIKE 'WhatsApp Web%' AND DATE(created_at)=CURDATE()")->fetchColumn();
$capturedWeek  = (int)$db->query("SELECT COUNT(*) FROM leads WHERE source='whatsapp' AND source_campaign LIKE 'WhatsApp Web%' AND created_at >= CURDATE() - INTERVAL 6 DAY")->fetchColumn();
$activeKeys    = count(array_filter($keys, fn($k) => $k['is_active']));
$crmUrl        = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com');

$pageTitle    = 'WhatsApp capture';
$pageSubtitle = 'Turn new WhatsApp Web chats into leads automatically with the Chrome extension.';
$pageActions  = '<a href="?download=1" class="btn btn-gold"><i class="bi bi-download"></i>Download extension</a>';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><?= sanitize($error) ?></div><?php endif; ?>

<?php if ($newKey): ?>
<div class="alert alert-success">
  <i class="bi bi-key-fill"></i>
  <div class="flex-grow-1">
    <strong>Key created. Copy it now; it won’t be shown again.</strong>
    <div class="input-group mt-2" style="max-width:560px">
      <input type="text" class="form-control font-monospace fs-12" value="<?= sanitize($newKey) ?>" readonly id="newKey">
      <button class="btn btn-ghost" type="button" onclick="copyVal('newKey', this)"><i class="bi bi-copy"></i>Copy</button>
    </div>
    <div class="fs-12 mt-2">Paste it into the extension’s settings on the computer that runs WhatsApp Web.</div>
  </div>
</div>
<?php endif; ?>

<div class="row g-3 page-section">
  <?php foreach ([
    ['Captured today', $capturedToday, 'bi-whatsapp', 'tone-green'],
    ['Last 7 days', $capturedWeek, 'bi-calendar-week', 'tone-gold'],
    ['Connected devices', $activeKeys, 'bi-pc-display', $activeKeys ? 'tone-blue' : 'tone-slate'],
  ] as [$l, $v, $i, $t]): ?>
  <div class="col-md-4">
    <div class="stat-card">
      <div class="stat-top"><div class="stat-label"><?= $l ?></div><span class="stat-icon <?= $t ?>"><i class="bi <?= $i ?>"></i></span></div>
      <div class="stat-value"><?= $v ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-xl-7 d-flex flex-column gap-3">

    <!-- Keys -->
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-key"></i> Connected devices</span><span class="card-header-sub">One key per computer</span></div>
      <?php if (!$keys): ?>
      <div class="empty-state"><i class="bi bi-pc-display"></i><div class="empty-state-title">No devices yet</div><p>Create a key on the right, then paste it into the extension.</p></div>
      <?php else: ?>
      <div class="table-responsive">
      <table class="table">
        <thead><tr><th>Device</th><th>New leads go to</th><th>Last used</th><th>Status</th><th class="text-end"></th></tr></thead>
        <tbody>
        <?php foreach ($keys as $k): ?>
        <tr class="<?= $k['is_active'] ? '' : 'opacity-50' ?>">
          <td>
            <span class="cell-title"><?= sanitize($k['label']) ?></span>
            <span class="cell-sub"><code class="slug"><?= sanitize($k['key_prefix']) ?>…</code> · created <?= date('d M Y', strtotime($k['created_at'])) ?></span>
          </td>
          <td>
            <form method="POST" class="m-0">
              <input type="hidden" name="action" value="assign"><input type="hidden" name="key_id" value="<?= $k['id'] ?>">
              <select name="assign_to" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Assign new leads to">
                <option value="">Unassigned</option>
                <?php foreach ($allStaff as $s): ?><option value="<?= $s['id'] ?>" <?= (int)$k['assign_to'] === (int)$s['id'] ? 'selected' : '' ?>><?= sanitize($s['name']) ?></option><?php endforeach; ?>
              </select>
            </form>
          </td>
          <td class="fs-12 text-tx-2 text-nowrap"><?= $k['last_used_at'] ? timeSince($k['last_used_at']) : 'Never' ?></td>
          <td><span class="chip <?= $k['is_active'] ? 'aging-fresh' : '' ?>"><?= $k['is_active'] ? 'Active' : 'Revoked' ?></span></td>
          <td class="text-end">
            <form method="POST" class="d-inline m-0">
              <input type="hidden" name="action" value="toggle"><input type="hidden" name="key_id" value="<?= $k['id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm" <?= $k['is_active'] ? 'data-confirm="Revoke this key? That computer will stop sending leads."' : '' ?>>
                <?= $k['is_active'] ? 'Revoke' : 'Re-enable' ?>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Captured leads -->
    <div class="card-crm">
      <div class="card-header-crm">
        <span><i class="bi bi-whatsapp"></i> Recently captured</span>
        <a href="/pages/leads.php?source=whatsapp" class="card-link">All WhatsApp leads <i class="bi bi-arrow-right"></i></a>
      </div>
      <?php if (!$captured): ?>
      <div class="empty-state"><i class="bi bi-inbox"></i><div class="empty-state-title">Nothing captured yet</div><p>New chats from unknown numbers will appear here.</p></div>
      <?php else: ?>
      <div class="list-rows">
        <?php foreach ($captured as $c): ?>
        <a class="list-row" href="/pages/lead_view.php?id=<?= $c['id'] ?>">
          <span class="avatar avatar-sm tone-green"><i class="bi bi-whatsapp"></i></span>
          <div class="list-row-main">
            <div class="list-row-title"><?= sanitize($c['name']) ?></div>
            <div class="list-row-sub tabular"><?= sanitize($c['phone']) ?> · <?= $c['source_campaign'] === 'WhatsApp Web (auto)' ? 'auto-captured' : 'added manually' ?><?= $c['staff_name'] ? ' · ' . sanitize($c['staff_name']) : '' ?></div>
          </div>
          <div class="list-row-end"><?= stageBadge($c['stage']) ?><span class="fs-12 text-tx-3"><?= timeSince($c['created_at']) ?></span></div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-xl-5 d-flex flex-column gap-3">
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-plus-lg"></i> Connect a computer</span></div>
      <form method="POST">
        <div class="card-body-crm">
          <input type="hidden" name="action" value="create">
          <div class="mb-3">
            <label class="form-label" for="kLabel">Device name</label>
            <input type="text" name="label" id="kLabel" class="form-control" placeholder="e.g. Reception PC" required maxlength="100">
          </div>
          <div>
            <label class="form-label" for="kAssign">Assign new WhatsApp leads to</label>
            <select name="assign_to" id="kAssign" class="form-select">
              <option value="">Unassigned</option>
              <?php foreach ($allStaff as $s): ?><option value="<?= $s['id'] ?>"><?= sanitize($s['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-gold"><i class="bi bi-key"></i>Create key</button></div>
      </form>
    </div>

    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-list-check"></i> Install the extension (Chrome or Edge)</span></div>
      <div class="card-body-crm">
        <ol class="steps">
          <li><a href="?download=1">Download the extension</a> and unzip it to a permanent folder (e.g. <code>Documents</code>).</li>
          <li>Open <code>chrome://extensions</code>, switch on <strong>Developer mode</strong> (top right), click <strong>Load unpacked</strong> and choose the unzipped <code>clinic-crm-whatsapp</code> folder.</li>
          <li>Click the extension’s icon → <strong>Settings</strong>. Enter the CRM address below and the key you created, then <strong>Save &amp; test</strong>.</li>
          <li>Open <a href="https://web.whatsapp.com" target="_blank" rel="noopener">web.whatsapp.com</a>. A small CRM panel appears at the bottom right.</li>
        </ol>
        <label class="form-label mt-2">CRM address</label>
        <div class="input-group">
          <input type="text" class="form-control font-monospace fs-12" value="<?= sanitize($crmUrl) ?>" readonly id="crmUrl">
          <button class="btn btn-ghost" type="button" onclick="copyVal('crmUrl', this)"><i class="bi bi-copy"></i></button>
        </div>
      </div>
    </div>

    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-info-circle"></i> How capture works</span></div>
      <div class="card-body-crm fs-13 text-tx-2">
        <div class="kv"><span class="kv-label">Automatic</span><span class="kv-value fw-normal">Unread chats from numbers <em>not saved</em> in the phone’s contacts become new leads (source: WhatsApp, stage: New), with their first message in the notes.</span></div>
        <div class="kv"><span class="kv-label">Manual</span><span class="kv-value fw-normal">On any open chat, the panel shows whether the contact is already in the CRM, or lets you add them with one click.</span></div>
        <div class="kv"><span class="kv-label">Duplicates</span><span class="kv-value fw-normal">Matched on the last 9 digits, so 050… and +97150… count as the same person.</span></div>
        <div class="kv"><span class="kv-label">Limits</span><span class="kv-value fw-normal">Only works while WhatsApp Web is open in that browser. It never sends messages.</span></div>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = "
function copyVal(id, btn) {
  navigator.clipboard?.writeText(document.getElementById(id).value).then(() => { const i = btn.querySelector('i'); i.className = 'bi bi-check2'; setTimeout(() => i.className = 'bi bi-copy', 1200); });
}";
require_once __DIR__ . '/../includes/footer.php';
?>
