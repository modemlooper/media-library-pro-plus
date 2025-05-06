<?php
 /**
 * Plugin Name:       Media Library Pro Plus
 * Description:       Media library enhancements.
 * Requires at least: 6.5
 * Requires PHP:      7.0
 * Version:           1.1.0
 * Author:            modemlooper
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       media-library-pro-plus
 *
 * @package CreateBlock
 */

// Prevent direct access to this file
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

define( 'MEDIA_LIBRARY_PRO_PLUS_URL', plugins_url( basename( __DIR__ ) ) );


add_action( 'init', 'mlpp_init' );
function mlpp_init() {

	// Load plugin files
	require_once( plugin_dir_path( __FILE__ ) . 'includes/post-type-filter.php' );
	require_once( plugin_dir_path( __FILE__ ) . 'includes/alt-text-filter.php' );
	require_once( plugin_dir_path( __FILE__ ) . 'includes/alt-text-column.php' );
	require_once( plugin_dir_path( __FILE__ ) . 'includes/convert-image-webp.php' );
	require_once( plugin_dir_path( __FILE__ ) . 'includes/get-alt-text.php' );

	if ( is_admin() && get_current_user_id() === 2114 ) {
		require_once( plugin_dir_path( __FILE__ ) . 'includes/broken-image-filter.php' );
		require_once( plugin_dir_path( __FILE__ ) . 'includes/delete-unattached.php' );
		require_once( plugin_dir_path( __FILE__ ) . 'includes/delete-broken.php' );
		require_once( plugin_dir_path( __FILE__ ) . 'includes/attach-content-images.php' );
		require_once( plugin_dir_path( __FILE__ ) . 'includes/attach-featured-images.php' );
		require_once( plugin_dir_path( __FILE__ ) . 'includes/remove-duplicate-media.php' );
		require_once( plugin_dir_path( __FILE__ ) . 'includes/delete-non-library-media.php' );
	}
}

/**
 * Plugin updater. Gets new version from Github.
 */
if ( is_admin() ) {

	function media_library_pro_plus_updater() {

		require 'plugin-update/plugin-update-checker.php';
		$myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
			'https://github.com/modemlooper/media-library-pro-plus',
			__FILE__,
			'media-library-pro-plus'
		);

		// Set the branch that contains the stable release.
		$myUpdateChecker->setBranch( 'main' );
		$myUpdateChecker->getVcsApi()->enableReleaseAssets();
	}
	media_library_pro_plus_updater();
}