#!/usr/bin/env bash
# Custom sign-in domains, ONE CERTIFICATE PER DOMAIN (CLAUTH_CERT_MODE=per-host). For Clauth's own server (M10), not h1:
# h1 keeps deploy/domains.sh (shared CloudPanel certificate). Run as root from cron every minute.
#
#   1. Every domain Clauth wants served gets /etc/nginx/clauth-domains/<host>.conf: port 80 only until its
#      certificate exists (ACME challenge + Clauth's ownership proof), then port 80 + 443 with its own certificate.
#   2. Domains that reached Clauth (cert-hosts) get a certificate with certbot (HTTP-01, webroot). Each host is
#      issued and reported on its own (`clauth:domains cert-result <host>`), so one failing domain blocks nothing else.
#   3. Domains Clauth no longer wants lose their conf file (certificates are left for certbot to expire).
#
# Needs: nginx with `include /etc/nginx/clauth-domains/*.conf;` in the http block, certbot, and the env vars below
# (or edit the defaults). Renewal: certbot's own timer with --deploy-hook "systemctl reload nginx".
set -Eeuo pipefail

APP_USER="${CLAUTH_APP_USER:-clauth}"
APP_DIR="${CLAUTH_APP_DIR:-/srv/clauth/current}"
PHP="${CLAUTH_PHP:-php8.5}"
CONF_DIR="/etc/nginx/clauth-domains"
ACME_ROOT="${CLAUTH_ACME_ROOT:-/var/www/clauth-acme}"
CERT_DIR="${CLAUTH_CERT_DIR:-/etc/letsencrypt/live}"
EMAIL="${CLAUTH_ACME_EMAIL:-}"            # Let's Encrypt account email (expiry notices); set it on the server
STATE_DIR="/var/lib/clauth-domains"        # per-host retry stamps
LOG="${CLAUTH_DOMAINS_LOG:-/var/log/clauth-domains.log}"

mkdir -p "$CONF_DIR" "$ACME_ROOT" "$STATE_DIR"
exec 9>"$STATE_DIR/.lock"; flock -n 9 || exit 0
[ -f "$APP_DIR/artisan" ] || exit 0
log() { printf '%s [domains] %s\n' "$(date -u '+%Y-%m-%d %H:%M:%S')" "$*" >>"$LOG"; }
art() { sudo -u "$APP_USER" -H "$PHP" "$APP_DIR/artisan" "$@" 2>>"$LOG"; }
clean() { (tr -d '\r' | grep -E '^[a-z0-9.-]+$' || true) | sort -u; }

want="$( (art clauth:domains hosts || true) | clean)"
certs="$( (art clauth:domains cert-hosts || true) | clean)"
changed=0

write_conf() {   # host, tls(0|1)
  local host="$1" tls="$2" tmp; tmp="$(mktemp)"
  if ! art clauth:domains nginx-conf "$host" $([ "$tls" = 1 ] && echo --tls) >"$tmp"; then rm -f "$tmp"; return 1; fi
  if ! cmp -s "$tmp" "$CONF_DIR/$host.conf" 2>/dev/null; then
    [ -f "$CONF_DIR/$host.conf" ] && cp "$CONF_DIR/$host.conf" "$STATE_DIR/$host.conf.bak"
    mv "$tmp" "$CONF_DIR/$host.conf"; chmod 644 "$CONF_DIR/$host.conf"; changed=1
  else
    rm -f "$tmp"
  fi
}

reload() {
  if nginx -t >/dev/null 2>&1; then
    systemctl reload nginx
  else
    log "nginx -t failed; restoring previous files"
    for b in "$STATE_DIR"/*.conf.bak; do [ -e "$b" ] && mv "$b" "$CONF_DIR/$(basename "$b" .bak)"; done
    nginx -t >/dev/null 2>&1 && systemctl reload nginx || log "nginx still failing — check $CONF_DIR"
  fi
  rm -f "$STATE_DIR"/*.conf.bak
}

# 1. Conf files for wanted hosts (TLS block only once a certificate exists).
for host in $want; do
  if [ -s "$CERT_DIR/$host/fullchain.pem" ]; then write_conf "$host" 1; else write_conf "$host" 0; fi
done
# 3. Drop hosts no longer wanted.
for f in "$CONF_DIR"/*.conf; do
  [ -e "$f" ] || continue
  h="$(basename "$f" .conf)"
  grep -qx "$h" <<<"$want" || { rm -f "$f"; changed=1; log "removed $h"; }
done
[ "$changed" = 1 ] && reload && changed=0

# 2. Certificates, one host at a time, each retried at most hourly.
for host in $certs; do
  [ -s "$CERT_DIR/$host/fullchain.pem" ] && continue
  stamp="$STATE_DIR/$host.attempt"
  if [ -f "$stamp" ] && [ $(( $(date +%s) - $(cat "$stamp") )) -lt 3600 ]; then continue; fi
  date +%s >"$stamp"
  log "requesting certificate for $host"
  if out="$(certbot certonly --webroot -w "$ACME_ROOT" -d "$host" --non-interactive --agree-tos \
      ${EMAIL:+--email "$EMAIL"} ${EMAIL:---register-unsafely-without-email} --keep-until-expiring 2>&1)"; then
    log "certificate ok for $host"
    write_conf "$host" 1
    reload
    art clauth:domains cert-result "$host" --ok >/dev/null || true
  else
    log "certificate FAILED for $host: $(tail -n 3 <<<"$out" | tr '\n' ' ')"
    art clauth:domains cert-result "$host" --failed="$(tail -n 3 <<<"$out" | tr '\n' ' ')" >/dev/null || true
  fi
done
