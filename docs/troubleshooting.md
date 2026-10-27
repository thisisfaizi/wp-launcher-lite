# Troubleshooting

Start with **Panel → System → Self-test**. It checks from your browser that demo URLs reach WordPress and that the private folder is blocked.

## Demo URLs show the host's 404 page, or "Page not found" from the launcher

The `.htaccess` rules aren't being applied.

- Check that `.htaccess` in the launcher folder starts with `# BEGIN WP Launcher Lite`. If not, click *System → Rewrite routing rules*.
- Some hosts disable `.htaccess` overrides for addon domains or subfolders. Ask support to enable `AllowOverride All` and `mod_rewrite` (on LiteSpeed it is on by default).
- Is the server nginx? See [nginx.md](nginx.md).
- Hidden files: File Manager hides `.htaccess` unless *Settings → Show hidden files* is on.

## "Private data folder is readable"

Your server ignored `wpl-data/.htaccess`, usually because it's nginx. Fix this before sharing the launcher: [nginx.md](nginx.md).

## The installer stops at "Downloading WordPress"

The host blocks or throttles outgoing requests. Download <https://wordpress.org/latest.zip>, upload it as `wpl-data/wordpress.zip`, and click *Retry*. For SQLite installs, do the same with <https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip> as `wpl-data/sqlite-database-integration.zip`.

## Building a template stops on a plugin

The build page shows what it was doing; click *Continue* and it resumes where it stopped. Downloads resume mid-file. A plugin that fails to install or activate is listed in red and skipped. Install it from the template's wp-admin instead (*Blueprint → Edit template in wp-admin*).

## "This demo is not available right now"

The blueprint is hidden, or its template hasn't finished building. Open it in the panel.

## Launch says all slots are taken

A limit was reached: *Settings → Limits* (total, per IP per hour, per visitor) or the blueprint's *Max running at once*. If demos that should have expired are still counted, cleanup isn't running: add the cron job (*Settings → Cleanup job*).

## A demo shows "Database Update Required"

You updated WordPress (*System*) after the demo was launched. Open each blueprint's template and accept the database update, so demos launched from then on start up to date.

## A plugin misbehaves on SQLite

SQLite support in WordPress is good but not universal. Plugins with complex custom SQL may fail. Use MySQL for those, which requires reinstalling with MySQL selected.

## I changed my domain or moved the folder

Templates store their own URLs, so moving the install breaks them. Reinstall in the new place, or keep the old URL working.

## Starting over

Delete `wpl-data`, `wpl-core`, `wpl-content` and `wpl-uploads` (and, for MySQL, every table starting with `wpl_`), restore the original `.htaccess` from the release zip, and open the launcher again.

## Logs

- *Panel → Activity*: launches, expiries, sign-ins, errors.
- *Settings → WordPress debug log*: writes demo PHP notices to `wpl-data/logs/`.
- Your host's PHP error log (cPanel → *Errors* / `error_log` files).
