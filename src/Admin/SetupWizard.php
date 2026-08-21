<?php
declare(strict_types=1);

namespace Rolepod\Wp\Admin;

use Rolepod\Wp\Security\PairToken;
use Rolepod\Wp\Guardian;

/**
 * Setup wizard — top-level submenu "Setup".
 *
 * Multi-step onboarding matching brief/Demo/Rolepod for Wordpress/onboarding.jsx:
 *
 *   Quick path (4 steps):
 *     0 Choose path  →  1 Generate token  →  2 Connect AI CLI  →  3 Verify
 *
 *   Manual path (5 steps):
 *     0 Choose path  →  1 App password  →  2 Install MCP  →  3 Wire CLI  →  4 Verify
 *
 * State is encoded in URL query (`?step=N&path=quick|manual`). The minted
 * pair token is persisted via a user transient keyed on user_id (TTL 60min,
 * matches PairToken::ttlSeconds) so it survives the wizard's Continue/Back
 * navigation without ever appearing in the URL.
 *
 * No JS framework — server renders each step on POST/GET; vanilla JS
 * (admin.js) only adds copy-to-clipboard and confirm-before-submit.
 */
final class SetupWizard
{
    private const NONCE_ACTION = 'rolepod_wp_setup_step';
    private const TRANSIENT_PREFIX = 'rolepod_wp_setup_';

    public static function register(): void { /* registered via Menu::register() */ }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $path = self::sanitizePath($_GET['path'] ?? 'quick');
        $step = max(0, (int) ($_GET['step'] ?? 0));

