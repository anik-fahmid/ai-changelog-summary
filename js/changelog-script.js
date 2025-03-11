jQuery(document).ready(function($) {
    // Preview Changelog Button
    $('#preview-changelog').on('click', function() {
        $('#changelog-preview').html('Loading...');
        $('#ai-summary').hide();
        $.ajax({
            url: AIChangelogSummary.ajax_url,
            type: 'POST',
            action: 'preview_fetch_changelog',
                security: AIChangelogSummary.nonce
            },
            success: function(response) {
                if (response.success) {
                    var previewContainer = $('#changelog-preview');
                    previewContainer.empty(); // Clear previous results
                    // Check if results exist
                    if (response.data.results && response.data.results.length > 0) {
                        // Iterate through all results
                        response.data.results.forEach(function(result, index) {
                            // Create a section for each changelog
                            var resultHtml = '<div class="changelog-result">';
                            // Add URL
                            resultHtml += '<h2>Changelog #' + (index + 1) + ': ' +
                                          (result.url ? result.url : 'Unknown URL') + '</h2>';
                            // Check if fetch was successful
                            if (result.success) {
                                resultHtml += '<p>Changelog successfully fetched</p>';
                                resultHtml += result.ai_summary || 'No summary available';
                            } else {
                                // Handle error case
                                resultHtml += '<p class="error">Failed to fetch: ' +
                                              (result.message || 'Unknown error') + '</p>';
                            }
                            resultHtml += '</div>';
                            previewContainer.append(resultHtml);
                        });
                    } else {
                        previewContainer.html('<p>No changelog results found.</p>');
                    }
                } else {
                    $('#changelog-preview').html('<p>Error: ' + response.data.message + '</p>');
                }
            },
            error: function() {
                $('#changelog-preview').html('<p>An error occurred while fetching changelogs.</p>');
            }
        });
    });

    // Send Test Email Button
    $('#send-test-email').on('click', function() {
        const button = $(this);
        const resultSpan = $('#test-email-result');
        // Disable button and show loading state
        button.prop('disabled', true);
        button.text('Sending...');
        resultSpan.html('');
        $.ajax({
            url: AIChangelogSummary.ajax_url,
            type: 'POST',
            action: 'send_test_changelog_email',
                security: AIChangelogSummary.nonce
            },
            success: function(response) {
                if (response.success) {
                    resultSpan.html(
                        '<span style="color: green;">' +
                        response.data.message +
                        '</span>'
                    );
                } else {
                    resultSpan.html(
                        '<span style="color: red;">Error: ' +
                        (response.data.message || 'Unknown error') +
                        '</span>'
                    );
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                resultSpan.html(
                    '<span style="color: red;">Ajax request failed: ' +
                    textStatus + '</span>'
                );
            },
            complete: function() {
                // Reset button state
                button.prop('disabled', false);
                button.text('Send Test Email');
            }
        });
    });

    // Test WordPress Email Button
    $('#test-wpmail').on('click', function() {
        const button = $(this);
        const resultSpan = $('#wpmail-test-result');
        button.prop('disabled', true);
        button.text('Testing...');
        resultSpan.html('');
        $.ajax({
            url: AIChangelogSummary.ajax_url,
            type: 'POST',
            data: {
                action: 'test_wp_mail',
                security: AIChangelogSummary.nonce
            },
            success: function(response) {
                if (response.success) {
                    resultSpan.html(
                        '<span style="color: green;">' +
                        response.data.message +
                        '</span>'
                    );
                } else {
                    resultSpan.html(
                        '<span style="color: red;">Error: ' +
                        (response.data.message || 'Unknown error') +
                        '</span>'
                    );
                }
            },
            error: function() {
                resultSpan.html(
                    '<span style="color: red;">Ajax request failed</span>'
                );
            },
            complete: function() {
                button.prop('disabled', false);
                button.text('Test WordPress Email');
            }
        });
    });

    // Helper function to display AI summary
    function displayAiSummary(summary, error) {
        const summaryContainer = $('#ai-summary');
        const summaryContent = $('.ai-summary-content');
        if (error) {
            summaryContent.html(`<div class="notice notice-error"><p>AI Summary Error: ${error}</p></div>`);
        } else if (summary) {
            summaryContent.html(summary);
        }
        summaryContainer.show();
    }
});
