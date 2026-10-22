<?php
// includes/data_flows.php — every place data leaves this system (Phase 0 audit, 16 Oct 2026). The F15 "Data flows"
// screen and the outbound-call guard (later phases) are built on this list; tests/data_flows_test.php fails when the code
// uses a host that isn't listed here. "health" = could the data reveal something about a person's health or treatment:
//   no | low (that a person enquired / has an appointment, no service or clinical detail) | yes
// "where" is where the provider processes the data as far as we know; UAE-only is the goal (docs/RUNBOOK_UAE.md).

const DATA_FLOWS = [
    // ── Server → outside (the PHP code calls these) ──
    'smtp' => [
        'name' => 'Clinic mailbox (SMTP)', 'side' => 'server', 'hosts' => ['smtp.hostinger.com'],
        'code' => 'includes/mailer.php smtpDeliver()', 'enabled_by' => 'Admin → Email (mail.password)',
        'sends' => 'To leads: first name, last 4 phone digits, treatment interest, call-back time. To staff: sign-in codes, reset links, alerts.',
        'health' => 'low', 'where' => 'Hostinger mail servers (location not confirmed, likely outside the UAE)',
        'action' => 'Phase 10/13: move to a UAE mail provider or keep content free of treatment names (call-back email currently names the treatment of interest).',
    ],
    'meta_graph' => [
        'name' => 'Meta Lead Ads + Conversions API', 'side' => 'server', 'hosts' => ['graph.facebook.com'],
        'code' => 'includes/meta_leads.php metaGraph()', 'enabled_by' => 'Meta Leads page (meta.token, meta.capi_enabled)',
        'sends' => 'Reads lead forms. Sends stage events (Lead/Contacted/Qualified/Booked/Converted/Lost/Cold) with the Meta lead id and SHA-256 hashed phone/email; website "Lead" events with hashed phone, IP, browser, fbp/fbc cookies.',
        'health' => 'low', 'where' => 'Meta (USA / EU)',
        'action' => 'Keep hashed identifiers only; never send service names. Becomes a per-clinic setting in Phase 1.',
    ],
    'google_oauth' => [
        'name' => 'Google sign-in for Calendar', 'side' => 'server+browser', 'hosts' => ['accounts.google.com', 'oauth2.googleapis.com'],
        'code' => 'includes/gcal.php gcalAuthUrl()/gcalConnect()/gcalToken()', 'enabled_by' => 'Google Calendar page (gcal.client_id) + each practitioner connecting',
        'sends' => 'OAuth codes and tokens only.', 'health' => 'no', 'where' => 'Google (USA / global)', 'action' => 'None.',
    ],
    'google_calendar' => [
        'name' => 'Google Calendar events', 'side' => 'server', 'hosts' => ['www.googleapis.com'],
        'code' => 'includes/gcal.php gcalSync()', 'enabled_by' => 'Each practitioner connecting their calendar',
        'sends' => '"Appointment #id – First name + initial", start/end time, a link back to the CRM. No service, phone or notes.',
        'health' => 'low', 'where' => 'Google (USA / global)',
        'action' => 'F15: default to initials + MRN instead of first name.',
    ],

    'whatsapp_cloud' => [
        'name' => 'WhatsApp Cloud API (patient messages)', 'side' => 'server', 'hosts' => ['graph.facebook.com'],
        'code' => 'includes/messaging.php waHttp(); webhook pages/api_wa_cloud.php', 'enabled_by' => 'Admin → Messaging (WhatsApp switch, phone number id, token)',
        'sends' => 'Patient phone number, first name, appointment date/time, branch and secure links. Treatment names or amounts only in templates the owner cleared for health information.',
        'health' => 'low', 'where' => 'Meta (outside the UAE)',
        'action' => 'Keep default templates free of health information; owner acknowledgement is audit-logged.',
    ],
    'sms' => [
        'name' => 'SMS provider (not chosen yet)', 'side' => 'server', 'hosts' => [],
        'code' => 'includes/messaging.php deliver() sms adapter', 'enabled_by' => 'Admin → Messaging (SMS provider)',
        'sends' => 'Phone number and the same minimal text as WhatsApp.', 'health' => 'low', 'where' => 'Decide with the provider (prefer UAE processing)',
        'action' => 'Add the provider’s host here when one is chosen; until then only the local test service exists.',
    ],

    'stripe' => [
        'name' => 'Stripe (the clinic’s subscription to this platform)', 'side' => 'server+browser', 'hosts' => ['api.stripe.com', 'checkout.stripe.com', 'billing.stripe.com'],
        'code' => 'includes/platform_billing.php stripeApi(); pages/api_stripe.php (webhook, signature checked)', 'enabled_by' => 'The platform config (STRIPE_SECRET_KEY); clinics only choose a plan',
        'sends' => 'The clinic’s name, owner email, TRN and chosen plan; card details are typed on Stripe’s own page. Never patient data.',
        'health' => 'no', 'where' => 'Stripe (USA / EU); business data about the clinic only',
        'action' => 'None: patient data never goes to Stripe (brief section 8).',
    ],

    // ── Browser → outside ──
    'google_fonts' => [
        'name' => 'Google Fonts on landing pages', 'side' => 'browser', 'hosts' => ['fonts.googleapis.com', 'fonts.gstatic.com'],
        'code' => 'dental.html, hydrafacial.html', 'enabled_by' => 'In the page code',
        'sends' => 'Font requests from visitor browsers (IP, user agent).', 'health' => 'no', 'where' => 'Google (global)',
        'action' => 'None for marketing pages. Staff pages self-host every library and font since Phase 1 (assets/vendor) and send a Content-Security-Policy.',
    ],

    // ── Landing pages (marketing, before someone becomes a patient) ──
    'meta_pixel' => [
        'name' => 'Meta Pixel on landing pages', 'side' => 'browser', 'hosts' => ['connect.facebook.net', 'www.facebook.com'],
        'code' => 'dental.html, hydrafacial.html', 'enabled_by' => 'In the page code (per clinic from Phase 1)',
        'sends' => 'Page views and a "Lead" event when the enquiry form is sent (no form contents).', 'health' => 'low', 'where' => 'Meta (USA / EU)',
        'action' => 'Phase 1: pixel ids become per-clinic settings.',
    ],
    'ms_clarity' => [
        'name' => 'Microsoft Clarity (session recording) on a landing page', 'side' => 'browser', 'hosts' => ['www.clarity.ms'],
        'code' => 'landing pages (your Clarity project)', 'enabled_by' => 'In the page code',
        'sends' => 'Recordings of visitor behaviour on the page, which has an enquiry form (name, phone). Clarity masks typed text by default.',
        'health' => 'low', 'where' => 'Microsoft (outside the UAE)',
        'action' => 'Decision needed: remove, or confirm masking is strict and list it in the privacy notice.',
    ],
    'landing_media' => [
        'name' => 'Images and map links on landing pages', 'side' => 'browser', 'hosts' => ['images.unsplash.com', 'example.ae', 'maps.app.goo.gl', 'www.google.com'],
        'code' => 'dental.html, hydrafacial.html, call-back email logo', 'enabled_by' => 'In the page code',
        'sends' => 'Image/map requests (IP, user agent).', 'health' => 'no', 'where' => 'Various (outside the UAE)', 'action' => 'None for marketing pages.',
    ],
    'whatsapp_links' => [
        'name' => 'WhatsApp click-to-chat links', 'side' => 'link', 'hosts' => ['wa.me', 'web.whatsapp.com'],
        'code' => 'Lead page buttons, emails, landing pages, the Chrome extension (reads web.whatsapp.com in staff browsers)', 'enabled_by' => 'A person clicking',
        'sends' => 'Nothing by the CRM itself; the person opens WhatsApp with the number.', 'health' => 'no', 'where' => 'Meta (outside the UAE)', 'action' => 'None.',
    ],

    // ── Inbound (others call us) ──
    'inbound' => [
        'name' => 'Endpoints that receive data', 'side' => 'inbound', 'hosts' => [],
        'code' => 'pages/meta_receiver.php (Meta webhook, signature checked), pages/api_public_lead.php (landing-page forms), pages/api_whatsapp.php (Chrome extension, API key)',
        'enabled_by' => '—', 'sends' => '—', 'health' => 'low', 'where' => 'This server', 'action' => 'Phase 13: rate limits on public endpoints.',
    ],
];

