<?php

namespace Wpl;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Filesystem work that has to survive shared hosting: no shell, 30-second
 * request limits and inode quotas. Anything that can touch thousands of files
 * takes a deadline and reports whether it finished, so callers can resume.
 *
 * Self-contained (no helpers.php) because the template builder also loads it
 * inside WordPress.
 */
class Fs
{
    /** Text files whose contents may embed a site URL (e.g. page-builder CSS). */
    const TEXT_EXTENSIONS = ['css', 'js', 'json', 'html', 'htm', 'txt', 'xml', 'svg', 'map'];

    public static function ensureDir(string $dir, int $mode = 0755): void
    {
        if (!is_dir($dir) && !@mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create directory ' . $dir);
        }
    }

    /** Deny all web access to a directory (Apache 2.2 and 2.4 / LiteSpeed). */
    public static function denyWebAccess(string $dir): void
    {
        self::ensureDir($dir);
        file_put_contents($dir . '/.htaccess', "# WP Launcher Lite: private\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
        if (!is_file($dir . '/index.html')) {
            file_put_contents($dir . '/index.html', '');
        }
    }

    /**
     * Copy a directory tree. When $replace is given, text files are copied with
     * the string replaced — the token swap that turns a template's URLs into a
     * clone's. Returns the number of files copied.
     */
    public static function copyTree(string $src, string $dst, array $replace = [], int $maxTextBytes = 5242880): int
    {
        if (!is_dir($src)) {
            return 0;
        }
        self::ensureDir($dst);
        $count = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $target = $dst . '/' . $it->getSubPathName();
            if ($item->isLink()) {
                continue; // never follow links out of the tree
            }
            if ($item->isDir()) {
                self::ensureDir($target);
                continue;
            }
            $ext = strtolower($item->getExtension());
            if ($replace && in_array($ext, self::TEXT_EXTENSIONS, true) && $item->getSize() <= $maxTextBytes) {
                $body = file_get_contents($item->getPathname());
                file_put_contents($target, strtr($body, $replace));
            } else {
                copy($item->getPathname(), $target);
            }
            $count++;
        }
        return $count;
    }

    /** Count files and bytes under a directory. */
    public static function usage(string $dir): array
    {
        $files = 0;
        $bytes = 0;
        if (is_dir($dir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $files++;
                $bytes += $f->getSize();
            }
        }
        return ['files' => $files, 'bytes' => $bytes];
    }

    /**
     * Move a directory into the trash. Renaming is instant whatever the size,
     * so deleting a site never waits on its files; emptyTrash() removes them
     * a slice at a time from cron.
     */
    public static function trash(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        self::ensureDir(WPL_DATA . '/trash');
        $dest = WPL_DATA . '/trash/' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
        if (!@rename($path, $dest)) {
            // Cross-device or locked: fall back to deleting in place.
            self::removeTree($path, microtime(true) + 10);
        }
    }

