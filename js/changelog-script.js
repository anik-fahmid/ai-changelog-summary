jQuery(document).ready(function ($) {
    'use strict';

    // Guard — AICS may not be localized on every page.
    if (typeof AICS === 'undefined') {
        return;
    }

    /* ───────────── Tab Switching ───────────── */

    $('.aics-tab').on('click', function (e) {
        e.preventDefault();
        var tab = $(this).data('tab');
        $('.aics-tab').removeClass('active');
        $(this).addClass('active');
        $('.aics-tab-content').removeClass('active');
        $('#aics-tab-' + tab).addClass('active');
        window.location.hash = tab;
    });

    // Restore tab from URL hash on page load.
    var hash = window.location.hash.replace('#', '');
    if (hash && $('.aics-tab[data-tab="' + hash + '"]').length) {
        $('.aics-tab[data-tab="' + hash + '"]').trigger('click');
    }

    /* ───────────── Provider key toggle ───────────── */

    $('#aics-ai-provider').on('change', function () {
        var provider = $(this).val();
        $('.aics-api-key-row').hide();
        $('.aics-api-key-row[data-provider="' + provider + '"]').show();
    });

    /* ───────────── SMTP fields toggle ───────────── */

    function toggleSmtpFields() {
        var rows = $('#aics-smtp-enabled').closest('tr').nextAll('tr');
        if ($('#aics-smtp-enabled').is(':checked')) {
            rows.show();
        } else {
            rows.hide();
        }
    }

    $('#aics-smtp-enabled').on('change', toggleSmtpFields);
    toggleSmtpFields(); // Run on page load.

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

    function fetchPreview(skipCache) {
        var btn = skipCache ? $('#preview-fresh') : $('#preview-changelog');
        var otherBtn = skipCache ? $('#preview-changelog') : $('#preview-fresh');
        var preview = $('#changelog-preview');
        var originalText = btn.text();

        btn.prop('disabled', true).text('Loading...');
        otherBtn.prop('disabled', true);
        preview.html('<p>Fetching changelogs' + (skipCache ? ' (fresh)' : '') + '...</p>');

        var data = {
            action: 'preview_fetch_changelog',
            security: AICS.nonce
        };
        if (skipCache) {
            data.skip_cache = 1;
        }

        $.ajax({
            url: AICS.ajax_url,
            type: 'POST',
            data: data,
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
                btn.prop('disabled', false).text(originalText);
                otherBtn.prop('disabled', false);
            }
        });
    }

    $('#preview-changelog').on('click', function () { fetchPreview(false); });
    $('#preview-fresh').on('click', function () { fetchPreview(true); });

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

    /* ───────────── Test Email Delivery ───────────── */

    $('#test-wpmail').on('click', function () {
        var btn = $(this);
        var result = $('#wpmail-test-result');

        btn.prop('disabled', true).text('Sending...');
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
                btn.prop('disabled', false).text('Test Email Delivery');
            }
        });
    });

    /* ───────────── From Email vs SMTP username mismatch warning ───────────── */

    function checkFromSmtpMismatch() {
        var smtpEnabled = $('#aics-smtp-enabled').is(':checked');
        var fromEmail   = $('#aics_email_from_address').val().trim();
        var smtpUser    = $('#aics_smtp_username').val().trim();
        var notice      = $('#aics-from-smtp-notice');

        if (smtpEnabled && fromEmail && smtpUser && fromEmail !== smtpUser) {
            notice.show();
        } else {
            notice.hide();
        }
    }

    $('#aics_email_from_address, #aics_smtp_username').on('input', checkFromSmtpMismatch);
    $('#aics-smtp-enabled').on('change', checkFromSmtpMismatch);
    checkFromSmtpMismatch();

    /* ───────────── Password / API key visibility toggle ───────────── */

    $(document).on('click', '.aics-pw-toggle', function () {
        var input = $(this).closest('.aics-pw-wrap').find('input');
        var isPassword = input.attr('type') === 'password';
        input.attr('type', isPassword ? 'text' : 'password');
        $(this).find('.aics-eye-show').toggle(!isPassword);
        $(this).find('.aics-eye-hide').toggle(isPassword);
    });

    /* ───────────── Auto Detect Changelog URL ───────────── */

    var urlContainer = $('#changelog-urls-container');

    $('#aics-detect-url').on('click', function () {
        var btn    = $(this);
        var domain = $('#aics-detect-domain').val().trim();
        var result = $('#aics-detect-result');

        if (!domain) {
            result.html('<span style="color:#b91c1c;">Please enter a domain.</span>');
            return;
        }

        btn.prop('disabled', true).text('Detecting...');
        result.html('');

        $.ajax({
            url: AICS.ajax_url,
            type: 'POST',
            data: {
                action: 'aics_detect_changelog',
                security: AICS.nonce,
                domain: domain
            },
            success: function (response) {
                if (response.success && response.data.urls) {
                    // Fill empty fields first, then overwrite from the start.
                    var inputs = urlContainer.find('input[type="url"]');
                    var filled = 0;
                    // Try empty slots first.
                    inputs.each(function () {
                        if (filled >= response.data.urls.length) { return false; }
                        if ($(this).val() === '') {
                            $(this).val(response.data.urls[filled++]);
                        }
                    });
                    // Fill remaining detected URLs into slots from the top.
                    if (filled < response.data.urls.length) {
                        inputs.each(function (i) {
                            if (filled >= response.data.urls.length) { return false; }
                            $(this).val(response.data.urls[filled++]);
                        });
                    }
                    result.html('<span style="color:green;">' + filled + ' URL(s) filled in.</span>');
                    $('#aics-detect-domain').val('');
                } else {
                    result.html('<span style="color:#b91c1c;">' + (response.data ? response.data.message : 'No changelogs found.') + '</span>');
                }
            },
            error: function () {
                result.html('<span style="color:#b91c1c;">Request failed.</span>');
            },
            complete: function () {
                btn.prop('disabled', false).text('Auto Detect');
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
