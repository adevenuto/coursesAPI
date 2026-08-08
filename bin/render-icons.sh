#!/usr/bin/env bash
#
# Render the GCA app icon set from resources/icons/*.svg into public/.
#
# Headless Chrome rasterises each master SVG once at 1024px, then GD downsamples
# to every target size. Chrome is not asked for the small sizes directly because
# it enforces a minimum window width (~500px on macOS) and would silently return
# a larger canvas than requested.
#
# The outputs are committed: nothing regenerates them on deploy.
#
# Usage:  bin/render-icons.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/resources/icons"
OUT="$ROOT/public"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

CHROME="${CHROME_BIN:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"
[ -x "$CHROME" ] || { echo "error: Chrome not found at $CHROME — set CHROME_BIN"; exit 1; }

# Rasterise one SVG to a 1024x1024 PNG.
render() {
    local svg="$1" png="$2"

    cat > "$TMP/page.html" <<HTML
<!doctype html><html><head><meta charset="utf-8">
<style>
  html,body{margin:0;padding:0;width:1024px;height:1024px;overflow:hidden}
  svg{display:block;width:1024px;height:1024px}
</style></head><body>
$(cat "$svg")
</body></html>
HTML

    # default-background-color=00000000 keeps the area outside the rounded
    # corners transparent. Chrome otherwise composites onto white, which shows
    # as white notches on a dark browser tab strip.
    "$CHROME" \
        --headless \
        --disable-gpu \
        --hide-scrollbars \
        --force-device-scale-factor=1 \
        --default-background-color=00000000 \
        --window-size=1024,1024 \
        --virtual-time-budget=10000 \
        --screenshot="$png" \
        "file://$TMP/page.html" 2>/dev/null
}

echo "==> Rasterising masters at 1024px"
render "$SRC/icon.svg" "$TMP/master.png"
render "$SRC/icon-opaque.svg" "$TMP/master-opaque.png"
render "$SRC/icon-maskable.svg" "$TMP/master-maskable.png"

for f in "$TMP/master.png" "$TMP/master-opaque.png" "$TMP/master-maskable.png"; do
    [ -s "$f" ] || { echo "error: Chrome produced no output for $(basename "$f")"; exit 1; }
done

echo "==> Downsampling"
php "$SRC/resize.php" "$TMP/master.png" "$TMP/master-opaque.png" "$TMP/master-maskable.png" "$OUT"

echo "==> Copying favicon.svg"
# Strip the leading comment so the served file stays lean.
sed '/^<!--/,/-->$/d' "$SRC/icon.svg" > "$OUT/favicon.svg"

echo
echo "==> Done. Generated in public/:"
for f in favicon.svg favicon.ico apple-touch-icon.png icon-192.png icon-512.png icon-maskable-512.png; do
    printf '    %-24s %s\n' "$f" "$(du -h "$OUT/$f" | cut -f1)"
done
echo "    Commit them — the deploy never regenerates them."
