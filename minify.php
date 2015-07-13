<?php
/*
Plugin Name: Minify
Plugin URI: http://emusic.com/
Description: A minimal yet powerful CSS / JS minification plugin for WordPress
Version: 0.3
Author: Scott Taylor and William P. Davis
Author URI: http://scotty-t.com
License: GPLv2 or later
*/

/*
 * @TODO: Cache headers (make sure this works with Batcache)
 * @TODO: Make the minification happen by firing off an async HTTP call to the endpoint, since we're pulling in remote scripts
 * @TODO: If WP adds additional required files for the HTTP API this will break.
 * @TODO: Delete unused lists of files from the site options table?
 */

define( 'MINIFY_SLUG', 'minify' );
if( !defined( 'MINIFY_ENABLED' ) )
	define( 'MINIFY_ENABLED', 1 );
define( 'MINIFY_INCR_KEY', MINIFY_SLUG . ':incr' );
define( 'MINIFY_INCR_KEY_PREV', MINIFY_SLUG . ':prev-incr' );

if( is_admin() )
	require_once( plugin_dir_path( __FILE__ ) . '/admin.php' );

//@TODO Make this a static method. Downside: lazy
if( !is_admin() && defined( 'MINIFY_ENABLED' ) && MINIFY_ENABLED )
	$minify = new Minify;

class Minify {
	
	/**
	 * These will be used to pretty-print the CSS and JS declarations
	 * the WP-generated code is inconsistent (OCD, I know)
	 *
	 */
	public $css_fmt = "\n<link rel=\"stylesheet\" href=\"%s\" />\n";
	public $js_fmt = "\n<script type=\"text/javascript\" src=\"%s\"></script>\n";
	
	/**
	 * Register these actions as soon as we load the plugin
	 */
	function __construct() {

		//At the highest priority, before anything is printed out, start the output
		//buffer that we'll check for scripts
		add_action( 'wp_head', array( $this, 'start_buffer' ), 0 );
		add_action( 'wp_footer', array( $this, 'start_buffer' ), 0 );

		//7 9s
		//As the (hopefully) last action, grab the output, find the scripts and styles
		//and replace them
		add_action( 'wp_head', array( $this, 'replace' ), 99999999 );
		add_action( 'wp_footer', array( $this, 'replace' ), 99999999 );

	}
	
	/** 
	 * Check the output of wp_head and wp_footer for styles and scripts
	 * and replace them with the single minified file
	 */
	public function replace() {

		$styles = array();
		$scripts = array();

		/**
		 * Extract the buffer's contents (from wp_head() or wp_footer())
		 *
		 */
		$html = ob_get_clean();
		
		/**
		 * Match all <link>s that are stylesheets
		 * @TODO: Only match if media="all"
		 *
		 */
		$css = '/<link.*?stylesheet.*?href=[\'|"]([^\'|"]+)[\'|"][^>]+?>/';
		preg_match_all( $css, $html, $styles );
		
		if ( !empty( $styles[ 1 ] ) ) {
			/**
			 * Styles exist, strip them from the buffer
			 *
			 */
			$html = preg_replace( $css, '', $html );
			/**
			 * Create MD5 hash of all file names in order
			 *
			 */
			$hash = $this->hash_files( $styles[1] );
			$styles = $this->combine( $hash, $styles[1], 'css' );
		} else {
			$styles = '';
		}
	
		/**
		 * Match all <script>s
		 *
		 */
		$js = '/<script.*src=[\'|"]([^"|\']+)[\'|"].*><\/script>/';
		preg_match_all( $js, $html, $scripts );

		if ( !empty( $scripts[ 1 ] ) ) {
			/**
			 * Scripts exist, strip them from the buffer
			 *
			 */
			$html = preg_replace( $js, '', $html );
			/**
			 * Create MD5 hash of all file names in order
			 *
			 */
			$hash = $this->hash_files( $scripts[1] );
			$scripts = $this->combine( $hash, $scripts[1], 'js' );
			
		} else {
			$scripts = '';
		}
		
		/* Print the scripts first if in the header, last if in the footer */
		if( doing_action( 'wp_footer' ) ) {
			$html .= $styles . $scripts;
		} else {
			$html = $styles . $scripts . $html;
		}
		
		echo $html;

	}
	
