<?php
/**
 * Plugin Name: Rolepod for WordPress — Recovery Guardian
 * Description: mu-plugin recovery layer for rolepod-wp. Loads before regular plugins so it survives main-plugin parse/fatal errors. Exposes /wp-json/wplab-recovery/v1/* for out-of-band recovery (disable plugin, rename file, restore snapshot, panic ledger). Auto-installed by the main rolepod-wp plugin on activate; removed on deactivate.
 * Version: 2.6.0
 * Author: nuttaruj
 *
 * IMPORTANT: This file MUST be self-contained. The main plugin's autoloader,
 * classes, and helpers are NOT guaranteed to be available — that's the whole
 * point of a guardian. Every dependency is inlined or comes from WP core.
 *
 * @package rolepod-wp-guardian
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (defined('ROLEPOD_WP_GUARDIAN_VERSION')) {
    // Another copy already loaded — don't double-register.
    return;
}
define('ROLEPOD_WP_GUARDIAN_VERSION', '2.8.0');
define('ROLEPOD_WP_GUARDIAN_NAMESPACE', 'wplab-recovery/v1');
define('ROLEPOD_WP_GUARDIAN_FATALS_TRANSIENT', 'rolepod_wp_recovery_recent_fatals');
define('ROLEPOD_WP_GUARDIAN_SAFE_MODE_OPTION', 'rolepod_wp_safe_mode');
// v2.8 boot-loop auto-heal. OPT-IN (default OFF): when the SAME plugin/theme/
// mu-plugin file fatals on this many consecutive guardian-observed requests,
// the guardian renames it to `.disabled` and flips safe-mode — breaking a WSOD
// loop without SSH/FTP. Off by default so behaviour is unchanged until an admin
// (or the MCP) explicitly enables it.
define('ROLEPOD_WP_GUARDIAN_AUTOHEAL_OPTION', 'rolepod_wp_guardian_autoheal');
define('ROLEPOD_WP_GUARDIAN_STREAK_OPTION', 'rolepod_wp_guardian_fatal_streak');
define('ROLEPOD_WP_GUARDIAN_AUTOHEAL_LOG_OPTION', 'rolepod_wp_guardian_autoheal_log');
define('ROLEPOD_WP_GUARDIAN_AUTOHEAL_THRESHOLD', 3);

/**
 * Early-dispatch short-circuit (v2.6.1).
 *
 * WHY: rest_api_init only fires after WP completes init(), which happens
 * AFTER setup_theme + theme functions.php load. If theme fatals at load
 * time, rest_api_init never fires → our guardian REST routes never
 * register → recovery endpoints unreachable in that request. Theme load
 * failure is ~40% of real-world WSODs.
 *
 * FIX: detect recovery URL at the top of mu-plugin load, then hook
 * muplugins_loaded:PHP_INT_MAX to short-circuit BEFORE plugins or theme
 * are loaded. Manually init REST API, register guardian routes, dispatch,
 * exit. Plugins + theme never load for this request.
 *
 * AUTH: WP's Application Password validation classes are in wp-includes
 * (loaded by wp-load.php BEFORE mu-plugins). We can call
 * WP_Application_Passwords::validate_application_password() directly.
 */
$rolepod_guardian_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
if (
    strpos($rolepod_guardian_uri, '/wp-json/wplab-recovery/v1/') !== false
    || strpos($rolepod_guardian_uri, 'rest_route=/wplab-recovery/v1/') !== false
    || strpos($rolepod_guardian_uri, 'rest_route=%2Fwplab-recovery%2Fv1%2F') !== false
) {
    add_action('muplugins_loaded', static function (): void {
        rolepod_guardian_early_dispatch();
        exit; // never returns — bypasses plugin/theme load entirely
    }, PHP_INT_MAX);
}

/**
 * Detect FATAL via shutdown handler. WP core's own WSOD-protection uses the
 * same mechanism. We only record errors that look plugin/theme-induced —
 * core-PHP errors get logged too but aren't actionable through guardian.
 */
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!is_array($err)) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array($err['type'] ?? 0, $fatalTypes, true)) {
        return;
    }

    // Only WP-context errors. Skip if no DB connection (set_transient
    // would fail anyway).
    if (!function_exists('set_transient')) {
        return;
    }

    $record = [
        'type' => (int) ($err['type'] ?? 0),
        'message' => (string) ($err['message'] ?? ''),
        'file' => (string) ($err['file'] ?? ''),
        'line' => (int) ($err['line'] ?? 0),
        'ts' => time(),
        'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
        'is_plugin' => strpos((string) ($err['file'] ?? ''), '/plugins/') !== false,
        'is_theme' => strpos((string) ($err['file'] ?? ''), '/themes/') !== false,
        'is_mu_plugin' => strpos((string) ($err['file'] ?? ''), '/mu-plugins/') !== false,
    ];

    $recent = get_transient(ROLEPOD_WP_GUARDIAN_FATALS_TRANSIENT);
    if (!is_array($recent)) {
        $recent = [];
    }
    $recent[] = $record;
    // Keep last 10.
    if (count($recent) > 10) {
        $recent = array_slice($recent, -10);
    }
    set_transient(ROLEPOD_WP_GUARDIAN_FATALS_TRANSIENT, $recent, 24 * HOUR_IN_SECONDS);

    // v2.8 — boot-loop auto-heal (opt-in). Break a repeating WSOD by disabling
    // the file that keeps fataling. No-op unless explicitly enabled.
    rolepod_guardian_maybe_autoheal($record);
});

