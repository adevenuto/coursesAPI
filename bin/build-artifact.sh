#!/usr/bin/env bash
#
# Build a production-ready deploy artifact for Hostinger.
#
# Shared hosting has no Node, so the Vite build must happen here. This produces
# dist/coursesApi-deploy.tar.gz containing prod `vendor/` (no-dev) and the compiled
# `public/build/`, ready to upload + extract on the server.
#
# The build runs in a temp copy of the project, so your working tree and your dev
# `vendor/`/`node_modules/` are left untouched.
#
# Usage:  bin/build-artifact.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

command -v composer >/dev/null || { echo "error: composer not found on PATH"; exit 1; }
command -v npm >/dev/null      || { echo "error: npm not found on PATH"; exit 1; }
command -v rsync >/dev/null    || { echo "error: rsync not found on PATH"; exit 1; }

BUILD_DIR="$(mktemp -d)"
trap 'rm -rf "$BUILD_DIR"' EXIT

echo "==> Staging a clean copy into $BUILD_DIR"
rsync -a \
  --exclude '.git' \
  --exclude 'node_modules' \
  --exclude 'vendor' \
  --exclude 'dist' \
  --exclude 'tests' \
  --exclude '.env' \
  --exclude '.env.local' \
  --exclude 'public/hot' \
  --exclude 'public/fonts-manifest.dev.json' \
  --exclude '.phpunit.result.cache' \
  --exclude '.phpunit.cache' \
  --exclude '.claude' \
  --exclude 'storage/app/import_data' \
  --exclude 'storage/logs/*.log' \
  --exclude 'storage/inertia-devtools' \
  ./ "$BUILD_DIR/"

cd "$BUILD_DIR"

echo "==> composer install (no-dev, optimized)"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress

echo "==> npm ci && npm run build"
npm ci
npm run build

if [ ! -f public/build/manifest.json ]; then
  echo "error: public/build/manifest.json missing — the Vite build did not produce assets"; exit 1
fi

# node_modules is a build-only dependency; keep the compiled public/build/ only.
rm -rf node_modules

echo "==> Packaging tarball"
mkdir -p "$ROOT/dist"
ARTIFACT="$ROOT/dist/coursesApi-deploy.tar.gz"
tar -czf "$ARTIFACT" .

echo
echo "==> Done: $ARTIFACT ($(du -h "$ARTIFACT" | cut -f1))"
echo "    Next: upload it to the server, extract into the app dir, then run bin/server-bootstrap.sh"
