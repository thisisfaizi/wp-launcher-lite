<?php
/**
 * WP Launcher Lite — panel bootstrap.
 *
 * Everything lives under the folder you uploaded. The four runtime folders are
 * created by the installer:
 *
 *   wpl-data/     private: config, panel database, per-site configs and SQLite files
 *   wpl-core/     the one WordPress core every demo site shares
 *   wpl-content/  one wp-content per blueprint (plugins, themes) — shared by its demos
 *   wpl-uploads/  per-site media uploads
 */

define('WPL_VERSION', '0.1.0');
define('WPL_MIN_PHP', '8.0.0');

define('WPL_ROOT', dirname(__DIR__));
define('WPL_APP', __DIR__);
define('WPL_DATA', WPL_ROOT . '/wpl-data');
define('WPL_CORE', WPL_ROOT . '/wpl-core');
define('WPL_CONTENT', WPL_ROOT . '/wpl-content');
define('WPL_UPLOADS', WPL_ROOT . '/wpl-uploads');

// Slugs are fixed-length so a template's URL and a clone's URL differ only in a
// same-length token. That is what lets cloning rewrite URLs with a plain string
// REPLACE, even inside PHP-serialized option values (lengths never change).
define('WPL_SLUG_LENGTH', 10);
define('WPL_SLUG_RE', '[a-z0-9]{10}');

spl_autoload_register(function ($class) {
    if (strncmp($class, 'Wpl\\', 4) !== 0) {
        return;
    }
    $file = WPL_APP . '/lib/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require WPL_APP . '/lib/helpers.php';
