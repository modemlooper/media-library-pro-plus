<?php

/**
 * Nassau Inn Alt Text Updater
 *
 * Fetches alt text from Nassau Inn API for media library items without alt text
 *
 * @package Media_Library_Pro_Plus
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class to handle fetching and updating alt text from Nassau Inn API
 */
class MLPP_Nassau_Inn_Alt_Text {

	/**
	 * API base URL
	 *
	 * @var string
	 */
	private $api_base_url = 'https://nassauinn.com/wp-json/wp/v2/media';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_submenu_page' ) );
		add_action( 'wp_ajax_mlpp_process_alt_text', array( $this, 'process_alt_text_ajax' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		add_settings_section(
			'mlpp_nassau_inn_alt_text_section',
			__( 'Nassau Inn Alt Text Settings', 'media-library-pro-plus' ),
			array( $this, 'settings_section_callback' ),
			'media'
		);
		
		add_settings_field(
			'mlpp_nassau_inn_api_url',
			__( 'Nassau Inn API URL', 'media-library-pro-plus' ),
			array( $this, 'api_url_callback' ),
			'media',
			'mlpp_nassau_inn_alt_text_section'
		);
		
		register_setting( 'media', 'mlpp_nassau_inn_api_url' );
	}

	/**
	 * Add submenu page
	 */
	public function add_submenu_page() {
		add_submenu_page(
			'upload.php',
			__( 'Update Alt Text', 'media-library-pro-plus' ),
			__( 'Update Alt Text', 'media-library-pro-plus' ),
			'manage_options',
			'mlpp-update-alt-text',
			array( $this, 'render_admin_page' )
		);
	}
	
	/**
	 * Settings section callback
	 */
	public function settings_section_callback() {
		echo '<p>' . __( 'Settings for the Nassau Inn Alt Text feature.', 'media-library-pro-plus' ) . '</p>';
	}
	
	/**
	 * API URL field callback
	 */
	public function api_url_callback() {
		$api_url = get_option( 'mlpp_nassau_inn_api_url', 'https://nassauinn.com/wp-json/wp/v2/media' );
		echo '<input type="text" name="mlpp_nassau_inn_api_url" value="' . esc_attr( $api_url ) . '" class="regular-text" />';
		echo '<p class="description">' . __( 'The API URL for Nassau Inn media.', 'media-library-pro-plus' ) . '</p>';
	}
	


	/**
	 * Render admin page
	 */
	public function render_admin_page() {
		?>
		<div class="wrap">
			<h1><?php _e( 'Update Alt Text', 'media-library-pro-plus' ); ?></h1>
			
			<div id="mlpp-alt-text-container">
				<p><?php _e( 'This tool will scan your media library for images without alt text, and search for matching images on the Nassau Inn website. If a match is found, the alt text will be updated.', 'media-library-pro-plus' ); ?></p>
				
				<div class="form-field">
					<label for="mlpp-remove-suffixes"><?php _e( 'Remove Suffixes', 'media-library-pro-plus' ); ?></label>
					<input type="text" id="mlpp-remove-suffixes" value="-1, -1-1, -2" class="regular-text" />
					<p class="description"><?php _e( 'Comma-separated list of suffixes to remove from the search term. For example: "-1, -1-1, -2"', 'media-library-pro-plus' ); ?></p>
				</div>
				
				<div class="form-field">
					<label for="mlpp-custom-regex"><?php _e( 'Custom Suffix Regex', 'media-library-pro-plus' ); ?></label>
					<input type="text" id="mlpp-custom-regex" value="/-\d+&#?\d+;?\d+(?:-\d+)?$/" class="regular-text" />
					<p class="description"><?php _e( 'Custom regex pattern to remove suffixes from image slugs. Default pattern removes dimension suffixes like -300&#215;200-1', 'media-library-pro-plus' ); ?></p>
				</div>
				
				<button id="mlpp-scan-alt-text" class="button button-primary"><?php _e( 'Scan for Images Without Alt Text', 'media-library-pro-plus' ); ?></button>
				
				<div id="mlpp-alt-text-results" style="display: none;">
					<h2><?php _e( 'Results', 'media-library-pro-plus' ); ?></h2>
					<div id="mlpp-alt-text-progress">
						<div id="mlpp-alt-text-progress-bar"></div>
					</div>
					<p id="mlpp-alt-text-status"></p>
					<ul id="mlpp-alt-text-list"></ul>
				</div>
			</div>
		<script>
		jQuery(document).ready(function($) {
			var images = [];
			var currentIndex = 0;
			var totalImages = 0;
			var updatedCount = 0;
			
			$('#mlpp-scan-alt-text').on('click', function() {
				$('#mlpp-alt-text-results').show();
				$('#mlpp-alt-text-status').text('<?php _e( 'Scanning for images without alt text...', 'media-library-pro-plus' ); ?>');
				$('#mlpp-alt-text-list').empty();
				$('#mlpp-alt-text-progress-bar').width('0%');
				
				// Get the custom regex pattern
				var customRegex = $('#mlpp-custom-regex').val();
				
				// Get images without alt text
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'mlpp_process_alt_text',
						nonce: '<?php echo wp_create_nonce( 'mlpp_alt_text_nonce' ); ?>',
						mode: 'scan'
					},
					success: function(response) {
						if (response.success && response.data.images) {
							images = response.data.images;
							totalImages = images.length;
							
							if (totalImages > 0) {
								$('#mlpp-alt-text-status').text('<?php _e( 'Found', 'media-library-pro-plus' ); ?> ' + totalImages + ' <?php _e( 'images without alt text. Processing...', 'media-library-pro-plus' ); ?>');
								processNextImage();
							} else {
								$('#mlpp-alt-text-status').text('<?php _e( 'No images without alt text found.', 'media-library-pro-plus' ); ?>');
							}
						} else {
							$('#mlpp-alt-text-status').text('<?php _e( 'Error scanning images.', 'media-library-pro-plus' ); ?>');
						}
					}
				});
			});
			
			function processNextImage() {
				if (currentIndex >= totalImages) {
					$('#mlpp-alt-text-status').text('<?php _e( 'Done! Updated alt text for', 'media-library-pro-plus' ); ?> ' + updatedCount + ' <?php _e( 'out of', 'media-library-pro-plus' ); ?> ' + totalImages + ' <?php _e( 'images.', 'media-library-pro-plus' ); ?>');
					return;
				}
				
				var progress = Math.round((currentIndex / totalImages) * 100);
				$('#mlpp-alt-text-progress-bar').width(progress + '%');
				
				// Get the suffixes to remove
				var suffixes = $('#mlpp-remove-suffixes').val();
				
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'mlpp_process_alt_text',
						nonce: '<?php echo wp_create_nonce( 'mlpp_alt_text_nonce' ); ?>',
						mode: 'process',
						image_id: images[currentIndex],
						suffixes: suffixes
					},
					success: function(response) {
						if (response.success) {
							var data = response.data;
							var listItem = $('<li>');
							
							if (data.updated) {
								listItem.addClass('updated');
								listItem.text('✅ ' + data.filename + ': ' + data.alt_text);
								updatedCount++;
							} else {
								listItem.addClass('not-updated');
								listItem.text('❌ ' + data.filename + ': <?php _e( 'No match found', 'media-library-pro-plus' ); ?>');
							}
							
							$('#mlpp-alt-text-list').append(listItem);
						}
						
						currentIndex++;
						processNextImage();
					}
				});
			}
		});
		</script>
		
		<style>
		#mlpp-alt-text-progress {
			background-color: #f0f0f0;
			height: 20px;
			width: 100%;
			margin: 10px 0;
			border-radius: 3px;
		}
		
		#mlpp-alt-text-progress-bar {
			background-color: #0073aa;
			height: 100%;
			width: 0;
			border-radius: 3px;
		}
		
		#mlpp-alt-text-list {
			max-height: 300px;
			overflow-y: auto;
			border: 1px solid #ddd;
			padding: 10px;
			margin-top: 10px;
		}
		
		#mlpp-alt-text-list li {
			margin-bottom: 5px;
		}
		
		#mlpp-alt-text-list li.updated {
			color: green;
		}
		
		#mlpp-alt-text-list li.not-updated {
			color: red;
		}
		
		.form-field {
			margin-bottom: 15px;
		}
		
		.form-field label {
			display: block;
			font-weight: bold;
			margin-bottom: 5px;
		}
		</style>
		</div>
		<?php
	}

	/**
	 * Process alt text via AJAX
	 */
	public function process_alt_text_ajax() {
		check_ajax_referer( 'mlpp_alt_text_nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'media-library-pro-plus' ) ) );
		}
		
		$mode = isset( $_POST['mode'] ) ? sanitize_text_field( $_POST['mode'] ) : '';
		
		if ( $mode === 'scan' ) {
			// Get images without alt text
			$images = $this->get_images_without_alt_text();
			wp_send_json_success( array( 'images' => $images ) );
		} elseif ( $mode === 'process' ) {
			$image_id = isset( $_POST['image_id'] ) ? intval( $_POST['image_id'] ) : 0;
			$suffixes = isset( $_POST['suffixes'] ) ? sanitize_text_field( $_POST['suffixes'] ) : '-1, -1-1, -2';
			
			if ( ! $image_id ) {
				wp_send_json_error( array( 'message' => __( 'Invalid image ID.', 'media-library-pro-plus' ) ) );
			}
			
			$filename = get_the_title( $image_id );
			$alt_text = $this->search_nassau_inn_api( $filename, $suffixes );
			
			if ( $alt_text ) {
				update_post_meta( $image_id, '_wp_attachment_image_alt', $alt_text );
				$updated = true;
			} else {
				$updated = false;
			}
			
			wp_send_json_success( array(
				'filename' => $filename,
				'updated' => $updated,
				'alt_text' => $alt_text
			) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Invalid mode.', 'media-library-pro-plus' ) ) );
		}
	}

	/**
	 * Get all images without alt text
	 *
	 * @return array Array of image IDs without alt text
	 */
	private function get_images_without_alt_text() {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => '_wp_attachment_image_alt',
					'value'   => '',
					'compare' => '=',
				),
				array(
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				),
			),
		);

		$query = new WP_Query( $args );
		$image_ids = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$image_ids[] = get_the_ID();
			}
		}

		wp_reset_postdata();

		return $image_ids;
	}

	/**
	 * Process a single image
	 *
	 * @param int $image_id The attachment ID
	 */
	private function process_single_image( $image_id ) {
		$filename = basename( get_attached_file( $image_id ) );
		$filename_without_ext = pathinfo( $filename, PATHINFO_FILENAME );
		
		// Get the post slug
		$post = get_post( $image_id );
		$slug = $post->post_name;
		
		// Skip if slug begins with 'image'
		if (strpos($slug, 'image') === 0) {
			wp_send_json_success( array(
				'image_id' => $image_id,
				'filename' => $filename,
				'slug'     => $slug,
				'search_term' => '',
				'updated'  => false,
				'skipped'  => true,
				'reason'   => 'Slug begins with "image"',
			) );
			return;
		}
		
		// If slug is empty, fallback to filename
		$search_term = ! empty( $slug ) ? $slug : $filename_without_ext;
		
		// Search Nassau Inn API
		$alt_text = $this->search_nassau_inn_api( $search_term, $suffixes, $custom_regex );
		
		$updated = false;
		
		if ( $alt_text ) {
			// Update alt text
			update_post_meta( $image_id, '_wp_attachment_image_alt', $alt_text );
			$updated = true;
		}
		
		// Decode HTML entities in the slug and search term for display
		$decoded_slug = html_entity_decode($slug, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$decoded_search_term = html_entity_decode($search_term, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		
		wp_send_json_success( array(
			'image_id' => $image_id,
			'filename' => $filename,
			'slug'     => $decoded_slug,
			'search_term' => $decoded_search_term,
			'updated'  => $updated,
			'alt_text' => $alt_text,
		) );
	}

	/**
	 * Search Nassau Inn API for alt text
	 *
	 * @param string $search_term The term to search for (slug or filename)
	 * @param string $suffixes_input Optional. Comma-separated list of suffixes to remove
	 * @param string $custom_regex Optional. Custom regex pattern to remove suffixes
	 * @return string|false Alt text if found, false otherwise
	 */
	private function search_nassau_inn_api( $search_term, $suffixes_input = '', $custom_regex = '' ) {
		// Get the list of suffixes to remove
		if ( empty( $suffixes_input ) ) {
			$suffixes_input = '-1, -1-1, -2';
		}
		$suffixes = array_map( 'trim', explode( ',', $suffixes_input ) );
		
		// Remove suffixes from the search term using regex
		$clean_search_term = $search_term;
		
		// Use default regex if not provided
		if ( empty( $custom_regex ) ) {
			$custom_regex = '/-\d+&#?\d+;?\d+(?:-\d+)?$/';
		}
		
		// Try to remove suffixes using the custom regex pattern
		$clean_search_term = preg_replace( $custom_regex, '', $clean_search_term );
	
		// If no match with the specific pattern, try the original suffix removal method
		if ($clean_search_term === $search_term) {
			foreach ( $suffixes as $suffix ) {
				if ( ! empty( $suffix ) && substr( $clean_search_term, -strlen( $suffix ) ) === $suffix ) {
					$clean_search_term = substr( $clean_search_term, 0, -strlen( $suffix ) );
					break; // Stop after first match
				}
			}
		}
		
		//error_log( 'Original: ' . $search_term . ' | Cleaned: ' . $clean_search_term . ' | Suffixes: ' . implode(', ', $suffixes) );
		
		$search_url = add_query_arg( 'search', urlencode( $clean_search_term ), $this->api_base_url );
		
		$response = wp_remote_get( $search_url, array(
			'timeout' => 15,
		) );
		
		if ( is_wp_error( $response ) ) {
			return false;
		}
		
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		
		if ( empty( $data ) || ! is_array( $data ) ) {
			return false;
		}
		
		// Look for a matching image
		foreach ( $data as $item ) {
			if ( ! empty( $item['alt_text'] ) ) {
      
				// Check if search term matches slug
				if ( ! empty( $item['slug'] ) && $this->terms_match( $search_term, $item['slug'] ) ) {
					return $item['alt_text'];
				}
				
				// Check if search term matches filename in source_url
				$item_filename = basename( $item['source_url'] );
				$item_filename_without_ext = pathinfo( $item_filename, PATHINFO_FILENAME );
				
				// If filenames match closely, use this alt text
				if ( $this->terms_match( $search_term, $item_filename_without_ext ) ) {
					return $item['alt_text'];
				}
			}
		}
		
		return false;
	}

	/**
	 * Check if terms match closely
	 *
	 * @param string $term1 First term (slug or filename)
	 * @param string $term2 Second term (slug or filename)
	 * @return bool Whether terms match
	 */
	private function terms_match( $term1, $term2 ) {
		// Clean terms (remove special characters, convert to lowercase)
		$clean1 = strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', $term1 ) );
		$clean2 = strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', $term2 ) );
		
		// Exact match
		if ( $clean1 === $clean2 ) {
			return true;
		}
		
		// Check if one contains the other
		if ( strpos( $clean1, $clean2 ) !== false || strpos( $clean2, $clean1 ) !== false ) {
			return true;
		}
		
		// Check similarity (if one is at least 80% similar to the other)
		$similarity = similar_text( $clean1, $clean2 ) / max( strlen( $clean1 ), strlen( $clean2 ) );
		
		return $similarity >= 0.8;
	}
}

// Initialize the class
$mlpp_nassau_inn_alt_text = new MLPP_Nassau_Inn_Alt_Text();
