<?php
declare(strict_types=1);

namespace Rolepod\Wp\Audit;

/**
 * Apply the "toggle" intent for a single ledger row.
 *
 * The recorder owns row CRUD; this class owns the SIDE EFFECTS — when the
 * admin or the MCP flips `applied` from 1 → 0, we need to actually revert
 * the underlying change. When they flip 0 → 1, we need to re-apply.
 *
 * For categories with native runtime gating (hooks via the wrapper helper),
 * the row flip alone is enough. For state-mutation categories (options,
 * post content, layouts, files), this class executes the inverse write.
 */
final class Toggler
{
    /**
     * Toggle a single change. The recorder has already flipped the `applied`
     * flag; this function executes the side effect (or no-op if the category
     * is wrapper-gated and needs nothing more).
     *
     * Returns a status array: { ok: bool, category, action: string, detail?: string }
     */
    public static function apply(array $row, bool $newApplied): array
    {
        $category = (string) ($row['category'] ?? '');
        if ($row['reversible'] === 0) {
            return [
                'ok' => false,
                'category' => $category,
                'action' => 'noop',
                'detail' => 'row marked reversible=0 (e.g. execute_php side effect or wp-cli destructive op)',
            ];
        }

        switch ($category) {
            case 'hook':
                // Wrapper helper reads `applied` from DB per request; nothing else to do.
                return ['ok' => true, 'category' => 'hook', 'action' => 'wrapper-flag-flipped'];

            case 'option':
                return self::toggleOption($row, $newApplied);

            case 'post':
                return self::togglePost($row, $newApplied);

            case 'layout':
                return self::toggleLayout($row, $newApplied);

            case 'file':
                return self::toggleFile($row, $newApplied);

            case 'plugin':
                return self::togglePlugin($row, $newApplied);

            case 'theme':
                return self::toggleTheme($row, $newApplied);

            case 'media':
                return self::toggleMedia($row, $newApplied);

            default:
                return [
                    'ok' => false,
                    'category' => $category,
                    'action' => 'noop',
                    'detail' => "no dispatcher for category '{$category}'",
                ];
        }
    }

    private static function toggleOption(array $row, bool $newApplied): array
    {
        $name = (string) ($row['subcategory'] ?? '');
        if ($name === '') {
            return ['ok' => false, 'category' => 'option', 'action' => 'skip', 'detail' => 'subcategory missing'];
        }
        $value = $newApplied
            ? ($row['after_state']['value'] ?? null)
            : ($row['before_state']['value'] ?? null);

        if ($value === null && $newApplied === false) {
            delete_option($name);
            return ['ok' => true, 'category' => 'option', 'action' => 'deleted', 'detail' => $name];
        }
        update_option($name, $value);
        return ['ok' => true, 'category' => 'option', 'action' => $newApplied ? 'reapplied' : 'reverted', 'detail' => $name];
    }

    private static function togglePost(array $row, bool $newApplied): array
    {
        $postId = (int) ($row['after_state']['post_id'] ?? $row['before_state']['post_id'] ?? 0);
        if ($postId <= 0) {
            return ['ok' => false, 'category' => 'post', 'action' => 'skip', 'detail' => 'post_id missing'];
        }
        $payload = $newApplied ? ($row['after_state'] ?? []) : ($row['before_state'] ?? []);
        if (empty($payload)) {
            return ['ok' => false, 'category' => 'post', 'action' => 'skip', 'detail' => 'state snapshot missing'];
        }
        $update = ['ID' => $postId];
        foreach (['post_title', 'post_content', 'post_excerpt', 'post_status'] as $field) {
            if (array_key_exists($field, $payload)) {
                $update[$field] = $payload[$field];
            }
        }
        $result = wp_update_post($update, true);
        if (is_wp_error($result)) {
            return ['ok' => false, 'category' => 'post', 'action' => 'wp_error', 'detail' => $result->get_error_message()];
        }
        return ['ok' => true, 'category' => 'post', 'action' => $newApplied ? 'reapplied' : 'reverted', 'detail' => "post {$postId}"];
    }

    private static function toggleLayout(array $row, bool $newApplied): array
    {
        $postId = (int) ($row['after_state']['post_id'] ?? 0);
        $metaKey = (string) ($row['subcategory'] ?? '');
        if ($postId <= 0 || $metaKey === '') {
            return ['ok' => false, 'category' => 'layout', 'action' => 'skip', 'detail' => 'post_id or meta_key missing'];
        }
        $value = $newApplied
            ? ($row['after_state']['meta_value'] ?? null)
            : ($row['before_state']['meta_value'] ?? null);
        if ($value === null) {
            delete_post_meta($postId, $metaKey);
        } else {
            update_post_meta($postId, $metaKey, $value);
        }
        return ['ok' => true, 'category' => 'layout', 'action' => $newApplied ? 'reapplied' : 'reverted', 'detail' => "post {$postId} meta {$metaKey}"];
    }

