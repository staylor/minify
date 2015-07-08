<?php

class MinifyAdmin {
	function __construct() {
		add_action( 'admin_menu', array( $this, 'page' ) );
	}
	
	/* @TODO use the options API */
	function page() {
		$hook = add_menu_page( 
			__( 'Minify', MINIFY_SLUG ), 
			__( 'Minify', MINIFY_SLUG ),
			'manage_options', 
			MINIFY_SLUG, 
			array( $this, 'admin' ) 
		);
		add_action( 'load-' . $hook, array( $this, 'load' ) );
	}
	
	/* @TODO: Nonce */
	/* @TODO: Sanitize input */
	function load() {
		if ( !empty( $_POST[ 'incr' ] ) ) {
			$incr = get_site_option( MINIFY_INCR_KEY );
			update_site_option( MINIFY_INCR_KEY_PREV, $incr );
			update_site_option( MINIFY_INCR_KEY, trim( $_POST[ 'incr' ] ) );
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
new MinifyAdmin;
