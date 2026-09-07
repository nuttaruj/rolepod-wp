<?php
declare(strict_types=1);

/**
 * Standalone test for Fs\BackupRetention — the "keep the newest N" prune that
 * runs after every backed-up write and after a theme snapshot. No WP deps.
 * Run: php tests/Unit/backup-retention-test.php
 */

require __DIR__ . '/../../src/Fs/BackupRetention.php';

use Rolepod\Wp\Fs\BackupRetention;

$failures = 0;
$count = 0;
function check(string $label, $got, $want): void
{
    global $failures, $count;
    $count++;
    if ($got === $want) {
        echo "  ok   $label\n";
    } else {
        $failures++;
        echo "  FAIL $label — want " . var_export($want, true) . ", got " . var_export($got, true) . "\n";
    }
}

$dir = sys_get_temp_dir() . '/rp-backup-retention-' . getmypid();
@mkdir($dir, 0777, true);

/** Write $name with an mtime $age seconds in the past. */
function seed(string $dir, string $name, int $age = 0): string
{
    $path = $dir . '/' . $name;
    file_put_contents($path, $name);
    touch($path, time() - $age);
    return $path;
}

function names(string $dir): array
{
    $out = array_values(array_diff((array) scandir($dir), ['.', '..']));
    sort($out);
    return $out;
}

function wipe(string $dir): void
{
    foreach ((array) glob($dir . '/*') as $f) {
        @unlink($f);
    }
}

echo "Fs\\BackupRetention\n";

// --- default keep -----------------------------------------------------------
check('default keep is 2', BackupRetention::keep(), 2);

// --- prunes a file's own backups down to the newest 2 -----------------------
wipe($dir);
seed($dir, 'functions.php');
seed($dir, 'functions.php.wplab-bak-20260101-000000', 400);
seed($dir, 'functions.php.wplab-bak-20260102-000000', 300);
seed($dir, 'functions.php.wplab-bak-20260103-000000', 200);
seed($dir, 'functions.php.wplab-bak-20260104-000000', 100);
$deleted = BackupRetention::prune($dir . '/functions.php');
check('deletes the two oldest', count($deleted), 2);
check('keeps the newest two + the file itself', names($dir), [
    'functions.php',
    'functions.php.wplab-bak-20260103-000000',
    'functions.php.wplab-bak-20260104-000000',
]);

// --- never reaches into a neighbour's backups -------------------------------
wipe($dir);
seed($dir, 'functions.php');
seed($dir, 'functions.php.wplab-bak-20260101-000000', 400);
seed($dir, 'functions.php.wplab-bak-20260102-000000', 300);
seed($dir, 'functions.php.wplab-bak-20260103-000000', 200);
seed($dir, 'style.css.wplab-bak-20260101-000000', 400);
seed($dir, 'style.css.wplab-bak-20260102-000000', 300);
seed($dir, 'style.css.wplab-bak-20260103-000000', 200);
seed($dir, 'notes.txt', 500);
BackupRetention::prune($dir . '/functions.php');
check('other files untouched', names($dir), [
    'functions.php',
    'functions.php.wplab-bak-20260102-000000',
    'functions.php.wplab-bak-20260103-000000',
    'notes.txt',
    'style.css.wplab-bak-20260101-000000',
    'style.css.wplab-bak-20260102-000000',
    'style.css.wplab-bak-20260103-000000',
]);

// --- a backup-of-a-backup is not part of the group --------------------------
wipe($dir);
seed($dir, 'functions.php');
seed($dir, 'functions.php.wplab-bak-A.wplab-bak-B', 500);
seed($dir, 'functions.php.wplab-bak-20260101-000000', 400);
seed($dir, 'functions.php.wplab-bak-20260102-000000', 300);
seed($dir, 'functions.php.wplab-bak-20260103-000000', 200);
BackupRetention::prune($dir . '/functions.php');
check('nested backup survives', in_array('functions.php.wplab-bak-A.wplab-bak-B', names($dir), true), true);

// --- uuid batch ids order by mtime, not by name -----------------------------
wipe($dir);
seed($dir, 'functions.php');
seed($dir, 'functions.php.wplab-bak-ffffffff-1111-4222-8333-444444444444', 300); // newest name, oldest file
seed($dir, 'functions.php.wplab-bak-00000000-1111-4222-8333-444444444444', 200);
seed($dir, 'functions.php.wplab-bak-11111111-1111-4222-8333-444444444444', 100);
BackupRetention::prune($dir . '/functions.php');
check('oldest mtime goes even with the highest name', in_array(
    'functions.php.wplab-bak-ffffffff-1111-4222-8333-444444444444',
    names($dir),
    true
), false);

// --- explicit keep, and keep is floored at 1 --------------------------------
wipe($dir);
seed($dir, 'functions.php');
for ($i = 1; $i <= 5; $i++) {
    seed($dir, 'functions.php.wplab-bak-2026010' . $i . '-000000', 600 - $i * 100);
}
BackupRetention::prune($dir . '/functions.php', 4);
check('keep=4 leaves four', count(names($dir)), 5); // 4 backups + the file
BackupRetention::prune($dir . '/functions.php', 0);
check('keep=0 is floored to 1', count(names($dir)), 2);

// --- nothing to do -----------------------------------------------------------
wipe($dir);
seed($dir, 'functions.php');
seed($dir, 'functions.php.wplab-bak-20260101-000000', 100);
check('under the limit deletes nothing', BackupRetention::prune($dir . '/functions.php'), []);
check('missing dir is a no-op', BackupRetention::prune('/no/such/dir/file.php'), []);

// --- theme snapshot grouping (prefix + suffix + strict stamp) ---------------
wipe($dir);
seed($dir, 'twentytwenty-20260101-000000.tar.gz', 400);
seed($dir, 'twentytwenty-20260102-000000.tar.gz', 300);
seed($dir, 'twentytwenty-20260103-000000.tar.gz', 200);
seed($dir, 'twentytwenty-child-20260101-000000.tar.gz', 400);
seed($dir, 'twentytwenty-readme.txt', 400);
BackupRetention::pruneGroup($dir, 'twentytwenty-', '.tar.gz', 2, '/^\d{8}-\d{6}$/');
check('snapshot prune keeps the newest two of that stylesheet only', names($dir), [
    'twentytwenty-20260102-000000.tar.gz',
    'twentytwenty-20260103-000000.tar.gz',
    'twentytwenty-child-20260101-000000.tar.gz',
    'twentytwenty-readme.txt',
]);

wipe($dir);
@rmdir($dir);

echo "\n$count checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
