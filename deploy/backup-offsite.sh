#!/usr/bin/env bash
# Off-box copy of Clauth's nightly backup (cron as root, after backup.sh). SPEC §10.1 / SPEC-organizations §10.5.
# The newest DB dump + .env (APP_KEY decrypts the signing keys) are tarred, ENCRYPTED with a passphrase that only
# lives on this server and in your password manager, then uploaded to a private Cloudflare R2 bucket.
# Remote copies older than 30 days are deleted.
#
# Needs /root/.clauth-offsite.env (chmod 600):
#   R2_ACCOUNT_ID=…  R2_ACCESS_KEY_ID=…  R2_SECRET_ACCESS_KEY=…  R2_BUCKET=clauth-backups
# and /root/.clauth-backup-passphrase (chmod 600, created by setup; copy it to the password manager).
set -Eeuo pipefail
# Server settings: /etc/clauth/deploy.env (shell syntax) — APP_HOST, SITE_USER and optionally BASE. See deploy/README.md.
[ -f /etc/clauth/deploy.env ] && . /etc/clauth/deploy.env
APP_HOST="${APP_HOST:-auth.example.com}"
SITE_USER="${SITE_USER:-clauth}"
BASE="${BASE:-/home/${SITE_USER}/htdocs/${APP_HOST}}"
OUT="$BASE/backups"
LOG="$BASE/shared/storage/logs/backup-offsite.log"
PASS="/root/.clauth-backup-passphrase"
log() { printf '%s [offsite] %s\n' "$(date -u '+%Y-%m-%d %H:%M:%S')" "$*" >>"$LOG"; }
trap 'log "FAILED (line $LINENO)"' ERR

set -a; . /root/.clauth-offsite.env; set +a
export RCLONE_CONFIG_R2_TYPE=s3 RCLONE_CONFIG_R2_PROVIDER=Cloudflare RCLONE_CONFIG_R2_ACL=private \
  RCLONE_CONFIG_R2_ACCESS_KEY_ID="$R2_ACCESS_KEY_ID" RCLONE_CONFIG_R2_SECRET_ACCESS_KEY="$R2_SECRET_ACCESS_KEY" \
  RCLONE_CONFIG_R2_ENDPOINT="https://${R2_ACCOUNT_ID}.r2.cloudflarestorage.com" RCLONE_CONFIG_R2_NO_CHECK_BUCKET=true \
  RCLONE_CONFIG_R2_NO_HEAD=true RCLONE_CONFIG=/dev/null  # R2 501s on the post-upload HEAD ?versionId (rclone 1.60)

db="$(ls -1t "$OUT"/clauth-nightly-*.sql.gz 2>/dev/null | head -n1)"
env="$(ls -1t "$OUT"/clauth-env-*.tar.gz 2>/dev/null | head -n1)"
[ -n "$db" ] && [ -n "$env" ] || { log "no local backup to copy"; exit 1; }
[ -s "$PASS" ] || { log "missing $PASS"; exit 1; }

stamp="$(basename "$db" .sql.gz | sed 's/clauth-nightly-//')"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
tar -C "$OUT" -cf "$tmp/bundle.tar" "$(basename "$db")" "$(basename "$env")"
sha256sum "$tmp/bundle.tar" | cut -d' ' -f1 > "$tmp/clauth-$stamp.sha256"
openssl enc -aes-256-cbc -pbkdf2 -iter 300000 -salt -in "$tmp/bundle.tar" -out "$tmp/clauth-$stamp.tar.enc" -pass "file:$PASS"
rclone copyto "$tmp/clauth-$stamp.tar.enc" "r2:$R2_BUCKET/clauth-$stamp.tar.enc" --s3-no-check-bucket
rclone copyto "$tmp/clauth-$stamp.sha256" "r2:$R2_BUCKET/clauth-$stamp.sha256" --s3-no-check-bucket
rclone delete "r2:$R2_BUCKET" --min-age 30d --include 'clauth-*' >/dev/null 2>&1 || true
log "uploaded clauth-$stamp.tar.enc ($(du -h "$tmp/clauth-$stamp.tar.enc" | cut -f1))"
