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
 * POST /wp-json/wplab/v1/elementor/widget-attribute (v2.12)
 *
 * Persist arbitrary `data-*` attributes per Elementor widget. Elementor's
 * sanitizer strips raw HTML widget attrs on re-save, and native widgets
 * don't pass arbitrary attrs through `_css_classes` — this endpoint solves
 * both by:
 *
 *  1. Storing the desired attrs in `_rolepod_widget_attrs` post meta keyed
 *     by widget element id.
 *  2. The footer-emitter hook (registered alongside this endpoint in
 *     rolepod-wp.php) outputs a JSON `<script>` on every singular page,
 *     listing per-widget attr maps.
 *  3. The walnut.js theme bridge reads that JSON on DOMContentLoaded and
 *     applies `data-*` attrs to `[data-id="<widget_id>"]` BEFORE running
 *     effects — so scramble / magnet / tilt / typer fire correctly.
 *
 * Body: { session_token, post_id, widget_id, attrs: { [attr]: string } }
 * (Use attr name WITHOUT the `data-` prefix. Pass attrs = {} to clear.)
 *
 * Response 200: { ok: true, post_id, widget_id, attrs_now: {...} }
 */
final class ElementorWidgetAttribute
{
    public const META_KEY = '_rolepod_widget_attrs';

    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/elementor/widget-attribute',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'post_id' => ['required' => true, 'type' => 'integer'],
                    'widget_id' => ['required' => true, 'type' => 'string'],
                    'attrs' => ['required' => true, 'type' => 'object'],
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
                'endpoint' => 'elementor/widget-attribute',
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

        $postId = (int) $req->get_param('post_id');
        $widgetId = trim((string) $req->get_param('widget_id'));
        $attrsRaw = $req->get_param('attrs');

        if ($postId <= 0 || !get_post($postId)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'POST_NOT_FOUND'], 404);
        }
        if ($widgetId === '' || !preg_match('/^[a-z0-9]{4,16}$/i', $widgetId)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_WIDGET_ID', 'error_message' => 'widget_id must be 4-16 hex/alphanumeric chars'], 400);
        }
        if (!is_array($attrsRaw)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_ATTRS', 'error_message' => 'attrs must be an object'], 400);
        }

        // Sanitize each attr. Allow only [a-z][a-z0-9-]* for keys, string values.
        $clean = [];
        foreach ($attrsRaw as $k => $v) {
            $key = strtolower((string) $k);
            if (!preg_match('/^[a-z][a-z0-9-]{0,30}$/', $key)) {
                return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_ATTR_KEY', 'error_message' => "attr key '{$key}' must match /^[a-z][a-z0-9-]{0,30}$/"], 400);
            }
            $clean[$key] = is_scalar($v) ? (string) $v : wp_json_encode($v);
        }

        $existing = get_post_meta($postId, self::META_KEY, true);
        if (!is_array($existing)) {
            $existing = [];
        }

        if (count($clean) === 0) {
            unset($existing[$widgetId]);
        } else {
            $existing[$widgetId] = $clean;
        }

        update_post_meta($postId, self::META_KEY, $existing);

        return new WP_REST_Response([
            'ok' => true,
            'post_id' => $postId,
            'widget_id' => $widgetId,
            'attrs_now' => $existing[$widgetId] ?? [],
            'widgets_total' => count($existing),
        ], 200);
    }

    /**
     * Emit the bridge JSON on every singular() page that has stored attrs.
     * Theme JS reads `#rolepod-widget-attrs`, walks the map, applies
     * `data-<attr>="<value>"` to elements matching `[data-id="<widget_id>"]`.
     */
    public static function emitBridgeFooter(): void
    {
        if (!is_singular()) {
            return;
        }
        $postId = (int) get_the_ID();
        if ($postId <= 0) {
            return;
        }
        $attrs = get_post_meta($postId, self::META_KEY, true);
        if (!is_array($attrs) || count($attrs) === 0) {
            return;
        }
        $json = wp_json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return;
        }
        echo "\n<script id=\"rolepod-widget-attrs\" type=\"application/json\">{$json}</script>\n";
        echo "<script>(function(){var s=document.getElementById('rolepod-widget-attrs');if(!s)return;var m={};try{m=JSON.parse(s.textContent)}catch(e){return}Object.keys(m).forEach(function(id){var el=document.querySelector('[data-id=\"'+id+'\"]');if(!el)return;var a=m[id];Object.keys(a).forEach(function(k){el.setAttribute('data-'+k,a[k])})})})();</script>\n";
    }
}
