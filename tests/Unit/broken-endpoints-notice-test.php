<?php

declare(strict_types=1);

/**
 * Standalone test for Admin\BrokenEndpointsNotice.
 * Run: php tests/Unit/broken-endpoints-notice-test.php
 *
 * The registrar deliberately keeps the site up when an endpoint class will not
 * load, so a broken companion is invisible unless something says so. This
 * pins down that the notice appears, names the file an ignore-list entry needs,
 * and stays quiet the rest of the time.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

// ---------------------------------------------------------------- WP stubs --

$GLOBALS['__options'] = [];
$GLOBALS['__can'] = true;

if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        return $GLOBALS['__options'][$name] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null): bool
    {
        $GLOBALS['__options'][$name] = $value;

        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option($name): bool
    {
        unset($GLOBALS['__options'][$name]);

        return true;
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap): bool
    {
        return (bool) $GLOBALS['__can'];
    }
}
if (!function_exists('esc_html')) {
    function esc_html($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('add_action')) {
    function add_action($hook, $cb, $priority = 10, $args = 1): bool
    {
        $GLOBALS['__actions'][] = $hook;

        return true;
    }
}
// get_current_screen() intentionally undefined — the notice then treats every
// screen as relevant, which is the conservative branch.

require __DIR__ . '/../../src/Bootstrap/EndpointRegistrar.php';
require __DIR__ . '/../../src/Admin/BrokenEndpointsNotice.php';

use Rolepod\Wp\Admin\BrokenEndpointsNotice;
use Rolepod\Wp\Bootstrap\EndpointRegistrar;

// ------------------------------------------------------------------ harness --

$failures = 0;

function check(string $label, bool $ok): void
{
    global $failures;

    if (!$ok) {
        $failures++;
    }
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
}

function capture(): string
{
    ob_start();
    BrokenEndpointsNotice::render();

    return (string) ob_get_clean();
}

// ------------------------------------------------------- 1. healthy is quiet --

echo "a healthy install says nothing\n";

$GLOBALS['__options'] = [];
check('no output when nothing is broken', capture() === '');

// ------------------------------------------------------ 2. broken is reported --

echo "a broken endpoint is named, with the path to ignore-list\n";

$GLOBALS['__options'][EndpointRegistrar::OPTION] = [
    'Rolepod\\Wp\\Endpoint\\ExecutePhp' => 'class not found — file missing or empty',
];

$html = capture();

check('renders a notice', strpos($html, 'notice-warning') !== false);
check('names the endpoint', strpos($html, 'ExecutePhp') !== false);
check('carries the reason', strpos($html, 'file missing or empty') !== false);
check(
    'gives the WP-root-relative path for an ignore list',
    strpos($html, 'wp-content/plugins/rolepod-wp/src/Endpoint/ExecutePhp.php') !== false
);
check('mentions the scanner cause', stripos($html, 'malware scanner') !== false);
check('names Imunify360 and Wordfence', strpos($html, 'Imunify360') !== false && strpos($html, 'Wordfence') !== false);
check('says the rest of the site is fine', stripos($html, 'REST API, are unaffected') !== false);
check('counts them', strpos($html, '1 endpoint(s) could not start') !== false);

// ---------------------------------------------------- 3. non-admins see none --

echo "a non-administrator sees nothing\n";

$GLOBALS['__can'] = false;
check('no output without manage_options', capture() === '');
$GLOBALS['__can'] = true;

// ------------------------------------------------------------ 4. escaping --

echo "reasons are escaped\n";

$GLOBALS['__options'][EndpointRegistrar::OPTION] = [
    'Rolepod\\Wp\\Endpoint\\ExecutePhp' => 'RuntimeException: <script>alert(1)</script>',
];

$html = capture();
check('no raw script tag', strpos($html, '<script>') === false);
check('escaped form present', strpos($html, '&lt;script&gt;') !== false);

// --------------------------------------------------------------------- done --

echo "\n" . ($failures === 0 ? "PASS\n" : "FAIL ({$failures})\n");
exit($failures === 0 ? 0 : 1);
