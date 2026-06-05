<?php
declare(strict_types=1);

/**
 * Standalone test for MediaOptimize::selectCandidates() — pure candidate
 * filtering/sorting/capping. The WP_Image_Editor path is verified live.
 * Run: php tests/Unit/media-optimize-test.php
 *
 * MediaOptimize references WP classes/functions in method bodies (not at load),
 * so requiring the file is safe; only selectCandidates() is exercised here.
 */

// Stub the few symbols referenced at class-load (use statements resolve lazily;
// nothing runs at include time), so a bare require works.
require __DIR__ . '/../../src/Endpoint/MediaOptimize.php';

use Rolepod\Wp\Endpoint\MediaOptimize;

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
        echo "  FAIL $label — want " . json_encode($want) . ", got " . json_encode($got) . "\n";
    }
}
function ids(array $rows): array
{
    return array_map(static fn(array $r): int => $r['id'], $rows);
}

echo "MediaOptimize::selectCandidates\n";

$items = [
    ['id' => 1, 'bytes' => 50_000],
    ['id' => 2, 'bytes' => 500_000],
    ['id' => 3, 'bytes' => 199_999],
    ['id' => 4, 'bytes' => 200_000],
    ['id' => 5, 'bytes' => 1_200_000],
];

// threshold filter (>= min)
check('threshold filters below min', ids(MediaOptimize::selectCandidates($items, 200_000, 100)), [5, 2, 4]);
check('boundary is inclusive (==min kept)', in_array(4, ids(MediaOptimize::selectCandidates($items, 200_000, 100)), true), true);
check('below boundary dropped (199999)', in_array(3, ids(MediaOptimize::selectCandidates($items, 200_000, 100)), true), false);

// sorted largest-first
check('largest first', ids(MediaOptimize::selectCandidates($items, 1, 100)), [5, 2, 4, 3, 1]);

// limit cap
check('limit caps to N', ids(MediaOptimize::selectCandidates($items, 1, 2)), [5, 2]);

// empty
check('none over a huge threshold', MediaOptimize::selectCandidates($items, 9_000_000, 100), []);
check('empty input', MediaOptimize::selectCandidates([], 1, 100), []);

echo "\n$count checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
