<?php
/**
 * AVIF to WebP Converter
 *
 * Converts AVIF images in the media library to WebP format.
 *
 * @package Media_Library_Pro_Plus
 */

// Prevent direct access to this file
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Class MLPP_AVIF_To_WebP_Converter
 * Handles the conversion of AVIF images to WebP format
 */
class MLPP_AVIF_To_WebP_Converter {

    /**
     * Holds the count of converted images
     *
     * @var int
     */
    private $converted_count = 0;

    /**
     * Holds the count of failed conversions
     *
     * @var int
     */
    private $failed_count = 0;

    /**
     * Holds log messages
     *
     * @var array
     */
    private $logs = array();

    /**
     * Holds the current conversion status
     *
     * @var array
     */
    private static $conversion_status = array();
    
    /**
     * Holds the count of images being scanned
     *
     * @var int
     */
    private $scanning_count = 0;

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_convert_avif_to_webp', array( $this, 'ajax_convert_avif_to_webp' ) );
        add_action( 'wp_ajax_get_conversion_status', array( $this, 'ajax_get_conversion_status' ) );
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_management_page(
            'AVIF to WebP Converter',
            'AVIF to WebP',
            'manage_options',
            'avif-to-webp-converter',
            array( $this, 'render_admin_page' )
        );
    }

    /**
     * Enqueue scripts
     *
     * @param string $hook The current admin page.
     */
    public function enqueue_scripts( $hook ) {
        if ( 'tools_page_avif-to-webp-converter' !== $hook ) {
            return;
        }

        wp_enqueue_script(
            'avif-to-webp-converter',
            MEDIA_LIBRARY_PRO_PLUS_URL . '/includes/js/avif-to-webp-converter.js',
            array( 'jquery' ),
            '1.0.0',
            true
        );

        wp_localize_script(
            'avif-to-webp-converter',
            'avifToWebpConverter',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'avif-to-webp-converter-nonce' ),
            )
        );
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>AVIF to WebP Converter</h1>
            <p>This tool will scan your media library for AVIF images and convert them to WebP format.</p>
            <p>WebP provides better compression than AVIF while maintaining good quality and has wider browser support.</p>
            
            <div class="notice notice-warning">
                <p><strong>Important:</strong> Please backup your media library before proceeding. This process will modify your media files.</p>
            </div>
            
            <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
                <h2>Convert AVIF Images</h2>
                <p>Click the button below to start the conversion process:</p>
                <button id="start-conversion" class="button button-primary">Start Conversion</button>
                
                <div id="conversion-progress" style="display: none; margin-top: 20px;">
                    <h3>Conversion Progress</h3>
                    <div class="progress-bar-container" style="background-color: #f0f0f0; height: 20px; width: 100%; border-radius: 4px; margin-bottom: 10px;">
                        <div id="progress-bar" style="background-color: #2271b1; height: 20px; width: 0; border-radius: 4px;"></div>
                    </div>
                    <p id="progress-text">0% complete</p>
                    <p id="conversion-stats"></p>
                </div>
                
                <div id="conversion-results" style="display: none; margin-top: 20px;">
                    <h3>Conversion Results</h3>
                    <div id="conversion-summary"></div>
                    <div id="conversion-log" style="max-height: 300px; overflow-y: auto; background-color: #f9f9f9; padding: 10px; border: 1px solid #ddd; margin-top: 10px;"></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for converting AVIF to WebP
     */
    public function ajax_convert_avif_to_webp() {
        // Check nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'avif-to-webp-converter-nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed' ) );
        }

        // Check user capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to perform this action' ) );
        }

        // Initialize logs and status
        $this->logs = array();
        $this->logs[] = array(
            'type' => 'info',
            'message' => 'Starting AVIF image detection...',
        );
        
        // Initialize conversion status
        self::$conversion_status = array(
            'is_complete' => false,
            'converted' => 0,
            'failed' => 0,
            'total' => 0,
            'logs' => $this->logs,
            'current_image' => 'Initializing scan...',
            'last_update' => time(),
            'scanning_count' => 0,
            'scanning_phase' => true
        );

        // Get AVIF images from media library
        $avif_attachments = $this->get_avif_attachments();
        $total_images = count( $avif_attachments );
        self::$conversion_status['total'] = $total_images;

        if ( $total_images === 0 ) {
            // Add debug information to help troubleshoot why no images were found
            $upload_dir = wp_upload_dir();
            $this->logs[] = array(
                'type' => 'error',
                'message' => 'No AVIF images found in the media library.',
            );
            $this->logs[] = array(
                'type' => 'info',
                'message' => 'Upload directory: ' . $upload_dir['basedir'],
            );
            
            // Check if any files with .avif extension exist in the uploads directory
            $avif_files = $this->count_avif_files_in_directory($upload_dir['basedir']);
            $this->logs[] = array(
                'type' => 'info',
                'message' => 'Total .avif files found in uploads directory: ' . $avif_files,
            );
            
            // Check if WordPress has the AVIF mime type registered
            $mime_types = wp_get_mime_types();
            $avif_mime_registered = isset($mime_types['avif']) ? 'Yes' : 'No';
            $this->logs[] = array(
                'type' => 'info',
                'message' => 'AVIF mime type registered in WordPress: ' . $avif_mime_registered,
            );
            
            // Update status
            self::$conversion_status['is_complete'] = true;
            self::$conversion_status['logs'] = $this->logs;
            
            wp_send_json_success( array(
                'message' => 'No AVIF images found in the media library.',
                'converted' => 0,
                'failed' => 0,
                'total' => 0,
                'logs' => $this->logs,
                'is_complete' => true
            ) );
            return;
        }

        // Log found images
        $this->logs[] = array(
            'type' => 'success',
            'message' => 'Found ' . $total_images . ' AVIF images to convert.',
        );
        
        // Add some details about the found images
        foreach ( $avif_attachments as $index => $attachment_id ) {
            if ($index < 5) { // Only show details for the first 5 images to avoid overwhelming the log
                $file_path = get_attached_file( $attachment_id );
                $this->logs[] = array(
                    'type' => 'info',
                    'message' => 'Image #' . ($index + 1) . ': ID ' . $attachment_id . ' - ' . basename( $file_path ),
                );
            } else if ($index === 5) {
                $this->logs[] = array(
                    'type' => 'info',
                    'message' => '... and ' . ($total_images - 5) . ' more images',
                );
                break;
            }
        }
        
        // Update status with initial logs
        self::$conversion_status['logs'] = $this->logs;
        
        // Start the conversion process in the background
        $this->start_background_conversion($avif_attachments);
        
        // Return initial status
        wp_send_json_success( array(
            'message' => 'Starting conversion of ' . $total_images . ' AVIF images...',
            'converted' => 0,
            'failed' => 0,
            'total' => $total_images,
            'logs' => $this->logs,
            'is_complete' => false
        ) );
    }
    
    /**
     * Start background conversion process
     * 
     * @param array $attachments Array of attachment IDs to convert
     */
    private function start_background_conversion($attachments) {
        // Reset counters
        $this->converted_count = 0;
        $this->failed_count = 0;
        
        // Start the conversion in a non-blocking way
        $this->process_attachments($attachments);
    }
    
    /**
     * Process attachments for conversion
     * 
     * @param array $attachments Array of attachment IDs to convert
     */
    private function process_attachments($attachments) {
        // Convert each AVIF image to WebP
        foreach ( $attachments as $attachment_id ) {
            // Update current image being processed
            $file_path = get_attached_file( $attachment_id );
            $filename = basename( $file_path );
            self::$conversion_status['current_image'] = 'Converting: ' . $filename;
            
            // Add to logs
            $log_entry = array(
                'type' => 'info',
                'message' => 'Processing image: ' . $filename . ' (ID: ' . $attachment_id . ')',
            );
            $this->logs[] = $log_entry;
            self::$conversion_status['logs'][] = $log_entry;
            
            // Convert the attachment
            $this->convert_attachment_to_webp( $attachment_id );
            
            // Update status
            self::$conversion_status['converted'] = $this->converted_count;
            self::$conversion_status['failed'] = $this->failed_count;
            self::$conversion_status['last_update'] = time();
        }
        
        // Mark conversion as complete
        self::$conversion_status['is_complete'] = true;
        
        // Add completion log
        $completion_log = array(
            'type' => 'success',
            'message' => sprintf(
                'Conversion complete. %d images converted, %d failed.',
                $this->converted_count,
                $this->failed_count
            ),
        );
        $this->logs[] = $completion_log;
        self::$conversion_status['logs'][] = $completion_log;
    }
    
    /**
     * AJAX handler for getting conversion status
     */
    public function ajax_get_conversion_status() {
        // Check nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'avif-to-webp-converter-nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed' ) );
        }

        // Check user capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to perform this action' ) );
        }
        
        // Get the current status
        $status = self::$conversion_status;
        
        // If no status exists yet, return an empty status
        if (empty($status)) {
            wp_send_json_success(array(
                'is_complete' => true,
                'converted' => 0,
                'failed' => 0,
                'total' => 0,
                'logs' => array(array(
                    'type' => 'error',
                    'message' => 'No conversion in progress.'
                ))
            ));
            return;
        }
        
        // Return the current status
        wp_send_json_success($status);
    }
    
    /**
     * Count AVIF files in a directory and its subdirectories
     *
     * @param string $dir Directory to scan
     * @return int Number of AVIF files found
     */
    private function count_avif_files_in_directory($dir) {
        $count = 0;
        
        // Count files with .avif extension in current directory
        $files = glob($dir . '/*.avif');
        $count += count($files);
        
        // Scan subdirectories
        $subdirs = glob($dir . '/*', GLOB_ONLYDIR);
        foreach ($subdirs as $subdir) {
            $count += $this->count_avif_files_in_directory($subdir);
        }
        
        return $count;
    }

    /**
     * Get all AVIF attachments from the media library
     *
     * @return array Array of attachment IDs
     */
    private function get_avif_attachments() {
        global $wpdb;
        $avif_attachments = array();
        $this->scanning_count = 0;
        
        $log_entry = array(
            'type' => 'info',
            'message' => 'Starting scan for AVIF images using multiple methods...',
        );
        $this->logs[] = $log_entry;
        self::$conversion_status['logs'][] = $log_entry;
        self::$conversion_status['current_image'] = 'Scanning for AVIF images...';
        
        // Method 1: Try to find by MIME type (standard approach)
        self::$conversion_status['current_image'] = 'Scanning by MIME type...';
        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image/avif',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        );
        
        $mime_type_results = get_posts( $args );
        $this->scanning_count += count($mime_type_results);
        self::$conversion_status['scanning_count'] = $this->scanning_count;
        
        if (!empty($mime_type_results)) {
            $avif_attachments = array_merge($avif_attachments, $mime_type_results);
            $log_entry = array(
                'type' => 'info',
                'message' => 'Found ' . count($mime_type_results) . ' images with MIME type "image/avif"',
            );
            $this->logs[] = $log_entry;
            self::$conversion_status['logs'][] = $log_entry;
        } else {
            $log_entry = array(
                'type' => 'info',
                'message' => 'No images found with MIME type "image/avif"',
            );
            $this->logs[] = $log_entry;
            self::$conversion_status['logs'][] = $log_entry;
        }
        
        // Method 2: Search by file extension in attachment metadata
        self::$conversion_status['current_image'] = 'Scanning metadata for .avif extension...';
        $upload_dir = wp_upload_dir();
        $attachments = $wpdb->get_results(
            "SELECT ID, post_title, meta_value FROM {$wpdb->posts} p 
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
            WHERE p.post_type = 'attachment' 
            AND pm.meta_key = '_wp_attachment_metadata'"
        );
        
        $extension_count = 0;
        $total_checked = 0;
        foreach ($attachments as $attachment) {
            $total_checked++;
            
            // Update status every 50 items
            if ($total_checked % 50 === 0) {
                self::$conversion_status['current_image'] = 'Scanning metadata: checked ' . $total_checked . ' of ' . count($attachments) . ' attachments...';
                self::$conversion_status['last_update'] = time();
            }
            
            $meta = maybe_unserialize($attachment->meta_value);
            $found = false;
            
            // Check if the main file has .avif extension
            if (!empty($meta['file']) && preg_match('/\.avif$/i', $meta['file'])) {
                $avif_attachments[] = $attachment->ID;
                $extension_count++;
                $this->scanning_count++;
                self::$conversion_status['scanning_count'] = $this->scanning_count;
                
                // Log the found image
                $log_entry = array(
                    'type' => 'info',
                    'message' => 'Found AVIF in metadata: ' . basename($meta['file']),
                );
                $this->logs[] = $log_entry;
                self::$conversion_status['logs'][] = $log_entry;
                continue;
            }
            
            // Check if any of the sizes have .avif extension
            if (!empty($meta['sizes'])) {
                foreach ($meta['sizes'] as $size) {
                    if (!empty($size['file']) && preg_match('/\.avif$/i', $size['file'])) {
                        $avif_attachments[] = $attachment->ID;
                        $extension_count++;
                        $this->scanning_count++;
                        self::$conversion_status['scanning_count'] = $this->scanning_count;
                        
                        // Log the found image
                        $log_entry = array(
                            'type' => 'info',
                            'message' => 'Found AVIF in size metadata: ' . basename($size['file']),
                        );
                        $this->logs[] = $log_entry;
                        self::$conversion_status['logs'][] = $log_entry;
                        break;
                    }
                }
            }
        }
        
        $log_entry = array(
            'type' => 'info',
            'message' => 'Found ' . $extension_count . ' images with .avif extension in metadata',
        );
        $this->logs[] = $log_entry;
        self::$conversion_status['logs'][] = $log_entry;
        
        // Method 3: Check all image attachments by examining the actual file content
        self::$conversion_status['current_image'] = 'Checking image files by content signature...';
        $log_entry = array(
            'type' => 'info',
            'message' => 'Checking image attachments by file content signature...',
        );
        $this->logs[] = $log_entry;
        self::$conversion_status['logs'][] = $log_entry;
        
        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        );
        
        $all_image_attachments = get_posts( $args );
        $signature_count = 0;
        $checked_count = 0;
        
        foreach ($all_image_attachments as $attachment_id) {
            $checked_count++;
            
            // Update status every 20 items
            if ($checked_count % 20 === 0) {
                self::$conversion_status['current_image'] = 'Checking file signatures: ' . $checked_count . ' of ' . count($all_image_attachments) . '...';
                self::$conversion_status['last_update'] = time();
            }
            
            if (in_array($attachment_id, $avif_attachments)) {
                continue; // Skip if already identified as AVIF
            }
            
            $file_path = get_attached_file($attachment_id);
            if (!$file_path || !file_exists($file_path)) {
                continue;
            }
            
            // Check if the file is actually an AVIF by examining its signature
            if ($this->is_avif_by_signature($file_path)) {
                $avif_attachments[] = $attachment_id;
                $signature_count++;
                $this->scanning_count++;
                self::$conversion_status['scanning_count'] = $this->scanning_count;
                
                // Log the found image
                $log_entry = array(
                    'type' => 'info',
                    'message' => 'Found AVIF by signature: ' . basename($file_path),
                );
                $this->logs[] = $log_entry;
                self::$conversion_status['logs'][] = $log_entry;
            }
        }
        
        $log_entry = array(
            'type' => 'info',
            'message' => 'Found ' . $signature_count . ' additional AVIF images by file signature',
        );
        $this->logs[] = $log_entry;
        self::$conversion_status['logs'][] = $log_entry;
        
        // Method 4: Scan the uploads directory for AVIF files and match to attachments
        self::$conversion_status['current_image'] = 'Scanning uploads directory for AVIF files...';
        $log_entry = array(
            'type' => 'info',
            'message' => 'Scanning uploads directory for AVIF files...',
        );
        $this->logs[] = $log_entry;
        self::$conversion_status['logs'][] = $log_entry;
        
        $before_count = count($avif_attachments);
        $this->scan_uploads_for_avif($upload_dir['basedir'], $avif_attachments);
        $after_count = count($avif_attachments);
        
        $log_entry = array(
            'type' => 'info',
            'message' => 'Found ' . ($after_count - $before_count) . ' additional images by scanning uploads directory',
        );
        $this->logs[] = $log_entry;
        self::$conversion_status['logs'][] = $log_entry;
        
        // Method 5: Check for any image with "avif" in the filename
        self::$conversion_status['current_image'] = 'Checking for "avif" in filenames...';
        $filename_results = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} p 
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
            WHERE p.post_type = 'attachment' 
            AND pm.meta_key = '_wp_attached_file' 
            AND (pm.meta_value LIKE '%avif%' OR p.post_title LIKE '%avif%')"
        );
        
        if (!empty($filename_results)) {
            $before_count = count($avif_attachments);
            $avif_attachments = array_merge($avif_attachments, $filename_results);
            $after_count = count(array_unique($avif_attachments));
            $this->scanning_count += ($after_count - $before_count);
            self::$conversion_status['scanning_count'] = $this->scanning_count;
            
            $log_entry = array(
                'type' => 'info',
                'message' => 'Found ' . ($after_count - $before_count) . ' additional images with "avif" in filename',
            );
            $this->logs[] = $log_entry;
            self::$conversion_status['logs'][] = $log_entry;
        }
        
        // Remove duplicates
        $unique_attachments = array_unique($avif_attachments);
        
        $log_entry = array(
            'type' => 'success',
            'message' => 'Scan complete! Total unique AVIF images found: ' . count($unique_attachments),
        );
        $this->logs[] = $log_entry;
        self::$conversion_status['logs'][] = $log_entry;
        self::$conversion_status['scanning_phase'] = false;
        
        return $unique_attachments;
    }
    
    /**
     * Check if a file is an AVIF image by examining its signature
     *
     * @param string $file_path Path to the file
     * @return bool True if the file is an AVIF image, false otherwise
     */
    private function is_avif_by_signature($file_path) {
        // AVIF files start with the ftyp box with brand 'avif' or 'avis'
        $handle = fopen($file_path, 'rb');
        if (!$handle) {
            return false;
        }
        
        // Read the first 32 bytes (should be enough to identify AVIF)
        $data = fread($handle, 32);
        fclose($handle);
        
        // Check for AVIF signature
        // The signature can be at position 4 or 8 depending on the file structure
        return (strpos($data, 'ftypavis') !== false || strpos($data, 'ftypavif') !== false);
    }
    
    /**
     * Scan uploads directory for AVIF files and match them to attachments
     *
     * @param string $dir Directory to scan
     * @param array &$avif_attachments Array to populate with attachment IDs
     * @param int $depth Current recursion depth (for status updates)
     */
    private function scan_uploads_for_avif($dir, &$avif_attachments, $depth = 0) {
        global $wpdb;
        
        // Update status with current directory being scanned (only for top-level directories to avoid too many updates)
        if ($depth <= 1) {
            $relative_dir = str_replace(wp_upload_dir()['basedir'], '', $dir);
            $display_dir = empty($relative_dir) ? '/' : $relative_dir;
            self::$conversion_status['current_image'] = 'Scanning directory: ' . $display_dir . '...';
            self::$conversion_status['last_update'] = time();
        }
        
        // Find all .avif files in this directory
        $files = glob($dir . '/*.avif');
        
        if (!empty($files)) {
            $log_entry = array(
                'type' => 'info',
                'message' => 'Found ' . count($files) . ' AVIF files in ' . str_replace(wp_upload_dir()['basedir'], '', $dir),
            );
            $this->logs[] = $log_entry;
            self::$conversion_status['logs'][] = $log_entry;
        }
        
        foreach ($files as $file) {
            $relative_path = str_replace(wp_upload_dir()['basedir'] . '/', '', $file);
            
            // Update status with current file being processed
            self::$conversion_status['current_image'] = 'Checking file: ' . basename($file);
            
            // Try to find attachment by file path
            $attachment_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = '_wp_attached_file' 
                AND meta_value = %s",
                $relative_path
            ));
            
            if ($attachment_id) {
                $avif_attachments[] = $attachment_id;
                $this->scanning_count++;
                self::$conversion_status['scanning_count'] = $this->scanning_count;
                
                // Log the found image
                $log_entry = array(
                    'type' => 'info',
                    'message' => 'Found AVIF in uploads: ' . basename($file),
                );
                $this->logs[] = $log_entry;
                self::$conversion_status['logs'][] = $log_entry;
                continue;
            }
            
            // Try to find by filename in metadata
            $filename = basename($file);
            $meta_like = '%' . $wpdb->esc_like($filename) . '%';
            $attachment_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = '_wp_attachment_metadata' 
                AND meta_value LIKE %s",
                $meta_like
            ));
            
            if (!empty($attachment_ids)) {
                $avif_attachments = array_merge($avif_attachments, $attachment_ids);
                $this->scanning_count += count($attachment_ids);
                self::$conversion_status['scanning_count'] = $this->scanning_count;
                
                // Log the found images
                $log_entry = array(
                    'type' => 'info',
                    'message' => 'Found ' . count($attachment_ids) . ' attachments matching ' . $filename,
                );
                $this->logs[] = $log_entry;
                self::$conversion_status['logs'][] = $log_entry;
            }
        }
        
        // Scan subdirectories
        $subdirs = glob($dir . '/*', GLOB_ONLYDIR);
        foreach ($subdirs as $subdir) {
            $this->scan_uploads_for_avif($subdir, $avif_attachments, $depth + 1);
        }
    }

    /**
     * Convert an AVIF attachment to WebP
     *
     * @param int $attachment_id The attachment ID.
     */
    private function convert_attachment_to_webp( $attachment_id ) {
        // Set time limit for this conversion to avoid timeout
        set_time_limit(30); // Reset time limit for each image
        
        $attachment_meta = wp_get_attachment_metadata( $attachment_id );
        if ( ! $attachment_meta || empty( $attachment_meta['file'] ) ) {
            $this->log_error( $attachment_id, 'Could not get attachment metadata' );
            return;
        }

        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/' . $attachment_meta['file'];
        $filename = basename($file_path);

        // Update status with current file being converted
        self::$conversion_status['current_image'] = 'Converting: ' . $filename;
        $log_entry = array(
            'type' => 'info',
            'message' => 'Starting conversion of ' . $filename . ' (ID: ' . $attachment_id . ')',
        );
        $this->logs[] = $log_entry;
        self::$conversion_status['logs'][] = $log_entry;

        if ( ! file_exists( $file_path ) ) {
            $this->log_error( $attachment_id, 'Original file not found: ' . $file_path );
            return;
        }

        // Create WebP version of the original file
        $webp_path = $this->get_webp_path( $file_path );
        
        // Update status with current operation
        self::$conversion_status['current_image'] = 'Converting main file: ' . $filename;
        self::$conversion_status['last_update'] = time();
        
        if ( ! $this->convert_to_webp( $file_path, $webp_path ) ) {
            $this->log_error( $attachment_id, 'Failed to convert original file to WebP' );
            return;
        }

        // Update attachment metadata
        $pathinfo = pathinfo( $attachment_meta['file'] );
        $new_file_name = $pathinfo['filename'] . '.webp';
        $new_file_path = $pathinfo['dirname'] . '/' . $new_file_name;
        $attachment_meta['file'] = $new_file_path;

        // Convert all image sizes
        if ( ! empty( $attachment_meta['sizes'] ) ) {
            $size_count = count($attachment_meta['sizes']);
            $current_size = 0;
            
            foreach ( $attachment_meta['sizes'] as $size_name => $size_data ) {
                $current_size++;
                
                // Update status with current size being converted
                self::$conversion_status['current_image'] = 'Converting size ' . $size_name . ' (' . $current_size . '/' . $size_count . ') for: ' . $filename;
                self::$conversion_status['last_update'] = time();
                
                $size_file_path = $upload_dir['basedir'] . '/' . $pathinfo['dirname'] . '/' . $size_data['file'];
                if ( file_exists( $size_file_path ) ) {
                    $size_webp_path = $this->get_webp_path( $size_file_path );
                    if ( $this->convert_to_webp( $size_file_path, $size_webp_path ) ) {
                        // Update size data in metadata
                        $size_pathinfo = pathinfo( $size_data['file'] );
                        $attachment_meta['sizes'][$size_name]['file'] = $size_pathinfo['filename'] . '.webp';
                        $attachment_meta['sizes'][$size_name]['mime-type'] = 'image/webp';
                        
                        // Log successful size conversion
                        $log_entry = array(
                            'type' => 'info',
                            'message' => 'Converted size ' . $size_name . ' for ' . $filename,
                        );
                        $this->logs[] = $log_entry;
                        self::$conversion_status['logs'][] = $log_entry;
                    } else {
                        $this->log_error( $attachment_id, 'Failed to convert size ' . $size_name . ' to WebP' );
                    }
                }
            }
        }

        // Update attachment metadata and mime type
        self::$conversion_status['current_image'] = 'Updating metadata for: ' . $filename;
        wp_update_attachment_metadata( $attachment_id, $attachment_meta );
        wp_update_post( array(
            'ID' => $attachment_id,
            'post_mime_type' => 'image/webp',
        ) );

        // Update any posts that use this image
        self::$conversion_status['current_image'] = 'Updating post references for: ' . $filename;
        $this->update_post_content( $attachment_id, $file_path, $webp_path );

        $this->converted_count++;
        $success_log = array(
            'type' => 'success',
            'message' => sprintf(
                'Converted attachment #%d: %s to WebP',
                $attachment_id,
                basename( $file_path )
            ),
        );
        $this->logs[] = $success_log;
        self::$conversion_status['logs'][] = $success_log;
        self::$conversion_status['converted'] = $this->converted_count;
    }

    /**
     * Get the WebP path for a given file path
     *
     * @param string $file_path The original file path.
     * @return string The WebP file path.
     */
    private function get_webp_path( $file_path ) {
        $pathinfo = pathinfo( $file_path );
        return $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '.webp';
    }

    /**
     * Convert an image to WebP format
     *
     * @param string $source_path The source image path.
     * @param string $dest_path The destination WebP path.
     * @return bool True on success, false on failure.
     */
    private function convert_to_webp( $source_path, $dest_path ) {
        if ( ! function_exists( 'imagecreatefromavif' ) ) {
            // PHP doesn't have AVIF support, try using GD for generic conversion
            return $this->convert_using_gd( $source_path, $dest_path );
        }

        // Use PHP's built-in AVIF support
        try {
            $image = imagecreatefromavif( $source_path );
            if ( ! $image ) {
                return false;
            }

            // Set WebP quality (0-100)
            $result = imagewebp( $image, $dest_path, 80 );
            imagedestroy( $image );

            return $result;
        } catch ( Exception $e ) {
            return false;
        }
    }

    /**
     * Fallback conversion using GD library
     *
     * @param string $source_path The source image path.
     * @param string $dest_path The destination WebP path.
     * @return bool True on success, false on failure.
     */
    private function convert_using_gd( $source_path, $dest_path ) {
        try {
            // Try to determine image type and create image resource
            $image_info = getimagesize( $source_path );
            if ( ! $image_info ) {
                return false;
            }

            $image = null;
            switch ( $image_info[2] ) {
                case IMAGETYPE_JPEG:
                    $image = imagecreatefromjpeg( $source_path );
                    break;
                case IMAGETYPE_PNG:
                    $image = imagecreatefrompng( $source_path );
                    break;
                case IMAGETYPE_GIF:
                    $image = imagecreatefromgif( $source_path );
                    break;
                default:
                    // Try using Imagick as a fallback
                    return $this->convert_using_imagick( $source_path, $dest_path );
            }

            if ( ! $image ) {
                return false;
            }

            // Set WebP quality (0-100)
            $result = imagewebp( $image, $dest_path, 80 );
            imagedestroy( $image );

            return $result;
        } catch ( Exception $e ) {
            return false;
        }
    }

    /**
     * Fallback conversion using ImageMagick
     *
     * @param string $source_path The source image path.
     * @param string $dest_path The destination WebP path.
     * @return bool True on success, false on failure.
     */
    private function convert_using_imagick( $source_path, $dest_path ) {
        if ( ! class_exists( 'Imagick' ) ) {
            return false;
        }

        try {
            $imagick = new Imagick( $source_path );
            $imagick->setImageFormat( 'webp' );
            $imagick->setImageCompressionQuality( 80 );
            $result = $imagick->writeImage( $dest_path );
            $imagick->clear();
            $imagick->destroy();

            return $result;
        } catch ( Exception $e ) {
            return false;
        }
    }

    /**
     * Update post content to use the new WebP image
     *
     * @param int    $attachment_id The attachment ID.
     * @param string $old_path The old file path.
     * @param string $new_path The new WebP file path.
     */
    private function update_post_content( $attachment_id, $old_path, $new_path ) {
        $old_url = wp_get_attachment_url( $attachment_id );
        $new_url = str_replace( basename( $old_path ), basename( $new_path ), $old_url );

        // Get all posts that might contain this image
        $posts = get_posts( array(
            'post_type'      => 'any',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            's'              => basename( $old_path ),
            'fields'         => 'ids',
        ) );

        foreach ( $posts as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post || empty( $post->post_content ) ) {
                continue;
            }

            // Replace old URL with new URL in post content
            $updated_content = str_replace( $old_url, $new_url, $post->post_content );
            if ( $updated_content !== $post->post_content ) {
                wp_update_post( array(
                    'ID'           => $post_id,
                    'post_content' => $updated_content,
                ) );

                $this->logs[] = array(
                    'type' => 'info',
                    'message' => sprintf(
                        'Updated references in post #%d',
                        $post_id
                    ),
                );
            }
        }
    }

    /**
     * Log an error
     *
     * @param int    $attachment_id The attachment ID.
     * @param string $message The error message.
     */
    private function log_error( $attachment_id, $message ) {
        $this->failed_count++;
        $this->logs[] = array(
            'type' => 'error',
            'message' => sprintf(
                'Error converting attachment #%d: %s',
                $attachment_id,
                $message
            ),
        );
    }
}

// Initialize the converter
$mlpp_avif_to_webp_converter = new MLPP_AVIF_To_WebP_Converter();
