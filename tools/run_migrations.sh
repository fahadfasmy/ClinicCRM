#!/bin/sh
# tools/run_migrations.sh — for hosting without a command line (hPanel cron): sets up the encryption keys if missing,
# backs up the database, then applies
# pending migrations. Output goes to logs/migrate.log next to the web root. Once the newest migration is applied it
# leaves a marker file and does nothing on later runs, so a once-a-minute cron job can safely run it until deleted.
#   cron command: /bin/sh /home/<account>/domains/<site>/public_html/tools/run_migrations.sh
cd "$(dirname "$0")/.." || exit 1
LATEST=$(basename "$(ls migrations/tenant/*.sql | tail -n 1)" .sql)
MARK="../logs/migrated_$LATEST"
[ -f "$MARK" ] && exit 0
mkdir -p ../logs
{ echo "== $(date) =="; /usr/bin/php -v | head -n 1; /usr/bin/php tools/ensure_keys.php && /usr/bin/php tools/backup.php && /usr/bin/php tools/migrate.php --yes && touch "$MARK"; } >> ../logs/migrate.log 2>&1
