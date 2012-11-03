<?php
/*
Plugin Name: Minify
Plugin URI: http://emusic.com/
Description: A minimal yet powerful CSS / JS minification plugin for WordPress
Version: 0.2
Author: Scott Taylor
Author URI: http://scotty-t.com
License: GPLv2 or later
*/	

define( 'MINIFY_HOST', $_SERVER['HTTP_HOST'] );
define( 'MINIFY_SLUG', 'minify' );
define( 'MINIFY_ENABLED', 1 );
define( 'MINIFY_INCR_KEY', MINIFY_SLUG . ':incr' );
define( 'MINIFY_INCR_KEY_PREV', MINIFY_SLUG . ':prev-incr' );

class MinifyAdmin {
    function init() {
        add_action( 'admin_menu', array( 'MinifyAdmin', 'page' ) );
    }
    
    function page() {
        $hook = add_menu_page( 
            __( 'Minify', MINIFY_SLUG ), 
            __( 'Minify', MINIFY_SLUG ),
            'manage_options', 
            MINIFY_SLUG, 
            array( 'MinifyAdmin', 'admin' ) 
        );
        add_action( "load-$hook", array( 'MinifyAdmin', 'load' ) );
    }
    
    function load() {
        if ( isset( $_POST['incr'] ) ) {
            $incr = get_site_option( MINIFY_INCR_KEY );
            update_site_option( MINIFY_INCR_KEY_PREV, $incr );
            update_site_option( MINIFY_INCR_KEY, trim( $_POST['incr'] ) );
            wp_redirect( menu_page_url( MINIFY_SLUG, false ) );  
            exit();
        }
    }
    
    function admin() {
        $incr = get_site_option( MINIFY_INCR_KEY );
    ?>
    <div class="wrap">
        <h2>Minify</h2>
        <form action="<?php menu_page_url( MINIFY_SLUG ) ?>" method="post">
            <p>Cache-buster value<p>
            <p><input type="text" name="incr" class="widefat" value="<?php echo esc_attr( $incr ) ?>" /></p>
            <p><input type="submit" value="Change Cache Buster"/></p>
        </form>
    </div>    
    <?php
    }
}
MinifyAdmin::init();

function minify_home_url( $path ) {
	if ( function_exists( 'home_url' ) )
		return home_url( $path );
	
	return '//' . MINIFY_HOST . '/' . ltrim( $_GET['site'], '/' ) . $path;
}

