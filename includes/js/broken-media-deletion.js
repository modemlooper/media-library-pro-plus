jQuery(document).ready(function($) {
    let totalItems = 0;
    let processedItems = 0;
    let deletedItems = 0;
    let skippedItems = 0;
    let errorCount = 0;
    let isCancelled = false;

    function updateProgress() {
        const percentage = totalItems > 0 ? (processedItems / totalItems) * 100 : 0;
        $('#progress-bar').css('width', percentage + '%');
        $('#processed-count').text(processedItems);
        $('#total-count').text(totalItems);
        $('#deleted-count').text(deletedItems);
        $('#skipped-count').text(skippedItems);
        $('#error-count').text(errorCount);
    }

    function resetUI() {
        $('#start-deletion-btn').prop('disabled', false);
        $('#cancel-deletion-btn').hide();
        $('#deletion-progress').hide();
        isCancelled = false;
    }

    function processBatch(offset = 0) {
        if (isCancelled) {
            resetUI();
            return;
        }

        $.ajax({
            url: brokenMediaDeletion.ajaxurl,
            type: 'POST',
            data: {
                action: 'delete_broken_media',
                nonce: brokenMediaDeletion.nonce,
                offset: offset
            },
            success: function(response) {
                if (response.success && response.data) {
                    const data = response.data;
                    
                    // Update totals if this is the first batch
                    if (offset === 0) {
                        totalItems = data.total;
                    }

                    // Update counters
                    processedItems += data.deleted + data.skipped;
                    deletedItems += data.deleted;
                    skippedItems += data.skipped;
                    errorCount += data.errors.length;

                    // Update progress UI
                    updateProgress();

                    // Process next batch if not done and not cancelled
                    if (!data.done && !isCancelled) {
                        processBatch(offset + 30);
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
        if (!confirm('Are you sure you want to proceed? This action cannot be undone.')) {
            return;
        }

        // Reset counters
        totalItems = 0;
        processedItems = 0;
        deletedItems = 0;
        skippedItems = 0;
        errorCount = 0;
        isCancelled = false;

        // Show progress UI
        $('#deletion-progress').show();
        $('#completion-notice').hide();
        $(this).prop('disabled', true);
        $('#cancel-deletion-btn').show();

        // Start processing
        processBatch();
    });

    $('#cancel-deletion-btn').on('click', function() {
        if (confirm('Are you sure you want to cancel the deletion process?')) {
            isCancelled = true;
            $(this).prop('disabled', true).text('Cancelling...');
        }
    });
});
