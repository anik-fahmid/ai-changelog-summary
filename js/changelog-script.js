jQuery(document).ready(function ($) {
    'use strict';

    // Guard — AICS may not be localized on every page.
    if (typeof AICS === 'undefined') {
        return;
    }

    /* ───────────── Provider key toggle ───────────── */

    $('#aics-ai-provider').on('change', function () {
        var provider = $(this).val();
        $('.aics-api-key-row').hide();
        $('.aics-api-key-row[data-provider="' + provider + '"]').show();
    });

    /* ───────────── Frequency → day visibility ───────────── */

    $('#aics-frequency').on('change', function () {
        var freq = $(this).val();
        if (freq === 'daily') {
            $('#aics-day').closest('tr').hide();
        } else {
            $('#aics-day').closest('tr').show();
        }
    });

    /* ───────────── Preview Changelog ───────────── */

    $('#preview-changelog').on('click', function () {
        var btn = $(this);
        var preview = $('#changelog-preview');

        btn.prop('disabled', true).text('Loading...');
        preview.html('<p>Fetching changelogs...</p>');

        $.ajax({
            url: AICS.ajax_url,
            type: 'POST',
            data: {
                action: 'preview_fetch_changelog',
                security: AICS.nonce
            },
            success: function (response) {
                preview.empty();

                if (response.success && response.data.results) {
                    response.data.results.forEach(function (result, i) {
                        var html = '<div class="changelog-result">';
                        html += '<h3>Changelog #' + (i + 1) + '</h3>';
                        html += '<p style="font-size:12px;color:#666;word-break:break-all;">' + (result.url || '') + '</p>';

                        if (result.success) {
                            var badge = result.changed
                                ? '<span class="status-badge status-updated">Updated</span>'
                                : '<span class="status-badge status-unchanged">No Changes</span>';
                            html += badge;
                            html += '<div class="ai-summary-content">' + (result.ai_summary || 'No summary available.') + '</div>';
                        } else {
                            html += '<span class="status-badge status-error">Error</span>';
                            html += '<p style="color:#b91c1c;">' + (result.message || 'Unknown error') + '</p>';
                        }

                        html += '</div>';
                        preview.append(html);
                    });
                } else {
                    preview.html('<p style="color:#b91c1c;">Error: ' + (response.data ? response.data.message : 'Unknown error') + '</p>');
                }
            },
            error: function () {
                preview.html('<p style="color:#b91c1c;">Request failed. Please try again.</p>');
            },
            complete: function () {
                btn.prop('disabled', false).text('Preview Changelog');
            }
        });
    });

    /* ───────────── Fetch & Email Now ───────────── */

    $('#force-fetch').on('click', function () {
        var btn = $(this);
        var result = $('#force-fetch-result');
        var ignoreDiff = $('#force-fetch-ignore-diff').is(':checked') ? '1' : '0';

        btn.prop('disabled', true).text('Fetching...');
        result.html('');

        $.ajax({
            url: AICS.ajax_url,
            type: 'POST',
            data: {
                action: 'aics_force_fetch',
                security: AICS.nonce,
                ignore_diff: ignoreDiff
            },
            success: function (response) {
                var color = response.success ? 'green' : 'red';
                var msg = response.data ? response.data.message : 'Unknown result';

                if (response.success && response.data) {
                    msg += ' (Changed: ' + response.data.changed +
                           ', Unchanged: ' + response.data.unchanged +
                           ', Errors: ' + response.data.errors + ')';
                }

                result.html('<span style="color:' + color + ';">' + msg + '</span>');
            },
            error: function () {
                result.html('<span style="color:red;">Request failed.</span>');
            },
            complete: function () {
                btn.prop('disabled', false).text('Fetch & Email Now');
            }
        });
    });

    /* ───────────── Send Test Changelog Email ───────────── */

    $('#send-test-email').on('click', function () {
        var btn = $(this);
        var result = $('#test-email-result');

        btn.prop('disabled', true).text('Sending...');
        result.html('');

        $.ajax({
            url: AICS.ajax_url,
            type: 'POST',
            data: {
                action: 'send_test_changelog_email',
                security: AICS.nonce
            },
            success: function (response) {
                var color = response.success ? 'green' : 'red';
                result.html('<span style="color:' + color + ';">' + (response.data ? response.data.message : 'Error') + '</span>');
            },
            error: function () {
                result.html('<span style="color:red;">Request failed.</span>');
            },
            complete: function () {
                btn.prop('disabled', false).text('Send Test Changelog Email');
            }
        });
    });

    /* ───────────── Test WordPress Email ───────────── */

    $('#test-wpmail').on('click', function () {
        var btn = $(this);
        var result = $('#wpmail-test-result');

        btn.prop('disabled', true).text('Testing...');
        result.html('');

        $.ajax({
            url: AICS.ajax_url,
            type: 'POST',
            data: {
                action: 'test_wp_mail',
                security: AICS.nonce
            },
            success: function (response) {
                var color = response.success ? 'green' : 'red';
                result.html('<span style="color:' + color + ';">' + (response.data ? response.data.message : 'Error') + '</span>');
            },
            error: function () {
                result.html('<span style="color:red;">Request failed.</span>');
            },
            complete: function () {
                btn.prop('disabled', false).text('Test WordPress Email');
            }
        });
    });

    /* ───────────── Dashboard Widget Refresh ───────────── */

    $(document).on('click', '#aics-widget-refresh', function () {
        var btn = $(this);
        var result = $('#aics-widget-result');

        btn.prop('disabled', true).text('Refreshing...');
        result.html('');

        $.ajax({
            url: AICS.ajax_url,
            type: 'POST',
            data: {
                action: 'aics_force_fetch',
                security: AICS.nonce,
                ignore_diff: '0'
            },
            success: function (response) {
                var color = response.success ? 'green' : 'red';
                var msg = response.data ? response.data.message : 'Error';
                result.html('<span style="color:' + color + ';">' + msg + '</span>');

                if (response.success) {
                    // Reload widget content after short delay.
                    setTimeout(function () {
                        location.reload();
                    }, 1500);
                }
            },
            error: function () {
                result.html('<span style="color:red;">Request failed.</span>');
            },
            complete: function () {
                btn.prop('disabled', false).text('Refresh Now');
            }
        });
    });
});