if ( !is_admin() && MINIFY_ENABLED ) {
    /**
     * This will be used to pretty-print the CSS declarations
     * the WP-generated code is inconsistent (OCD, I know)
     *
     */
    $css_fmt = "\n<link rel=\"stylesheet\" href=\"%s\" />\n";    
    /**
     * This will be used to pretty-print the JS declarations
     * the WP-generated code is inconsistent (OCD, I know)
     *
     */
    $js_fmt = "\n<script type=\"text/javascript\" src=\"%s\"></script>\n";
    
    function minify_do_scripts( $hash = '', $scripts = array(), $output = false ) {
        global $js_fmt;
        
        if ( empty( $scripts ) )
            return;
        
        if ( empty( $hash ) )
            $hash = md5( join( '', $scripts ) );

        $locking = false;
        $hash_lock_key = 'minify-js-locked-' . $hash;
        $locked = get_site_transient( $hash_lock_key );
        
        $incr = get_site_option( MINIFY_INCR_KEY );
        $prev = get_site_option( MINIFY_INCR_KEY_PREV );
        
        if ( empty( $incr ) ) {
            $locking = true;
            $incr = $_SERVER['REQUEST_TIME']; 
            set_site_transient( $hash_lock_key, 1 );
            set_site_transient( MINIFY_INCR_KEY, $incr );
        }
        
        if ( !empty( $locked ) && !empty( $prev ) )
            $incr = $prev;
        
        $rname = minify_home_url( '/wp-content/cache/' . MINIFY_SLUG . '-' . $hash . '-' . $incr . '.js' );
        
        $transient = get_site_transient( 'minify:scripts-output:' . $hash . ':' . $incr );
        if ( empty( $transient ) ) {
            $locking = true;
            set_site_transient( $hash_lock_key, 1 );

            $buffer = array();
            $added = array();
            foreach ( $scripts as $script ) {
                /**  
                 * Get filesystem path to JS file
                 *
                 */				
                $file = minify_check_path( $script );

                if ( $file ) {
                    /**  
                     * Local file, add contents to response buffer
                     *
                     */		
                    $added[] = $file;
                    $buffer[] = file_get_contents( $file );
                } else {
                    /**  
                     * Remote file, output <script>
                     *
                     */						
                    printf( $js_fmt, $script ); echo "\n";
                }	
            }
            require_once( 'JSMin.php' );

            /**
             * only require JSMin when we really need it
             *
             */        
            $raw = trim( join( '', $buffer ) );
            $min = trim( JSMin::minify( $raw ) );

            update_site_option( 'minify:scripts:' . $hash . ':' . $incr, $added );
            set_site_transient( 'minify:scripts-output:' . $hash . ':' . $incr, $min );
        }
        
        if ( $output ) {
            $min = get_site_transient( 'minify:scripts-output:' . $hash . ':' . $incr );          
            echo $min;
        } else {           
            printf( $js_fmt, $rname );             
        }
        
        if ( $locking )
            delete_site_transient( $hash_lock_key );
    }
    
    function minify_do_styles( $hash = 'styles', $styles = array(), $output = false ) {
        global $css_fmt;
        
        if ( empty( $styles ) )
            return;
        
        if ( empty( $hash ) )
            $hash = md5( join( '', $styles ) );

        $locking = false;
        $hash_lock_key = 'minify-css-locked-' . $hash;        
        $locked = get_site_transient( $hash_lock_key );
        
        $incr = get_site_option( MINIFY_INCR_KEY );
        $prev = get_site_option( MINIFY_INCR_KEY_PREV );
        
        if ( empty( $incr ) ) {
            $locking = true;
            $incr = $_SERVER['REQUEST_TIME']; 
            set_site_transient( $hash_lock_key, 1 );
            set_site_transient( MINIFY_INCR_KEY, $incr );
        }
        
        if ( !empty( $locked ) && !empty( $prev ) )
            $incr = $prev;
        
        $rname = minify_home_url( '/wp-content/cache/' . MINIFY_SLUG . '-' . $hash . '-' . $incr . '.css' );;
        
        $transient = get_site_transient( 'minify:styles-output:' . $hash . ':' . $incr );
        if ( empty( $transient ) ) {
            $locking = true;
            set_site_transient( $hash_lock_key, 1 );
            
            $buffer = array();
            $added = array();
            foreach ( $styles as $style ) {
                /**  
                 * Get filesystem path to CSS file
                 *
                 */
                $file = minify_check_path( $style );

                if ( $file ) {
                    /**  
                     * Local file, add contents to response buffer
                     *
                     */	
                    $added[] = $file;
                    $buffer[] = file_get_contents( $file );
                } else {
                    /**  
                     * Remote file, output <link>
                     *
                     */					
                    printf( $css_fmt, $style ); echo "\n";
                }	
            }

            /**
             * only require CSSMin when we really need it
             *
             */
            require_once( 'CSSMin.php' );

            $raw = trim( join( '', $buffer ) );
            $min = trim( CssMin::minify( $raw, array( 'ConvertLevel3Properties' => true ) ) );
            
            update_site_option( 'minify:styles:' . $hash . ':' . $incr, $added );
            set_site_transient( 'minify:styles-output:' . $hash . ':' . $incr, $min );
        }
        
        if ( $output ) {
            $min = get_site_transient( 'minify:styles-output:' . $hash . ':' . $incr );
            echo $min;
        } else {
            printf( $css_fmt, $rname );             
        }
        
        if ( $locking )
            delete_site_transient( $hash_lock_key );
    }
    

    /**
     *	Turn on output buffering in wp_head() / wp_footer
     *	this action has highest priority
     *
     */
    function minify_start_buffer() {
        ob_start();
    }
    add_action( 'wp_head', 'minify_start_buffer', 0 );
    add_action( 'wp_footer', 'minify_start_buffer', 0 );

    function minify_check_path( $file ) {
        $relative = parse_url( $file, PHP_URL_PATH );
        
        if ( 0 !== strpos( $relative, '/wp-' ) )
            $relative = substr( $relative, strpos( $relative, '/wp-' ) );
        
        $full = $_SERVER['DOCUMENT_ROOT'] . $relative;
        if ( is_file( $full ) )
            return $full;

        $wp = rtrim( ABSPATH, '/' ) . $relative;
        if ( is_file( $wp ) )
            return $wp;

        return false;
    }

    function minify_hash_files( $files ) {
        $added = array();
        foreach ( $files as $f ) {
            $file = minify_check_path( $f );

            if ( $file ) {
                $added[] = $file;
            }
        }
        
        return md5( join( '', $added ) );
    }

    function minify_combine_scripts() {
        $styles = array();
        $scripts = array();
        /**
         * Extract the buffer's contents (from wp_head() or wp_footer())
         *
         */
        $html = ob_get_clean();
        /**
         * Match all <link>s that are stylesheets
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
            $hash = minify_hash_files( $styles[1] );
            minify_do_styles( $hash, $styles[1] );
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
            $hash = minify_hash_files( $scripts[1] );
            minify_do_scripts( $hash, $scripts[1] );
        }
        echo preg_replace( array( '/[\n\n|\r\r]+/', '/\t/' ), array( "\n", '' ), $html );
    }
    add_action( 'wp_head', 'minify_combine_scripts', 10000 );
    add_action( 'wp_footer', 'minify_combine_scripts', 2000 );
}