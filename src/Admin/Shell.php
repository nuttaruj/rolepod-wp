<?php
declare(strict_types=1);

namespace Rolepod\Wp\Admin;

use Rolepod\Wp\Config;

/**
 * Shared page shell for all Rolepod WP admin pages.
 *
 * Layout (matches brief/Demo mockup):
 *
 *   ┌──────────────────────────────────────────────────────────────┐
 *   │ [R logo] Rolepod for WordPress — <page>  │  [Setup][Ch][Set] │
 *   │          v2.8.x · <one-line subtitle>    │                   │
 *   └──────────────────────────────────────────────────────────────┘
 *   <content>
 *   <footer hint>
 */
final class Shell
{
    /**
     * Render header + open the wrapper. Caller must close with self::close().
     *
     * @param string      $activeSlug One of Menu::SLUG_* constants.
     * @param string      $pageLabel  Short label appended after the em-dash in the title.
     * @param string|null $subtitle   Optional single-line description shown under the version.
     */
    public static function open(string $activeSlug, string $pageLabel, ?string $subtitle = null): void
    {
        // v2.8.9: the master endpoints toggle is gone (plugin activation =
        // consent). The status dot now reflects execute-php state — the
        // only remaining user-controlled gate, and the one that matters
        // for "is this a live customer site or dev/staging".
        $config = Config::all();
        $executePhpEnabled = (bool) ($config['execute_php_enabled'] ?? false);
        $statusLabel = $executePhpEnabled ? 'execute-php ON' : 'execute-php OFF';
        $statusClass = $executePhpEnabled ? '' : 'is-off';

        $tabs = [
            ['slug' => Menu::SLUG_SETUP,    'label' => 'Setup',    'icon' => self::iconSparkle()],
            ['slug' => Menu::SLUG_CHANGES,  'label' => 'Changes',  'icon' => self::iconList()],
            ['slug' => Menu::SLUG_MEDIA,    'label' => 'Media',    'icon' => self::iconImage()],
            ['slug' => Menu::SLUG_BACKUP,   'label' => 'Backup',   'icon' => self::iconArchive()],
            ['slug' => Menu::SLUG_SETTINGS, 'label' => 'Settings', 'icon' => self::iconCog()],
        ];

        echo '<div class="wrap rp-shell">';
        echo '<header class="rp-pageheader">';
        echo '  <div class="rp-pageheader-l">';
        echo '    <div class="rp-logo" aria-hidden="true">R</div>';
        echo '    <div class="rp-pageheader-titles">';
        echo '      <h1>Rolepod for WordPress <span class="rp-pagetitle-sep">&mdash;</span> <span class="rp-pagetitle-page">' . esc_html($pageLabel) . '</span></h1>';
        echo '      <div class="rp-version">';
        echo '        <span class="rp-dot ' . esc_attr($statusClass) . '" aria-hidden="true"></span>';
        echo '        v' . esc_html(ROLEPOD_WP_VERSION) . ' &middot; ' . esc_html($statusLabel);
        if ($subtitle !== null && $subtitle !== '') {
            echo '        &nbsp;&middot;&nbsp;<span class="rp-subtitle">' . esc_html($subtitle) . '</span>';
        }
        echo '      </div>';
        echo '    </div>';
        echo '  </div>';

        echo '  <nav class="rp-subnav" aria-label="Rolepod WP sections">';
        foreach ($tabs as $tab) {
            $isActive = $tab['slug'] === $activeSlug;
            $cls = $isActive ? 'is-active' : '';
            echo '<a class="' . esc_attr($cls) . '" href="' . esc_url(Menu::url($tab['slug'])) . '">';
            echo $tab['icon'];
            echo esc_html($tab['label']);
            echo '</a>';
        }
        echo '  </nav>';

        echo '</header>';
    }

    /**
     * Optional footer hint row (matches mockup's "Step N of M · Read the docs" line).
     */
    public static function footer(string $hint = '', string $landingUrl = 'https://nuttaruj.github.io/rolepod-wplab/'): void
    {
        echo '<div class="rp-footer-hint">';
        if ($hint !== '') {
            echo esc_html($hint) . ' &middot; ';
        }
        echo '<a href="' . esc_url($landingUrl) . '" target="_blank" rel="noopener">What can AI do here?</a>';
        echo ' &middot; ';
        echo '<a href="https://github.com/nuttaruj/rolepod-wplab" target="_blank" rel="noopener">Docs &amp; source</a>';
        echo '</div>';
    }

    public static function close(): void
    {
        echo '</div>';
    }

    private static function iconSparkle(): string
    {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M12 3v6M12 15v6M3 12h6M15 12h6M6 6l3 3M15 15l3 3M6 18l3-3M15 9l3-3"/></svg>';
    }

    private static function iconList(): string
    {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg>';
    }

    private static function iconImage(): string
    {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="m21 16-5-5L5 20"/></svg>';
    }

    private static function iconArchive(): string
    {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8M10 12h4"/></svg>';
    }

    private static function iconCog(): string
    {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8L4.2 7a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>';
    }
}
