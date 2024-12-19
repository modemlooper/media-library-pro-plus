<?php
/**
 * Plugin Name: Media Library Pro Plus
 * Description: Filters the media library to show images without alt text in the content, with a filter for post type.
 * Version: 1.0.0
 * Author: modemlooper
 * 
 * This plugin adds functionality to filter media library items by:
 * 1. Post type - Shows media attached to specific post types
 * 2. Alt text status - Can show only media items missing alt text
 */

// Prevent direct access to this file
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Load plugin files
require_once( plugin_dir_path( __FILE__ ) . 'includes/post-type-filter.php' );
require_once( plugin_dir_path( __FILE__ ) . 'includes/alt-text-filter.php' );
require_once( plugin_dir_path( __FILE__ ) . 'includes/alt-text-column.php' );
require_once( plugin_dir_path( __FILE__ ) . 'includes/broken-image-filter.php' );


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