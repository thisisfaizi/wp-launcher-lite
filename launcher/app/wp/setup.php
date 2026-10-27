<?php
/**
 * WP Launcher Lite — template builder.
 *
 * Reached in the operator's browser at <template-url>/wpl-setup.php?token=…
 * (the panel sends them here after creating a blueprint). It runs inside the
 * template site and does a small slice of work per request, moving on with a
 * meta refresh, so nothing can hit a shared host's 30-second limit:
 *
 *   step 0      install WordPress into the template's database
 *   step 1..n   one plugin or theme from the blueprint, itself in phases:
 *               resolve → download (resumable) → extract (resumable) → activate
 *   last        mark the template built and log the operator in
 *
 * WordPress's own Plugin_Upgrader does download + unzip in one request, which
 * a large plugin such as WooCommerce (18 MB, 6,400 files) cannot finish on a
 * strict host — hence our own resumable phases. No shell, no loopback request.
 */

$wpl_token = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';

// Find the site and its setup file before WordPress boots.
$wpl_data = dirname(__DIR__, 2) . '/wpl-data';
$wpl_uri_slug = null;
foreach ([$_SERVER['HTTP_HOST'] ?? '', $_SERVER['REQUEST_URI'] ?? ''] as $wpl_part) {
    if (preg_match('#(?:^|/s/)([a-z0-9]{10})(?:[./?]|$)#', $wpl_part, $wpl_m) && is_file($wpl_data . '/sites/' . $wpl_m[1] . '/setup.json')) {
        $wpl_uri_slug = $wpl_m[1];
        break;
    }
}
$wpl_setup_file = $wpl_uri_slug ? $wpl_data . '/sites/' . $wpl_uri_slug . '/setup.json' : null;
$wpl_setup = $wpl_setup_file ? json_decode((string) file_get_contents($wpl_setup_file), true) : null;

if (!is_array($wpl_setup) || $wpl_token === '' || (int) $wpl_setup['expires'] < time() || !hash_equals((string) $wpl_setup['hash'], hash('sha256', $wpl_token))) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('This setup link is invalid or has expired. Open the blueprint in the launcher panel and click "Build template" again.');
}

if ((int) $wpl_setup['step'] === 0) {
    // Nothing is installed yet: keep WordPress from redirecting to install.php.
    define('WP_INSTALLING', true);
}

require __DIR__ . '/../../wpl-core/wp-load.php';

if (!defined('WPL_SITE') || WPL_SITE['slug'] !== $wpl_uri_slug || WPL_SITE['kind'] !== 'template') {
    wp_die('Setup can only run on a template site.');
}

require_once WPL_ROOT . '/app/lib/Fs.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';

$wpl_deadline = microtime(true) + 15;
$plan = (array) $wpl_setup['plan'];
$step = (int) $wpl_setup['step'];
$log = (array) ($wpl_setup['log'] ?? []);
$label = 'Working…';

if ($step === 0) {
    if (!is_blog_installed()) {
        $result = wp_install(
            $wpl_setup['title'],
            $wpl_setup['admin_user'],
            $wpl_setup['admin_email'],
            false,
            '',
            wp_slash($wpl_setup['admin_password'])
        );
        if (is_wp_error($result) || empty($result['user_id'])) {
            wp_die('WordPress could not be installed: ' . esc_html(is_wp_error($result) ? $result->get_error_message() : 'unknown error'));
        }
    }
    update_option('permalink_structure', '/%postname%/');
    update_option('blog_public', '0');
    $log[] = 'Installed WordPress ' . get_bloginfo('version') . '.';
    $wpl_setup['step'] = 1;
    $wpl_setup['item'] = [];
} elseif ($step <= count($plan)) {
    $admin = get_user_by('login', $wpl_setup['admin_user']);
    if ($admin) {
        wp_set_current_user($admin->ID);
    }
    $item = $plan[$step - 1];
    $state = (array) ($wpl_setup['item'] ?? []);
    ob_start();
    try {
        [$state, $message, $label] = wpl_setup_item($item, $state, $wpl_deadline, $wpl_setup_file, $wpl_setup);
    } catch (Throwable $e) {
        $state = ['phase' => 'done'];
        $message = 'Failed: ' . $item['ref'] . ' — ' . $e->getMessage();
    }
    ob_end_clean();
    if ($message !== null) {
        $log[] = $message;
    }
    if (($state['phase'] ?? '') === 'done') {
        $wpl_setup['step'] = $step + 1;
        $wpl_setup['item'] = [];
    } else {
        $wpl_setup['item'] = $state;
    }
} else {
    file_put_contents($wpl_data . '/sites/' . $wpl_uri_slug . '/installed', (string) time());
    @unlink($wpl_setup_file);
    $admin = get_user_by('login', $wpl_setup['admin_user']);
    if ($admin) {
        wp_set_current_user($admin->ID);
        wp_set_auth_cookie($admin->ID, false);
    }
    flush_rewrite_rules(false);
    wpl_setup_page(100, 'Template ready', $log, admin_url(), 'Opening the template dashboard…', true);
}

