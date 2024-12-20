<?php
/**
 * Function to delete unattached media items that don't have year/month in their URLs
 *
 * @return array Results of the deletion process
 */
function delete_unattached_media_without_date($offset = 0) {
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
        'posts_per_page' => 10,
        'offset' => $offset,
        'post_parent' => 0, // Only get unattached media
    );

    $query = new WP_Query($args);
    $total_query = new WP_Query(array_merge($args, array('posts_per_page' => -1, 'fields' => 'ids')));
    $results['total'] = $total_query->found_posts;

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $attachment_id = get_the_ID();
            $attachment_url = wp_get_attachment_url($attachment_id);

            // Check if URL contains year/month pattern (YYYY/MM)
            if (!preg_match('/\/\d{4}\/\d{2}\//', $attachment_url)) {
                // No year/month in URL, proceed with deletion
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
function handle_media_deletion_ajax() {
    check_ajax_referer('delete_unattached_media_action', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }

    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $results = delete_unattached_media_without_date($offset);
    wp_send_json_success($results);
}
add_action('wp_ajax_delete_unattached_media', 'handle_media_deletion_ajax');

/**
 * Hook to add admin menu for the deletion functionality
 */
add_action('admin_menu', 'register_media_cleanup_menu');

function register_media_cleanup_menu() {
    add_submenu_page(
        'upload.php',
        'Delete Unattached Media',
        'Delete Unattached Media',
        'manage_options',
        'delete-unattached-media',
        'delete_unattached_media_page'
    );
}

/**
 * Enqueue required scripts
 */
function enqueue_deletion_scripts($hook) {
    if ('media_page_delete-unattached-media' !== $hook) {
        return;
    }

    wp_enqueue_script(
        'media-deletion-script',
        plugins_url('/js/media-deletion.js', __FILE__),
        array('jquery'),
        '1.0.0',
        true
    );

    wp_localize_script('media-deletion-script', 'mediaDeletion', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('delete_unattached_media_action')
    ));
}
add_action('admin_enqueue_scripts', 'enqueue_deletion_scripts');

/**
 * Callback function to render the admin page
 */
function delete_unattached_media_page() {
    ?>
    <div class="wrap">
        <h1>Delete Unattached Media</h1>
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
        <p>This tool will delete all unattached media items that don't have a year/month structure in their URLs.</p>
        <div id="start-deletion">
            <button type="button" id="start-deletion-btn" class="button button-primary">
                Start Deletion Process
            </button>
        </div>
    </div>
    <?php
}