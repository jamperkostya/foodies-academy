#!/bin/bash
# Dumps the local WordPress database of the site (MAMP, foodies_dev after site:prepare-db) for the server:
#   deploy/hestia/export-site-db.sh            → backups/jamper_foodies_site-YYYYMMDD-HHMM.sql.gz
# Then (see README.md, step 5):
#   scp -P PORT backups/jamper_foodies_site-….sql.gz jamper@SERVER:~/
#   gunzip -c ~/jamper_foodies_site-….sql.gz | mysql -u jamper_foodies_site -p jamper_foodies_site
set -euo pipefail

MYSQLDUMP="${MYSQLDUMP:-/Applications/MAMP/Library/bin/mysqldump}"
DB="${SITE_DB_DATABASE:-foodies_dev}"
OUT="$(cd "$(dirname "$0")/../.." && pwd)/backups/jamper_foodies_site-$(date +%Y%m%d-%H%M).sql.gz"
mkdir -p "$(dirname "$OUT")"

# --single-transaction: a consistent dump without locking the tables.
# The sed drops DEFINER clauses: the server's database user can't create objects for root@localhost.
"$MYSQLDUMP" -h "${SITE_DB_HOST:-127.0.0.1}" -P "${SITE_DB_PORT:-3306}" -u "${SITE_DB_USERNAME:-root}" -p"${SITE_DB_PASSWORD:-root}" \
	--single-transaction --quick --default-character-set=utf8mb4 --no-tablespaces --skip-triggers \
	"$DB" \
	| sed -E 's/DEFINER=`[^`]+`@`[^`]+`//g' \
	| gzip > "$OUT"

echo "$OUT ($(du -h "$OUT" | cut -f1))"
