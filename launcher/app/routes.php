<?php
/**
 * Routes and controllers for the launcher, panel and installer.
 */

use Wpl\Auth;
use Wpl\Blueprints;
use Wpl\Config;
use Wpl\Core;
use Wpl\Db;
use Wpl\Fs;
use Wpl\Htaccess;
use Wpl\Http;
use Wpl\Installer;
use Wpl\Log;
use Wpl\Settings;
use Wpl\SiteDb;
use Wpl\Sites;
use Wpl\UserError;
use Wpl\View;
use Wpl\Visitor;

function wpl_route(): string
{
    if (isset($_GET['r']) && is_string($_GET['r'])) {
        return trim($_GET['r'], '/');
    }
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $base = Config::basePath();
    if ($base !== '' && strncmp($path, $base, strlen($base)) === 0) {
        $path = substr($path, strlen($base));
    }
    $path = trim(rawurldecode($path), '/');
    return $path === 'index.php' ? '' : $path;
}

function wpl_handle_request(): void
{
    $route = wpl_route();
    try {
        if (!Config::installed()) {
            wpl_install_routes($route);
            return;
        }
        // Hosts without cron: expire demos after the response is sent.
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                Sites::maybeCleanup();
            } catch (\Throwable $e) {
                error_log('[wp-launcher-lite] cleanup: ' . $e->getMessage());
            }
        });

        if ($route === 'admin' || strncmp($route, 'admin/', 6) === 0) {
            wpl_admin_routes(substr($route, 6) ?: '');
            return;
        }
        wpl_public_routes($route);
    } catch (UserError $e) {
        if (Http::isPost() && wpl_back('') !== '') {
            Http::flash($e->getMessage(), 'error');
            Http::redirect(wpl_back(''));
        }
        wpl_error_page(400, 'Something is not right', $e->getMessage());
    } catch (\Throwable $e) {
        error_log('[wp-launcher-lite] ' . $e);
        if (Config::installed()) {
            Log::event('error', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }
        $detail = Auth::check() || !Config::installed() ? $e->getMessage() : 'The error was logged. Please try again in a moment.';
        wpl_error_page(500, 'Something went wrong', $detail);
    }
}

/** The referring page if it is one of ours (never an open redirect), else $fallback. */
function wpl_back(string $fallback): string
{
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    return $ref !== '' && strpos($ref, Config::baseUrl() . '/') === 0 ? $ref : $fallback;
}

function wpl_error_page(int $status, string $title, string $message): void
{
    View::render('error', ['title' => $title, 'message' => $message], 'layout', $status);
}

function wpl_not_found(): void
{
    wpl_error_page(404, 'Page not found', 'That page does not exist.');
}

// ── Public launcher ──────────────────────────────────────────────────────

