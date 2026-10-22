<?php
// pages/sources.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();
$success = '';
$error   = '';

// ── Check if lead_sources table exists ──
$tableExists = false;
try {
    $db->query("SELECT 1 FROM lead_sources LIMIT 1");
    $tableExists = true;
} catch (PDOException $e) {
    $tableExists = false;
}

// ── If table missing, show a helpful setup message ──
if (!$tableExists) {
    $pageTitle = 'Lead Sources';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <div class="alert alert-warning">
        <h5 class="mb-2"><i class="bi bi-exclamation-triangle-fill me-2"></i>Database setup required</h5>
        <p class="mb-3" style="font-size:13.5px;">
            The <code>lead_sources</code> table does not exist yet. Please run the following SQL
            in your <strong>phpMyAdmin</strong> (select your database → SQL tab → paste → click Go):
        </p>
        <pre style="background:#f4f3f0;padding:14px;border-radius:8px;font-size:12px;overflow-x:auto;">CREATE TABLE IF NOT EXISTS lead_sources (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    slug       VARCHAR(60)  NOT NULL UNIQUE,
    icon       VARCHAR(10),
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order INT UNSIGNED DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO lead_sources (name, slug, icon, sort_order) VALUES
('Meta Form',  'meta_form',  '🎯', 1),
('WhatsApp',   'whatsapp',   '💬', 2),
('Instagram',  'instagram',  '📸', 3),
('Facebook',   'facebook',   '👍', 4),
('Phone Call', 'phone_call', '📞', 5),
('Walk-in',    'walkin',     '🚶', 6),
('Referral',   'referral',   '🤝', 7),
('Other',      'other',      '📌', 8);</pre>
        <p class="mb-0 mt-2" style="font-size:12.5px;color:#6b7280;">
            After running the SQL, reload this page and it will work.
        </p>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// ── Table exists — handle POST actions (admin only) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can('setup.sources')) {
    $action = $_POST['action'] ?? '';
    $sid    = (int)($_POST['source_id']  ?? 0);
    $name   = trim($_POST['name']        ?? '');
    $icon   = trim($_POST['icon']        ?? '');
    $order  = (int)($_POST['sort_order'] ?? 0);
    $active = isset($_POST['is_active'])  ? 1 : 0;

    // Generate slug: lowercase, replace non-alphanumeric with underscore
    $slug = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
    if (!$slug) $slug = 'source_' . time();

    if ($action === 'add') {
        if (!$name) {
            $error = 'Source name is required.';
        } else {
            // Make slug unique if taken
            $chk = $db->prepare("SELECT id FROM lead_sources WHERE slug = ?");
            $chk->execute([$slug]);
            if ($chk->fetch()) {
                $slug .= '_' . substr((string)time(), -4);
            }
            try {
                $db->prepare(
                    "INSERT INTO lead_sources (name, slug, icon, sort_order) VALUES (?, ?, ?, ?)"
                )->execute([$name, $slug, $icon, $order]);
                $success = "Source '{$name}' added successfully.";
            } catch (PDOException $e) {
                $error = 'Could not add source. Error: ' . $e->getMessage();
            }
        }

    } elseif ($action === 'edit' && $sid) {
        try {
            $db->prepare(
                "UPDATE lead_sources SET name = ?, icon = ?, sort_order = ?, is_active = ? WHERE id = ?"
            )->execute([$name, $icon, $order, $active, $sid]);
            $success = 'Source updated successfully.';
        } catch (PDOException $e) {
            $error = 'Could not update. Error: ' . $e->getMessage();
        }

    } elseif ($action === 'toggle' && $sid) {
        try {
            $db->prepare(
                "UPDATE lead_sources SET is_active = 1 - is_active WHERE id = ?"
            )->execute([$sid]);
        } catch (PDOException $e) {
            $error = 'Could not toggle. Error: ' . $e->getMessage();
        }
        header('Location: /pages/sources.php');
        exit;
    }
}

// ── Load all sources with lead counts ──
try {
    $allSources = $db->query("
        SELECT
            ls.id, ls.name, ls.slug, ls.icon, ls.is_active, ls.sort_order,
            COUNT(l.id) AS lead_count
        FROM lead_sources ls
        LEFT JOIN leads l ON l.source = ls.slug
        GROUP BY ls.id, ls.name, ls.slug, ls.icon, ls.is_active, ls.sort_order
        ORDER BY ls.sort_order ASC, ls.name ASC
    ")->fetchAll();
} catch (PDOException $e) {
    // If collation mismatch on JOIN, fall back to no count
    try {
        $allSources = $db->query("
            SELECT id, name, slug, icon, is_active, sort_order, 0 AS lead_count
            FROM lead_sources
            ORDER BY sort_order ASC, name ASC
        ")->fetchAll();
    } catch (PDOException $e2) {
        $allSources = [];
        $error = 'Could not load sources: ' . $e2->getMessage();
    }
}

// ── Edit mode ──
$editSrc = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try {
        $stmt = $db->prepare("SELECT * FROM lead_sources WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $editSrc = $stmt->fetch();
    } catch (PDOException $e) {
        $editSrc = null;
    }
}

// Is leads.source still a fixed ENUM? Then custom sources can't be saved on leads.
$sourceIsEnum = false;
try {
    $col = $db->query("SHOW COLUMNS FROM leads LIKE 'source'")->fetch();
    $sourceIsEnum = $col && stripos($col['Type'], 'enum') === 0;
} catch (PDOException $e) {}
$builtIn = ['meta_form','whatsapp','instagram','facebook','phone_call','walkin','referral','other'];
$customSources = array_filter($allSources, fn($s) => !in_array($s['slug'], $builtIn, true));

$pageTitle    = 'Lead Sources';
$pageSubtitle = 'Where leads come from. Shown in lead forms, filters and reports.';
$pageActions  = can('setup.sources') && $editSrc ? '<a href="/pages/sources.php" class="btn btn-gold"><i class="bi bi-plus-lg"></i>Add source</a>' : '';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($success): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i><?= sanitize($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><?= sanitize($error) ?></div><?php endif; ?>

<?php if ($sourceIsEnum && $customSources && can('setup.sources')): ?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle-fill"></i>
  <div>
    <strong>Custom sources can’t be saved on leads yet.</strong>
    The database only accepts the 8 built-in sources, so leads saved as
    <?= implode(', ', array_map(fn($s) => '“' . sanitize($s['name']) . '”', $customSources)) ?> lose their source.
    Ask your developer to run: <code>ALTER TABLE leads MODIFY source VARCHAR(60) NOT NULL DEFAULT 'other';</code>
  </div>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="<?= can('setup.sources') ? 'col-xl-8' : 'col-12' ?>">
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-signpost-split"></i> Sources</span><span class="card-header-sub"><?= count($allSources) ?> total</span></div>
      <div class="table-responsive">
        <table class="table table-hover">
          <thead>
            <tr><th style="width:64px">Order</th><th>Source</th><th>Slug</th><th class="text-end">Leads</th><th>Status</th><?php if (can('setup.sources')): ?><th class="text-end"></th><?php endif; ?></tr>
          </thead>
          <tbody>
            <?php if (empty($allSources)): ?>
            <tr><td colspan="6"><div class="empty-state"><i class="bi bi-signpost"></i><p>No sources yet. Add one on the right.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($allSources as $s): ?>
            <tr class="<?= !$s['is_active'] ? 'opacity-50' : '' ?> <?= $editSrc && $editSrc['id'] == $s['id'] ? 'table-active' : '' ?>">
              <td class="text-tx-3 tabular"><?= (int)$s['sort_order'] ?></td>
              <td>
                <span class="d-inline-flex align-items-center gap-2">
                  <span class="source-emoji"><?= htmlspecialchars($s['icon'] ?: '📌', ENT_QUOTES, 'UTF-8') ?></span>
                  <span class="cell-title"><?= sanitize($s['name']) ?></span>
                </span>
              </td>
              <td><code class="slug"><?= sanitize($s['slug']) ?></code></td>
              <td class="text-end tabular"><a href="/pages/leads.php?source=<?= urlencode($s['slug']) ?>"><?= (int)$s['lead_count'] ?></a></td>
              <td><span class="chip <?= $s['is_active'] ? 'aging-fresh' : '' ?>"><?= $s['is_active'] ? 'Active' : 'Hidden' ?></span></td>
              <?php if (can('setup.sources')): ?>
              <td class="text-end">
                <div class="row-actions">
                  <a href="?edit=<?= (int)$s['id'] ?>" class="btn-icon" title="Edit"><i class="bi bi-pencil"></i></a>
                  <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="source_id" value="<?= (int)$s['id'] ?>">
                    <button type="submit" class="btn-icon" title="<?= $s['is_active'] ? 'Hide from forms' : 'Show in forms' ?>"><i class="bi bi-<?= $s['is_active'] ? 'eye-slash' : 'eye' ?>"></i></button>
                  </form>
                </div>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer-crm fs-12 text-tx-3">
        <i class="bi bi-info-circle"></i> The <strong>slug</strong> is what’s stored on each lead. It can’t be changed after creation, and it’s the value to use in the <em>source</em> column of CSV imports.
      </div>
    </div>
  </div>

  <?php if (can('setup.sources')): ?>
  <div class="col-xl-4">
    <div class="card-crm form-side">
      <div class="card-header-crm"><span><i class="bi bi-<?= $editSrc ? 'pencil' : 'plus-lg' ?>"></i> <?= $editSrc ? 'Edit source' : 'Add source' ?></span></div>
      <form method="POST">
        <div class="card-body-crm">
          <input type="hidden" name="action" value="<?= $editSrc ? 'edit' : 'add' ?>">
          <?php if ($editSrc): ?><input type="hidden" name="source_id" value="<?= (int)$editSrc['id'] ?>"><?php endif; ?>

          <div class="mb-3">
            <label class="form-label" for="srcName">Name <span class="req">*</span></label>
            <input type="text" name="name" id="srcName" class="form-control" required value="<?= sanitize($editSrc['name'] ?? '') ?>" placeholder="e.g. TikTok, Google Ads, Event">
            <div class="form-text">
              <?php if ($editSrc): ?>Slug: <code class="slug"><?= sanitize($editSrc['slug']) ?></code> (fixed)
              <?php else: ?>Slug: <code class="slug" id="slugPreview">—</code><?php endif; ?>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label" for="iconInput">Icon</label>
            <div class="d-flex gap-2 align-items-center mb-2">
              <input type="text" name="icon" id="iconInput" class="form-control text-center" maxlength="8" style="width:64px;font-size:18px"
                     value="<?= htmlspecialchars($editSrc['icon'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="📌">
              <span class="form-text m-0">Pick one below or type any emoji.</span>
            </div>
            <div class="emoji-grid">
              <?php foreach (['🎯','💬','📸','👍','📞','🚶','🤝','📧','🎪','📺','🎬','🌐','📱','💌','🔍','📌','🏥','🎁','🛍️','🎀','✨','💎','🌸','📣'] as $em): ?>
              <button type="button" onclick="document.getElementById('iconInput').value='<?= $em ?>'" aria-label="Use <?= $em ?>"><?= $em ?></button>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label" for="srcOrder">Sort order</label>
            <input type="number" name="sort_order" id="srcOrder" class="form-control" min="0" value="<?= (int)($editSrc['sort_order'] ?? (count($allSources) + 1)) ?>">
            <div class="form-text">Lower numbers appear first in dropdowns.</div>
          </div>

          <?php if ($editSrc): ?>
          <label class="form-check mb-0">
            <input type="checkbox" class="form-check-input" name="is_active" <?= $editSrc['is_active'] ? 'checked' : '' ?>>
            <span class="form-check-label">Active (shown in forms and filters)</span>
          </label>
          <?php endif; ?>
        </div>
        <div class="form-actions">
          <?php if ($editSrc): ?><a href="/pages/sources.php" class="btn btn-ghost">Cancel</a><?php endif; ?>
          <button type="submit" class="btn btn-gold"><i class="bi bi-check-lg"></i><?= $editSrc ? 'Save changes' : 'Add source' ?></button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php
$extraJs = "
const nm = document.getElementById('srcName'), sp = document.getElementById('slugPreview');
if (nm && sp) nm.addEventListener('input', () => { sp.textContent = nm.value.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || '—'; });
";
require_once __DIR__ . '/../includes/footer.php';
?>
