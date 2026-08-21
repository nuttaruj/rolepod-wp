<?php
declare(strict_types=1);

/**
 * Standalone test for SetupWizard::render()'s Post/Redirect/Get handling.
 * Run: php tests/Unit/setup-wizard-redirect-test.php
 *
 * Regression: the wizard used to call wp_safe_redirect() and then exit
 * unconditionally after handling a POST. On a site where another plugin had
 * already flushed output — or where a `wp_redirect` filter cancelled the
 * redirect — no Location header could be sent, so the exit served a completely
 * blank page and every step past "Choose path" looked dead. Opening the same
 * step by GET rendered fine, which is what pinned it to the redirect.
 *
 * headers_sent() and wp_safe_redirect() are stubbed in the Rolepod\Wp\Admin
 * namespace: PHP resolves unqualified calls to the current namespace first and
 * only then falls back to global, so these win inside SetupWizard.
 */

namespace Rolepod\Wp\Admin {

    function headers_sent(): bool
    {
        return (bool) ($GLOBALS['__headers_sent'] ?? false);
    }

    function wp_safe_redirect(string $location): bool
    {
        $GLOBALS['__redirect_to'] = $location;
        return (bool) ($GLOBALS['__redirect_returns'] ?? true);
    }
}

namespace {

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}
if (!defined('ROLEPOD_WP_VERSION')) {
    define('ROLEPOD_WP_VERSION', 'test');
}
if (!defined('WPMU_PLUGIN_DIR')) {
    define('WPMU_PLUGIN_DIR', sys_get_temp_dir() . '/mu-plugins');
}
if (!defined('ROLEPOD_WP_DIR')) {
    define('ROLEPOD_WP_DIR', dirname(__DIR__, 2) . '/');
}

