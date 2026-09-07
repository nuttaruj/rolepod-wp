<?php
declare(strict_types=1);

namespace Rolepod\Wp\Fs;

/**
 * Retention for the `.wplab-bak-*` copies written next to a file before an
 * AI-driven overwrite, and for the theme snapshots written on a theme edit.
 *
 * Without this every write left one more copy in the WP install, forever —
 * a site edited over months carried hundreds of them. Undoing an AI change
 * never reads these files (the Change Ledger keeps its own content snapshot),
 * so pruning costs only the on-disk copy a human would restore by hand.
 *
 * Deliberately WP-free so it can be unit tested standalone; the filter is
 * applied only when WordPress is actually loaded.
 */
final class BackupRetention
{
    /** How many backups of one file survive a write. */
    public const DEFAULT_KEEP = 2;

    public const SUFFIX = '.wplab-bak-';

    /**
     * What may sit between prefix and suffix. Timestamps (20260907-123456),
     * ISO stamps and uuid4 batch ids all match; a nested backup-of-a-backup
     * ("foo.php.wplab-bak-X.wplab-bak-Y") does not, so pruning one file's
     * copies never reaches into another file's.
     */
    public const STAMP_PATTERN = '/^[A-Za-z0-9_-]+$/';

    /**
     * Site-wide override: add_filter('rolepod_wp_backup_keep', fn() => 5);
     * Never returns less than 1 — a write that keeps zero backups would make
     * a manual recovery impossible.
     */
    public static function keep(): int
    {
        $keep = self::DEFAULT_KEEP;
        if (function_exists('apply_filters')) {
            /** @psalm-suppress MixedAssignment */
            $keep = (int) apply_filters('rolepod_wp_backup_keep', $keep);
        }
        return max(1, $keep);
    }

    /**
     * Prune `<absPath>.wplab-bak-*` down to the newest $keep copies.
     *
     * @return list<string> absolute paths actually deleted
     */
    public static function prune(string $absPath, ?int $keep = null): array
    {
        return self::pruneGroup(
            dirname($absPath),
            basename($absPath) . self::SUFFIX,
            '',
            $keep
        );
    }

    /**
     * Prune every file in $dir named "$prefix...$suffix" down to the newest
     * $keep. Ordered by mtime (newest first) with the filename as tie-break —
     * batch backups are named by batch id, not by a timestamp, so name order
     * alone is not chronological.
     *
     * @return list<string> absolute paths actually deleted
     */
    public static function pruneGroup(
        string $dir,
        string $prefix,
        string $suffix = '',
        ?int $keep = null,
        string $stampPattern = self::STAMP_PATTERN
    ): array {
        $keep = $keep ?? self::keep();
        if ($keep < 1) {
            $keep = 1;
        }
        if (!is_dir($dir)) {
            return [];
        }
        $names = @scandir($dir);
        if ($names === false) {
            return [];
        }

        clearstatcache();
        $found = [];
        $prefixLen = strlen($prefix);
        $suffixLen = strlen($suffix);
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if ($prefixLen > 0 && strncmp($name, $prefix, $prefixLen) !== 0) {
                continue;
            }
            // Require at least one character of stamp between prefix and suffix,
            // so the file the backups belong to is never itself a candidate.
            if (strlen($name) <= $prefixLen + $suffixLen) {
                continue;
            }
            if ($suffixLen > 0 && substr($name, -$suffixLen) !== $suffix) {
                continue;
            }
            $stamp = substr($name, $prefixLen, strlen($name) - $prefixLen - $suffixLen);
            if ($stampPattern !== '' && preg_match($stampPattern, $stamp) !== 1) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path)) {
                continue;
            }
            $found[] = ['path' => $path, 'mtime' => (int) @filemtime($path), 'name' => $name];
        }

        if (count($found) <= $keep) {
            return [];
        }

        usort($found, static function (array $a, array $b): int {
            if ($a['mtime'] !== $b['mtime']) {
                return $b['mtime'] <=> $a['mtime'];
            }
            return strcmp($b['name'], $a['name']);
        });

        $deleted = [];
        foreach (array_slice($found, $keep) as $old) {
            if (@unlink($old['path'])) {
                $deleted[] = $old['path'];
            }
        }
        return $deleted;
    }
}
