<?php
/**
 * Set up the 3Dekoration Japandi child theme.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/inc/inherit-layout.php';
require_once __DIR__ . '/inc/updates.php';

function tredekoration_japandi_enqueue_styles() {
	wp_enqueue_style(
		'tredekoration-japandi',
		get_stylesheet_directory_uri() . '/assets/japandi.css',
		array(),
		wp_get_theme()->get( 'Version' )
	);
}
add_action( 'wp_enqueue_scripts', 'tredekoration_japandi_enqueue_styles', 100 );

function tredekoration_japandi_editor_styles() {
	add_editor_style( 'assets/japandi.css' );
}
add_action( 'after_setup_theme', 'tredekoration_japandi_editor_styles', 20 );
