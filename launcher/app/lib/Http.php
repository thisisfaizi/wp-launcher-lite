<?php

namespace Wpl;

/** Request and response helpers. */
class Http
{
    public static function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    public static function post(string $key, $default = '')
    {
        $v = $_POST[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    /** Client IP. Proxy headers are only believed when the operator says so. */
    public static function ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (Config::installed() && Settings::get('trust_proxy')) {
            $fwd = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            $first = trim(explode(',', $fwd)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                $ip = $first;
            }
        }
        return substr($ip, 0, 45);
    }

    public static function redirect(string $to, int $code = 302): void
    {
        header('Location: ' . $to, true, $code);
        exit;
    }

    public static function json($data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function cookie(string $name, string $value, int $ttl): void
    {
        setcookie($name, $value, [
            'expires' => $ttl ? time() + $ttl : 0,
            'path' => (Config::basePath() ?: '') . '/',
            'secure' => Config::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$name] = $value;
    }

    /** Flash message carried to the next page in a short-lived cookie. */
    public static function flash(string $message, string $type = 'success'): void
    {
        self::cookie('wpl_flash', base64_encode(json_encode([$type, $message])), 60);
    }

    public static function takeFlash(): ?array
    {
        if (empty($_COOKIE['wpl_flash'])) {
            return null;
        }
        $data = json_decode((string) base64_decode((string) $_COOKIE['wpl_flash']), true);
        self::cookie('wpl_flash', '', -3600);
        return is_array($data) && count($data) === 2 ? ['type' => (string) $data[0], 'message' => (string) $data[1]] : null;
    }

    public static function securityHeaders(): void
    {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
    }
}
