<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Config;
use Rolepod\Wp\Security\SessionToken;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/wplab/v1/elementor/template-apply (v2.12)
 *
 * Companion-side complement to /elementor/template-export. Takes a
 * sections array (verbatim from template-export OR hand-built) plus a
 * target_post_id, optionally runs a list of string replacements, then
 * writes the result to the target post's `_elementor_data` meta + sets
 * the necessary Elementor flags. Returns the post id + sections count.
 *
 * Production-guarded. Refuses to overwrite when the target already has
 * non-empty _elementor_data unless overwrite=true is passed.
 *
 * Body:
 *   {
 *     session_token,
 *     target_post_id,
 *     sections: [ ... ],
 *     replace_strings?: { "<find>": "<replace>", ... },
 *     overwrite?: bool   (default false — refuses when target has data)
 *   }
 */
final class ElementorTemplateApply
{
    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/elementor/template-apply',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'target_post_id' => ['required' => true, 'type' => 'integer'],
                    'sections' => ['required' => true, 'type' => 'array'],
                    'replace_strings' => ['required' => false, 'type' => 'object'],
                    'overwrite' => ['required' => false, 'type' => 'boolean', 'default' => false],
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
                'endpoint' => 'elementor/template-apply',
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

        $postId = (int) $req->get_param('target_post_id');
        $sections = $req->get_param('sections');
        $replaceStrings = $req->get_param('replace_strings');
        $overwrite = (bool) $req->get_param('overwrite');

        if (!get_post($postId)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'POST_NOT_FOUND'], 404);
        }
        if (!is_array($sections) || count($sections) === 0) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'EMPTY_SECTIONS', 'error_message' => 'sections must be a non-empty array'], 400);
        }

        $existing = get_post_meta($postId, '_elementor_data', true);
        if (!$overwrite && is_string($existing) && trim($existing) !== '' && trim($existing) !== '[]') {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'TARGET_HAS_DATA',
                'error_message' => "post {$postId} already has _elementor_data; pass overwrite=true to replace",
            ], 409);
        }

        $json = wp_json_encode($sections, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'JSON_ENCODE_FAILED'], 500);
        }

        if (is_array($replaceStrings)) {
            foreach ($replaceStrings as $find => $repl) {
                if (!is_string($find) || !is_string($repl)) {
                    continue;
                }
                $json = str_replace($find, $repl, $json);
            }
        }

        // Regenerate unique element ids so the new copy doesn't collide with
        // the source in Elementor's internal id cache. A simple per-id remap
        // suffices — we replace every 8-char hex token preceded by "id":".
        $json = preg_replace_callback(
            '/"id":"([a-f0-9]{6,12})"/',
            static function () { return '"id":"' . bin2hex(random_bytes(4)) . '"'; },
            $json
        );

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'JSON_REGEN_INVALID'], 500);
        }

        update_post_meta($postId, '_elementor_data', wp_slash($json));
        update_post_meta($postId, '_elementor_edit_mode', 'builder');
        if ((string) get_post_meta($postId, '_elementor_template_type', true) === '') {
            update_post_meta($postId, '_elementor_template_type', 'wp-page');
        }
        if ((string) get_post_meta($postId, '_wp_page_template', true) === '') {
            update_post_meta($postId, '_wp_page_template', 'elementor_header_footer');
        }
        delete_post_meta($postId, '_elementor_css');

        return new WP_REST_Response([
            'ok' => true,
            'target_post_id' => $postId,
            'section_count' => count($decoded),
            'replacements_applied' => is_array($replaceStrings) ? count($replaceStrings) : 0,
        ], 200);
    }
}
