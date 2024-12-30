jQuery(document).ready(function($) {
    let progressInterval;
    const $progressContainer = $('.progress-container');
    const $progressBar = $('.progress');
    const $progressText = $('.progress-text');
    const $messageContainer = $('.message-container');
    const $startButton = $('#start-backup');

    function updateProgress() {
        $.ajax({
            url: mediaBackup.ajax_url,
            type: 'POST',
            data: {
                action: 'get_backup_progress',
                nonce: mediaBackup.nonce
            },
            success: function(response) {
                const progress = Math.round(response.progress);
                $progressBar.css('width', progress + '%');
                $progressText.text(progress + '%');
            }
        });
    }

    $startButton.on('click', function() {
        $startButton.prop('disabled', true);
        $progressContainer.show();
        $messageContainer.empty();

        // Start the backup process
        $.ajax({
            url: mediaBackup.ajax_url,
            type: 'POST',
            data: {
                action: 'start_media_backup',
                nonce: mediaBackup.nonce
            },
            success: function(response) {
                clearInterval(progressInterval);
                $messageContainer.html('<div class="notice notice-success"><p>' + response.message + '</p></div>');
                $startButton.prop('disabled', false);
            },
            error: function() {
                clearInterval(progressInterval);
                $messageContainer.html('<div class="notice notice-error"><p>An error occurred during the backup process.</p></div>');
                $startButton.prop('disabled', false);
            }
        });

        // Start progress updates
        progressInterval = setInterval(updateProgress, 1000);
    });
});
