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
		
		if ( !empty( $styles[1] ) ) {
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
		}
	
		/**
		 * Match all <script>s
		 *
		 */
		$js = '/<script.*src=[\'|"]([^"|\']+)[\'|"].*><\/script>/';
		preg_match_all( $js, $html, $scripts );

		if ( !empty( $scripts[1] ) ) {
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
			
		}
		
		/* Print the scripts first if in the header, last if in the footer */
		if( doing_action( 'wp_footer' ) ) {
			$html .= $styles . $scripts;
		} else {
			$html = $styles . $scripts . $html;
		}
		
		echo $html;

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
			
		$locking = false;
		$hash_lock_key = 'minify-' . $type . '-locked-' . $hash;
		$locked = get_site_transient( $hash_lock_key );
		
		$incr = get_site_option( MINIFY_INCR_KEY );
		$prev = get_site_option( MINIFY_INCR_KEY_PREV );
		
		if ( empty( $incr ) ) {
			$locking = true;
			$incr = $_SERVER[ 'REQUEST_TIME' ]; 
			set_site_transient( $hash_lock_key, 1 );
			set_site_transient( MINIFY_INCR_KEY, $incr );
		}
		
		if ( !empty( $locked ) && !empty( $prev ) )
			$incr = $prev;
		
		$transient = get_site_transient( 'minify:' . $type . '-output:' . $hash . ':' . $incr );
		if ( empty( $transient ) ) {
			$locking = true;
			set_site_transient( $hash_lock_key, 1 );

			$buffer = array();
			$added = array();
			foreach ( $files as $file ) {
				
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
					require_once( plugin_dir_path( __FILE__ ) . '/JSMin.php' );
					$min = trim( JSMin::minify( $raw ) );
					break;
				case 'css' :
					require_once( plugin_dir_path( __FILE__ ) . '/CSSMin.php' );
					$min = trim( CssMin::minify( $raw, array( 'ConvertLevel3Properties' => true ) ) );
					break;
			}

			update_site_option( 'minify:' . $type . ':' . $hash . ':' . $incr, $files );
			set_site_transient( 'minify:' . $type . '-output:' . $hash . ':' . $incr, $min );
		}
		
		if ( $output ) {
			$min = get_site_transient( 'minify:' . $type . '-output:' . $hash . ':' . $incr );		  
			echo $min;
		} else {
			$fmt = $type . '_fmt';
			return sprintf( $this->$fmt, content_url( '/cache/' . MINIFY_SLUG . '-' . $hash . '-' . $incr . '.' . $type ) );			 
		}
		
		if ( $locking )
			delete_site_transient( $hash_lock_key );
	
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
				$added[] = $file;
			}
		}
		
		return substr( md5( join( '', $added ) ), 0, 20 );
	}

}
