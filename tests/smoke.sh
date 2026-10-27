#!/usr/bin/env bash
# End-to-end smoke test against a real web server (see tests/README.md).
#
#   tests/smoke.sh DOCROOT_DIR URL_PATH ENGINE MODE [DOMAIN] [ZIPDIR]
#
#   DOCROOT_DIR  where to install a fresh copy (inside the web server's docroot)
#   URL_PATH     its URL, e.g. http://127.0.0.1:8088/demos
#   ENGINE       sqlite | mysql   (mysql reads WPL_TEST_MYSQL_* env vars)
#   MODE         path | subdomain
#   DOMAIN       base domain for subdomain mode (requests use curl --resolve)
#   ZIPDIR       folder holding wordpress.zip and sqlite.zip, to skip downloads
set -uo pipefail
here="$(cd "$(dirname "$0")/.." && pwd)"
dir="$1"; base="$2"; engine="$3"; mode="$4"; domain="${5:-}"; zips="${6:-}"
port=$(echo "$base" | sed -E 's#^https?://[^:/]+:?([0-9]*).*#\1#'); port=${port:-80}
tmp=$(mktemp -d "${TMPDIR:-/tmp}/wplsmoke.XXXX")
pass=0; fail=0
ok()   { pass=$((pass+1)); printf '  \033[32m✓\033[0m %s\n' "$1"; }
bad()  { fail=$((fail+1)); printf '  \033[31m✗\033[0m %s\n' "$1"; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (expected $3, got $2)"; fi; }
resolve=()
site_url() { php -r 'require $argv[1]."/app/bootstrap.php"; echo Wpl\Sites::url($argv[2]);' "$dir" "$1"; }
req() { # req URL [curl args…] → prints "code"; body in $tmp/body
  local u="$1"; shift
  local r=()
  if [ "$mode" = subdomain ]; then
    local h; h=$(echo "$u" | sed -E 's#^https?://([^:/]+).*#\1#'); r=(--resolve "$h:$port:127.0.0.1")
  fi
  curl -s ${r[@]+"${r[@]}"} -b "$tmp/jar" -c "$tmp/jar" -o "$tmp/body" -w '%{http_code}' "$@" "$u"
}
php_eval() { php -r "require '$dir/app/bootstrap.php'; \$_SERVER['REMOTE_ADDR']='127.0.0.1'; $1"; }

echo "▸ install ($engine, $mode)"
"$here/tools/rig-sync.sh" "$dir" --fresh "$zips" >/dev/null
if [ "$engine" = mysql ]; then
  # Start from an empty database.
  php -r '$p=new PDO("mysql:host=".getenv("WPL_TEST_MYSQL_HOST").";port=".getenv("WPL_TEST_MYSQL_PORT").";dbname=".getenv("WPL_TEST_MYSQL_NAME"), getenv("WPL_TEST_MYSQL_USER"), getenv("WPL_TEST_MYSQL_PASS")); foreach ($p->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) $p->exec("DROP TABLE `$t`");'
fi
php "$here/tests/install-cli.php" "$dir" "$base" "$engine" "$mode" "$domain" >"$tmp/install.log" 2>&1 && ok "installer finished" || { bad "installer failed"; cat "$tmp/install.log"; exit 1; }

check "launcher page" "$(req "$base/")" 200
check "wpl-data is private" "$(req "$base/wpl-data/probe.txt")" 403
check "app/ is private" "$(req "$base/app/bootstrap.php")" 403
check "routing self-test" "$(req "$(site_url rwtest0000)/wpl-ping.php")" 200
check "unknown site → 404 page" "$(req "$(site_url zzzzzzzzzz)/")" 404

echo "▸ blueprint + template build"
setup=$(php_eval '$bp = Wpl\Blueprints::create(["name" => "Smoke", "plugins" => ["preinstall" => [["source" => "wordpress.org", "slug" => "hello-dolly", "activate" => true]]]]); echo Wpl\Sites::setupUrl($bp["template_slug"]);')
next="$setup"; built=no
for i in $(seq 1 20); do
  code=$(req "$next"); next=$(grep -o 'url=[^"]*' "$tmp/body" | head -1 | sed 's/^url=//; s/&amp;/\&/g')
  [ "$code" != 200 ] && break
  case "$next" in *wp-admin*) built=yes; break;; esac
done
check "template built over HTTP" "$built" yes
grep -q "activated hello-dolly" "$tmp/body" && ok "blueprint plugin installed + activated" || bad "blueprint plugin not activated"
tpl=$(php_eval 'echo Wpl\Blueprints::find("smoke")["template_slug"];')

