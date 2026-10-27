<?php
/**
 * WP Launcher Lite — cleanup job. Deletes expired demos and empties the trash.
 *
 * cPanel → Cron Jobs, every 5 minutes:
 *     php /home/USER/public_html/demos/cron.php >/dev/null 2>&1
 *
 * Hosts whose cron can only fetch a URL can use the "cron URL" shown under
 * Settings instead. Without either, demos are still cleaned up lazily
 * whenever someone opens the launcher or panel.
 */
if (PHP_SAPI !== 'cli') {
    // Over the web, use /cron?key=… (routed through index.php).
    http_response_code(404);
    exit;
}
require __DIR__ . '/app/bootstrap.php';

if (!Wpl\Config::installed()) {
    fwrite(STDERR, "WP Launcher Lite is not installed.\n");
    exit(1);
}
$lock = fopen(WPL_DATA . '/cron.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // a previous run is still going
}
$result = Wpl\Sites::cleanup(microtime(true) + 50);
echo json_encode($result), "\n";
