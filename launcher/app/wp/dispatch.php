<?php
/**
 * WP Launcher Lite — per-request site dispatcher.
 *
 * Every demo and template site runs on the one WordPress core in wpl-core/.
 * This file is that core's wp-config.php (via a one-line shim). It works out
 * which site the request is for, from the original URL — never from rewrite
 * environment variables, which Apache and LiteSpeed pass on differently — and
 * then defines the constants that give WordPress that site's database, URLs,
 * wp-content and restrictions.
 *
 * It must stay free of panel classes: it runs inside WordPress.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPL_ROOT', dirname(__DIR__, 2));
define('WPL_DATA', WPL_ROOT . '/wpl-data');

$wpl_install = wpl_dispatch_read(WPL_DATA . '/config.php');
if (!is_array($wpl_install) || empty($wpl_install['installed'])) {
    wpl_dispatch_page(503, 'Not ready yet', 'This launcher has not finished installing.', null);
}

$wpl_slug = wpl_dispatch_slug($wpl_install);
$wpl_launcher = rtrim((string) $wpl_install['base_url'], '/') . '/';
$wpl_site_file = $wpl_slug ? WPL_DATA . '/sites/' . $wpl_slug . '/site.php' : null;
$wpl_site = $wpl_site_file ? wpl_dispatch_read($wpl_site_file) : null;

if (!is_array($wpl_site)) {
    wpl_dispatch_page(404, 'This demo has ended', 'The demo you are looking for has expired or never existed. You can launch a fresh one in a few seconds.', $wpl_launcher);
}
if ($wpl_site['kind'] === 'demo' && $wpl_site['expires_at'] > 0 && $wpl_site['expires_at'] <= time()) {
    wpl_dispatch_page(410, 'This demo has expired', 'Demo sites are deleted automatically when their time is up. Launch a fresh one — it only takes a few seconds.', $wpl_site['launcher_url']);
}

define('WPL_SITE', $wpl_site);
$wpl_is_demo = $wpl_site['kind'] === 'demo';

// ── Database ─────────────────────────────────────────────────────────────
if (($wpl_install['engine'] ?? 'sqlite') === 'mysql') {
    $wpl_db = $wpl_install['mysql'];
    define('DB_NAME', $wpl_db['name']);
    define('DB_USER', $wpl_db['user']);
    define('DB_PASSWORD', $wpl_db['pass']);
    define('DB_HOST', $wpl_db['host'] . (!empty($wpl_db['port']) ? ':' . $wpl_db['port'] : ''));
} else {
    // Read by the SQLite drop-in in the blueprint's wp-content/db.php.
    define('DB_DIR', dirname($wpl_site['db_path']));
    define('DB_FILE', basename($wpl_site['db_path']));
    define('DB_PATH', $wpl_site['db_path']);
    define('DB_NAME', 'wordpress');
    define('DB_USER', '');
    define('DB_PASSWORD', '');
    define('DB_HOST', '');
}
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$table_prefix = $wpl_site['prefix'];

foreach ($wpl_site['salts'] as $wpl_key => $wpl_salt) {
    define($wpl_key, $wpl_salt);
}

// ── URLs and folders ─────────────────────────────────────────────────────
define('WP_HOME', $wpl_site['url']);
define('WP_SITEURL', $wpl_site['url']);
define('WP_CONTENT_DIR', $wpl_site['content_dir']);   // shared per blueprint
define('WP_CONTENT_URL', $wpl_site['content_url']);
define('WPMU_PLUGIN_DIR', __DIR__ . '/mu-plugins');    // ours, outside any blueprint
define('WPMU_PLUGIN_URL', $wpl_site['content_url'] . '/mu-plugins');
define('WP_TEMP_DIR', WPL_DATA . '/tmp');              // not the /tmp other accounts share

// ── Safety ───────────────────────────────────────────────────────────────
// The core is shared: it is only ever updated from the panel.
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_AUTO_UPDATE_CORE', false);

if ($wpl_is_demo) {
    // Demos run as the same PHP user as the panel and every other site, so
    // nothing a visitor does may write code to disk. Not a blueprint option.
    define('DISALLOW_FILE_MODS', true);
    define('DISALLOW_FILE_EDIT', true);
    define('DISALLOW_UNFILTERED_HTML', true);
    define('DISABLE_WP_CRON', true);
    define('WP_POST_REVISIONS', 5);
} else {
    // Templates are the operator's own site: plugins install normally.
    define('FS_METHOD', 'direct');
}

define('WP_DEBUG', !empty($wpl_site['debug']));
define('WP_DEBUG_LOG', !empty($wpl_site['debug']) ? WPL_DATA . '/logs/' . $wpl_site['slug'] . '-debug.log' : false);
define('WP_DEBUG_DISPLAY', false);
define('WP_MEMORY_LIMIT', '128M');
define('WP_MAX_MEMORY_LIMIT', '256M');

// TLS terminated by Cloudflare or the host's proxy.
if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow');
}

unset($wpl_install, $wpl_db, $wpl_slug, $wpl_launcher, $wpl_site_file, $wpl_key, $wpl_salt, $wpl_is_demo);

require_once ABSPATH . 'wp-settings.php';

// ─────────────────────────────────────────────────────────────────────────

/** Read a launcher data file (JSON behind a PHP exit line; never include()d, so opcache cannot serve it stale). */
function wpl_dispatch_read(string $file): ?array
{
    $raw = is_file($file) ? @file_get_contents($file) : false;
    if ($raw === false) {
        return null;
    }
    $data = json_decode(substr($raw, (int) strpos($raw, "\n") + 1), true);
    return is_array($data) ? $data : null;
}

/** The site slug this request is for, or null. */
function wpl_dispatch_slug(array $install): ?string
{
    if (($install['url_mode'] ?? 'path') === 'subdomain') {
        $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        $re = '/^([a-z0-9]{10})\.' . preg_quote(strtolower((string) $install['base_domain']), '/') . '$/';
        return preg_match($re, $host, $m) ? $m[1] : null;
    }
    $base = rtrim((string) (parse_url((string) $install['base_url'], PHP_URL_PATH) ?: ''), '/');
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $re = '#^' . preg_quote($base, '#') . '/s/([a-z0-9]{10})(?:[/?]|$)#';
    return preg_match($re, $uri, $m) ? $m[1] : null;
}

/** A small standalone page for missing / expired sites. Ends the request. */
function wpl_dispatch_page(int $status, string $title, string $text, ?string $link): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    $t = htmlspecialchars($title, ENT_QUOTES);
    $p = htmlspecialchars($text, ENT_QUOTES);
    $a = $link ? '<a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Launch a new demo</a>' : '';
    echo <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>$t</title><style>
:root{color-scheme:light dark;--bg:#f6f7f9;--card:#fff;--fg:#111827;--muted:#6b7280;--accent:#2563eb}
@media (prefers-color-scheme:dark){:root{--bg:#0b0f17;--card:#131a26;--fg:#e5e7eb;--muted:#9ca3af;--accent:#60a5fa}}
body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--fg);font:16px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif;padding:16px;box-sizing:border-box}
main{max-width:420px;background:var(--card);border-radius:16px;padding:32px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
h1{font-size:1.35rem;margin:0 0 8px}p{color:var(--muted);margin:0 0 24px}
a{display:inline-block;background:var(--accent);color:#fff;text-decoration:none;padding:10px 18px;border-radius:10px;font-weight:600}
</style></head><body><main><h1>$t</h1><p>$p</p>$a</main></body></html>
HTML;
    exit;
}
