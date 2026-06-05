<?php
declare(strict_types=1);

namespace Rolepod\Wp\Admin;

use Rolepod\Wp\Backup\Archive;
use Rolepod\Wp\Backup\Engine;
use Rolepod\Wp\Backup\RestoreEngine;

/**
 * Admin "Backup" page.
 *
 * Start a throttled background backup, watch its progress, and manage finished
 * backups — including "Browse inside" which reads a backup zip's central
 * directory + manifest.json WITHOUT extracting the archive.
 */
final class BackupPage
{
    private const NONCE_ACTION = 'rolepod_wp_backup';

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $notice = null;
        if (
            isset($_POST['rolepod_wp_backup_nonce'])
            && wp_verify_nonce(sanitize_text_field((string) wp_unslash($_POST['rolepod_wp_backup_nonce'])), self::NONCE_ACTION)
        ) {
            $notice = self::handleAction();
        }

        $job = Engine::status();
        $restore = RestoreEngine::status();
        $history = Engine::history();
        $inspectId = isset($_GET['rp_inspect']) ? sanitize_text_field((string) wp_unslash($_GET['rp_inspect'])) : '';
        $restoreId = isset($_GET['rp_restore']) ? sanitize_text_field((string) wp_unslash($_GET['rp_restore'])) : '';

        Shell::open(Menu::SLUG_BACKUP, 'Backup', 'Throttled site backups.');

        if ($notice !== null) {
            echo '<div class="notice notice-' . esc_attr($notice['type']) . ' is-dismissible"><p>' . wp_kses_post($notice['message']) . '</p></div>';
        }

