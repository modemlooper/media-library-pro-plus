jQuery(document).ready(function($) {
    var postsProcessed = 0;
    var imagesSet = 0;
    var noImages = 0;
    var totalPosts = 0;
    var isProcessing = false;
    
    // Start processing when button is clicked
    $('#mlpp-start-attachment').on('click', function() {
        var postType = $('#mlpp-post-type-select').val();
        
        if (!postType) {
            alert('Please select a post type.');
            return;
        }
        
        // Reset counters
        postsProcessed = 0;
        imagesSet = 0;
        noImages = 0;
        
        // Show progress container
        $('#mlpp-progress-container').show();
        $('#mlpp-results').hide();
        $('#mlpp-progress').css('width', '0%');
        $('#mlpp-status').text('Processing...');
        
        // Start processing
        processNextBatch(postType, 0);
    });
    
    // Process posts in batches
    function processNextBatch(postType, offset) {
        if (isProcessing) {
            return;
        }
        
        isProcessing = true;
        
        $.ajax({
            url: mlppFeaturedImage.ajax_url,
            type: 'POST',
            data: {
                action: 'mlpp_process_featured_images',
                nonce: mlppFeaturedImage.nonce,
                post_type: postType,
                offset: offset
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    
                    // Update counters
                    postsProcessed += data.processed;
                    imagesSet += data.images_set;
                    noImages += data.no_images;
                    totalPosts = data.total_posts;
                    
                    // Update progress
                    var progress = Math.min(100, Math.round((postsProcessed / totalPosts) * 100));
                    $('#mlpp-progress').css('width', progress + '%');
                    $('#mlpp-status').text('Processed ' + postsProcessed + ' of ' + totalPosts + ' posts (' + progress + '%)');
                    
                    // Update results
                    $('#mlpp-posts-processed').text(postsProcessed);
                    $('#mlpp-images-set').text(imagesSet);
                    $('#mlpp-no-images').text(noImages);
                    
                    // If not complete, process next batch
                    if (!data.complete) {
                        isProcessing = false;
                        processNextBatch(postType, data.offset);
                    } else {
                        // Complete
                        $('#mlpp-status').text('Completed! Processed ' + postsProcessed + ' posts.');
                        $('#mlpp-results').show();
                        isProcessing = false;
                    }
                } else {
                    $('#mlpp-status').text('Error: ' + (response.data.message || 'Unknown error'));
                    isProcessing = false;
                }
            },
            error: function() {
                $('#mlpp-status').text('Error: Could not connect to server.');
                isProcessing = false;
            }
        });
    }
});
