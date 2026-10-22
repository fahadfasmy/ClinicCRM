<?php
// includes/plans.php — what a clinic's subscription allows, enforced on the server in one place:
//   planLimit('branches') / planCheck($db, 'branches')  — countable things (branches, practitioners, staff seats, storage,
//                                                         messages a month, custom layouts); over the limit = an upgrade
//                                                         message, never silent loss
//   planFeature('insurance')                            — modules a plan includes (also hides their permissions, see can())
//   tenantWritable() / tenantGuard()                    — the central read-only guard (trial over, unpaid, cancelled,
//                                                         suspended, or a read-only support session)
// Single-clinic mode (today's Hostinger site) and clinics with no plan (the first clinic, comp accounts) have no limits.
require_once __DIR__ . '/tenant.php';

class PlanLimitException extends RuntimeException {}

const PLAN_LIMIT_LABELS = ['branches' => ['branch', 'branches'], 'practitioners' => ['practitioner', 'practitioners'], 'staff_seats' => ['staff seat', 'staff seats'],
    'storage_gb' => ['GB of storage', 'GB of storage'], 'messages_per_month' => ['message a month', 'messages a month'], 'layouts' => ['custom document layout', 'custom document layouts']];
const PLAN_FEATURE_LABELS = ['insurance' => 'Insurance claims', 'broadcasts' => 'Broadcasts', 'automations' => 'Automatic messages', 'online_booking' => 'Online booking',
    'custom_domain' => 'Custom domain', 'priority_support' => 'Priority support'];
const TENANT_STATUS_LABELS = ['trial' => 'Free trial', 'active' => 'Active', 'past_due' => 'Payment overdue', 'read_only' => 'Read-only', 'suspended' => 'Suspended',
    'cancelled' => 'Cancelled', 'archived' => 'Archived'];
/** Permissions that belong to a plan feature: without the feature nobody has them (menus and pages follow). */
const PERMISSION_FEATURES = ['insurance.view' => 'insurance', 'insurance.manage' => 'insurance', 'insurance.claims' => 'insurance', 'marketing.broadcasts' => 'broadcasts'];
/** Pages that still work when a clinic is read-only (billing, export, sign-out, own security). */
const READ_ONLY_ALLOWED = ['subscription.php', 'export_all.php', 'logout.php', 'ui_lang.php', 'account.php', 'login.php'];

/** The clinic's platform record (status, plan, trial end…), once per request. */
function tenantRecord(): array {
    static $cache = [];
    if (isset($GLOBALS['__test_tenant_record'])) return $GLOBALS['__test_tenant_record'];   // unit tests
    if (!multiTenant()) return ['status' => 'active', 'plan_id' => null, 'trial_ends_at' => null, 'read_only_since' => null, 'cancelled_at' => null];
    $id = currentTenant()['id'];
    if (!isset($cache[$id]) || !empty($GLOBALS['__tenant_record_reload'])) {
        unset($GLOBALS['__tenant_record_reload']);
        $s = platformDB()->prepare("SELECT status, plan_id, trial_ends_at, read_only_since, cancelled_at FROM tenants WHERE id = ?");
        $s->execute([$id]);
        $cache[$id] = $s->fetch() ?: ['status' => 'active', 'plan_id' => null, 'trial_ends_at' => null, 'read_only_since' => null, 'cancelled_at' => null];
    }
    return $cache[$id];
}

/** The clinic's plan with limits and features decoded, or null (no plan = no limits). */
function tenantPlan(): ?array {
    static $cache = [];
    if (array_key_exists('__test_plan', $GLOBALS)) return $GLOBALS['__test_plan'];   // unit tests
    $pid = tenantRecord()['plan_id'];
    if (!$pid) return null;
    if (!isset($cache[$pid])) {
        $s = platformDB()->prepare("SELECT * FROM plans WHERE id = ?");
        $s->execute([(int)$pid]);
        $p = $s->fetch();
        if ($p) { $p['limits'] = json_decode($p['limits'], true) ?: []; $p['features'] = json_decode($p['features'], true) ?: []; }
        $cache[$pid] = $p ?: null;
    }
    return $cache[$pid];
}

/** The limit for a countable thing, or null for unlimited. */
function planLimit(string $key): ?int {
    $p = tenantPlan();
    if (!$p || !array_key_exists($key, $p['limits']) || $p['limits'][$key] === null) return null;
    return (int)$p['limits'][$key];
}

