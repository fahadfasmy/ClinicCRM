<?php
// includes/header.php
// Usage: set $pageTitle before including. Optional:
//   $pageSubtitle — one line under the title
//   $pageActions  — HTML for buttons on the right of the page header
//   $pageBack     — ['href' => ..., 'label' => ...] small back link above the title
//   $hidePageHead — true to render your own page header
require_once __DIR__ . '/inventory.php';
if (!isset($pageTitle)) $pageTitle = 'Clinic CRM';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
sendStaffPageHeaders();

$navGroups = [
    'Workspace' => [
        ['dashboard', 'Dashboard',  'bi-grid-1x2',        ['dashboard']],
        ['leads',     'Leads',      'bi-people',          ['leads','lead_view','lead_add','lead_edit'], 'nav-hot-count', ''],
        ['followups', 'Follow-ups', 'bi-chat-left-text',  ['followups'], 'nav-due-count', 'danger'],
        ['reminders', 'Reminders',  'bi-bell',            ['reminders']],
        ['inbox', 'Messages', 'bi-chat-dots', ['inbox'], null, null, 'messages.view'],
        ['google_calendar', 'Google Calendar', 'bi-calendar2-week', ['google_calendar']],
        ['help', 'Help', 'bi-question-circle', ['help']],
    ],
    'Marketing' => [
        ['pipeline', 'Pipeline', 'bi-kanban', ['pipeline']],
        ['campaigns', 'Campaigns', 'bi-graph-up-arrow', ['campaigns'], null, null, 'marketing.campaigns'],
        ['recalls', 'Recalls', 'bi-arrow-repeat', ['recalls'], null, null, 'marketing.recalls'],
        ['broadcasts', 'Broadcasts', 'bi-megaphone', ['broadcasts'], null, null, 'marketing.broadcasts'],
    ],
    'Front desk' => [
        ['scheduler', 'Scheduler', 'bi-calendar3', ['scheduler','waiting_list'], null, null, 'scheduler.view'],
        ['visit_board', 'Visit board', 'bi-kanban', ['visit_board'], null, null, 'scheduler.view'],
        ['patients', 'Patients', 'bi-person-vcard', ['patients','patient','patient_edit','patient_merge','patient_backfill'], null, null, 'patients.view'],
    ],
    'Clinical' => [
        ['notes', 'Clinical notes', 'bi-clipboard2-pulse', ['notes','encounter'], null, null, 'emr.view'],
    ],
    'Billing' => [
        ['invoices', 'Invoices', 'bi-receipt', ['invoices','invoice','receipt_new'], null, null, 'billing.view'],
        ['receipts', 'Receipts', 'bi-cash-coin', ['receipts','receipt'], null, null, 'billing.view'],
        ['day_close', 'Day close', 'bi-safe', ['day_close'], null, null, 'billing.day_close'],
        ['packages', 'Packages', 'bi-box2-heart', ['packages'], null, null, 'billing.view'],
        ['vouchers', 'Vouchers', 'bi-gift', ['vouchers'], null, null, 'billing.view'],
    ],
    'Insurance' => [
        ['claims', 'Claims', 'bi-file-medical', ['claims','claim'], null, null, 'insurance.view'],
        ['prior_auths', 'Prior approvals', 'bi-shield-check', ['prior_auths'], null, null, 'insurance.view'],
        ['remittances', 'Remittances', 'bi-bank', ['remittances'], null, null, 'insurance.view'],
    ],
    'Clinic' => [
        ['appointments', 'Appointments',   'bi-calendar-check', ['appointments','appointment'], 'nav-appt-count', '', 'appointments.view'],
        ['inventory',    'Inventory',      'bi-box-seam',       ['inventory','product','stock_receive','wastage','stock_counts','recall','stock_reports','purchasing','purchase_order','stock_import'], 'nav-inv-count', 'danger', 'inventory.view'],
        ['services',     'Treatment menu', 'bi-list-check',     ['services'], null, null, 'inventory.view'],
    ],
    'Insights' => [
        ['analytics', 'Analytics',  'bi-bar-chart-line',  ['analytics']],
        ['overview',  'Clinic overview', 'bi-speedometer2', ['overview'], null, null, 'reports.finance'],
        ['reports',   'Lead reports', 'bi-file-earmark-text', ['reports']],
        ['reports_finance', 'Finance reports', 'bi-cash-coin', ['reports_finance'], null, null, 'reports.finance'],
        ['reports_clinical', 'Clinical reports', 'bi-heart-pulse', ['reports_clinical'], null, null, 'reports.clinical'],
        ['reports_dha', 'DHA statistics', 'bi-clipboard-data', ['reports_dha'], null, null, 'reports.dha'],
        ['reports_commission', 'Commission', 'bi-percent', ['reports_commission'], null, null, 'reports.commission'],
    ],
    'Setup' => [
        ['treatments',  'Treatments',   'bi-clipboard2-pulse', ['treatments']],
        ['sources',     'Lead Sources', 'bi-signpost-split',   ['sources'],     null, null, 'setup.sources'],
        ['bulk_upload', 'Bulk Upload',  'bi-cloud-arrow-up',   ['bulk_upload'], null, null, 'leads.import'],
        ['whatsapp',    'WhatsApp Capture', 'bi-whatsapp',     ['whatsapp'],    null, null, 'setup.whatsapp'],
    ],
    'Admin' => [
        ['staff',   'Staff',        'bi-person-badge', ['staff','staff_access'],   null, null, 'staff.manage'],
        ['webhook', 'Meta Leads',   'bi-meta',         ['webhook'], null, null, 'setup.webhook'],
        ['settings_clinic', 'Clinic setup', 'bi-building', ['settings_clinic','branch','clinic_profile','layouts','layout_designer','layout_versions','billing_settings','insurance','messaging_settings','message_templates','consent_templates','clinical_settings'], null, null, 'setup.clinic'],
        ['practitioners', 'Practitioners', 'bi-heart-pulse', ['practitioners'], null, null, 'setup.clinic'],
        ['email_settings', 'Email', 'bi-envelope-at',  ['email_settings'], null, null, 'setup.email'],
        ['audit',   'Audit log',    'bi-journal-check', ['audit', 'access_log'], null, null, 'audit.view'],
        ['data_flows', 'Data flows & backups', 'bi-shield-lock', ['data_flows'], null, null, 'data.flows.view'],
        ['import',  'Import from ClinicSoft', 'bi-box-arrow-in-down', ['import'], null, null, 'data.import'],
        ['subscription', 'Subscription', 'bi-credit-card-2-front', ['subscription', 'export_all', 'onboarding'], null, null, 'staff.manage'],
        ['support_access', 'Support access', 'bi-life-preserver', ['support_access'], null, null, 'staff.manage'],
    ],
];
?>
<!DOCTYPE html>
<html lang="<?= uiLang() ?>" dir="<?= uiDir() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= csrfToken() ?>">
<title><?= sanitize(t($pageTitle)) ?> · Clinic CRM</title>
<?php include __DIR__ . '/theme_head.php'; ?>
<link rel="stylesheet" href="/assets/vendor/bootstrap-5.3.2/bootstrap<?= uiDir() === 'rtl' ? '.rtl' : '' ?>.min.css">
<link rel="stylesheet" href="/assets/vendor/bootstrap-icons-1.11.3/bootstrap-icons.min.css">
<link rel="stylesheet" href="/assets/vendor/datatables-1.13.8/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="/assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="/assets/css/app.css?v=10">
<script>window.UI_LANG = <?= json_encode(uiLang()) ?>; window.UI_T = <?= json_encode((object)uiJsStrings(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<?= brandStyleTag() ?>
</head>
<body>

<div class="app">

  <!-- Sidebar -->
  <nav class="sidebar" id="sidebar" aria-label="<?= t('Main navigation') ?>">
    <a class="sidebar-brand" href="/pages/dashboard.php">
      <span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span>
      <span>
        <span class="brand-name d-block">Clinic CRM</span>
        <span class="brand-sub"><?= sanitize(clinicName()) ?></span>
      </span>
    </a>

    <div class="sidebar-scroll">
      <?php foreach ($navGroups as $_navGroup => $_navItems):
        $_navItems = array_filter($_navItems, fn($it) => empty($it[6]) || can($it[6]));
        if (!$_navItems) continue; ?>
      <div class="nav-group">
        <div class="nav-group-label"><?= t($_navGroup) ?></div>
        <?php foreach ($_navItems as $_nav):
          $_navActive = in_array($currentPage, $_nav[3], true); ?>
        <a class="side-link <?= $_navActive ? 'active' : '' ?>" href="/pages/<?= $_nav[0] ?>.php" <?= $_navActive ? 'aria-current="page"' : '' ?>>
          <i class="bi <?= $_nav[2] ?>"></i><span><?= t($_nav[1]) ?></span>
          <?php if (!empty($_nav[4])): ?><span class="nav-badge <?= $_nav[5] ?? '' ?>" id="<?= $_nav[4] ?>"></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endforeach; unset($_navGroup, $_navItems, $_nav, $_navActive); ?>
    </div>

    <div class="sidebar-footer">
      <span class="avatar"><?= strtoupper(substr(staffName(), 0, 1)) ?></span>
      <div class="staff-details">
        <span class="staff-name"><?= sanitize(staffName()) ?></span>
        <span class="staff-role"><?= t(ROLES[currentRole()][0] ?? ucfirst(currentRole())) ?></span>
      </div>
      <a href="/pages/account.php" class="btn-logout" title="<?= t('My account: password, 2-step sign-in') ?>" aria-label="<?= t('My account') ?>"><i class="bi bi-person-gear"></i></a>
      <a href="/logout.php" class="btn-logout" title="<?= t('Sign out') ?>" aria-label="<?= t('Sign out') ?>"><i class="bi bi-box-arrow-right"></i></a>
    </div>
  </nav>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <!-- Main -->
  <div class="main-content">
    <header class="topbar">
      <button class="btn-sidebar-toggle" id="sidebarToggle" aria-label="<?= t('Open menu') ?>"><i class="bi bi-list"></i></button>

      <form class="topbar-search" action="/pages/leads.php" method="get" role="search">
        <i class="bi bi-search"></i>
        <input type="search" name="q" id="globalSearch" placeholder="<?= t('Search leads by name, phone or email') ?>"
               value="<?= $currentPage === 'leads' ? sanitize($_GET['q'] ?? '') : '' ?>" autocomplete="off">
        <kbd>/</kbd>
      </form>

      <div class="topbar-right">
        <a href="/pages/leads.php?aging=critical" id="aging-alert" class="topbar-alert d-none" title="<?= t('Leads not contacted in 7+ days') ?>">
          <i class="bi bi-exclamation-circle-fill"></i>
          <span id="aging-alert-count"></span><span class="topbar-alert-label">&nbsp;<?= t('need attention') ?></span>
        </a>
        <span class="topbar-date"><?= uiDate(date('Y-m-d'), true) ?></span>
        <?php require_once __DIR__ . '/compliance.php'; $_bell = notifCount(getDB()); ?>
        <a href="/pages/notifications.php" class="btn-icon topbar-icon position-relative" title="<?= t('Notifications') ?>" aria-label="<?= t('Notifications') ?><?= $_bell ? ', ' . t('{n} new', ['n' => $_bell]) : '' ?>">
          <i class="bi bi-bell"></i><?php if ($_bell): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:10px" aria-hidden="true"><?= $_bell > 99 ? '99+' : $_bell ?></span><?php endif; ?>
        </a>
        <div class="dropdown theme-toggle">
          <button type="button" class="btn-icon topbar-icon" data-bs-toggle="dropdown" aria-expanded="false" title="<?= t('Day / night theme') ?>" aria-label="<?= t('Choose theme') ?>">
            <i class="bi bi-moon-stars"></i><i class="bi bi-sun"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><h6 class="dropdown-header"><?= t('Theme') ?></h6></li>
            <li><button type="button" class="dropdown-item" data-theme-set="light"><i class="bi bi-sun me-2"></i><?= t('Day') ?></button></li>
            <li><button type="button" class="dropdown-item" data-theme-set="dark"><i class="bi bi-moon-stars me-2"></i><?= t('Night') ?></button></li>
            <li><button type="button" class="dropdown-item" data-theme-set="auto"><i class="bi bi-circle-half me-2"></i><?= t('Match computer') ?></button></li>
          </ul>
        </div>
        <form method="POST" action="/pages/ui_lang.php" class="d-inline"><input type="hidden" name="_csrf" value="<?= csrfToken() ?>"><input type="hidden" name="back" value="<?= sanitize($_SERVER['REQUEST_URI'] ?? '') ?>">
          <button class="btn-icon topbar-icon fw-600" name="lang" value="<?= uiLang() === 'ar' ? 'en' : 'ar' ?>" lang="<?= uiLang() === 'ar' ? 'en' : 'ar' ?>" title="<?= uiLang() === 'ar' ? 'English' : 'العربية' ?>" aria-label="<?= uiLang() === 'ar' ? 'Switch to English' : 'التبديل إلى العربية' ?>" style="font-size:13px"><?= uiLang() === 'ar' ? 'EN' : 'ع' ?></button></form>
        <a href="/pages/lead_add.php" class="btn btn-gold btn-sm"><i class="bi bi-plus-lg"></i><span class="topbar-new-label"><?= t('New lead') ?></span></a>
      </div>
    </header>

    <?= tenantBanner() ?>
    <main class="page-body">
    <?php if (empty($hidePageHead)): ?>
      <div class="page-head">
        <div class="min-w-0">
          <?php if (!empty($pageBack)): ?>
          <a class="page-head-back" href="<?= sanitize($pageBack['href']) ?>"><i class="bi bi-arrow-left"></i> <?= sanitize(t($pageBack['label'])) ?></a>
          <?php endif; ?>
          <h1 class="page-title"><?= sanitize(t($pageTitle)) ?></h1>
          <?php if (!empty($pageSubtitle)): ?><p class="page-subtitle"><?= $pageSubtitle ?></p><?php endif; ?>
        </div>
        <?php if (!empty($pageActions)): ?><div class="page-actions"><?= $pageActions ?></div><?php endif; ?>
      </div>
    <?php endif; ?>
