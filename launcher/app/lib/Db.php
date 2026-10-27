<?php

namespace Wpl;

use PDO;

/**
 * The panel's own database: settings, blueprints, sites and the event log.
 *
 * Uses the same engine the install chose for WordPress — a SQLite file in
 * wpl-data, or `wpl_*` tables beside the demo tables in the one MySQL database
 * a shared host gives you. SQL here is kept to the subset both engines accept.
 */
class Db
{
    private static ?PDO $pdo = null;

    public static function engine(): string
    {
        return Config::get('engine') === 'mysql' ? 'mysql' : 'sqlite';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::engine() === 'mysql'
                ? self::connectMysql(Config::get('mysql', []))
                : self::connectSqlite(WPL_DATA . '/.ht.panel.sqlite');
        }
        return self::$pdo;
    }

    public static function connectMysql(array $c): PDO
    {
        $dsn = 'mysql:host=' . ($c['host'] ?? 'localhost')
            . (!empty($c['port']) ? ';port=' . (int) $c['port'] : '')
            . ';dbname=' . ($c['name'] ?? '') . ';charset=utf8mb4';
        return new PDO($dsn, $c['user'] ?? '', $c['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public static function connectSqlite(string $file): PDO
    {
        $pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // A launch and a cleanup tick can overlap; wait rather than fail.
        $pdo->exec('PRAGMA busy_timeout = 10000');
        return $pdo;
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function insert(string $table, array $row): void
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        self::exec($sql, array_values($row));
    }

    public static function update(string $table, array $row, string $where, array $params = []): int
    {
        $set = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($row)));
        return self::exec("UPDATE $table SET $set WHERE $where", array_merge(array_values($row), $params));
    }

    /** Create the panel tables. Safe to run repeatedly. */
    public static function migrate(): void
    {
        $mysql = self::engine() === 'mysql';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $autoId = $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';

        $tables = [
            "CREATE TABLE IF NOT EXISTS wpl_settings (
                name VARCHAR(64) NOT NULL PRIMARY KEY,
                value TEXT
            )",
            "CREATE TABLE IF NOT EXISTS wpl_blueprints (
                id VARCHAR(40) NOT NULL PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                template_slug VARCHAR(16) NOT NULL,
                status VARCHAR(16) NOT NULL,
                enabled INT NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                config TEXT,
                created_at INT NOT NULL,
                updated_at INT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS wpl_sites (
                slug VARCHAR(16) NOT NULL PRIMARY KEY,
                blueprint_id VARCHAR(40) NOT NULL,
                kind VARCHAR(10) NOT NULL,
                status VARCHAR(12) NOT NULL,
                owner_hash VARCHAR(64),
                email VARCHAR(190),
                ip VARCHAR(45),
                admin_user VARCHAR(60),
                admin_password VARCHAR(100),
                created_at INT NOT NULL,
                expires_at INT NOT NULL DEFAULT 0,
                deleted_at INT NOT NULL DEFAULT 0
            )",
            "CREATE TABLE IF NOT EXISTS wpl_events (
                id $autoId,
                created_at INT NOT NULL,
                type VARCHAR(32) NOT NULL,
                slug VARCHAR(16),
                ip VARCHAR(45),
                message TEXT
            )",
        ];
        foreach ($tables as $sql) {
            self::pdo()->exec($sql . $suffix);
        }

        $indexes = [
            'wpl_sites_status' => 'wpl_sites (status, expires_at)',
            'wpl_sites_owner' => 'wpl_sites (owner_hash)',
            'wpl_sites_ip' => 'wpl_sites (ip, created_at)',
            'wpl_events_created' => 'wpl_events (created_at)',
            'wpl_events_type' => 'wpl_events (type, ip, created_at)',
        ];
        foreach ($indexes as $name => $def) {
            if ($mysql) {
                $exists = self::value('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = ?', [$name]);
                if (!$exists) {
                    self::pdo()->exec("CREATE INDEX $name ON $def");
                }
            } else {
                self::pdo()->exec("CREATE INDEX IF NOT EXISTS $name ON $def");
            }
        }
    }
}
