<?php
declare(strict_types=1);

namespace Rolepod\Wp\Security;

/**
 * Fail-closed safe-mode gate for the companion's REST surface.
 *
 * Until now `rolepod_wp_safe_mode` only gated media optimization
 * (Endpoint\MediaOptimize, Media\Queue). Everything else — execute-php,
 * fs-write, wp-cli, option-set, the Elementor writers — ran normally with
 * safe-mode ON, so the flag the recovery tooling sets after a fatal did not
 * actually stop the AI from writing again.
 *
 * This filter closes that gap for the whole `wplab/v1` namespace. When
 * safe-mode is on it refuses every mutating request EXCEPT an explicit
 * read/diagnostic allowlist. New write endpoints are therefore blocked by
 * default — the safe choice — rather than slipping through because nobody
 * remembered to gate them.
 *
 * Recovery endpoints live in the guardian mu-plugin under a different
 * namespace, so they are unaffected and keep working while safe-mode is on —
 * which is the whole point of being able to recover.
 */
final class SafeModeGuard
{
    /**
     * POST endpoints that read or diagnose without mutating site state, so
     * they stay available in safe-mode. GET/HEAD/OPTIONS are always allowed and
     * are not listed here. Matched on the route segment after the namespace,
     * with sub-paths (`request-observer/poll`) covered by prefix.
     */
    private const READ_ROUTES = [
        'fs-read',
        'fs-list',
        'introspect',
        'handshake',
        'option-get',
        'php-session',
        'syntax-check',
        'request-observer',
        // Pulling an existing archive offsite is read-only and is exactly what
        // you want available during recovery.
        'backup-download',
    ];

    /**
     * wp-cli subcommand verbs that only read. A `/wp-cli` call is allowed in
     * safe-mode only when one of these appears among its leading tokens.
     */
    private const WPCLI_READ_VERBS = [
        'list',
        'get',
        'status',
        'version',
        'info',
        'check',
        'is-installed',
        'is-active',
        'is-active-network',
        'verify-checksums',
    ];

    public static function register(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'gate'], 10, 3);
    }

    /**
     * @param mixed            $result  Short-circuit value; non-null means a
     *                                  prior filter already answered.
     * @param \WP_REST_Server  $server
     * @param \WP_REST_Request $request
     * @return mixed
     */
    public static function gate($result, $server, $request)
    {
        if ($result !== null) {
            return $result;
        }
        if (!(bool) get_option('rolepod_wp_safe_mode', false)) {
            return $result;
        }

        $prefix = '/' . ROLEPOD_WP_REST_NAMESPACE . '/';
        $route  = (string) $request->get_route();
        if (strncmp($route, $prefix, strlen($prefix)) !== 0) {
            // Another namespace (including the guardian recovery routes).
            return $result;
        }

        $method = strtoupper((string) $request->get_method());
        if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
            return $result;
        }

        $short = substr($route, strlen($prefix));
        foreach (self::READ_ROUTES as $allowed) {
            if ($short === $allowed || strncmp($short, $allowed . '/', strlen($allowed) + 1) === 0) {
                return $result;
            }
        }

        if ($short === 'wp-cli' && self::isReadOnlyWpCli((array) $request->get_param('args'))) {
            return $result;
        }

        return new \WP_Error(
            'rolepod_wp_safe_mode',
            '423 Locked: guardian safe-mode is ON — this write was refused. '
                . 'Clear it in Settings → Rolepod for WordPress, or via '
                . 'rolepod_wp_recovery_safe_mode(enabled=false) once the site is stable.',
            ['status' => 423]
        );
    }

    /**
     * True when the wp-cli args are a read-only command. A read verb must
     * appear among the first three non-flag tokens (`plugin list`,
     * `user session list`, `core verify-checksums`).
     */
    private static function isReadOnlyWpCli(array $args): bool
    {
        $tokens = [];
        foreach ($args as $a) {
            if (is_string($a) && $a !== '' && $a[0] !== '-') {
                $tokens[] = strtolower($a);
            }
        }
        foreach (array_slice($tokens, 0, 3) as $token) {
            if (in_array($token, self::WPCLI_READ_VERBS, true)) {
                return true;
            }
        }
        return false;
    }
}
