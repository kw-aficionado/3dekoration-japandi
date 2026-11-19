<?php
/**
 * Give this child an independent copy of the parent's published Site Editor
 * layout on first use. Files, pages, navigation posts and WooCommerce data
 * already resolve through WordPress and do not need copying.
 */
namespace Tredekoration\Japandi\Layout;

defined( 'ABSPATH' ) || exit;

const PARENT_THEME = 'twentytwentyfour';
const CHILD_THEME  = '3dekoration-japandi';
const DONE_OPTION  = 'tredekoration_japandi_layout_copied';
const ERROR_OPTION = 'tredekoration_japandi_layout_error';

function point_to_child_part( $block ) {
	if ( 'core/template-part' === $block['blockName'] && PARENT_THEME === ( $block['attrs']['theme'] ?? null ) ) {
		$block['attrs']['theme'] = CHILD_THEME;
	}
	return $block;
}

// A shared pattern or page may explicitly name the parent as well. Keep its stored content intact.
add_filter( 'render_block_data', __NAMESPACE__ . '\\point_to_child_part' );

function rebind_parts( $blocks ) {
	foreach ( $blocks as &$block ) {
		$block = point_to_child_part( $block );
		if ( ! empty( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = rebind_parts( $block['innerBlocks'] );
		}
	}
	return $blocks;
}

function theme_posts( $theme, $types, $args = array() ) {
	return get_posts(
		$args + array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'tax_query'      => array(
				array( 'taxonomy' => 'wp_theme', 'field' => 'name', 'terms' => $theme ),
			),
		)
	);
}

/**
 * Copy trusted, previously saved fields. Core normally filters content according
 * to whoever triggered init, which could even be the first anonymous visitor.
 * Limit the field-preserving filter to this specific internal write.
 */
function save_copy( $fields ) {
	$fields['_3dk_layout_copy'] = true;
	$preserve = static function ( $data, $postarr, $original ) use ( $fields ) {
		if ( ! empty( $original['_3dk_layout_copy'] ) ) {
			foreach ( array( 'post_content', 'post_content_filtered', 'post_title', 'post_excerpt' ) as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					$data[ $key ] = wp_slash( $fields[ $key ] );
				}
			}
		}
		return $data;
	};
	add_filter( 'wp_insert_post_data', $preserve, PHP_INT_MAX, 3 );
	try {
		return wp_insert_post( wp_slash( $fields ), true );
	} finally {
		remove_filter( 'wp_insert_post_data', $preserve, PHP_INT_MAX );
	}
}

function copy_layout_post( $parent, $name, $terms ) {
	$content = $parent->post_content;
	if ( 'wp_global_styles' !== $parent->post_type && false !== strpos( $content, PARENT_THEME ) ) {
		$content = serialize_blocks( rebind_parts( parse_blocks( $content ) ) );
	}
	$meta = array();
	foreach ( array( 'origin', 'is_wp_suggestion', '_wp_ignored_hooked_blocks' ) as $key ) {
		if ( metadata_exists( 'post', $parent->ID, $key ) ) {
			$meta[ $key ] = get_post_meta( $parent->ID, $key, true );
		}
	}
	$id = save_copy(
		array(
			'post_name'    => $name,
			'post_type'    => $parent->post_type,
			'post_status'  => 'publish',
			'post_author'  => $parent->post_author,
			'post_title'   => $parent->post_title,
			'post_excerpt' => $parent->post_excerpt,
			'post_content' => $content,
			'meta_input'   => $meta,
		)
	);
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	// Set the theme term last. Nothing can resolve this copy before the area is ready.
	$terms['wp_theme'] = array( CHILD_THEME );
	foreach ( $terms as $taxonomy => $values ) {
		$result = wp_set_object_terms( $id, $values, $taxonomy );
		if ( is_wp_error( $result ) ) {
			wp_delete_post( $id, true ); // Only this newly inserted, incomplete copy.
			return $result;
		}
	}
	return true;
}

function copy_templates() {
	$types = array( 'wp_template_part', 'wp_template' );
	$existing = array();
	foreach ( theme_posts( CHILD_THEME, $types, array( 'post_status' => get_post_stati() ) ) as $post ) {
		$existing[ $post->post_type ][ $post->post_name ] = true;
	}
	foreach ( theme_posts( PARENT_THEME, $types ) as $post ) {
		if ( isset( $existing[ $post->post_type ][ $post->post_name ] ) ) {
			continue;
		}
		$terms = array();
		if ( 'wp_template_part' === $post->post_type ) {
			$areas = wp_get_object_terms( $post->ID, 'wp_template_part_area', array( 'fields' => 'ids' ) );
			if ( is_wp_error( $areas ) ) {
				return $areas;
			}
			$terms['wp_template_part_area'] = array_map( 'intval', $areas );
		}
		$result = copy_layout_post( $post, $post->post_name, $terms );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$existing[ $post->post_type ][ $post->post_name ] = true;
	}
	return true;
}

