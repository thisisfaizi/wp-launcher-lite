<?php

namespace Wpl;

/**
 * Blueprints: what a demo is made from.
 *
 * Upstream WP Launcher bakes plugins into a Docker image. Here each blueprint
 * owns a *template site* — a real WordPress install the operator can log into
 * and set up however they like — plus a wp-content folder (plugins, themes)
 * that its demos share read-only. Launching a demo copies the template's
 * database and media, never touching wordpress.org.
 *
 * The config column holds the upstream blueprint JSON format, so upstream
 * blueprints import unchanged.
 */
class Blueprints
{
    /**
     * Upstream's restriction toggles and the capabilities each one blocks.
     * Grouped because blocking install_plugins while allowing update_plugins
     * is no restriction at all: an "update" can carry arbitrary code.
     */
    const CAPABILITY_GROUPS = [
        'install_plugins' => ['install_plugins', 'update_plugins', 'delete_plugins'],
        'install_themes' => ['install_themes', 'update_themes', 'delete_themes'],
        'edit_plugins' => ['edit_plugins', 'edit_files'],
        'edit_themes' => ['edit_themes', 'edit_files'],
        'update_core' => ['update_core'],
        'export' => ['export'],
        'import' => ['import'],
    ];

    const TOGGLE_LABELS = [
        'install_plugins' => 'Install, update or delete plugins',
        'install_themes' => 'Install, update or delete themes',
        'edit_plugins' => 'Plugin file editor',
        'edit_themes' => 'Theme file editor',
        'update_core' => 'Update WordPress',
        'export' => 'Export content',
        'import' => 'Import content',
    ];

    /**
     * Blocked on every demo whatever the blueprint says. Demos run as the same
     * PHP user as this panel, so anything that lets a visitor write code to
     * disk would hand them the whole hosting account.
     */
    const ALWAYS_BLOCKED = [
        'install_plugins', 'update_plugins', 'delete_plugins',
        'install_themes', 'update_themes', 'delete_themes',
        'edit_plugins', 'edit_themes', 'edit_files', 'update_core',
        'unfiltered_upload', 'unfiltered_html',
    ];

    public static function defaults(): array
    {
        return [
            'name' => 'New blueprint',
            'description' => '',
            'image_url' => '',
            'demo' => [
                'default_expiration' => '1h',
                'max_concurrent_sites' => 10,
                'admin_user' => 'demo',
                'admin_email' => 'demo@example.com',
                'landing_page' => '',
            ],
            'restrictions' => [
                'disable_file_mods' => true,
                'blocked_capabilities' => array_keys(self::CAPABILITY_GROUPS),
                'hidden_menu_items' => ['tools.php'],
            ],
            'plugins' => ['preinstall' => [], 'remove' => []],
            'themes' => ['install' => []],
            'branding' => ['banner_text' => ''],
        ];
    }

    /** Merge stored config over defaults so every key is present. */
    public static function normalize(array $config): array
    {
        $d = self::defaults();
        $out = array_replace($d, $config);
        foreach (['demo', 'restrictions', 'plugins', 'themes', 'branding'] as $k) {
            $out[$k] = array_replace($d[$k], is_array($config[$k] ?? null) ? $config[$k] : []);
        }
        // Upstream keeps description and image under branding.
        if ($out['description'] === '' && !empty($out['branding']['description'])) {
            $out['description'] = $out['branding']['description'];
        }
        if ($out['image_url'] === '' && !empty($out['branding']['image_url'])) {
            $out['image_url'] = $out['branding']['image_url'];
        }
        $out['demo']['admin_user'] = self::cleanUser((string) $out['demo']['admin_user']);
        return $out;
    }

    public static function cleanUser(string $user): string
    {
        $user = preg_replace('/[^a-z0-9_.@-]/', '', strtolower($user));
        return $user !== '' ? substr($user, 0, 60) : 'demo';
    }

    public static function all(bool $enabledOnly = false): array
    {
        $rows = Db::all('SELECT * FROM wpl_blueprints' . ($enabledOnly ? ' WHERE enabled = 1' : '') . ' ORDER BY sort_order, name');
        return array_map([self::class, 'hydrate'], $rows);
    }

    public static function find(string $id): ?array
    {
        $row = Db::one('SELECT * FROM wpl_blueprints WHERE id = ?', [$id]);
        return $row ? self::hydrate($row) : null;
    }

    private static function hydrate(array $row): array
    {
        $row['config'] = self::normalize(json_decode((string) $row['config'], true) ?: []);
        $row['ready'] = self::templateBuilt($row['template_slug']);
        return $row;
    }

    public static function templateBuilt(string $slug): bool
    {
        return is_file(WPL_DATA . '/sites/' . $slug . '/installed');
    }

    public static function contentDir(string $id): string
    {
        return WPL_CONTENT . '/' . $id;
    }

    /** Slugify a name into a blueprint id that is unique. */
    public static function newId(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $base = substr($base !== '' ? $base : 'blueprint', 0, 32);
        $id = $base;
        for ($n = 2; self::find($id) || is_dir(self::contentDir($id)); $n++) {
            $id = $base . '-' . $n;
        }
        return $id;
    }

