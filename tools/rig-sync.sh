#!/usr/bin/env bash
# Copy launcher/ into a local test docroot (fresh: wipes runtime folders).
# usage: tools/rig-sync.sh DEST [--fresh] [ZIPDIR]
set -euo pipefail
here="$(cd "$(dirname "$0")/.." && pwd)"
dest="$1"; fresh="${2:-}"; zips="${3:-}"
if [ "$fresh" = "--fresh" ]; then rm -rf "$dest"; fi
mkdir -p "$dest"
rsync -a --delete --exclude '/wpl-data' --exclude '/wpl-core' --exclude '/wpl-content' --exclude '/wpl-uploads' --exclude '/.htaccess' "$here/launcher/" "$dest/"
[ -f "$dest/.htaccess" ] || cp "$here/launcher/.htaccess" "$dest/.htaccess"
if [ -n "$zips" ]; then
  mkdir -p "$dest/wpl-data"
  cp "$zips/wordpress.zip" "$dest/wpl-data/wordpress.zip"
  cp "$zips/sqlite.zip" "$dest/wpl-data/sqlite-database-integration.zip"
fi
