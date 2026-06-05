<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Audit\ChangeRecorder;
use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Config;
use Rolepod\Wp\Security\SessionToken;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/wplab/v1/media-optimize
 *   Body: { session_token, min_bytes?, max_dimension?, quality?, limit?, apply? }
 *
 * Bulk-recompress (and optionally downscale) original media-library images that
 * exceed a byte threshold — the "optimize all images over 200KB" workflow.
 *
 * Safety:
 *   - dry-run by default (`apply=false`) — reports candidates + current sizes,
 *     writes nothing.
 *   - every original is copied to uploads/rolepod-wp/media-backups/ BEFORE it
 *     is overwritten, and each optimize is recorded in the Change Ledger
 *     (reversible) so a regretted pass can be rolled back.
 *   - if a re-encode does not actually shrink the file, the original is
 *     restored (never trade quality for zero gain).
 *   - refuses when guardian safe-mode is on (bulk file overwrite is "risky").
 *
 * Only the full-size original is touched; registered thumbnail files are left
 * as-is (already small). Stored attachment metadata (filesize, and width/height
 * when downscaled) is updated so wp-admin stays consistent.
 */
final class MediaOptimize
{
    private const DEFAULT_MIN_BYTES = 200_000;
    private const DEFAULT_QUALITY = 82;
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;
    private const SCAN_CAP = 1000;
    private const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/media-optimize',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'min_bytes' => ['required' => false, 'type' => 'integer', 'default' => self::DEFAULT_MIN_BYTES],
                    'max_dimension' => ['required' => false, 'type' => 'integer', 'default' => 0],
                    'quality' => ['required' => false, 'type' => 'integer', 'default' => self::DEFAULT_QUALITY],
                    'limit' => ['required' => false, 'type' => 'integer', 'default' => self::DEFAULT_LIMIT],
                    'apply' => ['required' => false, 'type' => 'boolean', 'default' => false],
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

    /**
     * Filter a list of {id, bytes} candidates to those at/above the threshold,
     * largest first, capped at $limit. Pure — unit-testable without WP.
     *
     * @param list<array{id:int,bytes:int}> $items
     * @return list<array{id:int,bytes:int}>
     */
    public static function selectCandidates(array $items, int $minBytes, int $limit): array
    {
        $matching = array_values(array_filter(
            $items,
            static fn(array $i): bool => ($i['bytes'] ?? 0) >= $minBytes
        ));
        usort($matching, static fn(array $a, array $b): int => ($b['bytes'] ?? 0) <=> ($a['bytes'] ?? 0));
        if ($limit > 0 && count($matching) > $limit) {
            $matching = array_slice($matching, 0, $limit);
        }
        return $matching;
    }

