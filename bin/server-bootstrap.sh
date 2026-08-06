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

# Generate APP_KEY only if one isn't already set.
if ! grep -qE '^APP_KEY=base64:' .env; then
  echo "==> Generating APP_KEY"
  "$PHP" artisan key:generate --force
fi

echo "==> Migrating database (--force)"
"$PHP" artisan migrate --force

echo "==> Linking public storage"
"$PHP" artisan storage:link 2>/dev/null || echo "    (storage link already exists — skipping)"

echo "==> Caching config / routes / views"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache

echo
echo "==> Bootstrap complete."
echo "    If this is the first deploy, load the data next (see docs/DEPLOY_HOSTINGER.md, step E),"
echo "    then grant your admin user: $PHP artisan user:role you@example.com admin"
