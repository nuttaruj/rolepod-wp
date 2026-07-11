<?php
declare(strict_types=1);

/**
 * Standalone test for WpCli::isCatastrophic() via reflection.
 * Run: php tests/Unit/wpcli-catastrophic-test.php
 *
 * The method is pure (no WP calls), so we can load the class and invoke the
 * private method directly without booting WordPress. A few WP symbols are
 * stubbed so the file's `use` statements resolve at load.
 */

// Stub the WP REST classes referenced by type hints elsewhere in the file so
// the class body parses/loads. Only isCatastrophic() is exercised.
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct($data = null, $status = 200)
        {
        }
    }
}

require __DIR__ . '/../../src/Endpoint/WpCli.php';

use Rolepod\Wp\Endpoint\WpCli;

$m = new ReflectionMethod(WpCli::class, 'isCatastrophic');
$isCatastrophic = static fn(array $args): bool => $m->invoke(null, $args);

$failures = 0;
$count    = 0;
function check(string $label, $got, $want): void
{
    global $failures, $count;
    $count++;
    if ($got === $want) {
        echo "  ok   $label\n";
    } else {
        $failures++;
        echo "  FAIL $label — want " . json_encode($want) . ", got " . json_encode($got) . "\n";
    }
}

// Blocked
check('db reset', $isCatastrophic(['db', 'reset']), true);
check('db drop', $isCatastrophic(['db', 'drop']), true);
check('db clean', $isCatastrophic(['db', 'clean']), true);
check('core multisite-convert', $isCatastrophic(['core', 'multisite-convert']), true);
check('flag-prefixed --yes db reset', $isCatastrophic(['--yes', 'db', 'reset']), true);
check('--path=/x --quiet db drop', $isCatastrophic(['--path=/x', '--quiet', 'db', 'drop']), true);
check('mixed case DB Reset', $isCatastrophic(['DB', 'Reset']), true);

// Allowed
check('eval is NOT catastrophic (companion runs it)', $isCatastrophic(['eval', 'echo 1;']), false);
check('db query', $isCatastrophic(['db', 'query', 'SELECT 1']), false);
check('db export', $isCatastrophic(['db', 'export', 'dump.sql']), false);
check('plugin list', $isCatastrophic(['plugin', 'list']), false);
check('core version', $isCatastrophic(['core', 'version']), false);
check('empty', $isCatastrophic([]), false);

echo "\n$count checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
