<?php

namespace Wpl;

use PDO;

/**
 * WordPress databases of individual sites.
 *
 * SQLite: one file per site in wpl-data/sites/<slug>/.ht.sqlite, prefix wp_.
 * MySQL:  tables prefixed wpl_<slug>_ in the install's one database.
 *
 * A demo is made by copying its blueprint's template database and swapping the
 * template's slug for the demo's. Both slugs are the same length and the slug
 * is the only part of the URL (and, for MySQL, the table prefix) that differs,
 * so a plain string REPLACE keeps PHP-serialized values valid.
 */
class SiteDb
{
    public static function prefix(string $slug): string
    {
        return Db::engine() === 'mysql' ? 'wpl_' . $slug . '_' : 'wp_';
    }

    public static function sqliteFile(string $slug): string
    {
        return WPL_DATA . '/sites/' . $slug . '/.ht.sqlite';
    }

    /** Copy the template's database into a new site and rewrite its token. */
    public static function cloneSite(string $fromSlug, string $toSlug): void
    {
        if (Db::engine() === 'mysql') {
            self::cloneMysql($fromSlug, $toSlug);
        } else {
            self::cloneSqlite($fromSlug, $toSlug);
        }
    }

    public static function drop(string $slug): void
    {
        if (Db::engine() === 'mysql') {
            $tables = self::mysqlTables($slug);
            if ($tables) {
                $pdo = Db::pdo();
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                $pdo->exec('DROP TABLE IF EXISTS ' . implode(', ', array_map([self::class, 'q'], $tables)));
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
        // SQLite files go with the site's data directory.
    }

    /**
     * Set the password of a site's admin user and end every session. Returns
     * the login actually reset: the operator may have renamed or replaced the
     * template's admin, and showing a visitor credentials for a user that does
     * not exist would fail silently (autologin still works, the password not).
     */
    public static function resetAdmin(string $slug, string $user, string $password): string
    {
        [$pdo, $prefix] = self::open($slug);
        $users = self::q($prefix . 'users');
        $meta = self::q($prefix . 'usermeta');

        $st = $pdo->prepare("SELECT ID, user_login FROM $users WHERE user_login = ?");
        $st->execute([$user]);
        $row = $st->fetch();
        if (!$row) {
            // The lowest-numbered administrator. In SQLite installs the prefix
            // is wp_, in MySQL the clone's own — either way $prefix.
            $st = $pdo->prepare("SELECT u.ID, u.user_login FROM $users u JOIN $meta m ON m.user_id = u.ID WHERE m.meta_key = ? AND m.meta_value LIKE ? ORDER BY u.ID LIMIT 1");
            $st->execute([$prefix . 'capabilities', '%"administrator"%']);
            $row = $st->fetch();
        }
        if (!$row) {
            throw new \RuntimeException('The template has no administrator account. Open the template and create one.');
        }
        // Plain bcrypt: WordPress 6.8+ verifies it with password_verify() and
        // older cores through phpass, so it works whatever core is installed.
        $st = $pdo->prepare("UPDATE $users SET user_pass = ? WHERE ID = ?");
        $st->execute([password_hash($password, PASSWORD_BCRYPT), $row['ID']]);
        $pdo->exec("DELETE FROM $meta WHERE meta_key = 'session_tokens'");
        return (string) $row['user_login'];
    }

    /**
     * Remove plugin and theme zips that WordPress is holding for an install
     * from a site's database, and return their paths relative to uploads.
     * Uploading a plugin over an installed one stops at "Replace current with
     * uploaded?" with the zip parked in the media folder; a demo cloned then
     * would serve the operator's (possibly paid) plugin to anyone.
     *
     * @return string[]
     */
    public static function dropUpgraderPackages(string $slug): array
    {
        [$pdo, $prefix] = self::open($slug);
        $posts = self::q($prefix . 'posts');
        $meta = self::q($prefix . 'postmeta');
        $st = $pdo->prepare("SELECT post_id FROM $meta WHERE meta_key = '_wp_attachment_context' AND meta_value = 'upgrader'");
        $st->execute();
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) {
            return [];
        }
        $in = implode(',', $ids);
        $files = $pdo->query("SELECT meta_value FROM $meta WHERE meta_key = '_wp_attached_file' AND post_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec("DELETE FROM $meta WHERE post_id IN ($in)");
        $pdo->exec("DELETE FROM $posts WHERE ID IN ($in)");
        return array_values(array_filter(array_map('strval', $files)));
    }

    /** Size of a site's database in bytes (best effort). */
    public static function size(string $slug): int
    {
        if (Db::engine() === 'mysql') {
            $like = self::likeEscape(self::prefix($slug)) . '%';
            return (int) Db::value('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?', [$like]);
        }
        $f = self::sqliteFile($slug);
        return is_file($f) ? (int) filesize($f) : 0;
    }

    // ── MySQL ────────────────────────────────────────────────────────────

    private static function cloneMysql(string $from, string $to): void
    {
        $pdo = Db::pdo();
        $old = self::prefix($from);
        $new = self::prefix($to);
        $tables = self::mysqlTables($from);
        if (!$tables) {
            throw new \RuntimeException('The template database is empty. Open the blueprint and finish building it first.');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $created = [];
        try {
            foreach ($tables as $t) {
                $n = $new . substr($t, strlen($old));
                $pdo->exec('CREATE TABLE ' . self::q($n) . ' LIKE ' . self::q($t));
                $created[] = $n;
                $pdo->exec('INSERT INTO ' . self::q($n) . ' SELECT * FROM ' . self::q($t));
            }
            foreach ($created as $n) {
                $cols = [];
                foreach ($pdo->query('SHOW COLUMNS FROM ' . self::q($n))->fetchAll() as $c) {
                    if (preg_match('/char|text/i', $c['Type'])) {
                        $cols[] = $c['Field'];
                    }
                }
                self::replaceIn($pdo, $n, $cols, $from, $to);
            }
            self::clearTransients($pdo, $new);
        } catch (\Throwable $e) {
            if ($created) {
                $pdo->exec('DROP TABLE IF EXISTS ' . implode(', ', array_map([self::class, 'q'], $created)));
            }
            throw $e;
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private static function mysqlTables(string $slug): array
    {
        $like = self::likeEscape(self::prefix($slug)) . '%';
        // Not SHOW TABLES LIKE ?: that cannot be a server-side prepared statement.
        $st = Db::pdo()->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ? ORDER BY table_name');
        $st->execute([$like]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    // ── SQLite ───────────────────────────────────────────────────────────

    private static function cloneSqlite(string $from, string $to): void
    {
        $src = self::sqliteFile($from);
        $dst = self::sqliteFile($to);
        if (!is_file($src)) {
            throw new \RuntimeException('The template database is missing. Open the blueprint and finish building it first.');
        }
        Fs::ensureDir(dirname($dst));
        // VACUUM INTO takes a consistent snapshot even if the template is in
        // use (WAL or not); a plain copy() could catch it mid-write.
        try {
            $srcPdo = Db::connectSqlite($src);
            $st = $srcPdo->prepare('VACUUM INTO ?');
            $st->execute([$dst]);
            $srcPdo = null;
        } catch (\Throwable $e) {
            // SQLite older than 3.27 has no VACUUM INTO.
            if (is_file($dst)) {
                @unlink($dst);
            }
            if (!copy($src, $dst)) {
                throw new \RuntimeException('Cannot copy the template database.');
            }
        }
        $pdo = Db::connectSqlite($dst);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite!_%' ESCAPE '!' AND name NOT LIKE '!_wp!_sqlite!_%' ESCAPE '!'")->fetchAll(PDO::FETCH_COLUMN);
        $pdo->beginTransaction();
        foreach ($tables as $t) {
            $cols = [];
            foreach ($pdo->query('PRAGMA table_info(' . self::q($t, '"') . ')')->fetchAll() as $c) {
                $type = strtolower((string) $c['type']);
                if ($type === '' || str_contains($type, 'text') || str_contains($type, 'char') || str_contains($type, 'clob')) {
                    $cols[] = $c['name'];
                }
            }
            self::replaceIn($pdo, $t, $cols, $from, $to, '"');
        }
        self::clearTransients($pdo, 'wp_', '"');
        $pdo->commit();
    }

    // ── Shared ───────────────────────────────────────────────────────────

    /** @return array{0: PDO, 1: string} */
    private static function open(string $slug): array
    {
        if (Db::engine() === 'mysql') {
            return [Db::pdo(), self::prefix($slug)];
        }
        return [Db::connectSqlite(self::sqliteFile($slug)), 'wp_'];
    }

    private static function replaceIn(PDO $pdo, string $table, array $cols, string $from, string $to, string $quote = '`'): void
    {
        foreach ($cols as $col) {
            $c = self::q($col, $quote);
            $st = $pdo->prepare('UPDATE ' . self::q($table, $quote) . " SET $c = REPLACE($c, ?, ?) WHERE $c LIKE ?");
            $st->execute([$from, $to, '%' . $from . '%']);
        }
    }

    /** Transients describe the template, not the clone; WordPress rebuilds them. */
    private static function clearTransients(PDO $pdo, string $prefix, string $quote = '`'): void
    {
        $options = self::q($prefix . 'options', $quote);
        $pdo->exec("DELETE FROM $options WHERE option_name LIKE '!_transient!_%' ESCAPE '!' OR option_name LIKE '!_site!_transient!_%' ESCAPE '!'");
    }

    private static function q(string $name, string $quote = '`'): string
    {
        return $quote . str_replace($quote, $quote . $quote, $name) . $quote;
    }

    private static function likeEscape(string $s): string
    {
        return addcslashes($s, '\\_%');
    }
}
