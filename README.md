# ClinicCRM

An open-source clinic management system for aesthetic and medical clinics, built for the UAE: leads and marketing,
patients, scheduling, clinical notes (EMR), consents and photos, billing with VAT, insurance claims, inventory,
WhatsApp/SMS messaging, reports, and an English/Arabic (RTL) interface. It runs as a single clinic or as a
multi-clinic platform (one database per clinic, encrypted per-clinic keys, Stripe subscriptions).

Plain PHP and MariaDB/MySQL, no framework and no Composer: it runs on ordinary shared hosting as well as a cloud server.

## Features
- **Leads & marketing:** Meta Lead Ads in (webhook), stage updates back to Meta (Conversions API), WhatsApp capture
  extension, pipeline board, campaigns, recalls, broadcasts, online booking page.
- **Patients & front desk:** patient files, scheduler with practitioner columns, visit board, waiting list.
- **Clinical:** signed, amend-only clinical notes with ICD-10, prescriptions, consent forms on a tablet, before/after photos.
- **Billing:** VAT invoices, receipts with mixed payment methods, credit notes, packages, vouchers, wallets, day close,
  printable layouts in English and Arabic.
- **Insurance:** cards, prior approvals, claim batches.
- **Inventory:** stock, batches and expiry, opened vials, purchase orders.
- **Reports:** clinic overview, finance (VAT return, receivables), clinical and DHA-style reports, CSV export.
- **Compliance:** audit log, patient data export and anonymisation, data-residency checks, nightly backups and restore drills.
- **Moving from ClinicSoft:** CSV importers for patients, balances, packages, visits, invoices, notes, documents and
  appointments, each with a check that saves nothing first.

## Requirements
- PHP 8.1+ with `pdo_mysql`, `curl`, `mbstring`, `iconv`, `openssl`
- MariaDB 10.6+ or MySQL 8.0+
- Apache/LiteSpeed (an `.htaccess` is included) or nginx (`deploy/nginx_clinic_crm.conf.example`)
- A cron job every 5 minutes

## Installation (single clinic)
1. Put the files in your web root.
2. Copy `config.example.php` to `crm_config.php` **one folder above the web root** (or point the `CRM_CONFIG`
   environment variable at it) and fill in the database settings and a new `MASTER_KEY`.
3. Create an empty database, then create the tables:
   ```bash
   php tools/migrate.php
   ```
4. Create the first admin (replace the name, email and password):
   ```bash
   php -r "echo password_hash('a-long-unique-password', PASSWORD_DEFAULT), PHP_EOL;"
   ```
   ```sql
   INSERT INTO staff (name, email, password, role, is_owner) VALUES ('Clinic Admin', 'you@example.com', '<the hash>', 'admin', 1);
   ```
5. Add the cron job:
   ```
   */5 * * * * php /path/to/webroot/cron.php
   ```
6. Sign in at `/login.php`, turn on 2-step sign-in under My account, and work through Help → Go-live checklist
   (clinic profile, branches, treatments, staff, messaging, document layouts).

Deployment, multi-clinic mode and backups: [docs/DEPLOY.md](docs/DEPLOY.md), [docs/GO_LIVE.md](docs/GO_LIVE.md),
[docs/RUNBOOK_UAE.md](docs/RUNBOOK_UAE.md) and [docs/RESTORE_DRILL.md](docs/RESTORE_DRILL.md).

## Local development
- Create `includes/db.local.php` with the same constants as `config.example.php` (it is git-ignored) and a local database.
- `php tools/migrate.php`, then `php local/seed.php` for fake staff, leads and test logins.
- Mock servers for every outside service live in `local/` (SMTP, Meta, Google, SMS, WhatsApp, Stripe).

## Tests
```bash
php tests/run.php
```
Also `tests/smoke.php`, `tests/integration/run_all.php` (needs the local server on 127.0.0.1:8080 and the mocks) and
`php tools/predeploy_check.php --full` before every release.

## Data residency
Clinic data is meant to stay in the UAE (`DATA_REGION = 'AE'`). Every outside service is listed in
`includes/data_flows.php`, and `php tools/residency_check.php` fails if code calls an unregistered host.

## Security
Please report vulnerabilities privately: see [SECURITY.md](SECURITY.md).

## Licence
[GNU Affero General Public License v3.0](LICENSE). If you run a modified version for others over a network, you must
offer them its source code.
