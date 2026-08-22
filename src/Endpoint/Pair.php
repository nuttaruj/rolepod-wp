<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Config;
use Rolepod\Wp\Security\PairToken;
use WP_Application_Passwords;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Pair-token endpoints — v1.2 one-click setup flow.
 *
 *   POST /wp-json/wplab/v1/pair/generate     (admin only, manage_options)
 *     - issues a single-use pair token (TTL 60 min)
 *     - admin redeem-cap: max 5 active tokens at once
 *     - response: { pair_token, expires_at, ttl_seconds, siteurl }
 *
 *   POST /wp-json/wplab/v1/pair/redeem       (public, token-authed)
 *     - body: { pair_token }
 *     - delete-first single-use guarantee (see PairToken::redeem)
 *     - mints a WP Application Password under the issuing admin user,
 *       named `wplab-pair-<timestamp>` for traceability
 *     - response: { username, app_password, capabilities, companion_version }
 *     - per-IP throttle: 10 failed redeems / hour
 */
final class Pair
{
    private const REDEEM_FAIL_LIMIT = 10;
    private const REDEEM_FAIL_WINDOW = 3600;
    private const ADMIN_ACTIVE_LIMIT = 5;
    private const APP_PASSWORD_NAME_PREFIX = 'wplab-pair-';

    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/pair/generate',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handleGenerate'],
                'permission_callback' => [self::class, 'permissionGenerate'],
            ]
        );
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/pair/redeem',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handleRedeem'],
                'permission_callback' => [self::class, 'permissionRedeem'],
            ]
        );
    }

    public static function permissionGenerate(WP_REST_Request $req)
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

    public static function permissionRedeem(WP_REST_Request $req)
    {
        // Pair token IS the auth. Public endpoint by design.
        if (!Config::endpointsEnabled()) {
            return new WP_Error(
                'rolepod_wp_disabled',
                'Companion endpoints are disabled.',
                ['status' => 403]
            );
        }
        return true;
    }

    public static function handleGenerate(WP_REST_Request $req): WP_REST_Response
    {
        $userId = get_current_user_id();
        PairToken::sweepExpired();
        $active = PairToken::countActiveFor($userId);
        if ($active >= self::ADMIN_ACTIVE_LIMIT) {
            Log::append([
                'endpoint' => 'pair_generate',
                'user' => (string) $userId,
                'site_url' => (string) get_option('siteurl'),
                'result' => 'fail',
                'error' => 'admin_active_limit_reached',
            ]);
            return new WP_REST_Response(
                [
                    'error_code' => 'PAIR_LIMIT_REACHED',
                    'error_message' => sprintf(
                        'You already have %d active pair tokens. Redeem or wait for one to expire.',
                        $active
                    ),
                ],
                429
            );
        }

        $raw = PairToken::issue($userId);
        $expiresAt = time() + PairToken::ttlSeconds();

        Log::append([
            'endpoint' => 'pair_generate',
            'user' => (string) $userId,
            'site_url' => (string) get_option('siteurl'),
            'result' => 'ok',
        ]);

        return new WP_REST_Response([
            'pair_token' => $raw,
            'expires_at' => gmdate('c', $expiresAt),
            'ttl_seconds' => PairToken::ttlSeconds(),
            'siteurl' => (string) get_option('siteurl'),
            'companion_version' => ROLEPOD_WP_VERSION,
        ], 200);
    }

    public static function handleRedeem(WP_REST_Request $req): WP_REST_Response
    {
        $ip = self::clientIp();
        $failures = self::failureCount($ip);
        if ($failures >= self::REDEEM_FAIL_LIMIT) {
            return new WP_REST_Response([
                'error_code' => 'PAIR_REDEEM_THROTTLED',
                'error_message' => 'Too many failed pair redeems. Try again later.',
            ], 429);
        }

        $body = $req->get_json_params();
        $rawToken = is_array($body) ? (string) ($body['pair_token'] ?? '') : '';
        if ($rawToken === '') {
            self::recordFailure($ip);
            return self::redeemFail($ip, 'PAIR_REDEEM_MISSING_TOKEN', 'pair_token required');
        }

        $userId = PairToken::redeem($rawToken);
        if ($userId === null) {
            return self::redeemFail($ip, 'PAIR_REDEEM_INVALID', 'pair token unknown, expired, or already used');
        }

        $user = get_userdata($userId);
        if (!$user || !user_can($user, 'manage_options')) {
            return self::redeemFail($ip, 'PAIR_REDEEM_USER_GONE', 'issuing user no longer has manage_options');
        }

        $appName = self::APP_PASSWORD_NAME_PREFIX . gmdate('YmdTHis');
        $created = WP_Application_Passwords::create_new_application_password($userId, [
            'name' => $appName,
            'app_id' => 'rolepod-wplab',
        ]);
        if (is_wp_error($created)) {
            return self::redeemFail(
                $ip,
                'PAIR_REDEEM_APP_PASSWORD_FAILED',
                $created->get_error_message()
            );
        }
        // create_new_application_password returns [plaintext_password, info_array]
        $plain = is_array($created) ? (string) ($created[0] ?? '') : (string) $created;
        if ($plain === '') {
            return self::redeemFail($ip, 'PAIR_REDEEM_APP_PASSWORD_EMPTY', 'WP returned empty App Password');
        }

        $capabilities = ['introspect_hooks', 'introspect_transients', 'introspect_options_full', 'skills'];
        // One switch, one owner decision: the full-access toggle alone
        // decides whether execute-php (and the rest of the power surface)
        // is available. The companion does not guess production-ness.
        if (Config::fullAccess()) {
            $capabilities[] = 'execute_php';
        }

        Log::append([
            'endpoint' => 'pair_redeem',
            'user' => $user->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => 'ok',
        ]);

        return new WP_REST_Response([
            'username' => $user->user_login,
            'app_password' => $plain,
            'app_password_name' => $appName,
            'capabilities' => $capabilities,
            'companion_version' => ROLEPOD_WP_VERSION,
            'siteurl' => (string) get_option('siteurl'),
            'access_mode' => Config::fullAccess() ? 'full' : 'guarded',
        ], 200);
    }

    private static function redeemFail(string $ip, string $code, string $msg): WP_REST_Response
    {
        self::recordFailure($ip);
        Log::append([
            'endpoint' => 'pair_redeem',
            'user' => 'anonymous',
            'site_url' => (string) get_option('siteurl'),
            'result' => 'fail',
            'error' => $code,
        ]);
        return new WP_REST_Response([
            'error_code' => $code,
            'error_message' => $msg,
        ], 400);
    }

    private static function clientIp(): string
    {
        $candidate = '';
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $candidate = (string) $_SERVER['REMOTE_ADDR'];
        }
        return preg_replace('/[^0-9a-fA-F:.]/', '', $candidate) ?: 'unknown';
    }

    private static function failureCount(string $ip): int
    {
        $key = 'rolepod_wp_pair_fail_' . md5($ip);
        $cur = (int) get_transient($key);
        return $cur > 0 ? $cur : 0;
    }

    private static function recordFailure(string $ip): void
    {
        $key = 'rolepod_wp_pair_fail_' . md5($ip);
        $cur = self::failureCount($ip);
        set_transient($key, $cur + 1, self::REDEEM_FAIL_WINDOW);
    }
}
