#!/usr/bin/env bash
# Pull-based deploy for Clauth: runs on h1 every minute (cron, as root) and installs the highest v* tag
# on GitHub when it changes. Releasing = pushing a tag:
#
#   git tag v0.2.0 && git push origin v0.2.0
#
# Pushing to main alone deploys nothing.
#
#   /etc/cron.d/clauth → bash /root/clauth-deploy/server-autodeploy.sh
#
# Optional config: $BASE/shared/autodeploy.conf (shell syntax), e.g.  ENABLED=0  to pause releases.
# State: $BASE/shared/.autodeploy-current. Log: $BASE/shared/storage/logs/autodeploy.log
# Before each release the database is dumped to $BASE/backups (last 10 kept).
set -Eeuo pipefail

# Server settings: /etc/clauth/deploy.env (shell syntax) — APP_HOST, SITE_USER and optionally BASE. See deploy/README.md.
[ -f /etc/clauth/deploy.env ] && . /etc/clauth/deploy.env
APP_HOST="${APP_HOST:-auth.example.com}"
SITE_USER="${SITE_USER:-clauth}"
BASE="${BASE:-/home/${SITE_USER}/htdocs/${APP_HOST}}"
PHP_BIN="$(command -v php8.5)"
REPO="git@github.com:wdonayre/clauth.git"
GIT_KEY="/root/.ssh/clauth-github-deploy"  # read-only deploy key on the repo
ENABLED=1
CONF="$BASE/shared/autodeploy.conf"; STATE="$BASE/shared/.autodeploy-current"
LOG="$BASE/shared/storage/logs/autodeploy.log"; LOCK="$BASE/shared/.autodeploy.lock"
[ -f "$CONF" ] && . "$CONF"
[ "$ENABLED" = 1 ] || exit 0

export GIT_SSH_COMMAND="ssh -i $GIT_KEY -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new"
mkdir -p "$(dirname "$LOG")"
log() { printf '%s [autodeploy] %s\n' "$(date -u '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$LOG"; }

exec 9>"$LOCK"; flock -n 9 || exit 0

TAG="$(git ls-remote --tags --refs "$REPO" 'refs/tags/v*' 2>/dev/null | awk '{print $2}' | sed 's#refs/tags/##' | sort -V | tail -n1 || true)"
[ -n "$TAG" ] || exit 0
SHA="$(git ls-remote --tags --refs "$REPO" "refs/tags/$TAG" | awk '{print $1}')"
[ "$(cat "$STATE" 2>/dev/null || true)" = "$TAG@$SHA" ] && exit 0

log "new release $TAG @ ${SHA:0:12}: building"
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
git init -q "$WORK/repo"
git -C "$WORK/repo" fetch -q --depth 1 "$REPO" "refs/tags/$TAG"
git -C "$WORK/repo" checkout -q FETCH_HEAD

BUILD="$WORK/build"; mkdir -p "$BUILD"
tar -C "$WORK/repo" --exclude='./.git' --exclude='./vendor' --exclude='./node_modules' --exclude='./tests' --exclude='./tools' --exclude='./.env' --exclude='./.env.*' \
    --exclude='./storage/logs/*' --exclude='./storage/framework/cache/*' --exclude='./storage/framework/sessions/*' \
    --exclude='./storage/framework/views/*' --exclude='./bootstrap/cache/*' --exclude='./database/*.sqlite*' -cf - . | tar -C "$BUILD" -xf -
chown -R "$SITE_USER":"$SITE_USER" "$WORK"
as_site() { sudo -u "$SITE_USER" -H bash -c "cd '$BUILD' && $1" >>"$LOG" 2>&1; }
as_site "$PHP_BIN /usr/local/bin/composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --quiet" \
  || { log "composer install failed for $TAG; retrying next minute"; exit 1; }
printf '%s\n' "$TAG" > "$BUILD/VERSION"; printf '%s\n' "$SHA" > "$BUILD/REVISION"

mkdir -p "$BASE/incoming" "$BASE/backups"
ARCHIVE="$BASE/incoming/clauth-${TAG}.tar.gz"
tar -C "$BUILD" -czf "$ARCHIVE" .
cp "$WORK/repo/deploy/deploy.sh" "$BASE/incoming/deploy.sh"
chown -R "$SITE_USER":"$SITE_USER" "$BASE/incoming"

# A copy of the database before migrations touch it, with the app's own login (root has none on CloudPanel's MySQL).
env_get() { grep -E "^$1=" "$BASE/shared/.env" | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DB="$(env_get DB_DATABASE)"
MYCNF="$(mktemp)"; chmod 600 "$MYCNF"
printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' "$(env_get DB_USERNAME)" "$(env_get DB_PASSWORD)" "$(env_get DB_HOST)" "$(env_get DB_PORT)" > "$MYCNF"
if [ -n "$DB" ] && mysqldump --defaults-extra-file="$MYCNF" --single-transaction --no-tablespaces "$DB" | gzip > "$BASE/backups/${DB}-before-${TAG}.sql.gz"; then
  rm -f "$MYCNF"
  ls -1t "$BASE"/backups/*.sql.gz | tail -n +11 | xargs -r rm -f
else
  rm -f "$MYCNF"; log "database backup failed; not deploying $TAG"; exit 1
fi

if sudo -u "$SITE_USER" -H bash "$BASE/incoming/deploy.sh" --base "$BASE" --tag "$TAG" --archive "$ARCHIVE" >>"$LOG" 2>&1; then
  echo "$TAG@$SHA" > "$STATE"
  systemctl reload php8.5-fpm || true
  code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "https://$APP_HOST/up" || true)"
  log "live: $TAG (health /up → HTTP $code)"
else
  log "deploy.sh failed for $TAG; the previous release stays live"; exit 1
fi