/**
 * Boot-loop auto-heal. Tracks a per-file consecutive-fatal streak; when the
 * SAME plugin/theme/mu-plugin file crosses the threshold AND auto-heal is
 * enabled, the file is renamed `.disabled` and safe-mode is turned on.
 *
 * Safety rails:
 *   - OPT-IN: no-op unless ROLEPOD_WP_GUARDIAN_AUTOHEAL_OPTION is truthy.
 *   - SCOPED: only files under wp-content/{plugins,themes,mu-plugins}. Core
 *     (wp-includes / wp-admin) and the guardian itself are never touched.
 *   - THRESHOLD: requires N consecutive fatals from the same file (a genuine
 *     loop), not a single transient error.
 *   - For a plugin file the whole plugin is disabled (rename main file +
 *     deactivate) so the plugin is never left half-loaded; theme / mu-plugin
 *     files are renamed individually.
 */
function rolepod_guardian_maybe_autoheal(array $record): void
{
    if (!function_exists('get_option') || !function_exists('update_option')) {
        return; // DB unavailable — can't track streak or rename safely.
    }
    if (!get_option(ROLEPOD_WP_GUARDIAN_AUTOHEAL_OPTION, false)) {
        return; // opt-in gate.
    }

    $file = (string) ($record['file'] ?? '');
    if ($file === '' || !rolepod_guardian_is_healable($file)) {
        return;
    }

    // Update the consecutive-same-file streak.
    $streak = get_option(ROLEPOD_WP_GUARDIAN_STREAK_OPTION, []);
    if (!is_array($streak) || ($streak['file'] ?? null) !== $file) {
        $streak = ['file' => $file, 'count' => 0];
    }
    $streak['count'] = (int) ($streak['count'] ?? 0) + 1;

    if ($streak['count'] < ROLEPOD_WP_GUARDIAN_AUTOHEAL_THRESHOLD) {
        update_option(ROLEPOD_WP_GUARDIAN_STREAK_OPTION, $streak, false);
        return;
    }

    // Threshold reached — attempt the heal.
    $result = rolepod_guardian_autoheal_disable($file);

    $log = get_option(ROLEPOD_WP_GUARDIAN_AUTOHEAL_LOG_OPTION, []);
    if (!is_array($log)) {
        $log = [];
    }
    $log[] = [
        'file' => $file,
        'action' => $result['action'],
        'target' => $result['target'] ?? null,
        'ok' => $result['ok'],
        'error' => $result['error'] ?? null,
        'streak' => $streak['count'],
        'ts' => time(),
    ];
    if (count($log) > 20) {
        $log = array_slice($log, -20);
    }
    update_option(ROLEPOD_WP_GUARDIAN_AUTOHEAL_LOG_OPTION, $log, false);

    if ($result['ok']) {
        // Loop broken — reset streak and raise safe-mode so the main plugin
        // refuses further risky ops until an admin reviews what happened.
        delete_option(ROLEPOD_WP_GUARDIAN_STREAK_OPTION);
        update_option(ROLEPOD_WP_GUARDIAN_SAFE_MODE_OPTION, true, false);
    } else {
        // Heal failed (perms?) — keep the streak so a later request can retry,
        // but cap it so the log doesn't grow unbounded.
        $streak['count'] = ROLEPOD_WP_GUARDIAN_AUTOHEAL_THRESHOLD;
        update_option(ROLEPOD_WP_GUARDIAN_STREAK_OPTION, $streak, false);
    }
}

/**
 * Whether a fataling file is in scope for auto-heal. Plugins / themes /
 * mu-plugins only; never WP core and never the guardian file itself
 * (disabling the guardian would remove the very recovery layer doing this).
 */