function copy_global_styles() {
	$latest = array( 'posts_per_page' => 1, 'orderby' => 'date', 'order' => 'DESC' );
	$parent = theme_posts( PARENT_THEME, 'wp_global_styles', $latest );
	if ( ! $parent ) {
		return true;
	}
	$child = theme_posts( CHILD_THEME, 'wp_global_styles', $latest );
	if ( ! $child ) {
		return copy_layout_post( $parent[0], 'wp-global-styles-' . CHILD_THEME, array() );
	}

	// The Site Editor can create an empty global styles post even during preview.
	// Only fill a core placeholder. A saved child customization takes precedence.
	$data = json_decode( $child[0]->post_content, true );
	if ( ! is_array( $data ) ) {
		return true;
	}
	unset( $data['version'], $data['isGlobalStylesUserThemeJSON'] );
	if ( array_filter( $data ) ) {
		return true;
	}
	// Preserve the placeholder's ID, slug, author and status.
	$fields = $child[0]->to_array();
	$fields['post_content'] = $parent[0]->post_content;
	return save_copy( $fields );
}

function copy_theme_settings() {
	$parent = (array) get_option( 'theme_mods_' . PARENT_THEME, array() );
	$child  = (array) get_option( 'theme_mods_' . CHILD_THEME, array() );
	// The CSS post ID is theme-specific. WordPress manages widget snapshots and edit sessions.
	unset( $parent['custom_css_post_id'], $parent['sidebars_widgets'], $parent['wp_classic_sidebars'], $parent['customize_stashed_theme_mods'] );
	$settings = $child + $parent;
	if ( $settings !== $child && ! update_option( 'theme_mods_' . CHILD_THEME, $settings ) ) {
		return new \WP_Error( 'layout_mods', 'Temaets indstillinger kunne ikke kopieres.' );
	}
	$css = wp_get_custom_css_post( PARENT_THEME );
	if ( ! $css || 'publish' !== $css->post_status ) {
		return true;
	}
	// An earlier request may have cached -1 before a copy or stopped before
	// linking it. Resolve the child's own record before inserting another.
	$existing = get_posts(
		array(
			'post_type'      => 'custom_css',
			'post_status'    => get_post_stati(),
			'post_name__in'  => array( CHILD_THEME ),
			'posts_per_page' => 1,
		)
	);
	if ( $existing ) {
		set_theme_mod( 'custom_css_post_id', $existing[0]->ID );
		return true;
	}
	$id = save_copy(
		array(
			'post_type'             => 'custom_css',
			'post_status'           => 'publish',
			'post_author'           => $css->post_author,
			'post_name'             => CHILD_THEME,
			'post_title'            => CHILD_THEME,
			'post_content'          => $css->post_content,
			'post_content_filtered' => $css->post_content_filtered,
		)
	);
	if ( ! is_wp_error( $id ) ) {
		set_theme_mod( 'custom_css_post_id', $id );
	}
	return $id;
}

function inherit_layout() {
	if ( get_option( DONE_OPTION ) || get_option( 'stylesheet' ) !== CHILD_THEME || get_stylesheet() !== CHILD_THEME || is_customize_preview() ) {
		return;
	}
	if ( get_option( ERROR_OPTION ) && ! current_user_can( 'switch_themes' ) ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! \WP_Upgrader::create_lock( DONE_OPTION, 5 * MINUTE_IN_SECONDS ) ) {
		return;
	}
	try {
		if ( get_option( DONE_OPTION ) ) {
			return;
		}
		foreach ( array( 'copy_templates', 'copy_global_styles', 'copy_theme_settings' ) as $step ) {
			$result = call_user_func( __NAMESPACE__ . '\\' . $step );
			if ( is_wp_error( $result ) ) {
				update_option( ERROR_OPTION, $result->get_error_message(), false );
				return;
			}
		}
		update_option( DONE_OPTION, true );
		delete_option( ERROR_OPTION );
	} finally {
		wp_clean_theme_json_cache();
		\WP_Upgrader::release_lock( DONE_OPTION );
	}
}
add_action( 'init', __NAMESPACE__ . '\\inherit_layout', 98 );

function layout_notice() {
	$error = get_option( ERROR_OPTION );
	if ( $error && current_user_can( 'switch_themes' ) ) {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( '3Dekoration Japandi kunne ikke kopiere hele layoutet fra Twenty Twenty-Four. Kopieringen forsøges igen ved næste administratorbesøg. ' . $error )
		);
	}
}
add_action( 'admin_notices', __NAMESPACE__ . '\\layout_notice' );
