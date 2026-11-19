<?php
/**
 * Expose stable GitHub releases to the WordPress theme updater.
 */
namespace Tredekoration\Japandi\Updates;

defined( 'ABSPATH' ) || exit;

const SLUG = '3dekoration-japandi';
const REPO = 'kw-aficionado/3dekoration-japandi';
const API  = 'https://api.github.com/repos/' . REPO . '/releases/';
const WEB  = 'https://github.com/' . REPO;
const USER_AGENT = '3Dekoration-Japandi-Updater';

function github_headers( $accept ) {
	$headers = array(
		'Accept'               => $accept,
		'X-GitHub-Api-Version' => '2022-11-28',
	);
	$token = defined( 'TREDEKORATION_GITHUB_TOKEN' ) ? TREDEKORATION_GITHUB_TOKEN : getenv( 'TREDEKORATION_GITHUB_TOKEN' );
	if ( is_string( $token ) && '' !== trim( $token ) ) {
		$headers['Authorization'] = 'Bearer ' . trim( $token );
	}
	return $headers;
}

function release_update( $release ) {
	if ( ! is_array( $release ) || ! isset( $release['tag_name'], $release['assets'], $release['draft'], $release['prerelease'] )
		|| $release['draft'] || $release['prerelease'] || ! is_array( $release['assets'] )
		|| ! preg_match( '/^v([0-9]+\.[0-9]+\.[0-9]+)$/D', $release['tag_name'], $version ) ) {
		return false;
	}

	foreach ( $release['assets'] as $asset ) {
		if ( SLUG . '.zip' !== ( $asset['name'] ?? '' ) || 'uploaded' !== ( $asset['state'] ?? '' )
			|| empty( $asset['id'] ) || ! ctype_digit( (string) $asset['id'] )
			|| ! preg_match( '/^sha256:([a-f0-9]{64})$/D', $asset['digest'] ?? '', $digest ) ) {
			continue;
		}
		return array(
			'theme'   => SLUG,
			'version' => $version[1],
			'url'     => WEB . '/releases/tag/' . $release['tag_name'],
			// Keep the checksum paired with the exact asset in WP's update transient.
			'package' => API . 'assets/' . $asset['id'] . '#sha256=' . $digest[1],
		);
	}
	return false;
}

function check( $update, $headers, $stylesheet ) {
	if ( SLUG !== $stylesheet || WEB !== ( $headers['UpdateURI'] ?? '' ) ) {
		return $update;
	}

	$response = wp_safe_remote_get(
		API . 'latest',
		array(
			'headers'     => github_headers( 'application/vnd.github+json' ),
			'user-agent'  => USER_AGENT,
			'redirection' => 0,
			'timeout'     => 8,
		)
	);
	$code = (int) wp_remote_retrieve_response_code( $response );
	$data = 200 === $code ? release_update( json_decode( wp_remote_retrieve_body( $response ), true ) ) : false;
	if ( ! $data ) {
		set_site_transient( 'tredekoration_japandi_update_error', 200 === $code ? 'asset' : 'access', DAY_IN_SECONDS );
		return $update;
	}

	delete_site_transient( 'tredekoration_japandi_update_error' );
	return $data;
}
add_filter( 'update_themes_github.com', __NAMESPACE__ . '\\check', 10, 3 );

function download( $reply, $package, $upgrader, $extra ) {
	// Theme_Upgrader::bulk_upgrade() only supplies the stylesheet name.
	if ( false !== $reply || SLUG !== ( $extra['theme'] ?? '' ) || 'theme' !== ( $extra['type'] ?? 'theme' ) ) {
		return $reply;
	}

	$asset_pattern = '~^(' . preg_quote( API, '~' ) . 'assets/[1-9][0-9]*)#sha256=([a-f0-9]{64})$~D';
	if ( ! preg_match( $asset_pattern, $package, $asset ) ) {
		return $reply;
	}

	$file = wp_tempnam( SLUG . '.zip' );
	if ( ! $file ) {
		return new \WP_Error( 'japandi_temp', 'WordPress kunne ikke oprette en midlertidig fil til temaet.' );
	}
	$args = array(
		'headers'     => github_headers( 'application/octet-stream' ),
		'user-agent'  => USER_AGENT,
		'redirection' => 0,
		'timeout'     => 60,
		'stream'      => true,
		'filename'    => $file,
	);
	$response = wp_safe_remote_get( $asset[1], $args );
	if ( 302 === (int) wp_remote_retrieve_response_code( $response ) ) {
		// GitHub may serve bytes itself or return a time-limited storage URL.
		// Only the API request is authenticated. Never forward the repo token.
		$location = wp_remote_retrieve_header( $response, 'location' );
		$hosts    = array( 'release-assets.githubusercontent.com', 'objects.githubusercontent.com', 'github-releases.githubusercontent.com' );
		if ( 'https' === wp_parse_url( $location, PHP_URL_SCHEME ) && in_array( wp_parse_url( $location, PHP_URL_HOST ), $hosts, true ) ) {
			unset( $args['headers'] );
			$response = wp_safe_remote_get( $location, $args );
		}
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		wp_delete_file( $file );
		return new \WP_Error( 'japandi_download', 'GitHub kunne ikke levere temaets ZIP-fil. Kontrollér adgang til den udgivne release.' );
	}
	if ( ! hash_equals( $asset[2], (string) hash_file( 'sha256', $file ) ) ) {
		wp_delete_file( $file );
		return new \WP_Error( 'japandi_digest', 'Temaets ZIP-fil stemmer ikke med GitHubs SHA-256-kontrolsum.' );
	}
	return $file;
}
add_filter( 'upgrader_pre_download', __NAMESPACE__ . '\\download', 10, 4 );

function update_notice() {
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->base, array( 'themes', 'themes-network', 'update-core', 'update-core-network' ), true ) || ! current_user_can( 'update_themes' ) ) {
		return;
	}
	$error = get_site_transient( 'tredekoration_japandi_update_error' );
	if ( $error ) {
		$message = 'asset' === $error
			? '3Dekoration Japandi: Den seneste GitHub-release mangler en gyldig temapakke.'
			: '3Dekoration Japandi: WordPress kunne ikke læse den seneste GitHub-release. Kontrollér netværket, at en release er udgivet, og at TREDEKORATION_GITHUB_TOKEN har læseadgang, hvis repositoryet er privat.';
		echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
	}
}
add_action( 'admin_notices', __NAMESPACE__ . '\\update_notice' );
add_action( 'network_admin_notices', __NAMESPACE__ . '\\update_notice' );
