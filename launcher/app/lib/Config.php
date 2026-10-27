<?php

namespace Wpl;

/**
 * Install-level configuration, stored in wpl-data/config.php.
 *
 * This file is also read by the WordPress dispatcher on every demo request, so
 * it holds only what both sides need: URLs, the database connection and the
 * install secret. Everything an operator tunes day to day lives in the
 * settings table instead (see Settings).
 */
class Config
{
    private static ?array $data = null;

    public static function file(): string
    {
        return WPL_DATA . '/config.php';
    }

    public static function installed(): bool
    {
        return self::all() !== [] && !empty(self::$data['installed']);
    }

    public static function all(): array
    {
        if (self::$data === null) {
            self::$data = read_data_file(self::file()) ?? [];
        }
        return self::$data;
    }

    public static function get(string $key, $default = null)
    {
        return self::all()[$key] ?? $default;
    }

    public static function save(array $data): void
    {
        if (!is_dir(WPL_DATA)) {
            mkdir(WPL_DATA, 0755, true);
        }
        write_data_file(self::file(), $data);
        self::$data = $data;
    }

    public static function update(array $changes): void
    {
        self::save(array_merge(self::all(), $changes));
    }

    /** Base URL of the launcher, without trailing slash. */
    public static function baseUrl(): string
    {
        $configured = self::get('base_url');
        return $configured ? rtrim($configured, '/') : self::detectBaseUrl();
    }

    /** URL path of the launcher ("/demos" or ""), without trailing slash. */
    public static function basePath(): string
    {
        $path = parse_url(self::baseUrl(), PHP_URL_PATH) ?: '';
        return rtrim($path, '/');
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        // Cloudflare / load balancers terminate TLS in front of many shared hosts.
        if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        return ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    /** Best guess at the base URL from the current request (used by the installer). */
    public static function detectBaseUrl(): string
    {
        $scheme = self::isHttps() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $dir = rtrim(dirname($script), '/');
        if ($dir === '.' || $dir === '\\') {
            $dir = '';
        }
        return $scheme . '://' . $host . $dir;
    }

    /** "path" (example.com/demos/s/abc…) or "subdomain" (abc….demo.example.com). */
    public static function urlMode(): string
    {
        return self::get('url_mode') === 'subdomain' ? 'subdomain' : 'path';
    }

    /** Public URL of a site slug. */
    public static function siteUrl(string $slug): string
    {
        if (self::urlMode() === 'subdomain') {
            $scheme = parse_url(self::baseUrl(), PHP_URL_SCHEME) ?: 'https';
            $port = parse_url(self::baseUrl(), PHP_URL_PORT);
            return $scheme . '://' . $slug . '.' . self::get('base_domain') . ($port ? ':' . $port : '');
        }
        return self::baseUrl() . '/s/' . $slug;
    }
}