    /**
     * Create a blueprint and its (not yet built) template site. The operator
     * is then sent to the template's setup URL to build it in the browser.
     */
    public static function create(array $config): array
    {
        $config = self::normalize($config);
        $id = self::newId($config['name']);
        $slug = Sites::newSlug();

        // The blueprint's shared wp-content, seeded with the core's default theme.
        $content = self::contentDir($id);
        Fs::ensureDir($content . '/plugins');
        Fs::ensureDir($content . '/themes');
        Fs::ensureDir($content . '/mu-plugins');
        file_put_contents($content . '/index.php', "<?php\n// Silence is golden.\n");
        $theme = Core::defaultTheme();
        if ($theme) {
            Fs::copyTree(WPL_DATA . '/vendor/themes/' . $theme, $content . '/themes/' . $theme);
        }
        if (Db::engine() === 'sqlite') {
            file_put_contents($content . '/db.php', Core::sqliteDropin());
        }

        $now = time();
        Db::insert('wpl_blueprints', [
            'id' => $id,
            'name' => $config['name'],
            'template_slug' => $slug,
            'status' => 'building',
            'enabled' => 1,
            'sort_order' => (int) Db::value('SELECT COUNT(*) FROM wpl_blueprints'),
            'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Sites::createTemplate($id, $slug, $config);
        Log::event('blueprint.created', 'Blueprint "' . $config['name'] . '" created', $slug);
        return self::find($id);
    }

    public static function update(string $id, array $config, ?bool $enabled = null): void
    {
        $config = self::normalize($config);
        $row = ['name' => $config['name'], 'config' => json_encode($config, JSON_UNESCAPED_SLASHES), 'updated_at' => time()];
        if ($enabled !== null) {
            $row['enabled'] = $enabled ? 1 : 0;
        }
        Db::update('wpl_blueprints', $row, 'id = ?', [$id]);
        // The template's admin username follows the blueprint.
        $bp = self::find($id);
        if ($bp) {
            Sites::writeConfig($bp['template_slug']);
        }
    }

    /** Delete a blueprint, its demos, template and shared files. */
    public static function delete(string $id): void
    {
        $bp = self::find($id);
        if (!$bp) {
            return;
        }
        foreach (Db::all("SELECT slug FROM wpl_sites WHERE blueprint_id = ? AND status <> 'deleted'", [$id]) as $s) {
            Sites::delete($s['slug'], 'blueprint deleted');
        }
        Fs::trash(self::contentDir($id));
        Db::exec('DELETE FROM wpl_blueprints WHERE id = ?', [$id]);
        Log::event('blueprint.deleted', 'Blueprint "' . $bp['name'] . '" deleted', $bp['template_slug']);
    }

    /** Capabilities a demo of this blueprint has removed. */
    public static function blockedCapabilities(array $config): array
    {
        $caps = self::ALWAYS_BLOCKED;
        foreach ((array) ($config['restrictions']['blocked_capabilities'] ?? []) as $toggle) {
            // Unknown toggles are ignored: the blueprint may come from a newer version.
            foreach (self::CAPABILITY_GROUPS[$toggle] ?? [] as $cap) {
                $caps[] = $cap;
            }
        }
        $caps = array_values(array_unique($caps));
        sort($caps);
        return $caps;
    }

    /** The plan the template setup script works through, one step per item. */
    public static function setupPlan(array $config): array
    {
        $plan = [];
        foreach ((array) $config['plugins']['preinstall'] as $p) {
            $ref = self::sourceRef($p);
            if ($ref) {
                $plan[] = ['type' => 'plugin', 'ref' => $ref, 'activate' => !empty($p['activate'])];
            }
        }
        foreach ((array) $config['themes']['install'] as $t) {
            $ref = self::sourceRef($t);
            if ($ref) {
                $plan[] = ['type' => 'theme', 'ref' => $ref, 'activate' => !empty($t['activate'])];
            }
        }
        return $plan;
    }

    /** wordpress.org slug or zip URL; local paths are not portable to a shared host. */
    private static function sourceRef(array $item): ?string
    {
        $source = $item['source'] ?? 'wordpress.org';
        if ($source === 'wordpress.org' && !empty($item['slug']) && preg_match('/^[a-z0-9-]+$/', $item['slug'])) {
            return $item['slug'];
        }
        if ($source === 'url' && !empty($item['url']) && preg_match('#^https?://#', $item['url'])) {
            return $item['url'];
        }
        return null;
    }

    /** Parse the "one per line" plugin box: `slug`, `slug:inactive` or a zip URL. */
    public static function parsePluginLines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $activate = true;
            if (preg_match('/^(.*?)\s*:\s*inactive$/i', $line, $m)) {
                $line = $m[1];
                $activate = false;
            }
            if (preg_match('#^https?://#', $line)) {
                $out[] = ['source' => 'url', 'url' => $line, 'activate' => $activate];
            } elseif (preg_match('/^[a-z0-9-]+$/', strtolower($line))) {
                $out[] = ['source' => 'wordpress.org', 'slug' => strtolower($line), 'activate' => $activate];
            }
        }
        return $out;
    }

    public static function pluginLines(array $config): string
    {
        $lines = [];
        foreach ((array) $config['plugins']['preinstall'] as $p) {
            $ref = ($p['source'] ?? '') === 'url' ? ($p['url'] ?? '') : ($p['slug'] ?? '');
            if ($ref !== '') {
                $lines[] = $ref . (empty($p['activate']) ? ':inactive' : '');
            }
        }
        return implode("\n", $lines);
    }
}
