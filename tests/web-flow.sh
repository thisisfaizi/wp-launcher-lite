#!/usr/bin/env bash
# Drives the real web UI with form posts: browser installer → panel → blueprint
# form → visitor launch → admin actions. Path mode, SQLite.
#
#   tests/web-flow.sh DOCROOT_DIR URL_PATH [ZIPDIR]
set -uo pipefail
here="$(cd "$(dirname "$0")/.." && pwd)"
dir="$1"; base="$2"; zips="${3:-}"
tmp=$(mktemp -d "${TMPDIR:-/tmp}/wplweb.XXXX")
pass=0; fail=0
ok()  { pass=$((pass+1)); printf '  \033[32m✓\033[0m %s\n' "$1"; }
bad() { fail=$((fail+1)); printf '  \033[31m✗\033[0m %s\n' "$1"; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (expected $3, got $2)"; fi; }
has() { if grep -q -- "$2" "$tmp/body"; then ok "$1"; else bad "$1 (no \"$2\")"; fi; }
# req JAR URL [curl args] → status code; body in $tmp/body, headers in $tmp/head
req() { local jar="$1" u="$2"; shift 2; curl -s -b "$tmp/$jar" -c "$tmp/$jar" -D "$tmp/head" -o "$tmp/body" -w '%{http_code}' "$@" "$u"; }
csrf() { grep -o 'name="_csrf" value="[^"]*' "$tmp/body" | head -1 | sed 's/.*value="//'; }
loc() { grep -i '^location:' "$tmp/head" | tr -d '\r' | sed 's/^[Ll]ocation: //'; }

"$here/tools/rig-sync.sh" "$dir" --fresh "$zips" >/dev/null

echo "▸ browser installer"
check "installer page" "$(req admin "$base/")" 200
has "requirements listed" "Server check"
check "configure" "$(req admin "$base/index.php?r=install/configure" -d "base_url=$base&url_mode=path&engine=sqlite&admin_email=op@example.com&admin_password=correct-horse-battery")" 200
check "second browser locked out" "$(req stranger "$base/")" 403
state='{}'
for i in $(seq 1 30); do
  code=$(req admin "$base/index.php?r=install/step" --data-urlencode "state=$state")
  state=$(cat "$tmp/body")
  echo "$state" | grep -q '"done":true' && break
  [ "$code" != 200 ] && break
done
echo "$state" | grep -q '"done":true' && ok "WordPress installed in steps" || bad "install steps: $state"
check "finish" "$(req admin "$base/index.php?r=install/finish" -d "admin_password=correct-horse-battery")" 200
has "finish returns next step" '"next"'
check "signed in after install" "$(req admin "$base/admin")" 200

echo "▸ panel"
check "sign-in page for strangers" "$(req stranger "$base/admin")" 302
check "wrong password rejected" "$(req stranger "$base/admin/login" >/dev/null; req stranger "$base/admin/login" -d "_csrf=$(csrf)&email=op@example.com&password=nope")" 200
has "error shown" "Wrong email or password"
check "new blueprint form" "$(req admin "$base/admin/blueprints/new")" 200
tok=$(csrf)
check "create blueprint" "$(req admin "$base/admin/blueprints/new" --data-urlencode "_csrf=$tok" -d "name=Web+Flow+Demo&description=Made+by+the+form&expiration=2h&max_concurrent=5&admin_user=demo&plugins=&theme=&blocked[]=export")" 302
setup=$(loc); case "$setup" in *wpl-setup.php*) ok "redirected to template build";; *) bad "no setup redirect ($setup)";; esac
check "csrf enforced" "$(req admin "$base/admin/blueprints/new" -d "_csrf=forged&name=X")" 400
next="$setup"
for i in $(seq 1 10); do
  req admin "$next" >/dev/null
  next=$(grep -o 'url=[^"]*' "$tmp/body" | head -1 | sed 's/^url=//; s/&amp;/\&/g')
  case "$next" in *wp-admin*) break;; esac
done
case "$next" in *wp-admin*) ok "template built";; *) bad "template build stalled";; esac
check "blueprint page" "$(req admin "$base/admin/blueprints/web-flow-demo")" 200
has "shows live" "Live"
check "save blueprint" "$(req admin "$base/admin/blueprints/web-flow-demo" --data-urlencode "_csrf=$(csrf)" -d "name=Web+Flow+Demo&description=Edited&expiration=1h&max_concurrent=5&admin_user=demo&enabled=0&enabled=1&blocked[]=export")" 302
check "settings page" "$(req admin "$base/admin/settings")" 200
check "turn on email capture" "$(req admin "$base/admin/settings" --data-urlencode "_csrf=$(csrf)" -d "section=launcher&brand_name=Acme+Demos&accent=%23e11d48&launcher_title=Try+Acme&launcher_intro=Hi&logo_url=&footer_text=&email_capture=required&consent_text=")" 302
for p in admin/sites admin/leads admin/log admin/system; do check "$p" "$(req admin "$base/$p")" 200; done

