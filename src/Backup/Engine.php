<?php
declare(strict_types=1);

namespace Rolepod\Wp\Backup;

/**
 * Throttled, resumable backup engine.
 *
 * A backup is a state machine — db → files → finalize — driven by a loopback
 * chain (+ cron fallback). Each tick does one TIME-BOXED chunk (~12s of work,
 * no per-item sleep) then yields the request, so CPU is bounded per request but
 * the job still runs fast; a small site finishes in a tick or two.
 *
 * Output is one browsable ZIP per backup under
 * uploads/rolepod-wp/backups/backup-<id>.zip containing manifest.json (first),
 * database.sql, database.meta.json, and files/ mirroring wp-content. State is
 * persisted every tick (crash-safe); a transient lock prevents overlapping
 * ticks.
 */
final class Engine
{
    public const CRON_HOOK = 'rolepod_wp_backup_tick';
    public const SCHEDULE = 'rolepod_wp_minute'; // shared 60s schedule (see Media\Queue)
    public const AJAX_ACTION = 'rolepod_wp_bg_backup';
    private const JOB_OPTION = 'rolepod_wp_backup_job';
    private const HISTORY_OPTION = 'rolepod_wp_backups';
    private const LOCK = 'rolepod_wp_backup_lock';

    // CPU is controlled by the per-tick TIME BUDGET (work flat-out, then yield
    // the request so the worker is freed) — NOT by sleeping between items.
    // A per-item usleep made small sites take minutes (the sleep was ~99% of
    // the wall time); time-boxing is how fast backup tools stay host-friendly.
    // SLEEP_US is kept at 0 (configurable escape hatch for paranoid shared hosts).
    private const ROW_BATCH = 2000;
    private const FILE_BATCH = 500;
    private const TICK_BUDGET_S = 12.0;
    private const SLEEP_US = 0;
    private const LOCK_TTL = 60;
    private const MAX_FILES = 200_000;

    private const DEFAULT_EXCLUDES = [
        '.git', 'node_modules', '.DS_Store', 'cache/',
        // Rolepod's own regenerable runtime artifacts — not site content, and
        // they bloat the archive badly (theme snapshots + the bundled wp-cli
        // phar can be tens of MB). Never back these up by default.
        'rolepod-wp/backups', 'rolepod-wp/tmp', 'rolepod-wp-theme-snapshots',
        'rolepod-wp-audit', 'wplab-tmp', 'wplab-bin', 'wplab-backups',
        '.rolepod-jobs',
    ];

