<?php
// pages/clinic_profile.php — Admin → Clinic profile: the clinic's name, contact details, brand colour and website pixels.
// Everything that used to be written into the code for one clinic lives here (integration_settings: brand.*, clinic.*,
// meta.web_pixels), so every clinic on the platform shows its own details. Read with tenantSetting().
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventory.php';     // CSRF helpers
require_once __DIR__ . '/../includes/mailer.php';        // ensureMailSchema() creates integration_settings
require_once __DIR__ . '/../includes/meta_leads.php';    // metaWebPixels()
requirePermission('setup.clinic');

$db = getDB();
ensureMailSchema($db);
$flash = $_SESSION['profile_flash'] ?? null;
unset($_SESSION['profile_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $one = fn(string $k, int $max) => mb_substr(trim(str_replace(["\r", "\n"], ' ', (string)($_POST[$k] ?? ''))), 0, $max);
    try {
        $v = [
            'brand.name'     => $one('name', 100),
            'clinic.email'   => $one('email', 190),
            'clinic.phone'   => $one('phone', 40),
            'clinic.whatsapp'=> $one('whatsapp', 40),
            'clinic.address' => $one('address', 255),
            'clinic.logo_url'=> $one('logo_url', 500),
            'brand.primary_color' => isset($_POST['custom_color']) ? strtolower($one('primary_color', 7)) : '',
        ];
        if ($v['brand.name'] === '') throw new RuntimeException('Enter the clinic’s name.');
        if ($v['clinic.email'] !== '' && !filter_var($v['clinic.email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid clinic email, or leave it empty.');
        foreach (['clinic.phone' => 'phone number', 'clinic.whatsapp' => 'WhatsApp number'] as $k => $what)
            if ($v[$k] !== '' && !preg_match('/^\+?[\d\s()-]{7,}$/', $v[$k])) throw new RuntimeException("Enter the $what with digits only, e.g. +971 4 123 4567.");
        if ($v['clinic.logo_url'] !== '' && !preg_match('#^https://[^\s"<>]+$#', $v['clinic.logo_url'])) throw new RuntimeException('The logo address must start with https://.');
        if ($v['brand.primary_color'] !== '' && !preg_match('/^#[0-9a-f]{6}$/', $v['brand.primary_color'])) throw new RuntimeException('Choose a brand colour.');

        // Website pixels: one "page = pixel id" per line (the landing page name its enquiry form sends)
        $pixels = [];
        foreach (preg_split('/\R/', (string)($_POST['pixels'] ?? '')) as $n => $line) {
            if (trim($line) === '') continue;
            if (!preg_match('/^\s*([a-z0-9_-]{1,40})\s*=\s*(\d{6,20})\s*$/i', $line, $m)) throw new RuntimeException('Website pixels, line ' . ($n + 1) . ': write it as page = pixel id, e.g. hydrafacial = 1234567890.');
            $pixels[strtolower($m[1])] = $m[2];
        }
        $v['meta.web_pixels'] = $pixels ? json_encode($pixels) : '';

        $db->beginTransaction();
        $s = $db->prepare("REPLACE INTO integration_settings (k, v) VALUES (?, ?)");
        foreach ($v as $k => $val) $s->execute([$k, $val]);
        $db->commit();
        tenantSetting('__reload');
        logActivity(currentStaffId(), 'clinic_profile_changed', null, null, $v['brand.name']);
        $_SESSION['profile_flash'] = ['type' => 'success', 'msg' => 'Saved.'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['profile_flash'] = ['type' => 'danger', 'msg' => $e->getMessage()];
    }
    header('Location: /pages/clinic_profile.php'); exit;
}

$t = fn(string $k) => sanitize(tenantSetting($k));
$color = tenantSetting('brand.primary_color');
$pixelLines = implode("\n", array_map(fn($p, $id) => "$p = $id", array_keys(metaWebPixels()), metaWebPixels()));
$missing = array_keys(array_filter(['name' => 'brand.name', 'phone' => 'clinic.phone', 'WhatsApp' => 'clinic.whatsapp', 'address' => 'clinic.address'], fn($k) => tenantSetting($k) === ''));

$pageTitle    = 'Clinic profile';
$pageSubtitle = 'Your clinic’s name and details, shown on screens and in emails';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] ?>"><i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i><div><strong><?= sanitize($flash['msg']) ?></strong></div></div>
<?php endif; ?>
<?php if ($missing): ?>
<div class="alert alert-warning"><i class="bi bi-info-circle"></i><div>The call-back email to new leads is only sent once the clinic’s <?= sanitize(implode(', ', $missing)) ?> <?= count($missing) > 1 ? 'are' : 'is' ?> filled in.</div></div>
<?php endif; ?>

<form method="POST" class="row g-3 page-section" autocomplete="off">
  <?= csrfField() ?>
  <div class="col-xl-7">
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-building"></i> Clinic details</span></div>
      <div class="card-body-crm">
        <label class="form-label" for="pName">Clinic name</label>
        <input class="form-control mb-3" id="pName" name="name" value="<?= $t('brand.name') ?>" maxlength="100" required>
        <div class="row g-2 mb-3">
          <div class="col-md-6"><label class="form-label" for="pPhone">Phone <span class="text-tx-3 fw-normal">(calls to leads come from this)</span></label>
            <input class="form-control" id="pPhone" name="phone" value="<?= $t('clinic.phone') ?>" placeholder="+971 4 123 4567"></div>
          <div class="col-md-6"><label class="form-label" for="pWa">WhatsApp number</label>
            <input class="form-control" id="pWa" name="whatsapp" value="<?= $t('clinic.whatsapp') ?>" placeholder="+971 50 123 4567"></div>
        </div>
        <label class="form-label" for="pEmail">Clinic email <span class="text-tx-3 fw-normal">(used when the Email page leaves the sender blank)</span></label>
        <input class="form-control mb-3" id="pEmail" name="email" type="email" value="<?= $t('clinic.email') ?>">
        <label class="form-label" for="pAddr">Address</label>
        <input class="form-control mb-3" id="pAddr" name="address" value="<?= $t('clinic.address') ?>" maxlength="255">
        <label class="form-label" for="pLogo">Logo for emails <span class="text-tx-3 fw-normal">(web address of a square image, https://…)</span></label>
        <input class="form-control" id="pLogo" name="logo_url" value="<?= $t('clinic.logo_url') ?>" placeholder="https://…">
      </div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card-crm mb-3">
      <div class="card-header-crm"><span><i class="bi bi-palette"></i> Brand colour</span></div>
      <div class="card-body-crm">
        <label class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" name="custom_color" id="pCustom" <?= $color ? 'checked' : '' ?>>
          <span class="form-check-label">Use our own colour instead of the standard gold</span></label>
        <input class="form-control form-control-color" type="color" name="primary_color" value="<?= sanitize($color ?: '#b8925a') ?>" aria-label="Brand colour">
      </div>
    </div>
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-meta"></i> Website pixels</span></div>
      <div class="card-body-crm">
        <p class="fs-13 text-tx-2">Landing pages with an enquiry form also report each enquiry to their Meta pixel. One per line: <code>page = pixel id</code>.</p>
        <textarea class="form-control font-monospace" name="pixels" rows="3" placeholder="hydrafacial = 1234567890"><?= sanitize($pixelLines) ?></textarea>
      </div>
      <div class="form-actions"><button class="btn btn-primary ms-auto"><i class="bi bi-check2"></i>Save</button></div>
    </div>
  </div>
</form>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
