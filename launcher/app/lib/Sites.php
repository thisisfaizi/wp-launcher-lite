<?php

namespace Wpl;

/**
 * Site lifecycle: templates, demo launches, autologin, expiry and cleanup.
 *
 * Every site — demo or template — is described to WordPress by
 * wpl-data/sites/<slug>/site.php, which the dispatcher reads per request.
 * Deleting that file is what takes a site offline, so it goes first.
 */
class Sites
{
    public static function dataDir(string $slug): string
    {
        return WPL_DATA . '/sites/' . $slug;
    }

    public static function uploadsDir(string $slug): string
    {
        return WPL_UPLOADS . '/' . $slug;
    }

    public static function isSlug(string $s): bool
    {
        return (bool) preg_match('/^' . WPL_SLUG_RE . '$/', $s);
    }

    /** A fresh slug that no site — live or deleted — has used. */
    public static function newSlug(): string
    {
        for ($i = 0; $i < 20; $i++) {
            // Leading letter: never all digits, and never mistaken for an id.
            $slug = random_str(1, 'abcdefghijklmnopqrstuvwxyz') . random_str(WPL_SLUG_LENGTH - 1);
            if (!Db::value('SELECT 1 FROM wpl_sites WHERE slug = ?', [$slug]) && !is_dir(self::dataDir($slug))) {
                return $slug;
            }
        }
        throw new \RuntimeException('Could not generate a unique site id.');
    }

    public static function find(string $slug): ?array
    {
        return Db::one('SELECT * FROM wpl_sites WHERE slug = ?', [$slug]);
    }

    public static function url(string $slug): string
    {
        return Config::siteUrl($slug);
    }

    // ── Templates ────────────────────────────────────────────────────────

    public static function createTemplate(string $blueprintId, string $slug, array $config): void
    {
        Db::insert('wpl_sites', [
            'slug' => $slug,
            'blueprint_id' => $blueprintId,
            'kind' => 'template',
            'status' => 'active',
            'admin_user' => $config['demo']['admin_user'],
            'admin_password' => random_password(20),
            'created_at' => time(),
            'expires_at' => 0,
        ]);
        Fs::ensureDir(self::dataDir($slug));
        Fs::ensureDir(self::uploadsDir($slug));
        self::writeConfig($slug);
    }

    /** One-time URL that builds (or finishes building) a template in the browser. */
    public static function setupUrl(string $slug): string
    {
        $site = self::find($slug);
        $bp = $site ? Blueprints::find($site['blueprint_id']) : null;
        if (!$bp) {
            throw new \RuntimeException('Unknown template.');
        }
        $token = random_str(40);
        $file = self::dataDir($slug) . '/setup.json';
        $existing = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        file_put_contents($file, json_encode([
            'hash' => hash('sha256', $token),
            'expires' => time() + 3600,
            'title' => $bp['name'],
            'admin_user' => $bp['config']['demo']['admin_user'],
            'admin_email' => $bp['config']['demo']['admin_email'],
            'admin_password' => $site['admin_password'],
            'plan' => Blueprints::setupPlan($bp['config']),
            // Resume where a previous attempt stopped.
            'step' => (int) ($existing['step'] ?? 0),
            'return_url' => url('admin/blueprints/' . $bp['id']),
        ]));
        return self::url($slug) . '/wpl-setup.php?token=' . $token;
    }

    // ── Demos ────────────────────────────────────────────────────────────

