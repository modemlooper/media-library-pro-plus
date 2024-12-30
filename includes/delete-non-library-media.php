<?php
/**
 * Script to check media files against WordPress media library and backup non-library files
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Media_Library_Backup {
    private $backup_base_dir;
    private $total_files = 0;
    private $processed_files = 0;

    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->backup_base_dir = WP_CONTENT_DIR . '/backups/media-library-backup';
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function add_admin_menu() {
        add_media_page(
            'Media Library Backup',
            'Media Backup',
            'manage_options',
            'media-library-backup',
            array($this, 'render_admin_page')
        );
    }

    public function enqueue_scripts($hook) {
        if ($hook !== 'media_page_media-library-backup') {
            return;
        }

        wp_enqueue_script(
            'media-backup-script',
            plugins_url('/js/media-backup.js', dirname(__FILE__)),
            array('jquery'),
            '1.0.0',
            true
        );

        wp_localize_script('media-backup-script', 'mediaBackup', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('media-backup-nonce')
        ));
    }

    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>Media Library Backup</h1>
            <p>This tool will scan your media library folders and backup any files that are not registered in the WordPress media library.</p>
            
            <div class="progress-container" style="display: none;">
                <div class="progress-bar" style="width: 100%; height: 20px; background-color: #f0f0f0; margin: 20px 0;">
                    <div class="progress" style="width: 0%; height: 100%; background-color: #0073aa; transition: width 0.3s;"></div>
                </div>
                <div class="progress-text">0%</div>
            </div>

            <div class="message-container"></div>
            
            <p class="submit">
                <button id="start-backup" class="button button-primary">Start Backup</button>
            </p>
        </div>
        <?php
    }

    public function start_backup() {
        // Create backup directory if it doesn't exist
        if (!file_exists($this->backup_base_dir)) {
            wp_mkdir_p($this->backup_base_dir);
        }

        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];

        // Get all files first to calculate total
        $this->count_files($base_dir);

        // Process each year directory
        $years = glob($base_dir . '/20*', GLOB_ONLYDIR);
        if (!empty($years)) {
            foreach ($years as $year) {
                $this->process_year_directory($year);
            }
        }

        return sprintf('Backup complete. Processed %d files.', $this->processed_files);
    }

    private function count_files($dir) {
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                $this->total_files++;
            } elseif (is_dir($file)) {
                $this->count_files($file);
            }
        }
    }

    private function process_year_directory($year_path) {
        $months = glob($year_path . '/*', GLOB_ONLYDIR);
        
        if (!empty($months)) {
            foreach ($months as $month) {
                $this->process_month_directory($month);
            }
        }
    }

    private function process_month_directory($month_path) {
        $files = glob($month_path . '/*');
        
        if (!empty($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    $this->process_file($file);
                }
            }
        }
    }

    private function process_file($file_path) {
        $this->processed_files++;
        $progress = ($this->processed_files / $this->total_files) * 100;
        
        // Update progress in the database for AJAX polling
        update_option('media_backup_progress', $progress);

        // Check if file exists in media library
        $attachment_id = $this->get_attachment_id_by_file($file_path);
        
        if (!$attachment_id) {
            // File not in media library, backup
            $this->backup_file($file_path);
        }
    }

    private function get_attachment_id_by_file($file_path) {
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'] . '/', '', $file_path);
        
        global $wpdb;
        $attachment = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = %s;", $relative_path));
        
        return !empty($attachment) ? $attachment[0] : false;
    }

    private function backup_file($file_path) {
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'] . '/', '', $file_path);
        $backup_path = $this->backup_base_dir . '/' . $relative_path;
        
        // Create directory structure if it doesn't exist
        wp_mkdir_p(dirname($backup_path));
        
        // Move file instead of copying
        if (!rename($file_path, $backup_path)) {
            // If move fails, log error
            error_log(sprintf('Failed to move file from %s to %s', $file_path, $backup_path));
        }
    }
}

// Initialize the class
$media_library_backup = new Media_Library_Backup();

// AJAX endpoint to get progress
add_action('wp_ajax_get_backup_progress', function() {
    check_ajax_referer('media-backup-nonce', 'nonce');
    $progress = get_option('media_backup_progress', 0);
    wp_send_json(['progress' => $progress]);
});

// AJAX endpoint to start backup
add_action('wp_ajax_start_media_backup', function() {
    check_ajax_referer('media-backup-nonce', 'nonce');
    $backup = new Media_Library_Backup();
    $result = $backup->start_backup();
    wp_send_json(['message' => $result]);
});
