jQuery(document).ready(function($) {
    // DOM elements
    const $select = $('#mlpp-post-type');
    const $startButton = $('#mlpp-start-process');
    const $progressContainer = $('#mlpp-progress-container');
    const $progress = $('.mlpp-progress');
    const $processedCount = $('.mlpp-processed');
    const $totalCount = $('.mlpp-total');
    const $message = $('.mlpp-message');
    const $resultsContainer = $('#mlpp-results-container');

    // State variables
    let isProcessing = false;
    let posts = [];
    let currentIndex = 0;
    let totalPosts = 0;
    let processedPosts = 0;
    let attachedImages = 0;
    let downloadedImages = 0;
    let contentUpdatedCount = 0;

    $startButton.on('click', function() {
        if (isProcessing) {
            return;
        }
        
        const postType = $select.val();
        if (!postType) {
            $message.text('Please select a post type');
            return;
        }

        startProcess(postType);
    });

    function startProcess(postType) {
        // Reset state
        isProcessing = true;
        currentIndex = 0;
        processedPosts = 0;
        attachedImages = 0;
        downloadedImages = 0;
        contentUpdatedCount = 0;
        posts = [];
        
        // Reset UI
        $startButton.prop('disabled', true);
        $progress.css('width', '0%');
        $processedCount.text('0');
        $totalCount.text('0');
        $message.empty();
        $resultsContainer.empty();
        $progressContainer.show();
        
        // Get posts
        $.ajax({
            url: mlppAttach.ajaxurl,
            type: 'POST',
            data: {
                action: 'mlpp_get_posts',
                nonce: mlppAttach.nonce,
                post_type: postType
            },
            success: function(response) {
                if (response.success) {
                    posts = response.data.posts;
                    totalPosts = posts.length;
                    $totalCount.text(totalPosts);
                    
                    if (totalPosts > 0) {
                        processNext();
                    } else {
                        $message.text('No posts found for the selected post type.');
                        resetProcess();
                    }
                } else {
                    handleError(response.data);
                }
            },
            error: function(xhr, status, error) {
                handleError('Error fetching posts: ' + error);
            }
        });
    }
    
    function processNext() {
        if (!isProcessing || currentIndex >= totalPosts) {
            if (currentIndex >= totalPosts) {
                $message.text(`Process completed! ${attachedImages} images attached (${downloadedImages} downloaded, ${attachedImages - downloadedImages} existing) across ${processedPosts} posts. Content updated in ${contentUpdatedCount} posts.`);
            }
            resetProcess();
            return;
        }
        
        const post = posts[currentIndex];
        $message.text(`Processing: ${post.title} (${currentIndex + 1}/${totalPosts})`);
        
        $.ajax({
            url: mlppAttach.ajaxurl,
            type: 'POST',
            data: {
                action: 'mlpp_process_post',
                nonce: mlppAttach.nonce,
                post_id: post.ID
            },
            success: function(response) {
                if (response.success) {
                    currentIndex++;
                    processedPosts++;
                    if (response.data.attached) {
                        attachedImages += response.data.attached;
                    }
                    if (response.data.downloaded) {
                        downloadedImages += response.data.downloaded;
                    }
                    if (response.data.content_updated) {
                        contentUpdatedCount++;
                    }
                    
                    // Display detailed results
                    const postResult = $('<div class="post-result"></div>');
                    postResult.append(`<h4>Post: ${response.data.post_title}</h4>`);
                    
                    if (response.data.processed_images && response.data.processed_images.length > 0) {
                        const imageList = $('<div class="image-list"></div>');
                        response.data.processed_images.forEach(img => {
                            const imageItem = $('<div class="image-item"></div>');
                            if (img.thumbnail) {
                                imageItem.append(`<img src="${img.thumbnail}" class="image-thumbnail" />`);
                            }
                            imageItem.append(`<div class="image-info">
                                <strong>${img.title || 'Untitled'}</strong><br>
                                Status: ${img.status.replace(/_/g, ' ')}<br>
                                ${img.url}
                                ${img.original_url ? `<br>Original URL: ${img.original_url}` : ''}
                            </div>`);
                            imageList.append(imageItem);
                        });
                        postResult.append(imageList);
                    } else {
                        postResult.append('<p>No images found in this post</p>');
                    }
                    
                    $resultsContainer.prepend(postResult);
                    updateProgress();
                    $message.text(`Processed: ${post.title} - ${response.data.message}`);
                    processNext();
                } else {
                    handleError('Error processing post: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                handleError('Error processing post: ' + error);
            }
        });
    }
    
    function updateProgress() {
        const progress = (currentIndex / totalPosts) * 100;
        $progress.css('width', progress + '%');
        $processedCount.text(currentIndex);
    }
    
    function handleError(message) {
        $message.text(message);
        resetProcess();
    }
    
    function resetProcess() {
        isProcessing = false;
        $startButton.prop('disabled', false);
    }
});