        // Handle form submissions before rendering.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $next = self::handlePost($path);
            if ($next !== null) {
                $step = (int) $next['step'];
                $path = $next['path'];

                // Post/Redirect/Get is preferred so a refresh does not
                // resubmit. But a redirect is impossible once anything has
                // already flushed output (a stray newline after a closing PHP
                // tag in any other active plugin is enough), and
                // wp_safe_redirect() also returns false when a `wp_redirect`
                // filter cancels it. Both cases used to reach the `exit` below
                // anyway and serve a blank page, so the wizard looked dead when
                // it was only PRG that was unavailable. Fall through and render
                // the destination step inline instead.
                if (!headers_sent() && wp_safe_redirect(Menu::url(Menu::SLUG_SETUP) . '&step=' . $step . '&path=' . urlencode($path))) {
                    exit;
                }
            }
        }

        $totalSteps = $path === 'manual' ? 5 : 4;
        if ($step >= $totalSteps) {
            $step = $totalSteps - 1;
        }

        $quickLabels  = ['Choose path', 'Generate token', 'Connect AI CLI', 'Verify'];
        $manualLabels = ['Choose path', 'App password', 'Install MCP', 'Wire CLI', 'Verify'];

        $subtitle = 'Connect this WordPress site to your AI CLI.';
        Shell::open(Menu::SLUG_SETUP, 'Setup', $subtitle);

        echo '<div class="rp-wizard">';

        // Stepper. On Step 0 we render BOTH steppers so CSS `:has(input:checked)`
        // can swap them live as the user toggles the path radios — no JS needed.
        // On steps 1+ the path is locked and only the matching stepper renders.
        if ($step === 0) {
            self::renderStepper($quickLabels, $step, 'data-rp-stepper-for="quick"');
            self::renderStepper($manualLabels, $step, 'data-rp-stepper-for="manual"');
        } else {
            $labels = $path === 'manual' ? $manualLabels : $quickLabels;
            self::renderStepper($labels, $step);
        }

        // Body
        echo '<div class="rp-wizard-body">';
        echo '<div class="rp-wizard-body-inner">';

        if ($step === 0) {
            self::renderStepChoosePath($path);
        } elseif ($path === 'quick') {
            if ($step === 1) self::renderStepGenerateToken();
            elseif ($step === 2) self::renderStepConnectCli();
            elseif ($step === 3) self::renderStepVerify();
        } else {
            // manual
            if ($step === 1) self::renderStepManualAppPassword();
            elseif ($step === 2) self::renderStepManualInstall();
            elseif ($step === 3) self::renderStepManualWire();
            elseif ($step === 4) self::renderStepVerify();
        }

        echo '</div>'; // body-inner

        // Nav row
        self::renderNav($step, $totalSteps, $path);

        echo '</div>'; // body
        echo '</div>'; // wizard

        // On Step 0 we don't know the final path total yet (4 vs 5). Render
        // both footer variants and let CSS `:has(input:checked)` show the
        // matching one — same pattern as the stepper above.
        $landing = 'https://nuttaruj.github.io/rolepod-wplab/';
        $ghDocs  = 'https://github.com/nuttaruj/rolepod-wplab';
        if ($step === 0) {
            $tail = ' &middot; <a href="' . esc_url($landing) . '" target="_blank" rel="noopener">What can AI do here?</a>'
                  . ' &middot; <a href="' . esc_url($ghDocs) . '" target="_blank" rel="noopener">Docs &amp; source</a>';
            echo '<div class="rp-footer-hint" data-rp-footer-for="quick">Step 1 of 4' . $tail . '</div>';
            echo '<div class="rp-footer-hint" data-rp-footer-for="manual">Step 1 of 5' . $tail . '</div>';
        } else {
            Shell::footer('Step ' . ($step + 1) . ' of ' . $totalSteps);
        }
        Shell::close();
    }

    // ───────────────────────────── Step renderers ─────────────────────────────

    private static function renderStepChoosePath(string $currentPath): void
    {
        ?>
        <div class="rp-wizard-hero">
            <div class="rp-wizard-hero-icon"><?php echo self::iconPlug(26); ?></div>
            <h2>Connect your AI CLI</h2>
            <p>Pair this WordPress site to Claude Code, Cursor, Codex, or Gemini. Pick a path below.</p>
        </div>

        <form method="post" id="rp-choose-path-form">
            <?php wp_nonce_field(self::NONCE_ACTION, '_rp_setup_nonce'); ?>
            <input type="hidden" name="rp_action" value="choose_path">
            <div class="rp-path-grid">
                <label class="rp-path-card">
                    <input type="radio" name="path" value="quick" <?php checked($currentPath, 'quick'); ?>>
                    <span class="rp-path-card-badge">Recommended</span>
                    <div class="rp-path-card-icon"><?php echo self::iconBolt(19); ?></div>
                    <div class="rp-path-card-title">Quick start</div>
                    <div class="rp-path-card-desc">One-click pair token. Generates a ready-to-paste prompt &mdash; no manual App Password copy.</div>
                    <div class="rp-path-card-meta">
                        <span class="rp-chip"><?php echo self::iconClock(11); ?> ~ 60 sec</span>
                        <span class="rp-chip">3 steps</span>
                    </div>
                </label>
                <label class="rp-path-card">
                    <input type="radio" name="path" value="manual" <?php checked($currentPath, 'manual'); ?>>
                    <div class="rp-path-card-icon"><?php echo self::iconKey(19); ?></div>
                    <div class="rp-path-card-title">Manual setup</div>
                    <div class="rp-path-card-desc">Create your own App Password and wire the MCP yourself. Good for advanced flows.</div>
                    <div class="rp-path-card-meta">
                        <span class="rp-chip"><?php echo self::iconClock(11); ?> ~ 5 min</span>
                        <span class="rp-chip">4 steps</span>
                    </div>
                </label>
            </div>
        </form>
        <?php
    }

    private static function renderStepGenerateToken(): void
    {
        $tokenState = self::getStoredToken();
        $generated = $tokenState !== null;
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px;">
            <div>
                <h3 style="font-size:16px;font-weight:600;margin:0;">Generate a pair token</h3>
                <div style="color:var(--rp-text-muted);font-size:13px;margin-top:3px;">Single-use token, expires in 60 minutes.</div>
            </div>
            <?php if ($generated): ?>
                <span class="rp-badge rp-badge-success"><span class="rp-bd"></span>Active</span>
            <?php endif; ?>
        </div>

        <?php if (!$generated): ?>
            <div style="border:1.5px dashed var(--rp-border-strong);border-radius:12px;padding:30px;text-align:center;">
                <p style="margin:0 auto 14px;max-width:460px;color:var(--rp-text-muted);font-size:13px;line-height:1.6;">
                    The token is single-use. On redeem the companion mints an Application Password named <span class="rp-chip" style="font-size:11px;">wplab-pair-&lt;timestamp&gt;</span> &mdash; revocable from <span class="rp-muted">profile.php</span> any time.
                </p>
                <form method="post" style="margin:0;">
                    <?php wp_nonce_field(self::NONCE_ACTION, '_rp_setup_nonce'); ?>
                    <input type="hidden" name="rp_action" value="mint_token">
                    <button type="submit" class="rp-btn rp-btn-primary"><?php echo self::iconBolt(14); ?> Generate pair token</button>
                </form>
            </div>
        <?php else: ?>
            <?php
            $remainingSec = max(0, (int) ($tokenState['expires_at'] - time()));
            $mm = str_pad((string) (int) floor($remainingSec / 60), 2, '0', STR_PAD_LEFT);
            $ss = str_pad((string) ($remainingSec % 60), 2, '0', STR_PAD_LEFT);
            $isLow = $remainingSec < 300;
            ?>
            <div class="rp-pair-card" style="margin-bottom:16px;">
                <div class="rp-pair-token">
                    <div class="rp-label">Pair token</div>
                    <div class="rp-value" id="rp-pair-token-value"><?php echo esc_html($tokenState['token']); ?></div>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <div style="font-size:11px;color:var(--rp-text-muted);">Expires in</div>
                    <div class="rp-mono" style="font-size:17px;font-weight:600;color:<?php echo $isLow ? 'var(--rp-danger)' : 'var(--rp-text)'; ?>;"><?php echo esc_html($mm . ':' . $ss); ?></div>
                </div>
                <button type="button" class="rp-btn rp-btn-sm" data-rp-copy="#rp-pair-token-value"><?php echo self::iconCopy(13); ?><span data-rp-copy-label>Copy</span></button>
            </div>

            <div class="rp-info-tile-grid">
                <div class="rp-info-tile">
                    <div class="rp-info-tile-h"><div class="rp-info-tile-icon"><?php echo self::iconKey(13); ?></div><div class="rp-info-tile-label">Single-use</div></div>
                    <div class="rp-info-tile-desc">Token is consumed on first redeem</div>
                </div>
                <div class="rp-info-tile">
                    <div class="rp-info-tile-h"><div class="rp-info-tile-icon"><?php echo self::iconClock(13); ?></div><div class="rp-info-tile-label">60 min TTL</div></div>
                    <div class="rp-info-tile-desc">Auto-expires if unused</div>
                </div>
                <div class="rp-info-tile">
                    <div class="rp-info-tile-h"><div class="rp-info-tile-icon"><?php echo self::iconShield(13); ?></div><div class="rp-info-tile-label">Revocable</div></div>
                    <div class="rp-info-tile-desc">From profile.php any time</div>
                </div>
            </div>
        <?php endif; ?>
        <?php
    }

    private static function renderStepConnectCli(): void
    {
        $tokenState = self::getStoredToken();
        if ($tokenState === null) {
            echo '<p style="color:var(--rp-text-muted);">No active pair token. Go back to Step 2 and generate one.</p>';
            return;
        }
        $siteurl = get_site_url();
        $prompt = self::buildPrompt($siteurl, $tokenState['token']);
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px;">
            <div>
                <h3 style="font-size:16px;font-weight:600;margin:0;">Connect your AI CLI</h3>
                <div style="color:var(--rp-text-muted);font-size:13px;margin-top:3px;">Paste this prompt into any AI CLI &mdash; one prompt works for all.</div>
            </div>
            <button type="button" class="rp-btn rp-btn-primary rp-btn-sm" data-rp-copy="#rp-pair-prompt"><?php echo self::iconCopy(13); ?><span data-rp-copy-label>Copy prompt</span></button>
        </div>

        <textarea id="rp-pair-prompt" readonly rows="18" class="rp-input rp-input-mono" style="resize:vertical;"><?php echo esc_textarea($prompt); ?></textarea>

        <p class="rp-field-hint" style="margin-top:10px;">
            The AI will read the install instructions in our README, then call <code>rolepod_wp_pair</code> with this token. Move to <strong>Verify</strong> once the AI confirms the pair succeeded.
        </p>
        <?php
    }

    private static function renderStepVerify(): void
    {
        $verified = self::detectPairCompletion();
        ?>
        <div class="rp-verify">
            <?php if ($verified['ok']): ?>
                <div class="rp-verify-icon is-done"><?php echo self::iconCheck(30); ?></div>
                <h2>Connected &mdash; you're all set</h2>
                <p>Rolepod is now reachable from your AI CLI. Every write is logged in the Change Ledger and is reversible by row.</p>
                <div style="display:flex;gap:10px;justify-content:center;margin-top:22px;">
                    <a class="rp-btn rp-btn-primary" href="<?php echo esc_url(Menu::url(Menu::SLUG_CHANGES)); ?>"><?php echo self::iconList(13); ?> Open Change Ledger</a>
                    <a class="rp-btn" href="<?php echo esc_url(Menu::url(Menu::SLUG_SETTINGS)); ?>">Settings</a>
                </div>
                <div class="rp-verify-meta">
                    <div>
                        <div class="rp-verify-meta-label">App Password</div>
                        <div class="rp-verify-meta-value"><?php echo esc_html($verified['app_password_name'] ?? 'wplab-pair-…'); ?></div>
                    </div>
                    <div>
                        <div class="rp-verify-meta-label">Companion</div>
                        <div class="rp-verify-meta-value">v<?php echo esc_html(ROLEPOD_WP_VERSION); ?></div>
                    </div>
                    <div>
                        <div class="rp-verify-meta-label">Guardian</div>
                        <div class="rp-verify-meta-value" style="color:<?php echo Guardian::isInstalled() ? 'var(--rp-success)' : 'var(--rp-warning-text)'; ?>;font-family:inherit;font-weight:600;">
                            <?php echo Guardian::isInstalled() ? 'Installed' : 'Off'; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="rp-verify-icon is-waiting"><div class="rp-verify-spinner"></div></div>
                <h2>Waiting for first handshake&hellip;</h2>
                <p>We'll detect the connection automatically as soon as your AI CLI redeems the pair token. Click <strong>Re-test</strong> after the AI confirms.</p>
                <form method="post" style="margin-top:22px;">
                    <?php wp_nonce_field(self::NONCE_ACTION, '_rp_setup_nonce'); ?>
                    <input type="hidden" name="rp_action" value="recheck">
                    <button type="submit" class="rp-btn"><?php echo self::iconRefresh(13); ?> Re-test connection</button>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function renderStepManualAppPassword(): void
    {
        $appPasswordsUrl = admin_url('profile.php#application-passwords-section');
        ?>
        <h3 style="font-size:16px;font-weight:600;margin:0 0 10px;">Create an Application Password</h3>
        <p style="color:var(--rp-text-muted);font-size:13px;margin:0 0 16px;">WordPress will show the password only once &mdash; copy it before leaving the page.</p>
        <ol style="margin:0 0 16px 18px;padding:0;font-size:13.5px;line-height:1.8;">
            <li>Open <a href="<?php echo esc_url($appPasswordsUrl); ?>">profile.php &rarr; Application Passwords</a></li>
            <li>Name the password <code>rolepod-wplab</code></li>
            <li>Click <strong>Add New Application Password</strong></li>
            <li>Copy the password &mdash; you'll only see it once.</li>
        </ol>
        <div style="padding:10px 14px;background:var(--rp-warning-soft);color:var(--rp-warning-text);border-radius:8px;font-size:12.5px;display:flex;gap:8px;align-items:flex-start;">
            <?php echo self::iconAlert(15); ?>
            <span>Once you leave that page the password is gone &mdash; store it in a secret manager.</span>
        </div>
        <?php
    }

    private static function renderStepManualInstall(): void
    {
        ?>
        <h3 style="font-size:16px;font-weight:600;margin:0 0 10px;">Install the Node MCP locally</h3>
        <p style="color:var(--rp-text-muted);font-size:13px;margin:0 0 14px;">One-time global install. You'll wire it into your AI CLI on the next step.</p>
        <div class="rp-codeblock" id="rp-mcp-install"><span data-rp-copy-text>npm install -g @rolepod/wplab
rolepod-wplab doctor</span><button type="button" class="rp-codeblock-copy" data-rp-copy="#rp-mcp-install [data-rp-copy-text]"><span data-rp-copy-label>Copy</span></button></div>
        <p style="font-size:12.5px;color:var(--rp-text-muted);margin-top:10px;">The <span class="rp-chip">doctor</span> command verifies your Node version and reachability.</p>
        <?php
    }

    private static function renderStepManualWire(): void
    {
        $host = (string) wp_parse_url(get_site_url(), PHP_URL_HOST);
        $current_user = wp_get_current_user();
        $username = $current_user instanceof \WP_User ? $current_user->user_login : 'admin';
        $registerCmd = sprintf('rolepod-wplab credentials add %s --username=%s', escapeshellarg($host), escapeshellarg($username));
        ?>
        <h3 style="font-size:16px;font-weight:600;margin:0 0 10px;">Wire the MCP into your CLI</h3>
        <p style="color:var(--rp-text-muted);font-size:13px;margin:0 0 14px;">Register this site as a target, then add the MCP entry per your CLI's docs.</p>

        <div style="margin-bottom:14px;">
            <div class="rp-field-label">1. Register this site (paste your App Password when prompted)</div>
            <div class="rp-codeblock" id="rp-reg"><span data-rp-copy-text><?php echo esc_html($registerCmd); ?></span><button type="button" class="rp-codeblock-copy" data-rp-copy="#rp-reg [data-rp-copy-text]"><span data-rp-copy-label>Copy</span></button></div>
        </div>

        <div>
            <div class="rp-field-label">2. Add the MCP to your CLI</div>
            <p style="font-size:12.5px;color:var(--rp-text-muted);margin:0 0 8px;">Per-CLI install instructions live in the README so we don't drift them across places:</p>
            <a class="rp-btn rp-btn-primary" href="https://github.com/nuttaruj/rolepod-wplab#install" target="_blank" rel="noopener">Open README install section &rarr;</a>
        </div>
        <?php
    }

    // ───────────────────────────── Stepper + Nav + POST handler ─────────────────────────────

    /**
     * Render a stepper bar. When $attrs contains data-rp-stepper-for, the
     * caller can render BOTH a quick and manual variant on Step 0 and rely
     * on CSS `:has(input:checked)` to swap them live as the radio toggles.
     *
     * @param string[] $labels
     */
    private static function renderStepper(array $labels, int $currentStep, string $attrs = ''): void
    {
        echo '<div class="rp-wizard-stepper" ' . $attrs . '><div class="rp-stepper">';
        foreach ($labels as $i => $label) {
            $cls = '';
            if ($i < $currentStep) $cls = 'is-done';
            elseif ($i === $currentStep) $cls = 'is-active';
            echo '<div class="rp-step ' . esc_attr($cls) . '">';
            echo '  <div class="rp-step-dot">' . ($i < $currentStep ? '&#10003;' : (string) ($i + 1)) . '</div>';
            echo '  <div class="rp-step-label">' . esc_html($label) . '</div>';
            echo '</div>';
            if ($i < count($labels) - 1) {
                echo '<div class="rp-step-line"></div>';
            }
        }
        echo '</div></div>';
    }

    private static function renderNav(int $step, int $totalSteps, string $path): void
    {
        $isFirst = $step === 0;
        $isLast = $step === $totalSteps - 1;
        $prevUrl = !$isFirst ? Menu::url(Menu::SLUG_SETUP) . '&step=' . ($step - 1) . '&path=' . urlencode($path) : null;
        $nextUrl = !$isLast ? Menu::url(Menu::SLUG_SETUP) . '&step=' . ($step + 1) . '&path=' . urlencode($path) : null;
        $tokenState = self::getStoredToken();
        $nextDisabled = false;

        if ($step === 0) {
            // The Choose path form has no submit button — Continue posts it.
            $nextDisabled = false;
        }
        if ($path === 'quick' && $step === 1 && $tokenState === null) {
            $nextDisabled = true;
        }

        ?>
        <div class="rp-wizard-nav">
            <?php if ($prevUrl !== null): ?>
                <a class="rp-btn rp-btn-ghost" href="<?php echo esc_url($prevUrl); ?>"><?php echo self::iconChevL(13); ?> Back</a>
            <?php else: ?>
                <span></span>
            <?php endif; ?>
            <div class="rp-spacer"></div>
            <?php if ($step === 0): ?>
                <button type="submit" form="rp-choose-path-form" class="rp-btn rp-btn-primary">Continue <?php echo self::iconChevR(13); ?></button>
            <?php elseif (!$isLast): ?>
                <?php if ($nextDisabled): ?>
                    <span class="rp-btn rp-btn-primary" style="opacity:.5;cursor:not-allowed;" aria-disabled="true">Continue <?php echo self::iconChevR(13); ?></span>
                <?php else: ?>
                    <a class="rp-btn rp-btn-primary" href="<?php echo esc_url($nextUrl); ?>">Continue <?php echo self::iconChevR(13); ?></a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function handlePost(string $path): ?array
    {
        if (
            !isset($_POST['_rp_setup_nonce']) ||
            !wp_verify_nonce(sanitize_text_field((string) wp_unslash($_POST['_rp_setup_nonce'])), self::NONCE_ACTION)
        ) {
            return null;
        }
        $action = isset($_POST['rp_action']) ? sanitize_key((string) wp_unslash($_POST['rp_action'])) : '';

        switch ($action) {
            case 'choose_path':
                $chosen = self::sanitizePath($_POST['path'] ?? 'quick');
                return ['step' => 1, 'path' => $chosen];

            case 'mint_token':
                $token = PairToken::issue(get_current_user_id());
                self::storeToken($token);
                return ['step' => 2, 'path' => $path];

            case 'recheck':
                // Re-render the verify step (no state change beyond what
                // detectPairCompletion derives from existing data).
                $manualVerifyStep = $path === 'manual' ? 4 : 3;
                return ['step' => $manualVerifyStep, 'path' => $path];
        }
        return null;
    }

    // ───────────────────────────── State / detection helpers ─────────────────────────────

    private static function sanitizePath($raw): string
    {
        $v = sanitize_key((string) $raw);
        return $v === 'manual' ? 'manual' : 'quick';
    }

    /**
     * @return array{token:string,expires_at:int}|null
     */
    private static function getStoredToken(): ?array
    {
        $val = get_transient(self::TRANSIENT_PREFIX . get_current_user_id());
        if (!is_array($val) || !isset($val['token'], $val['expires_at'])) {
            return null;
        }
        if ((int) $val['expires_at'] <= time()) {
            delete_transient(self::TRANSIENT_PREFIX . get_current_user_id());
            return null;
        }
        return ['token' => (string) $val['token'], 'expires_at' => (int) $val['expires_at']];
    }

    private static function storeToken(string $token): void
    {
        $ttl = PairToken::ttlSeconds();
        set_transient(
            self::TRANSIENT_PREFIX . get_current_user_id(),
            ['token' => $token, 'expires_at' => time() + $ttl],
            $ttl
        );
    }

    /**
     * Detect whether the pair flow completed by looking for an Application
     * Password named with the `wplab-pair-` prefix on the current user.
     *
     * Free of network calls — reads only WP's own user_meta-backed app
     * password store. Safe to call on every Verify render.
     *
     * @return array{ok:bool, app_password_name?:string}
     */
    private static function detectPairCompletion(): array
    {
        if (!class_exists('WP_Application_Passwords')) {
            return ['ok' => false];
        }
        $userId = get_current_user_id();
        $passwords = \WP_Application_Passwords::get_user_application_passwords($userId);
        if (!is_array($passwords)) {
            return ['ok' => false];
        }
        foreach ($passwords as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if (strpos($name, 'wplab-pair-') === 0 || strpos($name, 'rolepod-wp-pair-') === 0) {
                return ['ok' => true, 'app_password_name' => $name];
            }
        }
        return ['ok' => false];
    }

    /**
     * Build the ready-to-paste setup prompt.
     *
     * v2.8.1: shortened from ~50 lines to ~14. Per-CLI install snippets
     * removed — the prompt now links to the canonical install docs in
     * the MCP repo's README so install instructions stay in one source
     * of truth and the paste body stays small enough to read in one
     * glance. Single prompt for every CLI.
     */
    private static function buildPrompt(string $siteurl, string $pairToken): string
    {
        $docs = 'https://github.com/nuttaruj/rolepod-wplab#install';
        $lines = [];
        $lines[] = '=== rolepod-wplab one-click pair ===';
        $lines[] = '';
        $lines[] = 'Connect this WordPress site to your AI CLI.';
        $lines[] = '';
        $lines[] = "  Site URL:   {$siteurl}";
        $lines[] = "  Pair token: {$pairToken}    (single-use, expires in 60 min)";
        $lines[] = '';
        $lines[] = 'Step 1 — Install rolepod-wplab on your CLI per the README:';
        $lines[] = "  {$docs}";
        $lines[] = '';
        $lines[] = 'Step 2 — Once installed, call:';
        $lines[] = '  rolepod_wp_pair {';
        $lines[] = "    \"siteurl\": \"{$siteurl}\",";
        $lines[] = "    \"pair_token\": \"{$pairToken}\"";
        $lines[] = '  }';
        $lines[] = '';
        $lines[] = 'Step 3 — Verify:';
        $lines[] = '  rolepod_wp_health_check { "target_id": "<from-step-2>" }';
        $lines[] = '';
        $lines[] = '=== end ===';
        return implode("\n", $lines);
    }

    // ───────────────────────────── Inline SVG icons ─────────────────────────────

    private static function iconPlug(int $size = 18): string
    {
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="M9 8V4M15 8V4M7 8h10v4a5 5 0 0 1-10 0z M12 17v4"/></svg>';
    }
    private static function iconBolt(int $size = 14): string
    {
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg>';
    }
    private static function iconKey(int $size = 14): string
    {
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><circle cx="7.5" cy="15.5" r="4"/><path d="m10.5 13 9-9M16 7l3 3M14 9l3 3"/></svg>';
    }
    private static function iconClock(int $size = 14): string
    {
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
    }
    private static function iconShield(int $size = 14): string
    {
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/></svg>';
    }
    private static function iconCopy(int $size = 14): string
    {
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/></svg>';
    }
    private static function iconCheck(int $size = 14): string
    {
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 12l5 5L20 6"/></svg>';
    }
    private static function iconAlert(int $size = 14): string
    {
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="M12 3 2 20h20z"/><path d="M12 10v4"/><circle cx="12" cy="17" r=".6" fill="currentColor"/></svg>';
    }
    private static function iconRefresh(int $size = 14): string
    {
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12a8 8 0 0 1 14-5m2 5a8 8 0 0 1-14 5M18 3v5h-5M6 21v-5h5"/></svg>';
    }
    private static function iconList(int $size = 14): string
    {
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg>';
    }
    private static function iconChevL(int $size = 14): string
    {
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>';
    }
    private static function iconChevR(int $size = 14): string
    {
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';
    }
}