$wpl_setup['log'] = $log;
file_put_contents($wpl_setup_file, json_encode($wpl_setup));

$next = WPL_SITE['url'] . '/wpl-setup.php?token=' . rawurlencode($wpl_token);
$done = (int) $wpl_setup['step'];
$pct = (int) round($done / (count($plan) + 2) * 100);
if ($done > count($plan)) {
    $label = 'Finishing…';
} elseif ($done !== $step) {
    $label = 'Installing ' . $plan[$done - 1]['ref'] . '…';
}
wpl_setup_page($pct, 'Building template: ' . $wpl_setup['title'], $log, $next, $label, false);

// ─────────────────────────────────────────────────────────────────────────

/**
 * Advance one plugin/theme by one phase (or as many as fit before the
 * deadline). Returns [new state, log line or null, progress label].
 */
function wpl_setup_item(array $item, array $state, float $deadline, string $setupFile, array $setup): array
{
    $isTheme = $item['type'] === 'theme';
    $ref = $item['ref'];
    $isUrl = (bool) preg_match('#^https?://#', $ref);
    $root = $isTheme ? get_theme_root() : WP_PLUGIN_DIR;
    $phase = $state['phase'] ?? 'resolve';
    $kind = $isTheme ? 'theme' : 'plugin';

    if ($phase === 'resolve') {
        if (!$isUrl && is_dir($root . '/' . $ref)) {
            return [['phase' => 'activate', 'folder' => $ref], null, 'Activating ' . $ref . '…'];
        }
        $package = $ref;
        if (!$isUrl) {
            $api = $isTheme
                ? themes_api('theme_information', ['slug' => $ref, 'fields' => ['sections' => false]])
                : plugins_api('plugin_information', ['slug' => $ref, 'fields' => ['sections' => false]]);
            if (is_wp_error($api)) {
                return [['phase' => 'done'], 'Failed: ' . $kind . ' "' . $ref . '" — ' . $api->get_error_message(), ''];
            }
            $package = $api->download_link;
        }
        $state = ['phase' => 'download', 'package' => $package, 'zip' => WPL_DATA . '/tmp/setup-' . md5($package) . '.zip'];
        $phase = 'download';
    }

    if ($phase === 'download') {
        $r = Wpl\Fs::downloadStep($state['package'], $state['zip'], $deadline);
        if (!$r['done']) {
            $of = $r['total'] ? ' of ' . round($r['total'] / 1048576, 1) . ' MB' : ' MB';
            return [$state, null, 'Downloading ' . $ref . ' (' . round($r['bytes'] / 1048576, 1) . $of . ')…'];
        }
        $state['phase'] = 'extract';
        $state['next'] = 0;
        $state['staging'] = WP_CONTENT_DIR . '/upgrade/wpl-' . md5($state['package']);
        Wpl\Fs::trash($state['staging']);
        return [$state, null, 'Unpacking ' . $ref . '…'];
    }

    if ($phase === 'extract') {
        $r = Wpl\Fs::extractZip($state['zip'], $state['staging'], (int) $state['next'], $deadline, function ($name) {
            return $name;
        });
        $state['next'] = $r['next'];
        if ($r['next'] < $r['total']) {
            return [$state, null, 'Unpacking ' . $ref . ' (' . $r['next'] . ' / ' . $r['total'] . ' files)…'];
        }
        // A wordpress.org zip holds one top-level folder named after the slug.
        $entries = array_values(array_diff(scandir($state['staging']), ['.', '..', '__MACOSX']));
        if (count($entries) === 1 && is_dir($state['staging'] . '/' . $entries[0])) {
            $folder = $entries[0];
            $src = $state['staging'] . '/' . $folder;
        } else {
            $folder = $isUrl ? preg_replace('/(\.\d+)*\.zip$/', '', basename((string) parse_url($ref, PHP_URL_PATH))) : $ref;
            $src = $state['staging'];
        }
        $folder = sanitize_file_name($folder);
        if (is_dir($root . '/' . $folder)) {
            Wpl\Fs::trash($root . '/' . $folder);
        }
        wp_mkdir_p($root);
        if (!@rename($src, $root . '/' . $folder)) {
            throw new RuntimeException('Could not move ' . $folder . ' into place.');
        }
        Wpl\Fs::trash($state['staging']);
        @unlink($state['zip']);
        return [['phase' => 'activate', 'folder' => $folder], null, 'Activating ' . $ref . '…'];
    }

    // activate — progress is saved first: a plugin that exits during
    // activation (some redirect and die) must not wedge the build.
    $setup['step'] = (int) $setup['step'] + 1;
    $setup['item'] = [];
    file_put_contents($setupFile, json_encode($setup));

    $folder = $state['folder'];
    if ($isTheme) {
        wp_clean_themes_cache();
        if (!wp_get_theme($folder)->exists()) {
            return [['phase' => 'done'], 'Failed: theme "' . $folder . '" was unpacked but is not a valid theme.', ''];
        }
        if (!empty($item['activate'])) {
            switch_theme($folder);
            return [['phase' => 'done'], 'Installed and activated theme ' . $folder . '.', ''];
        }
        return [['phase' => 'done'], 'Installed theme ' . $folder . '.', ''];
    }

    wp_clean_plugins_cache(false);
    $plugins = get_plugins('/' . $folder);
    if (!$plugins) {
        return [['phase' => 'done'], 'Failed: "' . $folder . '" was unpacked but contains no plugin.', ''];
    }
    $file = $folder . '/' . array_key_first($plugins);
    if (!empty($item['activate']) && !is_plugin_active($file)) {
        $r = activate_plugin($file);
        if (is_wp_error($r)) {
            return [['phase' => 'done'], 'Installed ' . $file . ', but activation failed: ' . $r->get_error_message(), ''];
        }
        return [['phase' => 'done'], 'Installed and activated ' . $file . '.', ''];
    }
    return [['phase' => 'done'], 'Installed ' . $file . '.', ''];
}