/** Every registered host (for the outbound guard and the residency check). */
function dataFlowHosts(): array {
    return array_values(array_unique(array_merge(...array_column(DATA_FLOWS, 'hosts'))));
}

/** True if the app may call this host (or URL): it is in the register, or it is this computer on a local copy. */
function outboundAllowed(string $hostOrUrl): bool {
    $host = strtolower((string)(str_contains($hostOrUrl, '://') ? parse_url($hostOrUrl, PHP_URL_HOST) : $hostOrUrl));
    if ($host === '') return false;
    if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) return defined('APP_ENV') && APP_ENV === 'local';
    return in_array($host, dataFlowHosts(), true);
}

/** Every outbound call goes through this first: refuses hosts that are not in the data-flow register. */
function outboundGuard(string $hostOrUrl): void {
    if (!outboundAllowed($hostOrUrl)) {
        $host = str_contains($hostOrUrl, '://') ? (string)parse_url($hostOrUrl, PHP_URL_HOST) : $hostOrUrl;
        throw new RuntimeException("Refused: $host is not in the data-flow register (Settings → Data flows).");
    }
}

/**
 * The UAE residency check (tools/residency_check.php, the pre-deploy check, the Data flows screen). Returns problems:
 *  - an endpoint configured in the clinic's settings or the config file whose host is not in the register;
 *  - a staff or patient page that loads a script, stylesheet, font or image from another host (they must be self-hosted).
 * $pageDirs: where to look for pages (tests pass their own).
 */
