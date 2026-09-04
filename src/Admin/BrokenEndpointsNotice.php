<?php

declare(strict_types=1);

namespace Rolepod\Wp\Admin;

use Rolepod\Wp\Bootstrap\EndpointRegistrar;

/**
 * Warns an administrator when an endpoint class could not be registered.
 *
 * Since 2.24.1 a class that will not load costs only its own endpoint, so the
 * site keeps working and nobody notices — which is the point, and also the
 * problem. Silence would leave the companion quietly half-alive.
 *
 * The usual cause is a host malware scanner: `src/Endpoint/ExecutePhp.php`
 * evals arbitrary PHP, which is a backdoor signature, and Imunify360 in
 * particular trims a file it cannot clean rather than deleting it. The fix is
 * an ignore-list entry, so the notice says exactly which path to add.
 *
 * @package rolepod-wp
 */
final class BrokenEndpointsNotice
{
    /** Screens noisy enough to matter, quiet enough not to nag. */
    private const SCREENS = ['dashboard', 'plugins'];

    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'render']);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $broken = EndpointRegistrar::broken();
        if ($broken === []) {
            return;
        }

        if (!self::onRelevantScreen()) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>Rolepod for WordPress — '
            . count($broken) . ' endpoint(s) could not start.</strong></p>';

        echo '<p>The rest of the plugin, and the site\'s REST API, are unaffected. '
            . 'These endpoints are simply unavailable to your AI agent:</p><ul style="list-style:disc;margin-left:2em">';

        foreach ($broken as $class => $reason) {
            printf(
                '<li><code>%s</code> — %s<br><small>%s</small></li>',
                esc_html(self::shortName($class)),
                esc_html($reason),
                esc_html(self::relativePath($class))
            );
        }

        echo '</ul>';

        echo '<p><strong>Most likely cause:</strong> a malware scanner on your host emptied the file. '
            . 'Endpoints that run PHP look like a backdoor to a signature scanner — the detection is '
            . 'reasonable, the file is genuine.</p>';

        echo '<p><strong>To fix:</strong> add the path above to your scanner\'s ignore list '
            . '(Imunify360 → <em>Ignore List</em> → Add New File or Directory; Wordfence → '
            . '<em>Scan Options</em> → Exclude files matching wildcard patterns), then reinstall '
            . 'Rolepod for WordPress to restore the file. Without the ignore-list entry the next '
            . 'scan will empty it again.</p>';

        echo '<p>If you do not need the affected endpoint, you can leave it as it is — nothing else breaks.</p>';

        echo '</div>';
    }

    private static function onRelevantScreen(): bool
    {
        if (!function_exists('get_current_screen')) {
            return true;
        }

        $screen = get_current_screen();
        if ($screen === null) {
            return true;
        }

        if (in_array($screen->id, self::SCREENS, true)) {
            return true;
        }

        return strpos((string) $screen->id, Menu::PARENT_SLUG) !== false;
    }

    private static function shortName(string $class): string
    {
        $parts = explode('\\', $class);

        return (string) end($parts);
    }

    /** Path relative to the WordPress root — what goes in an ignore list. */
    private static function relativePath(string $class): string
    {
        $relative = strpos($class, 'Rolepod\\Wp\\') === 0
            ? substr($class, strlen('Rolepod\\Wp\\'))
            : $class;

        return 'wp-content/plugins/rolepod-wp/src/' . str_replace('\\', '/', $relative) . '.php';
    }
}
