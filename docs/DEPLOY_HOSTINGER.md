# Deploying to Hostinger (Premium Web Hosting / shared)

This is the runbook for the **first manual production deploy** of coursesApi to Hostinger shared
hosting. The automated CI/CD pipeline (build on GitHub Actions → ship the artifact) is a follow-up and
reuses the two scripts here.

## Why this app fits shared hosting

- Queue, cache, and session all use the **`database`** driver — **no Redis**.
- **No** scheduled tasks and **no** queued jobs — **no cron, no queue worker** required.
- The only external hook is the (optional) **Stripe webhook**, handled synchronously.

The one constraint: **shared hosting has no Node**, so the Vite front-end is built off-server (locally
via `bin/build-artifact.sh` for now, in GitHub Actions later) and uploaded.

## Requirements

| Thing | Value |
|---|---|
| PHP | **8.3+** with `pdo_mysql, mbstring, bcmath, intl` (+ standard Laravel set) |
| Database | **MySQL 8** |
| Node (build only) | 22 (local/CI — not needed on the server) |
| Composer | 2.x |

---

## A. Create the website + prep (hPanel)

1. hPanel → **Websites** → **Add website** → **Empty website** (not WordPress/builder).
2. **Stage first:** create it on a **subdomain already on this account** (e.g.
   `gca.your-existing-domain`) or Hostinger's **temporary domain** — working DNS + SSL immediately, so
   we deploy and test without touching the real (parked) domain. The real domain is attached in **F**.
3. **Advanced → SSH Access** → enable. Note the **host, port, username**.
4. **Advanced → PHP Configuration** → set **PHP 8.3**; enable `mbstring, bcmath, intl, pdo_mysql`.
   Bump `memory_limit` (e.g. 512M) if composer runs on the server.
5. **Databases → MySQL Databases** → create a **database + user**. Note **db name, user, password**
   (host is normally `localhost`).
