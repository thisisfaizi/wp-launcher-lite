<?php
/**
 * Small global helpers used by views and controllers.
 */

use Wpl\Config;

/** HTML-escape for output. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Absolute URL of a panel route, e.g. url('admin/sites'). */
function url(string $path = '', array $query = []): string
{
    $base = Config::baseUrl();
    $path = ltrim($path, '/');
    if (!Config::installed()) {
        // Before install there is no generated .htaccess, so route by query string.
        $query = ['r' => $path] + $query;
        $path = 'index.php';
    }
    $out = $base . '/' . $path;
    if ($query) {
        $out .= '?' . http_build_query($query);
    }
    return $out;
}

/** URL of a static file in /assets, cache-busted by version. */
function asset(string $file): string
{
    return Config::baseUrl() . '/assets/' . $file . '?v=' . WPL_VERSION;
}

/** Random string from an alphabet, using a CSPRNG. */
function random_str(int $length, string $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789'): string
{
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

/** A readable but strong password: no look-alike characters. */
function random_password(int $length = 16): string
{
    return random_str($length, 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789');
}

/**
 * Parse an upstream-style duration ("30m", "1h", "2d", "never") to seconds.
 * Returns 0 for "never". Unknown input falls back to one hour.
 */
function parse_duration(string $value): int
{
    $value = strtolower(trim($value));
    if ($value === 'never' || $value === '0') {
        return 0;
    }
    if (preg_match('/^(\d+)\s*(m|h|d)$/', $value, $m)) {
        $n = (int) $m[1];
        return $n * ['m' => 60, 'h' => 3600, 'd' => 86400][$m[2]];
    }
    return 3600;
}

/** Human form of a duration in seconds: "1 hour", "45 minutes", "2 days". */
function human_duration(int $seconds): string
{
    if ($seconds <= 0) {
        return 'never';
    }
    if ($seconds % 86400 === 0) {
        $n = $seconds / 86400;
        return $n . ' day' . ($n === 1 ? '' : 's');
    }
    if ($seconds % 3600 === 0) {
        $n = $seconds / 3600;
        return $n . ' hour' . ($n === 1 ? '' : 's');
    }
    $n = (int) round($seconds / 60);
    return $n . ' minute' . ($n === 1 ? '' : 's');
}

/** "in 42 minutes" / "3 hours ago" relative to now. */
function relative_time(int $ts): string
{
    if ($ts <= 0) {
        return 'never';
    }
    $diff = $ts - time();
    $abs = abs($diff);
    if ($abs < 60) {
        $txt = $abs . ' sec';
    } elseif ($abs < 3600) {
        $txt = (int) floor($abs / 60) . ' min';
    } elseif ($abs < 86400) {
        $txt = (int) floor($abs / 3600) . ' h ' . (int) floor(($abs % 3600) / 60) . ' min';
    } else {
        $txt = (int) floor($abs / 86400) . ' d';
    }
    return $diff >= 0 ? 'in ' . $txt : $txt . ' ago';
}

/** Format a unix timestamp for tables. */
function fmt_time(int $ts): string
{
    return $ts > 0 ? gmdate('Y-m-d H:i', $ts) . ' UTC' : '—';
}

/**
 * Data files (install config, per-site configs) are JSON behind a PHP exit
 * line: executed over the web they print nothing, and since they are read
 * rather than include()d, opcache can never serve a stale copy — which on
 * hosts with slow or no opcache revalidation kept expired demos alive.
 */
function write_data_file(string $file, array $data): void
{
    $body = "<?php exit; ?>\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    $tmp = $file . '.' . random_str(6) . '.tmp';
    if (file_put_contents($tmp, $body, LOCK_EX) === false || !@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot write ' . $file);
    }
}

function read_data_file(string $file): ?array
{
    $raw = is_file($file) ? @file_get_contents($file) : false;
    if ($raw === false) {
        return null;
    }
    $data = json_decode(substr($raw, (int) strpos($raw, "\n") + 1), true);
    return is_array($data) ? $data : null;
}

// mbstring is recommended, not required: fall back to byte-safe versions.
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $length = null): string
    {
        return (string) (preg_match_all('/./us', $s, $m) ? implode('', array_slice($m[0], $start, $length)) : substr($s, $start, $length));
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $s): string
    {
        return strtoupper($s);
    }
}
