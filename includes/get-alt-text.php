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
	}

	/**
	 * Add submenu page
	 */
	public function add_submenu_page() {
		add_submenu_page(
			'upload.php',
			'Update Alt Text',
			'Update Alt Text',
			'manage_options',
			'mlpp-update-alt-text',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render admin page
	 */
	public function render_admin_page() {
		?>
		<div class="wrap">
			<h1>Update Alt Text from Nassau Inn</h1>
			<p>This tool will scan your media library for images without alt text, search for matching images on Nassau Inn's website, and update the alt text if found.</p>
			
			<div class="mlpp-progress-container" style="display: none;">
				<h3>Processing Images</h3>
				<div class="mlpp-progress-bar-container" style="height: 25px; width: 100%; background-color: #f0f0f0; border-radius: 4px; margin-bottom: 20px;">
					<div class="mlpp-progress-bar" style="height: 100%; width: 0%; background-color: #0073aa; border-radius: 4px; transition: width 0.3s;"></div>
				</div>
				<div class="mlpp-progress-text">0% complete</div>
				<div class="mlpp-current-file"></div>
			</div>
			
			<div class="mlpp-results" style="display: none;">
				<h3>Results</h3>
				<div class="mlpp-results-content"></div>
			</div>
			
			<button id="mlpp-start-process" class="button button-primary">Start Process</button>
			
			<script>
			jQuery(document).ready(function($) {
				var totalImages = 0;
				var processedImages = 0;
				var updatedImages = 0;
				var imageIds = [];
				var results = [];
				
				$('#mlpp-start-process').on('click', function() {
					$(this).prop('disabled', true);
					$('.mlpp-progress-container').show();
					
					// First, get all media items without alt text
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'mlpp_process_alt_text',
							nonce: '<?php echo wp_create_nonce( 'mlpp_alt_text_nonce' ); ?>',
							subaction: 'get_images'
						},
						success: function(response) {
							if (response.success && response.data.image_ids) {
								imageIds = response.data.image_ids;
								totalImages = imageIds.length;
								
								if (totalImages > 0) {
									processNextImage();
								} else {
									showResults('No images without alt text were found.');
								}
							} else {
								showResults('Error: ' + (response.data ? response.data.message : 'Unknown error'));
							}
						},
						error: function() {
							showResults('Error connecting to server.');
						}
					});
				});
				
				function processNextImage() {
					if (processedImages >= totalImages) {
						showFinalResults();
						return;
					}
					
					var imageId = imageIds[processedImages];
					
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'mlpp_process_alt_text',
							nonce: '<?php echo wp_create_nonce( 'mlpp_alt_text_nonce' ); ?>',
							subaction: 'process_image',
							image_id: imageId
						},
						success: function(response) {
							processedImages++;
							
							if (response.success) {
								if (response.data.updated) {
									updatedImages++;
								}
								
								results.push({
									filename: response.data.filename,
									updated: response.data.updated,
									alt_text: response.data.alt_text || ''
								});
								
								$('.mlpp-current-file').text('Processing: ' + response.data.filename);
							}
							
							updateProgress();
							processNextImage();
						},
						error: function() {
							processedImages++;
							updateProgress();
							processNextImage();
						}
					});
				}
				
				function updateProgress() {
					var percent = Math.round((processedImages / totalImages) * 100);
					$('.mlpp-progress-bar').css('width', percent + '%');
					$('.mlpp-progress-text').text(percent + '% complete (' + processedImages + ' of ' + totalImages + ')');
				}
				
				function showFinalResults() {
					var html = '<p><strong>' + updatedImages + '</strong> of <strong>' + totalImages + '</strong> images were updated with alt text.</p>';
					
					if (results.length > 0) {
						html += '<table class="wp-list-table widefat fixed striped">';
						html += '<thead><tr><th>Filename</th><th>Status</th><th>Alt Text</th></tr></thead><tbody>';
						
						results.forEach(function(result) {
							html += '<tr>';
							html += '<td>' + result.filename + '</td>';
							html += '<td>' + (result.updated ? 'Updated' : 'Not Found') + '</td>';
							html += '<td>' + (result.updated ? result.alt_text : 'N/A') + '</td>';
							html += '</tr>';
						});
						
						html += '</tbody></table>';
					}
					
					showResults(html);
				}
				
				function showResults(content) {
					$('.mlpp-progress-container').hide();
					$('.mlpp-results-content').html(content);
					$('.mlpp-results').show();
					$('#mlpp-start-process').prop('disabled', false);
				}
			});
			</script>
		</div>
		<?php
	}

	/**
	 * Process alt text via AJAX
	 */
	public function process_alt_text_ajax() {
		// Check nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'mlpp_alt_text_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed.' ) );
		}

		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to perform this action.' ) );
		}

		$subaction = isset( $_POST['subaction'] ) ? sanitize_text_field( $_POST['subaction'] ) : '';

		switch ( $subaction ) {
			case 'get_images':
				$this->get_images_without_alt_text();
				break;

			case 'process_image':
				$image_id = isset( $_POST['image_id'] ) ? intval( $_POST['image_id'] ) : 0;
				if ( $image_id > 0 ) {
					$this->process_single_image( $image_id );
				} else {
					wp_send_json_error( array( 'message' => 'Invalid image ID.' ) );
				}
				break;

			default:
				wp_send_json_error( array( 'message' => 'Invalid action.' ) );
				break;
		}
	}

	/**
	 * Get all images without alt text
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

		wp_send_json_success( array(
			'image_ids' => $image_ids,
			'count'     => count( $image_ids ),
		) );
	}

	/**
	 * Process a single image
	 *
	 * @param int $image_id The attachment ID
	 */
	private function process_single_image( $image_id ) {
		$filename = basename( get_attached_file( $image_id ) );
		$filename_without_ext = pathinfo( $filename, PATHINFO_FILENAME );
		
		// Search Nassau Inn API
		$alt_text = $this->search_nassau_inn_api( $filename_without_ext );
		
		$updated = false;
		
		if ( $alt_text ) {
			// Update alt text
			update_post_meta( $image_id, '_wp_attachment_image_alt', $alt_text );
			$updated = true;
		}
		
		wp_send_json_success( array(
			'image_id' => $image_id,
			'filename' => $filename,
			'updated'  => $updated,
			'alt_text' => $alt_text,
		) );
	}

	/**
	 * Search Nassau Inn API for alt text
	 *
	 * @param string $filename The filename to search for
	 * @return string|false Alt text if found, false otherwise
	 */
	private function search_nassau_inn_api( $filename ) {
		$search_url = add_query_arg( 'search', urlencode( $filename ), $this->api_base_url );
		
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
				// Check if filename is in source_url
				$item_filename = basename( $item['source_url'] );
				$item_filename_without_ext = pathinfo( $item_filename, PATHINFO_FILENAME );
				
				// If filenames match closely, use this alt text
				if ( $this->filenames_match( $filename, $item_filename_without_ext ) ) {
					return $item['alt_text'];
				}
			}
		}
		
		return false;
	}

	/**
	 * Check if filenames match closely
	 *
	 * @param string $filename1 First filename
	 * @param string $filename2 Second filename
	 * @return bool Whether filenames match
	 */
	private function filenames_match( $filename1, $filename2 ) {
		// Clean filenames (remove special characters, convert to lowercase)
		$clean1 = strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', $filename1 ) );
		$clean2 = strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', $filename2 ) );
		
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
