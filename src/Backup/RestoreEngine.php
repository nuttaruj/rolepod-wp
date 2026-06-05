<?php
declare(strict_types=1);

namespace Rolepod\Wp\Backup;

use ZipArchive;

/**
 * Throttled, resumable RESTORE engine (backup phase 2).
 *
 * Restore is the destructive counterpart of Engine: it imports database.sql and
 * writes files/ back into wp-content from a backup zip. Like backup it is a
 * cron-driven state machine — prepare → db → files → done — that does only a
 * small chunk per tick (a budgeted run of SQL statements / a batch of files),
 * so even a large restore never blocks the front end.
 *
 * Safety:
 *   - caller must pass confirm=true (destructive).
 *   - file targets are realpath-validated to stay under wp-content (zip-slip
 *     protection); restore is additive-overwrite (never deletes files absent
 *     from the backup).
 *   - DB import is wrapped with FOREIGN_KEY_CHECKS handling from the dump.
 *   - selective: restore db only, files only, or a files/ path prefix.
 *
 * Same-site restore only (no serialized URL rewrite); cross-site migration is a
 * later phase.
 */
final class RestoreEngine
{
    public const CRON_HOOK = 'rolepod_wp_restore_tick';
    public const SCHEDULE = 'rolepod_wp_minute';
    public const AJAX_ACTION = 'rolepod_wp_bg_restore';
    private const JOB_OPTION = 'rolepod_wp_restore_job';
    private const LOCK = 'rolepod_wp_restore_lock';

    private const STMT_BUDGET_S = 8.0;
    private const FILE_BATCH = 40;
    private const SLEEP_US = 120_000;
    private const LOCK_TTL = 300;