function planFeature(string $feature): bool {
    $p = tenantPlan();
    return !$p || !empty($p['features'][$feature]);
}

/** How many of a countable thing the clinic has now. */
function planUsage(PDO $db, string $key): int {
    return match ($key) {
        'branches' => (int)$db->query("SELECT COUNT(*) FROM branches WHERE is_active = 1")->fetchColumn(),
        'practitioners' => (int)$db->query("SELECT COUNT(*) FROM practitioner_profiles pp JOIN staff s ON s.id = pp.staff_id AND s.is_active = 1")->fetchColumn(),
        'staff_seats' => (int)$db->query("SELECT COUNT(*) FROM staff WHERE is_active = 1 AND COALESCE(is_support, 0) = 0")->fetchColumn(),
        'layouts' => (int)$db->query("SELECT COUNT(*) FROM document_layouts WHERE starter IS NULL AND status <> 'archived'")->fetchColumn(),
        'messages_per_month' => (int)$db->query("SELECT COUNT(*) FROM messages WHERE direction = 'out' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn(),
        'storage_gb' => (int)ceil(tenantStorageBytes() / 1073741824),
        default => throw new InvalidArgumentException("Unknown limit $key"),
    };
}

/** Bytes in the clinic's storage folder (scans, photos, signatures, documents). Cached for the request. */
function tenantStorageBytes(): int {
    static $bytes = null;
    if ($bytes !== null) return $bytes;
    $bytes = 0;
    $dir = rtrim(STORAGE_PATH, '/\\') . '/' . currentTenant()['id'];
    if (is_dir($dir)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) $bytes += $f->getSize();
    return $bytes;
}

/** Refuses to add $adding more of something when the plan's limit is reached (an upgrade message, nothing lost). */
function planCheck(PDO $db, string $key, int $adding = 1): void {
    $limit = planLimit($key);
    if ($limit === null) return;
    if ($key === 'storage_gb' ? tenantStorageBytes() + $adding > $limit * 1073741824 : planUsage($db, $key) + $adding > $limit) {
        [$one, $many] = PLAN_LIMIT_LABELS[$key];
        throw new PlanLimitException('Your ' . tenantPlan()['name'] . " plan includes $limit " . ($limit === 1 ? $one : $many) . '. Upgrade on the Subscription page to add more.');
    }
}

/** Whether the clinic can change anything (not read-only, suspended, cancelled or archived). */
function tenantWritable(): bool {
    if (!empty($_SESSION['support']) && ($_SESSION['support']['mode'] ?? 'read_only') !== 'read_write') return false;
    return in_array(tenantRecord()['status'], ['trial', 'active', 'past_due'], true);
}

/**
 * The central guard, run on every signed-in request (requireLogin): a suspended clinic sees only its subscription page;
 * a read-only one (and a read-only support session) can look but not change anything, except billing, export and
 * signing out. One place, so no page can forget it.
 */
function tenantGuard(): void {
    $status = tenantRecord()['status'];
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($status === 'archived') { logoutStaff(); http_response_code(404); exit('Not found'); }
    if ($status === 'suspended' && !in_array($script, ['subscription.php', 'logout.php', 'export_all.php'], true)) { header('Location: /pages/subscription.php'); exit; }
    $writing = !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true);
    if ($writing && !tenantWritable() && !in_array($script, READ_ONLY_ALLOWED, true)) {
        http_response_code(423);
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'json') || !empty($_SERVER['HTTP_X_CSRF_TOKEN'])) { header('Content-Type: application/json'); exit(json_encode(['success' => false, 'error' => tenantReadOnlyReason()])); }
        exit('<!DOCTYPE html><meta charset="utf-8"><title>Read-only</title><body style="font:15px system-ui;margin:40px"><h2>Nothing was saved</h2><p>' . htmlspecialchars(tenantReadOnlyReason())
            . '</p><p><a href="/pages/subscription.php">Subscription</a> · <a href="javascript:history.back()">Back</a></p></body>');
    }
}

function tenantReadOnlyReason(): string {
    if (!empty($_SESSION['support']) && ($_SESSION['support']['mode'] ?? '') !== 'read_write') return 'This is a read-only support session: support can look but not change anything.';
    return match (tenantRecord()['status']) {
        'read_only' => 'This clinic is read-only because the trial ended or a payment is overdue. Choose a plan on the Subscription page; everything is kept.',
        'cancelled' => 'This clinic’s subscription is cancelled, so it is read-only. You can still export everything for 90 days.',
        'suspended' => 'This clinic is suspended. Please contact support.',
        default => 'This clinic is read-only.',
    };
}

