<?php
/**
 * Run in a disposable, newly installed WordPress site containing both themes:
 * define( 'TREDEKORATION_DISPOSABLE_TEST_SITE', true ); then include this file.
 * Requires WordPress to be bootstrapped. Never included by the theme.
 */
use Tredekoration\Japandi\Layout;

if ( ! defined( 'TREDEKORATION_DISPOSABLE_TEST_SITE' ) || true !== TREDEKORATION_DISPOSABLE_TEST_SITE || ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function layout_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function layout_test_post( $theme, $type, $slug, $content ) {
	$id = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => $type,
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_title'   => $slug,
				'post_content' => $content,
			)
		),
		true
	);
	layout_assert( ! is_wp_error( $id ), 'Fixture insert failed' );
	layout_assert( ! is_wp_error( wp_set_object_terms( $id, $theme, 'wp_theme' ) ), 'Fixture theme assignment failed' );
	return $id;
}

wp_set_current_user( 1 );
switch_theme( 'twentytwentyfour' );
$header = '<!-- wp:html --><div style="display:grid" data-layout="original">Header</div><!-- /wp:html -->';
$part   = layout_test_post( 'twentytwentyfour', 'wp_template_part', 'header', $header );
wp_set_object_terms( $part, 'header', 'wp_template_part_area' );
update_post_meta( $part, 'origin', 'theme' );
$body = '<!-- wp:group --><div class="wp-block-group"><!-- wp:template-part {"slug":"header","theme":"twentytwentyfour"} /--><!-- wp:navigation {"ref":23} /--></div><!-- /wp:group -->';
layout_test_post( 'twentytwentyfour', 'wp_template', 'page-no-title', $body );
layout_test_post( 'twentytwentyfour', 'wp_template_part', 'footer', 'parent footer' );
$styles = '{"version":3,"isGlobalStylesUserThemeJSON":true,"settings":{"layout":{"wideSize":"1234px"}}}';
layout_test_post( 'twentytwentyfour', 'wp_global_styles', 'wp-global-styles-twentytwentyfour', $styles );
set_theme_mod( 'custom_logo', 49 );
set_theme_mod( 'nav_menu_locations', array( 'main' => 12 ) );
wp_update_custom_css_post( '.same { content: "ø"; }' );

switch_theme( '3dekoration-japandi' );
require_once get_stylesheet_directory() . '/inc/inherit-layout.php';
layout_test_post( Layout\CHILD_THEME, 'wp_template_part', 'footer', 'existing child footer' );
$placeholder = layout_test_post( Layout\CHILD_THEME, 'wp_global_styles', 'wp-global-styles-3dekoration-japandi', '{"version":3,"isGlobalStylesUserThemeJSON":true}' );
set_theme_mod( 'custom_logo', 50 );

// Interrupt the first copy, then simulate the next admin visit.
$fail_once = static function ( $empty, $fields ) {
	return $empty || ( 'page-no-title' === $fields['post_name'] && ! empty( $fields['_3dk_layout_copy'] ) );
};
add_filter( 'wp_insert_post_empty_content', $fail_once, 10, 2 );
wp_set_current_user( 0 );
Layout\inherit_layout();
layout_assert( get_option( Layout\ERROR_OPTION ) && ! get_option( Layout\DONE_OPTION ), 'A failed copy must remain retryable' );
remove_filter( 'wp_insert_post_empty_content', $fail_once, 10 );
wp_set_current_user( 1 );
Layout\inherit_layout();
layout_assert( get_option( Layout\DONE_OPTION ) && ! get_option( Layout\ERROR_OPTION ), 'Retry must complete' );

$saved = get_block_template( Layout\CHILD_THEME . '//header', 'wp_template_part' );
layout_assert( $saved && $saved->wp_id !== $part && 'header' === $saved->area && 'theme' === $saved->origin, 'Header area and origin must survive' );
layout_assert( $saved->content === $header && get_post( $part )->post_content === $header, 'Parent and child header markup must survive' );
layout_assert( 'existing child footer' === get_block_template( Layout\CHILD_THEME . '//footer', 'wp_template_part' )->content, 'Existing child footer must win' );
$template = get_block_template( Layout\CHILD_THEME . '//page-no-title' )->content;
layout_assert( false !== strpos( $template, '"theme":"3dekoration-japandi"' ) && false !== strpos( $template, '"ref":23' ), 'Nested template and navigation references' );
layout_assert( get_post( $placeholder )->post_content === $styles, 'Preview placeholder must receive parent global styles' );
layout_assert( 50 === get_theme_mod( 'custom_logo' ) && array( 'main' => 12 ) === get_theme_mod( 'nav_menu_locations' ), 'Child and inherited theme mods' );
$css = wp_get_custom_css_post( Layout\CHILD_THEME );
layout_assert( $css && $css->post_content === wp_get_custom_css( Layout\PARENT_THEME ) && (int) get_theme_mod( 'custom_css_post_id' ) === $css->ID, 'Independent, accessible child custom CSS' );

set_theme_mod( 'custom_css_post_id', -1 );
Layout\copy_theme_settings();
layout_assert( wp_get_custom_css_post( Layout\CHILD_THEME )->ID === $css->ID, 'Retry must recover the same CSS post' );

wp_update_post( array( 'ID' => $saved->wp_id, 'post_content' => 'child edited after activation' ) );
wp_set_current_user( 0 );
Layout\inherit_layout();
layout_assert( 'child edited after activation' === get_post( $saved->wp_id )->post_content && get_post( $part )->post_content === $header, 'A second run must preserve independent edits' );
$copies = Layout\theme_posts( Layout\CHILD_THEME, 'wp_template_part', array( 'post_name__in' => array( 'header' ) ) );
layout_assert( 1 === count( $copies ), 'A retry must not duplicate the header' );
echo "Layout inheritance passed.\n";
