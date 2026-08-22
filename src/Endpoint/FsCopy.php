<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Config;
use Rolepod\Wp\Security\SessionToken;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/wplab/v1/fs-copy (v2.11)
 *
 * Copy `from` → `to`. Both paths must be scoped. Auto-creates parent dirs
 * of destination. Production-guarded.
 *
 * Body: { session_token, from, to, overwrite?: bool }
 * Response 200: { ok: true, from, to, bytes }
 */
final class FsCopy
{
    private const SCOPED_DIRS = ['wp-content/themes/', 'wp-content/plugins/', 'wp-content/uploads/', 'wp-content/mu-plugins/', 'wp-content/private/'];

    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/fs-copy',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'from' => ['required' => true, 'type' => 'string'],
                    'to' => ['required' => true, 'type' => 'string'],
                    'overwrite' => ['required' => false, 'type' => 'boolean', 'default' => false],
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
                'endpoint' => 'fs-copy',
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

        $from = ltrim((string) $req->get_param('from'), '/');
        $to = ltrim((string) $req->get_param('to'), '/');
        $overwrite = (bool) $req->get_param('overwrite');

        if ($from === '' || $to === '' || strpos($from, '..') !== false || strpos($to, '..') !== false) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'INVALID_PATH',
                'error_message' => 'from and to required; no parent traversal allowed',
            ], 400);
        }
        if (!self::pathInScope($from) || !self::pathInScope($to)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'FS_SCOPE_VIOLATION',
                'error_message' => 'both from and to must live under wp-content/(themes|plugins|uploads|mu-plugins)/',
            ], 400);
        }

        $fromAbs = ABSPATH . $from;
        $toAbs = ABSPATH . $to;

        if (!is_file($fromAbs)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'SOURCE_NOT_FOUND',
                'error_message' => "no file at {$from}",
            ], 404);
        }
        if (is_file($toAbs) && !$overwrite) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'DEST_EXISTS',
                'error_message' => "destination exists; pass overwrite=true to replace",
            ], 409);
        }
        $toDir = dirname($toAbs);
        if (!is_dir($toDir)) {
            @mkdir($toDir, 0755, true);
        }

        if (!@copy($fromAbs, $toAbs)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'COPY_FAILED',
                'error_message' => "copy({$from}, {$to}) returned false",
            ], 500);
        }
        return new WP_REST_Response([
            'ok' => true,
            'from' => $from,
            'to' => $to,
            'bytes' => (int) filesize($toAbs),
        ], 200);
    }

    private static function pathInScope(string $relPath): bool
    {
        foreach (self::SCOPED_DIRS as $dir) {
            if (strpos($relPath, $dir) === 0) {
                return true;
            }
        }
        return false;
    }
}
