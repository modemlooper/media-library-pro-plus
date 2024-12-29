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
            
            <button id="mlpp-start-attachment" class="button button-primary">Start Attachment</button>
            
            <div id="mlpp-progress-container" style="display: none; margin-top: 20px;">
                <div class="mlpp-progress-bar" style="background: #f0f0f0; height: 20px; border-radius: 3px; margin-bottom: 10px;">
                    <div id="mlpp-progress" style="width: 0%; height: 100%; background: #0073aa; border-radius: 3px; transition: width 0.3s;"></div>
                </div>
                <div id="mlpp-status">Ready to start...</div>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('#mlpp-start-attachment').on('click', function() {
            var postType = $('#mlpp-post-type-select').val();
            if (!postType) {
                alert('Please select a post type');
                return;
            }

            var $progress = $('#mlpp-progress');
            var $status = $('#mlpp-status');
            var $container = $('#mlpp-progress-container');
            
            $container.show();
            $(this).prop('disabled', true);

            // Start the attachment process
            attachFeaturedImages(postType);
        });

        function attachFeaturedImages(postType, offset = 0) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mlpp_attach_featured_images',
                    post_type: postType,
                    offset: offset,
                    nonce: '<?php echo wp_create_nonce("mlpp_attach_featured_images"); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        var progress = (response.data.processed / response.data.total) * 100;
                        $('#mlpp-progress').css('width', progress + '%');
                        $('#mlpp-status').text('Processing: ' + response.data.processed + ' of ' + response.data.total);

                        if (response.data.continue) {
                            // Continue with next batch
                            attachFeaturedImages(postType, response.data.offset);
                        } else {
                            // Process complete
                            $('#mlpp-status').text('Complete! Processed ' + response.data.total + ' items.');
                            $('#mlpp-start-attachment').prop('disabled', false);
                        }
                    } else {
                        $('#mlpp-status').text('Error: ' + response.data.message);
                        $('#mlpp-start-attachment').prop('disabled', false);
                    }
                },
                error: function() {
                    $('#mlpp-status').text('Error occurred during processing');
                    $('#mlpp-start-attachment').prop('disabled', false);
                }
            });
        }
    });
    </script>
    <?php
}

// Ajax handler for attachment process
add_action('wp_ajax_mlpp_attach_featured_images', 'mlpp_handle_featured_image_attachment');
function mlpp_handle_featured_image_attachment() {
    check_ajax_referer('mlpp_attach_featured_images', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $post_type = sanitize_text_field($_POST['post_type']);
    $offset = intval($_POST['offset']);
    $batch_size = 1; // Process 10 posts at a time

    $args = [
        'post_type' => $post_type,
        'posts_per_page' => $batch_size,
        'offset' => $offset,
        'post_status' => 'publish',
        'meta_query' => [
            [
                'key' => '_thumbnail_id',
                'compare' => 'NOT EXISTS'
            ]
        ]
    ];

    $query = new WP_Query($args);
    $total_posts = $query->found_posts;

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            
            // Get the first image from the post content
            $post_content = get_the_content();
            preg_match_all('/<img.+src=[\'"]([^\'"]+)[\'"].*>/i', $post_content, $matches);
            
            if (!empty($matches[1])) {
                $image_url = $matches[1][0];
                $upload_dir = wp_upload_dir();
                
                // Check if image is from media library
                if (strpos($image_url, $upload_dir['baseurl']) !== false) {
                    $attachment_id = attachment_url_to_postid($image_url);
                    if ($attachment_id) {
                        set_post_thumbnail($post_id, $attachment_id);
                    }
                }
            }
        }
        wp_reset_postdata();
    }

    $processed = $offset + $batch_size;
    $continue = $processed < $total_posts;

    wp_send_json_success([
        'processed' => min($processed, $total_posts),
        'total' => $total_posts,
        'continue' => $continue,
        'offset' => $processed,
    ]);
}
