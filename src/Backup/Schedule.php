<?php
declare(strict_types=1);

namespace Rolepod\Wp\Backup;

/**
 * Scheduled backups + retention.
 *
 * Stores a single config (enabled / frequency / components / compress /
 * retention) and drives a WP-Cron event that starts a backup on the chosen
 * cadence. Retention ("keep the last N backups") is enforced after every
 * completed backup by Engine::recordHistory, so it applies to manual and
 * scheduled backups alike.
 */
final class Schedule
{
    public const CRON_HOOK = 'rolepod_wp_backup_scheduled';
    private const OPTION = 'rolepod_wp_backup_schedule';
    private const WEEKLY = 'rolepod_wp_weekly';

    /** @return array{enabled:bool,frequency:string,components:array<string,bool>,compress:bool,retention:int} */
    public static function get(): array
    {
        $raw = get_option(self::OPTION, []);
        if (!is_array($raw)) {
            $raw = [];
        }
        $comp = is_array($raw['components'] ?? null) ? $raw['components'] : [];
        return [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'frequency' => self::normalizeFrequency((string) ($raw['frequency'] ?? 'daily')),
            'components' => [
                'db' => (bool) ($comp['db'] ?? true),
                'uploads' => (bool) ($comp['uploads'] ?? true),
                'themes' => (bool) ($comp['themes'] ?? true),
                'plugins' => (bool) ($comp['plugins'] ?? false),
                'muplugins' => (bool) ($comp['muplugins'] ?? false),
            ],
            'compress' => (bool) ($raw['compress'] ?? true),
            'retention' => min(50, max(0, (int) ($raw['retention'] ?? 0))),
        ];
    }

    /**
     * @param array<string,mixed> $patch
     */
    public static function save(array $patch): void
    {
        $cfg = array_merge(self::get(), $patch);
        $cfg['frequency'] = self::normalizeFrequency((string) $cfg['frequency']);
        $cfg['retention'] = min(50, max(0, (int) $cfg['retention']));
        update_option(self::OPTION, $cfg, false);
        self::reschedule();
    }

    public static function retention(): int
    {
        return (int) self::get()['retention'];
    }

    /** Unschedule + (re)schedule the cron event to match the current config. */
    public static function reschedule(): void
    {
        self::unschedule();
        $cfg = self::get();
        if (!$cfg['enabled']) {
            return;
        }
        $interval = self::wpInterval($cfg['frequency']);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, $interval, self::CRON_HOOK);
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

    public static function nextRun(): int
    {
        return (int) (wp_next_scheduled(self::CRON_HOOK) ?: 0);
    }

    /**
     * Cron callback: start a scheduled backup unless one is already running
     * (don't pile up). Retention is applied automatically when it finishes.
     */
    public static function runScheduled(): void
    {
        $cfg = self::get();
        if (!$cfg['enabled']) {
            return;
        }
        if ((Engine::status()['status'] ?? '') === 'running') {
            return; // a backup is already in progress — skip this tick.
        }
        Engine::start($cfg['components'], ['compress' => $cfg['compress'], 'origin' => 'scheduled']);
    }

    /**
     * Register the custom weekly schedule (WP ships hourly/twicedaily/daily).
     *
     * @param array<string, array{interval:int,display:string}> $schedules
     * @return array<string, array{interval:int,display:string}>
     */
    public static function registerSchedules(array $schedules): array
    {
        if (!isset($schedules[self::WEEKLY])) {
            $schedules[self::WEEKLY] = ['interval' => 604800, 'display' => 'Once weekly (Rolepod)'];
        }
        return $schedules;
    }

    private static function normalizeFrequency(string $f): string
    {
        return in_array($f, ['hourly', 'daily', 'weekly'], true) ? $f : 'daily';
    }

    private static function wpInterval(string $frequency): string
    {
        switch ($frequency) {
            case 'hourly': return 'hourly';
            case 'weekly': return self::WEEKLY;
            default: return 'daily';
        }
    }
}
