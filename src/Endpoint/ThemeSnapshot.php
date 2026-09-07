<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use PharData;
use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Config;
use Rolepod\Wp\Fs\BackupRetention;
use Rolepod\Wp\Security\SessionToken;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Theme snapshot + restore endpoints. Used by the MCP's wp_theme_switch_safe
 * + wp_theme_snapshot tools so AI can switch themes / edit theme files with
 * a known-good rollback artifact on disk.
 *
 *   POST /wp-json/wplab/v1/theme/snapshot
 *     Body: { session_token, stylesheet }
 *     Tars wp-content/themes/<stylesheet>/ → wp-content/uploads/rolepod-wp-theme-snapshots/<stylesheet>-<utc-ts>.tar.gz
 *     Returns: { ok, path, bytes, file_count }
 *
 *   POST /wp-json/wplab/v1/theme/restore
 *     Body: { session_token, snapshot_path }
 *     Untars over wp-content/themes/<stylesheet>/ (recovered from snapshot filename).
 *     Returns: { ok, stylesheet, files_restored }
 *
 * Both require manage_options + endpoints_enabled + session_token.
 *
 * Implementation: PharData (built-in, no composer) creates / extracts
 * .tar.gz files. PharData has size limits (~2GB) and is slow above a few
 * hundred MB; themes are usually < 50 MB so this is fine for v2.4.
 */
final class ThemeSnapshot
{
    public static function register(): void
    {
        $ns = ROLEPOD_WP_REST_NAMESPACE;

        register_rest_route($ns, '/theme/snapshot', [
            'methods' => 'POST',
            'callback' => [self::class, 'handleSnapshot'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'session_token' => ['required' => true, 'type' => 'string'],
                'stylesheet' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        register_rest_route($ns, '/theme/restore', [
            'methods' => 'POST',
            'callback' => [self::class, 'handleRestore'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'session_token' => ['required' => true, 'type' => 'string'],
                'snapshot_path' => ['required' => true, 'type' => 'string'],
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

    private static function checkToken(WP_REST_Request $req): ?WP_REST_Response
    {
        $userId = get_current_user_id();
        $token = (string) $req->get_param('session_token');
        if (!SessionToken::verify($token, $userId)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'INVALID_OR_EXPIRED_TOKEN',
            ], 401);
        }
        return null;
    }

    public static function handleSnapshot(WP_REST_Request $req): WP_REST_Response
    {
        if (($bad = self::checkToken($req)) !== null) return $bad;

        $stylesheet = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $req->get_param('stylesheet'));
        if ($stylesheet === '') {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'INVALID_STYLESHEET',
            ], 400);
        }

        $themeRoot = trailingslashit(get_theme_root()) . $stylesheet;
        if (!is_dir($themeRoot)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'THEME_NOT_FOUND',
                'error_message' => "no theme dir at {$themeRoot}",
            ], 404);
        }

        $dir = self::snapshotDir();
        if (!wp_mkdir_p($dir)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'MKDIR_FAILED',
                'error_message' => $dir,
            ], 500);
        }

        $ts = gmdate('Ymd-His');
        $tarPath = trailingslashit($dir) . "{$stylesheet}-{$ts}.tar";
        $gzPath = $tarPath . '.gz';

        try {
            // Build tar from the parent of $themeRoot so paths inside the archive
            // are <stylesheet>/... (restore reconstructs the correct dir name).
            $tar = new PharData($tarPath);
            $tar->buildFromDirectory(dirname($themeRoot), '/' . preg_quote($stylesheet, '/') . '/');
            $tar->compress(\Phar::GZ);
            // PharData::compress writes <orig>.gz alongside; remove the .tar.
            @unlink($tarPath);
        } catch (\Throwable $e) {
            @unlink($tarPath);
            @unlink($gzPath);
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'TAR_FAILED',
                'error_message' => $e->getMessage(),
            ], 500);
        }

        if (!is_file($gzPath)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'TAR_MISSING_OUTPUT',
            ], 500);
        }

        // Count files via a fresh PharData read for accuracy.
        $fileCount = 0;
        try {
            $check = new PharData($gzPath);
            foreach (new \RecursiveIteratorIterator($check) as $_) {
                $fileCount++;
            }
        } catch (\Throwable $e) {
            $fileCount = -1;
        }

        // A theme snapshot is a whole tar.gz of the theme dir — keeping one per
        // edit fills uploads/ fast. Keep the newest few per stylesheet; an
        // older snapshot_path handed to theme/restore may be gone, and the
        // 404 it returns already names the missing file.
        $prunedCount = count(BackupRetention::pruneGroup(
            $dir,
            $stylesheet . '-',
            '.tar.gz',
            null,
            '/^\\d{8}-\\d{6}$/'
        ));

        $auditId = Log::append([
            'endpoint' => 'theme/snapshot',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => 'success',
            'error' => null,
        ]);

        return new WP_REST_Response([
            'ok' => true,
            'stylesheet' => $stylesheet,
            'path' => $gzPath,
            'bytes' => (int) filesize($gzPath),
            'file_count' => $fileCount,
            'snapshots_pruned' => $prunedCount,
            'snapshots_kept' => BackupRetention::keep(),
            'audit_id' => $auditId,
        ], 200);
    }

    public static function handleRestore(WP_REST_Request $req): WP_REST_Response
    {
        if (($bad = self::checkToken($req)) !== null) return $bad;

        $snapshotPath = (string) $req->get_param('snapshot_path');
        if (!is_file($snapshotPath)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'SNAPSHOT_NOT_FOUND',
                'error_message' => $snapshotPath,
            ], 404);
        }

        // Confirm the snapshot lives in our managed dir — prevents arbitrary
        // path-restore attacks via crafted snapshot_path parameter.
        $managedDir = self::snapshotDir();
        $real = realpath($snapshotPath);
        $managedReal = realpath($managedDir);
        if ($real === false || $managedReal === false || strpos($real, $managedReal) !== 0) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'SNAPSHOT_PATH_OUT_OF_SCOPE',
            ], 403);
        }

        // Derive stylesheet from filename: <stylesheet>-<ts>.tar.gz
        $base = basename($snapshotPath);
        if (!preg_match('/^([a-zA-Z0-9_\-]+)-\d{8}-\d{6}\.tar\.gz$/', $base, $m)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'SNAPSHOT_NAME_MALFORMED',
            ], 400);
        }
        $stylesheet = $m[1];

        $destParent = get_theme_root();
        $destDir = trailingslashit($destParent) . $stylesheet;

        try {
            // Untar in-place over destination. PharData::extractTo with overwrite=true.
            $phar = new PharData($snapshotPath);
            $phar->extractTo($destParent, null, true);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'UNTAR_FAILED',
                'error_message' => $e->getMessage(),
            ], 500);
        }

        $count = 0;
        if (is_dir($destDir)) {
            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($destDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($rii as $_) {
                $count++;
            }
        }

        $auditId = Log::append([
            'endpoint' => 'theme/restore',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => 'success',
            'error' => null,
        ]);

        return new WP_REST_Response([
            'ok' => true,
            'stylesheet' => $stylesheet,
            'files_restored' => $count,
            'audit_id' => $auditId,
        ], 200);
    }

    private static function snapshotDir(): string
    {
        $uploads = wp_upload_dir();
        $base = is_array($uploads) && !empty($uploads['basedir']) ? $uploads['basedir'] : ABSPATH . 'wp-content/uploads';
        return trailingslashit($base) . 'rolepod-wp-theme-snapshots';
    }
}