    /**
     * Launch a demo for a visitor. Limits are checked under a lock, so two
     * clicks at once cannot both take the last free slot.
     */
    public static function launch(array $bp, string $ownerHash, string $email, string $ip): array
    {
        if (!$bp['ready'] || !$bp['enabled']) {
            throw new UserError('This demo is not available right now.');
        }
        $lock = fopen(WPL_DATA . '/launch.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            self::checkLimits($bp, $ownerHash, $ip);

            $slug = self::newSlug();
            $config = $bp['config'];
            $ttl = parse_duration((string) $config['demo']['default_expiration']);
            $password = random_password(16);
            $now = time();
            Db::insert('wpl_sites', [
                'slug' => $slug,
                'blueprint_id' => $bp['id'],
                'kind' => 'demo',
                'status' => 'creating',
                'owner_hash' => $ownerHash,
                'email' => $email !== '' ? $email : null,
                'ip' => $ip,
                'admin_user' => $config['demo']['admin_user'],
                'admin_password' => $password,
                'created_at' => $now,
                'expires_at' => $ttl > 0 ? $now + $ttl : 0,
            ]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $from = $bp['template_slug'];
        try {
            Fs::ensureDir(self::dataDir($slug));
            SiteDb::cloneSite($from, $slug);
            $login = SiteDb::resetAdmin($slug, $config['demo']['admin_user'], $password);
            if ($login !== $config['demo']['admin_user']) {
                Db::update('wpl_sites', ['admin_user' => $login], 'slug = ?', [$slug]);
            }
            Fs::copyTree(self::uploadsDir($from), self::uploadsDir($slug), [$from => $slug]);
            Fs::ensureDir(self::uploadsDir($slug));
            self::writeConfig($slug);
            Db::update('wpl_sites', ['status' => 'active'], 'slug = ?', [$slug]);
        } catch (\Throwable $e) {
            self::purge($slug);
            Db::update('wpl_sites', ['status' => 'deleted', 'deleted_at' => time()], 'slug = ?', [$slug]);
            Log::event('site.failed', 'Launch of "' . $bp['name'] . '" failed: ' . $e->getMessage(), $slug, $ip);
            throw $e;
        }

        $site = self::find($slug);
        Log::event('site.created', 'Demo of "' . $bp['name'] . '" launched' . ($email !== '' ? ' for ' . $email : ''), $slug, $ip);
        Webhook::send('site.created', self::publicData($site, $bp));
        return $site;
    }

    private static function checkLimits(array $bp, string $ownerHash, string $ip): void
    {
        $s = Settings::all();
        $active = "status IN ('active', 'creating') AND kind = 'demo'";

        if ($s['max_active_total'] > 0 && Db::value("SELECT COUNT(*) FROM wpl_sites WHERE $active") >= $s['max_active_total']) {
            throw new UserError('All demo slots are in use right now. Please try again in a few minutes.');
        }
        $perBp = (int) $bp['config']['demo']['max_concurrent_sites'];
        if ($perBp > 0 && Db::value("SELECT COUNT(*) FROM wpl_sites WHERE $active AND blueprint_id = ?", [$bp['id']]) >= $perBp) {
            throw new UserError('This demo is very popular right now — every slot is taken. Please try again in a few minutes.');
        }
        if ($s['max_active_per_visitor'] > 0 && Db::value("SELECT COUNT(*) FROM wpl_sites WHERE $active AND owner_hash = ?", [$ownerHash]) >= $s['max_active_per_visitor']) {
            throw new UserError('You already have the maximum number of demos running. Use one of them, or wait for it to expire.');
        }
        if ($s['max_per_ip_hour'] > 0 && Db::value("SELECT COUNT(*) FROM wpl_sites WHERE kind = 'demo' AND ip = ? AND created_at > ?", [$ip, time() - 3600]) >= $s['max_per_ip_hour']) {
            throw new UserError('You have launched several demos in the last hour. Please wait a while before launching another.');
        }
    }

    /** Active demos belonging to a visitor. */
    public static function forOwner(string $ownerHash): array
    {
        return Db::all("SELECT * FROM wpl_sites WHERE owner_hash = ? AND kind = 'demo' AND status = 'active' ORDER BY created_at DESC", [$ownerHash]);
    }

    public static function extend(string $slug, int $seconds): void
    {
        $site = self::find($slug);
        if (!$site || $site['kind'] !== 'demo' || $site['status'] !== 'active') {
            throw new UserError('That site is not running.');
        }
        $expires = $seconds <= 0 ? 0 : max(time(), (int) $site['expires_at']) + $seconds;
        Db::update('wpl_sites', ['expires_at' => $expires], 'slug = ?', [$slug]);
        self::writeConfig($slug);
        Log::event('site.extended', 'Expiry set to ' . ($expires ? fmt_time($expires) : 'never'), $slug);
    }

    /** Take a site offline and remove its database and files. */
    public static function delete(string $slug, string $reason = 'deleted'): void
    {
        $site = self::find($slug);
        if (!$site || $site['status'] === 'deleted') {
            return;
        }
        self::purge($slug);
        Db::update('wpl_sites', ['status' => 'deleted', 'deleted_at' => time(), 'admin_password' => null], 'slug = ?', [$slug]);
        if ($site['kind'] === 'demo') {
            Log::event($reason === 'expired' ? 'site.expired' : 'site.deleted', 'Demo ' . $reason, $slug);
            $bp = Blueprints::find($site['blueprint_id']);
            Webhook::send($reason === 'expired' ? 'site.expired' : 'site.deleted', self::publicData($site, $bp));
        }
    }

    /** Remove everything a site owns. Idempotent. */
    private static function purge(string $slug): void
    {
        // Config first: from this moment the site answers "expired".
        @unlink(self::dataDir($slug) . '/site.php');
        try {
            SiteDb::drop($slug);
        } catch (\Throwable $e) {
            Log::event('error', 'Dropping tables failed: ' . $e->getMessage(), $slug);
        }
        Fs::trash(self::dataDir($slug));
        Fs::trash(self::uploadsDir($slug));
    }

    /**
     * Expire due demos and empty the trash, within a time budget. Called from
     * cron.php and, for hosts without cron, lazily from panel page views.
     */
    public static function cleanup(float $deadline): array
    {
        $expired = 0;
        $due = Db::all("SELECT slug FROM wpl_sites WHERE kind = 'demo' AND status = 'active' AND expires_at > 0 AND expires_at <= ? ORDER BY expires_at", [time()]);
        foreach ($due as $row) {
            if (microtime(true) > $deadline) {
                break;
            }
            self::delete($row['slug'], 'expired');
            $expired++;
        }
        // Launches that died mid-way (request killed by the host).
        foreach (Db::all("SELECT slug FROM wpl_sites WHERE status = 'creating' AND created_at < ?", [time() - 600]) as $row) {
            self::purge($row['slug']);
            Db::update('wpl_sites', ['status' => 'deleted', 'deleted_at' => time()], 'slug = ?', [$row['slug']]);
        }
        $trashEmpty = Fs::emptyTrash($deadline);
        Db::exec('DELETE FROM wpl_events WHERE created_at < ?', [time() - 90 * 86400]);
        Settings::set('cron_last_run', (string) time());
        return ['expired' => $expired, 'trash_empty' => $trashEmpty];
    }

    /** Run cleanup at most once a minute, for hosts with no cron job. */
    public static function maybeCleanup(): void
    {
        if ((int) Settings::get('cron_last_run') > time() - 60) {
            return;
        }
        $lock = @fopen(WPL_DATA . '/cron.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return;
        }
        try {
            self::cleanup(microtime(true) + 5);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    // ── Login ────────────────────────────────────────────────────────────

    /** A single-use, five-minute URL that logs into the site's admin. */
    public static function loginUrl(string $slug): string
    {
        $token = random_str(40);
        file_put_contents(self::dataDir($slug) . '/login.json', json_encode([
            'hash' => hash('sha256', $token),
            'expires' => time() + 300,
        ]));
        return self::url($slug) . '/wp-login.php?autologin=' . $token;
    }

    // ── Per-site config for WordPress ────────────────────────────────────

    /** (Re)write wpl-data/sites/<slug>/site.php from the database. */
    public static function writeConfig(string $slug): void
    {
        $site = self::find($slug);
        $bp = $site ? Blueprints::find($site['blueprint_id']) : null;
        if (!$site || !$bp) {
            throw new \RuntimeException('Unknown site ' . $slug);
        }
        $file = self::dataDir($slug) . '/site.php';
        $previous = read_data_file($file) ?? [];

        // Salts are minted once; rewriting them would log everyone out.
        $salts = $previous['salts'] ?? [];
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $k) {
            if (empty($salts[$k])) {
                $salts[$k] = random_str(64, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#%^&*()-_=+[]{}<>~;:,.');
            }
        }

        $config = $bp['config'];
        $s = Settings::all();
        $isDemo = $site['kind'] === 'demo';
        $url = self::url($slug);

        write_data_file($file, [
            'slug' => $slug,
            'kind' => $site['kind'],
            'blueprint' => $bp['id'],
            'blueprint_name' => $bp['name'],
            'prefix' => SiteDb::prefix($slug),
            'db_path' => Db::engine() === 'sqlite' ? SiteDb::sqliteFile($slug) : null,
            'url' => $url,
            'content_dir' => Blueprints::contentDir($bp['id']),
            'content_url' => $url . '/_c/' . $bp['id'],
            'uploads_dir' => self::uploadsDir($slug),
            'uploads_url' => $url . '/wp-content/uploads',
            'launcher_url' => url(''),
            'panel_url' => url('admin/blueprints/' . $bp['id']),
            'expires_at' => (int) $site['expires_at'],
            'admin_user' => $site['admin_user'],
            'landing_page' => (string) $config['demo']['landing_page'],
            'blocked_caps' => $isDemo ? Blueprints::blockedCapabilities($config) : ['update_core'],
            'hidden_menus' => $isDemo ? array_values((array) $config['restrictions']['hidden_menu_items']) : [],
            'upload_limit' => $isDemo ? $s['upload_limit_mb'] * 1048576 : 0,
            'disk_quota' => $isDemo ? $s['disk_quota_mb'] * 1048576 : 0,
            'allow_email' => !$isDemo || $s['allow_demo_email'],
            'debug' => (bool) $s['wp_debug'],
            'salts' => $salts,
        ]);
    }

    /** What webhooks receive: no passwords, no tokens. */
    public static function publicData(array $site, ?array $bp): array
    {
        return [
            'slug' => $site['slug'],
            'url' => self::url($site['slug']),
            'blueprint' => $site['blueprint_id'],
            'blueprint_name' => $bp['name'] ?? null,
            'email' => $site['email'],
            'ip' => $site['ip'],
            'created_at' => gmdate('c', (int) $site['created_at']),
            'expires_at' => $site['expires_at'] ? gmdate('c', (int) $site['expires_at']) : null,
        ];
    }
}