function residencyCheck(?PDO $db, ?array $pageDirs = null): array {
    $problems = [];
    $urls = [];
    if ($db) {
        foreach ($db->query("SELECT k, v FROM integration_settings WHERE v LIKE 'http%' AND (k LIKE '%base%' OR k LIKE '%url%' OR k LIKE '%endpoint%')") as $r) $urls[$r['k']] = $r['v'];
        $mh = $db->query("SELECT v FROM integration_settings WHERE k = 'mail.host'")->fetchColumn();
        if ($mh) $urls['mail.host'] = (string)$mh;
    }
    foreach (get_defined_constants(true)['user'] ?? [] as $k => $v)
        if (is_string($v) && preg_match('#^https?://#', $v) && preg_match('/(BASE|URL|ENDPOINT|HOST)$/', $k)) $urls[$k] = $v;
    // Links people open in their browser (review page, map, booking page, the app's own address) are not data flows
    $skip = ['msg.google_review_url', 'msg.map_link', 'msg.booking_link', 'msg.base_url', 'clinic.logo_url', 'clinic.website', 'APP_URL'];
    foreach ($urls as $k => $u) {
        if (in_array($k, $skip, true) || str_ends_with($k, '_link')) continue;
        if (!outboundAllowed($u)) $problems[] = "$k points to " . (parse_url($u, PHP_URL_HOST) ?: $u) . ', which is not in the data-flow register';
    }
    $root = realpath(__DIR__ . '/..');
    $files = [];
    foreach ($pageDirs ?? ["$root/pages", "$root/includes", $root] as $d) $files = array_merge($files, glob(rtrim($d, '/\\') . '/*.php') ?: []);
    foreach ($files as $f) {
        if (str_ends_with($f, 'data_flows.php')) continue;
        $src = (string)file_get_contents($f);
        if (preg_match_all('#<(?:script|img|iframe|source|video|audio)\b[^>]*\bsrc\s*=\s*["\']?(https?:)?//([a-z0-9.-]+)#i', $src, $m1)) foreach ($m1[2] as $h) $problems[] = basename($f) . " loads from $h";
        if (preg_match_all('#<link\b[^>]*\bhref\s*=\s*["\']?(https?:)?//([a-z0-9.-]+)#i', $src, $m2)) foreach ($m2[2] as $h) $problems[] = basename($f) . " loads a stylesheet or font from $h";
        if (preg_match_all('#(?:url\(\s*["\']?|@import\s+["\'])(https?:)?//([a-z0-9.-]+)#i', $src, $m3)) foreach ($m3[2] as $h) $problems[] = basename($f) . " loads a file from $h in its CSS";
    }
    return array_values(array_unique($problems));
}
