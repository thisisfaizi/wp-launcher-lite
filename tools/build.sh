#!/usr/bin/env bash
# Build the release zip: the contents of launcher/ at the zip root, ready to
# extract into e.g. public_html/demos/.
set -euo pipefail
here="$(cd "$(dirname "$0")/.." && pwd)"
version=$(sed -n "s/^define('WPL_VERSION', '\([^']*\)');/\1/p" "$here/launcher/app/bootstrap.php")
out="$here/dist/wp-launcher-lite-$version.zip"
mkdir -p "$here/dist"
rm -f "$out"
for f in $(find "$here/launcher" -name '*.php'); do php -l "$f" >/dev/null || { echo "syntax error in $f"; exit 1; }; done
cd "$here/launcher"
zip -qr "$out" . -x 'wpl-data/*' 'wpl-core/*' 'wpl-content/*' 'wpl-uploads/*' '*.DS_Store'
cd "$here"
zip -qj "$out" LICENSE
echo "$out ($(du -h "$out" | cut -f1), $(unzip -l "$out" | tail -1 | awk '{print $2}') files)"
