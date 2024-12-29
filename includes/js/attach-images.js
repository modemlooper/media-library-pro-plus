jQuery(document).ready(function($) {
    const $select = $('#mlpp-post-type-select');
    const $offset = $('#mlpp-post-offset');
    const $startButton = $('#mlpp-start-attach');
    const $cancelButton = $('#mlpp-cancel-attach');
    const $progressWrapper = $('#mlpp-progress-wrapper');
    const $progress = $('#mlpp-progress');
    const $progressText = $('#mlpp-progress-text');
    const $results = $('#mlpp-results');
    const $postList = $('.mlpp-post-list');

    let isProcessing = false;
    let isCancelled = false;
    let totalPosts = 0;
    let processedPosts = 0;
    let attachedImages = 0;
    let startOffset = 0;
    let retryCount = 0;
    let currentImages = [];
    let currentImageIndex = 0;
    const MAX_RETRIES = 3;
    const RETRY_DELAY = 5000; // 5 seconds

    // Create preview dialog
    const $previewDialog = $('<div>', {
        id: 'mlpp-preview-dialog',
        class: 'mlpp-preview-dialog',
        css: {
            display: 'none',
            position: 'fixed',
            top: '50%',
            left: '50%',
            transform: 'translate(-50%, -50%)',
            backgroundColor: 'white',
            padding: '20px',
            borderRadius: '8px',
            boxShadow: '0 2px 10px rgba(0,0,0,0.1)',
            zIndex: 100000
        }
    }).appendTo('body');

    const $previewOverlay = $('<div>', {
        id: 'mlpp-preview-overlay',
        css: {
            display: 'none',
            position: 'fixed',
            top: 0,
            left: 0,
            right: 0,
            bottom: 0,
            backgroundColor: 'rgba(0,0,0,0.5)',
            zIndex: 99999
        }
    }).appendTo('body');

    const $previewImage = $('<img>', {
        id: 'mlpp-preview-image',
        css: {
            maxWidth: '500px',
            maxHeight: '400px',
            display: 'block',
            marginBottom: '15px'
        }
    }).appendTo($previewDialog);

    const $previewInfo = $('<div>', {
        id: 'mlpp-preview-info',
        css: { marginBottom: '15px' }
    }).appendTo($previewDialog);

    const $previewButtons = $('<div>', {
        css: { textAlign: 'right' }
    }).appendTo($previewDialog);

    const $skipButton = $('<button>', {
        text: 'Skip',
        class: 'button',
        css: { marginRight: '10px' }
    }).appendTo($previewButtons);

    const $attachButton = $('<button>', {
        text: 'Attach',
        class: 'button button-primary'
    }).appendTo($previewButtons);

    function showPreviewDialog(imageData) {
        $previewImage.attr('src', imageData.url);
        $previewInfo.html(
            `<strong>Post:</strong> ${imageData.post_title}<br>` +
            `<strong>Image URL:</strong> ${imageData.url}`
        );
        $previewDialog.show();
        $previewOverlay.show();
    }

    function hidePreviewDialog() {
        $previewDialog.hide();
        $previewOverlay.hide();
    }

    function processNextImage() {
        if (currentImageIndex >= currentImages.length) {
            // All images in current batch processed, continue with next post
            processPosts($select.val(), startOffset + processedPosts);
            return;
        }

        showPreviewDialog(currentImages[currentImageIndex]);
    }

    $skipButton.on('click', function() {
        currentImageIndex++;
        hidePreviewDialog();
        processNextImage();
    });

    $attachButton.on('click', function() {
        const imageData = currentImages[currentImageIndex];
        
        // Make AJAX call to attach the image
        $.post(mlppAttachImages.ajaxurl, {
            action: 'mlpp_attach_single_image',
            nonce: mlppAttachImages.nonce,
            image_url: imageData.url,
            post_id: imageData.post_id
        })
        .done(function(response) {
            if (response.success) {
                attachedImages++;
                $results.prepend(
                    `<p class="success">✓ Attached image: ${imageData.url} to post: ${imageData.post_title}</p>`
                );
            } else {
                $results.prepend(
                    `<p class="error">✗ Failed to attach image: ${imageData.url} to post: ${imageData.post_title}</p>`
                );
            }
        })
        .fail(function(jqXHR) {
            handleError(jqXHR);
        })
        .always(function() {
            currentImageIndex++;
            hidePreviewDialog();
            processNextImage();
        });
    });

    $startButton.on('click', function() {
        const postType = $select.val();
        startOffset = parseInt($offset.val()) || 0;
        
        if (!postType) {
            alert('Please select a post type');
            return;
        }

        if (isProcessing) {
            return;
        }

        isProcessing = true;
        isCancelled = false;
        retryCount = 0;
        $startButton.hide();
        $cancelButton.show();
        $select.prop('disabled', true);
        $offset.prop('disabled', true);
        $progressWrapper.show();
        $results.empty();
        $postList.empty();

        // Reset counters
        processedPosts = 0;
        attachedImages = 0;

        // Get total posts count
        $.post(mlppAttachImages.ajaxurl, {
            action: 'mlpp_get_posts_count',
            nonce: mlppAttachImages.nonce,
            post_type: postType
        })
        .done(function(response) {
            if (response.success) {
                totalPosts = response.data.count;
                processPosts(postType, startOffset);
            }
        })
        .fail(function(jqXHR, textStatus, errorThrown) {
            console.error('AJAX Error:', {
                status: jqXHR.status,
                statusText: jqXHR.statusText,
                responseText: jqXHR.responseText,
                errorThrown: errorThrown
            });
            handleError(jqXHR);
        });
    });

    $cancelButton.on('click', function() {
        isCancelled = true;
        isProcessing = false;
        $cancelButton.hide();
        $startButton.show();
        $select.prop('disabled', false);
        $offset.prop('disabled', false);
        $progressText.text(`Cancelled at ${processedPosts + startOffset} of ${totalPosts} posts`);
        
        $results.html(
            `<p>Process cancelled<br>` +
            `Processed ${processedPosts} of ${totalPosts} posts<br>` +
            `Started from offset: ${startOffset}<br>` +
            `Attached ${attachedImages} images</p>`
        );
    });

    function processPosts(postType, offset, isRetry = false) {
        if (isCancelled) {
            return;
        }

        if (!isRetry) {
            retryCount = 0;
        }

        $progressText.text(`Processing ${processedPosts + startOffset} of ${totalPosts} posts...`);
        
        $.post(mlppAttachImages.ajaxurl, {
            action: 'mlpp_process_posts',
            nonce: mlppAttachImages.nonce,
            post_type: postType,
            offset: offset
        })
        .done(function(response) {
            if (response.success) {
                if (response.data.images) {
                    // New batch of images to process
                    currentImages = response.data.images;
                    currentImageIndex = 0;
                    processNextImage();
                } else if (response.data.done) {
                    isProcessing = false;
                    $startButton.show();
                    $cancelButton.hide();
                    $select.prop('disabled', false);
                    $offset.prop('disabled', false);
                    $progressText.text('Process completed');
                    
                    $results.prepend(
                        `<p class="success">✓ Process completed<br>` +
                        `Processed ${processedPosts} posts<br>` +
                        `Started from offset: ${startOffset}<br>` +
                        `Attached ${attachedImages} images</p>`
                    );
                } else {
                    processedPosts++;
                    const progress = ((processedPosts + startOffset) / totalPosts) * 100;
                    $progress.css('width', progress + '%');
                    
                    // Continue with next post
                    processPosts(postType, offset + 1);
                }
            }
        })
        .fail(function(jqXHR) {
            handleError(jqXHR);
        });
    }

    function handleError(error) {
        isProcessing = false;
        isCancelled = false;
        $cancelButton.hide();
        $startButton.show();
        $select.prop('disabled', false);
        $offset.prop('disabled', false);
        $progressWrapper.hide();

        let errorMessage = 'Failed to process posts';
        
        if (error.responseJSON && error.responseJSON.data) {
            const data = error.responseJSON.data;
            errorMessage = data.message || errorMessage;
            
            if (data.error_details) {
                console.error('Error Details:', {
                    message: data.message,
                    file: data.error_details.file,
                    line: data.error_details.line,
                    trace: data.error_details.trace
                });
            }
        }
        
        // Show a resume option if we've processed some posts
        if (processedPosts > 0) {
            $results.html(
                `<div class="error-message">
                    <p style="color: red;">${errorMessage}</p>
                    ${error.status ? `<p>Status Code: ${error.status}</p>` : ''}
                    ${error.statusText ? `<p>Status: ${error.statusText}</p>` : ''}
                    <p>Processed ${processedPosts} posts before error occurred.</p>
                    <p>You can resume from where it stopped by setting the offset to ${processedPosts + startOffset} and clicking Start again.</p>
                </div>`
            );
        } else {
            $results.html(
                `<div class="error-message">
                    <p style="color: red;">${errorMessage}</p>
                    ${error.status ? `<p>Status Code: ${error.status}</p>` : ''}
                    ${error.statusText ? `<p>Status: ${error.statusText}</p>` : ''}
                </div>`
            );
        }
    }
});
