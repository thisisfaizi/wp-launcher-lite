<?php

namespace Wpl;

/**
 * First-run setup: requirement checks, then writing config, folders, routing
 * and the panel database. Downloading WordPress happens afterwards in
 * resumable steps (Core::step), driven by the browser.
 */
class Installer
{
    /** @return array<int, array{label: string, ok: bool, detail: string, fatal: bool}> */
    public static function requirements(): array
    {
        $checks = [];
        $add = function (string $label, bool $ok, string $detail, bool $fatal = true) use (&$checks) {
            $checks[] = compact('label', 'ok', 'detail', 'fatal');
        };

        $add('PHP ' . WPL_MIN_PHP . ' or newer', version_compare(PHP_VERSION, WPL_MIN_PHP, '>='), 'You have PHP ' . PHP_VERSION . '. Pick a newer version in your host\'s "Select PHP version" or "MultiPHP" screen.');
        $add('PDO', extension_loaded('pdo'), 'The PDO extension is required.');
        $sqlite = extension_loaded('pdo_sqlite');
        $mysql = extension_loaded('pdo_mysql') && extension_loaded('mysqli');
        $add('A database driver', $sqlite || $mysql, 'Enable pdo_sqlite, or pdo_mysql and mysqli, in your PHP extensions.');
        $add('SQLite (pdo_sqlite)', $sqlite, $sqlite ? 'Available.' : 'Not available — you will need MySQL.', false);
        $add('MySQL (pdo_mysql + mysqli)', $mysql, $mysql ? 'Available.' : 'Not available — you will need SQLite.', false);
        $add('Zip (ZipArchive)', class_exists('ZipArchive'), 'Needed to unpack WordPress. Enable the zip extension.');
        $add('Outgoing HTTP', function_exists('curl_init') || (bool) ini_get('allow_url_fopen'), 'Needed to download WordPress. Or upload wordpress.zip into wpl-data/ yourself.', false);
        $add('JSON', function_exists('json_encode'), 'The JSON extension is required.');
        $add('mbstring', extension_loaded('mbstring'), 'WordPress works better with mbstring.', false);

        $writable = is_writable(WPL_ROOT);
        $add('Folder is writable', $writable, $writable ? WPL_ROOT : 'PHP cannot write to ' . WPL_ROOT . '. Set the folder to 755 and make sure it is owned by your account.');

        $soft = (string) ($_SERVER['SERVER_SOFTWARE'] ?? '');
        $apacheLike = stripos($soft, 'apache') !== false || stripos($soft, 'litespeed') !== false;
        $add('Web server reads .htaccess', $apacheLike, $apacheLike ? $soft : ($soft ?: 'Unknown server') . ' — nginx needs the rules in docs/nginx.md.', false);

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $add('No shell needed', true, 'WP Launcher Lite never uses exec() or wp-cli' . (in_array('exec', $disabled, true) ? ' (and yours is disabled — that is fine).' : '.'), false);
        return $checks;
    }

    public static function requirementsMet(): bool
    {
        foreach (self::requirements() as $c) {
            if ($c['fatal'] && !$c['ok']) {
                return false;
            }
        }
        return true;
    }

    /**
     * Validate and apply the installer form. Throws UserError with a message
     * for the operator when something is wrong.
     *
     * @param array{base_url: string, url_mode: string, base_domain: string, engine: string, mysql: array, admin_email: string, admin_password: string} $in
     */
    public static function configure(array $in): void
    {
        $baseUrl = rtrim(trim($in['base_url']), '/');
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#', $baseUrl)) {
            throw new UserError('The launcher URL must be a full URL such as https://example.com/demos.');
        }
        $mode = $in['url_mode'] === 'subdomain' ? 'subdomain' : 'path';
        $domain = strtolower(trim($in['base_domain'], " .\t\n"));
        if ($mode === 'subdomain' && !preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $domain)) {
            throw new UserError('Enter the domain demos live under, e.g. demo.example.com (sites become abc….demo.example.com).');
        }
        if (!filter_var($in['admin_email'], FILTER_VALIDATE_EMAIL)) {
            throw new UserError('Enter a valid email address for your admin login.');
        }
        if (strlen($in['admin_password']) < 10) {
            throw new UserError('Use an admin password of at least 10 characters.');
        }

        $engine = $in['engine'] === 'mysql' ? 'mysql' : 'sqlite';
        $mysql = null;
        if ($engine === 'mysql') {
            $mysql = [
                'host' => trim($in['mysql']['host'] ?? '') ?: 'localhost',
                'port' => trim($in['mysql']['port'] ?? ''),
                'name' => trim($in['mysql']['name'] ?? ''),
                'user' => trim($in['mysql']['user'] ?? ''),
                'pass' => (string) ($in['mysql']['pass'] ?? ''),
            ];
            try {
                Db::connectMysql($mysql)->query('SELECT 1');
            } catch (\Throwable $e) {
                throw new UserError('Could not connect to MySQL: ' . $e->getMessage());
            }
        } elseif (!extension_loaded('pdo_sqlite')) {
            throw new UserError('SQLite is not available on this server. Choose MySQL.');
        }

        foreach ([WPL_DATA, WPL_DATA . '/sites', WPL_DATA . '/tmp', WPL_DATA . '/logs', WPL_DATA . '/vendor', WPL_CONTENT, WPL_UPLOADS, WPL_CORE] as $dir) {
            Fs::ensureDir($dir);
        }

        Config::save([
            'installed' => false,
            'version' => WPL_VERSION,
            'base_url' => $baseUrl,
            'url_mode' => $mode,
            'base_domain' => $mode === 'subdomain' ? $domain : '',
            'engine' => $engine,
            'mysql' => $mysql,
            'secret' => random_str(64),
            'cron_key' => random_str(32),
            'created_at' => time(),
        ]);
        Htaccess::writeSubdirRules();
        Db::migrate();
        Auth::setCredentials($in['admin_email'], $in['admin_password']);
        Settings::set('webhook_secret', random_str(40));
        Log::event('install', 'Install configured (' . $engine . ', ' . $mode . ' URLs)');
    }

    /** After WordPress is in place: turn routing on and mark the install done. */
    public static function finish(): void
    {
        if (!Core::installed()) {
            throw new UserError('WordPress is not installed yet.');
        }
        if (Db::engine() === 'sqlite' && !Core::sqliteInstalled()) {
            throw new UserError('The SQLite driver is not installed yet.');
        }
        Core::writeShims();
        Config::update(['installed' => true]);
        Htaccess::write();
        // Probe file used by the routing self-test (must NOT be reachable).
        file_put_contents(WPL_DATA . '/probe.txt', 'private');
        Log::event('install', 'Install finished with WordPress ' . Core::version());
    }
}
