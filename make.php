<?php 
/**
 * dynamic md5 string or sluf indicating which batch of assets is requested
 *  
 */
$hash = $_GET['hash'];
/**
 * must be css or js
 *  
 */
$type = $_GET['type'];

$incr = $_GET['incr'];

define( 'SHORTINIT', 1 );

if ( !empty( $_GET['site'] ) )
    $_SERVER['REQUEST_URI'] = '/' . $_GET['site'] . $_SERVER['REQUEST_URI'];

/**
 * load bare-bones WP so we get Cache / Options / Transients
 *  
 */
$load_path = $_SERVER['DOCUMENT_ROOT'] . '/wordpress/wp-load.php';
if ( !is_file( $load_path ) )
	$load_path = $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php';

if ( !is_file( $load_path ) )
	die( 'WHERE IS WORDPRESS? Please edit: ' . __FILE__ );

require_once( $load_path );
/**
 * SHORTINIT does NOT load plugins, so load our base plugin file
 *  
 */
require_once( 'minify.php' );

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
    $src = get_site_transient( 'minify:scripts-output:' . $hash . ':' . $incr );

    if ( empty( $src ) ) {
        $scripts = get_site_option( 'minify:scripts:' . $hash . ':' . $incr );
        if ( !empty( $scripts ) ) {
            minify_do_scripts( $hash, $scripts, true );
        } else {
            error_log( "Minify'd scripts with hash = {$hash} not found..." );
        }
    } else {
        exit( $src );
    }
    break;
    
case 'css':
    header( 'Content-type: text/css; charset=UTF-8' );
    $src = get_site_transient( 'minify:styles-output:' . $hash . ':' . $incr );

    if ( empty( $src ) ) {
        $styles = get_site_option( 'minify:styles:' . $hash . ':' . $incr );
        if ( !empty( $styles ) ) {
            minify_do_styles( $hash, $styles, true );
        } else {
            error_log( "Minify'd styles with hash = {$hash} not found..." );
        }
    } else {
        exit( $src );
    }
    break;
}
exit();