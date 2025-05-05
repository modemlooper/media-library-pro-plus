/**
 * AVIF to WebP Converter JavaScript
 * 
 * Handles the UI interactions and AJAX requests for the AVIF to WebP converter tool.
 */
(function($) {
    'use strict';

    // Variables to track conversion progress
    let totalImages = 0;
    let convertedImages = 0;
    let failedImages = 0;
    let conversionLogs = [];
    let imageIds = [];
    let currentBatch = 0;
    let batchSize = 5;
    let isConverting = false;
    let currentImageInfo = '';

    /**
     * Initialize the converter
     */
    function initConverter() {
        $('#start-conversion').on('click', function() {
            startConversion();
        });
    }

    /**
     * Start the conversion process
     */
    function startConversion() {
        if (isConverting) {
            return;
        }

        // Reset counters
        totalImages = 0;
        convertedImages = 0;
        failedImages = 0;
        conversionLogs = [];
        imageIds = [];
        currentBatch = 0;
        isConverting = true;
        currentImageInfo = '';

        // Show progress UI
        $('#conversion-progress').show();
        $('#conversion-results').hide();
        $('#progress-bar').css('width', '0%');
        $('#progress-text').text('0% complete');
        $('#conversion-stats').text('Scanning for AVIF images...');
        $('#start-conversion').prop('disabled', true);
        
        // Add current image info display
        if ($('#current-image-info').length === 0) {
            $('#progress-text').after('<p id="current-image-info" style="margin-top: 5px; font-style: italic;"></p>');
        }
        $('#current-image-info').text('Initializing...');

        // Make the AJAX call to start conversion
        $.ajax({
            url: avifToWebpConverter.ajax_url,
            type: 'POST',
            data: {
                action: 'convert_avif_to_webp',
                nonce: avifToWebpConverter.nonce
            },
            success: function(response) {
                if (response.success) {
                    // If the conversion is still in progress, start polling for updates
                    if (!response.data.is_complete) {
                        // Start polling for progress updates
                        setTimeout(updateConversionProgress, 1000);
                    } else {
                        // Conversion completed immediately
                        handleConversionResponse(response.data);
                    }
                } else {
                    handleError(response.data);
                    isConverting = false;
                    $('#start-conversion').prop('disabled', false);
                }
            },
            error: function() {
                handleError({ message: 'An error occurred while converting AVIF images.' });
                isConverting = false;
                $('#start-conversion').prop('disabled', false);
            }
        });
    }

    /**
     * Handle the conversion response
     * 
     * @param {Object} data Response data from the conversion process
     */
    function handleConversionResponse(data) {
        // Update counters
        totalImages = data.total || 0;
        convertedImages = data.converted || 0;
        failedImages = data.failed || 0;
        conversionLogs = data.logs || [];
        
        if (totalImages === 0) {
            // No images were found to convert
            handleNoImages(data);
            return;
        }
        
        // Update UI with results
        updateProgressUI(100);
        $('#conversion-stats').text('Conversion complete: ' + convertedImages + ' converted, ' + failedImages + ' failed');
        $('#current-image-info').text('');
        
        // Show the results
        finishConversion();
    }
    
    /**
     * Handle case when no images are found
     * 
     * @param {Object} data Response data
     */
    function handleNoImages(data) {
        isConverting = false;
        $('#start-conversion').prop('disabled', false);
        
        // Show results
        $('#conversion-results').show();
        
        // Create summary
        let summaryHtml = '<p>' + (data.message || 'No AVIF images found in the media library.') + '</p>';
        $('#conversion-summary').html(summaryHtml);
        
        // Create log
        if (conversionLogs.length > 0) {
            let logHtml = '<ul>';
            for (let i = 0; i < conversionLogs.length; i++) {
                const log = conversionLogs[i];
                const logClass = log.type === 'error' ? 'color: #d63638;' : 
                                (log.type === 'success' ? 'color: #00a32a;' : 'color: #2271b1;');
                logHtml += '<li style="' + logClass + '">' + log.message + '</li>';
            }
            logHtml += '</ul>';
            $('#conversion-log').html(logHtml);
        } else {
            $('#conversion-log').html('<p>No logs available.</p>');
        }
    }
    
    /**
     * Update the progress UI during conversion
     * 
     * This function is called periodically to update the progress bar
     * based on the logs received from the server
     */
    function updateConversionProgress() {
        if (!isConverting) {
            return;
        }
        
        // Make an AJAX call to get the current conversion status
        $.ajax({
            url: avifToWebpConverter.ajax_url,
            type: 'POST',
            data: {
                action: 'get_conversion_status',
                nonce: avifToWebpConverter.nonce
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    
                    // Update counters
                    totalImages = data.total || totalImages;
                    convertedImages = data.converted || convertedImages;
                    failedImages = data.failed || failedImages;
                    
                    // Update current image info
                    if (data.current_image) {
                        currentImageInfo = data.current_image;
                        $('#current-image-info').text(currentImageInfo);
                    }
                    
                    // Add new logs and update the log display in real-time
                    if (data.logs && data.logs.length > 0) {
                        // Only add logs that aren't already in our list
                        const newLogs = data.logs.slice(conversionLogs.length);
                        if (newLogs.length > 0) {
                            conversionLogs = conversionLogs.concat(newLogs);
                            
                            // Update the log display in real-time
                            let logHtml = '<ul>';
                            for (let i = 0; i < conversionLogs.length; i++) {
                                const log = conversionLogs[i];
                                const logClass = log.type === 'error' ? 'color: #d63638;' : 
                                                (log.type === 'success' ? 'color: #00a32a;' : 'color: #2271b1;');
                                logHtml += '<li style="' + logClass + '">' + log.message + '</li>';
                            }
                            logHtml += '</ul>';
                            
                            // Show the logs section during conversion
                            $('#conversion-results').show();
                            $('#conversion-log').html(logHtml);
                            
                            // Scroll to the bottom of the log
                            $('#conversion-log').scrollTop($('#conversion-log')[0].scrollHeight);
                        }
                    }
                    
                    // Calculate progress percentage
                    let percentage = 0;
                    if (totalImages > 0) {
                        percentage = Math.min(Math.round(((convertedImages + failedImages) / totalImages) * 100), 99);
                    }
                    
                    // Update UI
                    updateProgressUI(percentage);
                    $('#conversion-stats').text(
                        'Scanning: ' + (data.scanning_count || 0) + 
                        ' / Converting: ' + convertedImages + 
                        ' / Failed: ' + failedImages + 
                        ' / Total: ' + totalImages
                    );
                    
                    // Check if conversion is complete
                    if (data.is_complete) {
                        finishConversion();
                    } else {
                        // Continue checking progress
                        setTimeout(updateConversionProgress, 1000);
                    }
                } else {
                    // Error getting status, but continue checking
                    setTimeout(updateConversionProgress, 2000);
                }
            },
            error: function() {
                // Network error, but continue checking
                setTimeout(updateConversionProgress, 3000);
            }
        });
    }
    
    /**
     * Finish the conversion process
     */
    function finishConversion() {
        isConverting = false;
        $('#start-conversion').prop('disabled', false);
        
        // Update progress UI to 100%
        updateProgressUI(100);
        
        // Show results
        $('#conversion-results').show();
        
        // Create summary
        let summaryHtml = '<p>Conversion complete. ' + convertedImages + ' images converted, ' + 
            failedImages + ' failed.</p>';
        if (totalImages > 0) {
            summaryHtml += '<ul>';
            summaryHtml += '<li><strong>Total AVIF images found:</strong> ' + totalImages + '</li>';
            summaryHtml += '<li><strong>Successfully converted:</strong> ' + convertedImages + '</li>';
            summaryHtml += '<li><strong>Failed conversions:</strong> ' + failedImages + '</li>';
            summaryHtml += '</ul>';
        }
        $('#conversion-summary').html(summaryHtml);
        
        // Create log
        if (conversionLogs.length > 0) {
            let logHtml = '<ul>';
            for (let i = 0; i < conversionLogs.length; i++) {
                const log = conversionLogs[i];
                const logClass = log.type === 'error' ? 'color: #d63638;' : 
                                (log.type === 'success' ? 'color: #00a32a;' : 'color: #2271b1;');
                logHtml += '<li style="' + logClass + '">' + log.message + '</li>';
            }
            logHtml += '</ul>';
            $('#conversion-log').html(logHtml);
        } else {
            $('#conversion-log').html('<p>No logs available.</p>');
        }
    }

    /**
     * Handle conversion error
     * 
     * @param {Object} data Error data
     */
    function handleError(data) {
        $('#conversion-results').show();
        $('#conversion-summary').html('<p class="notice notice-error" style="padding: 10px;">' + 
            (data.message || 'An unknown error occurred.') + '</p>');
        $('#conversion-log').html('');
    }

    /**
     * Update the progress UI
     * 
     * @param {number} percentage Progress percentage
     */
    function updateProgressUI(percentage) {
        $('#progress-bar').css('width', percentage + '%');
        $('#progress-text').text(percentage + '% complete');
        
        if (totalImages > 0) {
            $('#conversion-stats').text(
                'Converted: ' + convertedImages + ' / Failed: ' + failedImages + ' / Total: ' + totalImages
            );
        }
    }

    // Initialize on document ready
    $(document).ready(function() {
        initConverter();
    });

})(jQuery);
