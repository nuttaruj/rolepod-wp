<?php
declare(strict_types=1);

/**
 * Standalone test for Backup\SerializedReplace — the serialized-data-safe
 * search/replace used by the restore URL-rewrite stage. Zero WP deps.
 * Run: php tests/Unit/serialized-replace-test.php
 */

require __DIR__ . '/../../src/Backup/SerializedReplace.php';

use Rolepod\Wp\Backup\SerializedReplace;

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
        echo "  FAIL $label\n       want: " . var_export($want, true) . "\n       got:  " . var_export($got, true) . "\n";
    }
}

$map = ['https://old.example' => 'https://new.example.com'];

echo "Backup\\SerializedReplace\n";

// --- plain string -----------------------------------------------------------
check('plain string replaced', SerializedReplace::applyToValue('go to https://old.example/x', $map), 'go to https://new.example.com/x');
check('no match untouched', SerializedReplace::applyToValue('nothing here', $map), 'nothing here');

// --- serialized string: length prefix must be regenerated -------------------
$orig = serialize(['url' => 'https://old.example/page', 'n' => 5]);
$out = SerializedReplace::applyToValue($orig, $map);
$round = unserialize($out);
check('serialized array value replaced', $round['url'], 'https://new.example.com/page');
check('serialized int preserved', $round['n'], 5);
check('serialized output is valid (re-serialized length correct)', is_array(unserialize($out)), true);

// the naive str_replace would corrupt length — prove ours did NOT:
$naive = str_replace('https://old.example', 'https://new.example.com', $orig);
check('naive str_replace corrupts (control)', @unserialize($naive), false);
check('our replace does NOT corrupt', is_array(@unserialize($out)), true);

// --- nested serialized inside serialized ------------------------------------
$nested = serialize(['outer' => serialize(['deep' => 'https://old.example/d'])]);
$nr = SerializedReplace::applyToValue($nested, $map);
$lvl1 = unserialize($nr);
$lvl2 = unserialize($lvl1['outer']);
check('nested serialized replaced', $lvl2['deep'], 'https://new.example.com/d');

// --- multibyte length correctness (bytes, not chars) ------------------------
$mb = serialize(['t' => 'https://old.example/café']);
$mbr = unserialize(SerializedReplace::applyToValue($mb, $map));
check('multibyte value intact + replaced', $mbr['t'], 'https://new.example.com/café');

// --- objects + scalars ------------------------------------------------------
$obj = (object) ['site' => 'https://old.example'];
$so = SerializedReplace::applyToValue(serialize($obj), $map);
check('serialized object stdClass replaced', unserialize($so)->site, 'https://new.example.com');
check('bool false (b:0;) survives', SerializedReplace::applyToValue('b:0;', $map), 'b:0;');

echo "\n$count checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
