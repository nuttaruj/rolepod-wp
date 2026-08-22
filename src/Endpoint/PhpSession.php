<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Config;
use Rolepod\Wp\Security\AstScreen;
use Rolepod\Wp\Security\SessionToken;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * POST /wp-json/wplab/v1/php-session (v0.2)
 *
 * Persistent PHP eval context keyed by session token. Variables persist
 * across multiple calls within the same FastCGI worker. Designed for
 * debugging sessions where the Lead iterates on a payload.
 *
 * Same guard chain as execute-php: profile + production block + AST
 * screen + audit log.
 */
final class PhpSession
{
    private const CACHE_GROUP = 'rolepod_wp_php_session';

    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/php-session',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'payload' => ['required' => true, 'type' => 'string'],
                    'reset' => ['required' => false, 'type' => 'boolean', 'default' => false],
                ],
            ]
        );
    }

    public static function permission(WP_REST_Request $req)
    {
        if (!Config::endpointsEnabled() || !Config::executePhpEnabled()) {
            return new WP_Error('rolepod_wp_disabled', 'Companion endpoints / execute-php disabled.', ['status' => 403]);
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
        $payload = (string) $req->get_param('payload');
        $screen = AstScreen::screen($payload);
        if (!$screen['ok']) {
            $auditId = Log::append([
                'endpoint' => 'php-session',
                'user' => (string) wp_get_current_user()->user_login,
                'site_url' => (string) get_option('siteurl'),
                'result' => 'rejected',
                'error' => 'AST_REJECTED: ' . ($screen['error'] ?? 'unknown'),
                'payload' => $payload,
            ]);
            return new WP_REST_Response(['ok' => false, 'error_code' => 'AST_REJECTED', 'audit_id' => $auditId], 400);
        }

        if ((bool) $req->get_param('reset')) {
            wp_cache_delete($token, self::CACHE_GROUP);
        }

        // Restore session state. We store an associative array of $var=>value
        // and re-extract before eval. PHP can't restore in-process state across
        // requests (different FPM workers may handle each call), so this is
        // best-effort within a single worker.
        $state = wp_cache_get($token, self::CACHE_GROUP);
        $vars = is_array($state) ? $state : [];
        extract($vars, EXTR_SKIP);

        ob_start();
        $errorMsg = null;
        $returnValue = null;
        try {
            $returnValue = eval($payload); // phpcs:ignore Squiz.PHP.Eval -- guarded
        } catch (\Throwable $t) {
            $errorMsg = get_class($t) . ': ' . $t->getMessage();
        }
        $stdout = (string) ob_get_clean();

        // Capture user-defined vars after eval (best-effort).
        $defined = get_defined_vars();
        // Strip framework vars
        unset(
            $defined['userId'], $defined['token'], $defined['payload'], $defined['screen'],
            $defined['state'], $defined['vars'], $defined['errorMsg'], $defined['returnValue'],
            $defined['stdout'], $defined['req'], $defined['this']
        );
        wp_cache_set($token, $defined, self::CACHE_GROUP, SessionToken::ttlSeconds());

        $auditId = Log::append([
            'endpoint' => 'php-session',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => $errorMsg === null ? 'success' : 'error',
            'error' => $errorMsg,
            'payload' => $payload,
        ]);

        $body = [
            'ok' => $errorMsg === null,
            'return_value' => $returnValue,
            'stdout' => $stdout,
            'session_vars_kept' => array_keys($defined),
            'audit_id' => $auditId,
        ];
        if ($errorMsg !== null) $body['error_message'] = $errorMsg;
        return new WP_REST_Response($body, $errorMsg === null ? 200 : 500);
    }
}
