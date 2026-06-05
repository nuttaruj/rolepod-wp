<?php
declare(strict_types=1);

/**
 * Standalone test for AstScreen::symbolConflicts() — no PHPUnit/wp-env.
 * AstScreen has zero WP dependencies (token_get_all + *_exists are native).
 * Run: php tests/Unit/astscreen-symbols-test.php
 */

require __DIR__ . '/../../src/Security/AstScreen.php';

use Rolepod\Wp\Security\AstScreen;

$failures = 0;
$count = 0;

function conflict_names(array $conflicts): string
{
    return implode(',', array_map(
        static fn(array $c): string => $c['kind'] . ':' . $c['name'],
        $conflicts
    ));
}

/** @param string[] $wantNames expected "kind:name" set (order-insensitive) */
function expect(string $label, string $payload, array $wantNames): void
{
    global $failures, $count;
    $count++;
    $got = AstScreen::symbolConflicts($payload);
    $gotSet = array_map(static fn(array $c): string => $c['kind'] . ':' . $c['name'], $got);
    sort($gotSet);
    $want = $wantNames;
    sort($want);
    if ($gotSet === $want) {
        echo "  ok   $label\n";
    } else {
        $failures++;
        echo "  FAIL $label\n";
        echo "       want: [" . implode(', ', $want) . "]\n";
        echo "       got:  [" . implode(', ', $gotSet) . "]\n";
    }
}

echo "AstScreen::symbolConflicts\n";

// --- conflicts (symbol already exists in PHP runtime) -----------------------
expect('redeclare global function', 'function strlen() { return 0; }', ['function:strlen']);
expect('redeclare global class', 'class Exception {}', ['class:Exception']);
expect('redeclare interface', 'interface Throwable {}', ['interface:Throwable']);
expect('by-ref function redeclare', 'function &array_map() {}', ['function:array_map']);
expect('two conflicts', "function strlen(){}\nclass Exception {}", ['function:strlen', 'class:Exception']);

// --- NO conflict (new symbols / non-declarations) ---------------------------
expect('unique new function', 'function rolepod_unique_fn_xyz() { return 1; }', []);
expect('unique new class', 'class Rolepod_Unique_Class_Xyz {}', []);
expect('closure declares nothing', '$f = function () { return strlen("x"); };', []);
expect('arrow fn declares nothing', '$f = fn($x) => $x + 1;', []);
expect('::class usage', '$c = Exception::class; return $c;', []);
expect('anonymous class', '$o = new class { public $a = 1; };', []);
expect(
    'method named like global fn is not a global decl',
    'class Rolepod_Tmp_Holder { public function strlen() { return 7; } }',
    []
);
expect('plain expression', 'return get_option("x");', []);
expect('string with brace interpolation', '$x = "a"; return "val {$x} end";', []);

echo "\n$count checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
