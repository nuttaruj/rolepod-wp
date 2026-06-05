<?php
declare(strict_types=1);

/**
 * Standalone test for Backup\Archive — the AI-friendly ZIP mechanism:
 * per-entry STORE-vs-DEFLATE selection + browse/read a member WITHOUT
 * extracting. Archive has zero WP deps (ZipArchive is native). No wp-env.
 * Run: php tests/Unit/backup-archive-test.php
 */

require __DIR__ . '/../../src/Backup/Archive.php';

use Rolepod\Wp\Backup\Archive;

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

if (!class_exists('ZipArchive')) {
    echo "SKIP — ZipArchive not available\n";
    exit(0);
}

echo "Backup\\Archive\n";

$zipPath = sys_get_temp_dir() . '/rp-backup-archive-test-' . getmypid() . '.zip';
@unlink($zipPath);

// --- write: manifest (deflate) + fake jpeg (store) --------------------------
$ar = Archive::openForAppend($zipPath);
check('openForAppend returns instance', $ar instanceof Archive, true);
$manifest = json_encode(['format' => 'rolepod-backup', 'format_version' => 1, 'hello' => str_repeat('x', 500)]);
$ar->addStringSmart('manifest.json', $manifest);
$ar->addStringSmart('database.sql', str_repeat("INSERT INTO t VALUES (1);\n", 200));
$ar->addStringSmart('files/uploads/photo.jpg', random_bytes(4000)); // "media" → store
$ar->close();

// --- append in a second open (incremental build across ticks) ---------------
$ar2 = Archive::openForAppend($zipPath);
$ar2->addStringSmart('files/themes/style.css', str_repeat('.a{color:red}', 100));
$ar2->close();

// --- browse WITHOUT extracting ----------------------------------------------
$list = Archive::listEntries($zipPath);
check('list ok', $list['ok'], true);
check('list total = 4', $list['total'], 4);
$byName = [];
foreach ($list['entries'] as $e) {
    $byName[$e['name']] = $e;
}
check('has manifest.json', isset($byName['manifest.json']), true);
check('has appended css (incremental build worked)', isset($byName['files/themes/style.css']), true);

// CM_STORE = 0, CM_DEFLATE = 8.
check('jpg is STORED (method 0)', $byName['files/uploads/photo.jpg']['method'], 0);
check('manifest is DEFLATED (method 8)', $byName['manifest.json']['method'], 8);
check('sql is DEFLATED (method 8)', $byName['database.sql']['method'], 8);
check('css is DEFLATED (method 8)', $byName['files/themes/style.css']['method'], 8);

// deflate actually shrank the repetitive SQL
check('sql compressed smaller than raw', $byName['database.sql']['compressed'] < $byName['database.sql']['size'], true);

// --- read a single member WITHOUT extracting --------------------------------
$read = Archive::readEntry($zipPath, 'manifest.json');
check('readEntry ok', $read['ok'], true);
check('readEntry content matches', $read['content'], $manifest);

$missing = Archive::readEntry($zipPath, 'does-not-exist.txt');
check('readEntry missing → not ok', $missing['ok'], false);
check('readEntry missing error', $missing['error'], 'ENTRY_NOT_FOUND');

@unlink($zipPath);

echo "\n$count checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