function wpl_setup_page(int $pct, string $title, array $log, string $next, string $label, bool $final): void
{
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    $items = '';
    foreach ($log as $line) {
        $bad = strpos($line, 'Failed') === 0 || strpos($line, 'activation failed') !== false;
        $items .= '<li' . ($bad ? ' class="bad"' : '') . '>' . esc_html($line) . '</li>';
    }
    $delay = $final ? 2 : 0;
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<meta http-equiv="refresh" content="<?php echo (int) $delay; ?>;url=<?php echo esc_attr($next); ?>">
<title><?php echo esc_html($title); ?></title>
<style>
:root{color-scheme:light dark;--bg:#f6f7f9;--card:#fff;--fg:#111827;--muted:#6b7280;--accent:#2563eb;--track:#e5e7eb;--bad:#b91c1c}
@media (prefers-color-scheme:dark){:root{--bg:#0b0f17;--card:#131a26;--fg:#e5e7eb;--muted:#9ca3af;--accent:#60a5fa;--track:#1f2937;--bad:#f87171}}
body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--fg);font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif;padding:16px;box-sizing:border-box}
main{width:100%;max-width:520px;background:var(--card);border-radius:16px;padding:28px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
h1{font-size:1.2rem;margin:0 0 4px}.sub{color:var(--muted);margin:0 0 18px}
.bar{height:8px;background:var(--track);border-radius:99px;overflow:hidden}.bar i{display:block;height:100%;background:var(--accent);width:<?php echo (int) $pct; ?>%;transition:width .3s}
ul{margin:18px 0 0;padding-left:18px;color:var(--muted);font-size:13px}li.bad{color:var(--bad)}
a{color:var(--accent)}
</style></head>
<body><main>
<h1><?php echo esc_html($title); ?></h1>
<p class="sub"><?php echo esc_html($label); ?> Keep this tab open.</p>
<div class="bar"><i></i></div>
<?php if ($items) { echo '<ul>' . $items . '</ul>'; } ?>
<p class="sub" style="margin-top:18px;font-size:13px">Not moving? <a href="<?php echo esc_attr($next); ?>">Continue</a></p>
</main></body></html><?php
    exit;
}
