<?php

/**
 * Adds post type dropdown and missing alt text checkbox filter to media library screen.
 * This function creates the UI elements for filtering media items.
 *
 * @since 1.0.0
 * @return void
 */
function add_post_type_filter() {
    // Only add filter to media library screen
    $screen = get_current_screen();
    if ($screen->id !== 'upload') {
        return;
    }

    // Get all public post types for the dropdown
    $post_types = get_post_types(['public' => true], 'objects');
    
    // Get current filter values from URL parameters
    $selected = isset($_GET['post_type_filter']) ? sanitize_key($_GET['post_type_filter']) : '';
    $missing_alt = isset($_GET['missing_alt_text']) ? 'checked' : '';
    ?>
    <select name="post_type_filter" id="post-type-filter">
        <option value=""><?php _e('All Post Types', 'alt-text-media-library'); ?></option>
        <?php foreach ($post_types as $type): ?>
            <?php if ($type->name !== 'attachment'): // Exclude attachment post type from the list ?>
                <option value="<?php echo esc_attr($type->name); ?>" <?php selected($selected, $type->name); ?>>
                    <?php echo esc_html($type->label); ?>
                </option>
            <?php endif; ?>
        <?php endforeach; ?>
    </select>
    <label style="display: inline-block; margin-left: 10px;">
        <input type="checkbox" name="missing_alt_text" value="1" <?php echo $missing_alt; ?>>
        <?php _e('Missing alt text', 'alt-text-media-library'); ?>
    </label>
    <?php
}
add_action('restrict_manage_posts', 'add_post_type_filter');

/**
 * Retrieves all attachment IDs associated with a specific post type.
 * This includes both directly attached media and featured images.
 *
 * @since 1.0.0
 * @param string $post_type The post type to get attachments for
 * @return array Array of attachment IDs, empty array if none found
 */
function get_post_type_attachments($post_type) {
    // Get all posts of the selected post type
    $posts = get_posts(array(
        'post_type' => $post_type,
        'posts_per_page' => -1,
        'fields' => 'ids'
    ));

    if (empty($posts)) {
        return array();
    }

    global $wpdb;
    
    // Complex query to get:
    // 1. Directly attached media (post_parent relationship)
    // 2. Featured images (_thumbnail_id meta relationship)
    $attachment_ids = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT p.ID
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->posts} parent ON p.post_parent = parent.ID
        WHERE p.post_type = 'attachment'
        AND (
            p.post_parent IN (" . implode(',', array_map('intval', $posts)) . ")
            OR p.ID IN (
                SELECT meta_value 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = '_thumbnail_id' 
                AND post_id IN (" . implode(',', array_map('intval', $posts)) . ")
            )
        )
    "));

    return $attachment_ids ?: array();
}

/**
 * Modifies the main query for the media library to apply our custom filters.
 * Handles both post type filtering and missing alt text filtering.
 *
 * @since 1.0.0
 * @param WP_Query $query The WordPress query object
 * @return void
 */
function filter_media_by_post_type($query) {
    // Only modify admin media library queries
    if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'attachment') {
        return;
    }

    // Apply post type filter if selected
    $post_type_filter = isset($_GET['post_type_filter']) ? sanitize_key($_GET['post_type_filter']) : '';
    if (!empty($post_type_filter)) {
        $attachment_ids = get_post_type_attachments($post_type_filter);
        if (!empty($attachment_ids)) {
            $query->set('post__in', $attachment_ids);
        } else {
            // Force no results if no attachments found
            $query->set('post__in', [0]);
        }
    }

    // Apply missing alt text filter if checked
    if (isset($_GET['missing_alt_text'])) {
        $meta_query = $query->get('meta_query', array());
        $meta_query[] = array(
            'relation' => 'OR',
            array(
                'key' => '_wp_attachment_image_alt',
                'compare' => 'NOT EXISTS'
            ),
            array(
                'key' => '_wp_attachment_image_alt',
                'value' => '',
                'compare' => '='
            )
        );
        $query->set('meta_query', $meta_query);
    }
}
add_action('pre_get_posts', 'filter_media_by_post_type');

