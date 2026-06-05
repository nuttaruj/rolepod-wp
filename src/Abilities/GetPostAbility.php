<?php
declare(strict_types=1);

namespace Rolepod\Wp\Abilities;

/**
 * Ability: rolepod/get-post
 *
 * Reads a single post/page by ID (or slug + type) including raw content and a
 * builder hint (whether Elementor owns the layout, in which case the visual
 * structure lives in post meta rather than post_content). Read-only.
 */
final class GetPostAbility
{
    public const ID = 'rolepod/get-post';

    public static function register(): void
    {
        wp_register_ability(
            self::ID,
            [
                'label'       => __('Get Rolepod post', 'rolepod-wp'),
                'description' => __('Reads one post/page by id (or slug + post_type) with raw content and an Elementor-layout hint. Read-only.', 'rolepod-wp'),
                'category'    => 'rolepod',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'        => ['type' => 'integer', 'description' => 'Post ID. Preferred.'],
                        'slug'      => ['type' => 'string', 'description' => 'Post slug — used with post_type when id is omitted.'],
                        'post_type' => ['type' => 'string', 'default' => 'post', 'description' => 'Post type when resolving by slug.'],
                    ],
                ],
                'output_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'found'         => ['type' => 'boolean'],
                        'id'            => ['type' => 'integer'],
                        'title'         => ['type' => 'string'],
                        'type'          => ['type' => 'string'],
                        'status'        => ['type' => 'string'],
                        'slug'          => ['type' => 'string'],
                        'link'          => ['type' => 'string'],
                        'content'       => ['type' => 'string'],
                        'excerpt'       => ['type' => 'string'],
                        'modified'      => ['type' => 'string'],
                        'has_elementor' => ['type' => 'boolean'],
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
        $post = null;
        if (!empty($input['id'])) {
            $post = get_post((int) $input['id']);
        } elseif (isset($input['slug']) && is_string($input['slug']) && $input['slug'] !== '') {
            $type = isset($input['post_type']) && is_string($input['post_type']) && $input['post_type'] !== ''
                ? $input['post_type'] : 'post';
            $found = get_posts([
                'name'           => $input['slug'],
                'post_type'      => $type,
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'no_found_rows'  => true,
            ]);
            $post = !empty($found) ? $found[0] : null;
        }

        if (!$post instanceof \WP_Post) {
            return ['found' => false];
        }

        $editMode = (string) get_post_meta($post->ID, '_elementor_edit_mode', true);

        return [
            'found'         => true,
            'id'            => $post->ID,
            'title'         => get_the_title($post),
            'type'          => $post->post_type,
            'status'        => $post->post_status,
            'slug'          => $post->post_name,
            'link'          => (string) get_permalink($post),
            'content'       => (string) $post->post_content,
            'excerpt'       => (string) $post->post_excerpt,
            'modified'      => $post->post_modified_gmt,
            'has_elementor' => $editMode === 'builder',
        ];
    }
}
