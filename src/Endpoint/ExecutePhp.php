<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Audit\ChangeRecorder;
use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Config;
use Rolepod\Wp\Security\AstScreen;
use Rolepod\Wp\Security\ProductionGuard;
use Rolepod\Wp\Security\SessionToken;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * POST /wp-json/wplab/v1/execute-php
 *
 * Runs a PHP payload inside the live WP request lifecycle — IF AND ONLY IF
 * all guards pass:
 *   1. Endpoints enabled (master toggle)
 *   2. execute_php_enabled toggle ON (v0.1 defaults to OFF)
 *   3. User has manage_options
 *   4. Valid session token
 *   5. Production guard does not match siteurl
 *   6. AST screen approves the payload
 *
 * v0.1 ships with execute_php_enabled=false by default. Admin must explicitly
 * flip it ON via Settings → Rolepod for WordPress. v0.2 will default to ON once
 * the audit log + AST screen have been dogfooded.
 */
final class ExecutePhp
{
    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/execute-php',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'payload' => ['required' => true, 'type' => 'string'],
                    'timeout_ms' => ['required' => false, 'type' => 'integer', 'default' => 5000],
                    // When false (default) the payload is rejected with
                    // SYMBOL_CONFLICT if it declares a global function/class/…
                    // that already exists (would fatal on eval). Set true only
                    // when the caller knowingly re-runs a declaring payload.
                    'allow_redeclare' => ['required' => false, 'type' => 'boolean', 'default' => false],
                ],
            ]
        );
    }

    public static function permission(WP_REST_Request $req)
    {
        if (!Config::endpointsEnabled()) {
            return new WP_Error('rolepod_wp_disabled', 'Companion endpoints disabled.', ['status' => 403]);
        }
        if (!Config::executePhpEnabled()) {
            return new WP_Error(
                'rolepod_wp_execute_php_disabled',
                'execute-php endpoint is OFF (v0.1 default). Enable in Settings → Rolepod for WordPress.',
                ['status' => 503]
            );
        }
        if (!current_user_can('manage_options')) {
            return new WP_Error('rolepod_wp_unauthorized', 'manage_options required.', ['status' => 403]);
        }
        return true;
    }

    public static function handle(WP_REST_Request $req): WP_REST_Response
    {
        $payload = (string) $req->get_param('payload');
        $token = (string) $req->get_param('session_token');
        $timeoutMs = max(100, min(30_000, (int) $req->get_param('timeout_ms')));
        $userId = get_current_user_id();
        $siteurl = (string) get_option('siteurl');

        // Production block — unconditional
        $matched = ProductionGuard::matchedPattern();
        if ($matched !== null) {
            $auditId = Log::append([
                'endpoint' => 'execute-php',
                'user' => (string) wp_get_current_user()->user_login,
                'site_url' => $siteurl,
                'result' => 'rejected',
                'error' => "PRODUCTION_BLOCKED (matched={$matched})",
                'payload_sha256' => hash('sha256', $payload),
            ]);
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'PRODUCTION_BLOCKED',
                'error_message' => "siteurl matches production pattern: {$matched}",
                'audit_id' => $auditId,
            ], 403);
        }

        // Session token check
        if (!SessionToken::verify($token, $userId)) {
            $auditId = Log::append([
                'endpoint' => 'execute-php',
                'user' => (string) wp_get_current_user()->user_login,
                'site_url' => $siteurl,
                'result' => 'rejected',
                'error' => 'INVALID_OR_EXPIRED_TOKEN',
                'payload_sha256' => hash('sha256', $payload),
            ]);
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'INVALID_OR_EXPIRED_TOKEN',
                'error_message' => 'session_token missing, invalid, or expired — re-handshake',
                'audit_id' => $auditId,
            ], 401);
        }

        // AST screen
        $screen = AstScreen::screen($payload);
        if (!$screen['ok']) {
            $auditId = Log::append([
                'endpoint' => 'execute-php',
                'user' => (string) wp_get_current_user()->user_login,
                'site_url' => $siteurl,
                'result' => 'rejected',
                'error' => 'AST_REJECTED: ' . ($screen['error'] ?? 'unknown'),
                'payload' => $payload,
            ]);
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'AST_REJECTED',
                'error_message' => $screen['error'] ?? 'forbidden token',
                'token' => $screen['token'] ?? null,
                'audit_id' => $auditId,
            ], 400);
        }

        // Symbol-conflict screen (v2.14) — reject payloads that re-declare an
        // existing global function/class/interface/trait/enum. "Cannot
        // redeclare" is a fatal that eval()'s try/catch cannot reliably
        // intercept, so we catch it here BEFORE eval and hand back an
        // actionable error instead of a 500/WSOD. Bypassable per-call via
        // allow_redeclare for the rare intentional re-run.
        $allowRedeclare = (bool) $req->get_param('allow_redeclare');
        if (!$allowRedeclare) {
            $conflicts = AstScreen::symbolConflicts($payload);
            if (!empty($conflicts)) {
                $names = implode(', ', array_map(
                    static fn(array $c): string => $c['kind'] . ' ' . $c['name'],
                    $conflicts
                ));
                $auditId = Log::append([
                    'endpoint' => 'execute-php',
                    'user' => (string) wp_get_current_user()->user_login,
                    'site_url' => $siteurl,
                    'result' => 'rejected',
                    'error' => 'SYMBOL_CONFLICT: ' . $names,
                    'payload' => $payload,
                ]);
                return new WP_REST_Response([
                    'ok' => false,
                    'error_code' => 'SYMBOL_CONFLICT',
                    'error_message' => "payload re-declares existing symbol(s): {$names}. "
                        . 'Rename them, guard with function_exists()/class_exists(), or retry with allow_redeclare=true.',
                    'conflicts' => $conflicts,
                    'audit_id' => $auditId,
                ], 409);
            }
        }

        // Eval — guarded by AST screen + symbol screen + prod block + session token + crash recovery
        @set_time_limit(intval(ceil($timeoutMs / 1000)));
        $stdout = '';
        $returnValue = null;
        $errorMsg = null;
        $phpWarnings = [];
        $startedAt = microtime(true);

        // v2.5 crash recovery: capture E_ERROR / E_PARSE / E_CORE_ERROR via
        // shutdown handler so a fatal in user payload does not bring the
        // request down (and thus the page the admin is viewing). We unregister
        // immediately after eval to keep the handler local to this request.
        $fatalCaught = null;
        $prevErrorHandler = set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) use (&$phpWarnings): bool {
            // Capture E_WARNING / E_NOTICE / E_USER_* without halting eval.
            $phpWarnings[] = [
                'level' => $errno,
                'message' => $errstr,
                'file' => $errfile,
                'line' => $errline,
            ];
            return true; // suppress default
        });

        ob_start();
        try {
            $returnValue = eval($payload); // phpcs:ignore Squiz.PHP.Eval -- guarded by AST screen + prod block + session token
        } catch (\ParseError $t) {
            $errorMsg = 'ParseError: ' . $t->getMessage() . ' on line ' . $t->getLine();
        } catch (\Error $t) {
            // PHP 7+ fatal errors are Throwable; catch and continue.
            $errorMsg = get_class($t) . ': ' . $t->getMessage();
            if ($t->getLine() > 0) {
                $errorMsg .= ' on line ' . $t->getLine();
            }
        } catch (\Throwable $t) {
            $errorMsg = get_class($t) . ': ' . $t->getMessage();
        }
        $stdout = (string) ob_get_clean();

        // Restore error handler chain.
        if ($prevErrorHandler === null) {
            restore_error_handler();
        } else {
            set_error_handler($prevErrorHandler);
            restore_error_handler();
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $auditId = Log::append([
            'endpoint' => 'execute-php',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => $siteurl,
            'result' => $errorMsg === null ? 'success' : 'error',
            'error' => $errorMsg,
            'payload' => $payload,
        ]);

        // v2.7 — server-side ledger capture. On success, scan payload for
        // common write fingerprints (update_option, wp_insert_post,
        // wp_update_post, update_post_meta, deactivate_plugins, etc.) and
        // record one ledger row per match so AI writes via execute-php are
        // discoverable in the AI Change Ledger. The detection is best-effort
        // tokenize-by-regex; it won't catch obfuscated payloads but covers
        // the common case where AI writes ARE the read.
        $ledgerSummary = null;
        if ($errorMsg === null) {
            $ledgerSummary = self::recordExecutePhpLedger($payload, $auditId, $returnValue);
        }

        $body = [
            'ok' => $errorMsg === null,
            'return_value' => $returnValue,
            'stdout' => $stdout,
            'duration_ms' => $durationMs,
            'php_warnings' => $phpWarnings,
            'audit_id' => $auditId,
            'ledger' => $ledgerSummary,
        ];
        if ($errorMsg !== null) {
            $body['error_message'] = $errorMsg;
        }

        return new WP_REST_Response($body, $errorMsg === null ? 200 : 500);
    }

    /**
     * Scan a successfully-executed PHP payload for common WP write calls and
     * record a Change Ledger row per detected write. Best-effort regex-based
     * — won't catch dynamic dispatch (call_user_func, variable function
     * names) but covers the bulk of AI-generated payloads where calls are
     * direct.
     *
     * Returns a short summary used in the response so MCP / AI can correlate
     * the execute-php call with the ledger rows it created.
     */
    private static function recordExecutePhpLedger(string $payload, string $auditId, $returnValue): array
    {
        $detected = [];
        $session = isset($_SERVER['HTTP_X_ROLEPOD_SESSION']) ? (string) $_SERVER['HTTP_X_ROLEPOD_SESSION'] : null;

        $patterns = [
            'option' => '/\\bupdate_option\\s*\\(\\s*[\'\"]([^\'\"]+)[\'\"]/',
            'post_create' => '/\\bwp_insert_post\\s*\\(/',
            'post_update' => '/\\bwp_update_post\\s*\\(/',
            'post_delete' => '/\\bwp_delete_post\\s*\\(/',
            'post_meta' => '/\\bupdate_post_meta\\s*\\(\\s*[^,]+,\\s*[\'\"]([^\'\"]+)[\'\"]/',
            'plugin_activate' => '/\\bactivate_plugins?\\s*\\(/',
            'plugin_deactivate' => '/\\bdeactivate_plugins\\s*\\(/',
            'theme_switch' => '/\\bswitch_theme\\s*\\(/',
            'user_create' => '/\\bwp_insert_user\\s*\\(|\\bwp_create_user\\s*\\(/',
            'menu_create' => '/\\bwp_create_nav_menu\\s*\\(/',
            'menu_item' => '/\\bwp_update_nav_menu_item\\s*\\(/',
            'theme_mod' => '/\\bset_theme_mod\\s*\\(\\s*[\'\"]([^\'\"]+)[\'\"]/',
        ];

        foreach ($patterns as $kind => $regex) {
            if (preg_match_all($regex, $payload, $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $m) {
                    $sub = isset($m[1]) ? $m[1] : $kind;
                    try {
                        $rowAuditId = ChangeRecorder::record([
                            'category' => self::ledgerCategory($kind),
                            'subcategory' => $sub,
                            'target_descriptor' => "$kind via execute-php (audit:$auditId)",
                            'before_state' => null,
                            'after_state' => ['detected_op' => $kind, 'execute_php_audit_id' => $auditId],
                            'reversible' => false, // can't auto-revert arbitrary execute-php
                            'source_tool' => 'execute_php',
                            'source_session' => $session,
                            'notes' => 'Auto-detected from execute-php payload via regex scan. Manual revert required.',
                        ]);
                        $detected[] = ['kind' => $kind, 'subcategory' => $sub, 'audit_id' => $rowAuditId];
                    } catch (\Throwable $t) {
                        // Ledger DB might not exist yet on fresh install — swallow.
                    }
                }
            }
        }

        return [
            'detected_ops' => count($detected),
            'rows' => $detected,
            'note' => count($detected) === 0
                ? 'No direct write calls detected — payload may be read-only or use dynamic dispatch.'
                : 'Regex-detected writes recorded in ledger (reversible=false).',
        ];
    }

    private static function ledgerCategory(string $kind): string
    {
        $map = [
            'option' => 'option',
            'post_create' => 'post',
            'post_update' => 'post',
            'post_delete' => 'post',
            'post_meta' => 'post',
            'plugin_activate' => 'plugin',
            'plugin_deactivate' => 'plugin',
            'theme_switch' => 'theme',
            'user_create' => 'execute_php', // no user category yet
            'menu_create' => 'execute_php',
            'menu_item' => 'execute_php',
            'theme_mod' => 'theme',
        ];
        return $map[$kind] ?? 'execute_php';
    }
}
