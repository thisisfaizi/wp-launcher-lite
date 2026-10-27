<?php
/**
 * Plugin Name: WP Launcher Lite — Site
 * Description: Makes a site work on the shared core: its own uploads folder, no permalink file writes, mail and update policy, and the blueprint's own mu-plugins.
 * Version: 0.1.0
 */

defined('ABSPATH') || exit;

if (!defined('WPL_SITE')) {
    return;
}

/** True for visitor demos, false for blueprint templates. */
function wpl_is_demo(): bool
{
    return WPL_SITE['kind'] === 'demo';
}

// ── Uploads live in wpl-uploads/<slug>, not the shared wp-content ────────
add_filter('upload_dir', function ($dirs) {
    $dirs['basedir'] = WPL_SITE['uploads_dir'];
    $dirs['baseurl'] = WPL_SITE['uploads_url'];
    $dirs['path'] = WPL_SITE['uploads_dir'] . $dirs['subdir'];
    $dirs['url'] = WPL_SITE['uploads_url'] . $dirs['subdir'];
    return $dirs;
}, 1);

// ── Permalinks ───────────────────────────────────────────────────────────
// Routing is done by the launcher's .htaccess. WordPress must never write
// its own rules (into the shared core), and pretty permalinks always work
// even where WordPress cannot detect mod_rewrite (LiteSpeed, php-fpm).
add_filter('flush_rewrite_rules_hard', '__return_false');
add_filter('got_url_rewrite', '__return_true');
add_filter('got_rewrite', '__return_true');

// ── Search engines ───────────────────────────────────────────────────────
add_filter('pre_option_blog_public', function () {
    return '0';
});

// ── Core updates belong to the panel ─────────────────────────────────────
add_filter('user_has_cap', function ($allcaps) {
    foreach (WPL_SITE['blocked_caps'] as $cap) {
        $allcaps[$cap] = false;
    }
    return $allcaps;
}, 10, 1);
add_filter('pre_site_transient_update_core', function () {
    return (object) ['last_checked' => time(), 'version_checked' => get_bloginfo('version'), 'updates' => []];
});
remove_action('wp_version_check', 'wp_version_check');
remove_action('admin_init', '_maybe_update_core');

if (wpl_is_demo()) {
    // Demos never call home for updates: it slows every admin page on a
    // shared host and there is nothing a demo could do with the answer.
    remove_action('admin_init', '_maybe_update_plugins');
    remove_action('admin_init', '_maybe_update_themes');
    remove_action('load-plugins.php', 'wp_update_plugins');
    remove_action('load-themes.php', 'wp_update_themes');
    remove_action('load-update-core.php', 'wp_update_plugins');
    remove_action('load-update-core.php', 'wp_update_themes');
    remove_action('wp_update_plugins', 'wp_update_plugins');
    remove_action('wp_update_themes', 'wp_update_themes');
    add_filter('xmlrpc_enabled', '__return_false');

    // The dashboard's PHP and browser checks are blocking calls to
    // api.wordpress.org; a fresh clone has no cached answer, so they added
    // ~6 s to every demo's first admin page. A demo has no use for them.
    add_filter('pre_http_request', function ($pre, $args, $url) {
        if (preg_match('#^https?://api\.wordpress\.org/core/(serve|browse)-happy/#', $url)) {
            return new WP_Error('wpl_skipped', 'Skipped on demo sites.');
        }
        return $pre;
    }, 10, 3);
}

// ── Email ────────────────────────────────────────────────────────────────
// A demo admin can create users with any address, and every one of those
// emails would leave from your hosting account. Off unless the operator
// allowed it; nothing is lost, because nothing in a demo needs email.
if (empty(WPL_SITE['allow_email'])) {
    add_filter('pre_wp_mail', function () {
        return false;
    });
}

// ── A blueprint's own mu-plugins (wp-content/mu-plugins in its folder) ───
$wpl_bp_mu = WP_CONTENT_DIR . '/mu-plugins';
if (is_dir($wpl_bp_mu)) {
    foreach (glob($wpl_bp_mu . '/*.php') ?: [] as $wpl_file) {
        include_once $wpl_file;
    }
}
unset($wpl_bp_mu, $wpl_file);

// ── Templates: say what this site is ─────────────────────────────────────
if (!wpl_is_demo()) {
    add_action('admin_notices', function () {
        if (!current_user_can('manage_options')) {
            return;
        }
        $panel = esc_url(WPL_SITE['panel_url']);
        $name = esc_html(WPL_SITE['blueprint_name']);
        echo '<div class="notice notice-info"><p><strong>WP Launcher Lite template: ' . $name . '.</strong> '
            . 'Every demo of this blueprint starts as a copy of this site — its content, settings, active plugins and media. '
            . 'Plugins and themes you install here are shared by all its demos. '
            . '<a href="' . $panel . '">Back to the launcher panel &rarr;</a></p></div>';
    });
    add_action('admin_bar_menu', function ($bar) {
        $bar->add_node([
            'id' => 'wpl-template',
            'title' => 'Template: ' . esc_html(WPL_SITE['blueprint_name']),
            'href' => WPL_SITE['panel_url'],
        ]);
    }, 100);
}
