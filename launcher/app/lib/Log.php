<?php

namespace Wpl;

/** The panel's event log (launches, expiries, logins, errors). */
class Log
{
    public static function event(string $type, string $message, ?string $slug = null, ?string $ip = null): void
    {
        try {
            Db::insert('wpl_events', [
                'created_at' => time(),
                'type' => $type,
                'slug' => $slug,
                'ip' => $ip ?? Http::ip(),
                'message' => mb_substr($message, 0, 2000),
            ]);
        } catch (\Throwable $e) {
            error_log('[wp-launcher-lite] ' . $type . ': ' . $message);
        }
    }

    public static function recent(int $limit = 200, ?string $type = null): array
    {
        if ($type) {
            return Db::all('SELECT * FROM wpl_events WHERE type = ? ORDER BY id DESC LIMIT ' . (int) $limit, [$type]);
        }
        return Db::all('SELECT * FROM wpl_events ORDER BY id DESC LIMIT ' . (int) $limit);
    }
}