    /**
     * Start a new backup. Replaces any finished job; refuses if one is running.
     *
     * @param array{db?:bool,uploads?:bool,themes?:bool,plugins?:bool,muplugins?:bool} $components
     * @param array{compress?:bool,exclude?:string[]} $opts
     * @return array<string,mixed>
     */
    public static function start(array $components, array $opts = []): array
    {
        $existing = self::raw();
        if (($existing['status'] ?? '') === 'running') {
            return ['ok' => false, 'error' => 'ALREADY_RUNNING', 'job' => self::status()];
        }

        // Domain-first DISPLAY id so backups are self-identifying:
        // <domain>-<date>-<time> (+ short suffix). This is a label only.
        $id = self::siteSlug() . '-' . gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        // The ON-DISK filename is an unguessable 128-bit token, decoupled from
        // the readable id: the backups dir is only protected by an Apache-only
        // .htaccess, so on Nginx/LiteSpeed the zip would otherwise be a
        // brute-forceable public URL exposing the full DB dump. Downloads always
        // go through PHP (streamDownload), so the on-disk name is never needed.
        $disk = bin2hex(random_bytes(16));
        $dir = self::backupsDir();
        if ($dir === '') {
            return ['ok' => false, 'error' => 'BACKUP_DIR_UNWRITABLE'];
        }
        $buildDir = $dir . '/.build-' . $disk;
        wp_mkdir_p($buildDir);

        $comp = [
            'db' => (bool) ($components['db'] ?? true),
            'uploads' => (bool) ($components['uploads'] ?? true),
            'themes' => (bool) ($components['themes'] ?? true),
            'plugins' => (bool) ($components['plugins'] ?? false),
            'muplugins' => (bool) ($components['muplugins'] ?? false),
        ];

        $job = [
            'id' => $id,
            'status' => 'running',
            'stage' => $comp['db'] ? 'db' : 'files',
            'bg_secret' => bin2hex(random_bytes(16)),
            'components' => $comp,
            'compress' => (bool) ($opts['compress'] ?? true),
            'exclude' => array_values(array_unique(array_merge(self::DEFAULT_EXCLUDES, (array) ($opts['exclude'] ?? [])))),
            'zip_path' => $dir . '/' . $disk . '.zip',
            'build_dir' => $buildDir,
            'db' => ['tables' => Db::tables(), 'tidx' => 0, 'roffset' => 0, 'sql_path' => $buildDir . '/database.sql', 'rows' => 0],
            'files' => ['list_path' => $buildDir . '/filelist.txt', 'cursor' => 0, 'total' => 0, 'added' => 0, 'listed' => false],
            'stats' => ['db_bytes' => 0, 'db_tables' => 0, 'files_count' => 0, 'files_bytes' => 0, 'zip_bytes' => 0],
            'started_at' => time(),
            'updated_at' => time(),
            'completed_at' => 0,
            'error' => null,
        ];
        update_option(self::JOB_OPTION, $job, false);
        self::ensureScheduled();
        // Kick a self-sustaining server-side loopback chain so the backup runs
        // to completion even if the browser is closed and the site gets no
        // traffic (cron + the admin page are fallbacks, not requirements).
        self::spawnLoopback();
        return ['ok' => true, 'job' => self::status()];
    }

    /**
     * Fire a non-blocking loopback request to admin-ajax that runs the next
     * tick and re-spawns itself — a background chain independent of cron /
     * browser / traffic. No-ops if loopback HTTP is blocked by the host (the
     * cron tick + on-page auto-tick still advance the job).
     */
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