function wpl_public_routes(string $route): void
{
    if ($route === '') {
        $owner = Visitor::ownerHash();
        View::render('launcher', [
            'title' => Settings::get('launcher_title'),
            'blueprints' => array_values(array_filter(Blueprints::all(true), fn ($b) => $b['ready'])),
            'mine' => Sites::forOwner($owner),
            'bpNames' => array_column(Blueprints::all(), 'name', 'id'),
            'flash' => Http::takeFlash(),
        ]);
    }

    if ($route === 'launch' && Http::isPost()) {
        Auth::verifyCsrf();
        // Honeypot: real people never fill in a hidden field.
        if (Http::post('website') !== '') {
            Http::redirect(url(''));
        }
        $bp = Blueprints::find((string) Http::post('blueprint'));
        if (!$bp) {
            throw new UserError('That demo does not exist.');
        }
        $email = strtolower((string) Http::post('email'));
        $capture = Settings::get('email_capture');
        if ($capture === 'required' && $email === '') {
            throw new UserError('Please enter your email address to launch the demo.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new UserError('That email address does not look right.');
        }
        if ($capture === 'off') {
            $email = '';
        }
        $site = Sites::launch($bp, Visitor::ownerHash(), $email, Http::ip());
        Http::redirect(url((Http::post('next') === 'go' ? 'go/' : 'ready/') . $site['slug']));
    }

    // One product's page, for a "Try the demo" button on the operator's own
    // site. Launching stays a POST so link previews and crawlers can't create
    // demos; the page submits itself when no email is asked for. A visitor who
    // already has this demo running is taken back into it.
    if (preg_match('#^demo/([a-z0-9-]+)$#', $route, $m)) {
        $bp = Blueprints::find($m[1]);
        if (!$bp || !$bp['ready'] || !$bp['enabled']) {
            wpl_error_page(404, 'Demo not found', 'This demo is not available right now.');
        }
        foreach (Sites::forOwner(Visitor::ownerHash()) as $s) {
            if ($s['blueprint_id'] === $bp['id'] && ((int) $s['expires_at'] === 0 || (int) $s['expires_at'] > time())) {
                Http::redirect(url('go/' . $s['slug']));
            }
        }
        View::render('demo', ['title' => $bp['name'], 'bp' => $bp, 'flash' => Http::takeFlash()]);
    }

    if (preg_match('#^(ready|go)/(' . WPL_SLUG_RE . ')$#', $route, $m)) {
        $site = Sites::find($m[2]);
        if (!$site || $site['kind'] !== 'demo' || !hash_equals((string) $site['owner_hash'], Visitor::ownerHash())) {
            wpl_error_page(404, 'Demo not found', 'This demo is not yours, or it has already been deleted.');
        }
        if ($site['status'] !== 'active') {
            wpl_error_page(410, 'This demo has ended', 'It was deleted when its time ran out. Launch a fresh one from the demo page.');
        }
        if ($m[1] === 'go') {
            Http::redirect(Sites::loginUrl($site['slug']));
        }
        View::render('ready', [
            'title' => 'Your demo is ready',
            'site' => $site,
            'bp' => Blueprints::find($site['blueprint_id']),
            'url' => Sites::url($site['slug']),
        ]);
    }

    if ($route === 'cron') {
        // For hosts whose cron can only fetch a URL: /cron?key=…
        if (!hash_equals((string) Config::get('cron_key'), (string) ($_GET['key'] ?? ''))) {
            Http::json(['error' => 'forbidden'], 403);
        }
        Http::json(Sites::cleanup(microtime(true) + 20));
    }

    wpl_not_found();
}

// ── Installer ────────────────────────────────────────────────────────────

function wpl_install_routes(string $route): void
{
    // The installer has no account to protect it yet; once configure() has
    // run, only the browser holding the install cookie may continue.
    $configured = Config::get('secret') !== null;
    if ($configured) {
        $cookie = (string) ($_COOKIE['wpl_install'] ?? '');
        if (!hash_equals(hash_hmac('sha256', 'install', (string) Config::get('secret')), $cookie)) {
            wpl_error_page(403, 'Installation in progress', 'Someone has started installing WP Launcher Lite here. To start over, delete the wpl-data folder with your file manager.');
        }
    }

    if ($route === 'install/configure' && Http::isPost()) {
        if ($configured) {
            Http::json(['ok' => true]);
        }
        if (!Installer::requirementsMet()) {
            Http::json(['error' => 'This server does not meet the requirements.'], 400);
        }
        try {
            Installer::configure([
                'base_url' => (string) Http::post('base_url'),
                'url_mode' => (string) Http::post('url_mode'),
                'base_domain' => (string) Http::post('base_domain'),
                'engine' => (string) Http::post('engine'),
                'mysql' => (array) ($_POST['mysql'] ?? []),
                'admin_email' => (string) Http::post('admin_email'),
                'admin_password' => (string) ($_POST['admin_password'] ?? ''),
            ]);
        } catch (UserError $e) {
            Http::json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            // Failed after the config was written: remove it, or this browser
            // would be locked out by the guard above with no way to retry.
            @unlink(Config::file());
            Http::json(['error' => 'Setup failed: ' . $e->getMessage()], 500);
        }
        Http::cookie('wpl_install',hash_hmac('sha256', 'install', (string) Config::get('secret')), 86400);
        Http::json(['ok' => true]);
    }

    if ($route === 'install/step' && Http::isPost() && $configured) {
        $state = json_decode((string) ($_POST['state'] ?? '{}'), true) ?: [];
        try {
            Http::json(Core::step($state, Db::engine() === 'sqlite'));
        } catch (\Throwable $e) {
            Http::json(['error' => $e->getMessage()], 500);
        }
    }

    if ($route === 'install/finish' && Http::isPost() && $configured) {
        try {
            Installer::finish();
        } catch (\Throwable $e) {
            Http::json(['error' => $e->getMessage()], 500);
        }
        Http::cookie('wpl_install', '', -3600);
        Auth::attempt((string) Settings::get('admin_email'), (string) ($_POST['admin_password'] ?? '')) ;
        Http::json([
            'ok' => true,
            'next' => url('admin/blueprints/new', ['welcome' => 1]),
            // The browser checks routing itself: many hosts block loopback requests.
            'ping' => Sites::url('rwtest0000') . '/wpl-ping.php',
            'probe' => Config::baseUrl() . '/wpl-data/probe.txt',
        ]);
    }

    View::render('install', [
        'title' => 'Install WP Launcher Lite',
        'checks' => Installer::requirements(),
        'ok' => Installer::requirementsMet(),
        'configured' => $configured,
        'detected' => Config::detectBaseUrl(),
    ], 'layout');
}

// ── Admin panel ──────────────────────────────────────────────────────────

function wpl_admin_routes(string $route): void
{
    header('X-Robots-Tag: noindex, nofollow');

    if ($route === 'login') {
        if (Auth::check()) {
            Http::redirect(url('admin'));
        }
        $error = null;
        if (Http::isPost()) {
            Auth::verifyCsrf();
            if (Auth::attempt((string) Http::post('email'), (string) ($_POST['password'] ?? ''))) {
                Http::redirect(url('admin'));
            }
            $error = 'Wrong email or password.';
        }
        View::render('admin/login', ['title' => 'Sign in', 'error' => $error]);
    }

    Auth::require();
    if (Http::isPost()) {
        Auth::verifyCsrf();
    }
    $flash = Http::takeFlash();
    $view = function (string $tpl, array $vars) use ($flash) {
        View::render('admin/' . $tpl, $vars + ['flash' => $flash, 'admin' => true], 'admin/layout');
    };

    if ($route === 'logout' && Http::isPost()) {
        Auth::logout();
        Http::redirect(url('admin/login'));
    }

    if ($route === '') {
        $view('dashboard', [
            'title' => 'Dashboard',
            'nav' => 'dashboard',
            'sites' => Db::all("SELECT * FROM wpl_sites WHERE kind = 'demo' AND status = 'active' ORDER BY created_at DESC"),
            'blueprints' => array_column(Blueprints::all(), null, 'id'),
            'stats' => [
                'active' => (int) Db::value("SELECT COUNT(*) FROM wpl_sites WHERE kind = 'demo' AND status = 'active'"),
                'today' => (int) Db::value("SELECT COUNT(*) FROM wpl_sites WHERE kind = 'demo' AND created_at > ?", [time() - 86400]),
                'week' => (int) Db::value("SELECT COUNT(*) FROM wpl_sites WHERE kind = 'demo' AND created_at > ?", [time() - 7 * 86400]),
                'leads' => (int) Db::value("SELECT COUNT(DISTINCT email) FROM wpl_sites WHERE email IS NOT NULL AND email <> ''"),
            ],
            'cronLast' => (int) Settings::get('cron_last_run'),
            'limit' => (int) Settings::get('max_active_total'),
        ]);
    }

    // Site actions
    if (preg_match('#^sites/(' . WPL_SLUG_RE . ')/(open|extend|delete)$#', $route, $m) && Http::isPost()) {
        $site = Sites::find($m[1]);
        if (!$site || $site['status'] !== 'active') {
            throw new UserError('That site is not running.');
        }
        if ($m[2] === 'open') {
            Http::redirect(Sites::loginUrl($site['slug']));
        }
        if ($m[2] === 'extend') {
            Sites::extend($site['slug'], parse_duration((string) Http::post('duration', '1h')));
            Http::flash('Expiry updated.');
        }
        if ($m[2] === 'delete') {
            Sites::delete($site['slug']);
            Http::flash('Site deleted.');
        }
        Http::redirect(wpl_back(url('admin')));
    }

    if ($route === 'blueprints') {
        $view('blueprints', ['title' => 'Blueprints', 'nav' => 'blueprints', 'blueprints' => Blueprints::all(), 'counts' => wpl_active_counts()]);
    }

    if ($route === 'blueprints/new') {
        if (Http::isPost()) {
            $config = wpl_blueprint_from_post([]);
            $json = trim((string) Http::post('import_json'));
            if ($json !== '') {
                $imported = json_decode($json, true);
                if (!is_array($imported)) {
                    throw new UserError('The blueprint JSON could not be read: ' . json_last_error_msg());
                }
                unset($imported['id']);
                $config = $imported;
            }
            $bp = Blueprints::create($config);
            Http::redirect(Sites::setupUrl($bp['template_slug']));
        }
        $view('blueprint-new', ['title' => 'New blueprint', 'nav' => 'blueprints', 'welcome' => !empty($_GET['welcome'])]);
    }

    if (preg_match('#^blueprints/([a-z0-9-]+)(?:/(build|open|delete|export))?$#', $route, $m)) {
        $bp = Blueprints::find($m[1]);
        if (!$bp) {
            wpl_not_found();
        }
        $action = $m[2] ?? '';
        if ($action === 'export') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $bp['id'] . '.json"');
            echo json_encode(['id' => $bp['id']] + $bp['config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }
        if ($action === 'build' && Http::isPost()) {
            Http::redirect(Sites::setupUrl($bp['template_slug']));
        }
        if ($action === 'open' && Http::isPost()) {
            Http::redirect(Sites::loginUrl($bp['template_slug']));
        }
        if ($action === 'delete' && Http::isPost()) {
            Blueprints::delete($bp['id']);
            Http::flash('Blueprint "' . $bp['name'] . '" deleted.');
            Http::redirect(url('admin/blueprints'));
        }
        if ($action === '' && Http::isPost()) {
            $config = wpl_blueprint_from_post($bp['config']);
            if ($bp['ready']) {
                // The template's WordPress user already exists under this name.
                $config['demo']['admin_user'] = $bp['config']['demo']['admin_user'];
            }
            Blueprints::update($bp['id'], $config, Http::post('enabled') === '1');
            Http::flash('Blueprint saved.');
            Http::redirect(url('admin/blueprints/' . $bp['id']));
        }
        $tpl = $bp['template_slug'];
        $view('blueprint', [
            'title' => $bp['name'],
            'nav' => 'blueprints',
            'bp' => $bp,
            'templateUrl' => Sites::url($tpl),
            'usage' => Fs::usage(Sites::uploadsDir($tpl)),
            'dbSize' => $bp['ready'] ? SiteDb::size($tpl) : 0,
            'active' => wpl_active_counts()[$bp['id']] ?? 0,
            'building' => is_file(Sites::dataDir($tpl) . '/setup.json'),
        ]);
    }

    if ($route === 'leads' || $route === 'leads.csv') {
        $rows = Db::all("SELECT s.email, s.blueprint_id, s.created_at, s.ip, s.slug, s.status FROM wpl_sites s WHERE s.kind = 'demo' AND s.email IS NOT NULL AND s.email <> '' ORDER BY s.created_at DESC LIMIT 5000");
        if ($route === 'leads.csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="leads-' . gmdate('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'blueprint', 'launched_at_utc', 'ip', 'site', 'status'], ',', '"', '');
            foreach ($rows as $r) {
                // Leading = + - @ would run as a formula in a spreadsheet.
                $email = preg_match('/^[=+\-@]/', $r['email']) ? "'" . $r['email'] : $r['email'];
                fputcsv($out, [$email, $r['blueprint_id'], gmdate('Y-m-d H:i:s', (int) $r['created_at']), $r['ip'], $r['slug'], $r['status']], ',', '"', '');
            }
            exit;
        }
        $view('leads', ['title' => 'Leads', 'nav' => 'leads', 'rows' => $rows, 'capture' => Settings::get('email_capture')]);
    }

    if ($route === 'sites') {
        $view('sites', [
            'title' => 'Site history',
            'nav' => 'sites',
            'rows' => Db::all("SELECT * FROM wpl_sites WHERE kind = 'demo' ORDER BY created_at DESC LIMIT 500"),
        ]);
    }

    if ($route === 'log') {
        $view('log', ['title' => 'Activity log', 'nav' => 'log', 'events' => Log::recent(300)]);
    }

    if ($route === 'settings') {
        if (Http::isPost()) {
            $section = (string) Http::post('section');
            if ($section === 'password') {
                if (!password_verify((string) ($_POST['current'] ?? ''), (string) Settings::get('admin_hash'))) {
                    throw new UserError('Your current password is wrong.');
                }
                $new = (string) ($_POST['new'] ?? '');
                if (strlen($new) < 10) {
                    throw new UserError('Use a new password of at least 10 characters.');
                }
                $email = (string) Http::post('email') ?: (string) Settings::get('admin_email');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new UserError('Enter a valid email address.');
                }
                Auth::setCredentials($email, $new);
                Auth::attempt($email, $new);
                Http::flash('Login details changed.');
            } else {
                // Checkboxes post a hidden "0" before the box, so every
                // submitted key is present whether ticked or not.
                foreach (array_keys(Settings::defaults()) as $key) {
                    if ($key !== 'webhook_secret' && array_key_exists($key, $_POST)) {
                        Settings::set($key, $_POST[$key]);
                    }
                }
                if (!in_array(Settings::get('email_capture'), ['off', 'optional', 'required'], true)) {
                    Settings::set('email_capture', 'off');
                }
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) Settings::get('accent'))) {
                    Settings::set('accent', '#2563eb');
                }
                Http::flash('Settings saved. Demo limits apply to demos launched from now on.');
            }
            Http::redirect(url('admin/settings'));
        }
        $view('settings', [
            'title' => 'Settings',
            'nav' => 'settings',
            's' => Settings::all(),
            'cronUrl' => url('cron', ['key' => Config::get('cron_key')]),
            'cronCli' => 'php ' . WPL_ROOT . '/cron.php',
            'cronLast' => (int) Settings::get('cron_last_run'),
        ]);
    }

    if ($route === 'system') {
        if (Http::isPost() && Http::post('do') === 'htaccess') {
            Htaccess::write();
            Http::flash('Routing rules rewritten.');
            Http::redirect(url('admin/system'));
        }
        $view('system', [
            'title' => 'System',
            'nav' => 'system',
            'core' => Core::version(),
            'latest' => Core::latestVersion(),
            'engine' => Db::engine(),
            'mode' => Config::urlMode(),
            'checks' => Installer::requirements(),
            'pingUrl' => Sites::url('rwtest0000') . '/wpl-ping.php',
            'probeUrl' => Config::baseUrl() . '/wpl-data/probe.txt',
            'disk' => [
                'core' => Fs::usage(WPL_CORE),
                'content' => Fs::usage(WPL_CONTENT),
                'uploads' => Fs::usage(WPL_UPLOADS),
                'data' => Fs::usage(WPL_DATA),
            ],
        ]);
    }

    if ($route === 'system/core-step' && Http::isPost()) {
        $state = json_decode((string) ($_POST['state'] ?? '{}'), true) ?: [];
        try {
            $r = Core::step($state, false);
            if ($r['done']) {
                Log::event('core.updated', 'WordPress core is now ' . Core::version());
            }
            Http::json($r);
        } catch (\Throwable $e) {
            Http::json(['error' => $e->getMessage()], 500);
        }
    }

    wpl_not_found();
}

