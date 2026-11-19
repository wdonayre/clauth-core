#!/usr/bin/env bash
# Custom sign-in domains on h1 (cron as root, every minute — /etc/cron.d/clauth).
#  1. nginx answers for every domain whose DNS is verified (server_name in the vhost + CloudPanel template).
#  2. The Let's Encrypt certificate covers exactly the domains that reached Clauth (SANs on the site's cert).
# Clauth (as the site user) decides which hosts: `artisan clauth:domains hosts|cert-hosts`. Log: shared/storage/logs/domains.log
set -Eeuo pipefail

# Server settings: /etc/clauth/deploy.env (shell syntax) — APP_HOST, SITE_USER and optionally BASE. See deploy/README.md.
[ -f /etc/clauth/deploy.env ] && . /etc/clauth/deploy.env
APP_HOST="${APP_HOST:-auth.example.com}"
SITE_USER="${SITE_USER:-clauth}"
BASE="${BASE:-/home/${SITE_USER}/htdocs/${APP_HOST}}"
CUR="$BASE/current"
VHOST="/etc/nginx/sites-enabled/${APP_HOST}.conf"
CLPDB="/home/clp/htdocs/app/data/db.sq3"
CERT="/etc/nginx/ssl-certificates/${APP_HOST}.crt"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG="$BASE/shared/storage/logs/domains.log"
STATE="$BASE/shared/.domains-cert-attempt"

exec 9>"$BASE/shared/.domains.lock"; flock -n 9 || exit 0
[ -f "$CUR/artisan" ] || exit 0
log() { printf '%s [domains] %s\n' "$(date -u '+%Y-%m-%d %H:%M:%S')" "$*" >>"$LOG"; }
art() { sudo -u "$SITE_USER" -H php8.5 "$CUR/artisan" "$@" 2>>"$LOG"; }
clean() { (tr -d '\r' | grep -E '^[a-z0-9.-]+$' || true) | sort -u; }

sync_vhost() {
  local want; want="$( (art clauth:domains hosts || true) | clean | tr '\n' ' ')"
  # shellcheck disable=SC2086
  if [ "$(python3 "$HERE/vhost.py" "$VHOST" "$CLPDB" "$APP_HOST" $want)" = "changed" ]; then
    if nginx -t >/dev/null 2>&1; then
      systemctl reload nginx && log "nginx now answers for: $APP_HOST $want"
    else
      cp "$VHOST.bak-domains" "$VHOST"; log "nginx -t failed after adding [$want]; reverted"
    fi
  fi
}

sync_vhost

# Certificate: reissue when the wanted SAN set differs from what the cert has (added or removed domains).
want_cert="$( (art clauth:domains cert-hosts || true) | clean)"
have_cert="$( (openssl x509 -in "$CERT" -noout -ext subjectAltName 2>/dev/null | grep -o 'DNS:[^,]*' | cut -c5- | grep -v "^${APP_HOST}$" || true) | clean)"
[ "$want_cert" = "$have_cert" ] && exit 0

key="$(printf '%s' "$want_cert" | sha1sum | cut -c1-12)"
read -r last_key last_at < "$STATE" 2>/dev/null || true
if [ "${last_key:-}" = "$key" ] && [ $(( $(date +%s) - ${last_at:-0} )) -lt 3600 ]; then exit 0; fi   # retry at most hourly
echo "$key $(date +%s)" > "$STATE"

san="$(printf '%s' "$want_cert" | paste -sd, -)"
log "requesting certificate for $APP_HOST${san:+ + $san}"
if out="$(clpctl lets-encrypt:install:certificate --domainName="$APP_HOST" ${san:+--subjectAlternativeName="$san"} 2>&1)"; then
  log "certificate ok: $out"
  sync_vhost                                   # CloudPanel may regenerate the vhost from its template
  systemctl reload nginx || true
  art clauth:domains cert-result --ok >/dev/null || true
else
  log "certificate FAILED: $out"
  art clauth:domains cert-result --failed="$out" >/dev/null || true
fi
