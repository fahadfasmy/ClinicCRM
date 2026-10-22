# UAE hosting runbook (Phase 0)

Goal (decision D1): every copy of patient data — database, uploads, backups, logs, sessions — lives and is processed in the UAE.
Today the CRM runs on Hostinger shared hosting (database `u000000000_crm`), which is **not** in the UAE. This runbook moves it.
Steps marked **[YOU]** need your accounts, payment or DNS; everything else I prepare and run with you.

---

## 1. Choose the provider [YOU]

| Option | UAE region | Managed MySQL | Key/secret service | Object storage | Notes |
|---|---|---|---|---|---|
| **AWS (recommended)** | me-central-1 (UAE) | RDS for MySQL 8 | KMS + Secrets Manager | S3 | Widest service set; check each service is listed for me-central-1 before relying on it |
| Microsoft Azure | UAE North (Dubai), UAE Central | Azure Database for MySQL | Key Vault | Blob Storage | |
| Oracle Cloud | Dubai, Abu Dhabi | MySQL HeatWave | Vault | Object Storage | |
| Core42 / e& / du | UAE | varies | varies | varies | Local providers; confirm managed MySQL and backups |

Rule: never enable a feature that copies data to another region (cross-region backup, global CDN, global logging).

## 2. Target architecture (start small)

- **Staging**: 1 small VM (PHP 8.2-FPM + Nginx) + 1 managed MySQL. Synthetic data only, never a copy of real patients.
- **Production**: 1–2 VMs behind a load balancer with HTTPS; managed MySQL 8 Multi-AZ, encrypted at rest; daily automated snapshots kept 14 days.
- Database in a private subnet; only 80/443 public; admin access via the provider's session manager or a bastion with MFA (no password SSH).
- Uploads (from Phase 6): encrypted volume at `/var/clinic-crm/storage`; move to UAE object storage once there are 2+ app servers.
- Secrets: `/etc/clinic-crm/crm_config.php` (owner root, group www-data, mode 0640), values from the provider's secret store. PHP finds it via `CRM_CONFIG` (see `deploy/nginx_clinic_crm.conf.example`).
- Logs: `/var/log/clinic-crm/` (LOG_PATH). No third-party log/error SaaS.
- Static libraries self-hosted (Phase 1); no CDN in front of patient pages.

Folder layout on a server:
```
/var/www/clinic-crm/current        the code (web root)
/etc/clinic-crm/crm_config.php     secrets (outside the web root)
/var/clinic-crm/storage            uploads (Phase 6+)
/var/backups/clinic-crm            BACKUP_PATH
/var/log/clinic-crm                LOG_PATH
```

## 3. What I need from you [YOU]

1. The provider choice (section 1) and an account in the clinic's or company's name, with billing set up.
2. An IAM/admin user for me to work through **in your browser** (I never need passwords in chat; you sign in).
3. DNS access for `example.ae` (to point `crm.` at the new server; later the product domain for other clinics).
4. A maintenance window for the move (about 1 hour, outside clinic hours).
5. Confirmation from the clinic's DPO/lawyer that the provider and region satisfy Federal Law No. 2 of 2019 and DHA policy.
6. A UAE location for the **encrypted copy of the old production dump** (see section 7).

## 4. Build steps (with you, in order)

1. Create the VPC/network, VM(s) and managed MySQL in the UAE region; turn on encryption at rest and automated backups (same region, or a second UAE region/provider for the copy).
2. Install PHP 8.2-FPM (extensions: pdo_mysql, mbstring, openssl, curl, gd, zlib, intl, bcmath) and Nginx; enable OPcache; `expose_php=Off`, `display_errors=Off`, `session.cookie_secure=1`.
3. Install the site config from `deploy/nginx_clinic_crm.conf.example`; TLS certificate (Let's Encrypt or the provider's); HSTS on.
4. Create `/etc/clinic-crm/crm_config.php` from `config.example.php`: database host/name/user/password, `APP_ENV=production`, LOG_PATH, BACKUP_PATH, `DATA_REGION='AE'`.
5. Create a database user with rights on the CRM database only.
6. Deploy the code (docs/DEPLOY.md), run `php tools/predeploy_check.php` locally first.
7. Cron (as the PHP user): `*/5 * * * * php /var/www/clinic-crm/current/cron.php` (cron.php decides its own timings) and `0 2 * * * php /var/www/clinic-crm/current/tools/backup.php`.
8. Staging test: restore a **synthetic** dump, run the smoke test against staging, sign in, check pages.

## 5. Moving the existing clinic (the cut-over) [YOU + me]

1. Announce the maintenance window to staff.
2. On Hostinger: put the site in maintenance (stop writes), run a final dump (`php tools/backup.php` via SSH, or hPanel → Databases → Export).
3. Copy the dump straight to the UAE server (never via a laptop if it can be avoided; if it must, encrypt it).
4. `php tools/restore.php --file=… --into=<new database>` on the UAE server (the target must be empty).
5. Copy the setting rows that live in `integration_settings` (they come with the dump) and update:
   Meta webhook URL (if the host changes), Google OAuth redirect URI, the Chrome extension's CRM address.
6. Run the smoke test against the new server with a temporary hosts-file entry, then switch DNS for `crm.example.ae`.
7. Watch for 24 hours: sign-ins, Meta leads arriving, cron log, backups.

## 6. Backups and restore

- Nightly `tools/backup.php` → BACKUP_PATH (gzip + .sha256, 14 days kept) plus provider snapshots (14 daily, 12 monthly).
- A second copy goes to another UAE location/provider — never outside the UAE.
- **Restore drill every quarter**: `php tools/restore.php --file=<latest> --into=clinic_crm_restore_drill`, compare row counts, record the date and result.
- Disaster recovery onto production is done by hand: restore into a new empty database, check it, then point `DB_NAME` in crm_config.php at it. Never restore over the live database.

## 7. Decommissioning Hostinger [YOU + me]

- Keep the old host read-only for 7 days after the cut-over, then delete the database, files, `backup_*` folders and any Hostinger backups.
  Move it into encrypted UAE storage (or delete it once the UAE database is confirmed), and remove the local copy.
- Rotate every secret that ever lived on Hostinger: database password (also right after the Phase 0 deploy), Meta token, SMTP mailbox password, Google client secret.

## 8. Security baseline checklist

- [ ] HTTPS only, HSTS, TLS 1.2+
- [ ] `.htaccess` (Hostinger) / Nginx rules (UAE) block dumps, notes, code and tool folders — checked by requesting them
- [ ] Secrets only in crm_config.php outside the web root; database password rotated
- [ ] PHP errors logged, never displayed
- [ ] Database not reachable from the internet
- [ ] OS security updates automatic; fail2ban or provider equivalent
- [ ] Backups nightly, restore drill done
- [ ] Data flows reviewed (includes/data_flows.php) — nothing new leaves the UAE without approval
