<?php

declare(strict_types=1);

/**
 * Standalone test for Repair.
 * Run: php tests/Unit/repair-test.php
 *
 * `Repair::run()` needs WordPress's upgrader, so it is not exercised here. What
 * is covered is everything that decides WHAT gets downloaded and WHETHER a
 * download happens at all — the two places a mistake is expensive:
 *
 *  - the package URL must pin the installed version. `Updater` points at
 *    `releases/latest` because an update moves forward; a repair pulling
 *    `latest` would be a silent upgrade nobody asked for.
 *  - damage must be read off the filesystem, not from the stored option, so the
 *    button never downloads a release to fix files that are already fine.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

$fixture = sys_get_temp_dir() . '/rolepod-repair-test-' . bin2hex(random_bytes(4));
mkdir($fixture . '/src/Endpoint', 0777, true);

if (!defined('ROLEPOD_WP_VERSION')) {
    define('ROLEPOD_WP_VERSION', '2.24.3');
}
if (!defined('ROLEPOD_WP_DIR')) {
    define('ROLEPOD_WP_DIR', $fixture . '/');
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
if (!function_exists('delete_option')) {
    function delete_option($name): bool
    {
        unset($GLOBALS['__options'][$name]);

        return true;
    }
}

require __DIR__ . '/../../src/Bootstrap/EndpointRegistrar.php';
require __DIR__ . '/../../src/Repair.php';

use Rolepod\Wp\Bootstrap\EndpointRegistrar;
use Rolepod\Wp\Repair;

$failures = 0;

function check(string $label, bool $ok): void
{
    global $failures;

    if (!$ok) {
        $failures++;
    }
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
}

// ------------------------------------------------- 1. the URL is version-pinned

echo "the package URL pins the installed version\n";

$url = Repair::packageUrl();

check('points at this exact tag', strpos($url, '/releases/download/v2.24.3/') !== false);
check('is NOT the latest alias', strpos($url, '/releases/latest/') === false);
check('names the release asset', substr($url, -strlen('/rolepod-wp.zip')) === '/rolepod-wp.zip');

// ------------------------------------------- 2. damage is read off the disk

echo "damage is read from disk, not from the option\n";

$GLOBALS['__options'][EndpointRegistrar::OPTION] = [
    'Rolepod\\Wp\\Endpoint\\ExecutePhp' => 'class not found — file missing or empty',
];

// The option says broken, but the file is healthy: a stale option must not
// trigger a download.
file_put_contents($fixture . '/src/Endpoint/ExecutePhp.php', "<?php\nclass X {}\n");
check('a healthy file is not reported', Repair::damagedFiles() === []);
check('isNeeded() false while files are fine', Repair::isNeeded() === false);

// Zero bytes is the shape a scanner leaves behind — that IS damage.
file_put_contents($fixture . '/src/Endpoint/ExecutePhp.php', '');
$damaged = Repair::damagedFiles();
check('an emptied file is reported', array_keys($damaged) === ['Rolepod\\Wp\\Endpoint\\ExecutePhp']);
check('with a plugin-relative path', $damaged['Rolepod\\Wp\\Endpoint\\ExecutePhp'] === 'src/Endpoint/ExecutePhp.php');
check('isNeeded() true', Repair::isNeeded() === true);

// A file removed outright is damage too.
unlink($fixture . '/src/Endpoint/ExecutePhp.php');
check('a deleted file is reported', Repair::isNeeded() === true);

// ------------------------------------------------- 3. nothing broken, no work

echo "an undamaged install reports nothing\n";

$GLOBALS['__options'] = [];
check('empty option means nothing to repair', Repair::damagedFiles() === []);
check('isNeeded() false', Repair::isNeeded() === false);

// --------------------------------------------------------------------- done --

@unlink($fixture . '/src/Endpoint/ExecutePhp.php');
@rmdir($fixture . '/src/Endpoint');
@rmdir($fixture . '/src');
@rmdir($fixture);

echo "\n" . ($failures === 0 ? "PASS\n" : "FAIL ({$failures})\n");
exit($failures === 0 ? 0 : 1);
