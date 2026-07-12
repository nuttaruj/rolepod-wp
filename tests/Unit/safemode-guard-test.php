<?php
declare(strict_types=1);

/**
 * Standalone test for SafeModeGuard::gate() — the fail-closed safe-mode filter.
 * Run: php tests/Unit/safemode-guard-test.php
 *
 * WP globals are stubbed below; the class body only references them at call
 * time, so requiring the file is safe.
 */

if (!defined('ROLEPOD_WP_REST_NAMESPACE')) {
    define('ROLEPOD_WP_REST_NAMESPACE', 'wplab/v1');
}

$GLOBALS['__safe_mode'] = false;
if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        if ($name === 'rolepod_wp_safe_mode') {
            return $GLOBALS['__safe_mode'];
        }
        return $default;
    }
}
if (!function_exists('add_filter')) {
    function add_filter(...$a): bool
    {
        return true;
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public string $code;
        public string $message;
        /** @var array<string,mixed> */
        public array $data;
        public function __construct(string $code = '', string $message = '', $data = [])
        {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = is_array($data) ? $data : [];
        }
    }
}

/** Minimal WP_REST_Request stand-in. */
final class FakeReq
{
    public function __construct(
        private string $route,
        private string $method,
        private array $params = []
    ) {
    }
    public function get_route(): string
    {
        return $this->route;
    }
    public function get_method(): string
    {
        return $this->method;
    }
    public function get_param(string $name)
    {
        return $this->params[$name] ?? null;
    }
}

require __DIR__ . '/../../src/Security/SafeModeGuard.php';

use Rolepod\Wp\Security\SafeModeGuard;

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

/** Returns 'blocked' (423 WP_Error) or 'passed' (null). */
function gate(string $route, string $method, array $params = []): string
{
    $r = SafeModeGuard::gate(null, null, new FakeReq($route, $method, $params));
    if ($r instanceof WP_Error) {
        return $r->data['status'] === 423 ? 'blocked' : 'other-error';
    }
    return $r === null ? 'passed' : 'unexpected';
}

$NS = '/wplab/v1/';

// ---- safe-mode OFF: everything passes ----
$GLOBALS['__safe_mode'] = false;
check('off: execute-php passes', gate($NS . 'execute-php', 'POST'), 'passed');
check('off: fs-write passes', gate($NS . 'fs-write', 'POST'), 'passed');

// ---- safe-mode ON ----
$GLOBALS['__safe_mode'] = true;

// mutating writes blocked (these are the endpoints the old code missed)
foreach (['execute-php', 'fs-write', 'fs-write-batch', 'fs-copy', 'fs-rename', 'option-set', 'dir-ensure', 'skills', 'backup-restore', 'backup-delete', 'backup-import', 'media-import', 'changes/panic', 'changes/toggle', 'job/create', 'admin/one-time-login', 'elementor/template-apply', 'theme/restore'] as $route) {
    check("on: $route blocked", gate($NS . $route, 'POST'), 'blocked');
}

// reads + diagnostics pass
check('on: GET changes passes', gate($NS . 'changes', 'GET'), 'passed');
check('on: GET backup-status passes', gate($NS . 'backup-status', 'GET'), 'passed');
check('on: POST fs-read passes', gate($NS . 'fs-read', 'POST'), 'passed');
check('on: POST option-get passes', gate($NS . 'option-get', 'POST'), 'passed');
check('on: POST introspect passes', gate($NS . 'introspect', 'POST'), 'passed');
check('on: POST php-session passes', gate($NS . 'php-session', 'POST'), 'passed');
check('on: POST syntax-check passes', gate($NS . 'syntax-check', 'POST'), 'passed');
check('on: POST request-observer/poll passes', gate($NS . 'request-observer/poll', 'POST'), 'passed');
check('on: POST backup-download passes (read-only, needed for recovery)', gate($NS . 'backup-download', 'POST'), 'passed');

// wp-cli: read verbs pass, writes blocked (closes the old looksDestructive hole)
check('on: wp-cli plugin list passes', gate($NS . 'wp-cli', 'POST', ['args' => ['plugin', 'list']]), 'passed');
check('on: wp-cli core verify-checksums passes', gate($NS . 'wp-cli', 'POST', ['args' => ['core', 'verify-checksums']]), 'passed');
check('on: wp-cli user session list passes', gate($NS . 'wp-cli', 'POST', ['args' => ['user', 'session', 'list']]), 'passed');
check('on: wp-cli plugin update --all BLOCKED', gate($NS . 'wp-cli', 'POST', ['args' => ['plugin', 'update', '--all']]), 'blocked');
check('on: wp-cli core update BLOCKED', gate($NS . 'wp-cli', 'POST', ['args' => ['core', 'update']]), 'blocked');
check('on: wp-cli option update BLOCKED', gate($NS . 'wp-cli', 'POST', ['args' => ['option', 'update', 'x', 'y']]), 'blocked');
check('on: wp-cli db query DELETE BLOCKED', gate($NS . 'wp-cli', 'POST', ['args' => ['db', 'query', 'DELETE FROM wp_posts']]), 'blocked');

// other namespace (guardian recovery) is never touched
check('on: guardian recovery namespace passes', gate('/rolepod-guardian/v1/disable-plugin', 'POST'), 'passed');

// a brand-new, ungated write endpoint is blocked by default (fail-closed)
check('on: unknown new write endpoint blocked', gate($NS . 'some-future-writer', 'POST'), 'blocked');

echo "\n$count checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
