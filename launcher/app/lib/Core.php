<?php

namespace Wpl;

/**
 * The shared WordPress core (wpl-core/) and the SQLite driver.
 *
 * Installing and updating both run as resumable steps: shared hosts cut a
 * request off after 30–60 seconds, and WordPress is ~4,000 files. The browser
 * (or cron) calls step() until it reports done.
 */
class Core
{
    const WP_ZIP_URL = 'https://wordpress.org/latest.zip';
    const SQLITE_ZIP_URL = 'https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip';

    public static function version(): ?string
    {
        $file = WPL_CORE . '/wp-includes/version.php';
        if (!is_file($file)) {
            return null;
        }
        return preg_match('/\$wp_version\s*=\s*\'([^\']+)\'/', (string) file_get_contents($file), $m) ? $m[1] : null;
    }

    public static function installed(): bool
    {
        return self::version() !== null && is_file(WPL_CORE . '/wp-settings.php');
    }

    public static function sqliteDir(): string
    {
        return WPL_DATA . '/vendor/sqlite-database-integration';
    }

    public static function sqliteInstalled(): bool
    {
        return is_file(self::sqliteDir() . '/wp-includes/sqlite/db.php');
    }

    /** Latest WordPress version on wordpress.org, cached for 12 hours. */
    public static function latestVersion(): ?string
    {
        $cached = Settings::get('core_latest');
        $checked = (int) Settings::get('core_latest_checked');
        if ($cached && $checked > time() - 43200) {
            return $cached;
        }
        try {
            $tmp = WPL_DATA . '/tmp/version-check.json';
            Fs::download('https://api.wordpress.org/core/version-check/1.7/', $tmp, 10);
            $json = json_decode((string) file_get_contents($tmp), true);
            @unlink($tmp);
            $v = $json['offers'][0]['version'] ?? null;
            if ($v) {
                Settings::set('core_latest', $v);
                Settings::set('core_latest_checked', (string) time());
            }
            return $v;
        } catch (\Throwable $e) {
            return $cached ?: null;
        }
    }

    /**
     * Run one slice of the install/update. $state is what the previous call
     * returned (empty to start). Returns the next state with 'done', 'progress'
     * (0–100) and 'message'.
     */
    public static function step(array $state, bool $withSqlite): array
    {
        $deadline = microtime(true) + 15;
        $phase = $state['phase'] ?? 'download';
        $zip = WPL_DATA . '/tmp/wordpress.zip';
        $staging = WPL_DATA . '/tmp/core-new';

        switch ($phase) {
            case 'download':
                // An operator whose host blocks outgoing requests can upload
                // wordpress.zip to wpl-data/ by hand instead.
                $manual = WPL_DATA . '/wordpress.zip';
                if (is_file($manual)) {
                    Fs::ensureDir(dirname($zip));
                    rename($manual, $zip);
                } elseif (!is_file($zip)) {
                    $r = Fs::downloadStep(self::WP_ZIP_URL, $zip, $deadline);
                    if (!$r['done']) {
                        $mb = round($r['bytes'] / 1048576, 1);
                        $of = $r['total'] ? ' of ' . round($r['total'] / 1048576, 1) . ' MB' : ' MB';
                        $pct = $r['total'] ? (int) (15 * $r['bytes'] / $r['total']) : 5;
                        return ['phase' => 'download', 'done' => false, 'progress' => $pct, 'message' => "Downloading WordPress ($mb$of)…"];
                    }
                }
                if (is_dir($staging)) {
                    Fs::trash($staging);
                }
                return ['phase' => 'extract', 'next' => 0, 'done' => false, 'progress' => 15, 'message' => 'Downloaded WordPress. Extracting…'];

            case 'extract':
                $r = Fs::extractZip($zip, $staging, (int) ($state['next'] ?? 0), $deadline, [self::class, 'mapCoreEntry']);
                if ($r['next'] < $r['total']) {
                    $pct = 15 + (int) (65 * $r['next'] / max(1, $r['total']));
                    return ['phase' => 'extract', 'next' => $r['next'], 'done' => false, 'progress' => $pct, 'message' => "Extracting WordPress ({$r['next']} / {$r['total']} files)…"];
                }
                return ['phase' => 'swap', 'done' => false, 'progress' => 82, 'message' => 'Activating the new core…'];

            case 'swap':
                if (!is_file($staging . '/wp-settings.php')) {
                    throw new \RuntimeException('The WordPress download looks incomplete. Retry the install.');
                }
                // Keep the bundled default themes outside the core: new
                // blueprints copy their starting theme from here.
                if (is_dir($staging . '/wp-content/themes')) {
                    Fs::trash(WPL_DATA . '/vendor/themes');
                    Fs::ensureDir(WPL_DATA . '/vendor');
                    rename($staging . '/wp-content/themes', WPL_DATA . '/vendor/themes');
                }
                Fs::trash($staging . '/wp-content');
                // Two renames: the core is only missing for an instant.
                $old = WPL_DATA . '/tmp/core-old';
                if (is_dir(WPL_CORE)) {
                    @chmod(WPL_CORE . '/.htaccess', 0644);
                    Fs::trash($old);
                    rename(WPL_CORE, $old);
                }
                rename($staging, WPL_CORE);
                self::writeShims();
                Fs::trash($old);
                @unlink($zip);
                if ($withSqlite && !self::sqliteInstalled()) {
                    return ['phase' => 'sqlite', 'done' => false, 'progress' => 88, 'message' => 'Installing the SQLite driver…'];
                }
                return ['phase' => 'finished', 'done' => true, 'progress' => 100, 'message' => 'WordPress ' . self::version() . ' is ready.'];

            case 'sqlite':
                self::installSqlite($deadline + 20);
                return ['phase' => 'finished', 'done' => true, 'progress' => 100, 'message' => 'WordPress ' . self::version() . ' is ready.'];
        }
        return ['phase' => 'finished', 'done' => true, 'progress' => 100, 'message' => 'Done.'];
    }

