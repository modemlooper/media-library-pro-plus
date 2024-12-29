<?php
/**
 * Attach images from post content that are not attached
 */

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

function mlpp_attach_content_images_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Attach Content Images', 'media-library-pro-plus'); ?></h1>
        <div id="mlpp-attach-images">
            <div class="mlpp-select-wrapper">
                <select id="mlpp-post-type-select">
                    <option value=""><?php esc_html_e('Select Post Type', 'media-library-pro-plus'); ?></option>
                    <?php
                    $post_types = get_post_types(['public' => true], 'objects');
                    foreach ($post_types as $post_type) {
                        printf(
                            '<option value="%s">%s</option>',
                            esc_attr($post_type->name),
                            esc_html($post_type->labels->name)
                        );
                    }
                    ?>
                </select>
                <input type="number" id="mlpp-post-offset" min="0" value="0" style="width: 100px;" placeholder="<?php esc_attr_e('Start from', 'media-library-pro-plus'); ?>">
                <button id="mlpp-start-attach" class="button button-primary"><?php esc_html_e('Start', 'media-library-pro-plus'); ?></button>
                <button id="mlpp-cancel-attach" class="button" style="display:none;"><?php esc_html_e('Cancel', 'media-library-pro-plus'); ?></button>
            </div>
            <div id="mlpp-progress-wrapper" style="display:none;">
                <div class="mlpp-progress-bar">
                    <div id="mlpp-progress" style="width: 0%"></div>
                </div>
                <div id="mlpp-progress-text"></div>
            </div>
            <div id="mlpp-results"></div>
            <div id="mlpp-post-list" style="margin-top: 20px;">
                <h3><?php esc_html_e('Posts with Attached Images', 'media-library-pro-plus'); ?></h3>
                <ul class="mlpp-post-list"></ul>
            </div>
        </div>
    </div>
    <?php
}

function mlpp_register_attach_content_images_page() {
    add_submenu_page(
        'upload.php',
        __('Attach Content Images', 'media-library-pro-plus'),
        __('Attach Content Images', 'media-library-pro-plus'),
        'manage_options',
        'mlpp-attach-content-images',
        'mlpp_attach_content_images_page'
    );
}
add_action('admin_menu', 'mlpp_register_attach_content_images_page');

function mlpp_attach_content_images_assets() {
    $screen = get_current_screen();
    if ($screen->id !== 'media_page_mlpp-attach-content-images') {
        return;
    }

    wp_enqueue_style(
        'mlpp-attach-images-styles',
        MEDIA_LIBRARY_PRO_PLUS_URL . '/includes/css/attach-images.css',
        [],
        '1.0.0'
    );

    wp_enqueue_script(
        'mlpp-attach-images',
        MEDIA_LIBRARY_PRO_PLUS_URL . '/includes/js/attach-images.js',
        ['jquery'],
        '1.0.0',
        true
    );

    wp_localize_script('mlpp-attach-images', 'mlppAttachImages', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('mlpp-attach-images'),
        'processing' => __('Processing...', 'media-library-pro-plus'),
        'complete' => __('Complete!', 'media-library-pro-plus'),
    ]);
}
add_action('admin_enqueue_scripts', 'mlpp_attach_content_images_assets');

