<?php
// includes/permissions.php — what each staff member may do: can('inventory.receive').
// Every feature has role defaults; an admin can switch features on or off for one person
// (Staff → Access), stored as exceptions in staff_permissions. Admins always have everything
// and only admins manage staff, so nobody can lock the clinic out.
require_once __DIR__ . '/db.php';

// Staff roles: key => [label, chip tone, what they do]. The role decides the starting set of features (below).
const ROLES = [
    'admin'        => ['Admin', 'tone-violet', 'Everything, including staff, clinic setup and integrations'],
    'consultant'   => ['Consultant', 'tone-blue', 'Own leads: add, edit, follow up, import; book appointments'],
    'receptionist' => ['Receptionist', 'tone-teal', 'Front desk: own leads, bookings, check-in, payments'],
    'practitioner' => ['Practitioner', 'tone-green', 'Doctors: their own schedule and patients, treatments and the products used'],
    'nurse'        => ['Nurse', 'tone-green', 'Triage and treatments with the doctors; records products used'],
    'finance'      => ['Finance', 'tone-amber', 'Invoices, payments, refunds, day close and finance reports (no clinical notes)'],
    'insurance'    => ['Insurance', 'tone-blue', 'Insurance plans, approvals and claims'],
    'inventory'    => ['Inventory', 'tone-amber', 'Stock team: products, receiving, batches and treatment recipes'],
];

