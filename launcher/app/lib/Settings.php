<?php

namespace Wpl;

/**
 * Operator-tunable settings, stored in the wpl_settings table.
 *
 * Every key has a default here, so a missing row is never a missing feature —
 * and the type of the default decides how a submitted form value is coerced.
 */
class Settings
{
    private static ?array $cache = null;

    public static function defaults(): array
    {
        return [
            // Launcher page
            'brand_name' => 'WP Launcher Lite',
            'launcher_title' => 'Try it live',
            'launcher_intro' => 'Launch a private WordPress demo in seconds. No signup, no credit card. It deletes itself when the time is up.',
            'logo_url' => '',
            'accent' => '#2563eb',
            'footer_text' => '',

            // Lead capture: off | optional | required
            'email_capture' => 'off',
            'consent_text' => '',

            // Limits. Shared hosting gets suspended for runaway resource use, so
            // these default low; 0 means unlimited.
            'max_active_total' => 20,
            'max_per_ip_hour' => 3,
            'max_active_per_visitor' => 2,

            // Demo site limits
            'upload_limit_mb' => 2,
            'disk_quota_mb' => 50,
            'allow_demo_email' => false,

            // Integration
            'webhook_url' => '',
            'webhook_secret' => '',

            // Network
            'trust_proxy' => false,

            // Debugging
            'wp_debug' => false,
        ];
    }

    public static function all(): array
    {
        if (self::$cache === null) {
            $rows = [];
            try {
                foreach (Db::all('SELECT name, value FROM wpl_settings') as $r) {
                    $rows[$r['name']] = $r['value'];
                }
            } catch (\Throwable $e) {
                // Not installed yet.
            }
            $out = [];
            foreach (self::defaults() as $key => $default) {
                $out[$key] = array_key_exists($key, $rows) ? self::coerce($rows[$key], $default) : $default;
            }
            // Keys without a default (internal state such as last cron run).
            foreach ($rows as $key => $value) {
                if (!array_key_exists($key, $out)) {
                    $out[$key] = $value;
                }
            }
            self::$cache = $out;
        }
        return self::$cache;
    }

    public static function get(string $key)
    {
        return self::all()[$key] ?? null;
    }

    public static function set(string $key, $value): void
    {
        $default = self::defaults()[$key] ?? '';
        $value = self::coerce($value, $default);
        $stored = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        Db::exec('REPLACE INTO wpl_settings (name, value) VALUES (?, ?)', [$key, $stored]);
        self::$cache = null;
    }

    /** Coerce a raw value to the type of its default. */
    private static function coerce($value, $default)
    {
        if (is_bool($default)) {
            return in_array($value, [true, 1, '1', 'on', 'true', 'yes'], true);
        }
        if (is_int($default)) {
            return max(0, (int) $value);
        }
        return trim((string) $value);
    }
}
