<?php
/**
 * Plugin Name: WP Launcher Lite — Auto Login
 * Description: One-click admin login via a single-use token issued by the launcher (wp-login.php?autologin=TOKEN).
 * Version: 0.1.0
 */

defined('ABSPATH') || exit;

if (!defined('WPL_SITE')) {
    return;
}

add_action('login_init', function () {
    if (empty($_GET['autologin']) || !is_string($_GET['autologin'])) {
        return;
    }
    // Stored per site, hashed, with a five-minute life — not in /tmp, which
    // a shared host may share with other sites or other accounts.
    $file = WPL_DATA . '/sites/' . WPL_SITE['slug'] . '/login.json';
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $valid = is_array($data)
        && (int) ($data['expires'] ?? 0) > time()
        && hash_equals((string) ($data['hash'] ?? ''), hash('sha256', wp_unslash($_GET['autologin'])));
    if (!$valid) {
        wp_die(
            'This login link has expired or was already used. <a href="' . esc_url(WPL_SITE['launcher_url']) . '">Back to the launcher</a>.',
            'Login link expired',
            ['response' => 403]
        );
    }
    @unlink($file); // single use

    $user = get_user_by('login', WPL_SITE['admin_user']);
    if (!$user) {
        // Template whose admin was renamed: fall back to the first administrator.
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID']);
        $user = $admins ? $admins[0] : null;
    }
    if (!$user) {
        wp_die('No administrator account was found on this site.', 'Login failed', ['response' => 403]);
    }

    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, false);
    do_action('wp_login', $user->user_login, $user);

    $landing = WPL_SITE['landing_page'];
    $to = admin_url();
    if ($landing !== '') {
        $to = preg_match('#^https?://#', $landing) ? $landing : home_url('/' . ltrim($landing, '/'));
    }
    wp_safe_redirect($to);
    exit;
});
