<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Config;
use Rolepod\Wp\Security\ProductionGuard;
use Rolepod\Wp\Security\SessionToken;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * GET /wp-json/wplab/v1/handshake
 *
 * Returns companion version, runtime info, capability map, production status,
 * and issues a per-session execution token (TTL 30 min). Required first call
 * before /execute-php (which is itself disabled in v0.1).
 */
final class Handshake
{
    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/handshake',
            [
                'methods' => 'GET',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
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
        $userId = get_current_user_id();
        $token = SessionToken::issue($userId);

        $capabilities = ['introspect_hooks', 'introspect_transients', 'introspect_options_full', 'skills', 'media_import'];
        if (Config::executePhpEnabled() && !ProductionGuard::isProduction()) {
            $capabilities[] = 'execute_php';
        }
        if (\Rolepod\Wp\Abilities\Bridge::isAvailable()) {
            $capabilities[] = 'abilities_api';
        }

        $matchedPattern = ProductionGuard::matchedPattern();

        return new WP_REST_Response([
            'companion_version' => ROLEPOD_WP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'siteurl' => (string) get_option('siteurl'),
            'is_production' => $matchedPattern !== null,
            'production_pattern_matched' => $matchedPattern,
            'capabilities' => $capabilities,
            'abilities_api' => [
                'available'  => \Rolepod\Wp\Abilities\Bridge::isAvailable(),
                'registered' => \Rolepod\Wp\Abilities\Bridge::registered(),
            ],
            'session_token' => $token,
            'session_ttl_seconds' => SessionToken::ttlSeconds(),
        ], 200);
    }
}
