<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Audit\ChangeRecorder;
use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Config;
use Rolepod\Wp\Media\Importer;
use Rolepod\Wp\Security\SessionToken;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/wplab/v1/media-import
 *   Body: { session_token, source, data?|url?|path?, filename?, alt?, title?,
 *           caption?, attach_to_post? }
 *
 * Import one file into the media library from a base64 payload, an https URL,
 * or a server-local path under wp-content (see Media\Importer for the source
 * bounds). Returns the new attachment id + url and records a reversible
 * `media/import` ledger row so the upload can be undone (Toggler deletes the
 * attachment on revert).
 *
 * Refused while guardian safe-mode is on (423) — SafeModeGuard blocks the
 * whole namespace by default, and this explicit check keeps the contract clear
 * at the endpoint.
 */
final class MediaImport
{
    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/media-import',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'source' => ['required' => true, 'type' => 'string', 'enum' => ['base64', 'url', 'local_path']],
                    'data' => ['required' => false, 'type' => 'string'],
                    'url' => ['required' => false, 'type' => 'string'],
                    'path' => ['required' => false, 'type' => 'string'],
                    'filename' => ['required' => false, 'type' => 'string'],
                    'alt' => ['required' => false, 'type' => 'string'],
                    'title' => ['required' => false, 'type' => 'string'],
                    'caption' => ['required' => false, 'type' => 'string'],
                    'attach_to_post' => ['required' => false, 'type' => 'integer', 'default' => 0],
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

        if ((bool) get_option('rolepod_wp_safe_mode', false)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'SAFE_MODE',
                'error_message' => 'Guardian safe-mode is on — media writes refused. Clear safe-mode first.',
            ], 423);
        }

        $result = Importer::import([
            'source' => (string) $req->get_param('source'),
            'data' => (string) $req->get_param('data'),
            'url' => (string) $req->get_param('url'),
            'path' => (string) $req->get_param('path'),
            'filename' => (string) $req->get_param('filename'),
            'alt' => (string) $req->get_param('alt'),
            'title' => (string) $req->get_param('title'),
            'caption' => (string) $req->get_param('caption'),
            'attach_to_post' => (int) $req->get_param('attach_to_post'),
        ]);

        if (empty($result['ok'])) {
            Log::append([
                'endpoint' => 'media-import',
                'user' => (string) wp_get_current_user()->user_login,
                'site_url' => (string) get_option('siteurl'),
                'result' => 'error',
                'error' => (string) ($result['error_code'] ?? 'IMPORT_FAILED'),
            ]);
            $status = ($result['error_code'] ?? '') === 'SIDELOAD_REJECTED' ? 415 : 422;
            return new WP_REST_Response($result, $status);
        }

        $attachId = (int) $result['attachment_id'];
        $session = isset($_SERVER['HTTP_X_ROLEPOD_SESSION']) ? (string) $_SERVER['HTTP_X_ROLEPOD_SESSION'] : null;

        try {
            ChangeRecorder::record([
                'category' => 'media',
                'subcategory' => 'import',
                'target_descriptor' => "attachment #{$attachId} " . basename((string) $result['url']),
                'before_state' => ['attachment_id' => null],
                'after_state' => ['attachment_id' => $attachId, 'url' => $result['url'], 'source' => $result['source']],
                'reversible' => true,
                'source_tool' => 'media_upload',
                'source_session' => $session,
                'notes' => 'Imported attachment — disable this row to delete it.',
            ]);
        } catch (\Throwable $t) {
            // Ledger table may be absent on a bare install — the upload stands.
        }

        $auditId = Log::append([
            'endpoint' => 'media-import',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => 'success',
            'error' => null,
        ]);

        $result['audit_id'] = $auditId;
        return new WP_REST_Response($result, 200);
    }
}
