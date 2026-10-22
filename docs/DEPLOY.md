# Deploying and releasing

## Every release

1. Locally: `php tools/predeploy_check.php --full` (lint, residency check, unit tests, smoke test, feature suites).
   It must say "Ready to deploy".
2. Back up production first: `php tools/backup.php` on the server, or your host's database export.
3. Upload only the changed files. **Never upload** `includes/db.local.php`, `local/`, `.claude/`, `.git/`, `tests/`,
   `*.sql` outside `migrations/`, or `*.md`. (`tools/`, `migrations/` and `deploy/` may be uploaded; the web server
   rules in `.htaccess` / `deploy/nginx_clinic_crm.conf.example` block them.)
4. Upload order: new include files first, then migrations (applied as below), then files that need the new columns,
   then pages.
5. Schema: `php tools/migrate.php --dry-run`, then `php tools/migrate.php --yes` (production refuses without a backup
   from the last 10 minutes).
6. Check: sign in, open the changed pages, `/status.php` is green, no new lines in `LOG_PATH/php_errors.log`.
7. Rollback: put back the previous files. Migrations only add, so the old code runs on the new schema.

## Shared hosting

- Put `crm_config.php` in the folder that **contains** the web root (e.g. next to `public_html`), never inside it.
- Upload `.htaccess` and confirm that `README.md`, `*.sql` files and `includes/db.php` return 403.
- Cron: `php /path/to/public_html/cron.php` every 5 minutes (it runs the nightly backup itself).

### Database updates without a command line
Add a temporary cron job running once per minute:
```
/bin/sh /path/to/public_html/tools/run_migrations.sh
```
It creates the encryption keys if missing, backs up the database, applies pending migrations and logs to
`logs/migrate.log` (next to the web root). Once the newest migration is applied it leaves `logs/migrated_<version>`
and does nothing on later runs. Delete the job afterwards. Upload new migrations and tools first; upload pages that use
new tables only after the log says "Done".

## Multi-clinic mode

Needs a server where code may create databases and users. `crm_config.php` adds `PLATFORM_DB_NAME`,
`REFERENCE_DB_NAME`, `PRODUCT_DOMAIN`, `PROVISION_DB_USER`/`PASS` and `MASTER_KEY` (see `config.example.php`).
Then, once:
```
php tools/migrate.php --set=platform
php tools/migrate.php --set=reference
```
An existing single-clinic database becomes clinic #1 with
`php tools/register_existing_tenant.php --slug=main --name="…" --db-name=… --domains=crm.…,main.PRODUCT_DOMAIN --owner-email=…`.

- New clinic: `php tools/provision_tenant.php --slug=… --name="…" --owner-email=… --owner-name="…"` (or self sign-up
  at `PLATFORM_HOST/signup.php`).
- Cron: `php cron.php` (runs every clinic in turn).
- Migrations: `php tools/migrate.php --all-tenants --yes`.
- Backups: `php tools/backup.php --all-tenants` and `php tools/backup.php --platform`.
- Back office: `https://PLATFORM_HOST/platform/`; admins are managed with `php tools/platform_admin.php`.
