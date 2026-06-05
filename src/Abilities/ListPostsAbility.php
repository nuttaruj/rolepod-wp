<?php
declare(strict_types=1);

namespace Rolepod\Wp\Abilities;

/**
 * Ability: rolepod/list-posts
 *
 * Lists posts / pages / any public post type with light filtering. Read-only.
 * Gives an Abilities consumer the same "what content exists here?" view the
 * MCP gets from rolepod_wp_post_list.
 */
final class ListPostsAbility
{
    public const ID = 'rolepod/list-posts';

    public static function register(): void
    {
        wp_register_ability(
            self::ID,
            [
                'label'       => __('List Rolepod posts', 'rolepod-wp'),
                'description' => __('Lists posts/pages/CPT entries with optional type, status, and search filters. Read-only.', 'rolepod-wp'),
                'category'    => 'rolepod',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_type' => ['type' => 'string', 'default' => 'post', 'description' => 'Post type slug (post, page, or a CPT).'],
                        'status'    => ['type' => 'string', 'default' => 'any', 'description' => 'Post status (publish, draft, any, …).'],
                        'search'    => ['type' => 'string', 'description' => 'Optional keyword search.'],
                        'limit'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                    ],
                ],
                'output_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'count' => ['type' => 'integer'],
                        'posts' => ['type' => 'array', 'items' => ['type' => 'object']],
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
        $postType = isset($input['post_type']) && is_string($input['post_type']) && $input['post_type'] !== ''
            ? $input['post_type'] : 'post';
        $status = isset($input['status']) && is_string($input['status']) && $input['status'] !== ''
            ? $input['status'] : 'any';
        $limit = max(1, min(100, (int) ($input['limit'] ?? 20)));

        $args = [
            'post_type'      => $postType,
            'post_status'    => $status,
            'posts_per_page' => $limit,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ];
        if (isset($input['search']) && is_string($input['search']) && $input['search'] !== '') {
            $args['s'] = $input['search'];
        }

        $query = new \WP_Query($args);
        $posts = array_map(static function (\WP_Post $p): array {
            return [
                'id'       => $p->ID,
                'title'    => get_the_title($p),
                'type'     => $p->post_type,
                'status'   => $p->post_status,
                'slug'     => $p->post_name,
                'link'     => (string) get_permalink($p),
                'modified' => $p->post_modified_gmt,
            ];
        }, $query->posts);

        return ['count' => count($posts), 'posts' => $posts];
    }
}