function mlpp_get_posts_count() {
    check_ajax_referer('mlpp-attach-images', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $post_type = sanitize_text_field($_POST['post_type']);
    $count = wp_count_posts($post_type);
    $total = $count->publish + $count->draft;

    wp_send_json_success(['count' => $total]);
}
add_action('wp_ajax_mlpp_get_posts_count', 'mlpp_get_posts_count');

function mlpp_process_posts() {
    check_ajax_referer('mlpp-attach-images', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
        return;
    }

    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'post';
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $posts_per_page = 1; // Reduced batch size to prevent timeouts
    $processed = 0;
    $total_attached = 0;
    $updated_posts = array();

    try {
        // Set time limit to prevent timeout
        set_time_limit(300); // 5 minutes
        ini_set('memory_limit', '256M');

        $posts = get_posts(array(
            'post_type' => $post_type,
            'posts_per_page' => $posts_per_page,
            'offset' => $offset,
            'orderby' => 'date',
            'order' => 'DESC',
            'post_status' => array('publish', 'draft', 'private')
        ));

        if (empty($posts)) {
            wp_send_json_success(array(
                'done' => true,
                'processed' => 0,
                'attached' => 0,
                'message' => 'No more posts to process'
            ));
            return;
        }

        $site_url = get_site_url();
        $upload_dir = wp_upload_dir();
        $upload_url = $upload_dir['baseurl'];

        foreach ($posts as $post) {
            error_log("MLPP: Processing post ID {$post->ID}: {$post->post_title}");
            $processed++;
            $images_attached = 0;

            // Get post content
            $content = $post->post_content;

            // Find all image references (both img tags and background images)
            $image_urls = array();

            // Find img tags
            if (preg_match_all('/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i', $content, $matches)) {
                $image_urls = array_merge($image_urls, $matches[1]);
            }

            // Find background images in style attributes
            if (preg_match_all('/background-image:\s*url\([\'"]?([^\'")\s]+)[\'"]?\)/i', $content, $matches)) {
                $image_urls = array_merge($image_urls, $matches[1]);
            }

            // Find background images in inline styles
            if (preg_match_all('/style=[\'"][^\'"]*(background(?:-image)?:\s*url\([\'"]?([^\'")\s]+)[\'"]?\))[^\'"]*/i', $content, $matches)) {
                $image_urls = array_merge($image_urls, $matches[2]);
            }

            $image_urls = array_unique($image_urls);

            $images_to_process = array();
            foreach ($image_urls as $image_url) {
                $image_url = str_replace(array('"', "'"), '', $image_url);
                
                // Check if image is already attached
                $existing_attachment = get_posts(array(
                    'post_type' => 'attachment',
                    'post_parent' => $post->ID,
                    'posts_per_page' => 1,
                    'meta_query' => array(
                        array(
                            'key' => '_wp_attached_file',
                            'value' => basename($image_url),
                            'compare' => 'LIKE'
                        )
                    )
                ));

                if (!empty($existing_attachment)) {
                    error_log("MLPP: Image already attached to post: " . $image_url);
                    continue;
                }

                // Convert relative URLs to absolute URLs
                if (strpos($image_url, 'http') !== 0) {
                    $original_url = $image_url;
                    if (strpos($image_url, '//') === 0) {
                        $image_url = 'https:' . $image_url;
                    } else if (strpos($image_url, '/wp-content') === 0) {
                        $image_url = $site_url . $image_url;
                    } else if (strpos($image_url, '/') === 0) {
                        $image_url = $site_url . $image_url;
                    } else {
                        $image_url = $site_url . '/' . $image_url;
                    }
                    error_log("MLPP: Converted relative URL from '$original_url' to '$image_url'");
                }

                $images_to_process[] = array(
                    'url' => $image_url,
                    'post_id' => $post->ID,
                    'post_title' => $post->post_title
                );
            }

            // Return images for preview if any found
            if (!empty($images_to_process)) {
                wp_send_json_success(array(
                    'done' => false,
                    'processed' => $processed,
                    'attached' => $total_attached,
                    'images' => $images_to_process,
                    'post' => array(
                        'ID' => $post->ID,
                        'title' => $post->post_title
                    )
                ));
                return;
            }

            foreach ($image_urls as $image_url) {
                try {
                    // Clean the URL
                    $image_url = str_replace(array('\\', '"', "'"), '', trim($image_url));

                    // Skip data URLs
                    if (strpos($image_url, 'data:') === 0) {
                        error_log("MLPP: Skipping data URL");
                        continue;
                    }

                    // Check if image is already in media library by URL pattern
                    $normalized_image_url = str_replace(array('http:', 'https:'), '', $image_url);
                    $normalized_upload_url = str_replace(array('http:', 'https:'), '', $upload_url);

                    if (strpos($normalized_image_url, $normalized_upload_url) !== false) {
                        error_log("MLPP: Skipping image already in media library: " . $image_url);
                        continue;
                    }

                    // Check if image is already attached to this post
                    $existing_attachment = get_posts(array(
                        'post_type' => 'attachment',
                        'post_parent' => $post->ID,
                        'posts_per_page' => 1,
                        'meta_query' => array(
                            array(
                                'key' => '_wp_attached_file',
                                'value' => basename($image_url),
                                'compare' => 'LIKE'
                            )
                        )
                    ));

                    if (!empty($existing_attachment)) {
                        error_log("MLPP: Image already attached to post: " . $image_url);
                        continue;
                    }

                    // Convert relative URLs to absolute URLs
                    if (strpos($image_url, 'http') !== 0) {
                        $original_url = $image_url;
                        if (strpos($image_url, '//') === 0) {
                            // Protocol-relative URL
                            $image_url = 'https:' . $image_url;
                        } else if (strpos($image_url, '/wp-content') === 0) {
                            $image_url = $site_url . $image_url;
                        } else if (strpos($image_url, '/') === 0) {
                            $image_url = $site_url . $image_url;
                        } else {
                            $image_url = $site_url . '/' . $image_url;
                        }
                        error_log("MLPP: Converted relative URL from '$original_url' to '$image_url'");
                    }

                    error_log("MLPP: Attempting to download image from: " . $image_url);

                    // Download and attach the image
                    $tmp = download_url($image_url);
                    if (is_wp_error($tmp)) {
                        error_log("MLPP: Failed to download image from $image_url. Error: " . $tmp->get_error_message());
                        continue;
                    }

                    // Verify if the downloaded file is actually an image
                    $file_type = wp_check_filetype(basename($image_url), null);
                    if (!$file_type['type'] || strpos($file_type['type'], 'image/') !== 0) {
                        error_log("MLPP: Downloaded file is not an image: " . $image_url);
                        @unlink($tmp);
                        continue;
                    }

                    $file_array = array(
                        'name' => sanitize_file_name(basename($image_url)),
                        'tmp_name' => $tmp
                    );

                    // Add the image to the media library and attach it to the post
                    $attachment_id = media_handle_sideload($file_array, $post->ID, null, array(
                        'post_title' => pathinfo(basename($image_url), PATHINFO_FILENAME),
                        'post_content' => '',
                        'post_excerpt' => ''
                    ));

                    if (is_wp_error($attachment_id)) {
                        @unlink($tmp);
                        error_log("MLPP: Failed to attach image from $image_url. Error: " . $attachment_id->get_error_message());
                        continue;
                    }

                    // Update alt text if available
                    if (preg_match('/<img[^>]*alt=[\'"]([^\'"]+)[\'"][^>]*src=[\'"]' . preg_quote($image_url, '/') . '[\'"][^>]*>/i', $content, $alt_matches) ||
                        preg_match('/<img[^>]*src=[\'"]' . preg_quote($image_url, '/') . '[\'"][^>]*alt=[\'"]([^\'"]+)[\'"][^>]*>/i', $content, $alt_matches)) {
                        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_matches[1]);
                    }

                    // Successfully attached
                    $images_attached++;
                    $total_attached++;

                } catch (Exception $e) {
                    error_log("MLPP: Exception while processing image $image_url: " . $e->getMessage());
                    if (isset($tmp) && file_exists($tmp)) {
                        @unlink($tmp);
                    }
                    continue;
                }
            }

            if ($images_attached > 0) {
                $updated_posts[] = array(
                    'title' => $post->post_title,
                    'edit_url' => get_edit_post_link($post->ID, 'raw'),
                    'images_attached' => $images_attached
                );
            }
        }

        wp_send_json_success(array(
            'done' => count($posts) < $posts_per_page,
            'processed' => $processed,
            'attached' => $total_attached,
            'updated_posts' => $updated_posts,
            'message' => "Successfully processed $processed posts and attached $total_attached images"
        ));

    } catch (Exception $e) {
        error_log("MLPP: Critical error in process_posts: " . $e->getMessage());
        wp_send_json_error(array(
            'message' => 'Error processing posts: ' . $e->getMessage(),
            'error_details' => array(
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            )
        ));
    }
}
add_action('wp_ajax_mlpp_process_posts', 'mlpp_process_posts');

function mlpp_attach_single_image() {
    check_ajax_referer('mlpp-attach-images', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
        return;
    }

    $image_url = isset($_POST['image_url']) ? sanitize_text_field($_POST['image_url']) : '';
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

    if (empty($image_url) || empty($post_id)) {
        wp_send_json_error(array('message' => 'Missing required parameters'));
        return;
    }

    try {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Download and attach the image
        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            throw new Exception($tmp->get_error_message());
        }

        $file_array = array(
            'name' => basename($image_url),
            'tmp_name' => $tmp
        );

        $attachment_id = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            throw new Exception($attachment_id->get_error_message());
        }

        wp_send_json_success(array(
            'message' => 'Image attached successfully',
            'attachment_id' => $attachment_id
        ));
    } catch (Exception $e) {
        error_log("MLPP: Error attaching image: " . $e->getMessage());
        wp_send_json_error(array('message' => $e->getMessage()));
    }
}
add_action('wp_ajax_mlpp_attach_single_image', 'mlpp_attach_single_image');
