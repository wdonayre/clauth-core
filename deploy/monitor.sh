#!/usr/bin/env bash
# Clauth uptime check, run from ANOTHER server (s2) every minute by cron. SPEC-organizations §10.5.
# Checks the health endpoint, discovery document and a sign-in page (main + custom domains listed in CHECK_URLS).
# After 3 failures in a row it emails ALERT_TO once; when everything passes again it emails "recovered".
#
# Config: /root/.clauth-monitor.env (chmod 600)
#   CHECK_URLS="https://auth.thecakelabs.com/up https://auth.thecakelabs.com/.well-known/openid-configuration https://auth.thecakelabs.com/login https://auth.alabapparel.com/login"
#   SMTP_URL=smtp://mail.smtp2go.com:2525  SMTP_USER=…  SMTP_PASS=…  ALERT_FROM=no-reply@thecakelabs.com  ALERT_TO=…
set -uo pipefail
. /root/.clauth-monitor.env
STATE=/var/lib/clauth-monitor; mkdir -p "$STATE"
LOG=/var/log/clauth-monitor.log

failed=""
for u in $CHECK_URLS; do
  code="$(curl -s -o /dev/null -m 15 -w '%{http_code}' "$u" || echo 000)"
  [ "$code" = 200 ] || failed="$failed $u=$code"
done

count="$(cat "$STATE/fails" 2>/dev/null || echo 0)"
alerted="$(cat "$STATE/alerted" 2>/dev/null || echo 0)"

mail() {  # subject, body
  printf 'From: Clauth monitor <%s>\r\nTo: %s\r\nSubject: %s\r\nDate: %s\r\n\r\n%s\r\n' "$ALERT_FROM" "$ALERT_TO" "$1" "$(date -R)" "$2" \
    | curl -s --ssl-reqd -m 30 --url "$SMTP_URL" --user "$SMTP_USER:$SMTP_PASS" --mail-from "$ALERT_FROM" --mail-rcpt "$ALERT_TO" -T - >/dev/null 2>&1 \
    || printf '%s mail failed: %s\n' "$(date -u '+%F %T')" "$1" >>"$LOG"
}

if [ -n "$failed" ]; then
  count=$((count + 1)); echo "$count" >"$STATE/fails"
  printf '%s FAIL (%s):%s\n' "$(date -u '+%F %T')" "$count" "$failed" >>"$LOG"
  if [ "$count" -ge 3 ] && [ "$alerted" = 0 ]; then
    mail "Clauth is DOWN" "Clauth checks have failed $count minutes in a row (from $(hostname)):$failed"
    echo 1 >"$STATE/alerted"
  fi
else
  if [ "$alerted" = 1 ]; then
    mail "Clauth recovered" "All Clauth checks pass again (from $(hostname)) after $count failed minutes."
    printf '%s recovered after %s\n' "$(date -u '+%F %T')" "$count" >>"$LOG"
  fi
  echo 0 >"$STATE/fails"; echo 0 >"$STATE/alerted"
fi
