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
 * POST /wp-json/wplab/v1/fs-write (v0.2)
 *
 * Scoped file write. Mirrors Node-side FsScope rules:
 *   - wp-content/themes/<slug>/
 *   - wp-content/plugins/<slug>/
 *   - wp-content/uploads/ (with extension allow-list)
 *   - wp-config.php (require explicit confirm)
 */
final class FsWrite
{
    private const SCOPED_DIRS = ['wp-content/themes/', 'wp-content/plugins/', 'wp-content/uploads/'];
    private const UPLOAD_EXTS = ['.jpg', '.jpeg', '.png', '.webp', '.gif', '.svg', '.css', '.js', '.json', '.txt', '.md', '.pdf'];

    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/fs-write',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'path' => ['required' => true, 'type' => 'string'],
                    'content' => ['required' => true, 'type' => 'string'],
                    'mode' => ['required' => false, 'type' => 'string', 'default' => 'overwrite'],
                    'backup' => ['required' => false, 'type' => 'boolean', 'default' => true],
                    'confirm_unsafe_path' => ['required' => false, 'type' => 'boolean', 'default' => false],
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
                'endpoint' => 'fs-write',
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

        $relPath = ltrim((string) $req->get_param('path'), '/');
        $confirmUnsafe = (bool) $req->get_param('confirm_unsafe_path');
        $inScope = self::pathInScope($relPath);
        if (!$inScope && !$confirmUnsafe) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'FS_SCOPE_VIOLATION',
                'error_message' => 'Path outside scoped dirs; pass confirm_unsafe_path=true to override',
            ], 400);
        }

        $absPath = ABSPATH . $relPath;
        $dir = dirname($absPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $backupPath = null;
        if ((bool) $req->get_param('backup') && is_file($absPath)) {
            $stamp = gmdate('Ymd-His');
            $backupPath = $absPath . '.wplab-bak-' . $stamp;
            @copy($absPath, $backupPath);
        }

        $content = (string) $req->get_param('content');
        $mode = (string) $req->get_param('mode');
        $written = $mode === 'append'
            ? @file_put_contents($absPath, $content, FILE_APPEND)
            : @file_put_contents($absPath, $content);

        if ($written === false) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'WRITE_FAILED',
            ], 500);
        }

        // Use the byte count file_put_contents actually wrote — NOT
        // filesize(), whose stat result is cached from the earlier backup
        // copy() and (on append) reports the pre-write size. For append this
        // is the bytes appended; for overwrite it equals strlen($content).
        return new WP_REST_Response([
            'ok' => true,
            'path' => $relPath,
            'absolute_path' => $absPath,
            'bytes_written' => $written,
            'backup_path' => $backupPath,
        ], 200);
    }

    private static function pathInScope(string $relPath): bool
    {
        foreach (self::SCOPED_DIRS as $dir) {
            if (strpos($relPath, $dir) === 0) {
                if ($dir === 'wp-content/uploads/') {
                    $ext = strtolower(substr($relPath, (int) strrpos($relPath, '.')));
                    return in_array($ext, self::UPLOAD_EXTS, true);
                }
                return true;
            }
        }
        return false;
    }
}
