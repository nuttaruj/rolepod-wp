<?php
declare(strict_types=1);

/**
 * Regression: the restore job must survive its own options table being
 * dropped and recreated by the SQL it restores. save() upserts the row
 * instead of update_option(), which only UPDATEs an existing row.
 * Run: php tests/Unit/restore-job-save-test.php
 */

require __DIR__ . '/../../src/Backup/RestoreEngine.php';

use Rolepod\Wp\Backup\RestoreEngine;

function maybe_serialize($v) { return is_array($v) ? serialize($v) : $v; }
function wp_cache_delete($k, $g = '') { return true; }

/** Options table as a map; a DROP clears it, like the restore does. */
final class FakeWpdb
{
    public string $options = 'wp_options';
    /** @var array<string,string> */
    public array $rows = [];

    public function prepare(string $q, ...$args): array
    {
        return [$q, $args];
    }

    public function query($q)
    {
        [$sql, $args] = $q;
        if (strpos($sql, 'INSERT INTO wp_options') === 0 && strpos($sql, 'ON DUPLICATE KEY UPDATE') !== false) {
            $this->rows[$args[0]] = $args[1];
            return 1;
        }
        return 0; // a plain UPDATE on a missing row
    }
}

$GLOBALS['wpdb'] = $db = new FakeWpdb();
$save = new ReflectionMethod(RestoreEngine::class, 'save');
$fails = 0;

$job = ['id' => 'rs_1', 'status' => 'running', 'db' => ['offset' => 10]];
$save->invoke(null, $job);
$db->rows = []; // the restore dropped and recreated wp_options
$job['db']['offset'] = 99;
$save->invoke(null, $job);

$got = isset($db->rows['rolepod_wp_restore_job']) ? unserialize($db->rows['rolepod_wp_restore_job']) : null;
if (($got['db']['offset'] ?? null) !== 99) {
    $fails++;
    fwrite(STDERR, "FAIL job lost after options table recreated: " . var_export($got, true) . "\n");
}

echo $fails === 0 ? "OK restore-job-save\n" : "{$fails} failed\n";
exit($fails === 0 ? 0 : 1);
