jQuery(document).ready(function($) {
    // DOM elements
    const $select = $('#mlpp-post-type');
    const $startButton = $('#mlpp-start-process');
    const $progressContainer = $('#mlpp-progress-container');
    const $progress = $('.mlpp-progress');
    const $processedCount = $('.mlpp-processed');
    const $totalCount = $('.mlpp-total');
    const $message = $('.mlpp-message');

    // State variables
    let isProcessing = false;
    let posts = [];
    let currentIndex = 0;
    let totalPosts = 0;
    let processedPosts = 0;
    let attachedImages = 0;

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
        posts = [];
        
        // Reset UI
        $startButton.prop('disabled', true);
        $progress.css('width', '0%');
        $processedCount.text('0');
        $totalCount.text('0');
        $message.empty();
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
                $message.text(`Process completed! ${attachedImages} images attached across ${processedPosts} posts.`);
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