    /** Empty the trash until the deadline. Returns true when it is empty. */
    public static function emptyTrash(float $deadline): bool
    {
        $trash = WPL_DATA . '/trash';
        if (!is_dir($trash)) {
            return true;
        }
        foreach (scandir($trash) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!self::removeTree($trash . '/' . $entry, $deadline)) {
                return false;
            }
        }
        return true;
    }

    /** Delete a tree until the deadline. Returns true when fully removed. */
    public static function removeTree(string $path, float $deadline): bool
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }
        if (!is_dir($path)) {
            return true;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if (microtime(true) > $deadline) {
                return false;
            }
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        return @rmdir($path);
    }

    /**
     * Download a URL to a file in one go (small files only). Throws on failure.
     */
    public static function download(string $url, string $dest, int $timeout = 60): void
    {
        $deadline = microtime(true) + $timeout;
        do {
            $r = self::downloadStep($url, $dest, $deadline);
        } while (!$r['done'] && microtime(true) < $deadline);
        if (!$r['done']) {
            throw new \RuntimeException('Download timed out: ' . $url);
        }
    }

    /**
     * Download a slice of a URL until the deadline, resuming from whatever is
     * already in "$dest.part" with an HTTP Range request. Call again until
     * 'done'. This is what lets a 37 MB WordPress zip arrive on a host that
     * kills every request after 30 seconds.
     *
     * @return array{done: bool, bytes: int, total: int}
     */
    public static function downloadStep(string $url, string $dest, float $deadline): array
    {
        self::ensureDir(dirname($dest));
        $part = $dest . '.part';
        $have = is_file($part) ? (int) filesize($part) : 0;

        if (!function_exists('curl_init')) {
            if (!ini_get('allow_url_fopen')) {
                throw new \RuntimeException('This server cannot make outgoing HTTP requests (no cURL, and allow_url_fopen is off). Upload the zip by hand instead.');
            }
            $ctx = stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'WP-Launcher-Lite']]);
            $in = @fopen($url, 'rb', false, $ctx);
            if (!$in) {
                throw new \RuntimeException('Download failed: ' . $url);
            }
            $out = fopen($part, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            rename($part, $dest);
            return ['done' => true, 'bytes' => (int) filesize($dest), 'total' => (int) filesize($dest)];
        }

        $fh = fopen($part, 'ab');
        $total = 0;
        $restarted = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => max(3, (int) floor($deadline - microtime(true))),
            CURLOPT_USERAGENT => 'WP-Launcher-Lite/' . (defined('WPL_VERSION') ? WPL_VERSION : 'wp'),
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$total, $have) {
                if (preg_match('#^Content-Range:\s*bytes\s+\d+-\d+/(\d+)#i', $line, $m)) {
                    $total = (int) $m[1];
                } elseif (preg_match('#^Content-Length:\s*(\d+)#i', $line, $m) && $total === 0) {
                    $total = (int) $m[1] + $have;
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($fh, &$have, &$restarted, &$total) {
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                if ($code >= 400) {
                    return 0; // abort; reported below
                }
                // Asked for a range, got the whole file: start the file over.
                if ($code === 200 && $have > 0 && !$restarted) {
                    ftruncate($fh, 0);
                    fseek($fh, 0);
                    $total -= $have;
                    $have = 0;
                }
                $restarted = true;
                return fwrite($fh, $data);
            },
        ]);
        if ($have > 0) {
            curl_setopt($ch, CURLOPT_RANGE, $have . '-');
        }
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        fclose($fh);
        clearstatcache(true, $part);
        $bytes = (int) filesize($part);

        if ($code === 416 && $have > 0) {
            // Range beyond the end: we already have it all.
            $ok = true;
        } elseif ($code >= 400) {
            @unlink($part);
            throw new \RuntimeException('Download failed (HTTP ' . $code . '): ' . $url);
        }
        if (!$ok) {
            if ($errno === 28) { // CURLE_OPERATION_TIMEDOUT: keep the part, resume next call
                return ['done' => false, 'bytes' => $bytes, 'total' => $total];
            }
            throw new \RuntimeException('Download failed (' . $err . '): ' . $url);
        }
        if ($total > 0 && $bytes < $total) {
            return ['done' => false, 'bytes' => $bytes, 'total' => $total];
        }
        if (!@rename($part, $dest)) {
            throw new \RuntimeException('Cannot write ' . $dest);
        }
        return ['done' => true, 'bytes' => $bytes, 'total' => max($total, $bytes)];
    }

    /**
     * Extract part of a zip, resuming at entry $start, until the deadline.
     * $map turns an entry name into a destination path relative to $dest, or
     * null to skip it. Returns the next index to process (count = finished).
     */
    public static function extractZip(string $zipFile, string $dest, int $start, float $deadline, callable $map): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException('Cannot open zip ' . basename($zipFile) . ' — it may be incomplete. Delete it and retry.');
        }
        $total = $zip->numFiles;
        $i = $start;
        for (; $i < $total; $i++) {
            if (microtime(true) > $deadline) {
                break;
            }
            $name = $zip->getNameIndex($i);
            $rel = $map($name);
            if ($rel === null || $rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $target = $dest . '/' . $rel;
            if (str_ends_with($name, '/')) {
                self::ensureDir($target);
                continue;
            }
            self::ensureDir(dirname($target));
            $in = $zip->getStream($name);
            if ($in === false) {
                throw new \RuntimeException('Cannot read ' . $name . ' from zip');
            }
            $out = fopen($target, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
        }
        $zip->close();
        return ['next' => $i, 'total' => $total];
    }
}
