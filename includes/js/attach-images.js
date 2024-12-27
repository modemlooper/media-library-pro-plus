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
    const MAX_RETRIES = 3;
    const RETRY_DELAY = 5000; // 5 seconds

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
        
        // Set a longer timeout for the AJAX request
        $.ajax({
            url: mlppAttachImages.ajaxurl,
            type: 'POST',
            data: {
                action: 'mlpp_process_posts',
                nonce: mlppAttachImages.nonce,
                post_type: postType,
                offset: offset
            },
            timeout: 120000, // 2 minute timeout
            success: function(response) {
                if (response.success && !isCancelled) {
                    processedPosts += response.data.processed;
                    attachedImages += response.data.attached;
                    
                    // Update progress
                    const progress = Math.min((processedPosts / (totalPosts - startOffset)) * 100, 100);
                    $progress.css('width', progress + '%');
                    $progressText.text(`Processing ${processedPosts + startOffset} of ${totalPosts} posts (${attachedImages} images attached)`);
                    
                    // Add posts to the list
                    if (response.data.updated_posts && response.data.updated_posts.length > 0) {
                        response.data.updated_posts.forEach(function(post) {
                            $postList.append(
                                `<li>
                                    <a href="${post.edit_url}" target="_blank">${post.title}</a>
                                    (${post.images_attached} image${post.images_attached > 1 ? 's' : ''} attached)
                                </li>`
                            );
                        });
                    }
                    
                    if (!response.data.done) {
                        // Process next batch with a small delay to prevent overwhelming the server
                        setTimeout(function() {
                            processPosts(postType, offset + response.data.processed);
                        }, 1000);
                    } else {
                        // Complete
                        isProcessing = false;
                        $cancelButton.hide();
                        $startButton.show();
                        $select.prop('disabled', false);
                        $offset.prop('disabled', false);
                        $progressText.text(`Complete! Processed ${processedPosts} posts (${attachedImages} images attached)`);
                        
                        $results.html(
                            `<p>Started from offset: ${startOffset}<br>` +
                            `Processed ${processedPosts} posts<br>` +
                            `Attached ${attachedImages} images</p>`
                        );
                    }
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX Error:', {
                    status: jqXHR.status,
                    statusText: jqXHR.statusText,
                    responseText: jqXHR.responseText,
                    errorThrown: errorThrown
                });

                // Implement retry logic
                if (retryCount < MAX_RETRIES) {
                    retryCount++;
                    $progressText.text(`Retry attempt ${retryCount} of ${MAX_RETRIES} after ${RETRY_DELAY/1000} seconds...`);
                    
                    setTimeout(function() {
                        processPosts(postType, offset, true);
                    }, RETRY_DELAY);
                } else {
                    handleError(jqXHR);
                }
            }
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