function rolepod_guardian_is_healable(string $file): bool
{
    $file = str_replace('\\', '/', $file);
    if (basename($file) === ROLEPOD_WP_GUARDIAN_VERSION) {
        return false; // never matches a path, but cheap belt-and-braces.
    }
    if (strpos($file, '/rolepod-wp-guardian.php') !== false) {
        return false;
    }
    if (strpos($file, '/wp-includes/') !== false || strpos($file, '/wp-admin/') !== false) {
        return false;
    }
    return strpos($file, '/plugins/') !== false
        || strpos($file, '/themes/') !== false
        || strpos($file, '/mu-plugins/') !== false;
}

/**
 * Disable the offending file. For a plugin, resolve + disable the whole plugin
 * (rename main file → .disabled, then deactivate). For theme / mu-plugin files,
 * rename the single file. Returns array{ok:bool,action:string,target?:string,error?:string}.
 */
function rolepod_guardian_autoheal_disable(string $file): array
{
    $norm = str_replace('\\', '/', $file);

    // Plugin file → disable the whole plugin via its main file.
    if (defined('WP_PLUGIN_DIR') && strpos($norm, '/plugins/') !== false) {
        $pluginsDir = str_replace('\\', '/', WP_PLUGIN_DIR);
        $rel = ltrim(substr($norm, strpos($norm, '/plugins/') + strlen('/plugins/')), '/');
        $slug = strpos($rel, '/') !== false ? substr($rel, 0, strpos($rel, '/')) : $rel;
        if ($slug !== '') {
            $mainFile = rolepod_guardian_resolve_plugin_file($slug);
            if ($mainFile !== null) {
                $abs = $pluginsDir . '/' . $mainFile;
                if (is_file($abs) && !is_file($abs . '.disabled') && @rename($abs, $abs . '.disabled')) {
                    if (function_exists('deactivate_plugins')) {
                        @deactivate_plugins($mainFile, true);
                    }
                    return ['ok' => true, 'action' => 'disable_plugin', 'target' => $mainFile];
                }
            }
        }
    }

    // Theme / mu-plugin / fallback plugin-file → rename the single file.
    if (is_file($norm) && !is_file($norm . '.disabled') && @rename($norm, $norm . '.disabled')) {
        return ['ok' => true, 'action' => 'disable_file', 'target' => $norm];
    }

    return ['ok' => false, 'action' => 'none', 'error' => 'rename_failed_or_already_disabled'];
}

/**
 * REST registration. Fires on rest_api_init when WP boot completes normally
 * (used for the "normal" path when WP is healthy). The early-dispatch path
 * (muplugins_loaded short-circuit) calls rolepod_guardian_register_routes()
 * directly on a manually-created WP_REST_Server because rest_get_server() →
 * rest_api_init → create_initial_rest_routes() depends on classes
 * (WP_Site_Health, etc.) not loaded until much later in WP boot.
 */
add_action('rest_api_init', static function (): void {
    rolepod_guardian_register_routes();
});

/**
 * Register guardian routes. Used by both the rest_api_init hook (normal
 * path, register_rest_route() routes through the global server) AND by
 * the early-dispatch path (manually-created server passed in, so we call
 * $server->register_route() directly).
 *
 * @param \WP_REST_Server|null $server  When null, uses register_rest_route
 *                                      (normal path). When passed, registers
 *                                      directly on the given server (early
 *                                      dispatch — avoids firing rest_api_init
 *                                      which would trigger WP-core's
 *                                      create_initial_rest_routes() that
 *                                      requires WP_Site_Health etc).
 */
function rolepod_guardian_register_routes(?\WP_REST_Server $server = null): void
{
    $perm = static function (): bool {
        return current_user_can('manage_options');
    };

    $routes = [
        ['method' => 'GET',  'route' => '/status',           'callback' => 'rolepod_guardian_status',           'args' => []],
        ['method' => 'POST', 'route' => '/disable-plugin',   'callback' => 'rolepod_guardian_disable_plugin',   'args' => ['plugin' => ['required' => true, 'type' => 'string']]],
        ['method' => 'POST', 'route' => '/disable-file',     'callback' => 'rolepod_guardian_disable_file',     'args' => ['path' => ['required' => true, 'type' => 'string']]],
        ['method' => 'POST', 'route' => '/restore-file',     'callback' => 'rolepod_guardian_restore_file',     'args' => ['path' => ['required' => true, 'type' => 'string']]],
        ['method' => 'POST', 'route' => '/restore-snapshot', 'callback' => 'rolepod_guardian_restore_snapshot', 'args' => ['snapshot_path' => ['required' => true, 'type' => 'string']]],
        ['method' => 'GET',  'route' => '/list-changes',     'callback' => 'rolepod_guardian_list_changes',     'args' => []],
        ['method' => 'POST', 'route' => '/safe-mode',        'callback' => 'rolepod_guardian_safe_mode',        'args' => ['enabled' => ['required' => true, 'type' => 'boolean']]],
        ['method' => 'POST', 'route' => '/clear-fatals',     'callback' => 'rolepod_guardian_clear_fatals',     'args' => []],
        ['method' => 'POST', 'route' => '/autoheal',         'callback' => 'rolepod_guardian_autoheal_toggle',  'args' => ['enabled' => ['required' => true, 'type' => 'boolean']]],
    ];

    foreach ($routes as $r) {
        $route_args = [
            'methods' => $r['method'],
            'callback' => $r['callback'],
            'permission_callback' => $perm,
            'args' => $r['args'],
        ];
        if ($server !== null) {
            // Early-dispatch path: register directly on the passed server,
            // bypassing register_rest_route()'s global state.
            $full_route = '/' . trim(ROLEPOD_WP_GUARDIAN_NAMESPACE, '/') . '/' . trim($r['route'], '/');
            $server->register_route(ROLEPOD_WP_GUARDIAN_NAMESPACE, $full_route, [$route_args]);
        } else {
            register_rest_route(ROLEPOD_WP_GUARDIAN_NAMESPACE, $r['route'], $route_args);
        }
    }
}

