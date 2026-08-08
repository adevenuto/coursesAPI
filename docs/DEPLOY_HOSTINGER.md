# Deploying to Hostinger (Premium Web Hosting / shared)

The app is **live** and deploys are **automated**: push to `main` → GitHub Actions runs the tests,
builds the app, and rsyncs it to the server. Nothing is built on the host.

- **Normal deploy** → [Automated deploys](#automated-deploys)
- **Actions is down** → [Manual fallback](#manual-fallback)
- **Rebuilding the server from scratch** → [First-time setup](#first-time-setup)

---

## How it fits shared hosting

- Queue, cache, and session all use the **`database`** driver — no Redis.
- **No scheduled tasks and no queued jobs** — no cron, no queue worker. The only external hook is
  the Stripe webhook, handled synchronously.
- Shared hosting has **no Node**, so Vite assets are built off-server (in Actions, or locally via
  `bin/build-artifact.sh`) and shipped compiled.

| | |
|---|---|
| PHP | **8.3** (host default `php` is 8.3.x — there is no `php8.3` alias) |
| Extensions | `pdo_mysql, mbstring, bcmath, intl` |
| Database | MySQL 8 |
| Node | 22 — build machines only |
| App root | `~/domains/<domain>/public_html` (see layout below) |

---

## Automated deploys

Defined in `.github/workflows/tests.yml` (workflow name: **CI**).

**Triggers:** push to `main`, or the **Run workflow** button on the Actions tab
(`workflow_dispatch`) for an on-demand redeploy. Pull requests run the `ci` job only.

**Flow:**

1. `ci` — MySQL 8 service, `php artisan test`.
2. `deploy` (only on `main`, only if `ci` passed) — `composer install --no-dev`, `npm run build`,
   asserts `public/build/manifest.json` exists, rsyncs the tree, then runs `bin/server-bootstrap.sh`
   over SSH (migrate + `storage:link` + config/route/view cache).

Deploys are serialized (`concurrency: deploy-production`) and **in-place** — no maintenance page, a
few seconds of possible inconsistency, accepted at this traffic.

> The gate is intentionally tests-only. Enabling `composer ci:check` (pint + phpstan + eslint +
> prettier + vue-tsc) is deferred until the existing lint/format debt and a PHPStan config crash
> (`DatabaseSeeder.php`, "Class mixed was not found") are cleaned up.

### Server layout

The app is deployed **directly into `public_html`** — that folder *is* the Laravel root. A
server-only root **`.htaccess` shim** (not in the repo) rewrites requests into `public/`.

The rsync uses `--delete` (to clear stale hashed assets) and therefore **excludes** everything that
lives only on the server: `.env` / `.env.*`, `/storage/`, `/bootstrap/cache/`, `/.htaccess`,
`/.well-known/`, `/public/storage`, `/public/hot`. **The pipeline never touches the server `.env`.**

### One-time setup (already done — for rebuilds)

1. Generate a dedicated deploy key and add the **public** half in hPanel → Advanced → SSH Access:
   ```bash
   ssh-keygen -t ed25519 -f deploy_key -N '' -C 'github-actions-deploy'
   ```
2. Repo → Settings → Secrets and variables → Actions:

   | Secret | Value |
   |---|---|
   | `SSH_HOST` | server host/IP from hPanel |
   | `SSH_PORT` | `65002` on Hostinger shared |
   | `SSH_USER` | e.g. `u123456789` |
   | `SSH_PRIVATE_KEY` | contents of the private `deploy_key` |
   | `DEPLOY_PATH` | `/home/<user>/domains/<domain>/public_html` |

### After a deploy

```bash
curl -s https://<domain>/build/manifest.json   # should match local public/build/manifest.json
```

---

## Manual fallback

```bash
bin/build-artifact.sh                    # local: dist/coursesApi-deploy.tar.gz (prod vendor/ + public/build/)
scp -P <port> dist/coursesApi-deploy.tar.gz <user>@<host>:~/
# on the server: extract over the app root, preserving .env / storage / .htaccess
tar -xzf ~/coursesApi-deploy.tar.gz -C ~/domains/<domain>/public_html
PHP_BIN=php bin/server-bootstrap.sh      # migrate + re-cache
```

`build-artifact.sh` runs in a temp copy, so your dev `vendor/` and `node_modules/` are untouched.

---

## First-time setup

Only needed to stand up a new server or rebuild this one.

### 1. Site + services (hPanel)

- **Websites → Add website → Empty website.** Stage on a subdomain or Hostinger's temporary domain
  first if the real domain is live.
- **Advanced → SSH Access** → enable; note host, port, user.
- **Advanced → PHP Configuration** → PHP 8.3, enable `mbstring, bcmath, intl, pdo_mysql`.
- **Databases → MySQL Databases** → create a database + user (host `localhost`).
- Document root stays `public_html`; add the root `.htaccess` shim that rewrites into `public/`.

### 2. Code + environment

Ship the code via [Manual fallback](#manual-fallback) above, then:

```bash
cd ~/domains/<domain>/public_html
cp .env.production.example .env
nano .env      # fill every CHANGE_ME
PHP_BIN=php bin/server-bootstrap.sh
chmod -R ug+rw storage bootstrap/cache
```

Required in `.env`: `APP_URL` (real https URL — a stale value breaks Stripe return URLs, WebAuthn,
and every absolute link), `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD`. `MAIL_MAILER` defaults to
`log`, so **password resets and verification emails silently go nowhere until SMTP is configured** —
uncomment the Hostinger SMTP block. `APP_KEY` is generated by the bootstrap script.

Anytime `.env` changes: `php artisan config:cache` (cached config ignores the live file).

### 3. Load courses + geo data

The local `courses_api` DB already holds the ~22k linked courses and the geo tables, so dump the
data rather than re-running the import commands. App tables (users, sessions) stay fresh on prod.

```bash
# local
mysqldump --no-create-info --complete-insert --single-transaction \
  courses_api courses countries states cities regions subregions > prod_data.sql
scp -P <port> prod_data.sql <user>@<host>:~/

# server (bootstrap already created the empty tables via migrate)
mysql -u <db_user> -p <db_name> \
  -e "SET FOREIGN_KEY_CHECKS=0; SOURCE ~/prod_data.sql; SET FOREIGN_KEY_CHECKS=1;"
mysql -u <db_user> -p <db_name> -e "SELECT COUNT(*) FROM courses; SELECT COUNT(*) FROM cities;"
```

> phpMyAdmin times out at this size — import over SSH.

### 4. Domain + SSL

Point the domain at Hostinger (A record to the plan IP, or Hostinger nameservers), attach it to the
**same website / document root** so `DEPLOY_PATH` never changes, then issue free SSL and force
HTTPS. Required before login works — `SESSION_SECURE_COOKIE=true` means cookies are HTTPS-only.
Finally set the real `APP_URL` and `php artisan config:cache`.

### 5. Integrations

| | |
|---|---|
| **Admin user** | register on the site, then `php artisan user:role you@example.com admin` |
| **Algolia** (explorer search) | set `SCOUT_DRIVER=algolia` + `ALGOLIA_*`, then `scout:sync-index-settings` and `scout:import` for Course/City/State/Country — see `docs/ALGOLIA_SETUP.md` |
| **Stripe** (billing) | live keys, `php artisan stripe:sync-products --write-env`, webhook at `https://<domain>/stripe/webhook` + `STRIPE_WEBHOOK_SECRET` — see `docs/STRIPE_SETUP.md` |
| **Google Maps** | `GOOGLE_MAPS_API_KEY` for the explorer/editor maps |

---

## Smoke test

1. Home page over HTTPS with a valid cert.
2. `/explorer` → search a city → results (confirms Algolia + DB + built assets).
3. `/courses/{id}` shows the scorecard; as admin the **Edit** link works.
4. `GET /api/v1/courses/{id}` with a Sanctum token returns `scorecard` including `*_women`.
5. `APP_DEBUG=false` (no trace on a forced error) and `storage/logs/laravel.log` is clean.

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| Blank page / 500 | temporarily `APP_DEBUG=true` + `config:cache`, read `storage/logs/laravel.log`, set back to `false` |
| "Vite manifest not found" | `public/build/manifest.json` didn't ship — re-run the deploy |
| `.env` change had no effect | `php artisan config:cache` |
| Composer OOM on the server | don't run composer there — ship the artifact with `vendor/` bundled |
| SSH logins hang / freeze | shared hosting (CloudLinux LVE) has a low process limit; kill orphans from dropped sessions — `pkill -x vim`, kill stale `bash`. Don't leave an editor open on `.env`. |
