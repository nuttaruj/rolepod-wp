<?php
declare(strict_types=1);

/**
 * Standalone test for Backup\Engine exclude matching (whole path segments, not
 * substrings) and the `languages` file root. No WordPress needed: private
 * methods are reached by reflection, WP_CONTENT_DIR points at a temp tree.
 * Run: php tests/Unit/backup-excludes-test.php
 */

$content = sys_get_temp_dir() . '/rp-backup-excludes-' . getmypid();
define('WP_CONTENT_DIR', $content);
require __DIR__ . '/../../src/Backup/Engine.php';

use Rolepod\Wp\Backup\Engine;

$failures = 0;
function check(string $label, $got, $want): void
{
    global $failures;
    if ($got === $want) {
        echo "  ok   $label\n";
    } else {
        $failures++;
        echo "  FAIL $label — want " . var_export($want, true) . ", got " . var_export($got, true) . "\n";
    }
}

$rc = new ReflectionClass(Engine::class);
$defaults = $rc->getReflectionConstant('DEFAULT_EXCLUDES')->getValue();
$excluded = $rc->getMethod('excluded');
$ex = static fn(string $rel, array $extra = []): bool => $excluded->invoke(null, $rel, array_merge($defaults, $extra));

echo "Backup\\Engine::excluded\n";
foreach ([
    'plugins/litespeed-cache/x.php',
    'plugins/w3-total-cache/a.php',
    'plugins/foo/vendor/symfony/cache/Adapter.php',
    'plugins/foo/.github/x.yml',
    'plugins/foo/.gitignore',
    'themes/t/cache/x.php',
    'uploads/2026/05/cache-photo.jpg',
    'languages/wpml/xx.mo',
    'plugins/node_modules_helper/a.js',
] as $kept) {
    check("kept $kept", $ex($kept), false);
}
foreach ([
    'plugins/foo/.git/HEAD',
    'plugins/foo/node_modules/a.js',
    'uploads/rolepod-wp/backups/b.zip',
    'uploads/rolepod-wp/tmp/x',
    'uploads/rolepod-wp-theme-snapshots/s.zip',
    'uploads/wplab-backups/a',
    'plugins/foo/.DS_Store',
    '.DS_Store',
    'uploads/cache/page.html',
] as $dropped) {
    check("dropped $dropped", $ex($dropped), true);
}
check('anchored /uploads/cache does not hit plugins/uploads/cache', $ex('plugins/uploads/cache/x'), false);
check('user exclude single segment', $ex('plugins/foo/tmp/a', ['tmp']), true);
check('user exclude segment is not a substring', $ex('plugins/foo/tmpl/a', ['tmp']), false);
check('user exclude with slash = consecutive run', $ex('uploads/2020/big/a.mp4', ['2020/big']), true);
check('user exclude run must be consecutive', $ex('uploads/2020/x/big/a.mp4', ['2020/big']), false);
check('user exclude trailing slash ignored', $ex('plugins/foo/logs/a', ['logs/']), true);

echo "Backup\\Engine::buildFileList roots\n";
foreach (['languages/wpml', 'languages/plugins', 'plugins/litespeed-cache', 'themes/t'] as $d) {
    mkdir($content . '/' . $d, 0777, true);
}
foreach (['languages/nl_NL.mo', 'languages/wpml/a.mo', 'languages/plugins/p-nl_NL.l10n.php', 'plugins/litespeed-cache/x.php', 'themes/t/s.css'] as $f) {
    file_put_contents($content . '/' . $f, 'x');
}
$bfl = $rc->getMethod('buildFileList');
$run = static function (array $comp) use ($bfl, $content, $defaults): array {
    $list = $content . '/list.txt';
    $bfl->invoke(null, ['components' => $comp, 'exclude' => $defaults, 'files' => ['list_path' => $list]]);
    $lines = file($list, FILE_IGNORE_NEW_LINES) ?: [];
    sort($lines);
    return $lines;
};
check('languages on', $run(['languages' => true]), ['languages/nl_NL.mo', 'languages/plugins/p-nl_NL.l10n.php', 'languages/wpml/a.mo']);
check('languages off', $run(['languages' => false]), []);
check('plugins+themes unchanged, cache plugin kept', $run(['plugins' => true, 'themes' => true]), ['plugins/litespeed-cache/x.php', 'themes/t/s.css']);

// cleanup
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($content, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
}
rmdir($content);

echo $failures === 0 ? "\nAll passed\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
