<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Config;
use Rolepod\Wp\Security\SessionToken;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/wplab/v1/dir-ensure (v2.11)
 *
 * Idempotent `mkdir -p` for scoped wp-content paths. Returns success when the
 * directory exists at end of call regardless of whether it pre-existed.
 *
 * Body: { session_token, path }
 * Response 200: { ok: true, path, absolute_path, created: bool }
 */
final class DirEnsure
{
    private const SCOPED_DIRS = ['wp-content/themes/', 'wp-content/plugins/', 'wp-content/uploads/', 'wp-content/mu-plugins/', 'wp-content/private/'];

    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/dir-ensure',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'path' => ['required' => true, 'type' => 'string'],
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

        $relPath = ltrim((string) $req->get_param('path'), '/');
        if ($relPath === '' || strpos($relPath, '..') !== false) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'INVALID_PATH',
                'error_message' => 'path required and must not traverse parents',
            ], 400);
        }
        if (!self::pathInScope($relPath)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'FS_SCOPE_VIOLATION',
                'error_message' => 'directory must live under wp-content/(themes|plugins|uploads|mu-plugins)/',
            ], 400);
        }

        $abs = ABSPATH . $relPath;
        if (is_dir($abs)) {
            return new WP_REST_Response([
                'ok' => true,
                'path' => $relPath,
                'absolute_path' => $abs,
                'created' => false,
            ], 200);
        }
        if (file_exists($abs)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'PATH_IS_FILE',
                'error_message' => 'path exists but is a file, not a directory',
            ], 400);
        }
        if (!@mkdir($abs, 0755, true)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'MKDIR_FAILED',
                'error_message' => "could not create directory {$abs}",
            ], 500);
        }
        return new WP_REST_Response([
            'ok' => true,
            'path' => $relPath,
            'absolute_path' => $abs,
            'created' => true,
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