/** The strip shown above every page: trial days left, overdue payment, read-only, support session. Empty when all is well. */
function tenantBanner(): string {
    $r = tenantRecord();
    $html = '';
    if (!empty($_SESSION['support'])) {
        $html .= '<div class="alert alert-warning rounded-0 mb-0 py-2 fs-13" role="status"><i class="bi bi-life-preserver"></i> Support session by <b>' . sanitize($_SESSION['support']['admin_name'] ?? 'platform support')
               . '</b> (' . ($_SESSION['support']['mode'] === 'read_write' ? 'can make changes' : 'read-only') . ') until ' . sanitize(substr((string)$_SESSION['support']['expires_at'], 11, 5)) . '. Every action is in the clinic’s audit log. <a href="/logout.php">End session</a></div>';
    }
    $link = isAdmin() ? ' <a href="/pages/subscription.php">' . t('Choose a plan') . '</a>' : '';
    if ($r['status'] === 'trial' && $r['trial_ends_at']) {
        $days = max(0, (int)ceil((strtotime($r['trial_ends_at']) - time()) / 86400));
        $setup = '';
        if (isAdmin() && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'onboarding.php') {
            $done = count(array_filter(array_column(onboardingSteps(getDB()), 'done')));
            if ($done < 5) $setup = ' · <a href="/pages/onboarding.php">' . sanitize(t('Set up your clinic: {n} of 5 done', ['n' => $done])) . '</a>';
        }
        $html .= '<div class="alert alert-info rounded-0 mb-0 py-2 fs-13" role="status"><i class="bi bi-hourglass-split"></i> ' . sanitize(t('Free trial: {n} days left.', ['n' => $days])) . $link . $setup . '</div>';
    } elseif ($r['status'] === 'past_due') {
        $html .= '<div class="alert alert-warning rounded-0 mb-0 py-2 fs-13" role="alert"><i class="bi bi-credit-card"></i> ' . t('The last payment failed. Update the card within 7 days to keep full access.') . $link . '</div>';
    } elseif (in_array($r['status'], ['read_only', 'cancelled', 'suspended'], true)) {
        $html .= '<div class="alert alert-danger rounded-0 mb-0 py-2 fs-13" role="alert"><i class="bi bi-lock"></i> ' . sanitize(tenantReadOnlyReason()) . $link . '</div>';
    }
    return $html;
}

/** The five setup steps of a new clinic, each done when the data says so. */
function onboardingSteps(PDO $db): array {
    $one = fn(string $sql) => (int)$db->query($sql)->fetchColumn();
    $set = fn(string $k) => $one("SELECT COUNT(*) FROM integration_settings WHERE k = " . $db->quote($k) . " AND v <> ''") > 0;
    $waOn = $one("SELECT COUNT(*) FROM integration_settings WHERE k = 'msg.wa_enabled' AND v = '1'") > 0;
    return [
        ['title' => 'Clinic profile and logo', 'help' => 'Name, phone, address and email that appear on documents and messages.', 'link' => '/pages/clinic_profile.php',
         'done' => $set('brand.name') && $set('clinic.phone') && $set('clinic.address')],
        ['title' => 'First branch and opening hours', 'help' => 'Where patients come, its DHA licence, TRN and working hours.', 'link' => '/pages/settings_clinic.php',
         'done' => $one("SELECT COUNT(*) FROM branches WHERE is_active = 1 AND working_hours IS NOT NULL AND working_hours <> ''") > 0],
        ['title' => 'Treatments and prices', 'help' => 'What the clinic offers, with prices, VAT and durations.', 'link' => '/pages/services.php',
         'done' => $one("SELECT COUNT(*) FROM services WHERE is_active = 1") > 0],
        ['title' => 'Staff and practitioners', 'help' => 'Accounts for the team, and practitioner profiles with schedules.', 'link' => '/pages/staff.php',
         'done' => $one("SELECT COUNT(*) FROM practitioner_profiles") > 0 && $one("SELECT COUNT(*) FROM staff WHERE is_active = 1 AND COALESCE(is_support, 0) = 0") > 1],
        ['title' => 'WhatsApp or email', 'help' => 'So reminders, codes and receipts reach patients.', 'link' => '/pages/messaging_settings.php',
         'done' => $waOn || (function_exists('mailConfigured') && mailConfigured($db))],
    ];
}
