<?php
/**
 * dynamic md5 string or sluf indicating which batch of assets is requested
 *  
 */
$hash = ( !empty( $_GET[ 'hash' ] ) ? $_GET[ 'hash' ] : false );
/**
 * must be css or js
 *  
 */
$type = ( !empty( $_GET[ 'type' ] ) && in_array( $_GET[ 'type' ], array( 'css', 'js' ) ) ? $_GET[ 'type' ] : false );

$incr = ( !empty( $_GET[ 'incr' ] ) ? $_GET[ 'incr' ] : false );

if( empty( $type ) || empty( $incr ) || empty( $hash ) )
	die( 'Not all parameters are included' );

define( 'SHORTINIT', true );

/**
 * load bare-bones WP so we get Cache / Options / Transients
 *  
 */
$load_path = $_SERVER[ 'DOCUMENT_ROOT' ] . '/wordpress/wp-load.php';
if ( !is_file( $load_path ) )
	$load_path = $_SERVER[ 'DOCUMENT_ROOT' ] . '/wp-load.php';

if ( !is_file( $load_path ) )
	die( 'WHERE IS WORDPRESS? Please edit: ' . __FILE__ );

require_once( $load_path );

/**
 * SHORTINIT does NOT load plugins, so load our base plugin file
 * Also doesn't load large chunks of WordPress, so load in a few files we need
 * @TODO: Only load the below files if we need to (though it's pretty lightweight)
 */
 
if( !defined( 'sanitize_option' ) )
	require_once( ABSPATH . WPINC . '/formatting.php' );

if( !defined( 'wp_remote_get' ) ) {
	require_once( ABSPATH . WPINC . '/class-http.php' );
	require_once( ABSPATH . WPINC . '/http.php' );
}

if( !defined( 'get_bloginfo' ) ) {
	require_once( ABSPATH . WPINC . '/general-template.php' );
	require_once( ABSPATH . WPINC . '/link-template.php' );
}

require_once( dirname( __FILE__ ) . '/minify.php' );

/**
 * Ditch any HTTP headers that happened to get added
 *  
 */
$list = headers_list();
if ( !empty( $list ) )
    header_remove();

$now = get_site_option( MINIFY_INCR_KEY );
$prev = get_site_option( MINIFY_INCR_KEY_PREV );
/**
 * Check to see if the script we are requesting is locked
 * If so, serve the last cached script for the requested hash 
 * 
 */
$hash_lock_key = 'minify-' . $type . '-locked-' . $hash;
$locked = get_site_transient( $hash_lock_key );

if ( !in_array( $incr, array( $now, $prev ) ) )
    $incr = $locked ? $prev : $now;

/**
 * Serve JS or CSS file
 *  
 */
switch ( $type ) {
case 'js':
    header( 'Content-type: application/x-javascript; charset=UTF-8' );
    break;
case 'css':
    header( 'Content-type: text/css; charset=UTF-8' );
    break;
}

$src = get_site_transient( 'minify:' . $type . '-output:' . $hash . ':' . $incr );

if ( empty( $src ) ) {
	$files = get_site_option( 'minify:' . $type . ':' . $hash . ':' . $incr );
	if ( !empty( $files ) ) {
		$minify->combine( $hash, $files, $type, true );
	} else {
		error_log( 'Minified files of type ' . $type . ' with hash = ' . $hash . ' not found...' );
	}
} else {
	exit( $src );
}

exit();
