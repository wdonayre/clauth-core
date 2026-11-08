#!/usr/bin/env bash
# Nightly Clauth backup on h1 (cron as root): DB dump + shared/.env (holds APP_KEY, which decrypts the
# signing keys). Kept 30 days in $BASE/backups. Copy off-box too (SPEC §10.1/§10.11).
set -Eeuo pipefail
BASE="/home/thecakelabs-auth/htdocs/auth.thecakelabs.com"
OUT="$BASE/backups"; mkdir -p "$OUT"; chmod 700 "$OUT"
env_get() { grep -E "^$1=" "$BASE/shared/.env" | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYCNF="$(mktemp)"; chmod 600 "$MYCNF"; trap 'rm -f "$MYCNF"' EXIT
printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' "$(env_get DB_USERNAME)" "$(env_get DB_PASSWORD)" "$(env_get DB_HOST)" "$(env_get DB_PORT)" > "$MYCNF"
STAMP="$(date -u +%Y%m%d-%H%M)"
mysqldump --defaults-extra-file="$MYCNF" --single-transaction --no-tablespaces "$(env_get DB_DATABASE)" | gzip > "$OUT/clauth-nightly-$STAMP.sql.gz"
tar -C "$BASE/shared" -czf "$OUT/clauth-env-$STAMP.tar.gz" .env
chmod 600 "$OUT"/*
find "$OUT" -name 'clauth-nightly-*' -mtime +30 -delete
find "$OUT" -name 'clauth-env-*' -mtime +30 -delete
