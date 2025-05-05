<?php

// Add menu page for featured image attachment
add_action('admin_menu', 'mlpp_add_featured_image_page');
function mlpp_add_featured_image_page() {
    add_submenu_page(
        'upload.php',
        'Attach Featured Images',
        'Attach Featured Images',
        'manage_options',
        'mlpp-attach-featured-images',
        'mlpp_featured_image_page_content'
    );
}

// Page content
function mlpp_featured_image_page_content() {
    // Get all public post types
    $post_types = get_post_types(['public' => true], 'objects');
    ?>
    <div class="wrap">
        <h1>Attach Featured Images</h1>
        <div class="mlpp-featured-image-tool">
            <select id="mlpp-post-type-select">
                <option value="">Select Post Type</option>
                <?php foreach ($post_types as $post_type): ?>
                    <option value="<?php echo esc_attr($post_type->name); ?>">
                        <?php echo esc_html($post_type->labels->singular_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <div style="margin: 15px 0;">
                <p><strong>Note:</strong> The plugin will automatically try to find and set the first image from post content as the featured image when a post doesn't have one.</p>
            </div>
            
            <button id="mlpp-start-attachment" class="button button-primary">Start Attachment</button>
            
            <div id="mlpp-progress-container" style="display: none; margin-top: 20px;">
                <div class="mlpp-progress-bar" style="background: #f0f0f0; height: 20px; border-radius: 3px; margin-bottom: 10px;">
                    <div id="mlpp-progress" style="width: 0%; height: 100%; background: #0073aa; border-radius: 3px; transition: width 0.3s;"></div>
                </div>
                <div id="mlpp-status">Ready to start...</div>
            </div>
            
            <div id="mlpp-results" style="margin-top: 20px; display: none;">
                <h3>Results</h3>
                <ul>
                    <li>Posts processed: <span id="mlpp-posts-processed">0</span></li>
                    <li>Featured images set: <span id="mlpp-images-set">0</span></li>
                    <li>Posts without images in content: <span id="mlpp-no-images">0</span></li>
                </ul>
            </div>
        </div>
    </div>
    <?php
    
    // Enqueue JavaScript
    wp_enqueue_script(
        'mlpp-featured-image-script',
        MEDIA_LIBRARY_PRO_PLUS_URL . '/assets/js/featured-image.js',
        array('jquery'),
        '1.0.0',
        true
    );
    
    // Pass data to JavaScript
    wp_localize_script(
        'mlpp-featured-image-script',
        'mlppFeaturedImage',
        array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mlpp_featured_image_nonce'),
        )
    );
}

/**
 * AJAX handler for processing posts
 */
add_action('wp_ajax_mlpp_process_featured_images', 'mlpp_process_featured_images_ajax');
function mlpp_process_featured_images_ajax() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mlpp_featured_image_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed'));
    }
    
    // Get post type and offset
    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'post';
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $batch_size = 10; // Process 10 posts at a time
    
    // Get posts without featured images
    $args = array(
        'post_type' => $post_type,
        'posts_per_page' => $batch_size,
        'offset' => $offset,
        'meta_query' => array(
            array(
                'key' => '_thumbnail_id',
                'compare' => 'NOT EXISTS'
            )
        )
    );
    
    $query = new WP_Query($args);
    $posts = $query->posts;
    $total_posts = $query->found_posts;
    
    $processed = 0;
    $images_set = 0;
    $no_images = 0;
    
    foreach ($posts as $post) {
        $processed++;
        $result = mlpp_set_featured_from_content($post->ID);
        
        if ($result === true) {
            $images_set++;
        } else {
            $no_images++;
        }
    }
    
    wp_send_json_success(array(
        'processed' => $processed,
        'images_set' => $images_set,
        'no_images' => $no_images,
        'total_posts' => $total_posts,
        'offset' => $offset + $batch_size,
        'complete' => ($offset + $batch_size >= $total_posts || empty($posts))
    ));
}

/**
 * Set featured image from the first image in post content
 *
 * @param int $post_id The post ID
 * @return bool True if featured image was set, false otherwise
 */
function mlpp_set_featured_from_content($post_id) {
    // Check if post already has a featured image
    if (has_post_thumbnail($post_id)) {
        return false;
    }
    
    // Get post content
    $post = get_post($post_id);
    $content = $post->post_content;
    
    // Extract first image from content
    $first_img = '';
    $output = preg_match_all('/<img.+src=[\'"]([^\'"]*)[\'"]/i', $content, $matches);
    
    if (!empty($matches[1][0])) {
        $first_img = $matches[1][0];
    }
    
    // If no image found, check for block editor images
    if (empty($first_img) && function_exists('parse_blocks')) {
        $blocks = parse_blocks($content);
        foreach ($blocks as $block) {
            if ($block['blockName'] === 'core/image' && !empty($block['attrs']['id'])) {
                // Found an image block with ID
                set_post_thumbnail($post_id, $block['attrs']['id']);
                return true;
            }
        }
    }
    
    // If no image found, return false
    if (empty($first_img)) {
        return false;
    }
    
    // Check if image is already in media library
    $attachment_id = mlpp_get_attachment_id_from_url($first_img);
    
    if (!$attachment_id) {
        // If not in media library, try to download it
        $attachment_id = mlpp_download_and_attach_image($first_img, $post_id);
    }
    
    if ($attachment_id) {
        // Set as featured image
        set_post_thumbnail($post_id, $attachment_id);
        return true;
    }
    
    return false;
}

/**
 * Get attachment ID from URL
 *
 * @param string $url The attachment URL
 * @return int|false Attachment ID or false if not found
 */
function mlpp_get_attachment_id_from_url($url) {
    // Remove query string if any
    $url = preg_replace('/\?.*/', '', $url);
    
    // Get the upload directory paths
    $upload_dir_paths = wp_upload_dir();
    $base_url = $upload_dir_paths['baseurl'];
    
    // If this is not a local URL, return false
    if (strpos($url, $base_url) === false) {
        return false;
    }
    
    // Get the file path relative to the upload directory
    $relative_path = str_replace($base_url . '/', '', $url);
    
    // Use custom query to find attachment by path
    global $wpdb;
    $attachment_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = %s", $relative_path));
    
    return $attachment_id ? intval($attachment_id) : false;
}

/**
 * Download external image and attach to post
 *
 * @param string $url The image URL
 * @param int $post_id The post ID
 * @return int|false Attachment ID or false if failed
 */
function mlpp_download_and_attach_image($url, $post_id) {
    // Require WordPress media handling functions
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    
    // Download file to temp location
    $temp_file = download_url($url);
    
    if (is_wp_error($temp_file)) {
        return false;
    }
    
    // Determine file name and mime type
    $file_name = basename($url);
    $file_array = array(
        'name'     => $file_name,
        'tmp_name' => $temp_file
    );
    
    // Upload the image and attach it to the post
    $attachment_id = media_handle_sideload($file_array, $post_id);
    
    // If error, clean up temp file
    if (is_wp_error($attachment_id)) {
        @unlink($temp_file);
        return false;
    }
    
    return $attachment_id;
}
