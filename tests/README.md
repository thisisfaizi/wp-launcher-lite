# Tests

End-to-end tests against a **real web server** reading `.htaccess`, because routing is the part most likely to break. There are no unit tests to run without one.

| Script | Covers |
|---|---|
| `smoke.sh` | install → build a template over HTTP (with a wordpress.org plugin) → launch → front end, permalinks, autologin (and single use), wp-admin, block editor, REST post + media upload, blocked plugin installer/theme editor/REST plugin install, PHP denied in uploads, private folders, logging in through the real `wp-login.php` form with the credentials a visitor is shown (also after the blueprint's admin username changes), limits, expiry page, cron cleanup (files and tables gone). 38 checks. |
| `web-flow.sh` | the real UI with form posts: browser installer (and lock-out of a second browser), sign-in and a rejected wrong password, CSRF, blueprint create/build/edit/delete, settings, visitor launch with required email + honeypot, ready page privacy, autologin, admin extend/delete, leads CSV, cron URL key. 54 checks. |

Helpers: `install-cli.php` (install without a browser), `launch.php`, `make-blueprint.php`, `follow-setup.sh` (follows a template build like a browser).

## A shared-hosting-like rig

The scripts expect Apache with `AllowOverride All` and PHP-FPM, configured like a strict host:

```ini
; php-fpm pool
php_admin_value[disable_functions] = exec,shell_exec,system,passthru,proc_open,popen,pcntl_exec
php_admin_value[max_execution_time] = 30
php_admin_value[memory_limit] = 128M
php_admin_value[open_basedir] = /path/to/docroot:/path/to/tmp
```

```apache
<Directory "/path/to/docroot">
  AllowOverride All
  Require all granted
</Directory>
<FilesMatch "\.php$">
  SetHandler "proxy:fcgi://127.0.0.1:9088"
</FilesMatch>
```

For subdomain mode, add a virtual host with `ServerAlias *.demo.test` whose `DocumentRoot` is the install folder. The script uses `curl --resolve`, so no DNS is needed.

## Running

```sh
# folder mode, SQLite (ZIPDIR holds wordpress.zip + sqlite.zip to skip downloads)
tests/smoke.sh /srv/www/demos http://127.0.0.1:8088/demos sqlite path "" ~/zips

# folder mode, MySQL — a user with rights on one database only, like cPanel gives
WPL_TEST_MYSQL_HOST=127.0.0.1 WPL_TEST_MYSQL_PORT=3307 WPL_TEST_MYSQL_NAME=wpl_test \
WPL_TEST_MYSQL_USER=cpuser_wpl WPL_TEST_MYSQL_PASS=… \
tests/smoke.sh /srv/www/my http://127.0.0.1:8088/my mysql path "" ~/zips

# subdomain mode (install at the vhost's document root)
tests/smoke.sh /srv/www-sub http://demo.test:8089 sqlite subdomain demo.test ~/zips

# the UI
tests/web-flow.sh /srv/www/web http://127.0.0.1:8088/web ~/zips
```

The scripts **delete and recreate** the folder they are given.
