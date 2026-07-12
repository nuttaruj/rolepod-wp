<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Backup\Archive;
use Rolepod\Wp\Backup\Engine;
use Rolepod\Wp\Backup\RestoreEngine;
use Rolepod\Wp\Backup\Transfer;
use Rolepod\Wp\Config;
use Rolepod\Wp\Security\SessionToken;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Backup REST surface (phase 1 — create + inspect; restore is a later phase).
 *
 *   POST /backup-start   { session_token, components{}, compress?, exclude[] }
 *   GET  /backup-status
 *   GET  /backup-list
 *   POST /backup-inspect { id, entry? }   ← list/read INSIDE a zip, no extract
 *   POST /backup-cancel  { session_token }
 *   POST /backup-delete  { session_token, id }
 *
 * Inspect is the AI-friendly payoff: it reads a backup's central directory (or
 * one member like manifest.json / database.sql) via Archive without unpacking
 * the archive — so an agent can understand or pull part of a backup cheaply.
 */
final class Backup
{
    public static function register(): void
    {
        $ns = ROLEPOD_WP_REST_NAMESPACE;
        register_rest_route($ns, '/backup-start', [
            'methods' => 'POST',
            'callback' => [self::class, 'start'],
            'permission_callback' => [self::class, 'permission'],
            'args' => ['session_token' => ['required' => true, 'type' => 'string']],
        ]);
        register_rest_route($ns, '/backup-status', [
            'methods' => 'GET',
            'callback' => [self::class, 'statusRoute'],
            'permission_callback' => [self::class, 'permission'],
        ]);
        register_rest_route($ns, '/backup-list', [
            'methods' => 'GET',
            'callback' => [self::class, 'listRoute'],
            'permission_callback' => [self::class, 'permission'],
        ]);
        register_rest_route($ns, '/backup-inspect', [
            'methods' => 'POST',
            'callback' => [self::class, 'inspect'],
            'permission_callback' => [self::class, 'permission'],
            'args' => ['id' => ['required' => true, 'type' => 'string']],
        ]);
        register_rest_route($ns, '/backup-cancel', [
            'methods' => 'POST',
            'callback' => [self::class, 'cancel'],
            'permission_callback' => [self::class, 'permission'],
            'args' => ['session_token' => ['required' => true, 'type' => 'string']],
        ]);
        register_rest_route($ns, '/backup-delete', [
            'methods' => 'POST',
            'callback' => [self::class, 'delete'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'session_token' => ['required' => true, 'type' => 'string'],
                'id' => ['required' => true, 'type' => 'string'],
            ],
        ]);
        register_rest_route($ns, '/backup-restore', [
            'methods' => 'POST',
            'callback' => [self::class, 'restore'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'session_token' => ['required' => true, 'type' => 'string'],
                'id' => ['required' => true, 'type' => 'string'],
                'confirm' => ['required' => true, 'type' => 'boolean'],
            ],
        ]);
        register_rest_route($ns, '/backup-restore-status', [
            'methods' => 'GET',
            'callback' => [self::class, 'restoreStatus'],
            'permission_callback' => [self::class, 'permission'],
        ]);
        // v2.23 — chunked offsite transfer: pull an archive out (download) or
        // push one in from another host (import).
        register_rest_route($ns, '/backup-download', [
            'methods' => 'POST',
            'callback' => [self::class, 'download'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'session_token' => ['required' => true, 'type' => 'string'],
                'id' => ['required' => true, 'type' => 'string'],
                'offset' => ['required' => false, 'type' => 'integer', 'default' => 0],
                'length' => ['required' => false, 'type' => 'integer', 'default' => Transfer::MAX_CHUNK],
            ],
        ]);
        register_rest_route($ns, '/backup-import', [
            'methods' => 'POST',
            'callback' => [self::class, 'import'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'session_token' => ['required' => true, 'type' => 'string'],
                'upload_id' => ['required' => true, 'type' => 'string'],
                'offset' => ['required' => true, 'type' => 'integer'],
                'chunk' => ['required' => true, 'type' => 'string'],
                'final' => ['required' => false, 'type' => 'boolean', 'default' => false],
                'filename' => ['required' => false, 'type' => 'string'],
            ],
        ]);
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

    private static function checkSession(WP_REST_Request $req): bool
    {
        return SessionToken::verify((string) $req->get_param('session_token'), get_current_user_id());
    }

    public static function start(WP_REST_Request $req): WP_REST_Response
    {
        if (!self::checkSession($req)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_OR_EXPIRED_TOKEN'], 401);
        }
        $components = (array) ($req->get_param('components') ?? []);
        $opts = [
            'compress' => $req->get_param('compress') === null ? true : (bool) $req->get_param('compress'),
            'exclude' => array_values(array_filter(array_map('strval', (array) ($req->get_param('exclude') ?? [])))),
        ];
        $r = Engine::start($components, $opts);
        Log::append([
            'endpoint' => 'backup-start',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => !empty($r['ok']) ? 'success' : 'rejected',
            'error' => $r['error'] ?? null,
        ]);
        return new WP_REST_Response($r, !empty($r['ok']) ? 200 : 409);
    }

    public static function statusRoute(): WP_REST_Response
    {
        return new WP_REST_Response(['ok' => true, 'job' => Engine::status()], 200);
    }

    public static function listRoute(): WP_REST_Response
    {
        return new WP_REST_Response(['ok' => true, 'backups' => Engine::history()], 200);
    }

    public static function inspect(WP_REST_Request $req): WP_REST_Response
    {
        $id = (string) $req->get_param('id');
        $zip = self::resolveZip($id);
        if ($zip === null) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'BACKUP_NOT_FOUND'], 404);
        }
        $entry = $req->get_param('entry');
        if (is_string($entry) && $entry !== '') {
            $r = Archive::readEntry($zip, $entry, (int) ($req->get_param('max_bytes') ?? 1_000_000));
            $r['ok'] = $r['ok'] ?? false;
            return new WP_REST_Response($r, $r['ok'] ? 200 : 404);
        }
        $r = Archive::listEntries($zip);
        return new WP_REST_Response($r, !empty($r['ok']) ? 200 : 500);
    }

    public static function cancel(WP_REST_Request $req): WP_REST_Response
    {
        if (!self::checkSession($req)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_OR_EXPIRED_TOKEN'], 401);
        }
        Engine::cancel();
        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function delete(WP_REST_Request $req): WP_REST_Response
    {
        if (!self::checkSession($req)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_OR_EXPIRED_TOKEN'], 401);
        }
        $ok = Engine::deleteBackup((string) $req->get_param('id'));
        return new WP_REST_Response(['ok' => $ok], $ok ? 200 : 404);
    }

    public static function restore(WP_REST_Request $req): WP_REST_Response
    {
        if (!self::checkSession($req)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_OR_EXPIRED_TOKEN'], 401);
        }
        $zip = self::resolveZip((string) $req->get_param('id'));
        if ($zip === null) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'BACKUP_NOT_FOUND'], 404);
        }
        $components = (array) ($req->get_param('components') ?? ['db' => true, 'files' => true]);
        $sr = (array) ($req->get_param('search_replace') ?? []);
        $r = RestoreEngine::start($zip, $components, [
            'confirm' => (bool) $req->get_param('confirm'),
            'path_prefix' => (string) ($req->get_param('path_prefix') ?? ''),
            'search_replace' => $sr,
        ]);
        Log::append([
            'endpoint' => 'backup-restore',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => !empty($r['ok']) ? 'success' : 'rejected',
            'error' => $r['error'] ?? null,
        ]);
        return new WP_REST_Response($r, !empty($r['ok']) ? 200 : 409);
    }

    public static function restoreStatus(): WP_REST_Response
    {
        return new WP_REST_Response(['ok' => true, 'job' => RestoreEngine::status()], 200);
    }

    /**
     * Chunked download of an existing archive. Read-only, so it stays available
     * in safe-mode (see SafeModeGuard READ_ROUTES) — pulling a backup offsite is
     * exactly what you want during recovery.
     */
    public static function download(WP_REST_Request $req): WP_REST_Response
    {
        if (!self::checkSession($req)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_OR_EXPIRED_TOKEN'], 401);
        }
        $zip = self::resolveZip((string) $req->get_param('id'));
        if ($zip === null) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'BACKUP_NOT_FOUND'], 404);
        }
        $r = Transfer::readChunk($zip, (int) $req->get_param('offset'), (int) $req->get_param('length'));
        if (empty($r['ok'])) {
            return new WP_REST_Response(['ok' => false, 'error_code' => $r['error'] ?? 'READ_FAILED', 'total_bytes' => $r['total_bytes'] ?? null], 422);
        }
        return new WP_REST_Response($r, 200);
    }

    /**
     * Chunked import of a backup pushed from another host. Each chunk appends to
     * a staging file at its exact current end (OFFSET_MISMATCH otherwise); the
     * final chunk hands the reassembled zip to Engine::importUpload, which is
     * the authority on whether it is a real Rolepod backup. A write → refused in
     * safe-mode.
     */
    public static function import(WP_REST_Request $req): WP_REST_Response
    {
        if (!self::checkSession($req)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_OR_EXPIRED_TOKEN'], 401);
        }
        if ((bool) get_option('rolepod_wp_safe_mode', false)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'SAFE_MODE',
                'error_message' => 'Guardian safe-mode is on — backup import refused. Clear safe-mode first.',
            ], 423);
        }

        $dir = Engine::backupsDir();
        if ($dir === '') {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'DIR_UNWRITABLE'], 500);
        }

        $uploadId = (string) $req->get_param('upload_id');
        $offset = (int) $req->get_param('offset');
        $raw = base64_decode((string) $req->get_param('chunk'), true);
        if ($raw === false) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_BASE64'], 422);
        }

        $appended = Transfer::appendChunk($dir, $uploadId, $offset, $raw);
        if (empty($appended['ok'])) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => $appended['error'] ?? 'STAGE_FAILED',
                'expected_offset' => $appended['expected_offset'] ?? null,
            ], 409);
        }

        if (!(bool) $req->get_param('final')) {
            return new WP_REST_Response(['ok' => true, 'done' => false, 'received' => $appended['received']], 200);
        }

        // Final chunk — validate + register the reassembled archive.
        $stage = Transfer::stagePath($dir, $uploadId);
        $filename = (string) ($req->get_param('filename') ?: 'imported-backup.zip');
        $result = Engine::importUpload($stage, $filename);
        Transfer::discard($dir, $uploadId);

        Log::append([
            'endpoint' => 'backup-import',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => !empty($result['ok']) ? 'success' : 'rejected',
            'error' => $result['error'] ?? null,
        ]);

        if (empty($result['ok'])) {
            return new WP_REST_Response([
                'ok' => false,
                'done' => true,
                'error_code' => $result['error'] ?? 'IMPORT_FAILED',
                'error_message' => $result['message'] ?? null,
            ], 422);
        }
        return new WP_REST_Response(['ok' => true, 'done' => true] + $result, 200);
    }

    private static function resolveZip(string $id): ?string
    {
        foreach (Engine::history() as $e) {
            if (($e['id'] ?? '') === $id && !empty($e['zip_path']) && is_file($e['zip_path'])) {
                return (string) $e['zip_path'];
            }
        }
        return null;
    }
}