/** Active demo counts per blueprint. */
function wpl_active_counts(): array
{
    $out = [];
    foreach (Db::all("SELECT blueprint_id, COUNT(*) AS n FROM wpl_sites WHERE kind = 'demo' AND status = 'active' GROUP BY blueprint_id") as $r) {
        $out[$r['blueprint_id']] = (int) $r['n'];
    }
    return $out;
}

/** Build a blueprint config from the edit form, on top of the existing one. */
function wpl_blueprint_from_post(array $config): array
{
    $config = Blueprints::normalize($config);
    $config['name'] = trim((string) Http::post('name')) ?: $config['name'];
    $config['description'] = (string) Http::post('description');
    $config['image_url'] = (string) Http::post('image_url');
    $config['demo']['default_expiration'] = preg_match('/^(\d+[mhd]|never)$/', (string) Http::post('expiration')) ? Http::post('expiration') : '1h';
    $config['demo']['max_concurrent_sites'] = max(0, (int) Http::post('max_concurrent', 10));
    $config['demo']['admin_user'] = Blueprints::cleanUser((string) Http::post('admin_user', 'demo'));
    $config['demo']['landing_page'] = (string) Http::post('landing_page');
    $toggles = array_values(array_intersect(array_keys(Blueprints::CAPABILITY_GROUPS), (array) ($_POST['blocked'] ?? [])));
    $config['restrictions']['blocked_capabilities'] = $toggles;
    $menus = array_filter(array_map('trim', preg_split('/[\s,]+/', (string) Http::post('hidden_menus'))));
    $config['restrictions']['hidden_menu_items'] = array_values(array_filter($menus, fn ($m) => preg_match('/^[a-z0-9_.?=&-]+$/i', $m)));
    $config['restrictions']['disable_file_mods'] = true;
    if (array_key_exists('plugins', $_POST)) {
        $config['plugins']['preinstall'] = Blueprints::parsePluginLines((string) Http::post('plugins'));
    }
    if (array_key_exists('theme', $_POST)) {
        $theme = strtolower(trim((string) Http::post('theme')));
        $config['themes']['install'] = $theme === '' ? [] : [preg_match('#^https?://#', $theme)
            ? ['source' => 'url', 'url' => $theme, 'activate' => true]
            : ['source' => 'wordpress.org', 'slug' => preg_replace('/[^a-z0-9-]/', '', $theme), 'activate' => true]];
    }
    return $config;
}