# A plugin zip parked in the template's media, as WordPress leaves it on the
# "Replace current with uploaded?" screen, next to an ordinary media file.
upgrader_rows() { php_eval '$s = "'"$1"'"; $pdo = Wpl\Db::engine() === "mysql" ? Wpl\Db::pdo() : Wpl\Db::connectSqlite(Wpl\SiteDb::sqliteFile($s)); $st = $pdo->prepare("SELECT COUNT(*) FROM " . Wpl\SiteDb::prefix($s) . "postmeta WHERE meta_key = ? AND meta_value = ?"); $st->execute(["_wp_attachment_context", "upgrader"]); echo $st->fetchColumn();'; }
php_eval '$s = "'"$tpl"'"; $pdo = Wpl\Db::engine() === "mysql" ? Wpl\Db::pdo() : Wpl\Db::connectSqlite(Wpl\SiteDb::sqliteFile($s)); $p = Wpl\SiteDb::prefix($s); $d = "2026-01-01 00:00:00";
  $pdo->prepare("INSERT INTO {$p}posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, guid, post_type, post_mime_type) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute([$d, $d, "", "paid-plugin.zip", "", "private", "paid-plugin", "", "", $d, $d, "", "", "attachment", "application/zip"]);
  $id = (int) $pdo->lastInsertId();
  $pdo->prepare("INSERT INTO {$p}postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?), (?, ?, ?)")
    ->execute([$id, "_wp_attached_file", "2026/01/paid-plugin.zip", $id, "_wp_attachment_context", "upgrader"]);'
mkdir -p "$dir/wpl-uploads/$tpl/2026/01"
echo "PK paid plugin" > "$dir/wpl-uploads/$tpl/2026/01/paid-plugin.zip"
echo "ordinary media" > "$dir/wpl-uploads/$tpl/2026/01/photo.txt"

echo "▸ launch"
json=$(php_eval '$s = Wpl\Sites::launch(Wpl\Blueprints::find("smoke"), "smoke-owner", "lead@example.com", "127.0.0.1"); echo json_encode(["slug" => $s["slug"], "login" => Wpl\Sites::loginUrl($s["slug"])]);')
slug=$(echo "$json" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["slug"];')
login=$(echo "$json" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["login"];')
url=$(site_url "$slug")
[ -n "$slug" ] && ok "demo launched ($slug)" || { bad "launch failed: $json"; exit 1; }
check "plugin zip waiting in the template is not copied" "$(req "$url/wp-content/uploads/2026/01/paid-plugin.zip")" 404
check "…nor its media record" "$(upgrader_rows "$slug")" 0
check "ordinary template media is copied" "$(req "$url/wp-content/uploads/2026/01/photo.txt")" 200

check "front page" "$(req "$url/")" 200
grep -q "$url" "$tmp/body" && ok "links point at the demo" || bad "front page has no demo links"
grep -q "$tpl" "$tmp/body" && bad "front page still mentions the template slug" || ok "no template slug left in output"
check "pretty permalink" "$(req "$url/sample-page/")" 200
# The credentials a visitor is shown must work in the real login form.
pw=$(php_eval 'echo Wpl\Sites::find("'"$slug"'")["admin_password"];'); user=$(php_eval 'echo Wpl\Sites::find("'"$slug"'")["admin_user"];')
login_form() { # login_form URL USER PASS → status; fresh jar so no autologin cookie helps
  local r=(); if [ "$mode" = subdomain ]; then local h; h=$(echo "$1" | sed -E 's#^https?://([^:/]+).*#\1#'); r=(--resolve "$h:$port:127.0.0.1"); fi
  curl -s ${r[@]+"${r[@]}"} -o /dev/null -D "$tmp/lhead" -w '%{http_code}' -b 'wordpress_test_cookie=WP%20Cookie%20check' \
    --data-urlencode "log=$2" --data-urlencode "pwd=$3" -d "testcookie=1&redirect_to=$1/wp-admin/" "$1/wp-login.php"
}
check "shown credentials log in ($user)" "$(login_form "$url" "$user" "$pw")" 302
grep -qi "^location: .*wp-admin" "$tmp/lhead" && ok "password login lands in wp-admin" || bad "password login did not reach wp-admin"
check "wrong password refused" "$(login_form "$url" "$user" "not-the-password")" 200
check "autologin redirects" "$(req "$login")" 302
check "admin dashboard" "$(req "$url/wp-admin/")" 200
grep -q 'wpl-countdown' "$tmp/body" && ok "countdown in admin bar" || bad "no countdown"
check "autologin is single-use" "$(req "$login")" 403
check "plugin installer blocked" "$(req "$url/wp-admin/plugin-install.php")" 403
check "theme editor blocked" "$(req "$url/wp-admin/theme-editor.php")" 403
check "block editor" "$(req "$url/wp-admin/post-new.php")" 200
nonce=$(req "$url/wp-admin/admin-ajax.php?action=rest-nonce" >/dev/null; cat "$tmp/body")
check "REST create post" "$(req "$url/wp-json/wp/v2/posts" -H "X-WP-Nonce: $nonce" -H 'Content-Type: application/json' -d '{"title":"Smoke post","status":"publish"}')" 201
check "new post is public" "$(req "$url/smoke-post/")" 200
php -r '$i=imagecreatetruecolor(8,8); imagepng($i,$argv[1]);' "$tmp/x.png" 2>/dev/null || printf '\x89PNG\r\n\x1a\n' > "$tmp/x.png"
check "REST upload media" "$(req "$url/wp-json/wp/v2/media" -H "X-WP-Nonce: $nonce" -H 'Content-Disposition: attachment; filename="x.png"' -H 'Content-Type: image/png' --data-binary @"$tmp/x.png")" 201
media=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["source_url"] ?? "";' "$tmp/body")
check "uploaded media is served" "$(req "$media")" 200
echo '<?php echo "x";' > "$dir/wpl-uploads/$slug/evil.php"
check "PHP in uploads is denied" "$(req "$url/wp-content/uploads/evil.php")" 403
rm -f "$dir/wpl-uploads/$slug/evil.php"
check "REST plugin install refused" "$(req "$url/wp-json/wp/v2/plugins" -H "X-WP-Nonce: $nonce" -H 'Content-Type: application/json' -d '{"slug":"akismet"}')" 403
check "template still intact" "$(req "$(site_url "$tpl")/sample-page/")" 200

echo "▸ renamed admin"
# Operator changes the blueprint's admin username after the template was built:
# the demo must still show working credentials (for the template's real admin).
json2=$(php_eval '$bp = Wpl\Blueprints::find("smoke"); $c = $bp["config"]; $c["demo"]["admin_user"] = "renamed"; Wpl\Db::update("wpl_blueprints", ["config" => json_encode($c)], "id = ?", ["smoke"]); $s = Wpl\Sites::launch(Wpl\Blueprints::find("smoke"), "smoke-owner-2", "", "127.0.0.3"); echo json_encode([$s["slug"], $s["admin_user"], $s["admin_password"]]);')
s2=$(echo "$json2" | php -r '$j = json_decode(stream_get_contents(STDIN)); echo $j[0];'); u2=$(echo "$json2" | php -r '$j = json_decode(stream_get_contents(STDIN)); echo $j[1];'); p2=$(echo "$json2" | php -r '$j = json_decode(stream_get_contents(STDIN)); echo $j[2];')
check "falls back to the real admin" "$u2" demo
check "its credentials log in" "$(login_form "$(site_url "$s2")" "$u2" "$p2")" 302
php_eval 'Wpl\Sites::delete("'"$s2"'");'

echo "▸ limits"
php_eval 'Wpl\Settings::set("max_active_per_visitor", 1); try { Wpl\Sites::launch(Wpl\Blueprints::find("smoke"), "smoke-owner", "", "127.0.0.2"); echo "launched"; } catch (Wpl\UserError $e) { echo "refused"; }' > "$tmp/limit"
check "per-visitor limit" "$(cat "$tmp/limit")" refused

echo "▸ expiry"
php_eval 'Wpl\Db::exec("UPDATE wpl_sites SET expires_at = ? WHERE slug = ?", [time() - 5, "'"$slug"'"]); Wpl\Sites::writeConfig("'"$slug"'");'
check "expired demo → 410" "$(req "$url/")" 410
php "$dir/cron.php" >/dev/null && ok "cron ran"
check "deleted demo → 404" "$(req "$url/")" 404
[ ! -d "$dir/wpl-uploads/$slug" ] && [ ! -d "$dir/wpl-data/sites/$slug" ] && ok "demo files removed" || bad "demo files left behind"
left=$(php_eval 'echo Wpl\Db::engine() === "mysql" ? count(Wpl\Db::all("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?", ["wpl_'"$slug"'_%"])) : 0;' | tr -d '[:space:]')
check "demo tables dropped" "$left" 0

rm -rf "$tmp"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