// key => [group, label, explanation, roles that have it by default]
const PERMISSIONS = [
    'leads.all'             => ['Leads', 'All leads, not just their own', 'See and work on every lead, follow-up and reminder, filter by owner, and see whole-team analytics and reports.', ['admin']],
    'leads.delete'          => ['Leads', 'Delete leads', 'Remove a lead and its follow-ups for good.', ['admin']],
    'leads.import'          => ['Leads', 'Bulk upload leads', 'Import leads from a spreadsheet.', ['admin', 'consultant']],
    'marketing.campaigns'   => ['Leads', 'Campaigns and funnel', 'Campaign budgets and the lead → patient → revenue funnel.', ['admin']],
    'marketing.recalls'     => ['Leads', 'Recalls', 'The list of patients due for a visit, and its rules.', ['admin', 'consultant', 'receptionist']],
    'marketing.broadcasts'  => ['Leads', 'Broadcasts', 'Send a message to a group of patients who agreed to marketing.', ['admin']],

    'patients.view'         => ['Patients', 'See patients', 'The patient list and profiles (not clinical notes).', ['admin', 'consultant', 'receptionist', 'practitioner', 'nurse', 'finance', 'insurance']],
    'patients.edit'         => ['Patients', 'Add and edit patients', 'Register patients and change their details.', ['admin', 'consultant', 'receptionist', 'nurse']],
    'patients.merge'        => ['Patients', 'Merge duplicate patients', 'Combine two records of the same person.', ['admin']],
    'patients.export'       => ['Patients', 'Export patients', 'Download the patient list as a spreadsheet.', ['admin']],
    'patients.sensitive'    => ['Patients', 'See Emirates ID and passport in full', 'Otherwise only the last 4 digits show.', ['admin', 'receptionist']],

    'appointments.view'     => ['Appointments', 'See appointments', 'The appointments list and each appointment.', ['admin', 'inventory', 'practitioner', 'nurse', 'consultant', 'receptionist', 'finance', 'insurance']],
    'appointments.book'     => ['Appointments', 'Book and cancel treatments', 'Book treatments for leads, which reserves the products.', ['admin', 'practitioner', 'consultant', 'receptionist']],
    'appointments.complete' => ['Appointments', 'Complete treatments', 'Record the products and batches used; this takes them out of stock.', ['admin', 'practitioner', 'nurse']],
    'scheduler.view'        => ['Appointments', 'See the scheduler', 'The calendar of every practitioner and room (practitioners see their own unless they also have this).', ['admin', 'consultant', 'receptionist', 'practitioner', 'nurse', 'finance', 'insurance']],
    'scheduler.book'        => ['Appointments', 'Book in the scheduler', 'Create, move and cancel appointments in the calendar.', ['admin', 'consultant', 'receptionist']],
    'scheduler.block_slots' => ['Appointments', 'Block time', 'Leave, breaks, meetings and room closures.', ['admin', 'receptionist']],
    'visits.checkin'        => ['Appointments', 'Check patients in', 'Mark arrivals on the visit board.', ['admin', 'receptionist']],
    'visits.triage'         => ['Appointments', 'Triage', 'Take patients from the waiting area and record vitals.', ['admin', 'nurse', 'practitioner']],

    'emr.view'              => ['Clinical records', 'See clinical notes', 'Their own patients’ notes, allergies and history.', ['admin', 'practitioner', 'nurse']],
    'emr.all'               => ['Clinical records', 'See every patient’s chart', 'Not just the patients they treat.', ['admin']],
    'emr.write'             => ['Clinical records', 'Write clinical notes', 'Vitals, notes, procedures.', ['practitioner', 'nurse']],
    'emr.sign'              => ['Clinical records', 'Sign notes', 'Signed notes are locked.', ['practitioner']],
    'emr.amend'             => ['Clinical records', 'Add addenda', 'Correct a signed note with a dated addendum.', ['practitioner']],
    'emr.prescribe'         => ['Clinical records', 'Prescribe', 'Write and print prescriptions.', ['practitioner']],
    'emr.photos'            => ['Clinical records', 'Clinical photos', 'Take, view and compare before/after photos.', ['practitioner', 'nurse']],
    'consents.collect'      => ['Clinical records', 'Collect consents', 'Hand a tablet to the patient to sign a consent form.', ['admin', 'receptionist', 'nurse', 'practitioner']],

    'billing.view'          => ['Billing', 'See invoices and payments', 'Invoices, receipts and balances.', ['admin', 'finance', 'receptionist']],
    'billing.invoice'       => ['Billing', 'Invoice and take payments', 'Issue invoices and receipts.', ['admin', 'finance', 'receptionist']],
    'billing.discount'      => ['Billing', 'Discounts above the role limit', 'Without this, discounts stop at the limit set for the role.', ['admin']],
    'billing.void'          => ['Billing', 'Void invoices and receipts', 'Cancel an issued document (with a reason).', ['admin', 'finance']],
    'billing.refund'        => ['Billing', 'Refunds and credit notes', 'Give money back against an invoice.', ['admin', 'finance']],
    'billing.day_close'     => ['Billing', 'Day close', 'Count the drawer and close the day.', ['admin', 'finance', 'receptionist']],
    'billing.packages_manage' => ['Billing', 'Manage packages', 'Create and price treatment packages.', ['admin']],

    'insurance.view'        => ['Insurance', 'See insurance', 'Plans, approvals and claims.', ['admin', 'insurance', 'finance']],
    'insurance.manage'      => ['Insurance', 'Manage insurance', 'Insurance companies, plans and approvals.', ['admin', 'insurance']],
    'insurance.claims'      => ['Insurance', 'Claims', 'Create, submit and follow up claims.', ['admin', 'insurance']],

    'inventory.view'        => ['Inventory', 'See stock and the treatment menu', 'Stock levels, products, batches and the treatment menu.', ['admin', 'inventory', 'practitioner', 'nurse', 'consultant', 'receptionist', 'finance']],
    'inventory.manage'      => ['Inventory', 'Manage products and treatments', 'Add, edit, hide and delete products and treatments, their recipes and prices; spreadsheet imports.', ['admin', 'inventory']],
    'inventory.receive'     => ['Inventory', 'Receive stock', 'Book in deliveries with batch and expiry.', ['admin', 'inventory']],
    'inventory.waste'       => ['Inventory', 'Record wastage', 'Write off broken, expired or spoiled stock.', ['admin', 'inventory', 'practitioner', 'nurse']],
    'inventory.count'       => ['Inventory', 'Do stock counts', 'Count the shelves and submit the differences.', ['admin', 'inventory']],
    'inventory.approve'     => ['Inventory', 'Approve stock counts', 'Approve large or prescription-only count differences (never their own count).', ['admin']],
    'inventory.adjust'      => ['Inventory', 'Correct a batch directly', 'Change a batch’s quantity outside a count.', ['admin']],
    'inventory.reports'     => ['Inventory', 'Stock reports', 'Usage, expiry, wastage and valuation reports.', ['admin', 'inventory', 'finance']],
    'reports.margin'        => ['Inventory', 'Treatment margin report', 'Price against product cost for each treatment.', ['admin', 'finance']],
    'purchasing.manage'     => ['Inventory', 'Purchasing', 'Suppliers and purchase orders.', ['admin', 'inventory']],

    'reports.clinical'      => ['Reports', 'Clinical reports', 'Practitioners see their own figures only.', ['admin', 'practitioner']],
    'reports.finance'       => ['Reports', 'Finance reports', 'Revenue, VAT, payments and balances.', ['admin', 'finance']],
    'reports.dha'           => ['Reports', 'DHA statistics', 'The counts DHA asks for.', ['admin']],
    'reports.commission'    => ['Reports', 'Commission report', 'What each practitioner earned.', ['admin', 'finance']],

    'setup.clinic'          => ['Settings', 'Clinic setup', 'Clinic profile, branches, rooms, departments, practitioners and their schedules, numbering.', ['admin']],
    'setup.layouts'         => ['Settings', 'Document layouts', 'Design how invoices, receipts and prescriptions look.', ['admin']],
    'setup.messaging'       => ['Settings', 'Messaging set-up', 'Message templates, automations, and the WhatsApp and SMS connection.', ['admin']],
    'messages.view'         => ['Messages', 'See patient messages', 'The WhatsApp and SMS inbox and each patient’s message history.', ['admin', 'receptionist', 'consultant']],
    'messages.send'         => ['Messages', 'Reply to patients', 'Send WhatsApp and SMS replies from the inbox.', ['admin', 'receptionist']],
    'setup.treatments'      => ['Settings', 'Edit interest tags', 'The Treatments list leads can be interested in.', ['admin']],
    'setup.sources'         => ['Settings', 'Edit lead sources', 'Add, rename and reorder lead sources.', ['admin']],
    'setup.whatsapp'        => ['Settings', 'WhatsApp Capture settings', 'Create and revoke the extension’s API keys.', ['admin']],
    'setup.webhook'         => ['Settings', 'Meta Leads settings', 'Connect Facebook and Instagram lead ads, import Meta leads and send lead stages to Meta.', ['admin']],
    'setup.gcal'            => ['Settings', 'Google Calendar app', 'The Google app details staff use to connect their calendars, and who is connected. Everyone can connect their own calendar without this.', ['admin']],
    'setup.email'           => ['Settings', 'Email settings', 'The clinic mailbox the CRM sends email from, including its password, and the log of sent emails.', ['admin']],
    'audit.view'            => ['Settings', 'Audit log', 'Who did what, and who opened which chart.', ['admin']],
    'data.import'           => ['Settings', 'Import from ClinicSoft', 'Bring patients, balances, packages, visits, invoices and notes across from ClinicSoft.', ['admin']],
    'data.flows.view'       => ['Settings', 'Data flows & backups', 'Where data leaves the clinic, the UAE residency check, backups and restore drills.', ['admin']],
    'patients.privacy'      => ['Patients', 'Patient data requests', 'Export a patient’s data for them, or anonymise their record on request (invoices and clinical notes are kept).', ['admin']],

    'staff.manage'          => [null, 'Manage staff', 'Admins only; can’t be given to other roles.', ['admin']],
];