    /** Strip "wordpress/" and leave out the bundled plugins we never use. */
    public static function mapCoreEntry(string $name): ?string
    {
        if (strncmp($name, 'wordpress/', 10) !== 0) {
            return null;
        }
        $rel = substr($name, 10);
        if ($rel === '' || strncmp($rel, 'wp-content/plugins/', 19) === 0) {
            return null;
        }
        return $rel;
    }

    public static function installSqlite(float $deadline): void
    {
        $zip = WPL_DATA . '/tmp/sqlite.zip';
        $manual = WPL_DATA . '/sqlite-database-integration.zip';
        if (is_file($manual)) {
            rename($manual, $zip);
        } elseif (!is_file($zip)) {
            Fs::download(self::SQLITE_ZIP_URL, $zip, 30);
        }
        $dest = WPL_DATA . '/tmp/sqlite-new';
        Fs::trash($dest);
        $r = Fs::extractZip($zip, $dest, 0, $deadline, function ($name) {
            return strncmp($name, 'sqlite-database-integration/', 28) === 0 ? substr($name, 28) : null;
        });
        if ($r['next'] < $r['total']) {
            throw new \RuntimeException('Extracting the SQLite driver timed out. Retry.');
        }
        Fs::ensureDir(dirname(self::sqliteDir()));
        Fs::trash(self::sqliteDir());
        rename($dest, self::sqliteDir());
        @unlink($zip);
    }

    /**
     * Our files inside the core. They are rewritten after every core update,
     * so they stay one-line includes of code that lives in app/.
     */
    public static function writeShims(): void
    {
        file_put_contents(WPL_CORE . '/wp-config.php', "<?php\n// WP Launcher Lite: every site shares this core. The per-site configuration\n// is chosen per request in app/wp/dispatch.php.\nrequire dirname(__DIR__) . '/app/wp/dispatch.php';\n");
        file_put_contents(WPL_CORE . '/wpl-setup.php', "<?php\n// WP Launcher Lite: builds a blueprint's template site. Token-protected.\nrequire dirname(__DIR__) . '/app/wp/setup.php';\n");
        file_put_contents(WPL_CORE . '/wpl-ping.php', "<?php\n// WP Launcher Lite: routing self-test. Loads nothing.\nheader('Content-Type: text/plain');\nheader('Cache-Control: no-store');\n// Subdomain installs test this from the panel's origin.\nheader('Access-Control-Allow-Origin: *');\necho 'WPL-ROUTE-OK';\n");
        // Never let anyone run the stock installer against the shared core.
        @unlink(WPL_CORE . '/wp-config-sample.php');
        @unlink(WPL_CORE . '/readme.html');
        @unlink(WPL_CORE . '/license.txt');
        Htaccess::writeSubdirRules();
    }

    /** The drop-in that points a blueprint's wp-content at the SQLite driver. */
    public static function sqliteDropin(): string
    {
        $copy = (string) file_get_contents(self::sqliteDir() . '/db.copy');
        return str_replace(
            ['{SQLITE_IMPLEMENTATION_FOLDER_PATH}', '{SQLITE_PLUGIN}'],
            [self::sqliteDir(), 'sqlite-database-integration/load.php'],
            $copy
        );
    }

    /** Folder name of the theme WordPress treats as its default. */
    public static function defaultTheme(): ?string
    {
        $constants = WPL_CORE . '/wp-includes/default-constants.php';
        if (is_file($constants) && preg_match("/'WP_DEFAULT_THEME',\s*'([^']+)'/", (string) file_get_contents($constants), $m)) {
            if (is_dir(WPL_DATA . '/vendor/themes/' . $m[1])) {
                return $m[1];
            }
        }
        $themes = glob(WPL_DATA . '/vendor/themes/*', GLOB_ONLYDIR) ?: [];
        rsort($themes);
        return $themes ? basename($themes[0]) : null;
    }
}
