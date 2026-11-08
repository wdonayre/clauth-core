# Clauth

Central sign-in (OpenID Connect) for The Cake Labs apps — Xpenses, Alab Studio, CebPOS, CakePricer and later ones.
Spec and decisions: `../SPEC.md` (in the Everyday Framework workspace). Production: https://auth.thecakelabs.com.

- Laravel 12 + Passport (OAuth2 core). Discovery, JWKS, ID tokens, access-token claims and key rotation are Clauth's
  own code (`app/OAuth`, `app/Services/SigningKeys.php`): RS256 keys live in the DB, encrypted with APP_KEY.
- Hosted pages are Blade with one stylesheet (`public/css/clauth.css`) and one small script — no build step.
- Admin console at `/admin` (owners and org admins, 2-step required). My account at `/account`.

## Local

```bash
composer install && cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate --seed && php artisan passport:keys
php artisan serve --port=8020                        # Clauth
php -S 127.0.0.1:8021 -t tools/demo-client           # sample app that signs in and verifies JWTs offline
php artisan test
```

The seeder creates local-only demo data (owner@clauth.test, Demo App). Deploying: `deploy/README.md`.