echo "▸ visitor"
check "launcher page" "$(req visitor "$base/")" 200
has "brand applied" "Acme Demos"
has "card shown" "Web Flow Demo"
has "description edited" "Edited"
tok=$(csrf)
check "email required" "$(req visitor "$base/launch" -H "Referer: $base/" -d "_csrf=$tok&blueprint=web-flow-demo&email=")" 302
req visitor "$base/" >/dev/null; has "error flashed" "Please enter your email"
check "honeypot drops bots" "$(req visitor "$base/launch" -d "_csrf=$tok&blueprint=web-flow-demo&email=bot@example.com&website=spam")" 302
check "launch" "$(req visitor "$base/launch" -d "_csrf=$(req visitor "$base/" >/dev/null; csrf)&blueprint=web-flow-demo&email=lead@example.com")" 302
ready=$(loc); case "$ready" in *ready/*) ok "sent to ready page";; *) bad "no ready redirect ($ready)";; esac
slug=${ready##*/}
check "ready page" "$(req visitor "$ready")" 200
has "credentials shown" "Password"
check "someone else can't see it" "$(req stranger "$ready")" 404
check "go → autologin" "$(req visitor "$base/go/$slug")" 302
check "lands in wp-admin" "$(req visitor "$(loc)")" 302
case "$(loc)" in */wp-admin/) ok "redirected to dashboard";; *) bad "autologin went to $(loc)";; esac
req visitor "$base/" >/dev/null; has "listed under your demos" "Your demos"

echo "▸ product link (/demo/<blueprint>)"
req admin "$base/admin/blueprints/web-flow-demo" >/dev/null; has "link shown in the panel" "$base/demo/web-flow-demo"
check "product page" "$(req fresh "$base/demo/web-flow-demo")" 200
has "asks for the email first" 'name="email"'
grep -q 'data-autostart' "$tmp/body" && bad "starts by itself although an email is required" || ok "waits for the email"
check "unknown product → 404" "$(req fresh "$base/demo/no-such-demo")" 404
check "visitor with a running demo" "$(req visitor "$base/demo/web-flow-demo")" 302
case "$(loc)" in *"go/$slug") ok "is taken back into it";; *) bad "went to $(loc)";; esac
check "launch from product page" "$(req fresh "$base/launch" -d "_csrf=$(req fresh "$base/demo/web-flow-demo" >/dev/null; csrf)&blueprint=web-flow-demo&next=go&email=second@example.com")" 302
case "$(loc)" in */go/*) ok "goes straight into the demo";; *) bad "went to $(loc)";; esac
check "autologin" "$(req fresh "$(loc)")" 302
check "lands in wp-admin" "$(req fresh "$(loc)")" 302
req admin "$base/admin/settings" >/dev/null
check "turn off email capture" "$(req admin "$base/admin/settings" --data-urlencode "_csrf=$(csrf)" -d "section=launcher&brand_name=Acme+Demos&accent=%23e11d48&launcher_title=Try+Acme&launcher_intro=Hi&logo_url=&footer_text=&email_capture=off&consent_text=")" 302
check "product page, no email asked" "$(req newcomer "$base/demo/web-flow-demo")" 200
has "starts by itself" 'data-autostart'

echo "▸ admin actions"
req admin "$base/admin" >/dev/null; has "demo on dashboard" "$slug"; has "lead email shown" "lead@example.com"
check "extend" "$(req admin "$base/admin/sites/$slug/extend" --data-urlencode "_csrf=$(csrf)" -d "duration=1d")" 302
check "leads CSV" "$(req admin "$base/admin/leads.csv")" 200
has "lead in CSV" "lead@example.com"
req admin "$base/admin" >/dev/null
check "delete" "$(req admin "$base/admin/sites/$slug/delete" --data-urlencode "_csrf=$(csrf)")" 302
check "demo gone" "$(req visitor "$(php -r 'require $argv[1]."/app/bootstrap.php"; echo Wpl\Sites::url($argv[2]);' "$dir" "$slug")/")" 404
check "cron URL needs key" "$(req stranger "$base/cron?key=wrong")" 403
key=$(php -r 'require $argv[1]."/app/bootstrap.php"; echo Wpl\Config::get("cron_key");' "$dir")
check "cron URL with key" "$(req stranger "$base/cron?key=$key")" 200
req admin "$base/admin/blueprints/web-flow-demo" >/dev/null
check "delete blueprint" "$(req admin "$base/admin/blueprints/web-flow-demo/delete" --data-urlencode "_csrf=$(csrf)")" 302
[ ! -d "$dir/wpl-content/web-flow-demo" ] && ok "blueprint files removed" || bad "blueprint files left"
check "sign out" "$(req admin "$base/admin/logout" --data-urlencode "_csrf=$(req admin "$base/admin" >/dev/null; csrf)")" 302
check "signed out" "$(req admin "$base/admin")" 302

grep -h "PHP \(Fatal\|Warning\|Deprecated\|Notice\)" "$dir/wpl-data/logs/"*.log 2>/dev/null | head -5
rm -rf "$tmp"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
