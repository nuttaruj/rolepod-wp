<?php
declare(strict_types=1);

/**
 * Standalone tests for the media-import feature (WS2-T2).
 * Run: php tests/Unit/media-import-test.php
 *
 * Covers the pure, WP-free logic — Importer's source bounds (base64 decode +
 * cap, https-only, wp-content scoping, filename derivation) and Toggler's
 * media/import revert dispatch. The actual sideload needs a booted WordPress
 * and is exercised in integration, not here.
 */

$tmpContent = sys_get_temp_dir() . '/rolepod-media-test-' . getmypid();
@mkdir($tmpContent, 0777, true);
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', $tmpContent);
}

// Minimal WP function stubs used by the pure helpers.
if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($name)
    {
        $name = basename((string) $name);
        return preg_replace('/[^A-Za-z0-9._-]/', '-', $name);
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($s)
    {
        return trim((string) $s);
    }
}
$GLOBALS['__deleted'] = [];
if (!function_exists('wp_delete_attachment')) {
    function wp_delete_attachment($id, $force = false)
    {
        $GLOBALS['__deleted'][] = (int) $id;
        return (object) ['ID' => (int) $id]; // truthy = success
    }
}

require __DIR__ . '/../../src/Media/Importer.php';
require __DIR__ . '/../../src/Audit/Toggler.php';

use Rolepod\Wp\Media\Importer;
use Rolepod\Wp\Audit\Toggler;

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

// Reflection handles for the private pure helpers.
$decode  = (new ReflectionMethod(Importer::class, 'decodeBase64'));
$isHttps = (new ReflectionMethod(Importer::class, 'isHttps'));
$within  = (new ReflectionMethod(Importer::class, 'withinContent'));
$fname   = (new ReflectionMethod(Importer::class, 'filename'));
$decodeB = static fn(string $s) => $decode->invoke(null, $s);
$https   = static fn(string $s): bool => $isHttps->invoke(null, $s);
$inside  = static fn(string $s): bool => $within->invoke(null, $s);
$filenm  = static fn(string $src, array $a): string => $fname->invoke(null, $src, $a);

// ---- base64 decode ----
$png = base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', 32));
check('valid base64 decodes', is_string($decodeB($png)), true);
check('data-uri prefix stripped', is_string($decodeB('data:image/png;base64,' . $png)), true);
check('garbage base64 → null', $decodeB('@@@not base64@@@'), null);

// ---- https-only ----
check('https url ok', $https('https://cdn.example.com/a.png'), true);
check('http url rejected', $https('http://cdn.example.com/a.png'), false);
check('ftp url rejected', $https('ftp://x/a.png'), false);

// ---- wp-content scoping ----
$inFile = WP_CONTENT_DIR . '/uploads-fake.png';
file_put_contents($inFile, 'x');
$outFile = sys_get_temp_dir() . '/rolepod-outside-' . getmypid() . '.png';
file_put_contents($outFile, 'x');
check('file inside wp-content allowed', $inside((string) realpath($inFile)), true);
check('file outside wp-content rejected', $inside((string) realpath($outFile)), false);

// ---- filename derivation ----
check('explicit filename sanitized', $filenm('base64', ['filename' => 'My Photo.png']), 'My-Photo.png');
check('url basename used', $filenm('url', ['url' => 'https://x.com/pics/logo.svg']), 'logo.svg');
check('base64 without name → generic', $filenm('base64', []), 'rolepod-import.png');

// ---- Importer.import guards (no WP needed to reach these branches) ----
check('bad source rejected', Importer::import(['source' => 'ftp'])['error_code'], 'BAD_SOURCE');
check('invalid base64 rejected', Importer::import(['source' => 'base64', 'data' => '@@@'])['error_code'], 'INVALID_BASE64');
check('empty base64 rejected', Importer::import(['source' => 'base64', 'data' => ''])['error_code'], 'EMPTY_PAYLOAD');
$oversize = base64_encode(str_repeat('x', Importer::MAX_BYTES + 1));
check('oversized base64 rejected', Importer::import(['source' => 'base64', 'data' => $oversize])['error_code'], 'TOO_LARGE');
check('http url rejected at import', Importer::import(['source' => 'url', 'url' => 'http://x/a.png'])['error_code'], 'NOT_HTTPS');
check('missing local path rejected', Importer::import(['source' => 'local_path', 'path' => '/does/not/exist'])['error_code'], 'NOT_FOUND');
check('local path outside wp-content rejected', Importer::import(['source' => 'local_path', 'path' => $outFile])['error_code'], 'PATH_OUT_OF_SCOPE');

// ---- Toggler media/import revert ----
$toggle = (new ReflectionMethod(Toggler::class, 'apply'));
$applyRow = static fn(array $row, bool $newApplied): array => $toggle->invoke(null, $row, $newApplied);

$importRow = [
    'category' => 'media',
    'subcategory' => 'import',
    'reversible' => 1,
    'after_state' => ['attachment_id' => 4242, 'url' => 'https://x/w.png'],
];
$GLOBALS['__deleted'] = [];
$disable = $applyRow($importRow, false);
check('disable import → ok', $disable['ok'], true);
check('disable import → reverted action', $disable['action'], 'reverted');
check('disable import → attachment deleted', in_array(4242, $GLOBALS['__deleted'], true), true);

$reenable = $applyRow($importRow, true);
check('re-enable import → honest noop', $reenable['ok'], false);
check('re-enable import → noop action', $reenable['action'], 'noop');

$optimizeRow = ['category' => 'media', 'subcategory' => 'optimize', 'reversible' => 1, 'after_state' => []];
check('media/optimize left to its own path (not this dispatcher)', $applyRow($optimizeRow, false)['action'], 'noop');

$irreversible = ['category' => 'media', 'subcategory' => 'import', 'reversible' => 0, 'after_state' => ['attachment_id' => 1]];
check('reversible=0 short-circuits to noop', $applyRow($irreversible, false)['action'], 'noop');

@unlink($inFile);
@unlink($outFile);
@rmdir($tmpContent);

echo "\n$count checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
