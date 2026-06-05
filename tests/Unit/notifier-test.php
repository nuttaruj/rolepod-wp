<?php
declare(strict_types=1);

/**
 * Standalone test for Audit\Notifier — webhook level gating + provider payload
 * shaping. Stubs get_option + wp_remote_post (captures calls). No wp-env.
 * Run: php tests/Unit/notifier-test.php
 */

$GLOBALS['__opts'] = [];
$GLOBALS['__posts'] = [];

function get_option($k, $default = false)
{
    return array_key_exists($k, $GLOBALS['__opts']) ? $GLOBALS['__opts'][$k] : $default;
}
function wp_json_encode($v)
{
    return json_encode($v);
}
function wp_remote_post($url, $args = [])
{
    $body = isset($args['body']) ? json_decode((string) $args['body'], true) : null;
    $GLOBALS['__posts'][] = ['url' => $url, 'body' => $body, 'blocking' => $args['blocking'] ?? null];
    return [];
}

require __DIR__ . '/../../src/Config.php';
require __DIR__ . '/../../src/Audit/Notifier.php';

use Rolepod\Wp\Audit\Notifier;

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
function set_config(string $url, string $level): void
{
    $GLOBALS['__opts']['rolepod_wp_config'] = ['webhook_url' => $url, 'webhook_level' => $level];
    $GLOBALS['__posts'] = [];
}
function last_post()
{
    return empty($GLOBALS['__posts']) ? null : end($GLOBALS['__posts']);
}

echo "Audit\\Notifier\n";

// --- gating: no URL = silent ------------------------------------------------
set_config('', 'all');
Notifier::maybeNotify(['endpoint' => 'execute-php', 'result' => 'success']);
check('no url → no post', count($GLOBALS['__posts']), 0);

// --- level=errors: success non-execute is skipped ---------------------------
set_config('https://example.com/hook', 'errors');
Notifier::maybeNotify(['endpoint' => 'fs-write', 'result' => 'success']);
check('errors level skips fs-write success', count($GLOBALS['__posts']), 0);

// --- level=errors: execute-php always sent ----------------------------------
set_config('https://example.com/hook', 'errors');
Notifier::maybeNotify(['endpoint' => 'execute-php', 'result' => 'success']);
check('errors level sends execute-php success', count($GLOBALS['__posts']), 1);

// --- level=errors: any error sent -------------------------------------------
set_config('https://example.com/hook', 'errors');
Notifier::maybeNotify(['endpoint' => 'fs-write', 'result' => 'rejected', 'error' => 'OUT_OF_SCOPE']);
check('errors level sends rejected', count($GLOBALS['__posts']), 1);

// --- level=all: success generic sent ----------------------------------------
set_config('https://example.com/hook', 'all');
Notifier::maybeNotify(['endpoint' => 'fs-write', 'result' => 'success']);
check('all level sends fs-write success', count($GLOBALS['__posts']), 1);

// --- delivery is non-blocking -----------------------------------------------
check('post is non-blocking', last_post()['blocking'] ?? null, false);

// --- provider shaping: Slack ------------------------------------------------
set_config('https://hooks.slack.com/services/T/B/X', 'all');
Notifier::maybeNotify(['endpoint' => 'execute-php', 'result' => 'success', 'site_url' => 'https://demo.test', 'user' => 'admin']);
$body = last_post()['body'];
check('slack has text key', isset($body['text']), true);
check('slack has no content key', isset($body['content']), false);
check('slack has no endpoint key', isset($body['endpoint']), false);

// --- provider shaping: Discord ----------------------------------------------
set_config('https://discord.com/api/webhooks/1/abc', 'all');
Notifier::maybeNotify(['endpoint' => 'execute-php', 'result' => 'error', 'error' => 'boom', 'site_url' => 'https://demo.test', 'user' => 'admin']);
$body = last_post()['body'];
check('discord has content key', isset($body['content']), true);
check('discord content includes error', strpos((string) $body['content'], 'boom') !== false, true);

// --- provider shaping: Generic ----------------------------------------------
set_config('https://example.com/ingest', 'all');
Notifier::maybeNotify(['endpoint' => 'execute-php', 'result' => 'success', 'site_url' => 'https://demo.test', 'user' => 'admin', 'audit_id' => 'a1']);
$body = last_post()['body'];
check('generic has endpoint key', $body['endpoint'] ?? null, 'execute-php');
check('generic has text key', isset($body['text']), true);
check('generic has audit_id', $body['audit_id'] ?? null, 'a1');

echo "\n$count checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