/**
 * GET /status — main plugin alive? recent fatals? safe-mode on?
 */
function rolepod_guardian_status(): WP_REST_Response
{
    // `main_alive` here would mean "main plugin already loaded into this
    // request" which is true on the normal REST path (rest_api_init fires
    // after plugin load) but FALSE on the early-dispatch path (we
    // shortcircuit at muplugins_loaded, before plugins). That semantic
    // is misleading for AI consumers: in early dispatch they'd see
    // main_alive=false even when main is perfectly healthy.
    //
    // Better signal: infer "main will load on next regular request" by
    // checking active_plugins option + file existence. If main is in
    // active_plugins AND its file isn't .disabled, the next normal
    // request will load it.
    $mainProbablyAlive = false;
    $mainFile = WP_PLUGIN_DIR . '/rolepod-wp/rolepod-wp.php';
    $mainDisabled = WP_PLUGIN_DIR . '/rolepod-wp/rolepod-wp.php.disabled';
    $active = (array) get_option('active_plugins', []);
    $isActive = in_array('rolepod-wp/rolepod-wp.php', $active, true);
    if ($isActive && is_file($mainFile) && !is_file($mainDisabled)) {
        $mainProbablyAlive = true;
    }

    // If main plugin file is present, try to read its Version header
    // without including it (a fatal in main would WSOD this request).
    $mainVersion = null;
    if (is_file($mainFile)) {
        $head = (string) @file_get_contents($mainFile, false, null, 0, 4096);
        if ($head !== '' && preg_match('/^\s*\*\s*Version:\s*([0-9A-Za-z.\-]+)/m', $head, $m)) {
            $mainVersion = $m[1];
        }
    }

    $dispatchPath = defined('ROLEPOD_WP_VERSION') ? 'rest_api_init' : 'early_dispatch';

    $recent = get_transient(ROLEPOD_WP_GUARDIAN_FATALS_TRANSIENT);
    if (!is_array($recent)) {
        $recent = [];
    }

    $lastFatal = !empty($recent) ? end($recent) : null;

    $autohealEnabled = (bool) get_option(ROLEPOD_WP_GUARDIAN_AUTOHEAL_OPTION, false);
    $autohealLog = get_option(ROLEPOD_WP_GUARDIAN_AUTOHEAL_LOG_OPTION, []);
    if (!is_array($autohealLog)) {
        $autohealLog = [];
    }
    $fatalStreak = get_option(ROLEPOD_WP_GUARDIAN_STREAK_OPTION, null);

    return new WP_REST_Response([
        'ok' => true,
        'guardian_version' => ROLEPOD_WP_GUARDIAN_VERSION,
        // v2.8 boot-loop auto-heal state.
        'autoheal_enabled' => $autohealEnabled,
        'autoheal_threshold' => ROLEPOD_WP_GUARDIAN_AUTOHEAL_THRESHOLD,
        'autoheal_log' => array_values($autohealLog),
        'fatal_streak' => is_array($fatalStreak) ? $fatalStreak : null,
        // True iff main plugin file exists, is active, and not .disabled.
        // Recent fatals in recent_fatals indicate if it actually loads
        // cleanly on a normal request.
        'main_alive' => $mainProbablyAlive,
        'main_version' => $mainVersion,
        'main_active_in_options' => $isActive,
        'main_file_disabled' => is_file($mainDisabled),
        // Which guardian dispatch path served this response:
        //   "rest_api_init"  = WP fully booted, main plugin loaded normally.
        //   "early_dispatch" = muplugins_loaded shortcircuit (we never
        //                      loaded plugins/theme for this request).
        'dispatch_path' => $dispatchPath,
        'safe_mode' => (bool) get_option(ROLEPOD_WP_GUARDIAN_SAFE_MODE_OPTION, false),
        'recent_fatals' => array_values($recent),
        'last_fatal' => $lastFatal,
        'wp_version' => function_exists('get_bloginfo') ? get_bloginfo('version') : null,
        'php_version' => PHP_VERSION,
        'siteurl' => function_exists('get_option') ? (string) get_option('siteurl') : null,
    ], 200);
}

