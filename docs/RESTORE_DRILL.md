# Restore drill (every 3 months, per clinic)

A backup only counts once it has been restored. Every 3 months, restore one clinic's latest nightly backup somewhere
safe, check it, and record the result on **Admin → Data flows & backups** (auditors see it there).

## Where the backups are
- Nightly after 02:00 (clinic time) cron.php writes `<database>_<YYYYmmdd_His>.sql.gz` plus a `.sha256` file to
  `BACKUP_PATH`, outside the website. Kept: 14 days, plus the first backup of each month for 12 months.
- Hostinger (single clinic): `/home/u000000000/domains/crm.example.ae/backups/`.
- Uploaded files (Emirates ID scans, photos, signatures) are in `STORAGE_PATH`; they need the host's own backups too.

## The drill
1. Pick last night's file. Download it with its `.sha256` file over the hosting file manager (never into the website).
   It holds personal data: keep it on an encrypted disk and delete it after the drill.
2. On a computer with MySQL/MariaDB and this project's code (the local copy, or the UAE server):
   `php tools/restore_drill.php --file=/path/to/<file>.sql.gz`
   It checks the checksum and the "dump complete" marker, loads it into a scratch database
   (`clinic_crm_restore_drill`), counts tables and rows, checks staff, leads, patients and the update history, prints
   PASSED or FAILED, then drops the scratch database.
3. Record it: Admin → Data flows & backups → Record drill (day, file name, tables, rows, result, notes such as where it
   was restored and how long it took).
4. Delete the downloaded copy.

## If it fails
Keep the file, note the error, and check the next newest backup the same way. Tell the platform owner the same day:
a failing backup is treated like an outage. `docs/RUNBOOK_UAE.md` has the full disaster-recovery steps (restoring over
a live database is done by hand, never by these tools).
