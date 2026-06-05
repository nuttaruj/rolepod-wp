<?php
declare(strict_types=1);

namespace Rolepod\Wp\Backup;

/**
 * Builds the self-describing manifest.json placed (first) inside every backup
 * zip. An AI reads ONLY this entry — via Archive::readEntry, no extraction — to
 * understand what a backup contains: site identity, environment, which
 * components are present, their sizes/counts, and integrity hashes.
 */
final class Manifest
{
    public const FORMAT = 'rolepod-backup';
    public const FORMAT_VERSION = 1;

    /**
     * @param array<string,mixed> $components
     * @return array<string,mixed>
     */
    public static function build(array $job, array $components): array
    {
        global $wpdb;
        $theme = wp_get_theme();
        $active = (array) get_option('active_plugins', []);

        return [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'backup_id' => (string) ($job['id'] ?? ''),
            'created_at' => gmdate('c'),
            'generator' => 'rolepod-wp ' . (defined('ROLEPOD_WP_VERSION') ? ROLEPOD_WP_VERSION : '?'),
            'site' => [
                'home_url' => (string) home_url(),
                'site_url' => (string) site_url(),
                'name' => (string) get_bloginfo('name'),
                'wp_version' => (string) get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'table_prefix' => $wpdb->prefix,
                'charset' => $wpdb->charset,
                'multisite' => is_multisite(),
                'locale' => (string) get_locale(),
                'active_theme' => (string) $theme->get_stylesheet(),
                'active_plugins' => array_values($active),
            ],
            'components' => $components,
            'compressed' => (bool) ($job['compress'] ?? true),
            'note' => 'Open with any unzip tool. database.sql is a standard SQL dump; files/ mirrors wp-content paths.',
        ];
    }
}
