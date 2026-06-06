<?php
declare(strict_types=1);

namespace Rolepod\Wp\Admin;

use Rolepod\Wp\Media\Optimizer;
use Rolepod\Wp\Media\Queue;
use Rolepod\Wp\Media\Stats;

/**
 * Admin "Media" page — image-optimize dashboard.
 *
 * Shows the current library size, the cumulative impact of our optimizer
 * (images done, bytes + % saved), and the throttled background queue with
 * start / process-now / pause / resume / clear controls.
 */
final class MediaPage
{
    private const NONCE_ACTION = 'rolepod_wp_media';

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $notice = null;
        if (
            isset($_POST['rolepod_wp_media_nonce'])
            && wp_verify_nonce(
                sanitize_text_field((string) wp_unslash($_POST['rolepod_wp_media_nonce'])),
                self::NONCE_ACTION
            )
        ) {
            $notice = self::handleAction();
        }

        $stats = Stats::get();
        $library = Stats::libraryBytes();
        $queue = Queue::status();
        $saved = Stats::totalSaved();
        $pct = Stats::percentSaved();

        Shell::open(Menu::SLUG_MEDIA, 'Media', 'Throttled image optimization.');

        if ($notice !== null) {
            echo '<div class="notice notice-' . esc_attr($notice['type']) . ' is-dismissible"><p>' . wp_kses_post($notice['message']) . '</p></div>';
        }
        ?>
        <div class="rp-grid-main">
            <div>
                <!-- Impact -->
                <div class="rp-card">
                    <div class="rp-card-head">
                        <div>
                            <h3>Optimization impact</h3>
                            <div class="rp-sub">Cumulative across every optimize this plugin has run.</div>
                        </div>
                        <span class="rp-badge rp-badge-success"><?php echo esc_html((string) $pct); ?>% saved</span>
                    </div>
                    <div class="rp-card-pad">
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;">
                            <?php
                            self::stat('Images optimized', number_format_i18n($stats['optimized_count']));
                            self::stat('Total saved', size_format($saved, 1) ?: '0 B');
                            self::stat('Reduction', $pct . '%');
                            ?>
                        </div>
                        <?php if ($stats['optimized_count'] > 0): ?>
                            <div style="margin-top:14px;font-size:12.5px;color:var(--rp-text-muted);">
                                <?php echo esc_html(size_format($stats['bytes_before'], 1)); ?>
                                &rarr; <?php echo esc_html(size_format($stats['bytes_after'], 1)); ?>
                                <?php if ($stats['last_run_at'] > 0): ?>
                                    &middot; last run <?php echo esc_html(human_time_diff($stats['last_run_at'])); ?> ago
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p style="margin:12px 0 0;color:var(--rp-text-muted);"><em>Nothing optimized yet. Queue your library below.</em></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Background queue -->
                <div class="rp-card">
                    <div class="rp-card-head">
                        <div>
                            <h3>Background queue</h3>
                            <div class="rp-sub">Small batches on a cron tick &mdash; never spikes CPU.</div>
                        </div>
                        <span class="rp-badge <?php echo $queue['status'] === 'running' ? 'rp-badge-success' : 'rp-badge-neutral'; ?>"><?php echo esc_html(ucfirst((string) $queue['status'])); ?></span>
                    </div>
                    <div class="rp-card-pad">
                        <?php if (($queue['total'] ?? 0) > 0): ?>
                            <?php
                            $total = (int) $queue['total'];
                            $done = (int) $queue['done'];
                            $pctDone = $total > 0 ? (int) round($done / $total * 100) : 0;
                            ?>
                            <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:6px;">
                                <span id="rp-mq-stat"><?php echo esc_html($done . ' / ' . $total); ?> processed</span>
                                <span class="rp-mono" id="rp-mq-pct"><?php echo esc_html((string) $pctDone); ?>%</span>
                            </div>
                            <div style="height:8px;border-radius:6px;background:var(--rp-surface-sunken);overflow:hidden;">
                                <div id="rp-mq-bar" style="height:100%;width:<?php echo (int) $pctDone; ?>%;background:var(--rp-accent,#2563eb);transition:width .6s ease;"></div>
                            </div>
                            <div style="margin-top:8px;font-size:12px;color:var(--rp-text-muted);">
                                <?php if ($queue['status'] === 'running'): ?>
                                    Optimizing automatically in the background — this page updates live. You can leave or come back later.
                                <?php else: ?>
                                    <?php echo esc_html((int) $queue['remaining']); ?> remaining
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p style="margin:0;color:var(--rp-text-muted);"><em>Queue is empty.</em></p>
                        <?php endif; ?>

