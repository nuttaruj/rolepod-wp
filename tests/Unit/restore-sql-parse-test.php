<?php
declare(strict_types=1);

/**
 * Regression: RestoreEngine::stepDb() must run the DROP TABLE that follows a
 * "-- Table: x" comment. The comment used to glue onto the statement, which
 * then began with "--" and was skipped, so restoring over existing tables
 * left them untouched.
 * Run: php tests/Unit/restore-sql-parse-test.php
 */

require __DIR__ . '/../../src/Backup/RestoreEngine.php';

use Rolepod\Wp\Backup\RestoreEngine;

final class FakeWpdb
{
    public string $last_error = '';
    /** @var string[] */
    public array $queries = [];

    public function query(string $q)
    {
        $this->last_error = '';
        $this->queries[] = $q;
        return 1;
    }
}

$sql = "-- rolepod-wp backup x\n-- 2026-11-23\nSET FOREIGN_KEY_CHECKS=0;\n\n"
    . "-- Table: wp_options\nDROP TABLE IF EXISTS `wp_options`;\nCREATE TABLE `wp_options` (\n  `option_id` bigint(20) NOT NULL\n);\n"
    . "INSERT INTO `wp_options` (`option_id`) VALUES\n(1),\n(2);\n\n"
    . "-- Table: wp_posts\nDROP TABLE IF EXISTS `wp_posts`;\n";
$tmp = tempnam(sys_get_temp_dir(), 'rpsql');
file_put_contents($tmp, $sql);

$GLOBALS['wpdb'] = $db = new FakeWpdb();
$job = [
    'components' => ['db' => true, 'files' => false],
    'search_replace' => [],
    'db' => ['sql_tmp' => $tmp, 'offset' => 0, 'statements' => 0, 'bytes' => strlen($sql)],
];
$step = new ReflectionMethod(RestoreEngine::class, 'stepDb');
$job = $step->invoke(null, $job, microtime(true));
unlink($tmp);

$want = [
    'SET FOREIGN_KEY_CHECKS=0;',
    'DROP TABLE IF EXISTS `wp_options`;',
    "CREATE TABLE `wp_options` (\n  `option_id` bigint(20) NOT NULL\n);",
    "REPLACE INTO `wp_options` (`option_id`) VALUES\n(1),\n(2);",
    'DROP TABLE IF EXISTS `wp_posts`;',
];
$fails = 0;
if ($db->queries !== $want) {
    $fails++;
    fwrite(STDERR, "FAIL statements\n  got:  " . var_export($db->queries, true) . "\n  want: " . var_export($want, true) . "\n");
}
if ($job['db']['statements'] !== 5) {
    $fails++;
    fwrite(STDERR, "FAIL statement count: {$job['db']['statements']}\n");
}
if ($job['stage'] !== 'done') {
    $fails++;
    fwrite(STDERR, "FAIL stage: {$job['stage']}\n");
}

echo $fails === 0 ? "OK restore-sql-parse\n" : "{$fails} failed\n";
exit($fails === 0 ? 0 : 1);
