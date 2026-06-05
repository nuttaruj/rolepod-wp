<?php
declare(strict_types=1);

namespace Rolepod\Wp\Security;

/**
 * v0.1: token-based PHP-payload blocklist screen.
 *
 * Rejects payloads containing forbidden function calls or language constructs.
 * This is the SECOND screen — the Node MCP runs `php-parser` AST screen first.
 * Token-based screen is sufficient as a defence-in-depth layer for v0.1; v0.2
 * upgrades to a proper PHP AST via nikic/php-parser (added as a composer dep
 * once execute-php is default-enabled).
 *
 * Approach: tokenize via token_get_all() and inspect each T_STRING for a
 * forbidden function name, plus check for T_INCLUDE / T_REQUIRE / T_EVAL.
 * Backtick operator and shell_exec syntax are rejected via raw-source scan
 * because token_get_all maps them to a special token id.
 */
final class AstScreen
{
    private const FORBIDDEN_FUNCS = [
        'eval', 'assert', 'create_function',
        'system', 'passthru', 'shell_exec', 'exec', 'proc_open', 'popen',
        'pcntl_exec', 'pcntl_fork',
        'dl', // load PHP extension
        // file ops handled by separate scope rule (v0.2)
    ];

    /**
     * @param string $payload PHP source (without opening <?php tag)
     * @return array{ok: bool, error?: string, token?: string}
     */
    public static function screen(string $payload): array
    {
        // 1. Quick scan for backtick operator (shell exec syntax).
        // token_get_all() emits these as ` characters in the token stream.
        if (preg_match('/`[^`]*`/', $payload) === 1) {
            return ['ok' => false, 'error' => 'Backtick shell-exec syntax is forbidden', 'token' => '`'];
        }

        // 2. Tokenize. Prepend <?php so token_get_all parses correctly.
        $tokens = @token_get_all('<?php ' . $payload);
        if (!is_array($tokens) || count($tokens) === 0) {
            return ['ok' => false, 'error' => 'Payload could not be tokenized'];
        }

        $forbiddenSet = array_flip(self::FORBIDDEN_FUNCS);

        foreach ($tokens as $i => $tok) {
            if (!is_array($tok)) {
                continue;
            }
            $id = $tok[0];
            $text = (string) $tok[1];

            // Language constructs
            if ($id === T_EVAL) {
                return ['ok' => false, 'error' => 'eval() is forbidden', 'token' => 'eval'];
            }
            if ($id === T_INCLUDE || $id === T_INCLUDE_ONCE || $id === T_REQUIRE || $id === T_REQUIRE_ONCE) {
                // Allow only if the very next non-whitespace token is a string literal — i.e.
                // include 'path' with a static path. Anything dynamic = reject.
                $next = self::nextNonWhitespace($tokens, $i);
                if ($next === null || (!is_array($next) || $next[0] !== T_CONSTANT_ENCAPSED_STRING)) {
                    return ['ok' => false, 'error' => 'Dynamic include/require is forbidden', 'token' => $text];
                }
            }

            // Forbidden function names — must look like a function call: T_STRING followed by `(`.
            if ($id === T_STRING && isset($forbiddenSet[strtolower($text)])) {
                $next = self::nextNonWhitespace($tokens, $i);
                if ($next === '(') {
                    return [
                        'ok' => false,
                        'error' => "Forbidden function call: {$text}()",
                        'token' => strtolower($text),
                    ];
                }
            }
        }

        return ['ok' => true];
    }

