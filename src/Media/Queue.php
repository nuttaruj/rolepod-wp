<?php
declare(strict_types=1);

namespace Rolepod\Wp\Media;

/**
 * Throttled background optimize queue.
 *
 * Image-optimize plugins routinely peg CPU to 100% and freeze the site by
 * batch-encoding everything at once. This queue does the opposite: a WP-Cron
 * tick processes only a SMALL batch per run (default 3), sleeps between images,
 * and yields after a short wall-time budget — so optimization trickles through
 * in the background without ever monopolising the box.
 *
 * State lives in one wp_option; a transient lock prevents overlapping ticks
 * from double-processing.
 */
final class Queue
{
    public const CRON_HOOK = 'rolepod_wp_media_optimize_tick';
    public const SCHEDULE = 'rolepod_wp_minute';
    public const AJAX_ACTION = 'rolepod_wp_bg_media';
    private const OPTION = 'rolepod_wp_media_queue';
    private const LOCK = 'rolepod_wp_media_lock';

    // CPU is bounded by the per-tick TIME BUDGET, then the request yields — and a
    // self-sustaining loopback chain runs ticks back-to-back instead of waiting
    // for a cron tick once a minute (which made a big library take ~ages). No
    // per-item sleep: an image encode already paces CPU; the sleep was pure
    // extra latency. BATCH is a high safety cap so the time-box is the limiter.
    private const BATCH_DEFAULT = 200;
    private const BATCH_MAX = 2000;
    private const SLEEP_US = 0;
    private const TICK_BUDGET_S = 10.0;
    private const LOCK_TTL = 60;

    /**
     * Register the 1-minute cron schedule. Hook on `cron_schedules`.
     *
     * @param array<string, array{interval:int,display:string}> $schedules
     * @return array<string, array{interval:int,display:string}>
     */
    public static function registerSchedule(array $schedules): array
    {
        if (!isset($schedules[self::SCHEDULE])) {
            $schedules[self::SCHEDULE] = ['interval' => 60, 'display' => 'Every minute (Rolepod media)'];
        }
        return $schedules;
    }

    /**
     * Queue a set of attachment ids for background optimization and start the
     * cron loop. Replaces any existing queue.
     *
     * @param int[] $ids
     * @param array{max_dimension?:int,quality?:int,batch?:int} $settings
     */
    public static function enqueue(array $ids, array $settings = []): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $queue = [
            'ids' => $ids,
            'total' => count($ids),
            'settings' => [
                'max_dimension' => max(0, (int) ($settings['max_dimension'] ?? 0)),
                'quality' => max(1, min(100, (int) ($settings['quality'] ?? 82))),
                'batch' => max(1, min(self::BATCH_MAX, (int) ($settings['batch'] ?? self::BATCH_DEFAULT))),
            ],
            'status' => $ids === [] ? 'idle' : 'running',
            'bg_secret' => bin2hex(random_bytes(16)),
            'started_at' => time(),
            'completed_at' => 0,
        ];
        update_option(self::OPTION, $queue, false);

