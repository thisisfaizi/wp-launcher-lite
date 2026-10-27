<?php
/**
 * Plugin Name: WP Launcher Lite — Countdown
 * Description: Live "demo expires in" timer in the admin bar, ported from WP Launcher. Sends the visitor back to the launcher when time is up.
 * Version: 0.1.0
 */

defined('ABSPATH') || exit;

if (!defined('WPL_SITE') || WPL_SITE['kind'] !== 'demo' || WPL_SITE['expires_at'] <= 0) {
    return;
}

add_action('admin_bar_menu', function ($bar) {
    $bar->add_node([
        'id' => 'wpl-timer',
        'title' => '<span id="wpl-countdown" data-expires="' . esc_attr((string) WPL_SITE['expires_at']) . '">Demo</span>',
        'href' => esc_url(WPL_SITE['launcher_url']),
        'meta' => ['class' => 'wpl-timer-node', 'title' => 'This demo deletes itself when the timer ends'],
    ]);
}, 100);

add_action('wp_footer', 'wpl_countdown_assets');
add_action('admin_footer', 'wpl_countdown_assets');

function wpl_countdown_assets(): void
{
    if (!is_admin_bar_showing()) {
        return;
    }
    $launcher = esc_js(WPL_SITE['launcher_url']);
    ?>
    <style>
        #wpadminbar .wpl-timer-node { background: #059669 !important; transition: background-color .5s ease; }
        #wpadminbar .wpl-timer-node > a { color: #fff !important; font-weight: 600; font-variant-numeric: tabular-nums; }
        #wpadminbar .wpl-timer-node.wpl-warn { background: #d97706 !important; }
        #wpadminbar .wpl-timer-node.wpl-crit { background: #dc2626 !important; }
    </style>
    <script>
    (function () {
        var el = document.getElementById('wpl-countdown');
        if (!el) return;
        var node = el.closest('.wpl-timer-node');
        var expires = parseInt(el.getAttribute('data-expires'), 10) * 1000;
        function pad(n) { return n < 10 ? '0' + n : n; }
        function tick() {
            var left = expires - Date.now();
            if (left <= 0) {
                el.textContent = 'Demo expired';
                if (node) node.className = node.className.replace(/wpl-warn/, '') + ' wpl-crit';
                window.location.href = '<?php echo $launcher; ?>';
                return;
            }
            var h = Math.floor(left / 3600000), m = Math.floor(left % 3600000 / 60000), s = Math.floor(left % 60000 / 1000);
            el.textContent = 'Demo ends in ' + (h > 0 ? h + 'h ' : '') + pad(m) + 'm ' + pad(s) + 's';
            if (node) {
                var mins = left / 60000;
                node.classList.toggle('wpl-crit', mins <= 5);
                node.classList.toggle('wpl-warn', mins > 5 && mins <= 30);
            }
            setTimeout(tick, 1000);
        }
        tick();
    })();
    </script>
    <?php
}
