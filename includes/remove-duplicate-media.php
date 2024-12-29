<?php

if (!defined('ABSPATH')) {
    exit;
}

// Add menu item
add_action('admin_menu', 'mlpp_add_duplicate_removal_page');
function mlpp_add_duplicate_removal_page() {
    add_submenu_page(
        'upload.php',
        'Remove Duplicate Media',
        'Remove Duplicates',
        'manage_options',
        'mlpp-remove-duplicates',
        'mlpp_duplicate_removal_page'
    );
}

// Register AJAX actions
add_action('wp_ajax_mlpp_start_duplicate_removal', 'mlpp_start_duplicate_removal');
add_action('wp_ajax_mlpp_get_progress', 'mlpp_get_progress');
add_action('wp_ajax_mlpp_cancel_duplicate_removal', 'mlpp_cancel_duplicate_removal');

// Admin page display
function mlpp_duplicate_removal_page() {
    ?>
    <div class="wrap">
        <h1>Remove Duplicate Media</h1>
        <div id="mlpp-duplicate-removal-container">
            <p>This tool will scan your media library for duplicate files and remove them while keeping one copy.</p>
            <div id="mlpp-progress-container" style="display: none;">
                <div class="mlpp-progress-bar">
                    <div id="mlpp-progress" style="width: 0%;">0%</div>
                </div>
                <p id="mlpp-status">Preparing to scan...</p>
                <button id="mlpp-cancel-btn" class="button button-secondary">Cancel</button>
            </div>
            <button id="mlpp-start-btn" class="button button-primary">Start Scanning</button>
        </div>
    </div>
    <style>
        .mlpp-progress-bar {
            width: 100%;
            max-width: 600px;
            height: 20px;
            background-color: #f0f0f0;
            border: 1px solid #ccc;
            border-radius: 3px;
            margin: 10px 0;
        }
        #mlpp-progress {
            height: 100%;
            background-color: #0073aa;
            text-align: center;
            line-height: 20px;
            color: white;
            transition: width 0.3s ease-in-out;
        }
    </style>
    <script>
    jQuery(document).ready(function($) {
        let isRunning = false;
        let progressInterval;

        $('#mlpp-start-btn').on('click', function() {
            isRunning = true;
            $('#mlpp-progress-container').show();
            $('#mlpp-start-btn').prop('disabled', true);
            
            $.post(ajaxurl, {
                action: 'mlpp_start_duplicate_removal',
                nonce: '<?php echo wp_create_nonce("mlpp_duplicate_removal"); ?>'
            });

            progressInterval = setInterval(checkProgress, 1000);
        });

        $('#mlpp-cancel-btn').on('click', function() {
            isRunning = false;
            clearInterval(progressInterval);
            $.post(ajaxurl, {
                action: 'mlpp_cancel_duplicate_removal',
                nonce: '<?php echo wp_create_nonce("mlpp_duplicate_removal"); ?>'
            });
            resetUI();
        });

        function checkProgress() {
            if (!isRunning) return;

            $.post(ajaxurl, {
                action: 'mlpp_get_progress',
                nonce: '<?php echo wp_create_nonce("mlpp_duplicate_removal"); ?>'
            }, function(response) {
                if (response.success) {
                    $('#mlpp-progress').css('width', response.data.progress + '%')
                        .text(response.data.progress + '%');
                    $('#mlpp-status').text(response.data.message);

                    if (response.data.complete) {
                        isRunning = false;
                        clearInterval(progressInterval);
                        setTimeout(resetUI, 3000);
                    }
                }
            });
        }

        function resetUI() {
            $('#mlpp-progress-container').hide();
            $('#mlpp-start-btn').prop('disabled', false);
            $('#mlpp-progress').css('width', '0%').text('0%');
            $('#mlpp-status').text('Preparing to scan...');
        }
    });
    </script>
    <?php
}

// Start duplicate removal process
function mlpp_start_duplicate_removal() {
    check_ajax_referer('mlpp_duplicate_removal', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    delete_transient('mlpp_duplicate_removal_progress');
    delete_transient('mlpp_duplicate_removal_cancel');
    
    wp_schedule_single_event(time(), 'mlpp_process_duplicate_removal');
    wp_die();
}

// Process duplicate removal
add_action('mlpp_process_duplicate_removal', 'mlpp_process_duplicate_removal');
function mlpp_process_duplicate_removal() {
    global $wpdb;

    $attachments = $wpdb->get_results("
        SELECT ID, guid, post_title 
        FROM {$wpdb->posts} 
        WHERE post_type = 'attachment'
    ");

    $total = count($attachments);
    $processed = 0;
    $duplicates = [];
    $hashes = [];

    foreach ($attachments as $attachment) {
        if (get_transient('mlpp_duplicate_removal_cancel')) {
            delete_transient('mlpp_duplicate_removal_cancel');
            delete_transient('mlpp_duplicate_removal_progress');
            return;
        }

        $file_path = get_attached_file($attachment->ID);
        if (!file_exists($file_path)) continue;

        $hash = md5_file($file_path);
        
        if (isset($hashes[$hash])) {
            $duplicates[] = $attachment->ID;
        } else {
            $hashes[$hash] = $attachment->ID;
        }

        $processed++;
        $progress = round(($processed / $total) * 100);
        
        set_transient('mlpp_duplicate_removal_progress', [
            'progress' => $progress,
            'message' => "Processed {$processed} of {$total} files...",
            'complete' => false
        ], HOUR_IN_SECONDS);
    }

    // Remove duplicates
    foreach ($duplicates as $duplicate_id) {
        wp_delete_attachment($duplicate_id, true);
    }

    set_transient('mlpp_duplicate_removal_progress', [
        'progress' => 100,
        'message' => "Completed! Removed " . count($duplicates) . " duplicate files.",
        'complete' => true
    ], HOUR_IN_SECONDS);
}

// Get progress
function mlpp_get_progress() {
    check_ajax_referer('mlpp_duplicate_removal', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    $progress = get_transient('mlpp_duplicate_removal_progress');
    if (!$progress) {
        $progress = [
            'progress' => 0,
            'message' => 'Starting...',
            'complete' => false
        ];
    }

    wp_send_json_success($progress);
}

// Cancel process
function mlpp_cancel_duplicate_removal() {
    check_ajax_referer('mlpp_duplicate_removal', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    set_transient('mlpp_duplicate_removal_cancel', true, HOUR_IN_SECONDS);
    wp_die();
}
