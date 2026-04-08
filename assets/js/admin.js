(function($) {
    'use strict';

    // -----------------------------------------------------------------
    // Test Email (Settings page + Dashboard widget)
    // -----------------------------------------------------------------

    function sendTestEmail(toField, resultSpan, action) {
        var to = $(toField).val();
        if (!to) {
            $(resultSpan).html('<span class="fhsmtp-result-error">Enter an email address.</span>');
            return;
        }

        $(resultSpan).html('<span class="fhsmtp-spinner"></span> Sending...');

        $.post(fhsmtp.ajax_url, {
            action: action || 'fhsmtp_send_test',
            nonce: fhsmtp.nonce,
            to: to
        }, function(response) {
            if (response.success) {
                $(resultSpan).html('<span class="fhsmtp-result-success">' + response.data + '</span>');
            } else {
                $(resultSpan).html('<span class="fhsmtp-result-error">' + response.data + '</span>');
            }
        }).fail(function() {
            $(resultSpan).html('<span class="fhsmtp-result-error">Request failed.</span>');
        });
    }

    // Settings page test
    $(document).on('click', '#fhsmtp-send-test', function() {
        sendTestEmail('#fhsmtp_test_to', '#fhsmtp-test-result', 'fhsmtp_send_test');
    });

    // Backup test
    $(document).on('click', '#fhsmtp-test-backup', function() {
        sendTestEmail('#fhsmtp_backup_test_to', '#fhsmtp-backup-test-result', 'fhsmtp_test_backup');
    });

    // Dashboard widget test
    $(document).on('click', '#fhsmtp-dash-send-test', function() {
        sendTestEmail('#fhsmtp-dash-test-to', '#fhsmtp-dash-test-result', 'fhsmtp_dashboard_test');
    });

    // -----------------------------------------------------------------
    // SES Region auto-fill
    // -----------------------------------------------------------------

    $('#fhsmtp_region').on('change', function() {
        var region = $(this).val();
        if (region) {
            $('#fhsmtp_host').val('email-smtp.' + region + '.amazonaws.com');
            $('#fhsmtp_port').val('587');
        } else {
            $('#fhsmtp_host').val('');
        }
    });

    // -----------------------------------------------------------------
    // Email Log Detail Modal
    // -----------------------------------------------------------------

    $(document).on('click', '.fhsmtp-view-log', function(e) {
        e.preventDefault();
        var id = $(this).data('id');

        $('#fhsmtp-modal-body').html('<p>Loading...</p>');
        $('#fhsmtp-email-modal').show();

        $.post(fhsmtp.ajax_url, {
            action: 'fhsmtp_get_log_detail',
            nonce: fhsmtp.nonce,
            log_id: id
        }, function(response) {
            if (response.success) {
                var d = response.data;
                var html = '<table class="fhsmtp-detail-table">';
                html += '<tr><th>Date</th><td>' + d.created_at + '</td></tr>';
                html += '<tr><th>To</th><td>' + d.to_email + '</td></tr>';
                html += '<tr><th>From</th><td>' + d.from_email + '</td></tr>';
                html += '<tr><th>Subject</th><td>' + d.subject + '</td></tr>';
                html += '<tr><th>Status</th><td><span class="fhsmtp-status-' + d.status + '">' + d.status.charAt(0).toUpperCase() + d.status.slice(1) + '</span></td></tr>';
                html += '<tr><th>Connection</th><td>' + d.connection_type + '</td></tr>';
                if (d.error_message) {
                    html += '<tr><th>Error</th><td style="color:#dc3232">' + d.error_message + '</td></tr>';
                }
                if (d.headers) {
                    html += '<tr><th>Headers</th><td><pre>' + d.headers + '</pre></td></tr>';
                }
                html += '<tr><th>Body</th><td><pre>' + d.message + '</pre></td></tr>';
                if (d.attachments) {
                    html += '<tr><th>Attachments</th><td>' + d.attachments + '</td></tr>';
                }
                html += '</table>';
                $('#fhsmtp-modal-body').html(html);
            } else {
                $('#fhsmtp-modal-body').html('<p>Failed to load log details.</p>');
            }
        });
    });

    // Close modal
    $(document).on('click', '.fhsmtp-modal-close, .fhsmtp-modal', function(e) {
        if (e.target === this) {
            $('#fhsmtp-email-modal').hide();
        }
    });
    $(document).on('keyup', function(e) {
        if (e.key === 'Escape') {
            $('#fhsmtp-email-modal').hide();
        }
    });

    // -----------------------------------------------------------------
    // Resend Failed Email
    // -----------------------------------------------------------------

    $(document).on('click', '.fhsmtp-resend-log', function(e) {
        e.preventDefault();
        var $link = $(this);
        var id = $link.data('id');

        if (!confirm('Resend this email?')) return;

        $link.text('Sending...');

        $.post(fhsmtp.ajax_url, {
            action: 'fhsmtp_resend_email',
            nonce: fhsmtp.nonce,
            log_id: id
        }, function(response) {
            if (response.success) {
                $link.text('Sent!').css('color', '#46b450');
            } else {
                $link.text('Failed').css('color', '#dc3232');
                alert(response.data);
            }
        });
    });

    // -----------------------------------------------------------------
    // CSV Export
    // -----------------------------------------------------------------

    $(document).on('click', '#fhsmtp-export-csv', function(e) {
        e.preventDefault();
        var params = new URLSearchParams(window.location.search);
        var url = fhsmtp.ajax_url + '?action=fhsmtp_export_csv&nonce=' + fhsmtp.nonce;

        if (params.get('filter_status')) url += '&filter_status=' + params.get('filter_status');
        if (params.get('s')) url += '&s=' + encodeURIComponent(params.get('s'));

        window.location.href = url;
    });

})(jQuery);