    /**
     * Begin a restore from a backup zip.
     *
     * @param array{db?:bool,files?:bool} $components
     * @param array{confirm?:bool,path_prefix?:string,search_replace?:array<string,string>} $opts
     * @return array<string,mixed>
     */
    public static function start(string $zipPath, array $components, array $opts = []): array
    {
        if (empty($opts['confirm'])) {
            return ['ok' => false, 'error' => 'CONFIRM_REQUIRED', 'message' => 'restore is destructive — pass confirm=true'];
        }
        if (!is_file($zipPath)) {
            return ['ok' => false, 'error' => 'BACKUP_NOT_FOUND'];
        }
        $cur = self::raw();
        if (($cur['status'] ?? '') === 'running') {
            return ['ok' => false, 'error' => 'ALREADY_RUNNING', 'job' => self::status()];
        }
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'ZIP_UNAVAILABLE'];
        }

        $db = (bool) ($components['db'] ?? true);
        $files = (bool) ($components['files'] ?? true);
        $searchReplace = [];
        foreach ((array) ($opts['search_replace'] ?? []) as $from => $to) {
            $from = (string) $from;
            if ($from !== '') {
                $searchReplace[$from] = (string) $to;
            }
        }
        $buildDir = Engine::backupsDir() . '/.restore-' . gmdate('Ymd-His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
        wp_mkdir_p($buildDir);

        $job = [
            'id' => 'rs_' . gmdate('Ymd-His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6),
            'status' => 'running',
            'stage' => 'prepare',
            'bg_secret' => bin2hex(random_bytes(16)),
            'zip_path' => $zipPath,
            'components' => ['db' => $db, 'files' => $files],
            'path_prefix' => (string) ($opts['path_prefix'] ?? ''),
            'search_replace' => $searchReplace,
            'build_dir' => $buildDir,
            'db' => ['sql_tmp' => $buildDir . '/database.sql', 'offset' => 0, 'statements' => 0, 'bytes' => 0],
            'rewrite' => ['tables' => [], 'tidx' => 0, 'offset' => 0, 'rows_changed' => 0, 'skipped_tables' => 0],
            'files' => ['list_path' => $buildDir . '/restore-files.txt', 'cursor' => 0, 'total' => 0, 'restored' => 0, 'skipped' => 0],
            'started_at' => time(),
            'updated_at' => time(),
            'completed_at' => 0,
            'error' => null,
        ];
        update_option(self::JOB_OPTION, $job, false);
        self::ensureScheduled();
        self::spawnLoopback();
        return ['ok' => true, 'job' => self::status()];
    }

    /** Fire a non-blocking loopback that runs the next tick + re-spawns (see Engine). */
    public static function spawnLoopback(): void
    {
        $job = self::raw();
        if (($job['status'] ?? '') !== 'running' || empty($job['bg_secret'])) {
            return;
        }
        wp_remote_post(admin_url('admin-ajax.php'), [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => false,
            'cookies' => [],
            'body' => ['action' => self::AJAX_ACTION, 'secret' => (string) $job['bg_secret']],
        ]);
    }

    /** admin-ajax handler for the restore loopback chain (secret-authenticated). */
    public static function handleLoopback(): void
    {
        $secret = isset($_REQUEST['secret']) ? sanitize_text_field((string) wp_unslash($_REQUEST['secret'])) : '';
        $job = self::raw();
        $expected = (string) ($job['bg_secret'] ?? '');
        if (($job['status'] ?? '') !== 'running' || $expected === '' || !hash_equals($expected, $secret)) {
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

    public static function tick(): array
    {
        if (get_transient(self::LOCK)) {
            return ['status' => 'locked'];
        }
        set_transient(self::LOCK, time(), self::LOCK_TTL);
        try {
            $job = self::raw();
            if (($job['status'] ?? '') !== 'running') {
                self::unschedule();
                return ['status' => $job['status'] ?? 'idle'];
            }
            $start = microtime(true);
            try {
                switch ($job['stage']) {
                    case 'prepare': $job = self::stepPrepare($job); break;
                    case 'db': $job = self::stepDb($job, $start); break;
                    case 'rewrite': $job = self::stepRewrite($job, $start); break;
                    case 'files': $job = self::stepFiles($job, $start); break;
                }
            } catch (\Throwable $t) {
                $job['status'] = 'error';
                $job['error'] = $t->getMessage();
                self::unschedule();
            }
            $job['updated_at'] = time();
            if ($job['stage'] === 'done') {
                $job['status'] = 'done';
                $job['completed_at'] = time();
                self::cleanupBuild($job);
            }
            update_option(self::JOB_OPTION, $job, false);
            if (in_array($job['status'], ['done', 'error'], true)) {
                self::unschedule();
            }
            return self::statusFrom($job);
        } finally {
            delete_transient(self::LOCK);
        }
    }

    // --- stages ------------------------------------------------------------

    private static function stepPrepare(array $job): array
    {
        $zip = new ZipArchive();
        if ($zip->open($job['zip_path']) !== true) {
            throw new \RuntimeException('ZIP_OPEN_FAILED');
        }

        if ($job['components']['db']) {
            $stream = $zip->getStream('database.sql');
            if ($stream === false) {
                $zip->close();
                throw new \RuntimeException('NO_DATABASE_SQL_IN_BACKUP');
            }
            $out = @fopen($job['db']['sql_tmp'], 'wb');
            if ($out === false) {
                fclose($stream);
                $zip->close();
                throw new \RuntimeException('SQL_TMP_UNWRITABLE');
            }
            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
            $job['db']['bytes'] = (int) filesize($job['db']['sql_tmp']);
        }

        if ($job['components']['files']) {
            $list = @fopen($job['files']['list_path'], 'w');
            $total = 0;
            if ($list !== false) {
                $prefixFilter = 'files/' . ltrim($job['path_prefix'], '/');
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (!is_string($name) || strpos($name, 'files/') !== 0) {
                        continue;
                    }
                    if ($job['path_prefix'] !== '' && strpos($name, $prefixFilter) !== 0) {
                        continue;
                    }
                    if (substr($name, -1) === '/') {
                        continue; // directory entry
                    }
                    fwrite($list, $name . "\n");
                    $total++;
                }
                fclose($list);
            }
            $job['files']['total'] = $total;
        }

        $zip->close();
        $job['stage'] = $job['components']['db'] ? 'db' : ($job['components']['files'] ? 'files' : 'done');
        return $job;
    }

    private static function stepDb(array $job, float $start): array
    {
        $fh = @fopen($job['db']['sql_tmp'], 'rb');
        if ($fh === false) {
            $job['stage'] = $job['components']['files'] ? 'files' : 'done';
            return $job;
        }
        fseek($fh, (int) $job['db']['offset']);

        global $wpdb;
        $buffer = '';
        $executed = 0;
        while ((microtime(true) - $start) < self::STMT_BUDGET_S) {
            $line = fgets($fh);
            if ($line === false) {
                // EOF — run any trailing statement, then advance stage.
                $tail = trim($buffer);
                if ($tail !== '') {
                    self::execStatement($wpdb, $tail);
                    $job['db']['statements']++;
                }
                $job['db']['offset'] = ftell($fh);
                fclose($fh);
                $job['stage'] = self::stageAfterDb($job);
                return $job;
            }
            $buffer .= $line;
            // Statements end with ";" at end of a line. Values never contain a
            // raw newline (escaped at dump time), so this boundary is safe.
            if (substr(rtrim($line), -1) === ';') {
                $stmt = trim($buffer);
                $buffer = '';
                if ($stmt !== '' && strpos($stmt, '--') !== 0) {
                    self::execStatement($wpdb, $stmt);
                    $job['db']['statements']++;
                    $executed++;
                }
                $job['db']['offset'] = ftell($fh);
            }
        }
        fclose($fh);
        return $job; // more next tick
    }

    private static function execStatement($wpdb, string $stmt): void
    {
        // Comments / blank → skip.
        if ($stmt === '' || strpos($stmt, '--') === 0) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($stmt);
    }

    /** Stage to enter once db import finishes: rewrite (if a map is set) → files → done. */
    private static function stageAfterDb(array $job): string
    {
        if (!empty($job['search_replace'])) {
            return 'rewrite';
        }
        return !empty($job['components']['files']) ? 'files' : 'done';
    }

    /**
     * Serialized-safe URL/path rewrite over the imported DB (migration support).
     * Walks every PRIMARY-KEY table row-by-row in throttled batches, applying
     * SerializedReplace to string columns and updating changed rows by PK.
     * Tables without a single-column primary key are skipped (can't UPDATE
     * safely) and counted.
     */
    private static function stepRewrite(array $job, float $start): array
    {
        global $wpdb;
        $map = (array) $job['search_replace'];
        if ($map === []) {
            $job['stage'] = !empty($job['components']['files']) ? 'files' : 'done';
            return $job;
        }
        if (empty($job['rewrite']['tables'])) {
            $job['rewrite']['tables'] = Db::tables();
        }
        $tables = $job['rewrite']['tables'];

        while (
            $job['rewrite']['tidx'] < count($tables)
            && (microtime(true) - $start) < self::STMT_BUDGET_S
        ) {
            $table = $tables[$job['rewrite']['tidx']];
            $pk = self::primaryKey($table);
            if ($pk === null) {
                $job['rewrite']['skipped_tables']++;
                $job['rewrite']['tidx']++;
                $job['rewrite']['offset'] = 0;
                continue;
            }
            $t = str_replace('`', '``', $table);
            $pkc = str_replace('`', '``', $pk);
            // phpcs:ignore WordPress.DB.PreparedSQL
            $rows = $wpdb->get_results(
                $wpdb->prepare('SELECT * FROM `' . $t . '` ORDER BY `' . $pkc . '` LIMIT %d, %d', (int) $job['rewrite']['offset'], 200),
                ARRAY_A
            );
            if (!is_array($rows) || $rows === []) {
                $job['rewrite']['tidx']++;
                $job['rewrite']['offset'] = 0;
                continue;
            }
            foreach ($rows as $row) {
                $update = [];
                foreach ($row as $col => $val) {
                    if ($col === $pk || !is_string($val) || $val === '') {
                        continue;
                    }
                    $nv = SerializedReplace::applyToValue($val, $map);
                    if ($nv !== $val) {
                        $update[$col] = $nv;
                    }
                }
                if ($update !== []) {
                    $wpdb->update($table, $update, [$pk => $row[$pk]]);
                    $job['rewrite']['rows_changed']++;
                }
            }
            $job['rewrite']['offset'] += count($rows);
            usleep(self::SLEEP_US);
        }

        if ($job['rewrite']['tidx'] >= count($tables)) {
            $job['stage'] = !empty($job['components']['files']) ? 'files' : 'done';
        }
        return $job;
    }

    /** Single-column PRIMARY KEY name for a table, or null (none / composite). */
    private static function primaryKey(string $table): ?string
    {
        global $wpdb;
        $t = str_replace('`', '``', $table);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $keys = $wpdb->get_results('SHOW KEYS FROM `' . $t . '` WHERE Key_name = "PRIMARY"', ARRAY_A);
        if (!is_array($keys) || count($keys) !== 1) {
            return null; // none or composite PK
        }
        return (string) ($keys[0]['Column_name'] ?? '');
    }

    private static function stepFiles(array $job, float $start): array
    {
        if ($job['files']['total'] === 0) {
            $job['stage'] = 'done';
            return $job;
        }
        $list = @fopen($job['files']['list_path'], 'r');
        if ($list === false) {
            $job['stage'] = 'done';
            return $job;
        }
        $line = 0;
        while ($line < $job['files']['cursor'] && fgets($list) !== false) {
            $line++;
        }

        $zip = new ZipArchive();
        if ($zip->open($job['zip_path']) !== true) {
            fclose($list);
            throw new \RuntimeException('ZIP_OPEN_FAILED at files');
        }

        $done = 0;
        while (
            $done < self::FILE_BATCH
            && (microtime(true) - $start) < self::STMT_BUDGET_S
            && ($entry = fgets($list)) !== false
        ) {
            $name = trim($entry);
            $job['files']['cursor']++;
            if ($name === '') {
                continue;
            }
            $rel = substr($name, strlen('files/'));
            $target = self::safeTarget($rel);
            if ($target === null) {
                $job['files']['skipped']++;
                continue;
            }
            $dir = dirname($target);
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
            $in = $zip->getStream($name);
            if ($in === false) {
                $job['files']['skipped']++;
                continue;
            }
            $out = @fopen($target, 'wb');
            if ($out === false) {
                fclose($in);
                $job['files']['skipped']++;
                continue;
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            $job['files']['restored']++;
            $done++;
            usleep(self::SLEEP_US);
        }
        $zip->close();
        fclose($list);

        if ($job['files']['cursor'] >= $job['files']['total']) {
            $job['stage'] = 'done';
        }
        return $job;
    }

    /**
     * Resolve a backup-relative path to an absolute wp-content target, rejecting
     * anything that escapes wp-content (zip-slip / traversal protection).
     */
    private static function safeTarget(string $rel): ?string
    {
        $rel = str_replace('\\', '/', $rel);
        if ($rel === '' || strpos($rel, '..') !== false || $rel[0] === '/') {
            return null;
        }
        $base = rtrim(str_replace('\\', '/', WP_CONTENT_DIR), '/');
        $target = $base . '/' . $rel;
        // Validate the resolved parent dir stays under wp-content.
        $parent = dirname($target);
        $realParent = is_dir($parent) ? realpath($parent) : self::firstExistingAncestor($parent);
        if ($realParent === null) {
            return null;
        }
        $realParent = str_replace('\\', '/', $realParent);
        if ($realParent !== $base && strpos($realParent, $base . '/') !== 0) {
            return null;
        }
        return $target;
    }

    private static function firstExistingAncestor(string $dir): ?string
    {
        $dir = str_replace('\\', '/', $dir);
        $base = rtrim(str_replace('\\', '/', WP_CONTENT_DIR), '/');
        while ($dir !== '' && $dir !== '/' && strlen($dir) >= strlen($base)) {
            if (is_dir($dir)) {
                $r = realpath($dir);
                return $r === false ? null : $r;
            }
            $dir = dirname($dir);
        }
        return null;
    }

    // --- status / control --------------------------------------------------

    public static function status(): array
    {
        return self::statusFrom(self::raw());
    }

    private static function statusFrom(array $job): array
    {
        return [
            'id' => $job['id'] ?? null,
            'status' => $job['status'] ?? 'idle',
            'stage' => $job['stage'] ?? null,
            'components' => $job['components'] ?? [],
            'db' => ['statements' => (int) ($job['db']['statements'] ?? 0), 'bytes' => (int) ($job['db']['bytes'] ?? 0), 'offset' => (int) ($job['db']['offset'] ?? 0)],
            'rewrite' => ['rows_changed' => (int) ($job['rewrite']['rows_changed'] ?? 0), 'skipped_tables' => (int) ($job['rewrite']['skipped_tables'] ?? 0)],
            'files' => ['restored' => (int) ($job['files']['restored'] ?? 0), 'total' => (int) ($job['files']['total'] ?? 0), 'skipped' => (int) ($job['files']['skipped'] ?? 0)],
            'started_at' => (int) ($job['started_at'] ?? 0),
            'completed_at' => (int) ($job['completed_at'] ?? 0),
            'error' => $job['error'] ?? null,
            'scheduled' => (bool) wp_next_scheduled(self::CRON_HOOK),
            'percent' => self::percent($job),
        ];
    }

    private static function percent(array $job): int
    {
        $status = $job['status'] ?? 'idle';
        if ($status === 'done') {
            return 100;
        }
        if ($status !== 'running') {
            return 0;
        }
        switch ($job['stage'] ?? '') {
            case 'prepare': return 2;
            case 'db':
                $tot = max(1, (int) ($job['db']['bytes'] ?? 1));
                return 5 + (int) round(min(40, ($job['db']['offset'] ?? 0) / $tot * 40));
            case 'rewrite':
                $tt = max(1, count($job['rewrite']['tables'] ?? []));
                return 45 + (int) round(min(5, ($job['rewrite']['tidx'] ?? 0) / $tt * 5));
            case 'files':
                $tot = max(1, (int) ($job['files']['total'] ?? 1));
                return 50 + (int) round(min(48, ($job['files']['cursor'] ?? 0) / $tot * 48));
            default: return 99;
        }
    }

    public static function cancel(): void
    {
        $job = self::raw();
        if (!empty($job)) {
            self::cleanupBuild($job);
        }
        delete_option(self::JOB_OPTION);
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

    private static function cleanupBuild(array $job): void
    {
        $bd = $job['build_dir'] ?? '';
        if ($bd === '' || !is_dir($bd)) {
            return;
        }
        foreach ((array) glob($bd . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($bd);
    }

    private static function raw(): array
    {
        $j = get_option(self::JOB_OPTION, []);
        return is_array($j) ? $j : [];
    }
}
