<?php
// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Add the alt text column to media library
function add_alt_text_column($columns) {
    $columns['alt_text'] = __('Alt Text', 'alt-text-media-library');
    return $columns;
}
add_filter('manage_media_columns', 'add_alt_text_column');

// Display alt text column content
function display_alt_text_column($column_name, $post_id) {
    if ($column_name !== 'alt_text') {
        return;
    }
    
    $alt_text = get_post_meta($post_id, '_wp_attachment_image_alt', true);
    ?>
    <textarea 
        class="alt-text-input" 
        data-attachment-id="<?php echo esc_attr($post_id); ?>"
        style="width: 100%; min-height: 60px; resize: vertical;"
    ><?php echo esc_textarea($alt_text); ?></textarea>
    <?php
}
add_action('manage_media_custom_column', 'display_alt_text_column', 10, 2);

// Add bulk action
function add_alt_text_bulk_action($bulk_actions) {
    $bulk_actions['save_alt_text'] = __('Save Alt Text', 'alt-text-media-library');
    return $bulk_actions;
}
add_filter('bulk_actions-upload', 'add_alt_text_bulk_action');

// Handle bulk action
function handle_alt_text_bulk_action($redirect_url, $action, $post_ids) {
    if ($action !== 'save_alt_text') {
        return $redirect_url;
    }

    // Add nonce verification
    if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'bulk-media')) {
        return $redirect_url;
    }

    // Check user capabilities
    if (!current_user_can('upload_files')) {
        return $redirect_url;
    }

    foreach ($post_ids as $post_id) {
        $input_name = 'alt_text_' . $post_id;
        if (isset($_REQUEST[$input_name])) {
            $alt_text = sanitize_text_field($_REQUEST[$input_name]);
            update_post_meta($post_id, '_wp_attachment_image_alt', $alt_text);
        }
    }

    $redirect_url = add_query_arg('bulk_alt_text_updated', count($post_ids), $redirect_url);
    return $redirect_url;
}
add_filter('handle_bulk_actions-upload', 'handle_alt_text_bulk_action', 10, 3);

// Display admin notice after bulk update
function display_bulk_alt_text_update_notice() {
    if (!empty($_REQUEST['bulk_alt_text_updated'])) {
        $count = intval($_REQUEST['bulk_alt_text_updated']);
        printf(
            '<div class="updated notice is-dismissible"><p>' . 
            _n(
                'Updated alt text for %s item.',
                'Updated alt text for %s items.',
                $count,
                'alt-text-media-library'
            ) . '</p></div>',
            $count
        );
    }
}
add_action('admin_notices', 'display_bulk_alt_text_update_notice');

// Add JavaScript for handling alt text changes
function add_alt_text_scripts() {
    $screen = get_current_screen();
    if ($screen->base !== 'upload') {
        return;
    }
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Store alt text values when bulk action is triggered
        $('#doaction, #doaction2').click(function(e) {
            var action = $(this).prev('select').val();
            if (action === 'save_alt_text') {
                $('.alt-text-input').each(function() {
                    var attachmentId = $(this).data('attachment-id');
                    var altText = $(this).val();
                    $('<input>').attr({
                        type: 'hidden',
                        name: 'alt_text_' + attachmentId,
                        value: altText
                    }).appendTo('#posts-filter');
                });
            }
        });

        // Individual save functionality
        $('.alt-text-input').change(function() {
            var $input = $(this);
            var attachmentId = $input.data('attachment-id');
            var altText = $input.val();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'save_alt_text',
                    attachment_id: attachmentId,
                    alt_text: altText,
                    nonce: '<?php echo wp_create_nonce('save_alt_text_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $input.css('background-color', '#e7f9e7').delay(1000).queue(function(next) {
                            $(this).css('background-color', '');
                            next();
                        });
                    }
                }
            });
        });
    });
    </script>
    <?php
}
add_action('admin_footer', 'add_alt_text_scripts');

// AJAX handler for individual alt text saves
function handle_save_alt_text() {
    check_ajax_referer('save_alt_text_nonce', 'nonce');

    if (!current_user_can('upload_files')) {
        wp_send_json_error('Permission denied');
    }

    $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;
    $alt_text = isset($_POST['alt_text']) ? sanitize_text_field($_POST['alt_text']) : '';

    if ($attachment_id) {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
        wp_send_json_success();
    }

    wp_send_json_error('Invalid attachment ID');
}
add_action('wp_ajax_save_alt_text', 'handle_save_alt_text');
