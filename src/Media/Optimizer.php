<?php
declare(strict_types=1);

namespace Rolepod\Wp\Media;

use Rolepod\Wp\Audit\ChangeRecorder;

/**
 * Image-optimize service shared by the /media-optimize endpoint (immediate
 * mode) and the throttled background Queue (cron mode).
 *
 * One responsibility: take an attachment id, recompress/downscale its full-size
 * original in place — backed up + ledgered + stats-counted — never trading
 * quality for zero gain. All WP-coupled work lives here so callers stay thin.
 */
final class Optimizer
{
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Skip images larger than this many pixels (w*h) to avoid OOM on a shared host. */
    private const MAX_PIXELS = 50_000_000; // 50 MP

    /**
     * Scan attachments and return {id, bytes} for image originals on disk,
     * newest first, capped at $scanCap. Pure WP read.
     *
     * @return list<array{id:int,bytes:int}>
     */
    public static function scan(int $scanCap = 1000): array
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_mime_type' => self::MIME_TYPES,
            'post_status' => 'inherit',
            'posts_per_page' => max(1, $scanCap),
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);

        $items = [];
        foreach ($ids as $id) {
            $path = get_attached_file((int) $id);
            if (!is_string($path) || $path === '' || !is_file($path)) {
                continue;
            }
            $items[] = ['id' => (int) $id, 'bytes' => (int) filesize($path)];
        }
        return $items;
    }

    /**
     * Filter {id,bytes} candidates to those at/above the threshold, largest
     * first, capped at $limit (0 = no cap). Pure — unit-testable without WP.
     *
     * @param list<array{id:int,bytes:int}> $items
     * @return list<array{id:int,bytes:int}>
     */
    public static function selectCandidates(array $items, int $minBytes, int $limit): array
    {
        $matching = array_values(array_filter(
            $items,
            static fn(array $i): bool => ($i['bytes'] ?? 0) >= $minBytes
        ));
        usort($matching, static fn(array $a, array $b): int => ($b['bytes'] ?? 0) <=> ($a['bytes'] ?? 0));
        if ($limit > 0 && count($matching) > $limit) {
            $matching = array_slice($matching, 0, $limit);
        }
        return $matching;
    }

    /**
     * Optimize a single attachment original in place. Backs up, re-encodes,
     * restores on no-gain, updates metadata, records ledger + cumulative stats.
     *
     * @return array<string, mixed> { id, action, before?, after?, saved?, ... }
     */
    public static function optimizeOne(int $id, int $maxDim, int $quality, ?string $session = null): array
    {
        $path = get_attached_file($id);
        if (!is_string($path) || $path === '' || !is_file($path)) {
            return ['id' => $id, 'action' => 'skipped', 'reason' => 'file_missing'];
        }

        $before = (int) filesize($path);

        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            return ['id' => $id, 'action' => 'skipped', 'reason' => 'no_editor', 'detail' => $editor->get_error_message()];
        }

        $size = $editor->get_size();
        $w = (int) ($size['width'] ?? 0);
        $h = (int) ($size['height'] ?? 0);
        if ($w > 0 && $h > 0 && ($w * $h) > self::MAX_PIXELS) {
            return ['id' => $id, 'action' => 'skipped', 'reason' => 'too_large', 'pixels' => $w * $h];
        }

        $resized = false;
        if ($maxDim > 0 && max($w, $h) > $maxDim) {
            $editor->resize($maxDim, $maxDim, false);
            $resized = true;
        }
        $editor->set_quality($quality);

        $backupDir = self::backupDir();
        $backup = $backupDir === '' ? '' : trailingslashit($backupDir) . $id . '-' . basename($path);
        if ($backup === '' || !@copy($path, $backup)) {
            return ['id' => $id, 'action' => 'skipped', 'reason' => 'backup_failed'];
        }

        $saved = $editor->save($path);
        if (is_wp_error($saved)) {
            @copy($backup, $path);
            @unlink($backup);
            return ['id' => $id, 'action' => 'skipped', 'reason' => 'save_failed', 'detail' => $saved->get_error_message()];
        }

        clearstatcache(true, $path);
        $after = (int) filesize($path);

        if ($after >= $before) {
            // No real gain — restore the original, drop the backup.
            @copy($backup, $path);
            @unlink($backup);
            clearstatcache(true, $path);
            return ['id' => $id, 'action' => 'skipped', 'reason' => 'no_gain', 'before' => $before, 'after' => $after];
        }

        $meta = wp_get_attachment_metadata($id);
        if (is_array($meta)) {
            $meta['filesize'] = $after;
            if ($resized && is_array($saved)) {
                $meta['width'] = (int) ($saved['width'] ?? ($meta['width'] ?? 0));
                $meta['height'] = (int) ($saved['height'] ?? ($meta['height'] ?? 0));
            }
            wp_update_attachment_metadata($id, $meta);
        }

        Stats::record($before, $after);

        try {
            ChangeRecorder::record([
                'category' => 'media',
                'subcategory' => 'optimize',
                'target_descriptor' => "attachment #{$id} " . basename($path),
                'before_state' => ['bytes' => $before],
                'after_state' => ['bytes' => $after, 'resized' => $resized, 'backup' => $backup],
                'reversible' => true,
                'source_tool' => 'media_optimize',
                'source_session' => $session,
                'notes' => 'Original backed up at ' . $backup . ' — restore to revert.',
            ]);
        } catch (\Throwable $t) {
            // Ledger table may be absent on a bare install — optimize still stands.
        }

        return [
            'id' => $id,
            'action' => 'optimized',
            'before' => $before,
            'after' => $after,
            'saved' => $before - $after,
            'resized' => $resized,
            'backup' => $backup,
        ];
    }

    /**
     * Ensure + return the media backup dir under the rolepod-wp data namespace
     * (already HTTP-denied by the bootstrap deny-all .htaccess). '' on failure.
     */
    public static function backupDir(): string
    {
        $base = trailingslashit((string) (wp_upload_dir()['basedir'] ?? WP_CONTENT_DIR . '/uploads'))
            . 'rolepod-wp/media-backups';
        if (!is_dir($base) && !wp_mkdir_p($base)) {
            return '';
        }
        return $base;
    }
}
