<?php
// config.example.php — copy to crm_config.php ONE FOLDER ABOVE the web root (never inside it) and fill in.
// On Hostinger: the folder that contains public_html. On a UAE cloud server: e.g. /etc/clinic-crm/crm_config.php
// with CRM_CONFIG=/etc/clinic-crm/crm_config.php set for PHP-FPM and cron. Placeholders only: never commit real values.

define('APP_ENV', 'production');            // local | staging | production
define('APP_URL', 'https://crm.example.ae');

// Database. Single-clinic mode: the clinic's database. Multi-clinic mode: the database server's address plus the
// platform login (each clinic's own database user lives, encrypted, in the platform database).
define('DB_HOST', 'localhost');
define('DB_NAME', 'CHANGE_ME');
define('DB_USER', 'CHANGE_ME');
define('DB_PASS', 'CHANGE_ME');
define('DB_CHARSET', 'utf8mb4');

// Multi-clinic mode: leave these out for a single clinic (today's Hostinger site)
// define('PLATFORM_DB_NAME', 'crm_platform');
// define('REFERENCE_DB_NAME', 'crm_reference');
// define('PRODUCT_DOMAIN', 'example-product.ae');     // clinics get <slug>.PRODUCT_DOMAIN
// define('TENANT_DB_PREFIX', 'crm_t_');               // clinic databases and users: crm_t_<slug> / crm_t_<id>
// define('PROVISION_DB_USER', 'CHANGE_ME');           // a login that may CREATE DATABASE / CREATE USER (tools only)
// define('PROVISION_DB_PASS', 'CHANGE_ME');
// define('PLATFORM_HOST', 'app.example-product.ae');   // signup, back office (/platform/) and the Stripe webhook
// Selling the platform (Stripe Billing in AED). Test keys first; never paste them anywhere but this file.
// define('STRIPE_SECRET_KEY', 'CHANGE_ME');             // sk_test_… then sk_live_…
// define('STRIPE_WEBHOOK_SECRET', 'CHANGE_ME');         // whsec_… for https://PLATFORM_HOST/pages/api_stripe.php
// define('STRIPE_TEST_CLOCKS', false);                  // test mode only: each new clinic gets its own Stripe test clock
// The platform's own mailbox (signup links, trial and billing emails; never patient data)
// define('PLATFORM_MAIL_HOST', 'CHANGE_ME');  define('PLATFORM_MAIL_PORT', 587);  define('PLATFORM_MAIL_SECURITY', 'tls');
// define('PLATFORM_MAIL_USER', 'CHANGE_ME');  define('PLATFORM_MAIL_PASS', 'CHANGE_ME');  define('PLATFORM_MAIL_FROM', 'hello@example-product.ae');

// Encryption master key: wraps each clinic's data key. base64 of 32 random bytes, made with:
//   php -r "echo base64_encode(random_bytes(32));"   (never share it; losing it loses every encrypted value)
define('MASTER_KEY', 'CHANGE_ME');
define('MASTER_KEY_ID', 'k1');

// Where logs, backups and clinic files go (outside the web root; all hold personal data)
define('LOG_PATH', '/path/outside/webroot/logs');
define('BACKUP_PATH', '/path/outside/webroot/backups');
define('STORAGE_PATH', '/path/outside/webroot/storage');

// Data residency: must stay 'AE' (see docs/RUNBOOK_UAE.md)
define('DATA_REGION', 'AE');
