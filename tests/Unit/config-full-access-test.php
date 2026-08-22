<?php
declare(strict_types=1);

/**
 * Standalone test for Config::fullAccess() — the one owner decision.
 * Run: php tests/Unit/config-full-access-test.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

$GLOBALS['__options'] = [];
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

require_once dirname(__DIR__, 2) . '/src/Config.php';

use Rolepod\Wp\Config;

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        $failures++;
    }
}

echo "Config::fullAccess\n";

// Fresh install: no config at all → guarded.
$GLOBALS['__options'] = [];
check('defaults to guarded (false) on a fresh install', Config::fullAccess() === false);

// Corrupt option shapes must fail closed.
$GLOBALS['__options']['rolepod_wp_config'] = 'not-an-array';
check('non-array config stays guarded', Config::fullAccess() === false);

$GLOBALS['__options']['rolepod_wp_config'] = ['execute_php_enabled' => false];
check('explicit OFF is guarded', Config::fullAccess() === false);

$GLOBALS['__options']['rolepod_wp_config'] = ['execute_php_enabled' => true];
check('toggle ON is full access', Config::fullAccess() === true);

// Stale keys from removed features must not matter.
$GLOBALS['__options']['rolepod_wp_config'] = [
    'execute_php_enabled' => true,
    'production_hosts' => ['dkdiecut.com'],
];
check('a stale production_hosts key changes nothing', Config::fullAccess() === true);

check('fullAccess always mirrors executePhpEnabled', Config::fullAccess() === Config::executePhpEnabled());

echo $failures === 0 ? "\nPASS\n" : "\nFAIL ($failures)\n";
exit($failures === 0 ? 0 : 1);