/**
 * POST /disable-plugin — rename plugin file → .disabled, WP skips next boot.
 */
function rolepod_guardian_disable_plugin(WP_REST_Request $req): WP_REST_Response
{
    $plugin = trim((string) $req->get_param('plugin'));
    if ($plugin === '') {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'PLUGIN_REQUIRED'], 400);
    }

    $pluginFile = rolepod_guardian_resolve_plugin_file($plugin);
    if ($pluginFile === null) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'PLUGIN_NOT_FOUND', 'plugin' => $plugin], 404);
    }

    $abs = WP_PLUGIN_DIR . '/' . $pluginFile;
    if (!is_file($abs)) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'PLUGIN_FILE_MISSING', 'path' => $abs], 404);
    }

    $disabled = $abs . '.disabled';
    if (is_file($disabled)) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'ALREADY_DISABLED', 'path' => $disabled], 409);
    }

    if (!@rename($abs, $disabled)) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'RENAME_FAILED', 'path' => $abs], 500);
    }

    // Best-effort: deactivate via WP API so active_plugins option is clean.
    if (function_exists('deactivate_plugins')) {
        @deactivate_plugins($pluginFile, true);
    }

    return new WP_REST_Response([
        'ok' => true,
        'disabled_file' => $disabled,
        'original_file' => $abs,
        'plugin' => $pluginFile,
    ], 200);
}

/**
 * POST /disable-file — rename arbitrary scoped file → .disabled.
 */
function rolepod_guardian_disable_file(WP_REST_Request $req): WP_REST_Response
{
    $path = (string) $req->get_param('path');
    $abs = rolepod_guardian_scope_path($path);
    if ($abs === null) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'OUT_OF_SCOPE', 'path' => $path], 403);
    }
    if (!is_file($abs)) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'NOT_FOUND', 'path' => $abs], 404);
    }
    $dest = $abs . '.disabled';
    if (is_file($dest)) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'ALREADY_DISABLED', 'path' => $dest], 409);
    }
    if (!@rename($abs, $dest)) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'RENAME_FAILED'], 500);
    }
    return new WP_REST_Response(['ok' => true, 'src' => $abs, 'dest' => $dest], 200);
}

/**
 * POST /restore-file — rename <path>.disabled → <path>. Accepts either form.
 */
function rolepod_guardian_restore_file(WP_REST_Request $req): WP_REST_Response
{
    $raw = (string) $req->get_param('path');
    $disabledForm = str_ends_with($raw, '.disabled') ? $raw : $raw . '.disabled';
    $activeForm = preg_replace('/\.disabled$/', '', $disabledForm);

    $absDisabled = rolepod_guardian_scope_path($disabledForm);
    $absActive = rolepod_guardian_scope_path($activeForm);
    if ($absDisabled === null || $absActive === null) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'OUT_OF_SCOPE'], 403);
    }
    if (!is_file($absDisabled)) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'DISABLED_FILE_NOT_FOUND', 'path' => $absDisabled], 404);
    }
    if (is_file($absActive)) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'ACTIVE_FILE_EXISTS', 'path' => $absActive], 409);
    }
    if (!@rename($absDisabled, $absActive)) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'RENAME_FAILED'], 500);
    }
    return new WP_REST_Response(['ok' => true, 'src' => $absDisabled, 'dest' => $absActive], 200);
}

/**
 * POST /restore-snapshot — untar a previously-captured theme snapshot.
 */
