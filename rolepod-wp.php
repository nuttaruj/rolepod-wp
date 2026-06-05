<?php
/**
 * Plugin Name:       Rolepod for WordPress
 * Plugin URI:        https://github.com/nuttaruj/rolepod-wp
 * Description:       The WordPress arm of the Rolepod ecosystem (https://github.com/nuttaruj/rolepod). Exposes guarded REST endpoints so AI coding agents (Claude Code / Cursor / Codex / Gemini) — driven by the rolepod-wplab MCP server — can run runtime introspection, the one-click pair wizard, and (with explicit opt-in) execute-php on this WordPress install. Endpoints are OFF by default; enable per-feature in Settings → Rolepod for WordPress. v2.6 adds a mu-plugin recovery guardian that survives main-plugin parse/fatal errors.
 * Author:            nuttaruj
 * Author URI:        https://github.com/nuttaruj
 * Version:           2.18.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       rolepod-wp
 *
 * @package rolepod-wp
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('ROLEPOD_WP_VERSION', '2.18.1');
define('ROLEPOD_WP_FILE', __FILE__);
define('ROLEPOD_WP_DIR', plugin_dir_path(__FILE__));

// REST namespace intentionally retained as `wplab/v1` for backward compatibility
// with the MCP client which already pins this path. The plugin slug rename
// (v2.0.0) does not break the REST contract; only the install location +
// option keys + PHP namespace change.
define('ROLEPOD_WP_REST_NAMESPACE', 'wplab/v1');

// Manual PSR-4 autoload — no composer required.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Rolepod\\Wp\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = ROLEPOD_WP_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

add_action('rest_api_init', static function (): void {
    \Rolepod\Wp\Endpoint\Handshake::register();
    \Rolepod\Wp\Endpoint\Introspect::register();
    \Rolepod\Wp\Endpoint\ExecutePhp::register();
    \Rolepod\Wp\Endpoint\WpCli::register();
    \Rolepod\Wp\Endpoint\FsRead::register();
    \Rolepod\Wp\Endpoint\FsWrite::register();
    \Rolepod\Wp\Endpoint\PhpSession::register();
    \Rolepod\Wp\Endpoint\RequestObserver::register();
    \Rolepod\Wp\Endpoint\Pair::register();
    // v2.3 — change ledger
    \Rolepod\Wp\Endpoint\Changes::register();
    // v2.4 — pre-write syntax check + theme snapshot/restore
    \Rolepod\Wp\Endpoint\SyntaxCheck::register();
    \Rolepod\Wp\Endpoint\ThemeSnapshot::register();
    // v2.5 — one-time admin login + file disable/enable + field-plugin adapters
    \Rolepod\Wp\Endpoint\OneTimeLogin::register();
    \Rolepod\Wp\Endpoint\FsRename::register();
    // v2.7 — direct wp_options access (bypass REST /wp/v2/settings allowlist)
    \Rolepod\Wp\Endpoint\Options::register();
    // v2.7.2 — SELECT-only DB query endpoint (bypass wp-cli `db query` shell-escape + {prefix} placeholder hazards)
    \Rolepod\Wp\Endpoint\DbQuery::register();
    // v2.11 — wplab Phase 2 surface: atomic batch write, fs primitives, Elementor introspection.
    \Rolepod\Wp\Endpoint\FsWriteBatch::register();
    \Rolepod\Wp\Endpoint\DirEnsure::register();
    \Rolepod\Wp\Endpoint\FsCopy::register();
    \Rolepod\Wp\Endpoint\FsList::register();
    \Rolepod\Wp\Endpoint\ElementorIntrospect::register();
    // v2.12 — wplab Phase 3.2: widget data-attr rehydrate, template-apply, async jobs.
    \Rolepod\Wp\Endpoint\ElementorWidgetAttribute::register();
    \Rolepod\Wp\Endpoint\ElementorTemplateApply::register();
    \Rolepod\Wp\Endpoint\JobCreate::register();
    \Rolepod\Wp\Endpoint\JobStatus::register();
    // v2.13 — site-owned agent skills (CPT-backed playbooks, progressive disclosure).
    \Rolepod\Wp\Endpoint\Skills::register();
    // v2.14 — bulk media optimize (recompress/downscale originals over a byte
    // threshold; dry-run default, backup + ledger on apply).
    \Rolepod\Wp\Endpoint\MediaOptimize::register();
    // v2.17 — throttled site backup (create + inspect; restore is a later phase).
    \Rolepod\Wp\Endpoint\Backup::register();
});

// v2.13 — register the skills CPT on init (register_post_type must run on `init`,
// not `rest_api_init`). Storage for site-owned agent playbooks.
add_action('init', [\Rolepod\Wp\Skills\Cpt::class, 'register']);

// v2.12 — widget-attr footer bridge. Emits a JSON+vanilla-JS pair on every
// singular() page that has stored widget attrs, so theme JS can read the
// map BEFORE its effects init scans for data-* attributes.
add_action('wp_footer', [\Rolepod\Wp\Endpoint\ElementorWidgetAttribute::class, 'emitBridgeFooter'], 5);

// v2.12.1 — patch Elementor free's section render so `_css_classes` set on
// section settings actually lands on the rendered <section> tag. Widget-
// level _css_classes already works; sections silently dropped. Without
// this, AI-built _elementor_data round-trips lose section-level CSS hooks.
\Rolepod\Wp\ElementorCompat::init();

// v2.12 — auto-install wp-content/private/.htaccess on first scoped use so
// the dev scratch zone is never publicly readable via HTTP.
add_action('rest_api_init', static function (): void {
    $priv = WP_CONTENT_DIR . '/private';
    $ht = $priv . '/.htaccess';
    if (is_dir($priv) && !is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n");
    }
}, 99);

// v2.13.1 — consolidated data namespace: all Rolepod runtime artifacts live
// under wp-content/uploads/rolepod-wp/ (backups, tmp, …). Ensure the dir
// exists with a deny-all .htaccess so backups/payloads are never web-readable.
add_action('rest_api_init', static function (): void {
    $base = trailingslashit((string) (wp_upload_dir()['basedir'] ?? WP_CONTENT_DIR . '/uploads')) . 'rolepod-wp';
    if (!is_dir($base)) {
        wp_mkdir_p($base);
    }
    $ht = $base . '/.htaccess';
    if (is_dir($base) && !is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n");
    }
}, 99);

// v2.5 — intercept ?rolepod_wp_otl=<token> on any request for the one-time
// admin login flow. Priority 1 so it runs before WP's main routing.
add_action('init', static function (): void {
    \Rolepod\Wp\Endpoint\OneTimeLogin::maybeIntercept();
}, 1);

// v2.8 — single top-level "Rolepod WP" menu with three submenus
// (Setup / Change Ledger / Settings). The three Admin\*Page classes still
// exist and own their respective render() methods; Menu::register() wires
// them into one consolidated nav.
add_action('admin_menu', static function (): void {
    \Rolepod\Wp\Admin\Menu::register();
});

// Conditional asset enqueue — bails out early on non-Rolepod admin screens
// so unrelated pages pay zero byte cost.
add_action('admin_enqueue_scripts', [\Rolepod\Wp\Admin\Menu::class, 'enqueueAssets']);

// Legacy URL redirects (v2.7 and earlier nested Rolepod under Settings/Tools).
add_action('admin_init', [\Rolepod\Wp\Admin\Menu::class, 'legacyRedirect']);

// v2.15 — throttled media-optimize background queue. Register the 1-minute
// cron schedule + the tick callback. The schedule is only actually used while a
// queue is running (Queue schedules/unschedules the event itself).
add_filter('cron_schedules', [\Rolepod\Wp\Media\Queue::class, 'registerSchedule']);
add_action(\Rolepod\Wp\Media\Queue::CRON_HOOK, [\Rolepod\Wp\Media\Queue::class, 'tick']);

// v2.17 — throttled backup engine cron tick (shares the 1-minute schedule).
add_action(\Rolepod\Wp\Backup\Engine::CRON_HOOK, [\Rolepod\Wp\Backup\Engine::class, 'tick']);
// v2.18 — throttled restore engine cron tick.
add_action(\Rolepod\Wp\Backup\RestoreEngine::CRON_HOOK, [\Rolepod\Wp\Backup\RestoreEngine::class, 'tick']);

// v2.9.0 — GitHub-based auto-updater. Polls releases/latest at the cadence
// WP polls the plugin update transient (default 12h); responds via the
// standard WP update notice + one-click upgrade button.
\Rolepod\Wp\Updater::init();

// v2.10.0 — WordPress 7.0 Abilities API bridge. Registers a curated subset
// of Rolepod tools so the native WP AI Client (Anthropic/OpenAI/Gemini
// providers shipped in core 7.0) can discover and invoke them from inside
// the WP admin without requiring the external rolepod-wplab MCP CLI.
// No-ops on WP < 7.0 — the Abilities API is detected at runtime via
// function_exists('wp_register_ability').
\Rolepod\Wp\Abilities\Bridge::init();

// Clear the GitHub release cache right after a successful self-upgrade so
// the next pageview sees the freshly-installed version, not the stale
// "update available" record.
add_action('upgrader_process_complete', static function ($upgrader, array $opts): void {
    if (($opts['action'] ?? '') !== 'update' || ($opts['type'] ?? '') !== 'plugin') {
        return;
    }
    $plugins = $opts['plugins'] ?? [];
    if (!is_array($plugins)) {
        return;
    }
    $self = plugin_basename(ROLEPOD_WP_FILE);
    if (in_array($self, $plugins, true)) {
        \Rolepod\Wp\Updater::clearCache();
    }
}, 10, 2);

// v2.3 — auto-install ledger schema on upgrade. register_activation_hook
// only fires on fresh activate; WP plugin UPDATE replaces files without
// re-activating. This hook fires on every request once and is a no-op when
// the schema is already at the current version.
//
// v2.6 — also re-installs the mu-plugin guardian on upgrade so users moving
// from 2.5 → 2.6 get the recovery layer without manual reactivation.
add_action('plugins_loaded', static function (): void {
    $current = (string) get_option(\Rolepod\Wp\Audit\ChangeLedger::TABLE_VERSION_OPTION, '');
    if ($current !== \Rolepod\Wp\Audit\ChangeLedger::TABLE_VERSION) {
        \Rolepod\Wp\Audit\ChangeLedger::install();
    }
    if (!\Rolepod\Wp\Guardian::isInstalledAtCurrentVersion()) {
        // Either not installed at all, or installed copy is from an older
        // plugin release (upgrade scenario). Re-copy to bring guardian to
        // the bundled version. Idempotent + cheap (single file copy).
        \Rolepod\Wp\Guardian::install();
    }
}, 5);

register_activation_hook(__FILE__, static function (): void {
    if (!current_user_can('activate_plugins')) {
        return;
    }
    add_option('rolepod_wp_version', ROLEPOD_WP_VERSION);
    // v2.8.9: plugin activation IS the consent gate for read + scoped-write
    // endpoints. The `endpoints_enabled` key is kept for back-compat with
    // any external reader but is no longer honored by Config::endpointsEnabled().
    // execute_php remains opt-in via its own Settings toggle.
    add_option('rolepod_wp_config', [
        'endpoints_enabled' => true,   // deprecated, retained for back-compat
        'execute_php_enabled' => false, // OFF by default — explicit opt-in on Settings page
        'production_hosts' => [],
    ]);
    // v2.3 — create the change-ledger table for AI-issued writes. Idempotent.
    \Rolepod\Wp\Audit\ChangeLedger::install();
    // v2.6 — install mu-plugin guardian for crash recovery.
    \Rolepod\Wp\Guardian::install();
});

register_deactivation_hook(__FILE__, static function (): void {
    // v2.6 — Option A tight coupling: deactivate = guardian also removed.
    // Predictable "off completely" UX. Re-activate copies it back.
    \Rolepod\Wp\Guardian::remove();
    // v2.15 — stop the media-optimize cron so no orphaned event survives
    // deactivation (queue state is left intact for a later re-activate).
    \Rolepod\Wp\Media\Queue::unschedule();
    // v2.17 — stop the backup cron too.
    \Rolepod\Wp\Backup\Engine::unschedule();
    // v2.18 — stop the restore cron too.
    \Rolepod\Wp\Backup\RestoreEngine::unschedule();
});
