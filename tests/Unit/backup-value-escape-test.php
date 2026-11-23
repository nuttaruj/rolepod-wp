<?php
declare(strict_types=1);

/**
 * Regression: Backup\Db::value() must not leak wpdb's per-request placeholder
 * escape ({64 hex}) into database.sql. Real wpdb::_real_escape() swaps every
 * `%` for that hash; a dump that keeps it restores as garbage on another site.
 * Run: php tests/Unit/backup-value-escape-test.php
 */

define('ARRAY_A', 'ARRAY_A');
require __DIR__ . '/../../src/Backup/Db.php';

use Rolepod\Wp\Backup\Db;

final class FakeWpdb
{
    private string $hash = '{5a6966e572a8e6e5c412ffdc8623734ca5d42e095842b4d9e99b99690840e027}';

    public function _real_escape(string $s): string
    {
        return str_replace('%', $this->hash, addslashes($s));
    }

    public function remove_placeholder_escape(string $s): string
    {
        return str_replace($this->hash, '%', $s);
    }
}

$GLOBALS['wpdb'] = new FakeWpdb();
$value = new ReflectionMethod(Db::class, 'value');

$fails = 0;
$cases = [
    ['/%postname%/', "'/%postname%/'"],
    ['50% off', "'50% off'"],
    ["%e0%b8%a7 it's", "'%e0%b8%a7 it\\'s'"],
    [null, 'NULL'],
];
foreach ($cases as [$in, $want]) {
    $got = $value->invoke(null, $in);
    if ($got !== $want) {
        $fails++;
        fwrite(STDERR, "FAIL value(" . var_export($in, true) . ")\n  got:  {$got}\n  want: {$want}\n");
    }
}

echo $fails === 0 ? "OK backup-value-escape\n" : "{$fails} failed\n";
exit($fails === 0 ? 0 : 1);
