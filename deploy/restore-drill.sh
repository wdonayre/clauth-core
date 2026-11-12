#!/usr/bin/env bash
# Restore drill (run by hand as root, monthly): proves an off-box backup can actually bring Clauth back.
# Downloads the newest R2 copy, checks its checksum, decrypts it, and checks the database dump and .env inside.
# With --import it also loads the dump into a throwaway database (clauth_restore_drill) and counts users, apps
# and signing keys, then drops it. Nothing touches the live database.
set -Eeuo pipefail
set -a; . /root/.clauth-offsite.env; set +a
export RCLONE_CONFIG_R2_TYPE=s3 RCLONE_CONFIG_R2_PROVIDER=Cloudflare RCLONE_CONFIG_R2_ACCESS_KEY_ID="$R2_ACCESS_KEY_ID" \
  RCLONE_CONFIG_R2_SECRET_ACCESS_KEY="$R2_SECRET_ACCESS_KEY" RCLONE_CONFIG_R2_ENDPOINT="https://${R2_ACCOUNT_ID}.r2.cloudflarestorage.com"
PASS="/root/.clauth-backup-passphrase"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT

latest="$(rclone lsf "r2:$R2_BUCKET" --include 'clauth-*.tar.enc' | sort | tail -n1)"
[ -n "$latest" ] || { echo "FAIL: no backups in r2:$R2_BUCKET"; exit 1; }
echo "newest off-box backup: $latest"
rclone copyto "r2:$R2_BUCKET/$latest" "$tmp/b.enc"
rclone copyto "r2:$R2_BUCKET/${latest%.tar.enc}.sha256" "$tmp/b.sha256"
openssl enc -d -aes-256-cbc -pbkdf2 -iter 300000 -in "$tmp/b.enc" -out "$tmp/b.tar" -pass "file:$PASS"
[ "$(sha256sum "$tmp/b.tar" | cut -d' ' -f1)" = "$(cat "$tmp/b.sha256")" ] && echo "ok: checksum matches" || { echo "FAIL: checksum"; exit 1; }
tar -C "$tmp" -xf "$tmp/b.tar"
dump="$(ls "$tmp"/clauth-nightly-*.sql.gz)"; envt="$(ls "$tmp"/clauth-env-*.tar.gz)"
gzip -t "$dump" && echo "ok: dump is a valid gzip"
for t in users organizations apps signing_keys grants oauth_clients; do
  zgrep -q "CREATE TABLE \`$t\`" "$dump" && echo "ok: table $t" || { echo "FAIL: table $t missing"; exit 1; }
done
tar -xzOf "$envt" .env | grep -q '^APP_KEY=base64:' && echo "ok: .env has APP_KEY (signing keys can be decrypted)"
if [ "${1:-}" = "--import" ]; then
  mysql -e 'DROP DATABASE IF EXISTS clauth_restore_drill; CREATE DATABASE clauth_restore_drill'
  zcat "$dump" | mysql clauth_restore_drill
  mysql -N clauth_restore_drill -e "SELECT CONCAT('users=',(SELECT COUNT(*) FROM users),' orgs=',(SELECT COUNT(*) FROM organizations),' apps=',(SELECT COUNT(*) FROM apps),' keys=',(SELECT COUNT(*) FROM signing_keys))"
  mysql -e 'DROP DATABASE clauth_restore_drill'
  echo "ok: imported into a scratch database and dropped it"
fi
echo "RESTORE DRILL PASSED"
