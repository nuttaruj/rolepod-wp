<?php

declare(strict_types=1);

/**
 * Standalone test for Bootstrap\EndpointRegistrar.
 * Run: php tests/Unit/endpoint-registrar-test.php
 *
 * Regression: on dkdiecut.com a host malware scanner truncated
 * src/Endpoint/ExecutePhp.php to zero bytes. The autoloader `require`d the
 * empty file happily, the class stayed undefined, and the resulting
 * `Class not found` escaped the `rest_api_init` handler — every REST route on
 * the site returned 500 for thirteen days, core `wp/v2` routes included.
 *
 * Against the old code (a straight run of `Foo::register();` calls) case 1
 * below is a fatal, not a failure: that is the bug this file pins down.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

// ---------------------------------------------------------------- WP stubs --

$GLOBALS['__options'] = [];
$GLOBALS['__writes'] = 0;
$GLOBALS['__deletes'] = 0;

if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        return array_key_exists($name, $GLOBALS['__options'])
            ? $GLOBALS['__options'][$name]
            : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null): bool
    {
        $GLOBALS['__options'][$name] = $value;
        $GLOBALS['__writes']++;

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($name): bool
    {
        unset($GLOBALS['__options'][$name]);
        $GLOBALS['__deletes']++;

        return true;
    }
}

require __DIR__ . '/../../src/Bootstrap/EndpointRegistrar.php';

use Rolepod\Wp\Bootstrap\EndpointRegistrar;

// ------------------------------------------------------------- test doubles --

final class GoodEndpointA
{
    public static $registered = false;

    public static function register(): void
    {
        self::$registered = true;
    }
}

final class GoodEndpointB
{
    public static $registered = false;

    public static function register(): void
    {
        self::$registered = true;
    }
}

final class ThrowingEndpoint
{
    public static function register(): void
    {
        throw new RuntimeException('endpoint blew up');
    }
}

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

// ---------------------------------------------- 1. missing class is survived --

echo "missing class does not take the hook down\n";

$failed = EndpointRegistrar::registerAll([
    GoodEndpointA::class,
    'Rolepod\\Wp\\Endpoint\\ClassThatWasEmptiedByAScanner',
    GoodEndpointB::class,
]);

check('returned without throwing', true); // reaching here at all is the point
check('missing class reported', isset($failed['Rolepod\\Wp\\Endpoint\\ClassThatWasEmptiedByAScanner']));
check('reason mentions missing/empty', (bool) preg_match(
    '/missing or empty/',
    $failed['Rolepod\\Wp\\Endpoint\\ClassThatWasEmptiedByAScanner'] ?? ''
));
check('endpoint before the gap registered', GoodEndpointA::$registered);
check('endpoint AFTER the gap still registered', GoodEndpointB::$registered);
check('exactly one failure', count($failed) === 1);

// ------------------------------------------------- 2. throwing class is caught --

echo "a throwing register() is contained\n";

GoodEndpointB::$registered = false;
$failed = EndpointRegistrar::registerAll([
    ThrowingEndpoint::class,
    GoodEndpointB::class,
]);

check('throw recorded', isset($failed[ThrowingEndpoint::class]));
check('message preserved', strpos($failed[ThrowingEndpoint::class] ?? '', 'endpoint blew up') !== false);
check('later endpoint still registered', GoodEndpointB::$registered);

// ------------------------------------------------------- 3. option write policy --

echo "failures persist, but only when they change\n";

$GLOBALS['__options'] = [];
$GLOBALS['__writes'] = 0;
$GLOBALS['__deletes'] = 0;

EndpointRegistrar::recordFailures(['A' => 'gone']);
check('first failure written', $GLOBALS['__writes'] === 1);

EndpointRegistrar::recordFailures(['A' => 'gone']);
check('identical set does not write again', $GLOBALS['__writes'] === 1);

EndpointRegistrar::recordFailures(['A' => 'gone', 'B' => 'gone']);
check('changed set writes', $GLOBALS['__writes'] === 2);

check('broken() reads it back', EndpointRegistrar::broken() === ['A' => 'gone', 'B' => 'gone']);

EndpointRegistrar::recordFailures([]);
check('recovery clears the option', $GLOBALS['__deletes'] === 1);
check('broken() empty after recovery', EndpointRegistrar::broken() === []);

EndpointRegistrar::recordFailures([]);
check('healthy repeat does not write or delete', $GLOBALS['__writes'] === 2 && $GLOBALS['__deletes'] === 1);

// ------------------------------------ 4. every listed class has a real file --

echo "every class in the list maps to a non-empty file\n";

$root = dirname(__DIR__, 2);
$missing = [];
$empty = [];

foreach (EndpointRegistrar::classes() as $class) {
    $relative = substr($class, strlen('Rolepod\\Wp\\'));
    $path = $root . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (!is_file($path)) {
        $missing[] = $class;
        continue;
    }
    if (filesize($path) === 0) {
        $empty[] = $class;
    }
}

check('list is not empty', EndpointRegistrar::classes() !== []);
check('no listed class lacks a file: ' . implode(', ', $missing), $missing === []);
check('no listed class has an empty file: ' . implode(', ', $empty), $empty === []);

// --------------------------------------------------------------------- done --

echo "\n" . ($failures === 0 ? "PASS\n" : "FAIL ({$failures})\n");
exit($failures === 0 ? 0 : 1);
