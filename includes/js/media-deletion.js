jQuery(document).ready(function($) {
    let totalItems = 0;
    let processedItems = 0;
    let deletedItems = 0;
    let skippedItems = 0;
    let errorCount = 0;
    let isCancelled = false;

    // Initialize by getting total items
    $.ajax({
        url: mediaDeletion.ajaxurl,
        type: 'POST',
        data: {
            action: 'get_total_unattached_media',
            nonce: mediaDeletion.nonce
        },
        success: function(response) {
            if (response.success && response.data) {
                totalItems = response.data.total;
                initializeSkipInput();
                $('#start-deletion-btn').prop('disabled', false);
            } else {
                alert('Error getting total media items. Please refresh the page.');
            }
        },
        error: function() {
            alert('Error getting total media items. Please refresh the page.');
        }
    });

    function initializeSkipInput() {
        const $input = $('#skip-to-position');
        $input.attr('max', totalItems);
        $('#total-items-info').text(`of ${totalItems} total items`);

        $input.on('change', function() {
            const val = parseInt($(this).val());
            if (val < 1) {
                $(this).val(1);
            } else if (val > totalItems) {
                $(this).val(totalItems);
            }
        });
    }

    function updateProgress() {
        const percentage = totalItems > 0 ? (processedItems / totalItems) * 100 : 0;
        $('#progress-bar').css('width', percentage + '%');
        $('#processed-count').text(processedItems);
        $('#total-count').text(totalItems);
        $('#deleted-count').text(deletedItems);
        $('#skipped-count').text(skippedItems);
        $('#error-count').text(errorCount);
        
        // Update current position display
        $('#position-info').text(`Current Position: ${processedItems}`);
    }

    function resetUI() {
        $('#pre-start-options').show();
        $('#start-deletion-btn').prop('disabled', false);
        $('#cancel-deletion-btn').hide().prop('disabled', false).text('Cancel Deletion');
        $('#deletion-progress').hide();
        isCancelled = false;
        initializeSkipInput();
    }

    function processBatch(offset = 0) {
        if (isCancelled) {
            $('#completion-notice').hide();
            resetUI();
            return;
        }

        $.ajax({
            url: mediaDeletion.ajaxurl,
            type: 'POST',
            data: {
                action: 'delete_unattached_media',
                nonce: mediaDeletion.nonce,
                offset: offset
            },
            success: function(response) {
                if (response.success && response.data) {
                    const data = response.data;
                    
                    // Update counters
                    processedItems = data.current_position;
                    deletedItems += data.deleted;
                    skippedItems += data.skipped;
                    errorCount += data.errors.length;

                    // Update progress UI
                    updateProgress();

                    // Process next batch if not done and not cancelled
                    if (!data.done && !isCancelled) {
                        processBatch(processedItems);
                    } else {
                        if (!isCancelled) {
                            $('#completion-notice').show();
                        }
                        resetUI();
                    }
                } else {
                    alert('Error processing media items. Please try again.');
                    resetUI();
                }
            },
            error: function() {
                alert('Error processing media items. Please try again.');
                resetUI();
            }
        });
    }

    $('#start-deletion-btn').on('click', function() {
        const startPosition = parseInt($('#skip-to-position').val()) - 1;
        if (startPosition < 0 || startPosition >= totalItems) {
            alert('Please enter a valid position number');
            return;
        }

        if (!confirm('Are you sure you want to proceed? This action cannot be undone.')) {
            return;
        }

        // Reset counters
        processedItems = startPosition;
        deletedItems = 0;
        skippedItems = 0;
        errorCount = 0;
        isCancelled = false;

        // Update UI
        $('#pre-start-options').hide();
        $('#deletion-progress').show();
        $('#completion-notice').hide();
        $('#cancel-deletion-btn')
            .show()
            .prop('disabled', false)
            .text('Cancel Deletion');

        // Start processing from selected position
        processBatch(startPosition);
    });

    $('#cancel-deletion-btn').on('click', function() {
        if (confirm('Are you sure you want to cancel the deletion process?')) {
            isCancelled = true;
            $(this).prop('disabled', true).text('Cancelling...');
            $('#completion-notice').hide();
        }
    });
});
