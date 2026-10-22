# Security policy

ClinicCRM holds patient health data, so please report vulnerabilities privately:
use GitHub's **Report a vulnerability** button on this repository's Security tab. Do not open a public issue.

Please include what is affected, how to reproduce it, and the impact. We aim to reply within 7 days.

## For people running it
- Keep `crm_config.php` outside the web root; never commit it, `includes/db.local.php` or database dumps.
- Change the default admin password and turn on 2-step sign-in for admins.
- Use HTTPS, keep PHP and the database updated, and run the nightly backup and a restore drill every 3 months.