function rolepod_guardian_restore_snapshot(WP_REST_Request $req): WP_REST_Response
{
    $snapshotPath = (string) $req->get_param('snapshot_path');
    if (!class_exists('PharData')) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'PHAR_UNAVAILABLE'], 503);
    }

    $uploadDir = wp_upload_dir();
    if (!is_array($uploadDir) || empty($uploadDir['basedir'])) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'UPLOAD_DIR_UNAVAILABLE'], 500);
    }
    $snapshotsRoot = realpath(trailingslashit((string) $uploadDir['basedir']) . 'rolepod-wp-theme-snapshots');
    if ($snapshotsRoot === false) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'SNAPSHOTS_DIR_MISSING'], 404);
    }

    $abs = realpath($snapshotPath);
    if ($abs === false || strpos($abs, $snapshotsRoot . '/') !== 0) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'SNAPSHOT_OUT_OF_SCOPE'], 403);
    }
    if (!is_file($abs) || !str_ends_with($abs, '.tar.gz')) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_SNAPSHOT'], 400);
    }

    // Filename convention: <slug>-YYYYMMDD-HHMMSS.tar.gz — ts contains a
    // hyphen between date and time, so naive explode('-') + array_pop()
    // strips only the time half. Use the same regex as the main companion
    // ThemeSnapshot::restore() to capture slug greedily before the ts.
    $base = basename($abs);
    if (!preg_match('/^([a-zA-Z0-9_\-]+)-\d{8}-\d{6}\.tar\.gz$/', $base, $m)) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'SNAPSHOT_NAME_MALFORMED'], 400);
    }
    $slug = $m[1];

    $themesDir = WP_CONTENT_DIR . '/themes';
    $target = $themesDir . '/' . $slug;

    try {
        $phar = new \PharData($abs);
        $phar->extractTo($themesDir, null, true);
    } catch (\Throwable $t) {
        return new WP_REST_Response([
            'ok' => false,
            'error_code' => 'EXTRACT_FAILED',
            'error_message' => $t->getMessage(),
        ], 500);
    }

    return new WP_REST_Response([
        'ok' => true,
        'restored_theme' => $slug,
        'snapshot_path' => $abs,
        'target_dir' => $target,
    ], 200);
}

/**
 * GET /list-changes — read ledger DB. Useful pre-panic to inspect what's
 * recent. Works only if DB alive (otherwise nothing here would work either).
 */
function rolepod_guardian_list_changes(WP_REST_Request $req): WP_REST_Response
{
    global $wpdb;
    $table = $wpdb->prefix . 'rolepod_wp_changes';
    $limit = (int) ($req->get_param('limit') ?? 50);
    $limit = max(1, min(500, $limit));

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($exists !== $table) {
        return new WP_REST_Response(['ok' => true, 'changes' => [], 'note' => 'ledger table not yet installed'], 200);
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, audit_id, category, subcategory, target_descriptor, applied, reversible, source_tool, source_session, created_at FROM {$table} ORDER BY id DESC LIMIT %d",
        $limit
    ), ARRAY_A);

    return new WP_REST_Response([
        'ok' => true,
        'changes' => is_array($rows) ? $rows : [],
        'count' => is_array($rows) ? count($rows) : 0,
    ], 200);
}

/**
 * POST /safe-mode — toggle the ROLEPOD_WP_SAFE_MODE flag. Main plugin (when
 * alive) should refuse risky ops when this is on.
 */
function rolepod_guardian_safe_mode(WP_REST_Request $req): WP_REST_Response
{
    $enabled = (bool) $req->get_param('enabled');
    update_option(ROLEPOD_WP_GUARDIAN_SAFE_MODE_OPTION, $enabled, false);
    return new WP_REST_Response([
        'ok' => true,
        'safe_mode' => $enabled,
    ], 200);
}

/**
 * POST /clear-fatals — clear the recent-fatals transient after manual review.
 */
function rolepod_guardian_clear_fatals(): WP_REST_Response
{
    delete_transient(ROLEPOD_WP_GUARDIAN_FATALS_TRANSIENT);
    return new WP_REST_Response(['ok' => true], 200);
}

/**
 * POST /autoheal — toggle boot-loop auto-heal. When turning it off we also
 * clear any in-progress streak so a re-enable starts clean.
 */
function rolepod_guardian_autoheal_toggle(WP_REST_Request $req): WP_REST_Response
{
    $enabled = (bool) $req->get_param('enabled');
    update_option(ROLEPOD_WP_GUARDIAN_AUTOHEAL_OPTION, $enabled, false);
    if (!$enabled) {
        delete_option(ROLEPOD_WP_GUARDIAN_STREAK_OPTION);
    }
    return new WP_REST_Response(['ok' => true, 'autoheal' => $enabled], 200);
}

// -----------------------------------------------------------------------------
// Early-dispatch (v2.6.1)
// -----------------------------------------------------------------------------

/**
 * Manual REST dispatch from muplugins_loaded. Authenticates via WP
 * Application Password directly (Basic auth header), registers guardian
 * routes, dispatches to the handler, emits JSON, exits.
 */
