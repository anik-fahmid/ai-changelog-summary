jQuery(document).ready(function($) {
    // Preview Changelog Button
    $('#preview-changelog').on('click', function() {
        $('#changelog-preview').html('Loading...');
        $('#ai-summary').hide();
        
        $.ajax({
            url: changelogChecker.ajax_url,
            type: 'GET',
            data: {
                action: 'preview_fetch_changelog',
                security: changelogChecker.nonce,
                url: $('#changelog-url').val()
            },
            success: function(response) {
                console.log('Full AJAX Response:', response);
                
                if (response.success) {
                    // Always show a success message
                    $('#changelog-preview').html(
                        '<div class="notice notice-success">' +
                        '<p>Changelog successfully fetched and processed!</p>' +
                        '</div>'
                    );
                    
                    // Handle AI summary
                    if (response.data.ai_summary) {
                        displayAiSummary(response.data.ai_summary);
                    } else {
                        displayAiSummary(null, 'No AI summary generated');
                    }
                } else {
                    // Handle error messages
                    var errorMessage = response.data && response.data.message 
                        ? response.data.message 
                        : 'Unknown error occurred';
                    
                    $('#changelog-preview').html(
                        '<div class="notice notice-error"><p>Error: ' + 
                        errorMessage + '</p></div>'
                    );
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                // Error handling
                const errorDetails = {
                    status: jqXHR.status,
                    statusText: jqXHR.statusText,
                    responseText: jqXHR.responseText,
                    textStatus: textStatus,
                    errorThrown: errorThrown
                };
                
                console.error('AJAX Error Details:', errorDetails);
                
                $('#changelog-preview').html(
                    '<div class="notice notice-error">' +
                    '<p>Ajax request failed: ' + textStatus + '</p>' +
                    '<p>Error: ' + errorThrown + '</p>' +
                    '<p>Status: ' + jqXHR.status + ' ' + jqXHR.statusText + '</p>' +
                    '</div>'
                );
                
                $('#ai-summary').hide();
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
            url: changelogChecker.ajax_url,
            type: 'POST',
            data: {
                action: 'send_test_changelog_email',
                security: changelogChecker.nonce
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
            url: changelogChecker.ajax_url,
            type: 'POST',
            data: {
                action: 'test_wp_mail',
                security: changelogChecker.nonce
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