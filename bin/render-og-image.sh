#!/usr/bin/env bash
#
# Render resources/og-image/card.html to public/og-image.png (1200x630), the
# site-wide Open Graph / Twitter share image.
#
# Headless Chrome is used because the card's typefaces (Sora, JetBrains Mono)
# come from Google Fonts rather than the system font book — other renderers
# substitute them silently. This needs network access to fetch them.
#
# The PNG is committed: the deploy rsyncs the repo with --delete and never runs
# this script, so an unversioned file would disappear on the next deploy.
#
# Usage:  bin/render-og-image.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/resources/og-image/card.html"
OUT="$ROOT/public/og-image.png"

CHROME="${CHROME_BIN:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"

[ -f "$SRC" ] || { echo "error: $SRC not found"; exit 1; }
[ -x "$CHROME" ] || { echo "error: Chrome not found at $CHROME — set CHROME_BIN"; exit 1; }

echo "==> Rendering $(basename "$SRC") -> public/og-image.png"

# --virtual-time-budget lets the webfonts finish loading before the capture.
"$CHROME" \
  --headless \
  --disable-gpu \
  --hide-scrollbars \
  --force-device-scale-factor=1 \
  --window-size=1200,630 \
  --virtual-time-budget=15000 \
  --screenshot="$OUT" \
  "file://$SRC" 2>/dev/null

[ -f "$OUT" ] || { echo "error: Chrome produced no output"; exit 1; }

echo "==> Done: public/og-image.png ($(du -h "$OUT" | cut -f1))"
echo "    Commit it — the deploy never regenerates it."
