jQuery(document).ready(function($) {
    
    // Modern Top-Right Toast Notifications
    function showToast(message, type) {
        if ($('#webclyde-toast-container').length === 0) {
            $('body').append('<div id="webclyde-toast-container"></div>');
        }
        
        var icon = 'ℹ️';
        if (type === 'success') icon = '✅';
        if (type === 'error') icon = '❌';
        
        var toast = $('<div class="webclyde-toast ' + type + '"><span class="webclyde-toast-icon">' + icon + '</span><div>' + message + '</div></div>');
        $('#webclyde-toast-container').append(toast);
        
        // Slide out and remove toast after 4 seconds
        setTimeout(function() {
            toast.css('animation', 'webclyde-slide-out 0.3s ease-in forwards');
            setTimeout(function() {
                toast.remove();
            }, 300);
        }, 4000);
    }

    // Save settings via AJAX
    $('#webclyde-settings-form').on('submit', function(e) {
        e.preventDefault();
        var btn = $(this).find('button[type="submit"]');
        var originalHtml = btn.html();
        
        btn.html('<span class="dashicons dashicons-update spin" style="margin-top:3px;"></span> Saving...').prop('disabled', true);
        
        $.post(webclydeContentVault.ajaxurl, {
            action: 'webclyde_save_settings',
            nonce: webclydeContentVault.nonce,
            data: $(this).serialize()
        }, function(response) {
            btn.html(originalHtml).prop('disabled', false);
            if (response.success) {
                showToast('Settings saved successfully!', 'success');
            } else {
                showToast(response.data || 'Error saving settings', 'error');
            }
        }).fail(function() {
            btn.html(originalHtml).prop('disabled', false);
            showToast('Request failed. Please try again.', 'error');
        });
    });
    
    // Test API Connection
    $('#webclyde-test-connection').on('click', function() {
        var btn = $(this);
        var originalHtml = btn.html();
        btn.html('<span class="dashicons dashicons-update spin" style="margin-top:3px;"></span> Testing...').prop('disabled', true);
        
        $.post(webclydeContentVault.ajaxurl, {
            action: 'webclyde_test_connection',
            nonce: webclydeContentVault.nonce
        }, function(response) {
            btn.html(originalHtml).prop('disabled', false);
            if (response.success) {
                showToast('API connection successful!', 'success');
            } else {
                showToast(response.data || 'Connection failed', 'error');
            }
        }).fail(function() {
            btn.html(originalHtml).prop('disabled', false);
            showToast('API request failed.', 'error');
        });
    });
    
    // Check Status of a Job
    $(document).on('click', '.webclyde-check-status', function() {
        var btn = $(this);
        var jobId = btn.data('job-id');
        var originalHtml = btn.html();
        btn.html('<span class="dashicons dashicons-update spin" style="margin-top:3px;"></span> Check').prop('disabled', true);
        
        $.post(webclydeContentVault.ajaxurl, {
            action: 'webclyde_check_status',
            nonce: webclydeContentVault.nonce,
            job_id: jobId
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                btn.html(originalHtml).prop('disabled', false);
                showToast(response.data || 'Error checking status', 'error');
            }
        }).fail(function() {
            btn.html(originalHtml).prop('disabled', false);
            showToast('Request failed.', 'error');
        });
    });
    
    // Check Link Health
    $(document).on('click', '.webclyde-check-health', function() {
        var btn = $(this);
        var logId = btn.data('log-id');
        var originalHtml = btn.html();
        btn.html('<span class="dashicons dashicons-update spin" style="margin-top:3px;"></span> Health').prop('disabled', true);
        
        $.post(webclydeContentVault.ajaxurl, {
            action: 'webclyde_check_health',
            nonce: webclydeContentVault.nonce,
            log_id: logId
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                btn.html(originalHtml).prop('disabled', false);
                showToast(response.data || 'Error checking health', 'error');
            }
        }).fail(function() {
            btn.html(originalHtml).prop('disabled', false);
            showToast('Request failed.', 'error');
        });
    });
    
    // Retry Archiving for a failed log
    $(document).on('click', '.webclyde-retry', function() {
        var btn = $(this);
        var logId = btn.data('log-id');
        btn.prop('disabled', true);
        
        $.post(webclydeContentVault.ajaxurl, {
            action: 'webclyde_retry_archive',
            nonce: webclydeContentVault.nonce,
            log_id: logId
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                btn.prop('disabled', false);
                showToast(response.data || 'Error retrying archive', 'error');
            }
        }).fail(function() {
            btn.prop('disabled', false);
            showToast('Request failed.', 'error');
        });
    });
    
    // Delete Log
    $(document).on('click', '.webclyde-delete', function() {
        if (!confirm(webclydeContentVault.strings.confirm_delete)) return;
        
        var btn = $(this);
        var logId = btn.data('log-id');
        
        $.post(webclydeContentVault.ajaxurl, {
            action: 'webclyde_delete_log',
            nonce: webclydeContentVault.nonce,
            log_id: logId
        }, function(response) {
            if (response.success) {
                btn.closest('tr').fadeOut(function() { $(this).remove(); });
                showToast('Log deleted successfully', 'success');
            } else {
                showToast(response.data || 'Error deleting log', 'error');
            }
        });
    });
    
    // Bulk Delete Logs
    $('#webclyde-bulk-delete').on('click', function() {
        var ids = [];
        $('.webclyde-log-checkbox:checked').each(function() {
            ids.push($(this).val());
        });
        
        if (ids.length === 0) {
            showToast('Please select logs to delete', 'error');
            return;
        }
        
        if (!confirm(webclydeContentVault.strings.confirm_bulk_delete)) return;
        
        $.post(webclydeContentVault.ajaxurl, {
            action: 'webclyde_bulk_delete',
            nonce: webclydeContentVault.nonce,
            ids: ids
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                showToast(response.data || 'Error deleting logs', 'error');
            }
        });
    });
    
    // Select all logs in list table
    $('#webclyde-select-all').on('change', function() {
        $('.webclyde-log-checkbox').prop('checked', $(this).is(':checked'));
    });
    
    // Select all post types in settings
    $(document).on('change', '#webclyde-select-all-post-types', function() {
        $('.webclyde-post-type-checkbox').prop('checked', $(this).is(':checked'));
    });

    // Unified Click Handler for "Archive Now / Archive Again" button
    $(document).on('click', '#webclyde-archive-now-btn, .webclyde-archive-now-inline, #webclyde-gutenberg-archive-btn, #webclyde-classic-archive-btn', function(e) {
        e.preventDefault();
        var btn = $(this);
        var postId = btn.data('post-id');
        var originalHtml = btn.html();
        
        btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin" style="font-size: 16px; width: 16px; height: 16px;"></span> Archiving...');
        
        $.post(webclydeContentVault.ajaxurl, {
            action: 'webclyde_archive_now',
            nonce: webclydeContentVault.nonce,
            post_id: postId
        }, function(response) {
            if (response.success) {
                btn.html('✓ Success!');
                showToast('Archive job created successfully! Checking status...', 'success');
                setTimeout(function() {
                    location.reload();
                }, 1500);
            } else {
                btn.prop('disabled', false).html(originalHtml);
                showToast(response.data || 'Failed to trigger archive.', 'error');
            }
        }).fail(function() {
            btn.prop('disabled', false).html(originalHtml);
            showToast('Request failed. Please check connection.', 'error');
        });
    });

    // Bulk Archive All Published Content
    $('#webclyde-bulk-archive-all').on('click', function() {
        var btn = $(this);
        var result = $('#webclyde-bulk-archive-result');
        var originalHtml = btn.html();

        if (!confirm('This will queue ALL published posts for archiving to the Wayback Machine. Continue?')) {
            return;
        }

        btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin" style="margin-top:3px;"></span> Queuing...');
        result.hide();

        $.post(webclydeContentVault.ajaxurl, {
            action: 'webclyde_bulk_archive_all',
            nonce: webclydeContentVault.nonce
        }, function(response) {
            btn.prop('disabled', false).html(originalHtml);
            if (response.success) {
                result.text(response.data.message).css('color', '#10b981').show();
                showToast(response.data.message, 'success');
            } else {
                result.text(response.data || 'Error queuing archives.').css('color', '#ef4444').show();
                showToast(response.data || 'Error queuing archives.', 'error');
            }
        }).fail(function() {
            btn.prop('disabled', false).html(originalHtml);
            showToast('Request failed. Please try again.', 'error');
        });
    });

    // Gutenberg Editor Header Button Injection
    function injectGutenbergHeaderButton() {
        var editorEl = $('#editor');
        if (editorEl.length === 0) return;
        
        // Prevent duplicate injection
        if ($('#webclyde-gutenberg-archive-btn').length > 0) return;
        
        // Locate the top-right header toolbar in Gutenberg
        var settingsToolbar = $('.edit-post-header__settings');
        if (settingsToolbar.length > 0) {
            // Get current Post ID securely
            var postId = $('#post_ID').val();
            if (!postId && wp.data && wp.data.select('core/editor')) {
                postId = wp.data.select('core/editor').getCurrentPostId();
            }
            if (!postId) return;
            
            var btnHtml = '<button type="button" id="webclyde-gutenberg-archive-btn" class="components-button is-secondary" data-post-id="' + postId + '" style="margin-right: 12px; border: 1.5px solid #667eea; color: #667eea; background: transparent; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; border-radius: 4px; height: 32px; padding: 0 12px; cursor: pointer;">';
            btnHtml += '<span class="dashicons dashicons-backup" style="font-size: 16px; width: 16px; height: 16px; margin-top: -2px;"></span> Archive Now';
            btnHtml += '</button>';
            
            // Insert button directly in the editor header toolbar
            settingsToolbar.prepend(btnHtml);
        }
    }

    // Classic Editor Publish Box Button Injection
    function injectClassicEditorButton() {
        if ($('#major-publishing-actions').length === 0) return;
        if ($('#webclyde-classic-archive-btn').length > 0) return;
        
        var postId = $('#post_ID').val();
        if (!postId) return;
        
        var btnHtml = '<div id="webclyde-classic-archive-btn-container" style="float:left; margin-top: 4px; margin-right: 8px;">';
        btnHtml += '<button type="button" id="webclyde-classic-archive-btn" class="button button-large" data-post-id="' + postId + '" style="border-color: #667eea; color: #667eea; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">';
        btnHtml += '<span class="dashicons dashicons-backup" style="font-size: 16px; width: 16px; height: 16px; margin-top: 2px;"></span> Archive Now';
        btnHtml += '</button>';
        btnHtml += '</div>';
        
        $('#major-publishing-actions').prepend(btnHtml);
    }

    // Run observer loops to support real-time header rendering in both Classic and Block (Gutenberg) Editors
    setInterval(function() {
        injectGutenbergHeaderButton();
        injectClassicEditorButton();
    }, 1000);

});