        if ($inspectId !== '') {
            self::renderInspect($inspectId, $history);
            Shell::footer();
            Shell::close();
            return;
        }
        if ($restoreId !== '') {
            self::renderRestoreConfirm($restoreId, $history);
            Shell::footer();
            Shell::close();
            return;
        }
        ?>
        <div class="rp-grid-main">
            <div>
                <?php self::renderRestoreCard($restore); ?>
                <?php self::renderJobCard($job); ?>
                <?php self::renderHistoryCard($history); ?>
            </div>
            <aside class="rp-stack">
                <?php self::renderStartCard($job); ?>
            </aside>
        </div>
        <?php
        Shell::footer('Backups run in small cron batches — no CPU spike. Stored under uploads/rolepod-wp/backups/ (HTTP-denied).');
        Shell::close();
    }

    private static function renderStartCard(array $job): void
    {
        $running = ($job['status'] ?? '') === 'running';
        ?>
        <form method="post">
            <?php wp_nonce_field(self::NONCE_ACTION, 'rolepod_wp_backup_nonce'); ?>
            <div class="rp-card">
                <div class="rp-card-head" style="padding:14px 18px 12px;">
                    <div><h3 style="font-size:13.5px;">New backup</h3><div class="rp-sub" style="font-size:12px;">Pick what to include</div></div>
                </div>
                <div style="padding:12px 18px;">
                    <div style="display:grid;gap:8px;font-size:13px;">
                        <?php
                        self::checkbox('c_db', 'Database', true);
                        self::checkbox('c_uploads', 'Uploads (media)', true);
                        self::checkbox('c_themes', 'Themes', true);
                        self::checkbox('c_plugins', 'Plugins', false);
                        self::checkbox('c_muplugins', 'mu-plugins', false);
                        ?>
                        <label style="display:flex;align-items:center;gap:8px;margin-top:6px;border-top:1px solid var(--rp-border);padding-top:10px;">
                            <input type="checkbox" name="compress" value="1" checked>
                            <span>Compress text/SQL (media stored as-is)</span>
                        </label>
                    </div>
                    <button type="submit" name="backup_action" value="start" class="rp-btn rp-btn-primary" style="margin-top:14px;width:100%;" <?php disabled($running); ?>>
                        <?php echo $running ? 'Backup running…' : 'Start backup'; ?>
                    </button>
                </div>
            </div>
        </form>
        <?php
    }

    private static function renderJobCard(array $job): void
    {
        $status = (string) ($job['status'] ?? 'idle');
        if ($status !== 'running' && $status !== 'error') {
            return;
        }
        $pct = (int) ($job['percent'] ?? 0);
        ?>
        <div class="rp-card">
            <div class="rp-card-head">
                <div><h3>Current backup</h3><div class="rp-sub"><?php echo esc_html((string) ($job['id'] ?? '')); ?> &middot; stage: <?php echo esc_html((string) ($job['stage'] ?? '')); ?></div></div>
                <span class="rp-badge <?php echo $status === 'error' ? 'rp-badge-danger' : 'rp-badge-success'; ?>"><?php echo esc_html(ucfirst($status)); ?></span>
            </div>
            <div class="rp-card-pad">
                <?php if ($status === 'error'): ?>
                    <div class="rp-tl-err"><?php echo esc_html((string) ($job['error'] ?? 'unknown error')); ?></div>
                <?php else: ?>
                    <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:6px;">
                        <span><?php echo esc_html((string) ($job['stats']['files_count'] ?? 0)); ?> files &middot; <?php echo esc_html((string) ($job['db_progress']['rows'] ?? 0)); ?> rows</span>
                        <span class="rp-mono"><?php echo (int) $pct; ?>%</span>
                    </div>
                    <div style="height:8px;border-radius:6px;background:var(--rp-surface-sunken);overflow:hidden;">
                        <div style="height:100%;width:<?php echo (int) $pct; ?>%;background:var(--rp-accent,#2563eb);transition:width .3s;"></div>
                    </div>
                    <div style="margin-top:8px;font-size:12px;color:var(--rp-text-muted);">cron <?php echo !empty($job['scheduled']) ? 'scheduled' : 'idle'; ?> &middot; runs in background</div>
                <?php endif; ?>
                <form method="post" style="margin-top:12px;display:flex;gap:6px;">
                    <?php wp_nonce_field(self::NONCE_ACTION, 'rolepod_wp_backup_nonce'); ?>
                    <?php if ($status === 'running'): ?>
                        <button type="submit" name="backup_action" value="run_now" class="rp-btn rp-btn-sm">Process now</button>
                    <?php endif; ?>
                    <button type="submit" name="backup_action" value="cancel" class="rp-btn rp-btn-sm rp-btn-ghost" data-rp-confirm="Cancel this backup? Partial archive is discarded.">Cancel</button>
                </form>
            </div>
        </div>
        <?php
    }

    private static function renderHistoryCard(array $history): void
    {
        ?>
        <div class="rp-card">
            <div class="rp-card-head"><div><h3>Backups</h3><div class="rp-sub"><?php echo (int) count($history); ?> stored</div></div></div>
            <div class="rp-card-pad">
                <?php if ($history === []): ?>
                    <p style="margin:0;color:var(--rp-text-muted);"><em>No backups yet.</em></p>
                <?php else: ?>
                    <div style="display:grid;gap:8px;">
                        <?php foreach ($history as $b): ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;border:1px solid var(--rp-border);border-radius:6px;padding:9px 12px;">
                                <div style="min-width:0;">
                                    <div class="rp-mono" style="font-size:12px;"><?php echo esc_html((string) ($b['id'] ?? '')); ?></div>
                                    <div style="font-size:11.5px;color:var(--rp-text-muted);">
                                        <?php echo esc_html(size_format((int) ($b['zip_bytes'] ?? 0), 1) ?: '0 B'); ?>
                                        &middot; <?php echo esc_html((string) ($b['files_count'] ?? 0)); ?> files
                                        &middot; <?php echo esc_html(human_time_diff((int) ($b['created_at'] ?? time()))); ?> ago
                                    </div>
                                </div>
                                <div style="display:flex;gap:6px;flex-shrink:0;">
                                    <a class="rp-btn rp-btn-sm rp-btn-ghost" href="<?php echo esc_url(add_query_arg('rp_inspect', (string) $b['id'], Menu::url(Menu::SLUG_BACKUP))); ?>">Browse</a>
                                    <a class="rp-btn rp-btn-sm" href="<?php echo esc_url(add_query_arg('rp_restore', (string) $b['id'], Menu::url(Menu::SLUG_BACKUP))); ?>">Restore</a>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field(self::NONCE_ACTION, 'rolepod_wp_backup_nonce'); ?>
                                        <input type="hidden" name="backup_id" value="<?php echo esc_attr((string) $b['id']); ?>">
                                        <button type="submit" name="backup_action" value="download" class="rp-btn rp-btn-sm">Download</button>
                                        <button type="submit" name="backup_action" value="delete" class="rp-btn rp-btn-sm rp-btn-ghost" data-rp-confirm="Delete this backup file?">Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function renderInspect(string $id, array $history): void
    {
        $zip = null;
        foreach ($history as $b) {
            if (($b['id'] ?? '') === $id && !empty($b['zip_path'])) {
                $zip = (string) $b['zip_path'];
            }
        }
        echo '<p><a href="' . esc_url(Menu::url(Menu::SLUG_BACKUP)) . '">&larr; Back to backups</a></p>';
        if ($zip === null || !is_file($zip)) {
            echo '<div class="rp-card"><div class="rp-card-pad"><em>Backup not found.</em></div></div>';
            return;
        }

        // Read manifest.json + the entry list WITHOUT extracting the archive.
        $manifest = Archive::readEntry($zip, 'manifest.json', 200_000);
        $list = Archive::listEntries($zip, 2000);
        ?>
        <div class="rp-card">
            <div class="rp-card-head"><div><h3>Inside <?php echo esc_html($id); ?></h3><div class="rp-sub">Read from the zip index &mdash; not extracted.</div></div></div>
            <div class="rp-card-pad">
                <?php if (!empty($manifest['ok'])): ?>
                    <h4 style="margin:0 0 6px;font-size:12.5px;">manifest.json</h4>
                    <pre style="max-height:260px;overflow:auto;background:var(--rp-surface-sunken);padding:10px;border-radius:6px;font-size:11.5px;"><?php echo esc_html((string) $manifest['content']); ?></pre>
                <?php endif; ?>
                <h4 style="margin:14px 0 6px;font-size:12.5px;">Entries (<?php echo (int) ($list['total'] ?? 0); ?>)<?php echo !empty($list['truncated']) ? ' — first ' . (int) count($list['entries'] ?? []) : ''; ?></h4>
                <div style="max-height:360px;overflow:auto;border:1px solid var(--rp-border);border-radius:6px;">
                    <table style="width:100%;border-collapse:collapse;font-size:11.5px;">
                        <?php foreach (($list['entries'] ?? []) as $e): ?>
                            <tr style="border-bottom:1px solid var(--rp-border);">
                                <td class="rp-mono" style="padding:5px 9px;word-break:break-all;"><?php echo esc_html((string) $e['name']); ?></td>
                                <td style="padding:5px 9px;text-align:right;white-space:nowrap;color:var(--rp-text-muted);"><?php echo esc_html(size_format((int) $e['size']) ?: '0 B'); ?></td>
                                <td style="padding:5px 9px;text-align:right;white-space:nowrap;color:var(--rp-text-muted);"><?php echo (int) $e['method'] === 0 ? 'store' : 'deflate'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    private static function renderRestoreCard(array $r): void
    {
        $status = (string) ($r['status'] ?? 'idle');
        if ($status !== 'running' && $status !== 'error') {
            return;
        }
        $pct = (int) ($r['percent'] ?? 0);
        ?>
        <div class="rp-card">
            <div class="rp-card-head">
                <div><h3>Restore in progress</h3><div class="rp-sub"><?php echo esc_html((string) ($r['id'] ?? '')); ?> &middot; stage: <?php echo esc_html((string) ($r['stage'] ?? '')); ?></div></div>
                <span class="rp-badge <?php echo $status === 'error' ? 'rp-badge-danger' : 'rp-badge-success'; ?>"><?php echo esc_html(ucfirst($status)); ?></span>
            </div>
            <div class="rp-card-pad">
                <?php if ($status === 'error'): ?>
                    <div class="rp-tl-err"><?php echo esc_html((string) ($r['error'] ?? 'unknown error')); ?></div>
                <?php else: ?>
                    <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:6px;">
                        <span><?php echo (int) ($r['db']['statements'] ?? 0); ?> SQL stmts &middot; <?php echo (int) ($r['files']['restored'] ?? 0); ?> files &middot; <?php echo (int) ($r['rewrite']['rows_changed'] ?? 0); ?> rows rewritten</span>
                        <span class="rp-mono"><?php echo (int) $pct; ?>%</span>
                    </div>
                    <div style="height:8px;border-radius:6px;background:var(--rp-surface-sunken);overflow:hidden;">
                        <div style="height:100%;width:<?php echo (int) $pct; ?>%;background:var(--rp-accent,#2563eb);transition:width .3s;"></div>
                    </div>
                <?php endif; ?>
                <form method="post" style="margin-top:12px;display:flex;gap:6px;">
                    <?php wp_nonce_field(self::NONCE_ACTION, 'rolepod_wp_backup_nonce'); ?>
                    <?php if ($status === 'running'): ?>
                        <button type="submit" name="backup_action" value="restore_run_now" class="rp-btn rp-btn-sm">Process now</button>
                    <?php endif; ?>
                    <button type="submit" name="backup_action" value="restore_cancel" class="rp-btn rp-btn-sm rp-btn-ghost">Dismiss</button>
                </form>
            </div>
        </div>
        <?php
    }

    private static function renderRestoreConfirm(string $id, array $history): void
    {
        $zip = null;
        foreach ($history as $b) {
            if (($b['id'] ?? '') === $id && !empty($b['zip_path'])) {
                $zip = (string) $b['zip_path'];
            }
        }
        echo '<p><a href="' . esc_url(Menu::url(Menu::SLUG_BACKUP)) . '">&larr; Back to backups</a></p>';
        if ($zip === null || !is_file($zip)) {
            echo '<div class="rp-card"><div class="rp-card-pad"><em>Backup not found.</em></div></div>';
            return;
        }
        $manifest = Archive::readEntry($zip, 'manifest.json', 200_000);
        $mj = !empty($manifest['ok']) ? json_decode((string) $manifest['content'], true) : [];
        $homeUrl = (string) ($mj['site']['home_url'] ?? '');
        ?>
        <div class="rp-card">
            <div class="rp-card-head"><div><h3>Restore <?php echo esc_html($id); ?></h3><div class="rp-sub">Destructive — overwrites current data with the backup.</div></div><span class="rp-badge rp-badge-danger">Destructive</span></div>
            <div class="rp-card-pad">
                <div class="notice notice-warning inline" style="margin:0 0 14px;padding:8px 12px;"><p style="margin:0;">This replaces tables + files with the backup's contents. Consider creating a fresh backup first. The action runs throttled in the background.</p></div>
                <?php if (is_array($mj) && $mj !== []): ?>
                    <div style="font-size:12.5px;color:var(--rp-text-muted);margin-bottom:14px;">
                        From <strong><?php echo esc_html((string) ($mj['site']['name'] ?? '')); ?></strong>
                        &middot; <?php echo esc_html($homeUrl); ?>
                        &middot; WP <?php echo esc_html((string) ($mj['site']['wp_version'] ?? '?')); ?>
                        &middot; <?php echo esc_html((string) ($mj['components']['database']['rows'] ?? 0)); ?> db rows
                        &middot; <?php echo esc_html((string) ($mj['components']['files']['count'] ?? 0)); ?> files
                    </div>
                <?php endif; ?>
                <form method="post">
                    <?php wp_nonce_field(self::NONCE_ACTION, 'rolepod_wp_backup_nonce'); ?>
                    <input type="hidden" name="backup_id" value="<?php echo esc_attr($id); ?>">
                    <div style="display:grid;gap:8px;font-size:13px;margin-bottom:12px;">
                        <?php self::checkbox('r_db', 'Restore database', true); ?>
                        <?php self::checkbox('r_files', 'Restore files (wp-content)', true); ?>
                    </div>
                    <div style="border-top:1px solid var(--rp-border);padding-top:12px;margin-bottom:12px;">
                        <div style="font-size:12.5px;font-weight:600;margin-bottom:6px;">Rewrite URL (optional — for restoring onto a different domain)</div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <input type="text" name="old_url" value="<?php echo esc_attr($homeUrl); ?>" placeholder="https://old-domain" style="flex:1;min-width:180px;font-size:12px;padding:6px;">
                            <span style="align-self:center;">&rarr;</span>
                            <input type="text" name="new_url" value="<?php echo esc_attr((string) home_url()); ?>" placeholder="https://this-domain" style="flex:1;min-width:180px;font-size:12px;padding:6px;">
                        </div>
                        <div class="rp-desc" style="margin-top:5px;">Leave both equal (default) for a same-site restore — serialized data is updated safely if they differ.</div>
                    </div>
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
                        <input type="checkbox" name="confirm" value="1" required>
                        <span><strong>I understand this overwrites current data.</strong></span>
                    </label>
                    <button type="submit" name="backup_action" value="restore" class="rp-btn rp-btn-primary">Start restore</button>
                </form>
            </div>
        </div>
        <?php
    }

    private static function checkbox(string $name, string $label, bool $checked): void
    {
        echo '<label style="display:flex;align-items:center;gap:8px;">';
        echo '<input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked($checked, true, false) . '>';
        echo '<span>' . esc_html($label) . '</span></label>';
    }

    private static function handleAction(): ?array
    {
        if (!current_user_can('manage_options')) {
            return null;
        }
        $action = isset($_POST['backup_action']) ? sanitize_text_field((string) wp_unslash($_POST['backup_action'])) : '';

        switch ($action) {
            case 'start':
                $components = [
                    'db' => isset($_POST['c_db']),
                    'uploads' => isset($_POST['c_uploads']),
                    'themes' => isset($_POST['c_themes']),
                    'plugins' => isset($_POST['c_plugins']),
                    'muplugins' => isset($_POST['c_muplugins']),
                ];
                $r = Engine::start($components, ['compress' => isset($_POST['compress'])]);
                if (empty($r['ok'])) {
                    return ['type' => 'warning', 'message' => 'Could not start: ' . esc_html((string) ($r['error'] ?? 'unknown')) . '.'];
                }
                // Run the first chunk synchronously so progress shows immediately.
                Engine::tick();
                return ['type' => 'success', 'message' => 'Backup started — running in the background.'];
            case 'run_now':
                Engine::tick();
                return ['type' => 'info', 'message' => 'Processed one batch.'];
            case 'cancel':
                Engine::cancel();
                return ['type' => 'info', 'message' => 'Backup cancelled.'];
            case 'delete':
                $id = isset($_POST['backup_id']) ? sanitize_text_field((string) wp_unslash($_POST['backup_id'])) : '';
                return Engine::deleteBackup($id)
                    ? ['type' => 'info', 'message' => 'Backup deleted.']
                    : ['type' => 'warning', 'message' => 'Backup not found.'];
            case 'download':
                $id = isset($_POST['backup_id']) ? sanitize_text_field((string) wp_unslash($_POST['backup_id'])) : '';
                self::streamDownload($id); // exits on success
                return ['type' => 'warning', 'message' => 'Backup not found.'];
            case 'restore':
                return self::handleRestore();
            case 'restore_run_now':
                RestoreEngine::tick();
                return ['type' => 'info', 'message' => 'Processed one restore batch.'];
            case 'restore_cancel':
                RestoreEngine::cancel();
                return ['type' => 'info', 'message' => 'Restore dismissed.'];
        }
        return null;
    }

    private static function handleRestore(): ?array
    {
        $id = isset($_POST['backup_id']) ? sanitize_text_field((string) wp_unslash($_POST['backup_id'])) : '';
        $zip = null;
        foreach (Engine::history() as $b) {
            if (($b['id'] ?? '') === $id && !empty($b['zip_path'])) {
                $zip = (string) $b['zip_path'];
            }
        }
        if ($zip === null || !is_file($zip)) {
            return ['type' => 'warning', 'message' => 'Backup not found.'];
        }
        $components = ['db' => isset($_POST['r_db']), 'files' => isset($_POST['r_files'])];
        $oldUrl = isset($_POST['old_url']) ? esc_url_raw(trim((string) wp_unslash($_POST['old_url']))) : '';
        $newUrl = isset($_POST['new_url']) ? esc_url_raw(trim((string) wp_unslash($_POST['new_url']))) : '';
        $sr = ($oldUrl !== '' && $newUrl !== '' && $oldUrl !== $newUrl) ? [$oldUrl => $newUrl] : [];

        $r = RestoreEngine::start($zip, $components, ['confirm' => isset($_POST['confirm']), 'search_replace' => $sr]);
        if (empty($r['ok'])) {
            return ['type' => 'warning', 'message' => 'Could not start restore: ' . esc_html((string) ($r['error'] ?? 'unknown')) . '.'];
        }
        RestoreEngine::tick(); // first chunk now so progress shows
        return ['type' => 'success', 'message' => 'Restore started — running in the background.'];
    }

    /** Stream a backup zip through PHP (the dir is HTTP-denied). Exits on success. */
    private static function streamDownload(string $id): void
    {
        foreach (Engine::history() as $b) {
            if (($b['id'] ?? '') === $id && !empty($b['zip_path']) && is_file($b['zip_path'])) {
                $path = (string) $b['zip_path'];
                nocache_headers();
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . basename($path) . '"');
                header('Content-Length: ' . filesize($path));
                readfile($path);
                exit;
            }
        }
    }
}
