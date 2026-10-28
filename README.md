# WP Launcher Lite

Temporary WordPress demo sites for plugin and theme demos, self-hosted on **ordinary shared hosting** — no Docker, no VPS, no root, no shell.

Visitors click **Launch demo** and get a private copy of your product's WordPress site in about a second, logged in and ready to try. When its time is up it deletes itself. You get the leads.

It is a PHP port of the *agency mode* of [WP Launcher](https://github.com/msrbuilds/wp-launcher) by MSR Builds, which does the same with one Docker container per site. Upload a folder, open it in a browser, done.

| | WP Launcher (Docker) | WP Launcher Lite |
|---|---|---|
| Hosting | VPS with Docker, root, wildcard DNS | Any cPanel / DirectAdmin / Plesk shared host |
| Stack | Node API + provisioner, React, Traefik, Docker | PHP 8.0+, MySQL or SQLite, Apache (LiteSpeed expected) |
| One demo is | a container (~10 s) | a copy of a template's database (~1 s) |
| WordPress core | one per container | one, shared by every site |
| Plugins & themes | baked into an image | one shared copy per blueprint |
| Isolation | container per site | WordPress-level (see [Security](#security)) |
| Blueprint JSON | ✓ | ✓ — upstream blueprints import unchanged |

Dropped on purpose because shared hosting can't do them or they don't belong in a demo launcher: local dev mode and the `wpl` CLI, multi-PHP and live php.ini changes, Mini CRM / client portal, productivity tracking, site sync, editor extensions.

**Alternatives.** Hosted services such as InstaWP and TasteWP also give you temporary WordPress sandbox and demo sites, which run on their servers. WP Launcher Lite is self-hosted on your own cPanel or shared hosting, so the demo sites and the leads they bring in stay with you.

<sub>Product names are trademarks of their owners; listed for comparison only.</sub>

## How it works

```
Visitor clicks "Launch demo"
  → the blueprint's template database is copied (MySQL tables or one SQLite file)
  → its URL token is swapped for the new site's — same length, so even
    PHP-serialized data stays valid with a plain string replace
  → the template's media is copied, a fresh admin password is set
  → one-click login drops the visitor into wp-admin, a countdown in the admin bar
  → cron (or the next page view) deletes it when time is up
```

Every site — demos and templates — runs on **one WordPress core** in `wpl-core/`. Its `wp-config.php` looks at the request URL, picks the site, and points WordPress at that site's database, URLs and files. A demo costs a few database tables and a copy of the template's media; plugins and themes are stored once per blueprint. On hosts that cap file counts (inodes) that is the difference between ten demos and hundreds.

A **blueprint** is a real WordPress site you set up yourself — install premium plugins, import demo content, configure everything in its own wp-admin. Demos are copies of it. Details: [docs/how-it-works.md](docs/how-it-works.md).

## Requirements

- PHP **8.0** or newer, with PDO, Zip and JSON (all standard). mbstring recommended.
- **MySQL/MariaDB** (pdo_mysql + mysqli) — *or* SQLite (pdo_sqlite).
- **Apache or LiteSpeed** reading `.htaccess`, the norm on shared hosting. Tested on Apache 2.4. LiteSpeed reads the same rules and is expected to work, but hasn't been verified yet. For nginx there are hand-written rules in [docs/nginx.md](docs/nginx.md), also unverified.
- Outgoing HTTPS to wordpress.org (or upload `wordpress.zip` by hand).

Never needed: `exec()`, wp-cli, Composer, Node, SSH, root, or permission to create databases.

## Install (cPanel)

1. **Database.** In cPanel → *MySQL Databases*, create one database and one user, and add the user to it with *All privileges*. (Or skip this and choose SQLite.)
2. **Upload.** Download the release zip, upload it in *File Manager* to where the launcher should live — e.g. `public_html/demos/` — and *Extract*. The folder should contain `index.php`, `app/`, `assets/`.
3. **Install.** Open `https://yourdomain.com/demos/` in a browser. The installer checks the server, asks for the database and your admin login, then downloads WordPress in small steps (shared hosts stop long requests, so it resumes across several). About two minutes.
4. **Cleanup job.** cPanel → *Cron Jobs* → every 5 minutes:
   ```
   php /home/USERNAME/public_html/demos/cron.php >/dev/null 2>&1
   ```
   The exact line is under *Settings → Cleanup job*. Without it, expired demos are still removed whenever someone opens the launcher — just less promptly.
5. **First blueprint.** The installer drops you on *New blueprint*. Name it, optionally list wordpress.org plugins (`woocommerce`, one per line) and a theme, and click *Create and build*. Your browser builds the template — keep the tab open. You land in the template's wp-admin: set it up how a visitor should find it.

Share `https://yourdomain.com/demos/`. That's the launcher.

### Updating WP Launcher Lite

Upload `wp-launcher-lite-<version>-update.zip` to the launcher folder and *Extract*, overwriting files. It leaves out `.htaccess` (which holds your routing rules) and never contains `wpl-data/`, `wpl-core/`, `wpl-content/` or `wpl-uploads/`, so settings, blueprints and running demos are untouched. Don't extract the full release zip over an install: its pre-install `.htaccess` would replace your routing rules (if that happens, *System → Rewrite routing rules* puts them back).

### Demo addresses

| Mode | Demo URL | Needs |
|---|---|---|
| **Folder** (default) | `example.com/demos/s/k7f3m9q2xa/` | nothing |
| **Subdomain** | `k7f3m9q2xa.demo.example.com` | a wildcard DNS record `*.demo` → your server, and in cPanel → *Domains* a subdomain `*.demo.example.com` whose document root is the launcher folder |

Subdomain mode gives every demo its own browser origin (see [Security](#security)). The mode is chosen at install time; changing it later means reinstalling, because templates store their URLs.

## Using it

- **Blueprints** — one per product. *Edit template in wp-admin* logs you into the template. Changes there apply to demos launched afterwards; running demos keep what they started with. Plugin *files* are shared, so updating a plugin in the template updates it for running demos too.
- **A button on your own website** — every blueprint has its own link, `https://yourdomain.com/demos/demo/<blueprint>` (copy it from the blueprint's page in the panel). Point a "Try the demo" button at it: the visitor lands in a fresh demo, logged in, in one click — or after entering an email, if you collect them. A visitor who already has that demo running is taken back to it.
- **Restrictions** — per blueprint you can also block export/import and hide admin menus. Installing, updating or editing plugins, themes and core is **always** blocked on demos (see below).
- **Limits** — total running demos, launches per IP per hour, running demos per visitor, upload size and media quota. Defaults are deliberately low: shared hosts suspend accounts that hog CPU or disk.
- **Leads** — turn on *Ask for an email* in Settings; download them as CSV, or send each launch to Zapier/Make/your CRM with the **webhook** (`site.created`, `site.expired`, `site.deleted`, HMAC-SHA256 signed in `X-WPL-Signature`, same as upstream).
- **Import** — paste an upstream `blueprints/*.json` into *New blueprint → Import*. wordpress.org and URL plugins/themes are installed; `local` sources (files in the Docker project) are skipped — upload those through the template's wp-admin.
- **Updates** — *System* shows the WordPress version and updates the shared core in place (all sites at once). Open each template afterwards; WordPress may ask to update its database.

## Security

Read this before you go live. On shared hosting **every demo runs as your hosting account's PHP user** — the same user that can read the launcher's config, every other demo, and every other site in your account. There is no container wall. So the line that matters is *can a visitor make the server run code they wrote?*

What WP Launcher Lite enforces on every demo, whatever the blueprint says:

- `DISALLOW_FILE_MODS` and `DISALLOW_FILE_EDIT` — no installing, updating or editing plugins, themes or core, through wp-admin or the REST API.
- `DISALLOW_UNFILTERED_HTML` and no `unfiltered_upload` — demo admins can't post raw `<script>`.
- PHP can't execute anywhere under `wpl-uploads/`.
- No email from demos unless you allow it (a demo admin could otherwise mail anyone from your account).
- `wpl-data/` (config, panel database, SQLite files) and `app/` are denied over HTTP. *System → Self-test* checks this from your browser.
- Demos are `noindex`, single-use five-minute login links, signed admin sessions, CSRF tokens, sign-in throttling, launch rate limits, honeypot.

What you are responsible for:

- **Don't put code-execution plugins in a demo template.** Code-snippet plugins, PHP widgets, file managers, "custom PHP" fields in page builders, database admin plugins — any of these hands a visitor your hosting account. Theme/plugin *settings* are fine.
- **Prefer subdomain mode, or keep the panel elsewhere.** In folder mode every demo shares one browser origin with each other and with the panel. Script injection is blocked by the measures above, but a plugin that lets an admin add raw JavaScript would let a malicious demo act on whoever opens it — including you, if you are signed in to the panel in the same browser. Use a separate browser profile for the panel, or subdomain mode.
- Use a hosting account (or at least a subdomain) dedicated to demos, not the one that hosts your shop.

## Limits and costs

Roughly, per demo: its database rows (a fresh WordPress ≈ 0.4 MB; with WooCommerce ≈ 1.2 MB) plus a copy of the template's media. Plugins and themes count once per blueprint (WooCommerce + Astra ≈ 100 MB, ~10,000 files). Keep the template's media library small — every demo copies it.

Launch time measured on the test rig: **0.8 s** for plain WordPress, **0.9 s** with WooCommerce. Building a WooCommerce + Astra template took 83 s on a slow connection, in 12 requests of ≤ 15 s each.

## Troubleshooting

See [docs/troubleshooting.md](docs/troubleshooting.md). The usual suspects: `.htaccess` not applied (demo URLs 404), PHP too old, a host blocking outgoing requests (upload `wordpress.zip` to `wpl-data/` and retry).

## Development

```
launcher/          ← the folder you upload
  index.php        launcher, panel and installer front controller
  cron.php         cleanup job (CLI)
  app/lib/         panel code (Config, Db, Sites, SiteDb, Blueprints, Core, …)
  app/wp/          runs inside WordPress: dispatch.php (the shared wp-config),
                   setup.php (template builder), mu-plugins/
  app/views/       server-rendered PHP templates
  assets/          one CSS and one JS file, no build step
tests/             end-to-end tests against a real Apache + php-fpm
tools/             build and test-rig helpers
```

Tests run against a real web server with `.htaccess`, under a php-fpm pool configured like a strict shared host (no `exec`, 30 s limit, 128 MB, `open_basedir`). See [tests/README.md](tests/README.md).

**Verified:** Apache 2.4 + PHP-FPM 8.5, WordPress 7.1.3, SQLite and MariaDB (a user limited to one database), folder and subdomain modes, over HTTP. **Not yet verified:** LiteSpeed, a real cPanel account, HTTPS behind a proxy, PHP 8.0–8.4 (the code avoids newer syntax, but was only run on 8.5), nginx. Build the release zip with `tools/build.sh`.

## Credits and license

MIT — see [LICENSE](LICENSE). Based on [WP Launcher](https://github.com/msrbuilds/wp-launcher) © MSR Builds (MIT): the blueprint format, the restriction model and the demo mu-plugins (restrictions, countdown, autologin) are ported from it. WordPress and the SQLite Database Integration plugin are downloaded from wordpress.org at install time and are GPL-licensed; they are not part of this repository.
