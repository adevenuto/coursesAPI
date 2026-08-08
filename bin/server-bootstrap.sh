#!/usr/bin/env bash
#
# Run once on the Hostinger server after the code + .env are in place.
# Safe to re-run on every deploy (it's idempotent).
#
# Hostinger's default SSH `php` may not be 8.3 — pass the right binary if needed:
#   PHP_BIN=/usr/bin/php8.3 bin/server-bootstrap.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PHP="${PHP_BIN:-php}"

[ -f .env ] || { echo "error: .env missing — copy .env.production.example to .env and fill it in first"; exit 1; }

echo "==> PHP: $("$PHP" -v | head -1)"

# Purge the stale bootstrap caches before touching artisan.
#
# Composer runs on the build machine, and bootstrap/cache/ is excluded from the
# deploy, so the server can be left holding a package manifest and provider
# cache that predate a newly added dependency. Its service provider then never
# loads, and any app provider depending on it dies during boot — which takes
# every artisan command with it ("Target class [...] does not exist").
#
# These are plain file removals precisely because they must work when the app
# can no longer boot. Laravel rebuilds all three on the next boot.
echo "==> Clearing stale bootstrap caches"
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php

echo "==> Discovering packages"
"$PHP" artisan package:discover

# Generate APP_KEY only if one isn't already set.
if ! grep -qE '^APP_KEY=base64:' .env; then
  echo "==> Generating APP_KEY"
  "$PHP" artisan key:generate --force
fi

echo "==> Migrating database (--force)"
"$PHP" artisan migrate --force

echo "==> Linking public storage"
# storage:link reports an existing link as an error on stdout, not stderr.
"$PHP" artisan storage:link >/dev/null 2>&1 || echo "    (storage link already exists — skipping)"

echo "==> Caching config / routes / views"
"$PHP" artisan config:cache
# route:cache can fail on closure-based routes; don't let it abort an automated
# deploy (and strand the app in maintenance). config + view caching stay strict.
"$PHP" artisan route:cache || echo "    (route:cache skipped — routes not cacheable)"
"$PHP" artisan view:cache

echo
echo "==> Bootstrap complete."
echo "    If this is the first deploy, load the data next (see docs/DEPLOY_HOSTINGER.md, step E),"
echo "    then grant your admin user: $PHP artisan user:role you@example.com admin"
