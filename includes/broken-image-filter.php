<?php

// Add filter dropdown to media library
add_action('restrict_manage_posts', function() {
    if (!isset($_GET['post_type']) || $_GET['post_type'] !== 'attachment') {
        return;
    }
    
    $broken_filter = isset($_GET['broken_image_filter']) ? $_GET['broken_image_filter'] : '';
    ?>
    <select name="broken_image_filter" id="broken_image_filter">
        <option value=""><?php _e('All Images', 'alt-text-media-library'); ?></option>
        <option value="broken" <?php selected($broken_filter, 'broken'); ?>><?php _e('Broken Images Only', 'alt-text-media-library'); ?></option>
    </select>
    <?php
});

// Modify the query to filter broken images
add_filter('posts_where', function($where, $query) {
    global $wpdb;
    
    if (!is_admin() || !$query->is_main_query()) {
        return $where;
    }
    
    if (isset($_GET['post_type']) && $_GET['post_type'] === 'attachment' && 
        isset($_GET['broken_image_filter']) && $_GET['broken_image_filter'] === 'broken') {
        
        // Get all image attachments
        $attachments = $wpdb->get_results("
            SELECT ID, meta_value 
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE post_type = 'attachment'
            AND post_mime_type LIKE 'image/%'
            AND meta_key = '_wp_attached_file'
        ");
        
        $broken_ids = [];
        foreach ($attachments as $attachment) {
            $file_path = get_attached_file($attachment->ID);
            if (!file_exists($file_path)) {
                $broken_ids[] = $attachment->ID;
            }
        }
        
        if (!empty($broken_ids)) {
            $ids = implode(',', $broken_ids);
            $where .= " AND ID IN ($ids)";
        } else {
            $where .= " AND 1=0"; // Return no results if no broken images found
        }
    }
    
    return $where;
}, 10, 2);