function rolepod_guardian_early_dispatch(): void
{
    if (!defined('REST_REQUEST')) {
        define('REST_REQUEST', true);
    }

    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';

    $route = rolepod_guardian_extract_route($uri);
    if ($route === null) {
        rolepod_guardian_emit(404, ['code' => 'rest_no_route', 'message' => 'no recovery route in URI']);
    }

    // Authenticate via Application Password (Basic auth).
    $user = rolepod_guardian_authenticate();
    if ($user === null) {
        header('WWW-Authenticate: Basic realm="WordPress Recovery"');
        rolepod_guardian_emit(401, ['code' => 'rest_not_logged_in', 'message' => 'authentication required']);
    }
    wp_set_current_user($user->ID);

    if (!current_user_can('manage_options')) {
        rolepod_guardian_emit(403, ['code' => 'rest_forbidden', 'message' => 'manage_options required']);
    }

    // Manually create a WP_REST_Server WITHOUT firing rest_api_init action.
    // v2.6.7 called rest_get_server() which fires rest_api_init →
    // create_initial_rest_routes() in WP core → uses WP_Site_Health class
    // which isn't loaded yet at muplugins_loaded. FATAL: "Class
    // WP_Site_Health not found" on Hostinger PHP 8.1 / WP 7.0.
    //
    // Skip the action chain by instantiating WP_REST_Server directly +
    // registering ONLY our guardian routes. Plugins + theme + WP-core
    // REST routes are never registered for this request — that's fine
    // because we're explicitly in recovery mode and don't need them.
    require_once ABSPATH . WPINC . '/rest-api.php';
    require_once ABSPATH . WPINC . '/rest-api/class-wp-rest-server.php';
    require_once ABSPATH . WPINC . '/rest-api/class-wp-rest-request.php';
    require_once ABSPATH . WPINC . '/rest-api/class-wp-rest-response.php';
    $server = new \WP_REST_Server();
    rolepod_guardian_register_routes($server);

    $request = new WP_REST_Request($method, $route);

    // Query params (skip the rest_route marker itself).
    foreach ($_GET as $key => $value) {
        if ($key === 'rest_route') continue;
        $request->set_param((string) $key, $value);
    }

    // Body params (JSON).
    if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
        $body = file_get_contents('php://input');
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    $request->set_param((string) $key, $value);
                }
                $request->set_body($body);
                $request->set_header('Content-Type', 'application/json');
            }
        }
    }

    $response = $server->dispatch($request);
    $status = $response instanceof WP_REST_Response ? $response->get_status() : 200;
    $data = $response instanceof WP_REST_Response ? $response->get_data() : $response;

    rolepod_guardian_emit($status, $data);
}

/**
 * Parse REST route from request URI. Returns "/wplab-recovery/v1/<action>"
 * or null if no match.
 */
function rolepod_guardian_extract_route(string $uri): ?string
{
    // /wp-json/wplab-recovery/v1/status (and beyond)
    if (preg_match('#/wp-json(/wplab-recovery/v1/[A-Za-z0-9_\-/]+)#', $uri, $m)) {
        return $m[1];
    }
    // ?rest_route=/wplab-recovery/v1/status
    if (preg_match('#rest_route=(/wplab-recovery/v1/[A-Za-z0-9_\-/]+)#', $uri, $m)) {
        return urldecode($m[1]);
    }
    // url-encoded form
    if (preg_match('#rest_route=(%2Fwplab-recovery%2Fv1%2F[A-Za-z0-9_\-/]+)#', $uri, $m)) {
        return urldecode($m[1]);
    }
    return null;
}

/**
 * Manual Application Password auth (Basic auth header). Returns WP_User on
 * success, null on failure. Bypasses normal init flow because we're at
 * muplugins_loaded — well before init.
 *
 * Auth header sources, in order: PHP_AUTH_USER (Apache mod_php),
 * HTTP_AUTHORIZATION (FastCGI/Nginx), REDIRECT_HTTP_AUTHORIZATION
 * (some Apache RewriteRule setups).
 *
 * Uses WP_Application_Passwords::validate_application_password — the
 * lowest-level validation that bypasses `wp_is_application_passwords_available()`
 * (which a security plugin may have disabled). This is intentional for
 * recovery: we want a working escape hatch even if Application Passwords
 * are disabled site-wide.
 */
