# Go-live: deployment checklist, ClinicSoft migration day, second-clinic pilot

## 1. Before deploying (every release)
- `php tools/predeploy_check.php --full` is green (syntax, residency check, unit, smoke, feature suites).
- Database backup taken (`php tools/backup.php`), and the last restore drill is under 3 months old.
- Upload order: new include files first, then the migration (cron `tools/run_migrations.sh` applies it and backs up
  first), then files that need the new columns, then pages. Never upload `includes/db.local.php`, `local/`, `tests/`,
  `.claude/`, `*.md` or dumps.
- After upload: the main screens load signed in, `/status.php` is green, no new lines in `logs/php_errors.log`.
- Remove the temporary migration cron job; keep `cron.php` every 5 minutes.

## 2. New server (UAE) or new clinic on the platform
- `crm_config.php` above the web root: database, `MASTER_KEY` (store a copy offline), paths outside the web root,
  `DATA_REGION = 'AE'`, platform settings (`PLATFORM_HOST`, Stripe keys, platform mailbox) where used.
- HTTPS, cron every 5 minutes, nightly backups landing in the backup folder, second copy at another UAE site.
- `php tools/residency_check.php` passes; Data flows & backups page reviewed.
- Webhooks registered: Meta (leads), WhatsApp Cloud, Stripe (platform only).

## 3. Clinic set-up (the clinic's admin; Help → Go-live checklist has the plain-language version)
Clinic profile and logo · branches with hours, TRN, DHA licence · numbering and VAT setting · treatment menu and
prices · practitioners and schedules · packages · staff and roles (2-step sign-in on) · WhatsApp/SMS and email ·
templates approved · invoice/receipt layouts checked in English and Arabic.

## 4. Moving from ClinicSoft (Admin → Import from ClinicSoft)
Export each list from ClinicSoft as CSV. Every import starts with a check that saves nothing; fix what its report
lists (CSV download) and check again; then import. Running a file twice adds nothing twice.

| Order | Import | Notes |
|---|---|---|
| 1 | Patients | Matched to patients already here (converted leads) by Emirates ID, or mobile + first name. |
| 2 | Balances and advances | Owed amounts become `OB-<file no>` opening balances, collected with a normal receipt; advances become wallet credit. Not revenue or VAT here. |
| 3 | Active packages | Package names must exist in Billing → Packages first. |
| 4 | Past visits | History only: no stock is taken. |
| 5 | Past invoices | Records (`CS-<number>`), not tax invoices of this system; not counted again in revenue or VAT. |
| 6 | Clinical notes | Kept signed and read-only, marked "From ClinicSoft". |
| 7 | Scanned documents | `php tools/import_documents.php --dir=<scans folder>` (file names start with the file number); check first, then `--commit`. |
| 8 | Future appointments | On go-live day, after booking in ClinicSoft has stopped. |

Large files: `php tools/import_clinicsoft.php --kind=<kind> --file=<csv> [--commit]` (same rules).

### Go-live day
1. Morning: stop booking in ClinicSoft. Take a ClinicSoft backup and keep it.
2. Import future appointments (and any patients, balances or packages changed since the earlier imports: the import
   skips what is already in).
3. Take a backup here (`php tools/backup.php`).
4. Front desk works only here from now on. ClinicSoft stays available read-only for comparison.
5. End of day: day close; compare totals with expectations.

### First week
- Day closes and receivables match expectations; no unexpected "rows to check".
- Spot-check 20 patients (details, balances, packages, history).
- Record a restore drill. Then switch ClinicSoft off (keep its final backup per the clinic's retention duty).

## 5. Pilot with a second clinic (platform)
- The clinic signs up at the platform address, verifies its email and lands in the 5-step set-up checklist
  (target: working, with a first booking, in under 60 minutes, with no code change).
- Check: its data never appears at the first clinic's address and vice versa (`tests/integration/tenancy_suite.php`
  proves this for every list, detail, download and API endpoint).
- Weekly for the first month: review the back office (usage, failed jobs and webhooks), support grants used, their
  feedback; fix anything found before the next clinic.
- Before charging: real Stripe keys (test mode first), the lawyer's Terms, Privacy Policy and DPA in `legal/`,
  confirmed prices in the plans editor, the platform mailbox.
