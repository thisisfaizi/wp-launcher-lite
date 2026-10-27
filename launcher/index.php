<?php
/**
 * WP Launcher Lite — launcher, panel and installer front controller.
 *
 * Kept free of PHP 7+ syntax up to the version check, so a host still on an
 * old PHP gets a readable message instead of a parse error.
 */
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    header('Content-Type: text/plain', true, 500);
    echo 'WP Launcher Lite needs PHP 8.0 or newer. This server runs PHP ' . PHP_VERSION . '. Choose a newer PHP version in your hosting control panel.';
    exit;
}

require __DIR__ . '/app/bootstrap.php';
require WPL_APP . '/routes.php';

wpl_handle_request();