function rolepod_guardian_authenticate(): ?\WP_User
{
    [$user, $pass] = rolepod_guardian_extract_basic_auth();
    if ($user === '' || $pass === '') {
        return null;
    }

    // At muplugins_loaded, pluggable.php is NOT yet required by WP. It
    // provides get_user_by(), wp_check_password(), wp_set_current_user()
    // and friends. Require it explicitly before use.
    if (!function_exists('get_user_by') || !function_exists('wp_check_password')) {
        require_once ABSPATH . WPINC . '/pluggable.php';
    }

    // wp_authenticate_application_password() (wp-includes/user.php) is
    // the WP-core entry point for Application Password validation. It
    // handles all the password-normalization quirks (trim, NBSP →
    // space, strip whitespace) AND understands WP 7.0's new `$generic$`
    // hash format. v2.6.5 tried to inline the iterate-and-check pattern
    // but missed the normalization step — passwords were never matched
    // against hashes because WP 7.0 stores hash of NORMALIZED password
    // but we passed the raw form. Direct delegation avoids re-implementing
    // every quirk.
    //
    // Signature: wp_authenticate_application_password($input_user, $username, $password)
    // Returns: WP_User on success, WP_Error on failure, or $input_user
    // unchanged if AppPasswords path doesn't apply. We pass null so any
    // non-null return is meaningful.
    if (function_exists('wp_authenticate_application_password')) {
        $result = wp_authenticate_application_password(null, $user, $pass);
        if ($result instanceof \WP_User) {
            return $result;
        }
    }

    // Fallback for local dev / CI smoke tests that authenticate with the
    // plain login password (no Application Password generated).
    $wp_user = is_email($user) ? get_user_by('email', $user) : get_user_by('login', $user);
    if ($wp_user && wp_check_password($pass, $wp_user->user_pass, $wp_user->ID)) {
        return $wp_user;
    }

    return null;
}

/**
 * Extract username + password from request, handling all common
 * server-variable layouts.
 *
 * @return array{0:string,1:string} [user, pass] — empty strings if missing.
 */
function rolepod_guardian_extract_basic_auth(): array
{
    $user = isset($_SERVER['PHP_AUTH_USER']) ? (string) $_SERVER['PHP_AUTH_USER'] : '';
    $pass = isset($_SERVER['PHP_AUTH_PW']) ? (string) $_SERVER['PHP_AUTH_PW'] : '';
    if ($user !== '' && $pass !== '') {
        return [$user, $pass];
    }

    $authHeader = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = (string) $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        // Some Apache configs strip Authorization unless rewritten.
        $authHeader = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $authHeader = (string) $value;
                    break;
                }
            }
        }
    }

    if ($authHeader !== '' && stripos($authHeader, 'Basic ') === 0) {
        $decoded = base64_decode(substr($authHeader, 6), true);
        if (is_string($decoded) && strpos($decoded, ':') !== false) {
            [$user, $pass] = explode(':', $decoded, 2);
            return [(string) $user, (string) $pass];
        }
    }

    return ['', ''];
}

/**
 * Emit JSON response + exit. Sends proper status header.
 */
function rolepod_guardian_emit(int $status, $data): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Rolepod-Guardian: ' . ROLEPOD_WP_GUARDIAN_VERSION);
        http_response_code($status);
    }
    echo wp_json_encode($data);
    exit;
}

// -----------------------------------------------------------------------------
// Helpers (inlined — no main-plugin autoload dependency)
// -----------------------------------------------------------------------------

/**
 * Map a plugin identifier (slug OR "slug/file.php") to its main file path
 * relative to WP_PLUGIN_DIR.
 */
function rolepod_guardian_resolve_plugin_file(string $plugin): ?string
{
    // Already in "slug/file.php" form?
    if (strpos($plugin, '/') !== false) {
        return $plugin;
    }
    // Try slug/slug.php convention.
    $candidate = $plugin . '/' . $plugin . '.php';
    if (is_file(WP_PLUGIN_DIR . '/' . $candidate)) {
        return $candidate;
    }
    // Scan via get_plugins() if available.
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (function_exists('get_plugins')) {
        $all = get_plugins();
        foreach ($all as $file => $_) {
            if (strpos($file, $plugin . '/') === 0 || basename(dirname($file)) === $plugin) {
                return $file;
            }
        }
    }
    return null;
}

/**
 * Resolve relative path to absolute, then verify it sits under one of:
 *   wp-content/{plugins,themes,uploads,mu-plugins}, or wp-config.php.
 * Returns absolute path or null if out of scope.
 */
function rolepod_guardian_scope_path(string $relative): ?string
{
    $abs = ($relative !== '' && $relative[0] === '/') ? $relative : ABSPATH . ltrim($relative, '/');
    $parent = dirname($abs);
    $real = realpath($parent);
    if ($real === false) {
        return null;
    }
    $abs = rtrim($real, '/') . '/' . basename($abs);

    $allowed = [
        realpath(WP_CONTENT_DIR . '/plugins'),
        realpath(WP_CONTENT_DIR . '/themes'),
        realpath(WP_CONTENT_DIR . '/uploads'),
        realpath(WP_CONTENT_DIR . '/mu-plugins'),
    ];
    foreach ($allowed as $root) {
        if ($root === false) {
            continue;
        }
        if (strpos($abs, $root . '/') === 0) {
            return $abs;
        }
    }
    $wpConfig = realpath(ABSPATH . 'wp-config.php');
    if ($wpConfig !== false && $abs === $wpConfig) {
        return $abs;
    }
    return null;
}