    public static function handle(WP_REST_Request $req): WP_REST_Response
    {
        $userId = get_current_user_id();
        $token = (string) $req->get_param('session_token');
        if (!SessionToken::verify($token, $userId)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_OR_EXPIRED_TOKEN'], 401);
        }

        $apply = (bool) $req->get_param('apply');
        if ($apply && (bool) get_option('rolepod_wp_safe_mode', false)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'SAFE_MODE',
                'error_message' => 'Guardian safe-mode is on — bulk media overwrite refused. Clear safe-mode first.',
            ], 423);
        }

        $minBytes = max(1, (int) $req->get_param('min_bytes'));
        $maxDim = max(0, (int) $req->get_param('max_dimension'));
        $quality = max(1, min(100, (int) $req->get_param('quality')));
        $limit = max(1, min(self::MAX_LIMIT, (int) $req->get_param('limit')));

        // Gather attachment originals over the threshold.
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_mime_type' => self::MIME_TYPES,
            'post_status' => 'inherit',
            'posts_per_page' => self::SCAN_CAP,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);

        $items = [];
        foreach ($ids as $id) {
            $path = get_attached_file((int) $id);
            if (!is_string($path) || $path === '' || !is_file($path)) {
                continue;
            }
            $bytes = (int) filesize($path);
            $items[] = ['id' => (int) $id, 'bytes' => $bytes, 'path' => $path];
        }

        // selectCandidates only needs id+bytes; keep a path index alongside.
        $pathById = [];
        foreach ($items as $it) {
            $pathById[$it['id']] = $it['path'];
        }
        $candidates = self::selectCandidates(
            array_map(static fn(array $i): array => ['id' => $i['id'], 'bytes' => $i['bytes']], $items),
            $minBytes,
            $limit
        );

        if (!$apply) {
            $totalBytes = array_sum(array_map(static fn(array $c): int => $c['bytes'], $candidates));
            return new WP_REST_Response([
                'ok' => true,
                'mode' => 'dry_run',
                'scanned' => count($items),
                'candidate_count' => count($candidates),
                'candidate_bytes' => $totalBytes,
                'candidates' => array_map(static fn(array $c): array => [
                    'id' => $c['id'],
                    'bytes' => $c['bytes'],
                    'url' => (string) wp_get_attachment_url($c['id']),
                ], $candidates),
                'note' => 'dry run — pass apply=true to optimize. Each original is backed up + ledgered before overwrite.',
            ], 200);
        }

        $session = isset($_SERVER['HTTP_X_ROLEPOD_SESSION']) ? (string) $_SERVER['HTTP_X_ROLEPOD_SESSION'] : null;
        $backupDir = self::backupDir();
        $results = [];
        $totalBefore = 0;
        $totalAfter = 0;

        foreach ($candidates as $c) {
            $id = $c['id'];
            $path = $pathById[$id] ?? null;
            if ($path === null || !is_file($path)) {
                $results[] = ['id' => $id, 'action' => 'skipped', 'reason' => 'file_missing'];
                continue;
            }
            $row = self::optimizeOne($id, $path, $maxDim, $quality, $backupDir, $session);
            $results[] = $row;
            if (($row['action'] ?? '') === 'optimized') {
                $totalBefore += (int) $row['before'];
                $totalAfter += (int) $row['after'];
            }
        }

        $optimized = count(array_filter($results, static fn(array $r): bool => ($r['action'] ?? '') === 'optimized'));

        $auditId = Log::append([
            'endpoint' => 'media-optimize',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => 'success',
            'error' => null,
        ]);

        return new WP_REST_Response([
            'ok' => true,
            'mode' => 'apply',
            'optimized' => $optimized,
            'processed' => count($results),
            'total_before' => $totalBefore,
            'total_after' => $totalAfter,
            'total_saved' => $totalBefore - $totalAfter,
            'backup_dir' => $backupDir,
            'results' => $results,
            'audit_id' => $auditId,
        ], 200);
    }

    /**
     * Optimize a single attachment original in place, with backup + ledger.
     *
     * @return array<string, mixed>
     */
    private static function optimizeOne(int $id, string $path, int $maxDim, int $quality, string $backupDir, ?string $session): array
    {
        $before = (int) filesize($path);

        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            return ['id' => $id, 'action' => 'skipped', 'reason' => 'no_editor', 'detail' => $editor->get_error_message()];
        }

        $resized = false;
        if ($maxDim > 0) {
            $size = $editor->get_size();
            $w = (int) ($size['width'] ?? 0);
            $h = (int) ($size['height'] ?? 0);
            if (max($w, $h) > $maxDim) {
                $editor->resize($maxDim, $maxDim, false);
                $resized = true;
            }
        }
        $editor->set_quality($quality);

        // Back up the original before any overwrite.
        $backup = trailingslashit($backupDir) . $id . '-' . basename($path);
        if ($backupDir === '' || !@copy($path, $backup)) {
            return ['id' => $id, 'action' => 'skipped', 'reason' => 'backup_failed'];
        }

        $saved = $editor->save($path);
        if (is_wp_error($saved)) {
            @copy($backup, $path); // restore
            @unlink($backup);
            return ['id' => $id, 'action' => 'skipped', 'reason' => 'save_failed', 'detail' => $saved->get_error_message()];
        }

        clearstatcache(true, $path);
        $after = (int) filesize($path);

        // No real gain → restore original, drop the backup. Never degrade for nothing.
        if ($after >= $before) {
            @copy($backup, $path);
            @unlink($backup);
            clearstatcache(true, $path);
            return ['id' => $id, 'action' => 'skipped', 'reason' => 'no_gain', 'before' => $before, 'after' => $after];
        }

        // Keep stored metadata consistent.
        $meta = wp_get_attachment_metadata($id);
        if (is_array($meta)) {
            $meta['filesize'] = $after;
            if ($resized && is_array($saved)) {
                $meta['width'] = (int) ($saved['width'] ?? ($meta['width'] ?? 0));
                $meta['height'] = (int) ($saved['height'] ?? ($meta['height'] ?? 0));
            }
            wp_update_attachment_metadata($id, $meta);
        }

        try {
            ChangeRecorder::record([
                'category' => 'media',
                'subcategory' => 'optimize',
                'target_descriptor' => "attachment #{$id} " . basename($path),
                'before_state' => ['bytes' => $before],
                'after_state' => ['bytes' => $after, 'resized' => $resized, 'backup' => $backup],
                'reversible' => true,
                'source_tool' => 'media_optimize',
                'source_session' => $session,
                'notes' => 'Original backed up at ' . $backup . ' — restore to revert.',
            ]);
        } catch (\Throwable $t) {
            // Ledger table may be absent on a bare install — optimize still stands.
        }

        return [
            'id' => $id,
            'action' => 'optimized',
            'before' => $before,
            'after' => $after,
            'saved' => $before - $after,
            'resized' => $resized,
            'backup' => $backup,
        ];
    }

    /**
     * Ensure + return the media backup dir under the rolepod-wp data namespace
     * (already HTTP-denied by the bootstrap's deny-all .htaccess). Returns ''
     * if the dir can't be created.
     */
    private static function backupDir(): string
    {
        $base = trailingslashit((string) (wp_upload_dir()['basedir'] ?? WP_CONTENT_DIR . '/uploads'))
            . 'rolepod-wp/media-backups';
        if (!is_dir($base) && !wp_mkdir_p($base)) {
            return '';
        }
        return $base;
    }
}
