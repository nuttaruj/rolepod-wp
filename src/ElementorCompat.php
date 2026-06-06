<?php
declare(strict_types=1);

namespace Rolepod\Wp;

/**
 * Elementor compatibility shims (v2.12.1).
 *
 * Patches a known Elementor free-version quirk so AI-built `_elementor_data`
 * round-trips correctly: section-level `_css_classes` round-trips through
 * post meta but the free renderer does NOT emit it on the outer `<section>`
 * tag. Widget-level `_css_classes` works natively.
 *
 * Strategy: post-process Elementor's rendered HTML via the
 * `elementor/frontend/the_content` filter. Walks the current post's
 * `_elementor_data`, builds a `section_id → _css_classes` map, then
 * regex-injects those classes into each `<section data-id="X">` opening
 * tag.
 *
 * Inner sections (isInner=true) walked too.
 *
 * Loaded from rolepod-wp.php via `ElementorCompat::init()`. Safe when
 * Elementor is not installed — the filter simply never fires.
 *
 * Why post-process instead of hooking the section render? Elementor 4.x
 * fires `elementor/frontend/before_render` only for widgets, not
 * sections. The legacy `elementor/element/before_render` fires only in
 * editor / control panel context. There is no public render hook for
 * frontend section element rendering — confirmed by tracing the
 * Elementor 4.1.1 hook tree on a live demo target during the
 * WalnutZtudio polish session.
 */
final class ElementorCompat
{
    public static function init(): void
    {
        add_filter( 'elementor/frontend/the_content', [ self::class, 'rewriteSectionClasses' ], 10, 1 );
    }

    /**
     * @param string|mixed $html Elementor's rendered post content.
     * @return string|mixed
     */
    public static function rewriteSectionClasses( $html )
    {
        if ( ! is_string( $html ) || $html === '' ) {
            return $html;
        }
        $post_id = (int) get_the_ID();
        if ( $post_id <= 0 ) {
            return $html;
        }
        $map = self::buildSectionClassMap( $post_id );
        if ( count( $map ) === 0 ) {
            return $html;
        }
        return preg_replace_callback(
            '#(<section\b[^>]*?\bclass="[^"]*?elementor-element-([a-f0-9]{6,12})[^"]*?)("[^>]*>)#i',
            static function ( array $m ) use ( $map ): string {
                $id = $m[2];
                if ( ! isset( $map[ $id ] ) ) {
                    return $m[0];
                }
                return $m[1] . ' ' . $map[ $id ] . $m[3];
            },
            $html
        );
    }

    /**
     * @return array<string, string> Map of element id => _css_classes string.
     */
    private static function buildSectionClassMap( int $post_id ): array
    {
        static $cache = [];
        if ( isset( $cache[ $post_id ] ) ) {
            return $cache[ $post_id ];
        }
        $raw = get_post_meta( $post_id, '_elementor_data', true );
        if ( ! is_string( $raw ) || $raw === '' ) {
            return $cache[ $post_id ] = [];
        }
        // Cheap pre-check: if the layout has no custom CSS classes anywhere, no
        // section can either — skip the json_decode + tree walk of a potentially
        // large _elementor_data blob entirely (covers the common page).
        if ( strpos( $raw, '_css_classes' ) === false ) {
            return $cache[ $post_id ] = [];
        }
        $tree = json_decode( $raw, true );
        if ( ! is_array( $tree ) ) {
            return $cache[ $post_id ] = [];
        }
        $map = [];
        self::walk( $tree, $map );
        return $cache[ $post_id ] = $map;
    }

    /** @param array<int|string, mixed> $tree */
    private static function walk( array $tree, array &$map ): void
    {
        foreach ( $tree as $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }
            $el_type = isset( $node['elType'] ) ? (string) $node['elType'] : '';
            $id      = isset( $node['id'] ) ? (string) $node['id'] : '';
            if ( $el_type === 'section' && $id !== '' ) {
                $classes = $node['settings']['_css_classes'] ?? '';
                if ( is_string( $classes ) && $classes !== '' ) {
                    $map[ $id ] = $classes;
                }
            }
            if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
                self::walk( $node['elements'], $map );
            }
        }
    }
}
