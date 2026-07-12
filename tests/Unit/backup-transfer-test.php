<?php
declare(strict_types=1);

/**
 * Standalone tests for Backup\Transfer — the chunked download/import framing
 * (WS13-T3/T4). Pure PHP (fopen/filesize/base64), no WordPress needed.
 * Run: php tests/Unit/backup-transfer-test.php
 */

require __DIR__ . '/../../src/Backup/Transfer.php';

use Rolepod\Wp\Backup\Transfer;

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

$dir = sys_get_temp_dir() . '/rolepod-xfer-test-' . getmypid();
@mkdir($dir, 0777, true);

// A payload larger than one small chunk so multi-chunk framing is exercised.
$payload = random_bytes(9000);
$src = $dir . '/source.zip';
file_put_contents($src, $payload);

// ---- readChunk: byte-identical multi-chunk reassembly ----
$chunkSize = 4096;
$offset = 0;
$reassembled = '';
$total = null;
$sawEof = false;
$guard = 0;
while ($guard++ < 100) {
    $r = Transfer::readChunk($src, $offset, $chunkSize);
    check("download chunk at {$offset} ok", $r['ok'], true);
    $total = $r['total_bytes'];
    $reassembled .= base64_decode($r['data'], true);
    $offset += $r['length'];
    if ($r['eof']) {
        $sawEof = true;
        break;
    }
    if ($r['length'] === 0) {
        break;
    }
}
check('download total_bytes correct', $total, strlen($payload));
check('download saw eof', $sawEof, true);
check('download reassembled is byte-identical', $reassembled === $payload, true);

// ---- readChunk edge cases ----
check('unknown path → NOT_FOUND', Transfer::readChunk($dir . '/nope.zip', 0, 10)['error'], 'NOT_FOUND');
check('offset past end → OFFSET_OUT_OF_RANGE', Transfer::readChunk($src, strlen($payload) + 1, 10)['error'], 'OFFSET_OUT_OF_RANGE');
$eofRead = Transfer::readChunk($src, strlen($payload), 10);
check('offset exactly at end → ok + eof + empty', $eofRead['ok'] && $eofRead['eof'] && $eofRead['length'] === 0, true);

// ---- appendChunk: contiguous reassembly byte-identical ----
$uploadId = 'unit-test-upload';
Transfer::discard($dir, $uploadId);
$parts = str_split($payload, 3000);
$off = 0;
foreach ($parts as $i => $part) {
    $res = Transfer::appendChunk($dir, $uploadId, $off, $part);
    check("import chunk {$i} appended", $res['ok'], true);
    $off += strlen($part);
    check("import running total after chunk {$i}", $res['received'], $off);
}
$staged = file_get_contents(Transfer::stagePath($dir, $uploadId));
check('import staged file is byte-identical', $staged === $payload, true);

// ---- appendChunk: offset mismatch rejected, does not corrupt ----
$mismatch = Transfer::appendChunk($dir, $uploadId, 5, 'zzz'); // current end != 5
check('wrong offset → OFFSET_MISMATCH', $mismatch['error'], 'OFFSET_MISMATCH');
check('OFFSET_MISMATCH reports expected end', $mismatch['expected_offset'], strlen($payload));
check('rejected chunk did not grow the file', filesize(Transfer::stagePath($dir, $uploadId)), strlen($payload));

// ---- stagePath: crafted upload id cannot traverse out of dir ----
$evil = Transfer::stagePath($dir, '../../../../etc/passwd');
check('traversal id stays inside dir', strpos($evil, $dir . '/rolepod-xfer-') === 0, true);
check('traversal id has no slash in the id segment', strpos(basename($evil), '/'), false);

// cleanup
Transfer::discard($dir, $uploadId);
@unlink($src);
@rmdir($dir);

echo "\n$count checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