6. **Document root:** point the site's root at the app's **`public/`** folder. Plan: deploy the app to
   `~/domains/<site>/coursesApi` and set the document root to `coursesApi/public`.
   *Fallback* (if the root can't be changed): move the contents of `public/` into `public_html` and
   edit the two `require` paths in `public_html/index.php` to point up into `../coursesApi`.

---

## B. Build the artifact (local machine)

```bash
bin/build-artifact.sh
```

Produces `dist/coursesApi-deploy.tar.gz` with prod `vendor/` (no-dev) + compiled `public/build/`.
Runs in a temp copy, so your dev `vendor/`/`node_modules/` are untouched.

---

## C. Get the code on the server

SSH in (`ssh -p <port> <user>@<host>`), then either:

**Option 1 — upload the artifact (simplest, no composer/Node on server):**
```bash
# from your local machine:
scp -P <port> dist/coursesApi-deploy.tar.gz <user>@<host>:~/domains/<site>/
# on the server:
mkdir -p ~/domains/<site>/coursesApi
tar -xzf ~/domains/<site>/coursesApi-deploy.tar.gz -C ~/domains/<site>/coursesApi
```

**Option 2 — git clone + composer on the server** (better for iterating; needs a read-only deploy key):
```bash
git clone git@github.com:<you>/coursesApi.git ~/domains/<site>/coursesApi
cd ~/domains/<site>/coursesApi
composer install --no-dev --optimize-autoloader
# then upload the locally-built public/build/ (the server can't run Vite):
# scp -P <port> -r public/build <user>@<host>:~/domains/<site>/coursesApi/public/
```

---

## D. Configure environment + bootstrap (server)

```bash
cd ~/domains/<site>/coursesApi
cp .env.production.example .env
# edit .env: set DB_DATABASE / DB_USERNAME / DB_PASSWORD and APP_URL (the staging URL for now)
nano .env

# key gen, migrate, storage:link, cache config/routes/views (idempotent):
PHP_BIN=php bin/server-bootstrap.sh
```

Ensure `storage/` and `bootstrap/cache/` are writable (usually already are):
```bash
chmod -R ug+rw storage bootstrap/cache
```

> If `php` on SSH isn't 8.3, find the right binary (`ls /usr/bin/php*` or `/opt/alt/php83/...`) and
> pass it via `PHP_BIN=...`. If `route:cache` errors, skip it — config + view cache are the important ones.

---

## E. Load the course + geo data (local dump → server import)

Your local `courses_api` DB already has the ~22k courses and the geo tables, already linked — so we
dump and import the data directly (no need to re-run the `geo:import` / `courses:import` commands).

**On your local machine** — dump *data only* for the reference tables (app tables like users/sessions
stay fresh on prod):
```bash
mysqldump --no-create-info --complete-insert --single-transaction \
  courses_api courses countries states cities regions subregions > prod_data.sql
scp -P <port> prod_data.sql <user>@<host>:~/domains/<site>/
```

**On the server** — import with FK checks off (step D already created the empty tables via migrate):
```bash
mysql -u <db_user> -p <db_name> \
  -e "SET FOREIGN_KEY_CHECKS=0; SOURCE ~/domains/<site>/prod_data.sql; SET FOREIGN_KEY_CHECKS=1;"
```

Sanity check:
```bash
mysql -u <db_user> -p <db_name> -e "SELECT COUNT(*) FROM courses; SELECT COUNT(*) FROM cities;"
```

> phpMyAdmin will time out on this size — import over SSH as above. If `mysqldump` warns about
> credentials, add `-u <local_user> -p` (and `-h 127.0.0.1` if needed).

---

## F. Cutover to the real domain

Only after the staging URL passes the checks below.

1. hPanel → add the **real domain** to this website (same document root).
2. Edit the **server** `.env`: `APP_URL=https://<real-domain>` → then `php artisan config:cache`.
   This is the value that must not stay as the `CHANGE_ME` placeholder — a wrong `APP_URL` breaks
   Stripe checkout/portal return URLs, WebAuthn, and any absolute link the app renders. The CI/CD
   pipeline never touches this file, so set it here once and every deploy re-caches it correctly.
3. At your registrar, point the domain at Hostinger — **A record** to the plan's IP (shown in hPanel),
   or switch **nameservers** to Hostinger's. The domain is parked, so there's nothing to disrupt.
4. Once DNS resolves, in hPanel issue **free SSL** (Let's Encrypt) and **force HTTPS**. Required
   before login works — `SESSION_SECURE_COOKIE=true` means cookies only send over HTTPS.

---

## G. Go-live tasks

- **Admin user:** register an account on the site, then:
  ```bash
  php artisan user:role you@example.com admin
  ```
- **Algolia (optional):** set `SCOUT_DRIVER=algolia` + `ALGOLIA_APP_ID/SECRET/SEARCH_KEY`, then
  `php artisan scout:sync-index-settings` and `scout:import` for Course/City/State/Country.
  See `docs/ALGOLIA_SETUP.md`.
- **Stripe (optional):** live keys, `php artisan stripe:sync-products --write-env`, add the webhook
  at `https://<domain>/stripe/webhook` and set `STRIPE_WEBHOOK_SECRET`. See `docs/STRIPE_SETUP.md`.
- **Google Maps (optional):** set `GOOGLE_MAPS_API_KEY` for the explorer map.

---

## Verify

1. Home page loads over HTTPS (valid cert).
2. `/explorer` → search a city → returns courses (confirms DB + geo data + built assets).
3. A course page `/courses/{id}` shows the scorecard; as admin the **Edit** link appears and the
   editor loads.
4. `GET /api/v1/courses/{id}` (with a Sanctum token) returns JSON with `scorecard` incl. `*_women`.
5. `APP_DEBUG=false` (no debug trace on a forced error); `storage/logs/laravel.log` clean.

## Automated deploys (GitHub Actions)

Deploys run from `.github/workflows/tests.yml`. On a push to **`main`**, the `ci` job runs the test
suite (`php artisan test`); if it passes, the `deploy` job builds the app on the runner
(`composer install --no-dev`, `npm run build` — the shared host has no Node) and **rsyncs the built
tree to the server over SSH**, then runs `bin/server-bootstrap.sh` remotely. The server never builds
anything and needs no git checkout.

> The gate is intentionally tests-only for now. Enabling the stricter `composer ci:check`
> (pint + phpstan + eslint + prettier + vue-tsc) is deferred until the existing lint/format debt and
> a PHPStan config crash (`DatabaseSeeder.php`, "Class mixed was not found") are cleaned up.

**Server layout:** the app is deployed **directly into `public_html`** (it is the app root), and a
small server-only **root `.htaccess` shim** rewrites web requests into `public/`. That `.htaccess`
is not in the repo, so the rsync must protect it. The rsync **excludes** `.env` / `.env.*`,
`/storage/`, `/bootstrap/cache/`, **`/.htaccess`**, and `/.well-known/`, so production secrets,
uploads, logs, the server's cached config, the rewrite shim, and SSL challenge files are never
overwritten or deleted. `--delete` removes stale hashed assets from `public/build/`. The deploy is
**in-place** (no maintenance page) — a few seconds of possible inconsistency, accepted at this
traffic.

### One-time setup

1. **Deploy key** (dedicated, not a personal key):
   ```bash
   ssh-keygen -t ed25519 -f deploy_key -N '' -C 'github-actions-deploy'
   ```
   Add the **public** key (`deploy_key.pub`) to Hostinger: hPanel → Advanced → SSH Access → Manage
   SSH keys (or append it to `~/.ssh/authorized_keys` on the server).
2. **GitHub secrets** — repo → Settings → Secrets and variables → Actions:
   | Secret | Value |
   |---|---|
   | `SSH_HOST` | server host/IP from hPanel SSH Access |
   | `SSH_PORT` | usually `65002` on Hostinger shared |
   | `SSH_USER` | your SSH username (e.g. `u123456789`) |
   | `SSH_PRIVATE_KEY` | contents of the private `deploy_key` |
   | `DEPLOY_PATH` | the `public_html` app root, e.g. `/home/<user>/domains/<primary-domain>/public_html` |
3. PHP binary: on this host the default `php` is already **8.3.30** (`php -v`), so the workflow
   uses `PHP_BIN=php`. There is no `php8.3` alias here — don't use it. If a future host's default
   differs, pin `PHP_BIN` to the versioned CloudLinux binary (`/opt/alt/php83/usr/bin/php`).

The first automated run should target the **staging** subdomain (server `.env` `APP_URL` still the
staging URL). After the real-domain cutover (§F), the same pipeline deploys to production unchanged —
attach the real domain to the **same website / document root** so the `public_html` folder (and thus
`DEPLOY_PATH`) doesn't change. The pipeline never touches the server `.env`.

After a deploy, sanity-check that the new hashed assets actually shipped:

```bash
curl -s https://<host>/build/manifest.json    # should match local public/build/manifest.json
```

### Manual fallback (if Actions is unavailable)

```bash
bin/build-artifact.sh                     # local: rebuild the tarball
# upload dist/coursesApi-deploy.tar.gz, extract over the app dir, then:
PHP_BIN=php bin/server-bootstrap.sh    # server: migrate + re-cache
```

## Troubleshooting

- **Blank page / 500:** temporarily set `APP_DEBUG=true` + `php artisan config:cache`, reload,
  read `storage/logs/laravel.log`, then set it back to `false`.
- **"Vite manifest not found":** `public/build/manifest.json` didn't get uploaded — re-upload
  `public/build/`.
- **Stale config after an `.env` change:** `php artisan config:cache` (cached config ignores live
  `.env` until you do).
- **Composer OOM on server:** use **Option 1** (bundled `vendor/`) instead of running composer there.
