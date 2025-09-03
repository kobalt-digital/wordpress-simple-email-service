jQuery(document).ready(function($) {
    // Handle log rotation button click
    $('#rotate-logs-btn').on('click', function() {
        var $button = $(this);
        var originalText = $button.text();

        // Disable button and show loading state
        $button.prop('disabled', true).text(sesAdmin.strings.rotating);

        // Make AJAX request
        $.ajax({
            url: sesAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ses_rotate_logs',
                nonce: sesAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Show success message
                    var notice = $('<div class="notice notice-success is-dismissible"><p>' + sesAdmin.strings.success + '</p></div>');
                    $('.wrap h1').after(notice);

                    // Reload page after a short delay to show updated log
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    // Show error message
                    var notice = $('<div class="notice notice-error is-dismissible"><p>' + sesAdmin.strings.error + '</p></div>');
                    $('.wrap h1').after(notice);
                }
            },
            error: function() {
                // Show error message
                var notice = $('<div class="notice notice-error is-dismissible"><p>' + sesAdmin.strings.error + '</p></div>');
                $('.wrap h1').after(notice);
            },
            complete: function() {
                // Re-enable button and restore original text
                $button.prop('disabled', false).text(originalText);
            }
        });
    });
});