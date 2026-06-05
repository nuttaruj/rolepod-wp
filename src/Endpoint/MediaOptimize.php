<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Config;
use Rolepod\Wp\Media\Optimizer;
use Rolepod\Wp\Media\Queue;
use Rolepod\Wp\Security\SessionToken;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/wplab/v1/media-optimize
 *   Body: { session_token, min_bytes?, max_dimension?, quality?, limit?, apply?, mode? }
 *
 * Bulk-recompress (and optionally downscale) media-library image originals over
 * a byte threshold — the "optimize all images over 200KB" workflow.
 *
 * Modes:
 *   - mode=immediate (default): process synchronously, up to `limit` images.
 *     apply=false → dry run (report candidates, write nothing); apply=true →
 *     optimize now (good for a handful of images).
 *   - mode=enqueue: hand ALL matching candidates to the throttled background
 *     Queue (cron, small batches, sleeps between images) so a large library
 *     optimizes gradually without spiking CPU. Returns queue status.
 *
 * Safety (both modes): each original is backed up to
 * uploads/rolepod-wp/media-backups/ before overwrite, each optimize is a
 * reversible Change Ledger row, a non-shrinking re-encode restores the
 * original, and apply/enqueue are refused while guardian safe-mode is on.
 */
final class MediaOptimize
{
    private const DEFAULT_MIN_BYTES = 200_000;
    private const DEFAULT_QUALITY = 82;
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;
    private const SCAN_CAP = 5000;

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
                    'mode' => ['required' => false, 'type' => 'string', 'enum' => ['immediate', 'enqueue'], 'default' => 'immediate'],
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

        $apply = (bool) $req->get_param('apply');
        $mode = (string) $req->get_param('mode');
        $writes = $apply || $mode === 'enqueue';
        if ($writes && (bool) get_option('rolepod_wp_safe_mode', false)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'SAFE_MODE',
                'error_message' => 'Guardian safe-mode is on — media writes refused. Clear safe-mode first.',
            ], 423);
        }

        $minBytes = max(1, (int) $req->get_param('min_bytes'));
        $maxDim = max(0, (int) $req->get_param('max_dimension'));
        $quality = max(1, min(100, (int) $req->get_param('quality')));
        $limit = max(1, min(self::MAX_LIMIT, (int) $req->get_param('limit')));

        $items = Optimizer::scan(self::SCAN_CAP);

        // Background mode: queue ALL matching candidates, let cron trickle them.
        if ($mode === 'enqueue') {
            $candidates = Optimizer::selectCandidates($items, $minBytes, 0);
            $ids = array_map(static fn(array $c): int => $c['id'], $candidates);
            $status = Queue::enqueue($ids, ['max_dimension' => $maxDim, 'quality' => $quality]);
            Log::append([
                'endpoint' => 'media-optimize',
                'user' => (string) wp_get_current_user()->user_login,
                'site_url' => (string) get_option('siteurl'),
                'result' => 'success',
                'error' => null,
            ]);
            return new WP_REST_Response([
                'ok' => true,
                'mode' => 'enqueue',
                'queued' => count($ids),
                'queue' => $status,
                'note' => 'Queued for throttled background optimization (cron, small batches). Watch progress on the Media admin page.',
            ], 200);
        }

        $candidates = Optimizer::selectCandidates($items, $minBytes, $limit);

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
                'note' => 'dry run — apply=true to optimize now, or mode=enqueue for throttled background processing.',
            ], 200);
        }

        // Immediate apply — synchronous, capped at `limit`.
        $session = isset($_SERVER['HTTP_X_ROLEPOD_SESSION']) ? (string) $_SERVER['HTTP_X_ROLEPOD_SESSION'] : null;
        $results = [];
        $totalBefore = 0;
        $totalAfter = 0;
        foreach ($candidates as $c) {
            $row = Optimizer::optimizeOne($c['id'], $maxDim, $quality, $session);
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
            'backup_dir' => Optimizer::backupDir(),
            'results' => $results,
            'audit_id' => $auditId,
        ], 200);
    }
}
