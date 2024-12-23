<?php
/**
 * Function to delete unattached media items with broken links
 *
 * @return array Results of the deletion process
 */
function delete_broken_media_items($offset = 0) {
    $results = array(
        'deleted' => 0,
        'skipped' => 0,
        'errors' => array(),
        'done' => false,
        'total' => 0
    );

    // Get all unattached media items
    $args = array(
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => 30,
        'offset' => $offset,
        //'post_parent' => 0, // Only get unattached media
    );

    $query = new WP_Query($args);
    $total_query = new WP_Query(array_merge($args, array('posts_per_page' => -1, 'fields' => 'ids')));
    $results['total'] = $total_query->found_posts;

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $attachment_id = get_the_ID();
            $attachment_url = wp_get_attachment_url($attachment_id);
            
            // Skip if no URL
            if (!$attachment_url) {
                $results['skipped']++;
                continue;
            }

            // Check if file exists
            $upload_dir = wp_upload_dir();
            $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $attachment_url);
            
            // Check if the file is missing or inaccessible
            if (!file_exists($file_path) || !is_readable($file_path)) {
                // File is broken/missing, proceed with deletion
                $deleted = wp_delete_attachment($attachment_id, true);
                
                if ($deleted) {
                    $results['deleted']++;
                } else {
                    $results['errors'][] = sprintf('Failed to delete attachment ID: %d', $attachment_id);
                }
            } else {
                $results['skipped']++;
            }
        }
    }

    $results['done'] = $offset + $args['posts_per_page'] >= $results['total'];
    wp_reset_postdata();
    return $results;
}

/**
 * AJAX handler for deletion process
 */
function handle_broken_media_deletion_ajax() {
    check_ajax_referer('delete_broken_media_action', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }

    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $results = delete_broken_media_items($offset);
    wp_send_json_success($results);
}
add_action('wp_ajax_delete_broken_media', 'handle_broken_media_deletion_ajax');

/**
 * Hook to add admin menu for the deletion functionality
 */
add_action('admin_menu', 'register_broken_media_cleanup_menu');

function register_broken_media_cleanup_menu() {
    add_submenu_page(
        'upload.php',
        'Delete Broken Media',
        'Delete Broken Media',
        'manage_options',
        'delete-broken-media',
        'delete_broken_media_page'
    );
}

/**
 * Enqueue required scripts
 */
function enqueue_broken_deletion_scripts($hook) {
    if ('media_page_delete-broken-media' !== $hook) {
        return;
    }

    wp_enqueue_script(
        'broken-media-deletion-script',
        plugins_url('/js/broken-media-deletion.js', __FILE__),
        array('jquery'),
        '1.0.0',
        true
    );

    wp_localize_script('broken-media-deletion-script', 'brokenMediaDeletion', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('delete_broken_media_action')
    ));
}
add_action('admin_enqueue_scripts', 'enqueue_broken_deletion_scripts');

/**
 * Callback function to render the admin page
 */
function delete_broken_media_page() {
    ?>
    <div class="wrap">
        <h1>Delete Broken Media</h1>
        <div id="deletion-progress" style="display: none;">
            <div class="progress-bar-wrapper" style="margin: 20px 0; background: #d4d4d4; height: 20px; border-radius: 10px; overflow: hidden;">
                <div id="progress-bar" style="width: 0%; height: 100%; background: #2271b1; transition: width 0.3s ease;"></div>
            </div>
            <div id="progress-text" style="margin: 10px 0;">Processing: <span id="processed-count">0</span> / <span id="total-count">0</span></div>
            <div id="results" style="margin: 10px 0;">
                <p>Deleted: <span id="deleted-count">0</span></p>
                <p>Skipped: <span id="skipped-count">0</span></p>
                <p>Errors: <span id="error-count">0</span></p>
            </div>
        </div>
        <div id="completion-notice" class="notice notice-success" style="display: none;">
            <p>Process completed successfully!</p>
        </div>
        <p>This tool will delete all media items that have broken or missing files.</p>
        <div id="start-deletion" style="display: flex; align-items: center;">
            <button type="button" id="start-deletion-btn" class="button button-primary">
                Start Deletion Process
            </button>
            <button type="button" id="cancel-deletion-btn" class="button button-secondary" style="display: none; margin-left: 10px;">
                Cancel Deletion
            </button>
        </div>
    </div>
    <?php
}
