<?php
/** Exercise update discovery and downloads through the registered WordPress hooks. */
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );

$hooks = $requests = $transients = $response_queue = array();
function add_action( $name, $callback ) { $GLOBALS['hooks'][ $name ] = $callback; }
function add_filter( $name, $callback ) { add_action( $name, $callback ); }
function set_site_transient( $key, $value, $ttl ) { $GLOBALS['transients'][ $key ] = $value; }
function delete_site_transient( $key ) { unset( $GLOBALS['transients'][ $key ] ); }
function wp_tempnam( $name ) { return tempnam( sys_get_temp_dir(), 'test-japandi-' ); }
function wp_delete_file( $file ) { unlink( $file ); }
function wp_parse_url( $url, $part ) { return parse_url( $url, $part ); }
function wp_remote_retrieve_response_code( $r ) { return $r['status']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_header( $r, $name ) { return $r[ $name ] ?? ''; }
class WP_Error {
	public $code;
	function __construct( $code, $message ) { $this->code = $code; }
}
function verify( $pass, $message ) {
	if ( ! $pass ) {
		throw new RuntimeException( $message );
	}
}
function wp_safe_remote_get( $url, $args ) {
	global $response_queue, $requests;
	verify( ! empty( $response_queue ), 'Unexpected HTTP request.' );
	verify( '3Dekoration-Japandi-Updater' === ( $args['user-agent'] ?? '' ), 'Anonymous user agent on API and storage requests' );
	$requests[] = array( $url, $args );
	$response = array_shift( $response_queue );
	if ( isset( $args['filename'] ) ) {
		file_put_contents( $args['filename'], $response['body'] ?? '' );
	}
	return $response;
}

require dirname( __DIR__ ) . '/inc/updates.php';
use Tredekoration\Japandi\Updates as Updates;
$check    = $hooks['update_themes_github.com'];
$download = $hooks['upgrader_pre_download'];
$headers  = array( 'UpdateURI' => Updates\WEB );
$contents = 'stable package bytes';
$checksum = hash( 'sha256', $contents );
$asset    = array( 'name' => Updates\SLUG . '.zip', 'state' => 'uploaded', 'id' => 42, 'digest' => 'sha256:' . $checksum );
$release  = array( 'tag_name' => 'v1.1.0', 'prerelease' => false, 'draft' => false, 'assets' => array( $asset ) );
$package  = Updates\API . 'assets/42#sha256=' . $checksum;
$extra    = array( 'theme' => Updates\SLUG, 'type' => 'theme' );

verify( false === $check( false, $headers, 'another-theme' ), 'Ignored unrelated theme' );
verify( false === $check( false, array( 'UpdateURI' => 'https://example.test/' ), Updates\SLUG ), 'Ignored unrelated repository' );
verify( false === Updates\release_update( array_merge( $release, array( 'prerelease' => true ) ) ), 'Prerelease rejected' );
verify( false === Updates\release_update( array_merge( $release, array( 'assets' => array() ) ) ), 'Missing ZIP rejected' );
verify( false === Updates\release_update( array_merge( $release, array( 'assets' => array( array_merge( $asset, array( 'digest' => null ) ) ) ) ) ), 'Missing checksum rejected' );

putenv( 'TREDEKORATION_GITHUB_TOKEN' );
$response_queue[] = array( 'status' => 200, 'body' => json_encode( $release ) );
$offer = $check( false, $headers, Updates\SLUG );
verify( '1.1.0' === $offer['version'] && $package === $offer['package'], 'Discovered version and pinned package' );
verify( ! isset( $requests[0][1]['headers']['Authorization'] ), 'Public releases need no token' );
verify( ! isset( $offer['autoupdate'] ), 'Respected administrator auto-update preference' );
verify( false === $download( false, $package, null, array( 'theme' => 'another-theme' ) ), 'Ignored another theme download' );

define( 'TREDEKORATION_GITHUB_TOKEN', 'fixture-token' );
$response_queue[] = array( 'status' => 404 );
verify( false === $check( false, $headers, Updates\SLUG ) && ! empty( $transients ), 'Private access failure reported' );
$response_queue[] = array( 'status' => 200, 'body' => json_encode( $release ) );
verify( $offer === $check( false, $headers, Updates\SLUG ) && ! $transients, 'Recovered after GitHub access restored' );

$response_queue[] = array( 'status' => 200, 'body' => $contents );
$file = $download( false, $package, null, $extra );
verify( file_get_contents( $file ) === $contents, 'Direct streaming response' );
unlink( $file );

$storage = 'https://release-assets.githubusercontent.com/github-production-release-asset/example';
$response_queue[] = array( 'status' => 302, 'location' => $storage );
$response_queue[] = array( 'status' => 200, 'body' => $contents );
$file = $download( false, $package, null, array( 'theme' => Updates\SLUG ) );
verify( file_get_contents( $file ) === $contents, 'Bulk upgrade with a signed storage redirect' );
unlink( $file );
list( $api_url, $api_args ) = $requests[ count( $requests ) - 2 ];
list( $storage_url, $storage_args ) = end( $requests );
verify( Updates\API . 'assets/42' === $api_url && 'Bearer fixture-token' === $api_args['headers']['Authorization'] && 0 === $api_args['redirection'], 'Token restricted to API endpoint' );
verify( $storage === $storage_url && ! isset( $storage_args['headers']['Authorization'] ), 'Storage request contains no repo token' );

$response_queue[] = array( 'status' => 200, 'body' => 'wrong bytes' );
verify( 'japandi_digest' === $download( false, $package, null, $extra )->code, 'Mismatched checksum rejected' );
verify( ! file_exists( end( $requests )[1]['filename'] ), 'Failed download cleaned up' );
$response_queue[] = array( 'status' => 302, 'location' => 'https://example.test/unexpected' );
verify( 'japandi_download' === $download( false, $package, null, $extra )->code, 'Unrecognized redirect rejected' );
echo "GitHub theme update tests passed.\n";
