<?php
// tools/residency_check.php — the UAE residency check (F15). Fails (exit 1) when an endpoint configured for a clinic is
// not in the data-flow register (includes/data_flows.php), or when a staff or patient page loads a script, stylesheet,
// font or image from another host. Run by tools/predeploy_check.php and nightly by cron.php (which alerts the admin).
//   php tools/residency_check.php                one clinic (single-clinic mode, or --tenant=<slug>)
//   php tools/residency_check.php --all-tenants  each clinic in turn
//   php tools/residency_check.php --code-only    pages only, no database (CI)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/data_flows.php';

$args = getopt('', ['tenant:', 'all-tenants', 'code-only']);
if (isset($args['all-tenants'])) exit(cliForEachTenant(__FILE__) ? 1 : 0);
$db = null;
if (!isset($args['code-only'])) { cliTenant(); $db = getDB(); }
$problems = residencyCheck($db);
echo 'Data region ' . DATA_REGION . ': ' . ($problems ? count($problems) . " problem(s)\n  - " . implode("\n  - ", $problems) : 'residency check passed') . "\n";
exit($problems ? 1 : 0);