// Features that only work with another one switched on (those pages check both)
const PERMISSION_REQUIRES = [
    'appointments.book' => 'appointments.view', 'appointments.complete' => 'appointments.view',
    'inventory.manage' => 'inventory.view', 'inventory.receive' => 'inventory.view', 'inventory.waste' => 'inventory.view',
    'inventory.count' => 'inventory.view', 'inventory.approve' => 'inventory.view', 'inventory.adjust' => 'inventory.view',
    'inventory.reports' => 'inventory.view', 'purchasing.manage' => 'inventory.view', 'reports.margin' => 'inventory.reports',
    'patients.edit' => 'patients.view', 'patients.merge' => 'patients.edit', 'patients.export' => 'patients.view', 'patients.privacy' => 'patients.view', 'patients.sensitive' => 'patients.view',
    'scheduler.book' => 'scheduler.view', 'scheduler.block_slots' => 'scheduler.view',
    'emr.all' => 'emr.view', 'emr.write' => 'emr.view', 'emr.sign' => 'emr.write', 'emr.amend' => 'emr.sign', 'emr.prescribe' => 'emr.write', 'emr.photos' => 'emr.view',
    'billing.invoice' => 'billing.view', 'billing.discount' => 'billing.invoice', 'billing.void' => 'billing.view', 'billing.refund' => 'billing.view', 'billing.day_close' => 'billing.view',
    'insurance.manage' => 'insurance.view', 'insurance.claims' => 'insurance.view', 'messages.send' => 'messages.view',
];

/** This person's exceptions to their role defaults: [permission => true|false]. Read fresh on each request. */
function staffPermissionOverrides(int $staffId, bool $refresh = false): array {
    static $cache = [];
    if (!$staffId) return [];
    if (!$refresh && isset($cache[$staffId])) return $cache[$staffId];
    $db = getDB();
    try {
        $s = $db->prepare("SELECT permission, allowed FROM staff_permissions WHERE staff_id = ?");
        $s->execute([$staffId]);
        $rows = $s->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') throw $e;   // table not created yet
        ensurePermissionSchema($db);
        $rows = [];
    }
    return $cache[$staffId] = array_map(fn($v) => (bool)$v, $rows);
}

function ensurePermissionSchema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS staff_permissions (
        staff_id INT UNSIGNED NOT NULL,
        permission VARCHAR(40) NOT NULL,
        allowed TINYINT(1) NOT NULL,
        updated_by INT UNSIGNED NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (staff_id, permission)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Whether a role has a feature before any per-person changes. */
function roleCan(string $role, string $permission): bool {
    return in_array($role, PERMISSIONS[$permission][3] ?? [], true);
}

/** Whether a given staff member (by role and id) has a feature. */
function staffCan(string $role, int $staffId, string $permission): bool {
    if (!isset(PERMISSIONS[$permission])) return false;
    if ($role === 'admin') return true;
    if (PERMISSIONS[$permission][0] === null) return false;          // admin-only, not grantable
    return staffPermissionOverrides($staffId)[$permission] ?? roleCan($role, $permission);
}

function can(string $permission): bool {
    // A module the clinic's plan doesn't include is off for everyone (menus and pages follow, see includes/plans.php)
    if (function_exists('planFeature') && isset(PERMISSION_FEATURES[$permission]) && !planFeature(PERMISSION_FEATURES[$permission])) return false;
    return staffCan($_SESSION['role'] ?? '', (int)($_SESSION['staff_id'] ?? 0), $permission);
}

function requirePermission(string $permission): void {
    requireLogin();
    if (!can($permission)) {
        http_response_code(403);
        die('<h2>Access Denied</h2><p>You do not have permission to do this. Ask an admin if you need it.</p>');
    }
}
