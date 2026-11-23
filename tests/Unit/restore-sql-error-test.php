<?php
declare(strict_types=1);

/**
 * Regression: RestoreEngine::execStatement() must surface a failed SQL
 * statement instead of swallowing it (a restore once reported "done" while
 * every DROP / CREATE / INSERT had failed and the old tables stayed).
 * Run: php tests/Unit/restore-sql-error-test.php
 */

require __DIR__ . '/../../src/Backup/RestoreEngine.php';

use Rolepod\Wp\Backup\RestoreEngine;

final class FakeWpdb
{
    public string $last_error = '';
    /** @var array<string,string> statement prefix => error */
    public array $fail = [];

    public function query(string $q)
    {
        $this->last_error = '';
        foreach ($this->fail as $prefix => $err) {
            if (strpos($q, $prefix) === 0) {
                $this->last_error = $err;
                return false;
            }
        }
        return 1;
    }
}

$exec = new ReflectionMethod(RestoreEngine::class, 'execStatement');
$fails = 0;
function check(string $label, bool $ok): void
{
    global $fails;
    if (!$ok) {
        $fails++;
        fwrite(STDERR, "FAIL {$label}\n");
    }
}

$db = new FakeWpdb();
$db->fail = ['DROP TABLE' => "DROP command denied to user 'x'"];

// A good statement passes.
$threw = null;
try {
    $exec->invoke(null, $db, "INSERT INTO `wp_options` VALUES (1,'a','b','yes');");
} catch (RuntimeException $e) {
    $threw = $e;
}
check('good statement does not throw', $threw === null);

// A failed statement throws with the DB error and the statement head.
$threw = null;
try {
    $exec->invoke(null, $db, 'DROP TABLE IF EXISTS `wp_options`;');
} catch (RuntimeException $e) {
    $threw = $e;
}
check('failed statement throws', $threw !== null);
check('message carries db error', $threw !== null && strpos($threw->getMessage(), 'DROP command denied') !== false);
check('message carries statement head', $threw !== null && strpos($threw->getMessage(), 'DROP TABLE IF EXISTS `wp_options`') !== false);

// Comments are skipped, never sent.
$threw = null;
try {
    $exec->invoke(null, $db, '-- DROP TABLE x');
} catch (RuntimeException $e) {
    $threw = $e;
}
check('comment skipped', $threw === null);

echo $fails === 0 ? "OK restore-sql-error\n" : "{$fails} failed\n";
exit($fails === 0 ? 0 : 1);
