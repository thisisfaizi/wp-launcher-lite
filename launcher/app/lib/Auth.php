<?php

namespace Wpl;

/**
 * The single operator account.
 *
 * Sessions are a signed cookie, not PHP sessions: shared hosts often keep
 * session files in a /tmp shared with other accounts, and some never garbage
 * collect a custom save path. The signature covers the password hash, so
 * changing the password signs out every browser.
 */
class Auth
{
    const COOKIE = 'wpl_admin';
    const TTL = 43200; // 12 hours

    public static function secret(): string
    {
        return (string) Config::get('secret');
    }

    public static function setCredentials(string $email, string $password): void
    {
        Settings::set('admin_email', strtolower(trim($email)));
        Settings::set('admin_hash', password_hash($password, PASSWORD_DEFAULT));
    }

    public static function attempt(string $email, string $password): bool
    {
        $ip = Http::ip();
        $recentFailures = (int) Db::value("SELECT COUNT(*) FROM wpl_events WHERE type = 'login.failed' AND ip = ? AND created_at > ?", [$ip, time() - 900]);
        if ($recentFailures >= 8) {
            throw new UserError('Too many failed sign-ins. Wait 15 minutes and try again.');
        }
        $ok = hash_equals((string) Settings::get('admin_email'), strtolower(trim($email)))
            && password_verify($password, (string) Settings::get('admin_hash'));
        if (!$ok) {
            Log::event('login.failed', 'Failed sign-in for ' . mb_substr($email, 0, 100));
            return false;
        }
        $sid = random_str(24);
        $exp = time() + self::TTL;
        Http::cookie(self::COOKIE, $sid . '.' . $exp . '.' . self::sign($sid . '.' . $exp), self::TTL);
        Log::event('login', 'Operator signed in');
        return true;
    }

    public static function logout(): void
    {
        Http::cookie(self::COOKIE, '', -3600);
    }

    /** Session id when signed in, else null. */
    public static function session(): ?string
    {
        $parts = explode('.', (string) ($_COOKIE[self::COOKIE] ?? ''));
        if (count($parts) !== 3) {
            return null;
        }
        [$sid, $exp, $sig] = $parts;
        if ((int) $exp < time() || !hash_equals(self::sign($sid . '.' . $exp), $sig)) {
            return null;
        }
        return $sid;
    }

    public static function check(): bool
    {
        return self::session() !== null;
    }

    public static function require(): void
    {
        if (!self::check()) {
            Http::redirect(url('admin/login'));
        }
    }

    /** CSRF token bound to the admin session (or to the visitor cookie). */
    public static function csrf(): string
    {
        return self::sign('csrf.' . (self::session() ?? Visitor::id()));
    }

    public static function verifyCsrf(): void
    {
        if (!hash_equals(self::csrf(), (string) ($_POST['_csrf'] ?? ''))) {
            throw new UserError('Your session expired. Reload the page and try again.');
        }
    }

    private static function sign(string $data): string
    {
        return hash_hmac('sha256', $data . '|' . Settings::get('admin_hash'), self::secret());
    }
}
