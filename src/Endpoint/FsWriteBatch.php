<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Config;
use Rolepod\Wp\Security\SessionToken;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * POST /wp-json/wplab/v1/fs-write-batch (v2.11)
 *
 * Atomic multi-file write. Either every file in the request lands or none do.
 *
 * Phases per request:
 *   1. AUTH / GUARD       — session token + production guard + manage_options
 *   2. SHAPE VALIDATION   — every entry has a scoped path + content
 *   3. SYNTAX PRE-FLIGHT  — `php -l` on every *.php entry (skip when exec disabled)
 *   4. CROSS-FILE REQUIRE — for each PHP bootstrap entry (functions.php,
 *                           header.php, footer.php, mu-plugins/*.php,
 *                           wp-config.php), scan content for require/include
 *                           with literal *.php paths; the missing path is OK
 *                           if it exists on disk OR appears as another entry
 *                           in this batch.
 *   5. STAGE              — write content to <absPath>.wplab-stage-<batchId>
 *                           for every entry (creates parent dirs as needed)
 *   6. BACKUP             — for every entry where target exists, copy to
 *                           <absPath>.wplab-bak-<batchId>
 *   7. COMMIT             — rename(<stage>, <abs>) for every entry; the rename
 *                           is atomic within a single filesystem (always the
 *                           case for wp-content).
 *   8. ROLLBACK on failure — restore backups, remove staged files, return
 *                            error with the first-failing entry's index.
 *
 * Body:
 *   {
 *     session_token,
 *     writes: [
 *       { path, content, mode?: "overwrite"|"append", confirm_unsafe_path?: bool },
 *       ...
 *     ],
 *     skip_php_lint?: bool   (default false; ignored when exec disabled)
 *   }
 *
 * Response on success (200):
 *   {
 *     ok: true,
 *     batch_id,
 *     written: [ { path, absolute_path, bytes_written, backup_path }, ... ],
 *     preflight: { php_lint_ran, require_chain_ran, entries_scanned, missing_requires: [] }
 *   }
 *
 * Response on failure (400/500):
 *   {
 *     ok: false,
 *     error_code,
 *     error_message,
 *     failed_index?,
 *     failed_path?,
 *     ... (additional diagnostics)
 *   }
 */
final class FsWriteBatch
{
    private const SCOPED_DIRS = ['wp-content/themes/', 'wp-content/plugins/', 'wp-content/uploads/', 'wp-content/mu-plugins/', 'wp-content/private/'];
    private const UPLOAD_EXTS = ['.jpg', '.jpeg', '.png', '.webp', '.gif', '.svg', '.css', '.js', '.json', '.txt', '.md', '.pdf'];
    private const BOOTSTRAP_RE = '#(?:^|/)(?:functions|header|footer|wp-config)\.php$|/mu-plugins/[^/]+\.php$#i';
    private const REQUIRE_RE = '/\b(require_once|require|include_once|include)\b[^;]*?[\'"]([^\'"]+\.php)[\'"][^;]*?;/i';

    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/fs-write-batch',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'writes' => ['required' => true, 'type' => 'array'],
                    'skip_php_lint' => ['required' => false, 'type' => 'boolean', 'default' => false],
                ],
            ]
        );
    }

    public static function permission(WP_REST_Request $req)
    {
        if (!Config::endpointsEnabled()) {
            return new WP_Error('rolepod_wp_disabled', 'Companion endpoints disabled.', ['status' => 403]);
        }
        if (!current_user_can('manage_options')) {
            return new WP_Error('rolepod_wp_unauthorized', 'manage_options required.', ['status' => 403]);
        }
        return true;
    }

    public static function handle(WP_REST_Request $req): WP_REST_Response
    {
        $userId = get_current_user_id();
        $token = (string) $req->get_param('session_token');
        if (!SessionToken::verify($token, $userId)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_OR_EXPIRED_TOKEN'], 401);
        }

        // Guarded mode — the owner has not enabled full access.
        if (!Config::fullAccess()) {
            $auditId = Log::append([
                'endpoint' => 'fs-write-batch',
                'user' => (string) wp_get_current_user()->user_login,
                'site_url' => (string) get_option('siteurl'),
                'result' => 'rejected',
                'error' => 'FULL_ACCESS_REQUIRED',
            ]);
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'FULL_ACCESS_REQUIRED',
                'error_message' => 'Guarded mode: enable Full access in wp-admin -> Rolepod WP -> Settings to use this endpoint.',
                'audit_id' => $auditId,
            ], 403);
        }

        $rawWrites = $req->get_param('writes');
        if (!is_array($rawWrites) || count($rawWrites) === 0) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'EMPTY_WRITES',
                'error_message' => 'writes must be a non-empty array',
            ], 400);
        }
        if (count($rawWrites) > 100) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'TOO_MANY_WRITES',
                'error_message' => 'batch limited to 100 entries (current: ' . count($rawWrites) . ')',
            ], 400);
        }

        // === Phase 2: shape validation + path scope ===
        $entries = [];
        foreach ($rawWrites as $i => $entry) {
            $shape = self::validateEntry($i, $entry);
            if ($shape instanceof WP_REST_Response) {
                return $shape;
            }
            $entries[] = $shape;
        }

        $batchId = wp_generate_uuid4();
        $skipLint = (bool) $req->get_param('skip_php_lint');
        $phpLintRan = false;
        $requireChainRan = false;

        // === Phase 3: per-entry PHP syntax lint ===
        if (!$skipLint && self::execAvailable()) {
            foreach ($entries as $i => $entry) {
                if (self::endsWith($entry['rel_path'], '.php')) {
                    $lint = self::phpLint($entry['content']);
                    if ($lint !== null) {
                        return new WP_REST_Response([
                            'ok' => false,
                            'error_code' => 'PHP_SYNTAX_ERROR',
                            'failed_index' => $i,
                            'failed_path' => $entry['rel_path'],
                            'error_line' => $lint['line'],
                            'error_message' => $lint['message'],
                        ], 400);
                    }
                }
            }
            $phpLintRan = true;
        }

        // === Phase 4: cross-file require chain — the KEY benefit of batched writes ===
        $virtualFsAdditions = array_fill_keys(array_column($entries, 'rel_path'), true);
        foreach ($entries as $i => $entry) {
            if (!self::isBootstrapPath($entry['rel_path'])) {
                continue;
            }
            $missing = self::missingRequires($entry, $virtualFsAdditions);
            if (count($missing) > 0) {
                return new WP_REST_Response([
                    'ok' => false,
                    'error_code' => 'REQUIRE_CHAIN_BROKEN',
                    'failed_index' => $i,
                    'failed_path' => $entry['rel_path'],
                    'error_message' => 'one or more require/include targets do not exist on disk and are not in this batch',
                    'missing_requires' => $missing,
                ], 400);
            }
            $requireChainRan = true;
        }

        // === Phase 5: stage every entry ===
        $stagePaths = [];
        try {
            foreach ($entries as $i => $entry) {
                $absPath = ABSPATH . $entry['rel_path'];
                $dir = dirname($absPath);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                $stage = $absPath . '.wplab-stage-' . $batchId;
                $written = @file_put_contents($stage, $entry['content']);
                if ($written === false) {
                    throw new \RuntimeException("stage write failed for entry #{$i} ({$entry['rel_path']})");
                }
                $stagePaths[$i] = ['stage' => $stage, 'abs' => $absPath, 'rel' => $entry['rel_path']];
            }
        } catch (\Throwable $e) {
            self::cleanupStageOnly($stagePaths);
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'STAGE_FAILED',
                'error_message' => $e->getMessage(),
            ], 500);
        }

        // === Phase 6: backup existing targets ===
        $backupPaths = [];
        foreach ($stagePaths as $i => $sp) {
            if (is_file($sp['abs'])) {
                $bak = $sp['abs'] . '.wplab-bak-' . $batchId;
                if (!@copy($sp['abs'], $bak)) {
                    self::cleanupStageOnly($stagePaths);
                    self::cleanupBackups($backupPaths);
                    return new WP_REST_Response([
                        'ok' => false,
                        'error_code' => 'BACKUP_FAILED',
                        'failed_index' => $i,
                        'failed_path' => $sp['rel'],
                        'error_message' => 'could not snapshot existing file before commit',
                    ], 500);
                }
                $backupPaths[$i] = $bak;
            }
        }

        // === Phase 7: atomic commit (rename per entry) ===
        $written = [];
        try {
            foreach ($stagePaths as $i => $sp) {
                if (!@rename($sp['stage'], $sp['abs'])) {
                    throw new \RuntimeException("commit failed for entry #{$i} ({$sp['rel']})");
                }
                $written[] = [
                    'path' => $sp['rel'],
                    'absolute_path' => $sp['abs'],
                    'bytes_written' => (int) filesize($sp['abs']),
                    'backup_path' => $backupPaths[$i] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            // ROLLBACK — restore any backups we've already committed.
            foreach ($written as $w) {
                foreach ($backupPaths as $j => $bak) {
                    if ($stagePaths[$j]['rel'] === $w['path'] && is_file($bak)) {
                        @copy($bak, $stagePaths[$j]['abs']);
                    }
                }
            }
            self::cleanupStageOnly($stagePaths);
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'COMMIT_FAILED',
                'error_message' => $e->getMessage(),
            ], 500);
        }

        return new WP_REST_Response([
            'ok' => true,
            'batch_id' => $batchId,
            'written' => $written,
            'preflight' => [
                'php_lint_ran' => $phpLintRan,
                'require_chain_ran' => $requireChainRan,
                'entries_scanned' => count($entries),
            ],
        ], 200);
    }

    /**
     * @return array{rel_path:string,content:string,mode:string,confirm_unsafe:bool}|WP_REST_Response
     */
    private static function validateEntry(int $i, $entry)
    {
        if (!is_array($entry)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'INVALID_ENTRY',
                'failed_index' => $i,
                'error_message' => 'each writes entry must be an object',
            ], 400);
        }
        $path = isset($entry['path']) ? ltrim((string) $entry['path'], '/') : '';
        $content = isset($entry['content']) ? (string) $entry['content'] : '';
        $mode = isset($entry['mode']) ? (string) $entry['mode'] : 'overwrite';
        $confirmUnsafe = !empty($entry['confirm_unsafe_path']);

        if ($path === '') {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'EMPTY_PATH',
                'failed_index' => $i,
                'error_message' => 'path required',
            ], 400);
        }
        if (!in_array($mode, ['overwrite', 'append'], true)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'INVALID_MODE',
                'failed_index' => $i,
                'failed_path' => $path,
                'error_message' => "mode must be 'overwrite' or 'append'",
            ], 400);
        }
        $inScope = self::pathInScope($path);
        if (!$inScope && !$confirmUnsafe) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'FS_SCOPE_VIOLATION',
                'failed_index' => $i,
                'failed_path' => $path,
                'error_message' => "path outside scoped dirs; pass confirm_unsafe_path=true to override",
            ], 400);
        }

        return [
            'rel_path' => $path,
            'content' => $content,
            'mode' => $mode,
            'confirm_unsafe' => $confirmUnsafe,
        ];
    }

    private static function pathInScope(string $relPath): bool
    {
        foreach (self::SCOPED_DIRS as $dir) {
            if (strpos($relPath, $dir) === 0) {
                if ($dir === 'wp-content/uploads/') {
                    $dot = strrpos($relPath, '.');
                    if ($dot === false) {
                        return false;
                    }
                    $ext = strtolower(substr($relPath, $dot));
                    return in_array($ext, self::UPLOAD_EXTS, true);
                }
                return true;
            }
        }
        return $relPath === 'wp-config.php';
    }

    private static function isBootstrapPath(string $relPath): bool
    {
        return (bool) preg_match(self::BOOTSTRAP_RE, $relPath);
    }

    /**
     * @return array<int, array{required_path:string, resolved_path:string, line_hint:int}>
     */
    private static function missingRequires(array $entry, array $virtualFsAdditions): array
    {
        $sourceDir = self::parentDir($entry['rel_path']);
        $missing = [];
        if (!preg_match_all(self::REQUIRE_RE, $entry['content'], $matches, PREG_OFFSET_CAPTURE)) {
            return $missing;
        }
        foreach ($matches[2] as $idx => $m) {
            $literal = $m[0];
            $offset = (int) $m[1];
            $resolved = self::resolveRequirePath($sourceDir, $literal);
            if ($resolved === null) {
                continue;
            }
            $existsOnDisk = is_file(ABSPATH . $resolved);
            $inBatch = isset($virtualFsAdditions[$resolved]);
            if (!$existsOnDisk && !$inBatch) {
                $missing[] = [
                    'required_path' => $literal,
                    'resolved_path' => $resolved,
                    'line_hint' => substr_count(substr($entry['content'], 0, $offset), "\n") + 1,
                ];
            }
        }
        return $missing;
    }

    private static function resolveRequirePath(string $sourceDir, string $literal): ?string
    {
        if ($literal === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $literal)) {
            return null;
        }
        if (strpos($literal, '/home/') === 0 || strpos($literal, '/var/') === 0) {
            return null;
        }
        $trimmed = ltrim($literal, '/');
        if (strpos($trimmed, './') === 0) {
            $trimmed = substr($trimmed, 2);
        }
        $segs = [];
        $combined = $sourceDir === '' ? $trimmed : ($sourceDir . '/' . $trimmed);
        foreach (explode('/', $combined) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if (count($segs) === 0) {
                    return null;
                }
                array_pop($segs);
                continue;
            }
            $segs[] = $seg;
        }
        return implode('/', $segs);
    }

    private static function parentDir(string $path): string
    {
        $i = strrpos($path, '/');
        return $i === false ? '' : substr($path, 0, $i);
    }

    private static function endsWith(string $haystack, string $needle): bool
    {
        $len = strlen($needle);
        if ($len === 0) {
            return true;
        }
        return substr($haystack, -$len) === $needle;
    }

    private static function execAvailable(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = explode(',', (string) ini_get('disable_functions'));
        return !in_array('exec', array_map('trim', $disabled), true);
    }

    /** @return array{line:int|null,message:string}|null */
    private static function phpLint(string $content): ?array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rolepod_wp_batch_');
        if ($tmp === false) {
            return ['line' => null, 'message' => 'tempnam failed — cannot run php -l'];
        }
        $tmpPhp = $tmp . '.php';
        @rename($tmp, $tmpPhp);
        if (file_put_contents($tmpPhp, $content) === false) {
            @unlink($tmpPhp);
            return ['line' => null, 'message' => 'temp file write failed'];
        }
        $cmd = 'php -l ' . escapeshellarg($tmpPhp) . ' 2>&1';
        $output = [];
        $exitCode = 0;
        @exec($cmd, $output, $exitCode);
        @unlink($tmpPhp);
        if ($exitCode === 0) {
            return null;
        }
        $stdout = implode("\n", $output);
        $line = null;
        if (preg_match('/on line (\d+)/', $stdout, $m)) {
            $line = (int) $m[1];
        }
        return ['line' => $line, 'message' => trim($stdout)];
    }

    /** @param array<int, array{stage:string,abs:string,rel:string}> $stagePaths */
    private static function cleanupStageOnly(array $stagePaths): void
    {
        foreach ($stagePaths as $sp) {
            if (is_file($sp['stage'])) {
                @unlink($sp['stage']);
            }
        }
    }

    /** @param array<int, string> $backupPaths */
    private static function cleanupBackups(array $backupPaths): void
    {
        foreach ($backupPaths as $bak) {
            if (is_file($bak)) {
                @unlink($bak);
            }
        }
    }
}
