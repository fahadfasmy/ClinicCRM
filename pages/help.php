<?php
// pages/help.php — Help: short how-tos for the jobs staff do every day, and the go-live checklist for admins.
// Plain steps, linked to the screens they describe. (Arabic versions follow the same review as the screen texts.)
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

const HELP = [
    'start' => ['Getting started', 'bi-signpost', [
        'Use the menu on the left; you only see what your role allows. The bell at the top shows requests and alerts for you.',
        'Search any lead by name or phone with the box at the top (press / to jump to it).',
        'Switch the screens to Arabic with the ع button at the top; it remembers your choice.',
        'Your password and 2-step sign-in are under My account (the person icon at the bottom of the menu).',
    ]],
    'leads' => ['Leads, follow-ups and the pipeline', 'bi-people', [
        'New enquiries arrive from Meta, WhatsApp and the website on their own; add walk-ins with New lead.',
        'Log each conversation on the lead (Log a follow-up) and set the next follow-up date; Follow-ups lists what is due today.',
        'Marketing → Pipeline shows every lead by stage; drag a card to change its stage.',
        'Convert to patient when someone books their first visit: their phone, details and history carry over.',
    ]],
    'booking' => ['Booking and the visit board', 'bi-calendar3', [
        'Front desk → Scheduler: click a free time in a practitioner’s column, choose the patient and treatment.',
        'On the day, the Visit board shows each patient’s step: arrived, triage, with the doctor, done. Click a card to move it on.',
        'Cancelling or moving a visit frees its time; the patient gets the change by WhatsApp or SMS when messages are switched on.',
        'Waiting list: patients who want an earlier slot. When one frees up, the list suggests who to call.',
    ]],
    'clinical' => ['Clinical notes and consents', 'bi-clipboard2-pulse', [
        'From the appointment, Start clinical note. Fill the sections, add diagnoses (ICD-10), treatments and prescriptions.',
        'Sign the note when done: it locks. Corrections are added as an amendment, never by editing.',
        'Treatments that need a consent ask for it before you start: Hand to patient turns this device into the consent screen.',
        'Photos: add before and after photos on the patient’s Photos tab; Compare shows two side by side.',
    ]],
    'billing' => ['Invoices, payments and closing the day', 'bi-receipt', [
        'Invoice this visit from a completed appointment; check the lines, then Issue invoice (its number is final).',
        'Take payment: one receipt can mix cash, card, transfer, wallet and vouchers, and pay several invoices.',
        'Mistakes after issuing are corrected with a credit note (Issue credit note), never by deleting.',
        'At the end of the day: Billing → Day close. Count the drawer; any difference needs a reason.',
        'Balances carried over from ClinicSoft show as “OB-…” invoices: collect them with Take payment like any other.',
    ]],
    'packages' => ['Packages and vouchers', 'bi-box2-heart', [
        'Sell a package on the patient’s Packages tab. Each booking can use a session; completing the visit uses it up.',
        'Gift vouchers: Billing → Vouchers → sell; the code pays for anything later, up to its value.',
    ]],
    'insurance' => ['Insurance claims', 'bi-file-medical', [
        'Add the patient’s card on their Insurance tab (member number, policy, valid dates, a photo of the card).',
        'Treatments that need approval: request it first (Prior approvals). The invoice splits what the patient and insurer pay.',
        'Claims are made when insurance invoices are issued; Insurance → Claims batches them for the insurer.',
    ]],
    'messages' => ['Messages and reminders', 'bi-chat-dots', [
        'Messages shows WhatsApp and SMS conversations; reply within 24 hours of the patient’s last message.',
        'Automatic reminders, confirmations and feedback are switched on by an admin in Clinic setup → Messaging.',
        'Messages carry only first name, date, time and branch unless the owner allowed health details for a template.',
    ]],
    'reports' => ['Reports', 'bi-bar-chart-line', [
        'Insights → Clinic overview: money in, revenue by doctor and treatment, appointments, no-shows.',
        'Finance reports: daily collection, the VAT return, receivables, packages and vouchers still owed.',
        'Every report has Export (opens in Excel) and Print.',
    ]],
    'privacy' => ['Patient data requests and privacy', 'bi-shield-lock', [
        'A patient asks for their data: patient → Data request → Download (or a printable copy). It is logged.',
        'A patient asks to be removed: Data request → Anonymise. Invoices and clinical records stay, as the law requires.',
        'Admin → Audit log shows who changed what and who opened which chart.',
    ]],
    'golive' => ['Go-live checklist (admins)', 'bi-rocket-takeoff', [
        'Clinic setup: profile and logo, branches with hours, TRN and DHA licence, numbering, VAT setting.',
        'Treatment menu with prices; practitioners with schedules; packages; staff accounts with the right roles.',
        'Messaging: WhatsApp or SMS connected, templates approved, automations switched on; email mailbox set up.',
        'Document layouts: check the invoice and receipt print, in English and Arabic.',
        'Import from ClinicSoft in this order: patients, balances, packages, past visits, invoices, notes, then future appointments (on go-live day). Check each file first; fix what the report lists; run it again.',
        'Scanned documents: ask your administrator to run the document import for the scans folder.',
        'Go-live day: stop booking in ClinicSoft, import future appointments, take a backup, and work only here. Keep ClinicSoft for reading until you are sure, then switch it off.',
        'After a week: compare the day closes and balances with what you expected; record a restore drill (Admin → Data flows & backups).',
    ]],
];

$topic = isset(HELP[$_GET['t'] ?? '']) ? $_GET['t'] : null;
$pageTitle = 'Help';
$pageSubtitle = $topic ? t(HELP[$topic][0]) : t('How to do the everyday jobs');
if ($topic) $pageBack = ['href' => '/pages/help.php', 'label' => 'Help'];
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($topic): [$title, $icon, $steps] = HELP[$topic]; ?>
<div class="card-crm" style="max-width:820px"><div class="card-body-crm"><ol class="mb-0"><?php foreach ($steps as $s): ?><li class="mb-2"><?= sanitize(t($s)) ?></li><?php endforeach; ?></ol></div></div>
<?php else: ?>
<div class="row g-3"><?php foreach (HELP as $k => [$title, $icon]): if ($k === 'golive' && !isAdmin()) continue; ?>
  <div class="col-md-6 col-xl-4"><a class="card-crm d-block h-100 text-decoration-none" href="?t=<?= $k ?>"><div class="card-body-crm d-flex gap-3 align-items-center">
    <i class="bi <?= $icon ?> fs-3 text-gold" aria-hidden="true"></i><span class="fw-600 text-tx-1"><?= sanitize(t($title)) ?></span></div></a></div>
<?php endforeach; ?></div>
<p class="fs-13 text-tx-3 mt-3"><?= t('System status:') ?> <a href="/status.php" target="_blank" rel="noopener">/status.php</a></p>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
