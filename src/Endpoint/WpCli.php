<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Config;
use Rolepod\Wp\Security\ProductionGuard;
use Rolepod\Wp\Security\SessionToken;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * POST /wp-json/wplab/v1/wp-cli (v0.2, W-027)
 *
 * Proxy to wp-cli.phar executed via PHP exec(). Lets shared-hosting users
 * run wp-cli without installing it on the host (companion bundles or
 * fetches the phar to wp-content/uploads/wplab-bin/wp-cli.phar).
 *
 * If `exec()` is disabled in PHP config OR phar path is missing, returns
 * 503 with hint to enable exec OR install host-side wp-cli.
 */
final class WpCli
{
    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/wp-cli',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'args' => ['required' => true, 'type' => 'array'],
                    'timeout_seconds' => ['required' => false, 'type' => 'integer', 'default' => 30],
                ],
            ]
        );

        // Idempotent bootstrap: fetches the wp-cli.phar from upstream and
        // drops it at wp-content/uploads/wplab-bin/wp-cli.phar. Called by
        // the MCP after a /wp-cli call returns WP_CLI_NOT_BUNDLED, so the
        // host gets a working wp-cli without anyone SSH'ing in.
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/wp-cli/bootstrap',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handleBootstrap'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
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
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'INVALID_OR_EXPIRED_TOKEN',
            ], 401);
        }

        if (!function_exists('exec') || in_array('exec', explode(',', (string) ini_get('disable_functions')), true)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'EXEC_DISABLED',
                'error_message' => 'PHP exec() is disabled. Install wp-cli host-side OR use a host that allows exec().',
            ], 503);
        }

        $phar = self::resolvePharPath();
        if ($phar === null) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'WP_CLI_NOT_BUNDLED',
                'error_message' => 'wp-cli.phar not found at ' . self::expectedPharPath()
                    . '. Run: curl -L -o ' . self::expectedPharPath() . ' https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar',
            ], 503);
        }

        $args = (array) $req->get_param('args');
        $timeout = max(1, min(120, (int) $req->get_param('timeout_seconds')));
        $wpPath = ABSPATH;

        // Catastrophic commands are refused unconditionally — no production
        // check, no override. These destroy the site beyond recovery, and a
        // token that can run wp-cli must still never run them. The Node side
        // guards the same set, but a compromised or buggy client must not be
        // the only thing standing between a token and `db drop`.
        if (self::isCatastrophic($args)) {
            $auditId = Log::append([
                'endpoint' => 'wp-cli',
                'user' => (string) wp_get_current_user()->user_login,
                'site_url' => (string) get_option('siteurl'),
                'result' => 'rejected',
                'error' => 'CATASTROPHIC_BLOCKED; args=' . implode(' ', $args),
            ]);
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'CATASTROPHIC_BLOCKED',
                'error_message' => 'This wp-cli command is permanently blocked by the companion and cannot be run through the REST bridge.',
                'audit_id' => $auditId,
            ], 403);
        }

        // Production guard for destructive ops — basic version. Real allow-list
        // lives on the Node MCP side (W-005); companion just refuses on prod.
        $matched = ProductionGuard::matchedPattern();
        if ($matched !== null && self::looksDestructive($args)) {
            $auditId = Log::append([
                'endpoint' => 'wp-cli',
                'user' => (string) wp_get_current_user()->user_login,
                'site_url' => (string) get_option('siteurl'),
                'result' => 'rejected',
                'error' => "PRODUCTION_BLOCKED (matched={$matched}); args=" . implode(' ', $args),
            ]);
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'PRODUCTION_BLOCKED',
                'audit_id' => $auditId,
            ], 403);
        }

        // Ensure scratch dir exists before any wp-cli call. Many ops write to
        // wp-content/uploads/wplab-tmp/ (db export, backup, restore staging,
        // diagnostic dumps). v2.7.0 backup_create failed with "mysqldump:
        // Can't create/write to file ... (errno 2)" because this dir didn't
        // exist on first call. wp_mkdir_p is idempotent + cheap.
        $scratchDir = WP_CONTENT_DIR . '/uploads/wplab-tmp';
        if (!is_dir($scratchDir)) {
            wp_mkdir_p($scratchDir);
        }

        $escapedArgs = array_map('escapeshellarg', $args);
        $cmd = 'php ' . escapeshellarg($phar) . ' --path=' . escapeshellarg($wpPath)
            . ' --no-color ' . implode(' ', $escapedArgs) . ' 2>&1';

        $output = [];
        $exitCode = 0;
        @exec($cmd, $output, $exitCode);
        $stdout = implode("\n", $output);

        $auditId = Log::append([
            'endpoint' => 'wp-cli',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => $exitCode === 0 ? 'success' : 'error',
            'error' => $exitCode === 0 ? null : "exit_code={$exitCode}",
        ]);

        return new WP_REST_Response([
            'ok' => $exitCode === 0,
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'audit_id' => $auditId,
        ], 200);
    }

    public static function handleBootstrap(WP_REST_Request $req): WP_REST_Response
    {
        $userId = get_current_user_id();
        $token = (string) $req->get_param('session_token');
        if (!SessionToken::verify($token, $userId)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'INVALID_OR_EXPIRED_TOKEN',
            ], 401);
        }

        $target = self::expectedPharPath();

        // Idempotent — return ok:true if phar already present.
        if (is_file($target)) {
            return new WP_REST_Response([
                'ok' => true,
                'already_present' => true,
                'path' => $target,
                'bytes' => (int) filesize($target),
            ], 200);
        }

        $dir = dirname($target);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'MKDIR_FAILED',
                'error_message' => 'could not create ' . $dir,
            ], 500);
        }

        // Pull pinned upstream phar. Public asset, no auth needed.
        $upstream = 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';
        $res = wp_remote_get($upstream, ['timeout' => 60]);
        if (is_wp_error($res)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'FETCH_FAILED',
                'error_message' => $res->get_error_message(),
            ], 502);
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        $body = (string) wp_remote_retrieve_body($res);
        if ($code !== 200 || $body === '') {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'FETCH_BAD_RESPONSE',
                'error_message' => "upstream returned HTTP {$code}, body bytes=" . strlen($body),
            ], 502);
        }

        // Sanity-check: phar starts with #!/usr/bin/env php and contains __HALT_COMPILER.
        if (strpos($body, "__HALT_COMPILER") === false) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'FETCH_NOT_A_PHAR',
                'error_message' => 'upstream response did not look like a phar (no __HALT_COMPILER marker)',
            ], 502);
        }

        $written = file_put_contents($target, $body);
        if ($written === false) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'WRITE_FAILED',
                'error_message' => "could not write to {$target}",
            ], 500);
        }

        @chmod($target, 0644);

        $auditId = Log::append([
            'endpoint' => 'wp-cli/bootstrap',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => 'success',
            'error' => null,
        ]);

        return new WP_REST_Response([
            'ok' => true,
            'already_present' => false,
            'path' => $target,
            'bytes' => $written,
            'audit_id' => $auditId,
        ], 200);
    }

    private static function expectedPharPath(): string
    {
        $uploads = wp_upload_dir();
        $base = is_array($uploads) && !empty($uploads['basedir']) ? $uploads['basedir'] : ABSPATH . 'wp-content/uploads';
        return trailingslashit($base) . 'wplab-bin/wp-cli.phar';
    }

    private static function resolvePharPath(): ?string
    {
        $p = self::expectedPharPath();
        return is_file($p) ? $p : null;
    }

    /** Heuristic destructive-cmd detection. Real allow-list lives on Node side. */
    private static function looksDestructive(array $args): bool
    {
        if (count($args) === 0) return false;
        $head = strtolower(implode(' ', array_slice($args, 0, 2)));
        $destructive = ['db reset', 'db drop', 'core multisite-convert', 'plugin delete', 'theme delete'];
        foreach ($destructive as $d) {
            if (strpos($head, $d) === 0) return true;
        }
        return false;
    }

    /**
     * Commands that destroy the site beyond recovery — refused with no override,
     * mirroring the Node CATASTROPHIC set. `eval` is deliberately absent: the
     * companion runs `wp eval` legitimately for internal operations.
     *
     * Global parameters (`wp --yes db reset`) are stripped first so the check
     * cannot be bypassed by prefixing a flag.
     */
    private static function isCatastrophic(array $args): bool
    {
        $tokens = [];
        foreach ($args as $a) {
            if (is_string($a) && $a !== '' && $a[0] !== '-') {
                $tokens[] = strtolower(trim($a));
            }
        }
        $catastrophic = [
            ['db', 'reset'],
            ['db', 'drop'],
            ['db', 'clean'],
            ['core', 'multisite-convert'],
        ];
        foreach ($catastrophic as $cmd) {
            if (($tokens[0] ?? null) === $cmd[0] && ($tokens[1] ?? null) === $cmd[1]) {
                return true;
            }
        }
        return false;
    }
}
