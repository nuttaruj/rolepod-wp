<?php
declare(strict_types=1);

/**
 * Standalone test for the guardian boot-loop auto-heal logic.
 * Stubs the handful of WP functions the guardian touches, then drives
 * rolepod_guardian_maybe_autoheal() through a simulated WSOD loop against a
 * real temp file. Run: php tests/Unit/guardian-autoheal-test.php
 */

// --- WP environment stubs (defined BEFORE including the guardian) ------------
$TMP = sys_get_temp_dir() . '/rp-guardian-test-' . getmypid();
@mkdir($TMP . '/themes/rp-badtheme', 0777, true);
@mkdir($TMP . '/plugins', 0777, true);
@mkdir($TMP . '/mu-plugins', 0777, true);

define('ABSPATH', $TMP . '/');
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', $TMP);
define('WP_PLUGIN_DIR', $TMP . '/plugins');
define('WPMU_PLUGIN_DIR', $TMP . '/mu-plugins');
define('HOUR_IN_SECONDS', 3600);

$GLOBALS['__opts'] = [];
$GLOBALS['__transients'] = [];

function get_option($k, $default = false)
{
    return array_key_exists($k, $GLOBALS['__opts']) ? $GLOBALS['__opts'][$k] : $default;
}
function update_option($k, $v, $autoload = null): bool
{
    $GLOBALS['__opts'][$k] = $v;
    return true;
}
function delete_option($k): bool
{
    unset($GLOBALS['__opts'][$k]);
    return true;
}
function get_transient($k)
{
    return $GLOBALS['__transients'][$k] ?? false;
}
function set_transient($k, $v, $ttl = 0): bool
{
    $GLOBALS['__transients'][$k] = $v;
    return true;
}
function delete_transient($k): bool
{
    unset($GLOBALS['__transients'][$k]);
    return true;
}
function add_action(...$a): bool
{
    return true;
}
function deactivate_plugins(...$a): void {}

require __DIR__ . '/../../guardian/rolepod-wp-guardian.php';

// --- harness ----------------------------------------------------------------
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

echo "guardian boot-loop auto-heal\n";

// --- is_healable scope ------------------------------------------------------
check('plugin file healable', rolepod_guardian_is_healable('/x/wp-content/plugins/foo/foo.php'), true);
check('theme file healable', rolepod_guardian_is_healable('/x/wp-content/themes/bar/functions.php'), true);
check('mu-plugin file healable', rolepod_guardian_is_healable('/x/wp-content/mu-plugins/baz.php'), true);
check('core wp-includes NOT healable', rolepod_guardian_is_healable('/x/wp-includes/post.php'), false);
check('core wp-admin NOT healable', rolepod_guardian_is_healable('/x/wp-admin/menu.php'), false);
check('guardian itself NOT healable', rolepod_guardian_is_healable('/x/wp-content/mu-plugins/rolepod-wp-guardian.php'), false);

// --- streak: below threshold does nothing, no rename ------------------------
$badFile = $TMP . '/themes/rp-badtheme/functions.php';
file_put_contents($badFile, "<?php // boom\n");
update_option(ROLEPOD_WP_GUARDIAN_AUTOHEAL_OPTION, true, false);

$rec = ['file' => $badFile, 'type' => E_ERROR, 'message' => 'fatal'];

rolepod_guardian_maybe_autoheal($rec); // streak 1
check('streak1: file still present', is_file($badFile), true);
rolepod_guardian_maybe_autoheal($rec); // streak 2
check('streak2: file still present', is_file($badFile), true);
$streak = get_option(ROLEPOD_WP_GUARDIAN_STREAK_OPTION);
check('streak2: count is 2', $streak['count'] ?? null, 2);

// --- threshold reached: file disabled + safe-mode + log ---------------------
rolepod_guardian_maybe_autoheal($rec); // streak 3 → heal
check('streak3: original gone', is_file($badFile), false);
check('streak3: .disabled exists', is_file($badFile . '.disabled'), true);
check('streak3: safe-mode raised', (bool) get_option(ROLEPOD_WP_GUARDIAN_SAFE_MODE_OPTION), true);
check('streak3: streak reset', get_option(ROLEPOD_WP_GUARDIAN_STREAK_OPTION, null), null);
$log = get_option(ROLEPOD_WP_GUARDIAN_AUTOHEAL_LOG_OPTION, []);
check('streak3: log has 1 entry', count($log), 1);
check('streak3: log action disable_file', $log[0]['action'] ?? null, 'disable_file');
check('streak3: log ok', $log[0]['ok'] ?? null, true);

// --- opt-out: disabled flag → no heal even past threshold -------------------
$GLOBALS['__opts'] = [];
$GLOBALS['__transients'] = [];
$badFile2 = $TMP . '/themes/rp-badtheme/style-loader.php';
file_put_contents($badFile2, "<?php // boom2\n");
update_option(ROLEPOD_WP_GUARDIAN_AUTOHEAL_OPTION, false, false); // OFF
$rec2 = ['file' => $badFile2, 'type' => E_ERROR, 'message' => 'fatal'];
for ($i = 0; $i < 5; $i++) {
    rolepod_guardian_maybe_autoheal($rec2);
}
check('opt-out: file untouched after 5 fatals', is_file($badFile2), true);

// --- different file resets streak (no cross-file accumulation) --------------
$GLOBALS['__opts'] = [];
update_option(ROLEPOD_WP_GUARDIAN_AUTOHEAL_OPTION, true, false);
$fA = $TMP . '/themes/rp-badtheme/a.php';
$fB = $TMP . '/themes/rp-badtheme/b.php';
file_put_contents($fA, "<?php\n");
file_put_contents($fB, "<?php\n");
rolepod_guardian_maybe_autoheal(['file' => $fA, 'type' => E_ERROR]); // A:1
rolepod_guardian_maybe_autoheal(['file' => $fB, 'type' => E_ERROR]); // B:1 (reset)
rolepod_guardian_maybe_autoheal(['file' => $fA, 'type' => E_ERROR]); // A:1 (reset again)
$streak = get_option(ROLEPOD_WP_GUARDIAN_STREAK_OPTION);
check('alternating files keep count at 1', $streak['count'] ?? null, 1);
check('alternating: A still present', is_file($fA), true);
check('alternating: B still present', is_file($fB), true);

// --- cleanup ----------------------------------------------------------------
exec('rm -rf ' . escapeshellarg($TMP));

echo "\n$count checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