	public function do_minify( $hash, $files = array(), $type = 'js', $incr = 1 ) {
	
		$min = get_site_transient( 'minify:' . $type . '-output:' . $hash . ':' . $incr );
		
		if( !empty( $min ) )
			return $min;
			
		error_log( 'Doing minification' );
		
		$buffer = array();
		foreach ( $files as $file ) {
		
			if( substr( $file, 0, 2 ) == '//' )
				$file = 'https:' . $file;
			
			//Get the filesystem path to the local file	
			$local = $this->check_path( $file );

			if ( $local ) {
				//Local file!
				$buffer[] = file_get_contents( $local );
			} else {
				//Remote file!
				$buffer[] = wp_remote_retrieve_body( wp_remote_get( $file ) );
			}	
		}
		
		$raw = trim( join( "\n", $buffer ) );
		
		switch( $type ) {
			case 'js' :
				require_once( plugin_dir_path( __FILE__ ) . '/JShrink.php' );
				$min = trim(  \JShrink\Minifier::minify( $raw, array( 'flaggedComments' => false ) ) );
				break;
			case 'css' :
				require_once( plugin_dir_path( __FILE__ ) . '/CSSMinify.php' );
				$min = Minify_CSS_Compressor::process( $raw );
				break;
		}

		set_site_transient( 'minify:' . $type . '-output:' . $hash . ':' . $incr, $min );
		
		return $min;
	
	}
	
	/**
	 * One function to combine the JS or CSS files
	 *
	 */
	public function combine( $hash = '', $files = array(), $type = 'js', $output = false ) {
	
		if( !in_array( $type, array( 'js', 'css' ) ) )
			return false;
	
		//No files brah!
		if( empty( $files ) )
			return false;
		
		//Get the hash for the files
		if( empty( $hash ) )
			$hash = $this->hash_files( $files );
		
		$incr = get_site_option( MINIFY_INCR_KEY );
		$incr = !empty( $incr ) ? $incr : 1;
		
		if( get_site_option( 'minify:' . $type . ':' . $hash . ':' . $incr ) != $files )
			update_site_option( 'minify:' . $type . ':' . $hash . ':' . $incr, $files );
		
		if ( $output ) {	  
			echo $this->do_minify( $hash, $files, $type, $incr );
		} else {
			$fmt = $type . '_fmt';
			return sprintf( $this->$fmt, content_url( '/cache/' . MINIFY_SLUG . '-' . $hash . '-' . $incr . '.' . $type ) );			 
		}
	
	}	

	/**
	 *	Turn on output buffering in wp_head() / wp_footer
	 *	this action has highest priority
	 *
	 */
	public function start_buffer() {
		ob_start();
	}

	/**
	 * Check if a URL is a local file. If so, return the relative path to the file
	 *
	 */
	public function check_path( $file ) {
		$relative = parse_url( $file, PHP_URL_PATH );
		
		if ( 0 !== strpos( $relative, '/wp-' ) )
			$relative = substr( $relative, strpos( $relative, '/wp-' ) );
		
		$full = $_SERVER[ 'DOCUMENT_ROOT' ] . $relative;
		if ( is_file( $full ) )
			return $full;

		$wp = rtrim( ABSPATH, '/' ) . $relative;
		if ( is_file( $wp ) )
			return $wp;

		return false;
	}

	/**
	 * Create a hash based on an array of files
	 *
	 */
	public function hash_files( $files ) {
		$added = array();
		foreach ( $files as $f ) {
			$file = $this->check_path( $f );

			if ( $file ) {
			
				//check_path(); returns the path to the file, which is nice because
				//it allows different sites to share hashes. But we also want to include
				//query strings, because they include versioning
				$query_string = parse_url( $f, PHP_URL_QUERY );
				$file = !empty( $query_string ) ? $file . '?' . $query_string : $file;
				
				$added[] = $file;
			} else {
				//Not a local file, but we still want to include it in the hash
				$added[] = $f;
			}
		}
		
		return substr( md5( join( '', $added ) ), 0, 20 );
	}

}
