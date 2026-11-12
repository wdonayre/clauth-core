# Deploying Clauth

Live at **https://auth.thecakelabs.com** on h1.gelomedia.com (CloudPanel, `ssh root@h1.gelomedia.com -p 22022`),
site user `thecakelabs-auth`, PHP 8.5. Same pull-based pattern as Alab Studio.

## Releasing

Push a version tag. The server checks GitHub every minute and installs the highest `v*` tag:

```bash
git tag v0.2.0 && git push origin v0.2.0
```

Pushing to `main` alone deploys nothing. Log: `shared/storage/logs/autodeploy.log`, ending with
`live: v0.2.0 (health /up → HTTP 200)`. Each release: composer install, DB dump to `backups/`, unpack to
`releases/<tag>`, migrate, switch `current`, reload PHP-FPM. A failed step leaves the previous release live.

## Rolling back

```bash
sudo -u thecakelabs-auth bash /root/clauth-deploy/deploy.sh --base /home/thecakelabs-auth/htdocs/auth.thecakelabs.com --list
sudo -u thecakelabs-auth bash /root/clauth-deploy/deploy.sh --base /home/thecakelabs-auth/htdocs/auth.thecakelabs.com --tag v0.1.0
```

Migrations aren't undone: restore the matching `backups/*.sql.gz` if the schema changed. Pause releases with
`ENABLED=0` in `shared/autodeploy.conf`.

## Layout on the server

```
/home/thecakelabs-auth/htdocs/auth.thecakelabs.com/
  current -> releases/<tag>     nginx root is current/public
  releases/<tag>/               last 5 kept
  shared/.env                   the only config (not in git). APP_KEY here decrypts the token signing keys.
  shared/storage/               logs, sessions, uploaded app logos, Passport key files
  backups/                      DB dumps before each release + nightly DB and .env (30 days, root only)
/root/clauth-deploy/            server-autodeploy.sh, deploy.sh, backup.sh (copied from this folder)
/root/.ssh/clauth-github-deploy read-only deploy key on wdonayre/clauth
/etc/cron.d/clauth              autodeploy (every minute), Laravel scheduler, nightly backup
```

If you change a script here, copy it to `/root/clauth-deploy/` on the server too: cron runs that copy.

## Custom sign-in domains (apps' own addresses, e.g. auth.cebpos.com)

`/root/clauth-deploy/domains.sh` (+ `vhost.py`) runs every minute as root. It asks Clauth which hosts are verified
(`artisan clauth:domains hosts`) and keeps both `server_name` lines of the vhost **and** CloudPanel's stored
`site.vhost_template` in sync, then reissues the Let's Encrypt certificate with the wanted SANs
(`clpctl lets-encrypt:install:certificate --domainName=auth.thecakelabs.com --subjectAlternativeName=…`) whenever the
set changes (at most hourly per set). Log: `shared/storage/logs/domains.log`. Domains broken for over a week drop out of
the certificate so they can't block renewing auth.thecakelabs.com itself. The session cookie is host-only
(`SESSION_DOMAIN=` empty, `SESSION_COOKIE=clauth_sid`) — required for app domains.

**Losing APP_KEY or the database logs everyone out of every app.** Keep an off-box copy of the newest
`backups/clauth-env-*.tar.gz` and `clauth-nightly-*.sql.gz` (SPEC §10.1).

## Owners

Never put a password in a file or terminal. Create an owner with a one-time setup link (password + 2-step):

```bash
cd /home/thecakelabs-auth/htdocs/auth.thecakelabs.com/current && sudo -u thecakelabs-auth php8.5 artisan clauth:owner you@example.com --name="Your Name"
```

Other admin commands: `clauth:seed-orgs`, `clauth:keys status|stage|activate --kid=…`.
