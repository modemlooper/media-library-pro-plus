<?php
if (!defined('ABSPATH')) {
    exit;
}

// Add menu page
add_action('admin_menu', 'mlpp_attach_images_menu');
function mlpp_attach_images_menu() {
    add_submenu_page(
        'upload.php',
        'Attach Content Images',
        'Attach Content Images',
        'manage_options',
        'mlpp-attach-images',
        'mlpp_attach_images_page'
    );
}

// Enqueue scripts and styles
add_action('admin_enqueue_scripts', 'mlpp_attach_images_scripts');
function mlpp_attach_images_scripts($hook) {
    if ($hook !== 'media_page_mlpp-attach-images') {
        return;
    }

    wp_enqueue_style('mlpp-attach-images', plugin_dir_url(__FILE__) . 'css/attach-images.css');
    wp_enqueue_script('mlpp-attach-images', plugin_dir_url(__FILE__) . 'js/attach-images.js', array('jquery'), '1.0', true);
    wp_localize_script('mlpp-attach-images', 'mlppAttach', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('mlpp_attach_images_nonce')
    ));
}

// Admin page callback
function mlpp_attach_images_page() {
    $post_types = get_post_types(array('public' => true), 'objects');
    ?>
    <div class="wrap">
        <h1>Attach Content Images</h1>
        <div class="mlpp-attach-container">
            <select id="mlpp-post-type">
                <?php foreach ($post_types as $post_type): ?>
                    <option value="<?php echo esc_attr($post_type->name); ?>">
                        <?php echo esc_html($post_type->labels->name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button id="mlpp-start-process" class="button button-primary">Start Process</button>
            
            <div id="mlpp-progress-container" style="display: none;">
                <div class="mlpp-progress-bar">
                    <div class="mlpp-progress"></div>
                </div>
                <div class="mlpp-status">
                    <span class="mlpp-processed">0</span> / <span class="mlpp-total">0</span> items processed
                </div>
                <div class="mlpp-message"></div>
            </div>
        </div>
    </div>
    <?php
}

// AJAX handler for getting posts
add_action('wp_ajax_mlpp_get_posts', 'mlpp_get_posts');
function mlpp_get_posts() {
    check_ajax_referer('mlpp_attach_images_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }

    $post_type = sanitize_text_field($_POST['post_type']);
    
    $posts = get_posts(array(
        'post_type' => $post_type,
        'posts_per_page' => -1,
        'post_status' => 'publish'
    ));

    wp_send_json_success(array(
        'total' => count($posts),
        'posts' => array_map(function($post) {
            return array(
                'ID' => $post->ID,
                'title' => $post->post_title
            );
        }, $posts)
    ));
}

// AJAX handler for processing posts
add_action('wp_ajax_mlpp_process_post', 'mlpp_process_post');
function mlpp_process_post() {
    check_ajax_referer('mlpp_attach_images_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }

    $post_id = intval($_POST['post_id']);
    $post = get_post($post_id);
    
    if (!$post) {
        wp_send_json_error('Post not found');
    }

    // Get post content
    $content = $post->post_content;
    
    // Find all img tags
    preg_match_all('/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i', $content, $matches);
    
    if (empty($matches[1])) {
        wp_send_json_success(array(
            'message' => 'No images found in post',
            'attached' => 0
        ));
        return;
    }

    $attached = 0;
    foreach ($matches[1] as $src) {
        // Get image path from URL
        $upload_dir = wp_upload_dir();
        $image_path = str_replace($upload_dir['baseurl'], '', $src);
        
        // Check if image exists in media library
        $attachment = get_posts(array(
            'post_type' => 'attachment',
            'meta_key' => '_wp_attached_file',
            'meta_value' => ltrim($image_path, '/'),
            'posts_per_page' => 1
        ));

        if ($attachment) {
            $attachment_id = $attachment[0]->ID;
            
            // Check if already attached
            $current_attachments = get_children(array(
                'post_parent' => $post_id,
                'post_type' => 'attachment'
            ));
            
            if (!isset($current_attachments[$attachment_id])) {
                // Update attachment post parent
                wp_update_post(array(
                    'ID' => $attachment_id,
                    'post_parent' => $post_id
                ));
                $attached++;
            }
        }
    }

    wp_send_json_success(array(
        'message' => sprintf('%d images attached to post', $attached),
        'attached' => $attached
    ));
}
