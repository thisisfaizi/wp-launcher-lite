#!/usr/bin/env bash
# Follow a template setup URL's meta-refresh chain like a browser. usage: follow-setup.sh URL COOKIEJAR
next="$1"; jar="$2"; rm -f "$jar"
for i in $(seq 1 40); do
  start=$(date +%s)
  body=$(curl -s -b "$jar" -c "$jar" --max-time 200 -w "\n__CODE:%{http_code}" "$next"); code=${body##*__CODE:}
  echo "step $i [$code, $(( $(date +%s) - start ))s]: $(echo "$body" | grep -o 'class="sub">[^<]*' | head -1 | sed 's/class="sub">//') $(echo "$body" | grep -o '<li[^>]*>[^<]*' | tail -1 | sed 's/<li[^>]*>//')"
  next=$(echo "$body" | grep -o 'url=[^"]*' | head -1 | sed 's/^url=//; s/&amp;/\&/g')
  [ -z "$next" ] && { echo "$body" | head -c 800; exit 1; }
  case "$next" in *wp-admin*) echo "done -> $next"; exit 0;; esac
done
