#!/usr/bin/env bash
# Custom sign-in domains on h1 (CloudPanel), ONE CERTIFICATE PER DOMAIN. Cron as root, every minute (/etc/cron.d/clauth).
# Replaces domains.sh (shared SAN certificate) from Clauth v0.9.0.
#
#  1. CloudPanel's PHP backend block (port 8080) answers for every domain whose DNS is verified (vhost.py).
#  2. A domain that reached Clauth gets its own Let's Encrypt certificate (certbot, HTTP-01 webroot = the app's public
#     dir, served by CloudPanel's front block until the domain has its own block).
#  3. Once its certificate exists, the domain gets its own front block /etc/nginx/sites-enabled/clauth-domain-<host>.conf
#     (80 + 443, its certificate, proxying to the backend) and leaves CloudPanel's front block.
#  4. Domains Clauth no longer wants lose their block. One failing domain never touches another, nor auth.thecakelabs.com.
# Renewal: certbot.timer (renewal-hooks/deploy reloads nginx). Log: shared/storage/logs/domains.log
set -Eeuo pipefail

APP_HOST="auth.thecakelabs.com"
SITE_USER="thecakelabs-auth"
BASE="/home/${SITE_USER}/htdocs/${APP_HOST}"
CUR="$BASE/current"
WEBROOT="$CUR/public"                      # stable path (symlink), the same in both phases
VHOST="/etc/nginx/sites-enabled/${APP_HOST}.conf"
CLPDB="/home/clp/htdocs/app/data/db.sq3"
CONF_DIR="/etc/nginx/sites-enabled"
PREFIX="clauth-domain-"
LIVE="/etc/letsencrypt/live"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG="$BASE/shared/storage/logs/domains.log"
STATE="$BASE/shared/.domains-state"; mkdir -p "$STATE"

exec 9>"$BASE/shared/.domains.lock"; flock -n 9 || exit 0
[ -f "$CUR/artisan" ] || exit 0
log() { printf '%s [domains] %s\n' "$(date -u '+%Y-%m-%d %H:%M:%S')" "$*" >>"$LOG"; }
export CLAUTH_PERHOST_UPSTREAM="http://127.0.0.1:8080" CLAUTH_ACME_ROOT="$WEBROOT" CLAUTH_CERT_DIR="$LIVE"
art() { sudo -u "$SITE_USER" -H --preserve-env=CLAUTH_PERHOST_UPSTREAM,CLAUTH_ACME_ROOT,CLAUTH_CERT_DIR php8.5 "$CUR/artisan" "$@" 2>>"$LOG"; }
clean() { (tr -d '\r' | grep -E '^[a-z0-9.-]+$' || true) | sort -u; }
has_cert() { [ -s "$LIVE/$1/fullchain.pem" ]; }

want="$( (art clauth:domains hosts || true) | clean)"
certs="$( (art clauth:domains cert-hosts || true) | clean)"
own=""; for h in $want; do has_cert "$h" && own="$own,$h"; done
changed=0
backup() { [ -f "$1" ] && cp "$1" "$STATE/$(basename "$1").bak"; return 0; }

sync() {
  # CloudPanel vhost: backend knows every wanted host; the front block only those without their own certificate.
  backup "$VHOST"
  # shellcheck disable=SC2086
  [ "$(python3 "$HERE/vhost.py" "$VHOST" "$CLPDB" "$APP_HOST" --own-cert="${own#,}" $want)" = "changed" ] && changed=1
  for h in $want; do
    has_cert "$h" || continue
    f="$CONF_DIR/$PREFIX$h.conf"; tmp="$(mktemp)"
    if art clauth:domains nginx-conf "$h" --tls >"$tmp" && ! cmp -s "$tmp" "$f" 2>/dev/null; then
      backup "$f"; mv "$tmp" "$f"; chmod 644 "$f"; changed=1
    else
      rm -f "$tmp"
    fi
  done
  for f in "$CONF_DIR/$PREFIX"*.conf; do
    [ -e "$f" ] || continue
    h="$(basename "$f" .conf)"; h="${h#"$PREFIX"}"
    grep -qx "$h" <<<"$want" || { backup "$f"; rm -f "$f"; changed=1; log "removed block for $h"; }
  done
  if [ "$changed" = 1 ]; then
    if nginx -t >/dev/null 2>&1; then
      systemctl reload nginx && log "nginx updated (own certificates: ${own#,})"
    else
      log "nginx -t failed; restoring previous files"
      for b in "$STATE"/*.bak; do
        [ -e "$b" ] || continue
        n="$(basename "$b" .bak)"
        if [ "$n" = "$(basename "$VHOST")" ]; then cp "$b" "$VHOST"; else cp "$b" "$CONF_DIR/$n"; fi
      done
      for f in "$CONF_DIR/$PREFIX"*.conf; do [ -e "$f" ] && [ ! -e "$STATE/$(basename "$f").bak" ] && rm -f "$f"; done
      nginx -t >/dev/null 2>&1 && systemctl reload nginx
    fi
    changed=0
  fi
  rm -f "$STATE"/*.bak
}

sync

# Certificates, one host at a time, each retried at most hourly.
for h in $certs; do
  has_cert "$h" && continue
  stamp="$STATE/$h.attempt"
  if [ -f "$stamp" ] && [ $(( $(date +%s) - $(cat "$stamp") )) -lt 3600 ]; then continue; fi
  date +%s >"$stamp"
  log "requesting certificate for $h"
  if out="$(certbot certonly --webroot -w "$WEBROOT" -d "$h" --non-interactive --agree-tos --register-unsafely-without-email --keep-until-expiring 2>&1)"; then
    log "certificate ok for $h"
    own="$own,$h"; sync
    art clauth:domains cert-result "$h" --ok >/dev/null || true
  else
    msg="$(tail -n 3 <<<"$out" | tr '\n' ' ')"
    log "certificate FAILED for $h: $msg"
    art clauth:domains cert-result "$h" --failed="$msg" >/dev/null || true
  fi
done