                        <form method="post" style="margin-top:14px;display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;">
                            <?php wp_nonce_field(self::NONCE_ACTION, 'rolepod_wp_media_nonce'); ?>
                            <label style="font-size:12px;">Min size (KB)<br>
                                <input type="number" name="min_kb" value="200" min="1" style="width:90px;padding:6px;">
                            </label>
                            <label style="font-size:12px;">Max dimension (px, 0=off)<br>
                                <input type="number" name="max_dim" value="2560" min="0" style="width:120px;padding:6px;">
                            </label>
                            <label style="font-size:12px;">Quality<br>
                                <input type="number" name="quality" value="82" min="1" max="100" style="width:80px;padding:6px;">
                            </label>
                            <button type="submit" name="media_action" value="enqueue" class="rp-btn rp-btn-primary">Queue library</button>
                        </form>

                        <form method="post" style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;">
                            <?php wp_nonce_field(self::NONCE_ACTION, 'rolepod_wp_media_nonce'); ?>
                            <?php if ($queue['status'] === 'running'): ?>
                                <button type="submit" name="media_action" value="pause" class="rp-btn rp-btn-sm rp-btn-ghost">Pause</button>
                            <?php elseif ($queue['status'] === 'paused'): ?>
                                <button type="submit" name="media_action" value="resume" class="rp-btn rp-btn-sm rp-btn-primary">Resume</button>
                            <?php endif; ?>
                            <?php if (($queue['total'] ?? 0) > 0): ?>
                                <button type="submit" name="media_action" value="clear" class="rp-btn rp-btn-sm rp-btn-ghost" data-rp-confirm="Clear the optimize queue? Already-optimized images keep their savings.">Clear queue</button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Right column -->
            <aside class="rp-stack">
                <div class="rp-card">
                    <div class="rp-card-head" style="padding:14px 18px 12px;">
                        <div>
                            <h3 style="font-size:13.5px;">Media library</h3>
                            <div class="rp-sub" style="font-size:12px;">Image originals on disk</div>
                        </div>
                    </div>
                    <div style="padding:12px 18px;">
                        <div style="display:grid;gap:8px;">
                            <?php
                            self::stat('Images', number_format_i18n($library['count']));
                            self::stat('Total size', size_format($library['bytes'], 1) ?: '0 B');
                            ?>
                        </div>
                        <form method="post" style="margin-top:12px;">
                            <?php wp_nonce_field(self::NONCE_ACTION, 'rolepod_wp_media_nonce'); ?>
                            <button type="submit" name="media_action" value="refresh_library" class="rp-btn rp-btn-sm rp-btn-ghost">Refresh<?php echo $library['cached'] ? ' (cached)' : ''; ?></button>
                            <button type="submit" name="media_action" value="reset_stats" class="rp-btn rp-btn-sm rp-btn-ghost" data-rp-confirm="Reset cumulative optimize stats? Files are not changed.">Reset stats</button>
                        </form>
                    </div>
                </div>
            </aside>
        </div>
        <?php
        if (($queue['status'] ?? '') === 'running') {
            self::livePollScript();
        }
        Shell::footer('Optimization runs in the background in small bursts — no CPU spike. Keeps going even if you close this page.');
        Shell::close();
    }

    /** In-place live progress via AJAX poll — no page reload (mirrors BackupPage). */
    private static function livePollScript(): void
    {
        $nonce = wp_create_nonce(self::NONCE_ACTION . '_poll');
        ?>
        <script>
        (function () {
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var url = (window.ajaxurl || '<?php echo esc_js(admin_url('admin-ajax.php')); ?>');
            function poll() {
                fetch(url + '?action=rolepod_wp_media_poll&_wpnonce=' + encodeURIComponent(nonce), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        var bar = document.getElementById('rp-mq-bar');
                        var pct = document.getElementById('rp-mq-pct');
                        var stat = document.getElementById('rp-mq-stat');
                        if (bar) bar.style.width = (d.percent | 0) + '%';
                        if (pct) pct.textContent = (d.percent | 0) + '%';
                        if (stat && d.line) stat.textContent = d.line;
                        if (d.status === 'running') { setTimeout(poll, 1200); }
                        else { window.location = window.location.pathname + window.location.search; }
                    })
                    .catch(function () { setTimeout(poll, 3000); });
            }
            setTimeout(poll, 1200);
        })();
        </script>
        <?php
    }

    /** admin-ajax: compact live queue status + re-arm the loopback chain. */
    public static function ajaxPoll(): void
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce((string) ($_REQUEST['_wpnonce'] ?? ''), self::NONCE_ACTION . '_poll')) {
            wp_send_json_error([], 403);
        }
        $q = Queue::status();
        if (($q['status'] ?? '') === 'running') {
            Queue::spawnLoopback();
        }
        $total = (int) ($q['total'] ?? 0);
        $done = (int) ($q['done'] ?? 0);
        wp_send_json([
            'status' => $q['status'] ?? 'idle',
            'percent' => $total > 0 ? (int) round($done / $total * 100) : 0,
            'line' => $done . ' / ' . $total . ' processed',
        ]);
    }

    private static function stat(string $label, string $value): void
    {
        echo '<div>';
        echo '<div style="font-size:11.5px;color:var(--rp-text-muted);text-transform:uppercase;letter-spacing:.04em;">' . esc_html($label) . '</div>';
        echo '<div style="font-size:20px;font-weight:600;margin-top:2px;">' . esc_html($value) . '</div>';
        echo '</div>';
    }

    private static function handleAction(): ?array
    {
        if (!current_user_can('manage_options')) {
            return null;
        }
        $action = isset($_POST['media_action'])
            ? sanitize_text_field((string) wp_unslash($_POST['media_action']))
            : '';

        if ($action === 'enqueue' && get_option('rolepod_wp_safe_mode', false)) {
            return ['type' => 'warning', 'message' => 'Guardian safe-mode is on — media writes are paused. Clear safe-mode on the Settings page first.'];
        }

        switch ($action) {
            case 'enqueue':
                $minBytes = max(1, (int) ($_POST['min_kb'] ?? 200) * 1024);
                $maxDim = max(0, (int) ($_POST['max_dim'] ?? 0));
                $quality = max(1, min(100, (int) ($_POST['quality'] ?? 82)));
                $items = Optimizer::scan(5000);
                $candidates = Optimizer::selectCandidates($items, $minBytes, 0);
                $ids = array_map(static fn(array $c): int => $c['id'], $candidates);
                $status = Queue::enqueue($ids, ['max_dimension' => $maxDim, 'quality' => $quality]);
                if ($ids === []) {
                    return ['type' => 'info', 'message' => 'No images over that size threshold — nothing to queue.'];
                }
                return ['type' => 'success', 'message' => 'Queued <strong>' . count($ids) . '</strong> image' . (count($ids) === 1 ? '' : 's') . ' for throttled background optimization.'];
            case 'pause':
                Queue::pause();
                return ['type' => 'info', 'message' => 'Queue paused.'];
            case 'resume':
                Queue::resume();
                return ['type' => 'success', 'message' => 'Queue resumed.'];
            case 'clear':
                Queue::clear();
                return ['type' => 'info', 'message' => 'Queue cleared.'];
            case 'refresh_library':
                Stats::libraryBytes(true);
                return ['type' => 'success', 'message' => 'Library size refreshed.'];
            case 'reset_stats':
                Stats::reset();
                return ['type' => 'info', 'message' => 'Cumulative stats reset.'];
        }
        return null;
    }
}