        if ($ids !== []) {
            self::ensureScheduled();
            // Self-sustaining loopback chain — runs ticks back-to-back without
            // waiting for the once-a-minute cron (cron stays as a fallback).
            self::spawnLoopback();
        }
        return self::status();
    }

    /** Fire a non-blocking loopback that runs the next tick + re-spawns. */
    public static function spawnLoopback(): void
    {
        $q = self::raw();
        if (($q['status'] ?? '') !== 'running' || empty($q['bg_secret'])) {
            return;
        }
        wp_remote_post(admin_url('admin-ajax.php'), [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => false,
            'cookies' => [],
            'body' => ['action' => self::AJAX_ACTION, 'secret' => (string) $q['bg_secret']],
        ]);
    }

    /** admin-ajax handler for the media loopback chain (secret-authenticated). */
    public static function handleLoopback(): void
    {
        $secret = isset($_REQUEST['secret']) ? sanitize_text_field((string) wp_unslash($_REQUEST['secret'])) : '';
        $q = self::raw();
        $expected = (string) ($q['bg_secret'] ?? '');
        if (($q['status'] ?? '') !== 'running' || $expected === '' || !hash_equals($expected, $secret)) {
            wp_die('', '', ['response' => 200]);
        }
        @set_time_limit(0);
        @ignore_user_abort(true);
        $result = self::tick();
        if (($result['status'] ?? '') !== 'locked' && (self::raw()['status'] ?? '') === 'running') {
            self::spawnLoopback();
        }
        wp_die('', '', ['response' => 200]);
    }

    /**
     * Cron callback — process one throttled batch. Also callable synchronously
     * from the admin "Process now" button. Returns a small summary.
     *
     * @return array{processed:int,remaining:int,skipped:int,status:string}
     */
    public static function tick(): array
    {
        // Single-flight: bail if another tick holds the lock.
        if (get_transient(self::LOCK)) {
            return ['processed' => 0, 'remaining' => self::remainingCount(), 'skipped' => 0, 'status' => 'locked'];
        }
        set_transient(self::LOCK, time(), self::LOCK_TTL);

        try {
            // Respect guardian safe-mode — pause rather than overwrite files.
            if (get_option('rolepod_wp_safe_mode', false)) {
                return ['processed' => 0, 'remaining' => self::remainingCount(), 'skipped' => 0, 'status' => 'safe_mode'];
            }

            $queue = self::raw();
            if (($queue['status'] ?? 'idle') !== 'running' || empty($queue['ids'])) {
                self::finishIfEmpty($queue);
                return ['processed' => 0, 'remaining' => count($queue['ids'] ?? []), 'skipped' => 0, 'status' => $queue['status'] ?? 'idle'];
            }

            $batch = (int) ($queue['settings']['batch'] ?? self::BATCH_DEFAULT);
            $maxDim = (int) ($queue['settings']['max_dimension'] ?? 0);
            $quality = (int) ($queue['settings']['quality'] ?? 82);

            $start = microtime(true);
            $processed = 0;
            $skipped = 0;

            while (
                !empty($queue['ids'])
                && ($processed + $skipped) < $batch
                && (microtime(true) - $start) < self::TICK_BUDGET_S
            ) {
                if (self::SLEEP_US > 0) {
                    usleep(self::SLEEP_US);
                }
                $id = (int) array_shift($queue['ids']);
                $result = Optimizer::optimizeOne($id, $maxDim, $quality);
                if (($result['action'] ?? '') === 'optimized') {
                    $processed++;
                } else {
                    $skipped++;
                }
                // Persist progress after every image so a crash mid-batch never
                // re-processes or loses the queue.
                update_option(self::OPTION, $queue, false);
            }

            self::finishIfEmpty($queue);

            return [
                'processed' => $processed,
                'remaining' => count($queue['ids']),
                'skipped' => $skipped,
                'status' => empty($queue['ids']) ? 'idle' : 'running',
            ];
        } finally {
            delete_transient(self::LOCK);
        }
    }

    /** @return array<string, mixed> */
    public static function status(): array
    {
        $q = self::raw();
        $remaining = count($q['ids'] ?? []);
        $total = (int) ($q['total'] ?? 0);
        return [
            'status' => $q['status'] ?? 'idle',
            'total' => $total,
            'remaining' => $remaining,
            'done' => max(0, $total - $remaining),
            'settings' => $q['settings'] ?? [],
            'started_at' => (int) ($q['started_at'] ?? 0),
            'completed_at' => (int) ($q['completed_at'] ?? 0),
            'scheduled' => (bool) wp_next_scheduled(self::CRON_HOOK),
        ];
    }

    public static function pause(): void
    {
        $q = self::raw();
        if (($q['status'] ?? '') === 'running') {
            $q['status'] = 'paused';
            update_option(self::OPTION, $q, false);
        }
        self::unschedule();
    }

    public static function resume(): void
    {
        $q = self::raw();
        if (($q['status'] ?? '') === 'paused' && !empty($q['ids'])) {
            $q['status'] = 'running';
            update_option(self::OPTION, $q, false);
            self::ensureScheduled();
            self::spawnLoopback();
        }
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
        delete_transient(self::LOCK);
        self::unschedule();
    }

    public static function ensureScheduled(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 5, self::SCHEDULE, self::CRON_HOOK);
        }
    }

    public static function unschedule(): void
    {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        while ($ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
            $ts = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    /** @return array<string, mixed> */
    private static function raw(): array
    {
        $q = get_option(self::OPTION, []);
        return is_array($q) ? $q : [];
    }

    private static function remainingCount(): int
    {
        return count(self::raw()['ids'] ?? []);
    }

    /**
     * If the queue drained, flip to idle, stamp completion, and stop the cron.
     *
     * @param array<string, mixed> $queue
     */
    private static function finishIfEmpty(array $queue): void
    {
        if (!empty($queue['ids'])) {
            return;
        }
        if (($queue['status'] ?? '') === 'running') {
            $queue['status'] = 'idle';
            $queue['completed_at'] = time();
            update_option(self::OPTION, $queue, false);
        }
        self::unschedule();
    }
}
