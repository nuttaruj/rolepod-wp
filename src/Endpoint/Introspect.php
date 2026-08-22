<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Config;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * GET /wp-json/wplab/v1/introspect?scope=hooks|transients|options_full|request_state
 *
 * Returns runtime-context snapshot. Read-only — no AST screen, no execute,
 * no payload eval. Production guard applies to scopes that may surface
 * secrets (transients, options_full) unless explicitly allowed in Settings.
 */
final class Introspect
{
    private const ALLOWED_SCOPES = ['hooks', 'transients', 'options_full', 'request_state', 'plugin_internals'];

    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/introspect',
            [
                'methods' => 'GET',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'scope' => [
                        'required' => true,
                        'validate_callback' => static fn ($v): bool => in_array($v, self::ALLOWED_SCOPES, true),
                    ],
                    'include_values' => [
                        'required' => false,
                        'default' => false,
                        'sanitize_callback' => 'rest_sanitize_boolean',
                    ],
                ],
            ]
        );
    }

    public static function permission(WP_REST_Request $req)
    {
        if (!Config::endpointsEnabled()) {
            return new WP_Error(
                'rolepod_wp_disabled',
                'Companion endpoints are disabled. Enable in Settings → Rolepod for WordPress.',
                ['status' => 403]
            );
        }
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'rolepod_wp_unauthorized',
                'manage_options capability required.',
                ['status' => 403]
            );
        }
        return true;
    }

    public static function handle(WP_REST_Request $req): WP_REST_Response
    {
        $scope = (string) $req->get_param('scope');
        $includeValues = (bool) $req->get_param('include_values');
        // Values in these scopes can carry secrets (API keys in options,
        // tokens in transients) — guarded mode returns keys only.
        if (!Config::fullAccess() && $includeValues && in_array($scope, ['transients', 'options_full'], true)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'FULL_ACCESS_REQUIRED',
                'error_message' => 'include_values requires full access for ' . $scope,
            ], 403);
        }

        switch ($scope) {
            case 'hooks':
                return new WP_REST_Response(self::dumpHooks(), 200);
            case 'transients':
                return new WP_REST_Response(self::dumpTransients($includeValues), 200);
            case 'options_full':
                return new WP_REST_Response(self::dumpOptions($includeValues), 200);
            case 'request_state':
                return new WP_REST_Response(self::dumpRequestState(), 200);
            case 'plugin_internals':
                return new WP_REST_Response(self::dumpPluginInternals((string) $req->get_param('plugin_slug')), 200);
        }
        return new WP_REST_Response(['ok' => false, 'error_code' => 'UNKNOWN_SCOPE'], 400);
    }

    private static function dumpPluginInternals(string $slug): array
    {
        if ($slug === '') {
            return ['ok' => false, 'error_code' => 'PLUGIN_SLUG_REQUIRED'];
        }
        // 3rd-party plugins can register their own introspection via this filter.
        $data = apply_filters('rolepod_wp_introspect_' . $slug, null);
        if ($data === null) {
            return ['ok' => false, 'error_code' => 'NO_INTROSPECTION_REGISTERED', 'hint' => 'plugin must add_filter("rolepod_wp_introspect_' . $slug . '", fn() => [...])'];
        }
        return ['plugin' => $slug, 'data' => $data];
    }

    private static function dumpHooks(): array
    {
        global $wp_filter;
        $out = [];
        if (!is_array($wp_filter)) {
            return ['hooks' => []];
        }
        foreach ($wp_filter as $hookName => $hook) {
            $callbacks = [];
            // $hook is a WP_Hook instance in modern WP; iterate priorities via ->callbacks
            $byPriority = isset($hook->callbacks) ? $hook->callbacks : [];
            foreach ($byPriority as $priority => $entries) {
                foreach ($entries as $entry) {
                    $callbacks[] = [
                        'priority' => (int) $priority,
                        'callback_identifier' => self::identifyCallback($entry['function'] ?? null),
                    ];
                }
            }
            $out[$hookName] = $callbacks;
        }
        return ['hooks' => $out];
    }

    private static function dumpTransients(bool $includeValues): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT option_name, LENGTH(option_value) AS sz FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' AND option_name NOT LIKE '_transient_timeout_%'",
            ARRAY_A
        );
        $out = [];
        foreach ($rows as $row) {
            $name = preg_replace('/^_transient_/', '', $row['option_name']);
            $entry = [
                'name' => $name,
                'size_bytes' => (int) $row['sz'],
            ];
            if ($includeValues) {
                $entry['value'] = get_transient($name);
            }
            $out[] = $entry;
        }
        return ['transients' => $out];
    }

    private static function dumpOptions(bool $includeValues): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT option_name, autoload, LENGTH(option_value) AS sz FROM {$wpdb->options}",
            ARRAY_A
        );
        $sensitive = ['siteurl' => false, 'admin_email' => true];
        $out = [];
        foreach ($rows as $row) {
            $name = $row['option_name'];
            $entry = [
                'name' => $name,
                'autoload' => $row['autoload'],
                'size_bytes' => (int) $row['sz'],
            ];
            if ($includeValues) {
                $entry['value'] = isset($sensitive[$name]) && $sensitive[$name] === true
                    ? '<redacted>'
                    : get_option($name);
            }
            $out[] = $entry;
        }
        return ['options' => $out];
    }

    private static function dumpRequestState(): array
    {
        return [
            'request' => [
                'method' => $_SERVER['REQUEST_METHOD'] ?? null,
                'uri' => $_SERVER['REQUEST_URI'] ?? null,
                'user_id' => get_current_user_id(),
                'has_get_params' => !empty($_GET),
                'has_post_params' => !empty($_POST),
            ],
            'wp' => [
                'is_admin' => is_admin(),
                'doing_rest' => defined('REST_REQUEST') && constant('REST_REQUEST'),
                'doing_cron' => defined('DOING_CRON') && constant('DOING_CRON'),
                'doing_ajax' => defined('DOING_AJAX') && constant('DOING_AJAX'),
            ],
        ];
    }

    /**
     * @param mixed $callback
     */
    private static function identifyCallback($callback): string
    {
        if (is_string($callback)) {
            return $callback;
        }
        if (is_array($callback) && isset($callback[0], $callback[1])) {
            $owner = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];
            return $owner . '::' . (string) $callback[1];
        }
        if ($callback instanceof \Closure) {
            try {
                $ref = new \ReflectionFunction($callback);
                return 'closure@' . $ref->getFileName() . ':' . $ref->getStartLine();
            } catch (\ReflectionException $e) {
                return 'closure@unknown';
            }
        }
        return 'unknown';
    }
}