// ── WP function stubs ────────────────────────────────────────────────────────
function current_user_can($cap): bool { return true; }
function wp_die($msg = ''): void { throw new RuntimeException('wp_die: ' . $msg); }
function get_current_user_id(): int { return 7; }
function sanitize_key($v): string { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); }
function sanitize_text_field($v): string { return trim((string) $v); }
function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'good-nonce' ? 1 : false; }
function wp_nonce_field($action, $name): void { echo '<input type="hidden" name="' . $name . '" value="good-nonce">'; }
function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . $path; }
function esc_html($v): string { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_attr($v): string { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_url($v): string { return (string) $v; }
function checked($a, $b = true, $echo = true): string { $r = ((string) $a === (string) $b) ? ' checked' : ''; if ($echo) { echo $r; } return $r; }
function get_option($name, $default = false) { return $GLOBALS['__options'][$name] ?? $default; }
function get_transient($key) { return $GLOBALS['__transients'][$key] ?? false; }
function set_transient($key, $value, $ttl = 0): bool { $GLOBALS['__transients'][$key] = $value; return true; }
function delete_transient($key): bool { unset($GLOBALS['__transients'][$key]); return true; }
function wp_generate_password(int $len = 12, bool $special = true): string { return str_repeat('a', $len); }
function get_userdata($id) { return null; }
function wp_get_current_user() { return null; }
function apply_filters($tag, $value, ...$args) { return $value; }
function add_action(...$a): bool { return true; }
function add_filter(...$a): bool { return true; }
function is_ssl(): bool { return true; }
function home_url($path = ''): string { return 'https://example.test' . $path; }
function get_bloginfo($what = ''): string { return 'Test Site'; }
function add_option($name, $value = '', $deprecated = '', $autoload = 'yes'): bool { $GLOBALS['__options'][$name] = $value; return true; }
function update_option($name, $value, $autoload = null): bool { $GLOBALS['__options'][$name] = $value; return true; }
function delete_option($name): bool { unset($GLOBALS['__options'][$name]); return true; }
function add_menu_page(...$a): string { return 'toplevel_page_rolepod-wp'; }
function add_submenu_page(...$a): string { return 'rolepod-wp_page_sub'; }
function wp_enqueue_script(...$a): void {}
function wp_enqueue_style(...$a): void {}
function wp_mkdir_p($dir): bool { return true; }
function is_admin(): bool { return true; }
function esc_like($v): string { return (string) $v; }
function esc_textarea($v): string { return htmlspecialchars((string) $v, ENT_QUOTES); }
function get_site_url($blog_id = null, $path = '', $scheme = null): string { return 'https://example.test' . $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }

require_once dirname(__DIR__, 2) . '/src/Config.php';
require_once dirname(__DIR__, 2) . '/src/Guardian.php';
require_once dirname(__DIR__, 2) . '/src/Security/PairToken.php';
require_once dirname(__DIR__, 2) . '/src/Admin/Menu.php';
require_once dirname(__DIR__, 2) . '/src/Admin/Shell.php';
require_once dirname(__DIR__, 2) . '/src/Admin/SetupWizard.php';

use Rolepod\Wp\Admin\SetupWizard;

$failures = 0;

// render() calls exit when it redirects. Every case below is set up so it must
// NOT redirect, so an exit means the regression is back — and a bare exit
// leaves status 0, which would read as a pass. Fail loudly instead.
$GLOBALS['__completed'] = false;
register_shutdown_function(static function (): void {
    if (!$GLOBALS['__completed']) {
        fwrite(STDERR, "\nFAIL: render() exited mid-test — a real request would have served a blank page\n");
        exit(1);
    }
});

function check(string $label, bool $ok): void
{
    global $failures;
    if ($ok) {
        echo "  ok   $label\n";
    } else {
        echo "  FAIL $label\n";
        $failures++;
    }
}

/** Drive a POST through render() and capture whatever it emitted. */
function renderPost(array $post, bool $headersSent, bool $redirectReturns): string
{
    $GLOBALS['__headers_sent']     = $headersSent;
    $GLOBALS['__redirect_returns'] = $redirectReturns;
    $GLOBALS['__redirect_to']      = null;
    $GLOBALS['__transients']       = [];
    $GLOBALS['__options']          = [];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = $post;
    $_GET  = ['step' => '0', 'path' => 'quick'];

    ob_start();
    SetupWizard::render();
    return (string) ob_get_clean();
}

echo "SetupWizard PRG fallback\n";

// ── 1. Output already flushed: must not die blank ────────────────────────────
$html = renderPost(
    ['_rp_setup_nonce' => 'good-nonce', 'rp_action' => 'choose_path', 'path' => 'quick'],
    true,
    true
);
check('renders something when headers are already sent', trim($html) !== '');
check('advances to the Generate-token step inline', str_contains($html, 'Generate a pair token'));
check('does not fall back to the path chooser', !str_contains($html, 'Connect your AI CLI'));

// ── 2. A wp_redirect filter cancelled the redirect ───────────────────────────
$html = renderPost(
    ['_rp_setup_nonce' => 'good-nonce', 'rp_action' => 'choose_path', 'path' => 'manual'],
    false,
    false
);
check('renders when wp_safe_redirect() returns false', trim($html) !== '');
check('honours the chosen manual path', str_contains($html, 'Application Password'));
check('still attempted the redirect first', str_contains((string) ($GLOBALS['__redirect_to'] ?? ''), 'step=1'));
check('redirect target carries the chosen path', str_contains((string) ($GLOBALS['__redirect_to'] ?? ''), 'path=manual'));

// ── 3. mint_token over a broken redirect still mints + shows the token ───────
$html = renderPost(
    ['_rp_setup_nonce' => 'good-nonce', 'rp_action' => 'mint_token'],
    true,
    true
);
check('mint_token reaches the Connect-AI-CLI step inline', str_contains($html, 'rolepod_wp_pair_') || str_contains($html, 'Pair token'));

// ── 4. A bad nonce changes nothing ───────────────────────────────────────────
$html = renderPost(
    ['_rp_setup_nonce' => 'bad', 'rp_action' => 'choose_path', 'path' => 'quick'],
    true,
    true
);
check('rejects a bad nonce and stays on step 0', str_contains($html, 'Connect your AI CLI'));

$GLOBALS['__completed'] = true;
echo $failures === 0 ? "\nPASS\n" : "\nFAIL ($failures)\n";
exit($failures === 0 ? 0 : 1);

}
