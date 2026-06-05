<?php
declare(strict_types=1);

namespace Rolepod\Wp\Media;

/**
 * Cumulative media-optimize statistics, persisted in a single wp_option row.
 * Feeds the admin "Media" page: total images optimized, bytes saved, % saved,
 * plus a cached snapshot of the current library size.
 */
final class Stats
{
    private const OPTION = 'rolepod_wp_media_stats';
    private const LIB_TRANSIENT = 'rolepod_wp_media_library_bytes';
    private const LIB_TTL = 3600; // 1h cache for the (expensive) library scan

    /** @return array{optimized_count:int,bytes_before:int,bytes_after:int,last_run_at:int} */
    public static function get(): array
    {
        $raw = get_option(self::OPTION, []);
        if (!is_array($raw)) {
            $raw = [];
        }
        return [
            'optimized_count' => (int) ($raw['optimized_count'] ?? 0),
            'bytes_before' => (int) ($raw['bytes_before'] ?? 0),
            'bytes_after' => (int) ($raw['bytes_after'] ?? 0),
            'last_run_at' => (int) ($raw['last_run_at'] ?? 0),
        ];
    }

    /** Add one optimized image's before/after to the running totals. */
    public static function record(int $before, int $after): void
    {
        $s = self::get();
        $s['optimized_count']++;
        $s['bytes_before'] += $before;
        $s['bytes_after'] += $after;
        $s['last_run_at'] = time();
        update_option(self::OPTION, $s, false);
        // Library size shrank — invalidate the cached snapshot.
        delete_transient(self::LIB_TRANSIENT);
    }

    public static function reset(): void
    {
        delete_option(self::OPTION);
        delete_transient(self::LIB_TRANSIENT);
    }

    /** Total bytes saved across all optimize runs. */
    public static function totalSaved(): int
    {
        $s = self::get();
        return max(0, $s['bytes_before'] - $s['bytes_after']);
    }

    /** Percent reduction across all optimize runs (0 when nothing optimized). */
    public static function percentSaved(): float
    {
        $s = self::get();
        if ($s['bytes_before'] <= 0) {
            return 0.0;
        }
        return round(($s['bytes_before'] - $s['bytes_after']) / $s['bytes_before'] * 100, 1);
    }

    /**
     * Current total bytes of all image-library originals on disk. Cached for an
     * hour — scanning every attachment's filesize is too costly for a pageview.
     *
     * @return array{bytes:int,count:int,cached:bool}
     */
    public static function libraryBytes(bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = get_transient(self::LIB_TRANSIENT);
            if (is_array($cached)) {
                return ['bytes' => (int) $cached['bytes'], 'count' => (int) $cached['count'], 'cached' => true];
            }
        }

        $items = Optimizer::scan(100_000);
        $bytes = array_sum(array_map(static fn(array $i): int => $i['bytes'], $items));
        $snapshot = ['bytes' => (int) $bytes, 'count' => count($items)];
        set_transient(self::LIB_TRANSIENT, $snapshot, self::LIB_TTL);

        return $snapshot + ['cached' => false];
    }
}