    /**
     * Detect global function / class / interface / trait / enum declarations in
     * the payload whose names ALREADY exist in the current PHP runtime.
     * Re-declaring them inside eval() triggers a "Cannot redeclare" fatal that
     * brings down the request (and the admin's page) — and unlike a thrown
     * Throwable it is not reliably catchable. We screen for it BEFORE eval so
     * the MCP gets a clean, actionable SYMBOL_CONFLICT instead of a 500/WSOD.
     *
     * This is the companion-side analogue of SproutOS's sandbox symbol-conflict
     * detection, adapted to our in-request eval model (we keep full WP context
     * on purpose; the guard just prevents the one fatal class that AST + the
     * Throwable catch cannot).
     *
     * Only declarations at brace-depth 0 count:
     *   - `function foo()` inside a class body is a METHOD (no global symbol).
     *   - a closure `function () {}` / arrow `fn()` declares nothing.
     *   - `new class {}` (anonymous) and `Foo::class` declare nothing.
     *
     * @param string $payload PHP source (without opening <?php tag)
     * @return list<array{kind:string,name:string}> empty when no conflict
     */
    public static function symbolConflicts(string $payload): array
    {
        $tokens = @token_get_all('<?php ' . $payload);
        if (!is_array($tokens) || count($tokens) === 0) {
            return [];
        }

        $enumToken = defined('T_ENUM') ? T_ENUM : -999;
        $conflicts = [];
        $depth = 0;

        foreach ($tokens as $i => $tok) {
            // Brace-depth tracking. token_get_all emits plain braces as the
            // single-char string tokens '{' / '}'. String-interpolation opens
            // ({$x}, ${x}) arrive as T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES
            // and close with a plain '}', so counting both as +1 stays balanced.
            if (is_string($tok)) {
                if ($tok === '{') {
                    $depth++;
                } elseif ($tok === '}') {
                    $depth = max(0, $depth - 1);
                }
                continue;
            }

            $id = $tok[0];
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                continue;
            }

            if ($depth !== 0) {
                continue;
            }

            if ($id === T_FUNCTION) {
                // Skip the optional by-ref `&` then look for a name. A closure
                // ( `function (` / `function &(` ) has '(' here → not a decl.
                $name = self::declNameAfter($tokens, $i);
                if ($name !== null && function_exists($name)) {
                    $conflicts[] = ['kind' => 'function', 'name' => $name];
                }
                continue;
            }

            if ($id === T_CLASS) {
                // `Foo::class` → previous non-ws token is T_DOUBLE_COLON. Anonymous
                // `new class {}` → next non-ws is not a T_STRING. declNameAfter
                // returns null in both cases.
                $prev = self::prevNonWhitespace($tokens, $i);
                if (is_array($prev) && $prev[0] === T_DOUBLE_COLON) {
                    continue;
                }
                $name = self::declNameAfter($tokens, $i);
                if ($name !== null && class_exists($name, false)) {
                    $conflicts[] = ['kind' => 'class', 'name' => $name];
                }
                continue;
            }

            if ($id === T_INTERFACE) {
                $name = self::declNameAfter($tokens, $i);
                if ($name !== null && interface_exists($name, false)) {
                    $conflicts[] = ['kind' => 'interface', 'name' => $name];
                }
                continue;
            }

            if ($id === T_TRAIT) {
                $name = self::declNameAfter($tokens, $i);
                if ($name !== null && trait_exists($name, false)) {
                    $conflicts[] = ['kind' => 'trait', 'name' => $name];
                }
                continue;
            }

            if ($id === $enumToken) {
                $name = self::declNameAfter($tokens, $i);
                if ($name !== null && function_exists('enum_exists') && enum_exists($name, false)) {
                    $conflicts[] = ['kind' => 'enum', 'name' => $name];
                }
                continue;
            }
        }

        return $conflicts;
    }

    /**
     * Given the index of a declaration keyword (T_FUNCTION/T_CLASS/…), return
     * the declared symbol name if the next significant token is a name token,
     * skipping a single by-ref `&` after `function`. Returns null for closures
     * / anonymous classes / `::class` (where no name follows).
     *
     * @param array<int, array{0:int,1:string,2:int}|string> $tokens
     */
    private static function declNameAfter(array $tokens, int $i): ?string
    {
        // Find the index of the next significant token, then optionally skip a
        // single by-ref `&`. PHP 8.1+ tokenizes `&` as
        // T_AMPERSAND_{NOT_,}FOLLOWED_BY_VAR_OR_VARARG (array tokens), while
        // older PHP emits a plain '&' string token — so we match on token text.
        $j = self::nextNonWhitespaceIndex($tokens, $i);
        if ($j !== null && self::tokenText($tokens[$j]) === '&') {
            $j = self::nextNonWhitespaceIndex($tokens, $j);
        }
        if ($j !== null) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_STRING) {
                return (string) $t[1];
            }
        }
        return null;
    }

    /**
     * @param array{0:int,1:string,2:int}|string $token
     */
    private static function tokenText($token): string
    {
        return is_array($token) ? (string) $token[1] : (string) $token;
    }

    /**
     * @param array<int, array{0:int,1:string,2:int}|string> $tokens
     */
    private static function nextNonWhitespaceIndex(array $tokens, int $i): ?int
    {
        for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_WHITESPACE) {
                continue;
            }
            return $j;
        }
        return null;
    }

    /**
     * @param array<int, array{0:int,1:string,2:int}|string> $tokens
     * @return array{0:int,1:string,2:int}|string|null
     */
    private static function prevNonWhitespace(array $tokens, int $i)
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_WHITESPACE) {
                continue;
            }
            return $t;
        }
        return null;
    }

    /**
     * @param array<int, array{0:int,1:string,2:int}|string> $tokens
     * @return array{0:int,1:string,2:int}|string|null
     */
    private static function nextNonWhitespace(array $tokens, int $i)
    {
        for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_WHITESPACE) {
                continue;
            }
            return $t;
        }
        return null;
    }
}