    /**
     * admin-ajax handler for the loopback chain. Authenticated by the per-job
     * secret (the request carries no admin cookie). Runs one tick, then spawns
     * the next link unless the tick was a no-op (another worker holds the lock)
     * or the job finished.
     */
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
        // Back off if another worker is mid-tick (avoid a loopback storm); the
        // active chain will continue itself.
        if (($result['status'] ?? '') !== 'locked' && (self::raw()['status'] ?? '') === 'running') {
            self::spawnLoopback();
        }
        wp_die('', '', ['response' => 200]);
    }

    /**
     * Cron callback — advance the job by one throttled chunk.
     *
     * @return array<string,mixed>
     */
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
                    case 'db':
                        $job = self::stepDb($job, $start);
                        break;
                    case 'files':
                        $job = self::stepFiles($job, $start);
                        break;
                    case 'finalize':
                        $job = self::stepFinalize($job);
                        break;
                }
            } catch (\Throwable $t) {
                $job['status'] = 'error';
                $job['error'] = $t->getMessage();
                self::unschedule();
            }

            $job['updated_at'] = time();
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

    private static function stepDb(array $job, float $start): array
    {
        $tables = $job['db']['tables'];
        $sqlPath = $job['db']['sql_path'];

        if ($job['db']['tidx'] === 0 && $job['db']['roffset'] === 0 && !is_file($sqlPath)) {
            @file_put_contents($sqlPath, "-- rolepod-wp backup {$job['id']}\n-- " . gmdate('c') . "\nSET FOREIGN_KEY_CHECKS=0;\n");
        }

        while (
            $job['db']['tidx'] < count($tables)
            && (microtime(true) - $start) < self::TICK_BUDGET_S
        ) {
            $table = $tables[$job['db']['tidx']];

            if ($job['db']['roffset'] === 0) {
                @file_put_contents($sqlPath, Db::schemaSql($table), FILE_APPEND);
            }

            $chunk = Db::rowsSql($table, $job['db']['roffset'], self::ROW_BATCH);
            if ($chunk['rows'] > 0) {
                @file_put_contents($sqlPath, $chunk['sql'], FILE_APPEND);
                $job['db']['roffset'] += $chunk['rows'];
                $job['db']['rows'] += $chunk['rows'];
            }
            if ($chunk['rows'] < self::ROW_BATCH) {
                // Table drained — advance.
                $job['db']['tidx']++;
                $job['db']['roffset'] = 0;
                $job['stats']['db_tables']++;
            }
            // Persist per batch so the live poll sees the DB stage move smoothly
            // (durable: the SQL is already appended to the temp file, roffset
            // marks our position).
            update_option(self::JOB_OPTION, $job, false);
            usleep(self::SLEEP_US);
        }

        if ($job['db']['tidx'] >= count($tables)) {
            @file_put_contents($sqlPath, "SET FOREIGN_KEY_CHECKS=1;\n", FILE_APPEND);
            $job['stats']['db_bytes'] = is_file($sqlPath) ? (int) filesize($sqlPath) : 0;
            $job['stage'] = 'files';
        }
        return $job;
    }

    private static function stepFiles(array $job, float $start): array
    {
        if (!$job['files']['listed']) {
            $job['files']['total'] = self::buildFileList($job);
            $job['files']['listed'] = true;
        }

        // Nothing to archive (db-only or empty) → still create the zip in finalize.
        if ($job['files']['total'] === 0) {
            $job['stage'] = 'finalize';
            return $job;
        }

        $list = @fopen($job['files']['list_path'], 'r');
        if ($list === false) {
            $job['stage'] = 'finalize';
            return $job;
        }

        // Seek to cursor line.
        $line = 0;
        while ($line < $job['files']['cursor'] && fgets($list) !== false) {
            $line++;
        }

        $archive = Archive::openForAppend($job['zip_path']);
        if ($archive === null) {
            fclose($list);
            throw new \RuntimeException('ZIP_OPEN_FAILED (ZipArchive missing or path unwritable)');
        }

        $added = 0;
        while (
            $added < self::FILE_BATCH
            && (microtime(true) - $start) < self::TICK_BUDGET_S
            && ($entry = fgets($list)) !== false
        ) {
            $rel = trim($entry);
            $job['files']['cursor']++;
            if ($rel === '') {
                continue;
            }
            $abs = WP_CONTENT_DIR . '/' . $rel;
            if (!is_file($abs)) {
                continue;
            }
            if ($archive->addFileSmart($abs, 'files/' . $rel)) {
                $job['files']['added']++;
                $job['stats']['files_count']++;
                $job['stats']['files_bytes'] += (int) filesize($abs);
                $added++;
            }
            usleep(self::SLEEP_US);
        }
        $archive->close();
        fclose($list);

        if ($job['files']['cursor'] >= $job['files']['total']) {
            $job['stage'] = 'finalize';
        }
        return $job;
    }

    private static function stepFinalize(array $job): array
    {
        $archive = Archive::openForAppend($job['zip_path']);
        if ($archive === null) {
            throw new \RuntimeException('ZIP_OPEN_FAILED at finalize');
        }

        // database.sql + meta (browsable individually inside the zip).
        $components = [];
        if ($job['components']['db'] && is_file($job['db']['sql_path'])) {
            $sql = (string) file_get_contents($job['db']['sql_path']);
            $archive->addStringSmart('database.sql', $sql);
            $archive->addStringSmart('database.meta.json', (string) wp_json_encode([
                'tables' => $job['stats']['db_tables'],
                'rows' => $job['db']['rows'],
                'bytes' => $job['stats']['db_bytes'],
                'table_prefix' => $GLOBALS['wpdb']->prefix,
            ], JSON_PRETTY_PRINT));
            $components['database'] = [
                'file' => 'database.sql',
                'tables' => $job['stats']['db_tables'],
                'rows' => $job['db']['rows'],
                'bytes' => $job['stats']['db_bytes'],
                'sha256' => hash_file('sha256', $job['db']['sql_path']) ?: null,
            ];
        }

        foreach (['uploads', 'themes', 'plugins', 'muplugins'] as $c) {
            if (!empty($job['components'][$c])) {
                $components[$c] = ['path' => 'files/' . self::componentRel($c)];
            }
        }
        $components['files'] = ['count' => $job['stats']['files_count'], 'bytes' => $job['stats']['files_bytes']];

        // manifest.json LAST so it captures final component data — but it is the
        // semantic index regardless of physical order; readers fetch it by name.
        $manifest = Manifest::build($job, $components);
        $archive->addStringSmart('manifest.json', (string) wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $archive->close();

        $job['stats']['zip_bytes'] = is_file($job['zip_path']) ? (int) filesize($job['zip_path']) : 0;
        $job['status'] = 'done';
        $job['completed_at'] = time();

        self::cleanupBuild($job);
        self::recordHistory($job, $manifest);
        return $job;
    }

    // --- file enumeration --------------------------------------------------

    private static function buildFileList(array $job): int
    {
        $roots = [];
        if (!empty($job['components']['uploads'])) {
            $roots['uploads'] = self::uploadsRel();
        }
        if (!empty($job['components']['themes'])) {
            $roots['themes'] = 'themes';
        }
        if (!empty($job['components']['plugins'])) {
            $roots['plugins'] = 'plugins';
        }
        if (!empty($job['components']['muplugins'])) {
            $roots['muplugins'] = 'mu-plugins';
        }

        $fh = @fopen($job['files']['list_path'], 'w');
        if ($fh === false) {
            return 0;
        }
        $count = 0;
        $excludes = (array) $job['exclude'];

        foreach ($roots as $rel) {
            $absRoot = WP_CONTENT_DIR . '/' . $rel;
            if (!is_dir($absRoot)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absRoot, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $abs = $fileInfo->getPathname();
                $relPath = ltrim(str_replace(WP_CONTENT_DIR, '', $abs), '/\\');
                $relPath = str_replace('\\', '/', $relPath);
                if (self::excluded($relPath, $excludes)) {
                    continue;
                }
                fwrite($fh, $relPath . "\n");
                if (++$count >= self::MAX_FILES) {
                    break 2;
                }
            }
        }
        fclose($fh);
        return $count;
    }

    private static function excluded(string $rel, array $excludes): bool
    {
        foreach ($excludes as $ex) {
            if ($ex !== '' && strpos($rel, $ex) !== false) {
                return true;
            }
        }
        return false;
    }

    // --- status / history / control ---------------------------------------

    public static function status(): array
    {
        return self::statusFrom(self::raw());
    }

    private static function statusFrom(array $job): array
    {
        $dbTotalTables = count($job['db']['tables'] ?? []);
        return [
            'id' => $job['id'] ?? null,
            'status' => $job['status'] ?? 'idle',
            'stage' => $job['stage'] ?? null,
            'components' => $job['components'] ?? [],
            'db_progress' => $dbTotalTables > 0 ? ['table' => (int) ($job['db']['tidx'] ?? 0), 'tables' => $dbTotalTables, 'rows' => (int) ($job['db']['rows'] ?? 0)] : null,
            'files_progress' => ['added' => (int) ($job['files']['added'] ?? 0), 'total' => (int) ($job['files']['total'] ?? 0), 'cursor' => (int) ($job['files']['cursor'] ?? 0)],
            'stats' => $job['stats'] ?? [],
            'zip_path' => $job['zip_path'] ?? null,
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
        // Coarse: db stage 0-40%, files 40-95%, finalize 95-100%.
        if (($job['stage'] ?? '') === 'db') {
            $tt = max(1, count($job['db']['tables'] ?? []));
            return (int) round(min(40, ($job['db']['tidx'] ?? 0) / $tt * 40));
        }
        if (($job['stage'] ?? '') === 'files') {
            $tot = max(1, (int) ($job['files']['total'] ?? 1));
            return 40 + (int) round(min(55, ($job['files']['cursor'] ?? 0) / $tot * 55));
        }
        return 96;
    }

    public static function cancel(): void
    {
        $job = self::raw();
        if (!empty($job)) {
            self::cleanupBuild($job);
            if (!empty($job['zip_path']) && is_file($job['zip_path']) && ($job['status'] ?? '') !== 'done') {
                @unlink($job['zip_path']);
            }
        }
        delete_option(self::JOB_OPTION);
        delete_transient(self::LOCK);
        self::unschedule();
    }

    /** @return array<int,array<string,mixed>> completed backups, newest first */
    public static function history(): array
    {
        $h = get_option(self::HISTORY_OPTION, []);
        if (!is_array($h)) {
            return [];
        }
        // Drop entries whose zip no longer exists on disk.
        $h = array_values(array_filter($h, static fn($e): bool => is_array($e) && !empty($e['zip_path']) && is_file($e['zip_path'])));
        return $h;
    }

    public static function deleteBackup(string $id): bool
    {
        $h = self::history();
        $target = null;
        foreach ($h as $e) {
            if (($e['id'] ?? '') === $id) {
                $target = $e;
                break;
            }
        }
        if ($target === null) {
            return false;
        }
        if (!empty($target['zip_path']) && is_file($target['zip_path'])) {
            @unlink($target['zip_path']);
        }
        update_option(self::HISTORY_OPTION, array_values(array_filter($h, static fn($e): bool => ($e['id'] ?? '') !== $id)), false);
        return true;
    }

    /**
     * Import an uploaded backup zip: validate it is genuinely one of our
     * backups (a Rolepod manifest.json inside), store it under the backups dir
     * (HTTP-denied), and register it in history so it can be viewed / restored.
     * Only the central directory + manifest are read here; nothing is extracted.
     *
     * @return array{ok:bool,error?:string,message?:string,id?:string,bytes?:int,manifest?:array}
     */
    public static function importUpload(string $tmpPath, string $origName): array
    {
        if ($tmpPath === '' || !is_file($tmpPath)) {
            return ['ok' => false, 'error' => 'NO_FILE'];
        }
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'ZIP_UNAVAILABLE'];
        }
        $zip = new \ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            return ['ok' => false, 'error' => 'NOT_A_ZIP', 'message' => 'That file is not a valid zip.'];
        }
        $raw = $zip->getFromName('manifest.json', 1_000_000);
        $zip->close();
        if ($raw === false) {
            return ['ok' => false, 'error' => 'NO_MANIFEST', 'message' => 'Not a Rolepod backup (no manifest.json inside).'];
        }
        $mj = json_decode($raw, true);
        if (!is_array($mj) || ($mj['format'] ?? '') !== Manifest::FORMAT) {
            return ['ok' => false, 'error' => 'BAD_FORMAT', 'message' => 'Not a recognised Rolepod backup.'];
        }

        $dir = self::backupsDir();
        if ($dir === '') {
            return ['ok' => false, 'error' => 'DIR_UNWRITABLE'];
        }
        // Readable DISPLAY id from the uploaded filename (keeps the source
        // domain-date naming if present); length-capped. The attacker-supplied
        // name is NEVER used as the on-disk path — that is a random 128-bit
        // token, so a crafted filename can't traverse, overwrite, or produce a
        // guessable public URL.
        $base = preg_replace('/[^A-Za-z0-9.\-]/', '-', (string) pathinfo($origName, PATHINFO_FILENAME)) ?? '';
        $base = trim($base, '-.');
        $base = substr($base, 0, 180);
        if ($base === '') {
            $base = self::siteSlug() . '-import-' . gmdate('Ymd-His');
        }
        $id = $base;
        $disk = bin2hex(random_bytes(16));
        $dest = $dir . '/' . $disk . '.zip';

        $moved = is_uploaded_file($tmpPath) ? @move_uploaded_file($tmpPath, $dest) : @copy($tmpPath, $dest);
        if (!$moved || !is_file($dest)) {
            return ['ok' => false, 'error' => 'STORE_FAILED'];
        }
        @chmod($dest, 0640);
        self::registerImported($id, $dest, $mj);
        return ['ok' => true, 'id' => $id, 'bytes' => (int) filesize($dest), 'manifest' => $mj];
    }

    private static function registerImported(string $id, string $zipPath, array $mj): void
    {
        $h = get_option(self::HISTORY_OPTION, []);
        if (!is_array($h)) {
            $h = [];
        }
        array_unshift($h, [
            'id' => $id,
            'created_at' => time(),
            'zip_path' => $zipPath,
            'zip_bytes' => (int) filesize($zipPath),
            'components' => $mj['components'] ?? [],
            'db_rows' => (int) ($mj['components']['database']['rows'] ?? 0),
            'files_count' => (int) ($mj['components']['files']['count'] ?? 0),
            'imported' => true,
            'source_url' => (string) ($mj['site']['home_url'] ?? ''),
        ]);
        if (count($h) > 50) {
            $h = array_slice($h, 0, 50);
        }
        update_option(self::HISTORY_OPTION, $h, false);
    }

    private static function recordHistory(array $job, array $manifest): void
    {
        $h = get_option(self::HISTORY_OPTION, []);
        if (!is_array($h)) {
            $h = [];
        }
        array_unshift($h, [
            'id' => $job['id'],
            'created_at' => time(),
            'zip_path' => $job['zip_path'],
            'zip_bytes' => $job['stats']['zip_bytes'],
            'components' => $job['components'],
            'db_rows' => $job['db']['rows'] ?? 0,
            'files_count' => $job['stats']['files_count'],
        ]);
        if (count($h) > 50) {
            $h = array_slice($h, 0, 50);
        }
        update_option(self::HISTORY_OPTION, $h, false);
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

    // --- helpers -----------------------------------------------------------

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

    private static function raw(): array
    {
        $j = get_option(self::JOB_OPTION, []);
        return is_array($j) ? $j : [];
    }

    /** Filename-safe slug of the site host, e.g. "demo.example.com". */
    public static function siteSlug(): string
    {
        $host = (string) parse_url((string) home_url(), PHP_URL_HOST);
        $slug = preg_replace('/[^A-Za-z0-9.\-]/', '-', $host) ?? '';
        $slug = trim($slug, '-.');
        return $slug !== '' ? $slug : 'site';
    }

    public static function backupsDir(): string
    {
        $base = trailingslashit((string) (wp_upload_dir()['basedir'] ?? WP_CONTENT_DIR . '/uploads')) . 'rolepod-wp/backups';
        if (!is_dir($base) && !wp_mkdir_p($base)) {
            return '';
        }
        // Defense in depth on top of the unguessable on-disk filenames: deny
        // direct web access on Apache (.htaccess) AND IIS (web.config). The
        // primary protection is the random 128-bit filename — these only help
        // on servers that honour them.
        $ht = $base . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\nDeny from all\nOptions -Indexes\n");
        }
        $wc = $base . '/web.config';
        if (!is_file($wc)) {
            @file_put_contents($wc, "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\"/></authorization></system.webServer></configuration>\n");
        }
        return $base;
    }

    private static function uploadsRel(): string
    {
        $basedir = (string) (wp_upload_dir()['basedir'] ?? '');
        $rel = ltrim(str_replace(WP_CONTENT_DIR, '', $basedir), '/\\');
        return str_replace('\\', '/', $rel) ?: 'uploads';
    }

    private static function componentRel(string $c): string
    {
        switch ($c) {
            case 'uploads': return self::uploadsRel();
            case 'muplugins': return 'mu-plugins';
            default: return $c;
        }
    }
}
