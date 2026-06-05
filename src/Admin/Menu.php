<?php
declare(strict_types=1);

namespace Rolepod\Wp\Admin;

/**
 * Single top-level admin menu for Rolepod WP.
 *
 * v2.8: collapses 3 scattered admin pages (was Settings → Rolepod for
 * WordPress + Tools → Setup + Tools → Changes) into one top-level menu
 * named "Rolepod WP" with three submenus. Lean: PHP server-render only,
 * one CSS file (~7KB) + one vanilla JS file (~3KB) enqueued ONLY on
 * Rolepod pages — zero impact on other admin screens.
 *
 * Backward compat: old `options-general.php?page=rolepod-wp` and
 * `tools.php?page=rolepod-wp-setup` / `tools.php?page=rolepod-wp-changes`
 * URLs are redirected to the new top-level routes in admin_init.
 */
final class Menu
{
    public const PARENT_SLUG    = 'rolepod-wp';
    public const SLUG_SETUP     = 'rolepod-wp';
    public const SLUG_CHANGES   = 'rolepod-wp-changes';
    public const SLUG_MEDIA     = 'rolepod-wp-media';
    public const SLUG_BACKUP    = 'rolepod-wp-backup';
    public const SLUG_SETTINGS  = 'rolepod-wp-settings';

    public static function register(): void
    {
        $cap = 'manage_options';

        add_menu_page(
            'Rolepod WP',
            'Rolepod WP',
            $cap,
            self::PARENT_SLUG,
            [SetupWizard::class, 'render'],
            self::iconDataUri(),
            76
        );
        add_submenu_page(
            self::PARENT_SLUG,
            'Rolepod WP — Setup',
            'Setup',
            $cap,
            self::SLUG_SETUP,
            [SetupWizard::class, 'render']
        );
        add_submenu_page(
            self::PARENT_SLUG,
            'Rolepod WP — Change Ledger',
            'Change Ledger',
            $cap,
            self::SLUG_CHANGES,
            [ChangeLedgerPage::class, 'render']
        );
        add_submenu_page(
            self::PARENT_SLUG,
            'Rolepod WP — Media',
            'Media',
            $cap,
            self::SLUG_MEDIA,
            [MediaPage::class, 'render']
        );
        add_submenu_page(
            self::PARENT_SLUG,
            'Rolepod WP — Backup',
            'Backup',
            $cap,
            self::SLUG_BACKUP,
            [BackupPage::class, 'render']
        );
        add_submenu_page(
            self::PARENT_SLUG,
            'Rolepod WP — Settings',
            'Settings',
            $cap,
            self::SLUG_SETTINGS,
            [SettingsPage::class, 'render']
        );
    }

    /**
     * Lean SVG icon for the admin sidebar. Inline data URI — no extra HTTP.
     * Uses currentColor so WP can recolor it via the standard menu CSS.
     */
    private static function iconDataUri(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M5 4h9a5 5 0 0 1 1.8 9.7l3.2 6.3h-3.4l-3-6H8v6H5V4zm3 3v4h6a2 2 0 0 0 0-4H8z"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * URL of a Rolepod WP page by submenu slug.
     */
    public static function url(string $slug): string
    {
        return admin_url('admin.php?page=' . $slug);
    }

    /**
     * Conditional asset enqueue. Hooked once globally; bails out early on
     * any non-Rolepod admin screen — zero byte cost for unrelated pages.
     */
    public static function enqueueAssets(string $hook): void
    {
        // Top-level menu screens have hooks like:
        //   toplevel_page_rolepod-wp
        //   rolepod-wp_page_rolepod-wp-changes
        //   rolepod-wp_page_rolepod-wp-settings
        if (strpos($hook, 'rolepod-wp') === false) {
            return;
        }

        $url = plugins_url('assets/', ROLEPOD_WP_FILE);
        wp_enqueue_style(
            'rolepod-wp-admin',
            $url . 'admin.css',
            [],
            ROLEPOD_WP_VERSION
        );
        wp_enqueue_script(
            'rolepod-wp-admin',
            $url . 'admin.js',
            [],
            ROLEPOD_WP_VERSION,
            true
        );
    }

    /**
     * Redirect legacy admin URLs to the new top-level menu.
     * Cheap — early-returns on non-Rolepod pages.
     */
    public static function legacyRedirect(): void
    {
        if (!is_admin()) {
            return;
        }
        $page = isset($_GET['page'])
            ? sanitize_key((string) wp_unslash($_GET['page']))
            : '';
        if ($page === '') {
            return;
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';

        // options-general.php?page=rolepod-wp → admin.php?page=rolepod-wp-settings
        if (strpos($uri, 'options-general.php') !== false && $page === 'rolepod-wp') {
            wp_safe_redirect(self::url(self::SLUG_SETTINGS));
            exit;
        }
        // tools.php?page=rolepod-wp-setup → admin.php?page=rolepod-wp
        if (strpos($uri, 'tools.php') !== false && $page === 'rolepod-wp-setup') {
            wp_safe_redirect(self::url(self::SLUG_SETUP));
            exit;
        }
        // tools.php?page=rolepod-wp-changes → admin.php?page=rolepod-wp-changes
        if (strpos($uri, 'tools.php') !== false && $page === 'rolepod-wp-changes') {
            wp_safe_redirect(self::url(self::SLUG_CHANGES));
            exit;
        }
    }
}
