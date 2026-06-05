<?php
declare(strict_types=1);

namespace Rolepod\Wp\Abilities;

/**
 * Ability: rolepod/site-info
 *
 * One-shot read of the site's identity + environment: title, URLs, versions,
 * active theme, plugin count, and a best-effort page-builder detection. Lets
 * the native WP AI Client (or any Abilities consumer) orient itself before
 * acting — the same orientation the MCP gets from rolepod_wp_builder_detect +
 * core info, without the external CLI.
 *
 * Pure read, no side effects.
 */
final class SiteInfoAbility
{
    public const ID = 'rolepod/site-info';

    /** slug-fragment => label for active page builders / key plugins. */
    private const BUILDER_SIGNATURES = [
        'elementor/elementor.php'         => 'Elementor',
        'divi-builder/divi-builder.php'   => 'Divi Builder',
        'bricks'                          => 'Bricks',
        'oxygen/functions.php'            => 'Oxygen',
        'beaver-builder'                  => 'Beaver Builder',
        'woocommerce/woocommerce.php'     => 'WooCommerce',
    ];

    public static function register(): void
    {
        wp_register_ability(
            self::ID,
            [
                'label'       => __('Rolepod site info', 'rolepod-wp'),
                'description' => __('Returns site title, URLs, WP/PHP versions, active theme, plugin count, and detected page builders. Safe read-only orientation.', 'rolepod-wp'),
                'category'    => 'rolepod',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                ],
                'output_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'           => ['type' => 'string'],
                        'description'    => ['type' => 'string'],
                        'home_url'       => ['type' => 'string'],
                        'site_url'        => ['type' => 'string'],
                        'wp_version'     => ['type' => 'string'],
                        'php_version'    => ['type' => 'string'],
                        'language'       => ['type' => 'string'],
                        'timezone'       => ['type' => 'string'],
                        'active_theme'   => ['type' => 'object'],
                        'active_plugins' => ['type' => 'integer'],
                        'builders'       => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'execute_callback'    => [self::class, 'execute'],
                'permission_callback' => [Bridge::class, 'adminPermission'],
                'meta' => ['show_in_rest' => true],
            ]
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function execute(array $input = []): array
    {
        $theme = wp_get_theme();
        $active = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }

        $builders = [];
        foreach (self::BUILDER_SIGNATURES as $needle => $label) {
            foreach ($active as $pluginFile) {
                if (strpos((string) $pluginFile, $needle) !== false) {
                    $builders[] = $label;
                    break;
                }
            }
        }

        return [
            'name'           => (string) get_bloginfo('name'),
            'description'    => (string) get_bloginfo('description'),
            'home_url'       => (string) home_url(),
            'site_url'       => (string) site_url(),
            'wp_version'     => (string) get_bloginfo('version'),
            'php_version'    => PHP_VERSION,
            'language'       => (string) get_bloginfo('language'),
            'timezone'       => (string) wp_timezone_string(),
            'active_theme'   => [
                'name'        => (string) $theme->get('Name'),
                'version'     => (string) $theme->get('Version'),
                'stylesheet'  => (string) $theme->get_stylesheet(),
                'template'    => (string) $theme->get_template(),
                'is_child'    => $theme->get_stylesheet() !== $theme->get_template(),
            ],
            'active_plugins' => count($active),
            'builders'       => array_values(array_unique($builders)),
        ];
    }
}