    private static function toggleFile(array $row, bool $newApplied): array
    {
        $absolute = (string) ($row['after_state']['absolute_path'] ?? '');
        if ($absolute === '') {
            return ['ok' => false, 'category' => 'file', 'action' => 'skip', 'detail' => 'absolute_path missing'];
        }
        $content = $newApplied
            ? ($row['after_state']['content'] ?? null)
            : ($row['before_state']['content'] ?? null);
        if ($content === null && $newApplied === false) {
            // before-state was "file did not exist" → delete
            if (is_file($absolute)) {
                @unlink($absolute);
            }
            return ['ok' => true, 'category' => 'file', 'action' => 'deleted', 'detail' => $absolute];
        }
        if ($content === null) {
            return ['ok' => false, 'category' => 'file', 'action' => 'skip', 'detail' => 'content snapshot missing'];
        }
        $bytes = file_put_contents($absolute, $content);
        if ($bytes === false) {
            return ['ok' => false, 'category' => 'file', 'action' => 'write_failed', 'detail' => $absolute];
        }
        return ['ok' => true, 'category' => 'file', 'action' => $newApplied ? 'reapplied' : 'reverted', 'detail' => "{$absolute} ({$bytes} bytes)"];
    }

    private static function togglePlugin(array $row, bool $newApplied): array
    {
        $slug = (string) ($row['subcategory'] ?? '');
        if ($slug === '') {
            return ['ok' => false, 'category' => 'plugin', 'action' => 'skip', 'detail' => 'slug missing'];
        }
        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ($newApplied) {
            $result = activate_plugin($slug);
            if (is_wp_error($result)) {
                return ['ok' => false, 'category' => 'plugin', 'action' => 'activate_failed', 'detail' => $result->get_error_message()];
            }
            return ['ok' => true, 'category' => 'plugin', 'action' => 'activated', 'detail' => $slug];
        }
        deactivate_plugins([$slug]);
        return ['ok' => true, 'category' => 'plugin', 'action' => 'deactivated', 'detail' => $slug];
    }

    private static function toggleTheme(array $row, bool $newApplied): array
    {
        $slug = $newApplied
            ? (string) ($row['after_state']['stylesheet'] ?? '')
            : (string) ($row['before_state']['stylesheet'] ?? '');
        if ($slug === '') {
            return ['ok' => false, 'category' => 'theme', 'action' => 'skip', 'detail' => 'stylesheet missing in snapshot'];
        }
        switch_theme($slug);
        return ['ok' => true, 'category' => 'theme', 'action' => $newApplied ? 'reapplied' : 'reverted', 'detail' => $slug];
    }

    /**
     * Media rows. Only `import` is revertible here: disabling deletes the
     * attachment that was created. Re-enabling cannot re-create it (the file
     * is gone), and other media subcategories (e.g. `optimize`, which restores
     * from its own on-disk backup outside this dispatcher) are left untouched.
     */
    private static function toggleMedia(array $row, bool $newApplied): array
    {
        $subcategory = (string) ($row['subcategory'] ?? '');
        if ($subcategory !== 'import') {
            return ['ok' => false, 'category' => 'media', 'action' => 'noop', 'detail' => "no dispatcher for media subcategory '{$subcategory}'"];
        }

        $attachId = (int) ($row['after_state']['attachment_id'] ?? 0);
        if ($attachId <= 0) {
            return ['ok' => false, 'category' => 'media', 'action' => 'skip', 'detail' => 'attachment_id missing in snapshot'];
        }

        if ($newApplied) {
            // 0 → 1: the attachment was already deleted; nothing to re-create.
            return ['ok' => false, 'category' => 'media', 'action' => 'noop', 'detail' => "cannot re-create deleted attachment {$attachId}; re-import via wp_media_upload"];
        }

        $deleted = wp_delete_attachment($attachId, true);
        if (!$deleted) {
            return ['ok' => false, 'category' => 'media', 'action' => 'delete_failed', 'detail' => "attachment {$attachId}"];
        }
        return ['ok' => true, 'category' => 'media', 'action' => 'reverted', 'detail' => "deleted attachment {$attachId}"];
    }
}
