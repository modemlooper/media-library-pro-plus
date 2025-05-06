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
    
    // Add inline CSS for the new options
    $custom_css = "
        .mlpp-options-row {
            margin-bottom: 15px;
        }
        .mlpp-options-row label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        .mlpp-options-row input[type=text] {
            width: 300px;
        }
        .description {
            font-style: italic;
            color: #666;
            margin: 5px 0 0;
        }
    ";
    wp_add_inline_style('mlpp-attach-images', $custom_css);
}

// Admin page callback
function mlpp_attach_images_page() {
    $post_types = get_post_types(array('public' => true), 'objects');
    ?>
    <div class="wrap">
        <h1>Attach Content Images</h1>
        <div class="mlpp-attach-container">
            <div class="mlpp-options-row">
                <label for="mlpp-post-type">Post Type:</label>
                <select id="mlpp-post-type">
                    <?php foreach ($post_types as $post_type): ?>
                        <option value="<?php echo esc_attr($post_type->name); ?>">
                            <?php echo esc_html($post_type->labels->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="mlpp-options-row">
                <label for="mlpp-regex-suffix" title="Enter a regex pattern to match image slugs (e.g., -\d+&#215;\d+-\d+)">Regex Suffix Pattern:</label>
                <input type="text" id="mlpp-regex-suffix" placeholder="e.g., -\d+&#215;\d+-\d+" value="-\d+&#215;\d+-\d*">
                <p class="description">Pattern to match image slugs like "-1024&#215;431-2". Leave empty to disable.</p>
            </div>
            
            <button id="mlpp-start-process" class="button button-primary">Start Process</button>
            
            <div id="mlpp-progress-container" style="display: none;">
                <div class="mlpp-progress-bar">
                    <div class="mlpp-progress"></div>
                </div>
                <div class="mlpp-status">
                    <span class="mlpp-processed">0</span> / <span class="mlpp-total">0</span> items processed
                </div>
                <div class="mlpp-message"></div>
                <div id="mlpp-results-container"></div>
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
    $regex_suffix = isset($_POST['regex_suffix']) ? sanitize_text_field($_POST['regex_suffix']) : '';
    $post = get_post($post_id);
    
    if (!$post) {
        wp_send_json_error('Post not found');
    }

    // Get post content
    $content = $post->post_content;
    $found_images = array();
    $processed_images = array();
    
    // Store if we're using regex suffix pattern
    $using_regex_suffix = !empty($regex_suffix);
    
    // Method 1: Find all img tags
    preg_match_all('/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i', $content, $matches);
    if (!empty($matches[1])) {
        $found_images = array_merge($found_images, $matches[1]);
    }

    // Method 2: Check for WordPress block image patterns
    if (has_blocks($content)) {
        $blocks = parse_blocks($content);
        foreach ($blocks as $block) {
            if ($block['blockName'] === 'core/image' && !empty($block['attrs']['id'])) {
                $image_url = wp_get_attachment_url($block['attrs']['id']);
                if ($image_url) {
                    $found_images[] = $image_url;
                }
            } elseif ($block['blockName'] === 'core/image' && !empty($block['attrs']['url'])) {
                // Handle image blocks with URL but no ID
                $found_images[] = $block['attrs']['url'];
            } elseif ($block['blockName'] === 'core/gallery' && !empty($block['attrs']['ids'])) {
                foreach ($block['attrs']['ids'] as $image_id) {
                    $image_url = wp_get_attachment_url($image_id);
                    if ($image_url) {
                        $found_images[] = $image_url;
                    }
                }
            }
        }
    }

    // Method 3: Check for gallery shortcodes
    preg_match_all('/\[gallery.*ids=[\'"](.*?)[\'"]/i', $content, $gallery_matches);
    if (!empty($gallery_matches[1])) {
        foreach ($gallery_matches[1] as $gallery_ids) {
            $ids = explode(',', $gallery_ids);
            foreach ($ids as $attachment_id) {
                $image_url = wp_get_attachment_url($attachment_id);
                if ($image_url) {
                    $found_images[] = $image_url;
                }
            }
        }
    }

    // Remove duplicates
    $found_images = array_unique($found_images);
    
    if (empty($found_images)) {
        wp_send_json_success(array(
            'message' => 'No images found in post',
            'attached' => 0,
            'processed_images' => array()
        ));
        return;
    }

    $attached = 0;
    $downloaded = 0;
    foreach ($found_images as $src) {
        $image_info = array(
            'url' => $src,
            'status' => 'not_processed'
        );

        // Skip data URLs or non-HTTP URLs
        if (strpos($src, 'data:') === 0 || (strpos($src, 'http') !== 0 && strpos($src, '//') !== 0)) {
            $image_info['status'] = 'skipped_invalid_url';
            $processed_images[] = $image_info;
            continue;
        }

        // Normalize protocol-relative URLs
        if (strpos($src, '//') === 0) {
            $src = 'https:' . $src;
            $image_info['url'] = $src;
        }
        
        // Extract filename from URL
        $filename = basename(parse_url($src, PHP_URL_PATH));
        $image_info['filename'] = $filename;

        // Get image path from URL
        $upload_dir = wp_upload_dir();
        $attachment_id = null;
        $is_external = false;
        $site_url = get_site_url();
        
        // Check if URL is external
        if (strpos($src, $site_url) === false && strpos($src, $upload_dir['baseurl']) === false) {
            $is_external = true;
            $image_info['is_external'] = true;
        }

        // Try direct URL to post ID first
        $attachment_id = attachment_url_to_postid($src);

        // If not found, try alternative methods
        if (!$attachment_id) {
            // Method 1: Check if URL is in uploads directory
            if (strpos($src, $upload_dir['baseurl']) !== false) {
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
                }
            }
            
            // Method 2: If external URL, check if same filename exists in media library
            if (!$attachment_id && $is_external && !empty($filename)) {
                global $wpdb;
                
                // Search for attachments with the same filename
                $attachment = $wpdb->get_col($wpdb->prepare(
                    "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND guid LIKE %s",
                    '%/' . $filename
                ));
                
                if (!empty($attachment)) {
                    $attachment_id = $attachment[0];
                    $image_info['status'] = 'found_by_filename';
                    $image_info['original_url'] = $src;
                    $src = wp_get_attachment_url($attachment_id);
                    $image_info['url'] = $src;
                }
            }
        }

        if ($attachment_id) {
            $image_info['attachment_id'] = $attachment_id;
            $image_info['title'] = get_the_title($attachment_id);
            $image_info['thumbnail'] = wp_get_attachment_image_url($attachment_id, 'thumbnail');
            
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
                $image_info['status'] = 'attached';
            } else {
                $image_info['status'] = 'already_attached';
            }
        } else {
            // Image doesn't exist in media library, download and attach it
            // Make sure we have the required functions
            if (!function_exists('media_handle_sideload')) {
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/image.php');
            }
            
            // Only download if it's an external URL
            if ($is_external) {
                // Check if URL is an image
                $file_headers = @get_headers($src, 1);
                $content_type = isset($file_headers['Content-Type']) ? $file_headers['Content-Type'] : '';
                
                // If it's an array, get the last value
                if (is_array($content_type)) {
                    $content_type = end($content_type);
                }
                
                if (strpos($content_type, 'image/') !== 0) {
                    $image_info['status'] = 'not_an_image';
                    $processed_images[] = $image_info;
                    continue;
                }
            } else {
                // For local URLs not in media library, check if the file physically exists in uploads directory
                $upload_dir = wp_upload_dir();
                $filename = basename(parse_url($src, PHP_URL_PATH));
                
                // Search for the file in the uploads directory
                $found_file = false;
                $file_path = '';
                
                // First check in the specific media subfolder
                $media_folder = WP_CONTENT_DIR . '/uploads/media';
                if (file_exists($media_folder)) {
                    // Check if file exists directly in the media folder
                    if (file_exists($media_folder . '/' . $filename)) {
                        $found_file = true;
                        $file_path = $media_folder . '/' . $filename;
                    } else {
                        // Check subfolders within the media folder
                        $media_directory = new RecursiveDirectoryIterator($media_folder);
                        $media_iterator = new RecursiveIteratorIterator($media_directory);
                        
                        foreach ($media_iterator as $file) {
                            if ($file->isFile() && $file->getFilename() === $filename) {
                                $found_file = true;
                                $file_path = $file->getPathname();
                                break;
                            }
                        }
                    }
                }
                
                // If not found in media folder, try the entire uploads directory
                if (!$found_file) {
                    $directory = new RecursiveDirectoryIterator($upload_dir['basedir']);
                    $iterator = new RecursiveIteratorIterator($directory);
                    
                    foreach ($iterator as $file) {
                        if ($file->isFile() && $file->getFilename() === $filename) {
                            $found_file = true;
                            $file_path = $file->getPathname();
                            break;
                        }
                    }
                }
                
                if ($found_file) {
                    // File exists physically but not in media library, add it to media library
                    $file_array = array();
                    $file_array['name'] = $filename;
                    
                    // Copy the file to a temp location
                    $tmp = wp_tempnam($filename);
                    copy($file_path, $tmp);
                    $file_array['tmp_name'] = $tmp;
                    
                    // Upload and attach to post
                    $attachment_id = media_handle_sideload($file_array, $post_id);
                    
                    if (is_wp_error($attachment_id)) {
                        @unlink($tmp);
                        $image_info['status'] = 'upload_failed';
                        $image_info['error'] = $attachment_id->get_error_message();
                    } else {
                        $image_info['attachment_id'] = $attachment_id;
                        $image_info['title'] = get_the_title($attachment_id);
                        $image_info['thumbnail'] = wp_get_attachment_image_url($attachment_id, 'thumbnail');
                        $image_info['status'] = 'found_in_uploads_folder';
                        $image_info['original_url'] = $src;
                        $image_info['url'] = wp_get_attachment_url($attachment_id);
                        $attached++;
                    }
                    $processed_images[] = $image_info;
                    continue;
                } else {
                    // Skip downloading for local URLs that weren't found in the media library or uploads folder
                    $image_info['status'] = 'local_url_not_in_media_or_uploads';
                    $processed_images[] = $image_info;
                    continue;
                }
            }
            
            // Generate a unique filename
            $tmp = download_url($src);
            
            if (is_wp_error($tmp)) {
                $image_info['status'] = 'download_failed';
                $image_info['error'] = $tmp->get_error_message();
            } else {
                // Get file info
                $file_array = array();
                $file_array['name'] = basename($src);
                $file_array['tmp_name'] = $tmp;
                
                // Upload and attach to post
                $attachment_id = media_handle_sideload($file_array, $post_id);
                
                if (is_wp_error($attachment_id)) {
                    @unlink($tmp);
                    $image_info['status'] = 'upload_failed';
                    $image_info['error'] = $attachment_id->get_error_message();
                } else {
                    $image_info['attachment_id'] = $attachment_id;
                    $image_info['title'] = get_the_title($attachment_id);
                    $image_info['thumbnail'] = wp_get_attachment_image_url($attachment_id, 'thumbnail');
                    $image_info['status'] = 'downloaded_and_attached';
                    $attached++;
                    $downloaded++;
                }
            }
        }

        $processed_images[] = $image_info;
    }

    // Update post content with local URLs if needed
    $content_updated = false;
    $new_content = $content;
    
    foreach ($processed_images as $image) {
        if (isset($image['original_url']) && isset($image['url']) && $image['original_url'] !== $image['url']) {
            // Replace external URL with local URL in post content
            $new_content = str_replace($image['original_url'], $image['url'], $new_content);
            $content_updated = true;
        }
    }
    
    // Update post if content has changed
    if ($content_updated) {
        wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $new_content
        ));
    }
    
    wp_send_json_success(array(
        'message' => sprintf('%d images attached to post (%d downloaded, %d existing)', $attached, $downloaded, ($attached - $downloaded)),
        'attached' => $attached,
        'downloaded' => $downloaded,
        'content_updated' => $content_updated,
        'processed_images' => $processed_images,
        'post_title' => $post->post_title,
        'post_id' => $post_id
    ));
}
