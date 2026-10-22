# Hosting in the UAE

Goal: every copy of patient data (database, uploads, backups, logs, sessions) lives and is processed in the UAE.
`DATA_REGION = 'AE'` is the default, and `php tools/residency_check.php` checks the code against `includes/data_flows.php`.

## 1. Choose a provider

| Option | UAE region | Managed MySQL | Key/secret service | Object storage | Notes |
|---|---|---|---|---|---|
| AWS | me-central-1 (UAE) | RDS for MySQL 8 | KMS + Secrets Manager | S3 | Check each service is offered in me-central-1 before relying on it |
| Microsoft Azure | UAE North (Dubai), UAE Central | Azure Database for MySQL | Key Vault | Blob Storage | |
| Oracle Cloud | Dubai, Abu Dhabi | MySQL HeatWave | Vault | Object Storage | |
| Core42 / e& / du | UAE | varies | varies | varies | Local providers; confirm managed MySQL and backups |

Rule: never enable a feature that copies data to another region (cross-region backup, global CDN, global logging).
Have the clinic's DPO or lawyer confirm the provider and region meet Federal Decree-Law No. 45 of 2021, Federal Law
No. 2 of 2019 (health data) and the health authority's policy (e.g. DHA).

## 2. Target architecture (start small)

- **Staging**: 1 small VM (PHP-FPM + Nginx) + 1 managed MySQL. Synthetic data only, never a copy of real patients.
- **Production**: 1–2 VMs behind a load balancer with HTTPS; managed MySQL 8 Multi-AZ, encrypted at rest; daily
  automated snapshots kept 14 days.
- Database in a private subnet; only 80/443 public; admin access via the provider's session manager or a bastion
  with MFA (no password SSH).
- Uploads: encrypted volume at `/var/clinic-crm/storage`; move to UAE object storage once there are 2+ app servers.
- Secrets: `/etc/clinic-crm/crm_config.php` (owner root, group www-data, mode 0640). PHP finds it via `CRM_CONFIG`
  (see `deploy/nginx_clinic_crm.conf.example`).
- Logs: `/var/log/clinic-crm/` (LOG_PATH). No third-party log or error-tracking service.
- Front-end libraries are self-hosted; no CDN in front of patient pages.

Folder layout:
```
/var/www/clinic-crm/current        the code (web root)
/etc/clinic-crm/crm_config.php     secrets (outside the web root)
/var/clinic-crm/storage            uploads (STORAGE_PATH)
/var/backups/clinic-crm            BACKUP_PATH
/var/log/clinic-crm                LOG_PATH
```

## 3. Build steps

1. Create the network, VM(s) and managed MySQL in the UAE region; turn on encryption at rest and automated backups
   (same region, or a second UAE region/provider for the copy).
2. Install PHP 8.2+ FPM (pdo_mysql, mbstring, iconv, openssl, curl, gd, zlib, intl) and Nginx; enable OPcache;
   `expose_php=Off`, `display_errors=Off`, `session.cookie_secure=1`.
3. Install the site config from `deploy/nginx_clinic_crm.conf.example`; TLS certificate; HSTS on.
4. Create `/etc/clinic-crm/crm_config.php` from `config.example.php`: database, `APP_ENV=production`, `MASTER_KEY`
   (keep a copy offline), LOG_PATH, BACKUP_PATH, STORAGE_PATH, `DATA_REGION='AE'`.
5. Create a database user with rights on the CRM database only.
6. Deploy the code ([DEPLOY.md](DEPLOY.md)) after `php tools/predeploy_check.php` passes locally.
7. Cron (as the PHP user): `*/5 * * * * php /var/www/clinic-crm/current/cron.php` (it runs the nightly backup itself).
8. Staging test: restore a **synthetic** dump, run the smoke test against staging, sign in, check pages.

## 4. Moving an existing installation from another host

1. Announce a maintenance window (about 1 hour, outside clinic hours).
2. On the old host: stop writes, take a final dump (`php tools/backup.php`, or the host's database export).
3. Copy the dump straight to the UAE server (avoid laptops; if unavoidable, encrypt it).
4. `php tools/restore.php --file=… --into=<new database>` on the UAE server (the target must be empty).
5. Update anything tied to the address: Meta webhook URL, Google OAuth redirect URI, WhatsApp webhook, the Chrome
   extension's CRM address (`whatsapp-extension/manifest.json`).
6. Run the smoke test against the new server with a temporary hosts-file entry, then switch DNS.
7. Watch for 24 hours: sign-ins, leads arriving, cron log, backups.
8. Keep the old host read-only for 7 days, then delete its database, files and backups, and rotate every secret that
   lived there (database password, Meta token, SMTP password, Google client secret).

## 5. Backups and restore

- Nightly backup to BACKUP_PATH (gzip + .sha256; daily copies for 14 days, the first of each month kept longer) plus
  provider snapshots.
- A second copy at another UAE location or provider, never outside the UAE.
- **Restore drill every quarter**: see [RESTORE_DRILL.md](RESTORE_DRILL.md).
- Disaster recovery: restore into a new empty database, check it, then point `DB_NAME` in crm_config.php at it.
  Never restore over the live database.

## 6. Security baseline checklist

- [ ] HTTPS only, HSTS, TLS 1.2+
- [ ] `.htaccess` (Apache/LiteSpeed) or Nginx rules block dumps, notes, code and tool folders: checked by requesting them
- [ ] Secrets only in crm_config.php outside the web root
- [ ] PHP errors logged, never displayed
- [ ] Database not reachable from the internet
- [ ] Automatic OS security updates; fail2ban or the provider's equivalent
- [ ] Nightly backups; restore drill done
- [ ] Data flows reviewed (Admin → Data flows & backups); nothing new leaves the UAE without approval
