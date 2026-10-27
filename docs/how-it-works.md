# How it works

This is the design behind WP Launcher Lite, and why each piece looks the way it does on shared hosting.

## The constraints

Shared hosting gives you PHP, one or a few MySQL databases, `.htaccess`, cron, and limits:

- **No shell.** `exec()`, `proc_open()` and wp-cli are usually disabled. Everything is plain PHP: PDO, ZipArchive, file functions, cURL.
- **Short requests.** 30–60 seconds, then the host kills the process — sometimes regardless of `set_time_limit()`. No background workers.
- **Inode quotas.** Often 100k–250k files per account. WordPress core alone is ~3,000 files.
- **No new databases** from code. You get the one you created in cPanel.
- **Shared PHP user.** Every site in the account runs as the same user.

## Folders

```
demos/                     the folder you uploaded
├── index.php              launcher + panel + installer
├── cron.php
├── app/                   code (denied over HTTP)
├── assets/
├── wpl-core/              ONE WordPress core for every site
├── wpl-content/<blueprint>/   plugins/, themes/, mu-plugins/ — shared by that blueprint's demos
├── wpl-uploads/<slug>/    each site's media
└── wpl-data/              private (denied over HTTP)
    ├── config.php         install config
    ├── .ht.panel.sqlite   panel database (SQLite installs)
    ├── sites/<slug>/      site.php (per-site config), .ht.sqlite (SQLite installs), login/setup tokens
    ├── vendor/            SQLite driver, bundled default themes
    ├── tmp/  logs/  trash/
```

## One core, many sites

`wpl-core/wp-config.php` is a one-line include of `app/wp/dispatch.php`. Per request it:

1. Works out the site **slug** from the original URL — `REQUEST_URI` (`/demos/s/<slug>/…`) in folder mode, `HTTP_HOST` (`<slug>.demo.example.com`) in subdomain mode. It never relies on rewrite environment variables, because Apache and LiteSpeed pass those on differently (`REDIRECT_` prefixes).
2. Reads `wpl-data/sites/<slug>/site.php`. Missing → a friendly "this demo has ended" page (404); past its expiry → 410, even before cron has cleaned it up.
3. Defines that site's database (`DB_*` and `$table_prefix` for MySQL, `DB_PATH` for SQLite), salts, `WP_HOME`/`WP_SITEURL`, `WP_CONTENT_DIR`/`WP_CONTENT_URL` (the blueprint's shared folder), and the demo hardening constants.
4. Hands over to `wp-settings.php`.

`.htaccess` maps a site's URLs onto the shared folders:

| URL under the site | Served from |
|---|---|
| `/wp-content/uploads/…` | `wpl-uploads/<slug>/…` |
| `/_c/<blueprint>/…` (plugin and theme assets) | `wpl-content/<blueprint>/…` |
| `/wp-admin/…`, `/wp-includes/…`, `/*.php` | `wpl-core/…` |
| anything else | `wpl-core/index.php` |

Plugin and theme assets live under `/_c/<blueprint>/` rather than `/wp-content/` because `.htaccess` can't look up which blueprint a site belongs to, so the blueprint has to be in the URL. Uploads stay at the standard `/wp-content/uploads/`.

`wpl-core/.htaccess` contains `RewriteEngine Off` and is read-only. That stops a second rewrite pass inside the core, and stops WordPress writing its own permalink rules into the shared core. The `wpl-site` mu-plugin also tells WordPress that pretty permalinks work and never to flush rules to disk.

## Data files are read, never included

`config.php` and each `site.php` are JSON behind a `<?php exit; ?>` first line. Executed over the web they print nothing. They are read with `file_get_contents()` rather than `include`d, so opcache can never serve a stale copy. On hosts with slow or disabled opcache revalidation, an `include`d config kept expired demos alive and delayed "extend" — the test suite caught this.

## Templates and cloning

A blueprint owns a **template site** (`kind = template`), a normal WordPress install with file modifications allowed, so you can install plugins in it. It is built by `wpl-setup.php` in the operator's browser, one slice per request, moving on with a meta refresh:

```
step 0    wp_install() into the template's database
step 1…n  each plugin/theme: resolve (wordpress.org API) → download (HTTP Range, resumable)
          → extract (resumable, time-boxed) → activate
last      mark built, log the operator in
```

WordPress's own `Plugin_Upgrader` downloads and unzips in one request. WooCommerce (18 MB, 6,400 files) can't do that within 30 seconds on a slow host, hence the resumable phases. Progress is saved before activation, so a plugin that `exit`s during activation doesn't wedge the build.

**Launching a demo** never talks to wordpress.org:

1. Under a file lock, check the limits and insert the site row.
2. Copy the database. MySQL: `CREATE TABLE … LIKE` + `INSERT … SELECT` for every `wpl_<template>_*` table into `wpl_<demo>_*`. SQLite: `VACUUM INTO` a new file, which gives a consistent snapshot even mid-write.
3. Replace the template's slug with the demo's in every text column. Slugs are 10 random characters, so `…/s/tq8z1m4cab` becomes `…/s/k7f3m9q2xa` without changing any string length, and PHP-serialized values (`s:42:"…"`) stay valid with a plain SQL `REPLACE()`. In MySQL the token is also in the table prefix, so prefixed keys like `wpl_<slug>_user_roles` and `wpl_<slug>_capabilities` are fixed by the same pass.
4. Delete transients (they describe the template) and session tokens, then set a fresh admin password.
5. Copy the template's media, applying the same token swap inside text files (page-builder CSS often contains absolute URLs).
6. Write `site.php`.

**Deleting** removes `site.php` first, so the site is offline at once. It then drops the tables and *renames* the site's folders into `wpl-data/trash/`, which is instant whatever the size. Cron empties the trash in time-boxed slices.

## Cleanup without cron

`cron.php` (CLI) or `/cron?key=…` (for URL-only cron) expires due demos and empties the trash. As a fallback, every launcher or panel page view runs cleanup at most once a minute, after the response has been sent (`fastcgi_finish_request()`), so visitors never wait for it.

## Panel

Server-rendered PHP views, one CSS file, one small JS file for progress loops and conveniences. There is no build step, because hosts have no Node. Operator sessions are HMAC-signed cookies rather than PHP sessions: shared hosts often keep session files in a `/tmp` shared with other accounts. The signature covers the password hash, so changing the password signs out everywhere.

## What was dropped from upstream, and why

| Upstream feature | In Lite |
|---|---|
| Container per site, Traefik, provisioner | one shared core + `.htaccess` routing |
| Multi-PHP, per-site php.ini, extensions | the host's PHP; not per site on shared hosting |
| MySQL/MariaDB server per engine | the one database you have, a table prefix per site |
| Local dev mode, `wpl` CLI, Mailpit | not applicable |
| Demos that allow plugin installs | not offered: same PHP user as everything else |
| Snapshots, cloning, export ZIP, sync, CRM, portal, productivity | out of scope for a demo launcher |
| Blueprint JSON, restrictions, countdown, autologin, webhooks, limits | ✓ ported |
