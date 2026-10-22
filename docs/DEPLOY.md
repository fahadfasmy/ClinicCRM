# Releasing a change

## Every release

1. Local: `php tools/predeploy_check.php --full` (lint, unit tests, smoke test, feature suites). Must say "Ready to deploy".
2. Back up production first (`php tools/backup.php` on the server, or hPanel export while on Hostinger).
3. Upload only the changed files. **Never upload**: `includes/db.local.php`, `local/`, `.claude/`, `.git/`, `tests/`, `*.sql` (except `migrations/`), `*.md`, `BUILD_PROMPT.md`.
   (`tools/`, `migrations/`, `deploy/` may be uploaded; the web server blocks them.)
4. Schema: `php tools/migrate.php --dry-run`, then `php tools/migrate.php --yes` (production refuses without a backup from the last 10 minutes).
5. Check: sign in, open the changed pages, look at the log in LOG_PATH, confirm cron ran.
6. Rollback: put back the previous files (the deploy keeps a copy). Migrations are additive, so old code runs on the new schema.

## Phase 0 go-live on Hostinger (one-off)

Order matters, so the site never loses its database settings:

1. **[YOU]** In hPanel → File Manager, open the folder that **contains** `public_html` (the top level, where the `backup_*` folders are).
   Create `crm_config.php` there with the four lines from the current `public_html/includes/db.php`
   (DB_HOST, DB_NAME, DB_USER, DB_PASS — copy them; don't send them to me) plus:
   `define('APP_ENV', 'production');` `define('LOG_PATH', __DIR__ . '/logs');` `define('BACKUP_PATH', __DIR__ . '/backups');`
2. I upload `includes/config.php` first (inactive until db.php uses it), then the new `includes/db.php` (no credentials), then check every page.
3. I upload `.htaccess`, then confirm `README.md` and `*.sql` files now return 403 and every page still works; if anything breaks, `.htaccess` is removed straight away.
4. **[YOU]** Rotate the database password in hPanel → Databases, then update it in `crm_config.php` only.
5. Optional: hPanel → Advanced → Cron Jobs: `php public_html/tools/backup.php` daily at 02:00.

## Phase 1 go-live on Hostinger (single-clinic mode)

Hostinger can't create databases or users from code, so the live site stays in **single-clinic mode** (no
PLATFORM_DB_NAME in crm_config.php): the existing clinic is clinic #1 with the database from crm_config.php. Multi-clinic mode starts
on the UAE server (docs/RUNBOOK_UAE.md, step 4b).

Order (the call-back email and website pixels read the clinic profile, so it must be filled in before they switch over):
1. Upload `assets/vendor/` (inert until pages point at it).
2. Upload `includes/crypto.php`, `includes/tenant.php`, then `includes/config.php`, `includes/db.php`, `includes/auth.php`. Check pages.
3. Upload `includes/meta_leads.php`, `pages/api_public_lead.php`, `pages/webhook.php` (the profile page needs metaWebPixels()), then
   `includes/permissions.php`, `includes/header.php`, `includes/footer.php`, `pages/clinic_profile.php`. Fill in Admin → Clinic profile with
   today's values (name, clinic email, phone, WhatsApp, address, email logo, pixels hydrafacial/dental).
4. Upload the rest: `includes/footer.php`, `login.php`, `includes/lead_email.php`, `includes/meta_leads.php`, `includes/mailer.php`,
   `includes/gcal.php`, `includes/data_flows.php`, `pages/api_public_lead.php`, `pages/webhook.php`, `pages/purchase_order.php`,
   `pages/analytics.php`, `pages/reports.php`, `cron.php`, `tools/*.php`, `includes/provision.php`, `migrations/`, `config.example.php`.
5. Check: sign in, dashboard, analytics charts, leads table, Clinic profile; send a test call-back email preview.

## Multi-clinic mode (UAE server)

crm_config.php adds PLATFORM_DB_NAME, REFERENCE_DB_NAME, PRODUCT_DOMAIN, PROVISION_DB_USER/PASS, MASTER_KEY (see
config.example.php). Then, once:
```
php tools/migrate.php --set=platform
php tools/migrate.php --set=reference
php tools/register_existing_tenant.php --slug=main --name="…" --db-name=… --domains=crm.…,main.PRODUCT_DOMAIN --owner-email=…
```
New clinic: `php tools/provision_tenant.php --slug=… --name="…" --owner-email=… --owner-name="…"`.
Cron: `php cron.php` (runs every clinic in turn). Migrations: `php tools/migrate.php --all-tenants --yes`.
Backups: `php tools/backup.php --all-tenants` and `php tools/backup.php --platform`.

## Database updates on Hostinger (no command line)

hPanel → Advanced → Cron Jobs → Custom, once per minute (hPanel allows at most 255 characters):
```
/bin/sh /home/u000000000/domains/crm.example.ae/public_html/tools/run_migrations.sh
```
It backs up the database, applies pending migrations and logs to `logs/migrate.log` (next to public_html). After the
newest migration is applied it leaves `logs/migrated_<version>` and does nothing on later runs. Delete the job afterwards.
Upload new migration files and tools first; upload pages that use new tables only after the log says "Done".
