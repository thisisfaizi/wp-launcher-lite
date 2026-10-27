<?php
/**
 * Plugin Name: WP Launcher Lite — Restrictions
 * Description: Demo-site lockdown, ported from WP Launcher. Capabilities are removed in wpl-site.php; this hides what they leave behind and enforces upload limits.
 * Version: 0.1.0
 */

defined('ABSPATH') || exit;

if (!defined('WPL_SITE') || WPL_SITE['kind'] !== 'demo') {
    return;
}

function wpl_blocks(string $cap): bool
{
    return in_array($cap, WPL_SITE['blocked_caps'], true);
}

/**
 * Remove the menus the blueprint hides, plus the submenus implied by blocked
 * capabilities — "Add New" with install_plugins blocked is a dead link.
 */
add_action('admin_menu', function () {
    foreach (WPL_SITE['hidden_menus'] as $menu) {
        remove_menu_page($menu);
    }
    $sub = [
        'install_plugins' => ['plugins.php', 'plugin-install.php'],
        'install_themes' => ['themes.php', 'theme-install.php'],
        'edit_themes' => ['themes.php', 'theme-editor.php'],
        'edit_plugins' => ['plugins.php', 'plugin-editor.php'],
        'update_core' => ['index.php', 'update-core.php'],
    ];
    foreach ($sub as $cap => [$parent, $page]) {
        if (wpl_blocks($cap)) {
            remove_submenu_page($parent, $page);
        }
    }
}, 999);

add_action('admin_head', function () {
    remove_action('admin_notices', 'update_nag', 3);
    remove_action('admin_notices', 'maintenance_nag', 10);
    echo '<style>.plugins-php .page-title-action,.themes-php .page-title-action{display:none!important}</style>';
}, 1);

/** Direct URLs to restricted screens. */
add_action('admin_init', function () {
    $page_for_cap = [
        'plugin-install.php' => 'install_plugins',
        'theme-install.php' => 'install_themes',
        'plugin-editor.php' => 'edit_plugins',
        'theme-editor.php' => 'edit_themes',
        'update-core.php' => 'update_core',
        'update.php' => 'update_plugins',
        'import.php' => 'import',
        'export.php' => 'export',
    ];
    $current = basename(sanitize_text_field(wp_unslash($_SERVER['SCRIPT_NAME'] ?? '')));
    if (isset($page_for_cap[$current]) && wpl_blocks($page_for_cap[$current])) {
        wp_die('<h1>Restricted</h1><p>This action is disabled on demo sites.</p>', 'Restricted', ['response' => 403]);
    }
});

/** The same privileges through the REST API. */
add_filter('rest_dispatch_request', function ($dispatch, $request, $route) {
    if ($request->get_method() === 'GET') {
        return $dispatch;
    }
    $patterns = [];
    if (wpl_blocks('install_plugins') || wpl_blocks('edit_plugins')) {
        $patterns[] = '#^/wp/v2/plugins#';
    }
    if (wpl_blocks('install_themes') || wpl_blocks('edit_themes')) {
        $patterns[] = '#^/wp/v2/themes#';
    }
    foreach ($patterns as $p) {
        if (preg_match($p, $route)) {
            return new WP_Error('wpl_restricted', 'This action is disabled on demo sites.', ['status' => 403]);
        }
    }
    return $dispatch;
}, 10, 3);

/** Per-file upload limit and total quota for the site's uploads. */
if (WPL_SITE['upload_limit'] > 0) {
    add_filter('upload_size_limit', function ($size) {
        return min($size, WPL_SITE['upload_limit']);
    });
}

if (WPL_SITE['disk_quota'] > 0) {
    add_filter('wp_handle_upload_prefilter', function ($file) {
        $used = wpl_uploads_size();
        if ($used + (int) $file['size'] > WPL_SITE['disk_quota']) {
            $file['error'] = sprintf('Upload quota exceeded. This demo site is limited to %d MB of uploads.', round(WPL_SITE['disk_quota'] / 1048576));
        }
        return $file;
    });

    add_action('admin_notices', function () {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'upload') {
            return;
        }
        $used = wpl_uploads_size();
        $quota = WPL_SITE['disk_quota'];
        $pct = min(100, (int) round($used / $quota * 100));
        printf('<div class="notice notice-info"><p><strong>Demo storage:</strong> %s MB of %d MB used (%d%%)</p></div>', number_format_i18n($used / 1048576, 1), round($quota / 1048576), $pct);
    });
}

function wpl_uploads_size(): int
{
    $dir = WPL_SITE['uploads_dir'];
    $size = 0;
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $size += $f->getSize();
        }
    }
    return $size;
}

add_filter('automatic_updater_disabled', '__return_true');
add_filter('auto_update_plugin', '__return_false');
add_filter('auto_update_theme', '__return_false');
