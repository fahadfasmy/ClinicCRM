<?php
// pages/settings_clinic.php — Admin → Clinic setup: branches, departments, document numbering, and links to every other
// clinic setting. Each branch (details, opening hours, rooms) is edited on branch.php; practitioners on practitioners.php.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventory.php';     // CSRF helpers
require_once __DIR__ . '/../includes/clinic_setup.php';
requirePermission('setup.clinic');

$db = getDB();
$flash = $_SESSION['setup_flash'] ?? null;
unset($_SESSION['setup_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $go = '/pages/settings_clinic.php';
    try {
        $act = $_POST['action'] ?? '';
        if ($act === 'branch_add') {
            $name = mb_substr(trim($_POST['name'] ?? ''), 0, 120);
            $prefix = strtoupper(trim($_POST['invoice_prefix'] ?? ''));
            if ($name === '') throw new RuntimeException('Enter the branch name.');
            if (!preg_match('/^[A-Z0-9]{2,6}$/', $prefix)) throw new RuntimeException('The number prefix is 2–6 capital letters or digits, e.g. JVC.');
            $dup = $db->prepare("SELECT COUNT(*) FROM branches WHERE invoice_prefix = ?"); $dup->execute([$prefix]);
            if ($dup->fetchColumn()) throw new RuntimeException("Another branch already uses the prefix $prefix.");
            planCheck($db, 'branches');
            $db->prepare("INSERT INTO branches (name, invoice_prefix) VALUES (?, ?)")->execute([$name, $prefix]);
            $id = (int)$db->lastInsertId();
            logActivity(currentStaffId(), 'branch_added', null, null, "$name ($prefix)");
            $_SESSION['setup_flash'] = ['success', 'Branch added. Add its details, opening hours and rooms here.'];
            $go = "/pages/branch.php?id=$id";
        } elseif ($act === 'mrn_save') {
            $prefix = trim((string)($_POST['mrn_prefix'] ?? ''));
            $pad = (int)($_POST['mrn_padding'] ?? 6);
            if (!preg_match('/^[A-Za-z0-9-]{0,10}$/', $prefix)) throw new RuntimeException('The file number prefix is up to 10 letters, digits or dashes.');
            if ($pad < 4 || $pad > 10) throw new RuntimeException('The number of digits is between 4 and 10.');
            $s = $db->prepare("REPLACE INTO integration_settings (k, v) VALUES (?, ?)");
            $s->execute(['clinic.mrn_prefix', $prefix]); $s->execute(['clinic.mrn_padding', (string)$pad]);
            tenantSetting('__reload');
            logActivity(currentStaffId(), 'mrn_format_changed', null, null, mrnFormat(1));
            $_SESSION['setup_flash'] = ['success', 'File numbers will look like ' . mrnFormat(123) . '. Existing ones stay as they are.'];
        } elseif ($act === 'dept_save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = mb_substr(trim($_POST['name'] ?? ''), 0, 100);
            $nameAr = mb_substr(trim($_POST['name_ar'] ?? ''), 0, 100) ?: null;
            if ($name === '') throw new RuntimeException('Enter the department name.');
            $dup = $db->prepare("SELECT COUNT(*) FROM departments WHERE name = ? AND id <> ?"); $dup->execute([$name, $id]);
            if ($dup->fetchColumn()) throw new RuntimeException("There is already a department called $name.");
            if ($id) {
                $db->prepare("UPDATE departments SET name = ?, name_ar = ?, is_active = ? WHERE id = ?")->execute([$name, $nameAr, isset($_POST['is_active']) ? 1 : 0, $id]);
            } else {
                $db->prepare("INSERT INTO departments (name, name_ar) VALUES (?, ?)")->execute([$name, $nameAr]);
            }
            logActivity(currentStaffId(), $id ? 'department_changed' : 'department_added', null, null, $name);
            $_SESSION['setup_flash'] = ['success', 'Department saved.'];
        }
    } catch (Throwable $e) {
        $_SESSION['setup_flash'] = ['danger', $e->getMessage()];
    }
    header("Location: $go"); exit;
}

$branches = $db->query("SELECT b.*, (SELECT COUNT(*) FROM rooms r WHERE r.branch_id = b.id AND r.is_active = 1) AS rooms,
                               (SELECT COUNT(DISTINCT s.staff_id) FROM practitioner_schedules s WHERE s.branch_id = b.id) AS practitioners
                        FROM branches b ORDER BY b.is_active DESC, b.name")->fetchAll();
$departments = $db->query("SELECT d.*, (SELECT COUNT(*) FROM practitioner_profiles p WHERE p.department_id = d.id) AS people FROM departments d ORDER BY d.is_active DESC, d.name")->fetchAll();
$sequences = $db->query("SELECT n.*, b.name AS branch_name, b.invoice_prefix FROM number_sequences n LEFT JOIN branches b ON b.id = n.branch_id ORDER BY n.year DESC, n.type, b.name")->fetchAll();
$editDept = (int)($_GET['dept'] ?? 0);

$pageTitle    = 'Clinic setup';
$pageSubtitle = count($branches) . ' branch' . (count($branches) == 1 ? '' : 'es') . ' · ' . count($departments) . ' department' . (count($departments) == 1 ? '' : 's');
$pageActions  = '<a href="/pages/practitioners.php" class="btn btn-ghost"><i class="bi bi-heart-pulse"></i>Practitioners</a>';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash[0] ?>"><i class="bi bi-<?= $flash[0] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div>
<?php endif; ?>

<div class="row g-3 page-section">
  <div class="col-xl-8">
    <div class="card-crm mb-3">
      <div class="card-header-crm"><span><i class="bi bi-shop"></i> Branches</span></div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>Branch</th><th>Number prefix</th><th class="text-end">Rooms</th><th class="text-end">Practitioners</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($branches as $b): ?>
            <tr>
              <td><a href="/pages/branch.php?id=<?= (int)$b['id'] ?>" class="fw-semibold"><?= sanitize($b['name']) ?></a>
                <?php if ($b['name_ar']): ?><div class="fs-12 text-tx-3" dir="rtl" lang="ar"><?= sanitize($b['name_ar']) ?></div><?php endif; ?></td>
              <td><code><?= sanitize($b['invoice_prefix']) ?></code></td>
              <td class="text-end"><?= (int)$b['rooms'] ?></td>
              <td class="text-end"><?= (int)$b['practitioners'] ?></td>
              <td><span class="chip <?= $b['is_active'] ? 'aging-fresh' : 'tone-slate' ?>"><?= $b['is_active'] ? 'Open' : 'Closed' ?></span></td>
              <td class="text-end"><a href="/pages/branch.php?id=<?= (int)$b['id'] ?>" class="btn btn-ghost btn-sm"><i class="bi bi-pencil"></i>Edit</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <form method="POST" class="form-actions flex-wrap gap-2">
        <?= csrfField() ?><input type="hidden" name="action" value="branch_add">
        <input class="form-control" style="max-width:260px" name="name" placeholder="New branch name" required aria-label="New branch name">
        <input class="form-control text-uppercase" style="max-width:140px" name="invoice_prefix" placeholder="Prefix, e.g. JVC" pattern="[A-Za-z0-9]{2,6}" required aria-label="Number prefix">
        <button class="btn btn-gold"><i class="bi bi-plus-lg"></i>Add branch</button>
      </form>
    </div>

    <div class="card-crm mb-3">
      <div class="card-header-crm"><span><i class="bi bi-diagram-3"></i> Departments</span><span class="card-header-sub">Dermatology, aesthetics, dental, wellness …</span></div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Name</th><th>Arabic name</th><th class="text-end">Practitioners</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($departments as $d): if ($editDept === (int)$d['id']): ?>
            <tr><td colspan="5">
              <form method="POST" class="d-flex flex-wrap gap-2 align-items-center">
                <?= csrfField() ?><input type="hidden" name="action" value="dept_save"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <input class="form-control" style="max-width:220px" name="name" value="<?= sanitize($d['name']) ?>" required aria-label="Department name">
                <input class="form-control" style="max-width:220px" name="name_ar" value="<?= sanitize($d['name_ar'] ?? '') ?>" dir="rtl" lang="ar" aria-label="Arabic name">
                <label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="is_active" <?= $d['is_active'] ? 'checked' : '' ?>> <span class="form-check-label">Active</span></label>
                <button class="btn btn-gold btn-sm"><i class="bi bi-check-lg"></i>Save</button>
                <a href="/pages/settings_clinic.php" class="btn btn-ghost btn-sm">Cancel</a>
              </form>
            </td></tr>
          <?php else: ?>
            <tr>
              <td class="fw-semibold"><?= sanitize($d['name']) ?></td>
              <td dir="rtl" lang="ar"><?= sanitize($d['name_ar'] ?? '') ?></td>
              <td class="text-end"><?= (int)$d['people'] ?></td>
              <td><span class="chip <?= $d['is_active'] ? 'aging-fresh' : 'tone-slate' ?>"><?= $d['is_active'] ? 'Active' : 'Hidden' ?></span></td>
              <td class="text-end"><a href="?dept=<?= (int)$d['id'] ?>" class="btn btn-ghost btn-sm"><i class="bi bi-pencil"></i>Edit</a></td>
            </tr>
          <?php endif; endforeach; ?>
          <?php if (!$departments): ?><tr><td colspan="5" class="text-tx-3 fs-13">No departments yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <form method="POST" class="form-actions flex-wrap gap-2">
        <?= csrfField() ?><input type="hidden" name="action" value="dept_save">
        <input class="form-control" style="max-width:220px" name="name" placeholder="New department" required aria-label="New department name">
        <input class="form-control" style="max-width:220px" name="name_ar" placeholder="الاسم بالعربية" dir="rtl" lang="ar" aria-label="Arabic name">
        <button class="btn btn-gold"><i class="bi bi-plus-lg"></i>Add department</button>
      </form>
    </div>

    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-123"></i> Document numbering</span></div>
      <div class="card-body-crm">
        <p class="fs-13 text-tx-2 mb-2">Invoices, receipts and credit notes are numbered per branch and year with no gaps, e.g.
          <code><?= sanitize(($branches[0]['invoice_prefix'] ?? 'MAIN')) ?>-INV-<?= date('Y') ?>-000001</code>. Patient file numbers (MRN) are one sequence for the whole clinic.
          The prefix is set on each branch.</p>
        <form method="POST" class="d-flex flex-wrap gap-2 align-items-end mb-3">
          <?= csrfField() ?><input type="hidden" name="action" value="mrn_save">
          <div><label class="form-label fs-13" for="mrnP">Patient file number prefix</label><input class="form-control" id="mrnP" name="mrn_prefix" value="<?= sanitize(tenantSetting('clinic.mrn_prefix', 'MRN-')) ?>" style="max-width:140px"></div>
          <div><label class="form-label fs-13" for="mrnD">Digits</label><input class="form-control" type="number" min="4" max="10" id="mrnD" name="mrn_padding" value="<?= (int)tenantSetting('clinic.mrn_padding', '6') ?>" style="max-width:90px"></div>
          <button class="btn btn-ghost"><i class="bi bi-check-lg"></i>Save</button>
          <span class="fs-12 text-tx-3">e.g. <code><?= sanitize(mrnFormat(123)) ?></code></span>
        </form>
        <?php if (!$sequences): ?><div class="fs-13 text-tx-3">No documents numbered yet.</div><?php else: ?>
        <table class="table table-sm mb-0"><thead><tr><th>Type</th><th>Branch</th><th>Year</th><th class="text-end">Last number</th></tr></thead><tbody>
          <?php foreach ($sequences as $n): ?>
          <tr><td><?= sanitize(ucwords(str_replace('_', ' ', $n['type']))) ?></td><td><?= sanitize($n['branch_name'] ?? 'Whole clinic') ?></td>
              <td><?= $n['year'] ? (int)$n['year'] : '—' ?></td><td class="text-end"><?= (int)$n['last_no'] ?></td></tr>
          <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-sliders"></i> Other settings</span></div>
      <div class="list-group list-group-flush">
        <?php foreach ([
            ['/pages/clinic_profile.php', 'bi-building', 'Clinic profile', 'Name, contact details, brand colour, website pixels', 'setup.clinic'],
            ['/pages/practitioners.php', 'bi-heart-pulse', 'Practitioners', 'Profiles, weekly schedules, leave and breaks', 'setup.clinic'],
            ['/pages/clinical_settings.php', 'bi-clipboard2-pulse', 'Clinical settings', 'Note templates, required sections, auto-lock, ICD-10 codes', 'setup.clinic'],
            ['/pages/layouts.php', 'bi-layout-text-window', 'Document layouts', 'Design how invoices, receipts and credit notes look', 'setup.layouts'],
            ['/pages/messaging_settings.php', 'bi-chat-dots', 'Messaging', 'WhatsApp, SMS, message templates and automatic reminders', 'setup.messaging'],
            ['/pages/insurance.php', 'bi-shield-plus', 'Insurance', 'Insurance companies, plans, their prices and denial codes', 'insurance.view'],
            ['/pages/billing_settings.php', 'bi-percent', 'Payment & tax', 'Prices with or without VAT, discount limits per role, discount offers', 'setup.clinic'],
            ['/pages/packages.php', 'bi-box2-heart', 'Packages', 'Treatment packages: sessions, price and validity', 'billing.packages_manage'],
            ['/pages/consent_templates.php', 'bi-pen', 'Consent forms', 'Consent texts in English and Arabic, and the services that need them', 'setup.clinic'],
            ['/pages/staff.php', 'bi-person-badge', 'Staff', 'Accounts, roles, home branch and feature access', 'staff.manage'],
            ['/pages/email_settings.php', 'bi-envelope-at', 'Email', 'The mailbox the CRM sends from', 'setup.email'],
            ['/pages/webhook.php', 'bi-meta', 'Meta Leads', 'Lead ads in, lead stages out', 'setup.webhook'],
            ['/pages/whatsapp.php', 'bi-whatsapp', 'WhatsApp Capture', 'Chrome extension keys', 'setup.whatsapp'],
            ['/pages/google_calendar.php', 'bi-calendar2-week', 'Google Calendar', 'Practitioners’ own calendars', 'setup.gcal'],
          ] as [$href, $icon, $label, $sub, $perm]): if (!can($perm)) continue; ?>
        <a href="<?= $href ?>" class="list-group-item list-group-item-action d-flex gap-3 align-items-start">
          <i class="bi <?= $icon ?> fs-5 text-gold"></i><span><span class="fw-semibold d-block"><?= $label ?></span><span class="fs-12 text-tx-3"><?= $sub ?></span></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
