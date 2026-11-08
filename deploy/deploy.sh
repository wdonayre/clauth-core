#!/usr/bin/env bash
# Activates one release of Clauth. Run by server-autodeploy.sh as the site user, never by hand on a live box
# unless you mean it:
#
#   deploy.sh --base /home/thecakelabs-auth/htdocs/auth.thecakelabs.com --tag v0.1.0 --archive incoming/clauth-v0.1.0.tar.gz
#   deploy.sh --base … --tag v0.1.0            # re-activate a release that's already on the server (rollback)
#   deploy.sh --base … --list                  # what's there, * = live
#
# Layout under --base:
#   releases/<tag>/   one unpacked build each (the last 5 are kept)
#   shared/.env       the only configuration; symlinked into each release
#   shared/storage/   uploads, logs, sessions: survives every release
#   current           symlink to the live release; nginx serves current/public
set -Eeuo pipefail

BASE=""; TAG=""; ARCHIVE=""; LIST=0
KEEP_RELEASES="${KEEP_RELEASES:-5}"
PHP_BIN="${PHP_BIN:-$(command -v php8.5 || command -v php)}"

while [ $# -gt 0 ]; do
  case "$1" in
    --base)    BASE="$2"; shift 2 ;;
    --tag)     TAG="$2"; shift 2 ;;
    --archive) ARCHIVE="$2"; shift 2 ;;
    --list)    LIST=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

log()  { printf '[deploy] %s\n' "$*"; }
fail() { printf '[deploy] ERROR: %s\n' "$*" >&2; exit 1; }

[ -n "$BASE" ] || fail "--base is required"
RELEASES="$BASE/releases"; SHARED="$BASE/shared"; CURRENT="$BASE/current"
mkdir -p "$RELEASES" "$SHARED/storage" "$BASE/incoming"

if [ "$LIST" = 1 ]; then
  live="$(readlink -f "$CURRENT" 2>/dev/null || true)"
  for d in "$RELEASES"/*/; do
    d="${d%/}"; mark=" "; [ "$(readlink -f "$d")" = "$live" ] && mark="*"
    printf ' %s %s  (%s)\n' "$mark" "$(basename "$d")" "$(date -r "$d" '+%Y-%m-%d %H:%M')"
  done
  exit 0
fi

[ -n "$TAG" ] || fail "--tag is required"
[[ "$TAG" =~ ^[A-Za-z0-9._-]+$ ]] || fail "tag contains unexpected characters: $TAG"
RELEASE="$RELEASES/$TAG"

if [ -n "$ARCHIVE" ]; then
  [ -f "$ARCHIVE" ] || fail "archive not found: $ARCHIVE"
  [ -d "$RELEASE" ] && { log "release $TAG already exists; replacing it"; rm -rf "$RELEASE"; }
  mkdir -p "$RELEASE"
  log "unpacking $(basename "$ARCHIVE") → $RELEASE"
  tar -xzf "$ARCHIVE" -C "$RELEASE"
  rm -f "$ARCHIVE"
else
  [ -d "$RELEASE" ] || fail "release $TAG is not on the server; see --list"
  log "re-activating release $TAG"
fi
[ -f "$RELEASE/artisan" ] || fail "$RELEASE has no artisan: not a Laravel build"
[ -f "$SHARED/.env" ] || fail "$SHARED/.env is missing (see deploy/README.md)"

ln -sfn "$SHARED/.env" "$RELEASE/.env"

if [ -d "$RELEASE/storage" ] && [ ! -L "$RELEASE/storage" ]; then
  (cd "$RELEASE/storage" && find . -type d -exec mkdir -p "$SHARED/storage/{}" \;)
  rm -rf "$RELEASE/storage"
fi
ln -sfn "$SHARED/storage" "$RELEASE/storage"
mkdir -p "$SHARED/storage/app/public" "$SHARED/storage/framework/"{cache/data,sessions,views} "$SHARED/storage/logs" "$RELEASE/bootstrap/cache"
chmod -R ug+rwX "$SHARED/storage" "$RELEASE/bootstrap/cache" 2>/dev/null || true

artisan() { "$PHP_BIN" "$RELEASE/artisan" "$@" --no-interaction; }

log "rebuilding caches"
rm -f "$RELEASE"/bootstrap/cache/*.php
artisan package:discover >/dev/null
artisan config:clear >/dev/null
artisan storage:link --force >/dev/null 2>&1 || true
# Migrations first: the cache lives in the database, and on a first release its table doesn't exist yet.
log "running migrations"
artisan migrate --force
# OAuth needs Passport key files (unused for signing: Clauth signs with its DB keys) and an active signing key.
[ -f "$SHARED/storage/oauth-private.key" ] || artisan passport:keys
chmod 600 "$SHARED"/storage/oauth-*.key
artisan clauth:keys init >/dev/null
artisan optimize:clear >/dev/null
artisan optimize

PREVIOUS="$(readlink -f "$CURRENT" 2>/dev/null || true)"
ln -sfn "$RELEASE" "$CURRENT.tmp"
mv -Tf "$CURRENT.tmp" "$CURRENT"
log "current → $TAG"


mapfile -t old < <(ls -1dt "$RELEASES"/*/ 2>/dev/null | sed 's#/$##' | tail -n +$((KEEP_RELEASES + 1)))
for d in "${old[@]:-}"; do
  [ -z "$d" ] && continue
  real="$(readlink -f "$d")"
  [ "$real" = "$(readlink -f "$CURRENT")" ] || [ "$real" = "$PREVIOUS" ] && continue
  log "pruning $(basename "$d")"; rm -rf "$d"
done
log "done: $TAG is live$( [ -n "$PREVIOUS" ] && printf ' (previous: %s)' "$(basename "$PREVIOUS")" )"
