<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WebClyde_Content_Vault_Admin {
    
    private $settings;
    private $logger;
    private $api;
    private $scheduler;
    
    public function __construct(
        WebClyde_Content_Vault_Settings $settings,
        WebClyde_Content_Vault_Logger $logger,
        WebClyde_Content_Vault_API $api,
        WebClyde_Content_Vault_Scheduler $scheduler
    ) {
        $this->settings = $settings;
        $this->logger = $logger;
        $this->api = $api;
        $this->scheduler = $scheduler;
        
        add_action('admin_menu', array($this, 'add_menu_pages'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_webclyde_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_webclyde_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_webclyde_check_status', array($this, 'ajax_check_status'));
        add_action('wp_ajax_webclyde_check_health', array($this, 'ajax_check_health'));
        add_action('wp_ajax_webclyde_retry_archive', array($this, 'ajax_retry_archive'));
        add_action('wp_ajax_webclyde_delete_log', array($this, 'ajax_delete_log'));
        add_action('wp_ajax_webclyde_bulk_delete', array($this, 'ajax_bulk_delete'));
    }
    
    public function add_menu_pages() {
        add_menu_page(
            __('Content Vault', 'content-vault'),
            __('Content Vault', 'content-vault'),
            'manage_options',
            'content-vault',
            array($this, 'render_dashboard_page'),
            'dashicons-backup',
            80
        );
        
        add_submenu_page(
            'content-vault',
            __('Dashboard', 'content-vault'),
            __('Dashboard', 'content-vault'),
            'manage_options',
            'content-vault',
            array($this, 'render_dashboard_page')
        );
        
        add_submenu_page(
            'content-vault',
            __('Settings', 'content-vault'),
            __('Settings', 'content-vault'),
            'manage_options',
            'content-vault-settings',
            array($this, 'render_settings_page')
        );
        
        add_submenu_page(
            'content-vault',
            __('All Logs', 'content-vault'),
            __('All Logs', 'content-vault'),
            'manage_options',
            'content-vault-logs',
            array($this, 'render_logs_page')
        );
        
        add_submenu_page(
            'content-vault',
            __('Post Logs', 'content-vault'),
            __('Post Logs', 'content-vault'),
            'manage_options',
            'content-vault-posts',
            array($this, 'render_post_logs_page')
        );
        
        add_submenu_page(
            'content-vault',
            __('Page Logs', 'content-vault'),
            __('Page Logs', 'content-vault'),
            'manage_options',
            'content-vault-pages',
            array($this, 'render_page_logs_page')
        );
    }
    
    public function enqueue_assets($hook) {
        if (strpos($hook, 'content-vault') === false) {
            return;
        }
        
        wp_enqueue_style(
            'content-vault-admin',
            WEBCLYDE_CONTENT_VAULT_PLUGIN_URL . 'admin-style.css',
            array(),
            WEBCLYDE_CONTENT_VAULT_VERSION
        );
        
        wp_enqueue_script(
            'content-vault-admin',
            WEBCLYDE_CONTENT_VAULT_PLUGIN_URL . 'admin-script.js',
            array('jquery'),
            WEBCLYDE_CONTENT_VAULT_VERSION,
            true
        );
        
        wp_localize_script('content-vault-admin', 'webclydeContentVault', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('webclyde_content_vault_nonce'),
            'strings' => array(
                'confirm_delete' => __('Are you sure you want to delete this log?', 'content-vault'),
                'confirm_bulk_delete' => __('Are you sure you want to delete selected logs?', 'content-vault'),
                'saving' => __('Saving...', 'content-vault'),
                'testing' => __('Testing...', 'content-vault'),
                'checking' => __('Checking...', 'content-vault')
            )
        ));
        
        $this->inline_styles();
        $this->inline_scripts();
    }
    
    private function inline_styles() {
        $css = "
        .webclyde-wrap {
            width: 100%;
            padding-right: 20px;
            box-sizing: border-box;
        }
        .webclyde-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            color: white;
        }
        .webclyde-header h1 {
            margin: 0 0 10px;
            font-size: 28px;
            font-weight: 600;
            color: #fff;
        }
        .webclyde-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 14px;
        }
        .webclyde-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .webclyde-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #667eea;
        }
        .webclyde-card.success { border-left-color: #10b981; }
        .webclyde-card.warning { border-left-color: #f59e0b; }
        .webclyde-card.error { border-left-color: #ef4444; }
        .webclyde-card.info { border-left-color: #3b82f6; }
        .webclyde-card h3 {
            margin: 0 0 5px;
            font-size: 14px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .webclyde-card .number {
            font-size: 36px;
            font-weight: 700;
            color: #1f2937;
        }
        .webclyde-box {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        .webclyde-box h2 {
            margin: 0 0 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
            font-size: 18px;
            color: #1f2937;
        }
        .webclyde-form-row {
            margin-bottom: 25px;
        }
        .webclyde-form-row label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #374151;
        }
        .webclyde-form-row input[type='text'],
        .webclyde-form-row input[type='password'],
        .webclyde-form-row input[type='number'] {
            width: 100%;
            max-width: 400px;
            padding: 12px 15px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        .webclyde-form-row input:focus {
            outline: none;
            border-color: #667eea;
        }
        .webclyde-form-row .description {
            margin-top: 8px;
            color: #6b7280;
            font-size: 13px;
        }
        .webclyde-checkbox-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        .webclyde-checkbox-row input[type='checkbox'] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        .webclyde-checkbox-row label {
            margin: 0;
            font-weight: 500;
            cursor: pointer;
        }
        .webclyde-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .webclyde-btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .webclyde-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        .webclyde-btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }
        .webclyde-btn-secondary:hover {
            background: #e5e7eb;
        }
        .webclyde-btn-danger {
            background: #ef4444;
            color: white;
        }
        .webclyde-btn-danger:hover {
            background: #dc2626;
        }
        .webclyde-btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }
        .webclyde-table {
            width: 100%;
            border-collapse: collapse;
        }
        .webclyde-table th,
        .webclyde-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #f3f4f6;
        }
        .webclyde-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .webclyde-table tr:hover {
            background: #f9fafb;
        }
        .webclyde-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .webclyde-status.pending {
            background: #fef3c7;
            color: #92400e;
        }
        .webclyde-status.processing {
            background: #dbeafe;
            color: #1e40af;
        }
        .webclyde-status.success {
            background: #d1fae5;
            color: #065f46;
        }
        .webclyde-status.error {
            background: #fee2e2;
            color: #991b1b;
        }
        .webclyde-health {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .webclyde-health.healthy {
            background: #d1fae5;
            color: #065f46;
        }
        .webclyde-health.unhealthy {
            background: #fee2e2;
            color: #991b1b;
        }
        .webclyde-health.unknown {
            background: #f3f4f6;
            color: #6b7280;
        }
        .webclyde-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .webclyde-badge.post {
            background: #dbeafe;
            color: #1e40af;
        }
        .webclyde-badge.page {
            background: #fae8ff;
            color: #86198f;
        }
        .webclyde-url {
            max-width: 250px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 13px;
        }
        .webclyde-url a {
            color: #667eea;
            text-decoration: none;
        }
        .webclyde-url a:hover {
            text-decoration: underline;
        }
        .webclyde-actions {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        .webclyde-pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #f3f4f6;
        }
        .webclyde-filters {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
        }
        .webclyde-filters select {
            padding: 10px 15px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            background: white;
        }
        .webclyde-notice {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .webclyde-notice.success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .webclyde-notice.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .webclyde-system-status {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        .webclyde-status-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 15px;
            background: #f9fafb;
            border-radius: 8px;
        }
        .webclyde-status-item .icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }
        .webclyde-status-item .icon.active {
            background: #d1fae5;
            color: #065f46;
        }
        .webclyde-status-item .icon.inactive {
            background: #fee2e2;
            color: #991b1b;
        }
        .webclyde-time {
            font-size: 12px;
            color: #6b7280;
        }
        .webclyde-error-msg {
            font-size: 11px;
            color: #991b1b;
            margin-top: 4px;
        }
        .webclyde-quick-links {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        .webclyde-quick-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 20px;
            background: #f9fafb;
            border-radius: 10px;
            text-decoration: none;
            color: #374151;
            transition: all 0.2s;
        }
        .webclyde-quick-link:hover {
            background: #f3f4f6;
            transform: translateY(-2px);
        }
        .webclyde-quick-link .dashicons {
            font-size: 24px;
            width: 24px;
            height: 24px;
            color: #667eea;
        }
        ";
        wp_add_inline_style('content-vault-admin', $css);
    }
    
    private function inline_scripts() {
        $js = "
        jQuery(document).ready(function($) {
            // Save settings
            $('#webclyde-settings-form').on('submit', function(e) {
                e.preventDefault();
                var btn = $(this).find('button[type=\"submit\"]');
                var originalText = btn.text();
                btn.text(webclydeContentVault.strings.saving).prop('disabled', true);
                
                $.post(webclydeContentVault.ajaxurl, {
                    action: 'webclyde_save_settings',
                    nonce: webclydeContentVault.nonce,
                    data: $(this).serialize()
                }, function(response) {
                    btn.text(originalText).prop('disabled', false);
                    if (response.success) {
                        showNotice('Settings saved successfully!', 'success');
                    } else {
                        showNotice(response.data || 'Error saving settings', 'error');
                    }
                });
            });
            
            // Test connection
            $('#webclyde-test-connection').on('click', function() {
                var btn = $(this);
                var originalText = btn.text();
                btn.text(webclydeContentVault.strings.testing).prop('disabled', true);
                
                $.post(webclydeContentVault.ajaxurl, {
                    action: 'webclyde_test_connection',
                    nonce: webclydeContentVault.nonce
                }, function(response) {
                    btn.text(originalText).prop('disabled', false);
                    if (response.success) {
                        showNotice('API connection successful!', 'success');
                    } else {
                        showNotice(response.data || 'Connection failed', 'error');
                    }
                });
            });
            
            // Check status
            $(document).on('click', '.webclyde-check-status', function() {
                var btn = $(this);
                var jobId = btn.data('job-id');
                btn.text(webclydeContentVault.strings.checking).prop('disabled', true);
                
                $.post(webclydeContentVault.ajaxurl, {
                    action: 'webclyde_check_status',
                    nonce: webclydeContentVault.nonce,
                    job_id: jobId
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        btn.text('Check').prop('disabled', false);
                        showNotice(response.data || 'Error checking status', 'error');
                    }
                });
            });
            
            // Check health
            $(document).on('click', '.webclyde-check-health', function() {
                var btn = $(this);
                var logId = btn.data('log-id');
                btn.text(webclydeContentVault.strings.checking).prop('disabled', true);
                
                $.post(webclydeContentVault.ajaxurl, {
                    action: 'webclyde_check_health',
                    nonce: webclydeContentVault.nonce,
                    log_id: logId
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        btn.text('Health').prop('disabled', false);
                        showNotice(response.data || 'Error checking health', 'error');
                    }
                });
            });
            
            // Retry archive
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
                        showNotice(response.data || 'Error retrying archive', 'error');
                    }
                });
            });
            
            // Delete log
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
                    } else {
                        showNotice(response.data || 'Error deleting log', 'error');
                    }
                });
            });
            
            // Bulk delete
            $('#webclyde-bulk-delete').on('click', function() {
                var ids = [];
                $('.webclyde-log-checkbox:checked').each(function() {
                    ids.push($(this).val());
                });
                
                if (ids.length === 0) {
                    showNotice('Please select logs to delete', 'error');
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
                        showNotice(response.data || 'Error deleting logs', 'error');
                    }
                });
            });
            
            // Select all
            $('#webclyde-select-all').on('change', function() {
                $('.webclyde-log-checkbox').prop('checked', $(this).is(':checked'));
            });
            
            function showNotice(message, type) {
                var notice = $('<div class=\"webclyde-notice ' + type + '\">' + message + '</div>');
                $('.webclyde-wrap').after(notice);
                setTimeout(function() { notice.fadeOut(function() { $(this).remove(); }); }, 5000);
            }
        });
        ";
        wp_add_inline_script('content-vault-admin', $js);
    }
    
    public function render_dashboard_page() {
        $stats = $this->logger->get_stats();
        ?>
        <div class="wrap webclyde-wrap">
            <div class="webclyde-header">
                <h1><?php esc_html_e('Content Vault', 'content-vault'); ?></h1>
                <p><?php esc_html_e('Archive your WordPress content to the Content Vault automatically', 'content-vault'); ?></p>
            </div>
            
            
            <div class="webclyde-box">
                <h2><?php esc_html_e('System Status', 'content-vault'); ?></h2>
                <div class="webclyde-system-status">
                    <div class="webclyde-status-item">
                        <div class="icon <?php echo class_exists('ActionScheduler') ? 'active' : 'inactive'; ?>">
                            <?php echo class_exists('ActionScheduler') ? '✓' : '✗'; ?>
                        </div>
                        <div>
                            <strong><?php esc_html_e('Action Scheduler', 'content-vault'); ?></strong>
                            <div class="webclyde-time">
                                <?php echo class_exists('ActionScheduler') 
                                    ? esc_html__('Active', 'content-vault') 
                                    : esc_html__('Not installed - Using WP Cron', 'content-vault'); ?>
                            </div>
                        </div>
                    </div>
                    <div class="webclyde-status-item">
                        <div class="icon <?php echo $this->settings->has_api_keys() ? 'active' : 'inactive'; ?>">
                            <?php echo $this->settings->has_api_keys() ? '✓' : '✗'; ?>
                        </div>
                        <div>
                            <strong><?php esc_html_e('API Keys', 'content-vault'); ?></strong>
                            <div class="webclyde-time">
                                <?php echo $this->settings->has_api_keys() 
                                    ? esc_html__('Configured', 'content-vault') 
                                    : esc_html__('Not configured', 'content-vault'); ?>
                            </div>
                        </div>
                    </div>
                    <div class="webclyde-status-item">
                        <div class="icon <?php echo $this->settings->get('enable_posts') ? 'active' : 'inactive'; ?>">
                            <?php echo $this->settings->get('enable_posts') ? '✓' : '✗'; ?>
                        </div>
                        <div>
                            <strong><?php esc_html_e('Post Archiving', 'content-vault'); ?></strong>
                            <div class="webclyde-time">
                                <?php echo $this->settings->get('enable_posts') 
                                    ? esc_html__('Enabled', 'content-vault') 
                                    : esc_html__('Disabled', 'content-vault'); ?>
                            </div>
                        </div>
                    </div>
                    <div class="webclyde-status-item">
                        <div class="icon <?php echo $this->settings->get('enable_pages') ? 'active' : 'inactive'; ?>">
                            <?php echo $this->settings->get('enable_pages') ? '✓' : '✗'; ?>
                        </div>
                        <div>
                            <strong><?php esc_html_e('Page Archiving', 'content-vault'); ?></strong>
                            <div class="webclyde-time">
                                <?php echo $this->settings->get('enable_pages') 
                                    ? esc_html__('Enabled', 'content-vault') 
                                    : esc_html__('Disabled', 'content-vault'); ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="webclyde-quick-links">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=content-vault-settings')); ?>" class="webclyde-quick-link">
                        <span class="dashicons dashicons-admin-generic"></span>
                        <div>
                            <strong><?php esc_html_e('Settings', 'content-vault'); ?></strong>
                            <div class="webclyde-time"><?php esc_html_e('Configure API keys and options', 'content-vault'); ?></div>
                        </div>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=content-vault-logs')); ?>" class="webclyde-quick-link">
                        <span class="dashicons dashicons-list-view"></span>
                        <div>
                            <strong><?php esc_html_e('All Logs', 'content-vault'); ?></strong>
                            <div class="webclyde-time"><?php esc_html_e('View all archive logs', 'content-vault'); ?></div>
                        </div>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=content-vault-posts')); ?>" class="webclyde-quick-link">
                        <span class="dashicons dashicons-admin-post"></span>
                        <div>
                            <strong><?php esc_html_e('Post Logs', 'content-vault'); ?></strong>
                            <div class="webclyde-time"><?php esc_html_e('View post archive logs', 'content-vault'); ?></div>
                        </div>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=content-vault-pages')); ?>" class="webclyde-quick-link">
                        <span class="dashicons dashicons-admin-page"></span>
                        <div>
                            <strong><?php esc_html_e('Page Logs', 'content-vault'); ?></strong>
                            <div class="webclyde-time"><?php esc_html_e('View page archive logs', 'content-vault'); ?></div>
                        </div>
                    </a>
                </div>
            </div>
            
            <div class="webclyde-box">
                <h2><?php esc_html_e('Link Health Overview', 'content-vault'); ?></h2>
                <div class="webclyde-cards" style="margin-bottom: 0;">
                    <div class="webclyde-card success">
                        <h3><?php esc_html_e('Healthy Links', 'content-vault'); ?></h3>
                        <div class="number"><?php echo esc_html($stats['healthy']); ?></div>
                    </div>
                    <div class="webclyde-card">
                        <h3><?php esc_html_e('Unknown Status', 'content-vault'); ?></h3>
                        <div class="number"><?php echo esc_html($stats['unknown']); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function render_settings_page() {
        ?>
        <div class="wrap webclyde-wrap">
            <div class="webclyde-header">
                <h1><?php esc_html_e('Settings', 'content-vault'); ?></h1>
                <p><?php esc_html_e('Configure your Content Vault API settings', 'content-vault'); ?></p>
            </div>
            
            <form id="webclyde-settings-form">
                <div class="webclyde-box">
                    <h2><?php esc_html_e('API Configuration', 'content-vault'); ?></h2>
                    
                    <div class="webclyde-form-row">
                        <label for="access_key"><?php esc_html_e('Access Key', 'content-vault'); ?></label>
                        <input type="text" id="access_key" name="access_key" 
                               value="<?php echo esc_attr($this->settings->get('access_key')); ?>" 
                               placeholder="<?php esc_attr_e('Your S3 Access Key', 'content-vault'); ?>">
                        <p class="description"><?php esc_html_e('Get your keys from archive.org/account/s3.php', 'content-vault'); ?></p>
                    </div>
                    
                    <div class="webclyde-form-row">
                        <label for="secret_key"><?php esc_html_e('Secret Key', 'content-vault'); ?></label>
                        <input type="password" id="secret_key" name="secret_key" 
                               value="<?php echo esc_attr($this->settings->get('secret_key')); ?>" 
                               placeholder="<?php esc_attr_e('Your S3 Secret Key', 'content-vault'); ?>">
                    </div>
                    
                    <button type="button" id="webclyde-test-connection" class="webclyde-btn webclyde-btn-secondary">
                        <span class="dashicons dashicons-networking"></span>
                        <?php esc_html_e('Test Connection', 'content-vault'); ?>
                    </button>
                </div>
                
                <div class="webclyde-box">
                    <h2><?php esc_html_e('Post Archiving', 'content-vault'); ?></h2>
                    
                    <div class="webclyde-checkbox-row">
                        <input type="checkbox" id="enable_posts" name="enable_posts" value="1" 
                               <?php checked($this->settings->get('enable_posts'), 1); ?>>
                        <label for="enable_posts"><?php esc_html_e('Enable automatic archiving for Posts', 'content-vault'); ?></label>
                    </div>
                    <p class="description" style="margin-left: 30px; margin-top: -5px;">
                        <?php esc_html_e('When enabled, posts will be automatically sent to Content Vault when published.', 'content-vault'); ?>
                    </p>
                </div>
                
                <div class="webclyde-box">
                    <h2><?php esc_html_e('Page Archiving', 'content-vault'); ?></h2>
                    
                    <div class="webclyde-checkbox-row">
                        <input type="checkbox" id="enable_pages" name="enable_pages" value="1" 
                               <?php checked($this->settings->get('enable_pages'), 1); ?>>
                        <label for="enable_pages"><?php esc_html_e('Enable automatic archiving for Pages', 'content-vault'); ?></label>
                    </div>
                    <p class="description" style="margin-left: 30px; margin-top: -5px;">
                        <?php esc_html_e('When enabled, pages will be automatically sent to Content Vault when published.', 'content-vault'); ?>
                    </p>
                </div>
                
                <div class="webclyde-box">
                    <h2><?php esc_html_e('Advanced Settings', 'content-vault'); ?></h2>
                    
                    <div class="webclyde-form-row">
                        <label for="check_interval"><?php esc_html_e('Status Check Interval (minutes)', 'content-vault'); ?></label>
                        <input type="number" id="check_interval" name="check_interval" 
                               value="<?php echo esc_attr($this->settings->get('check_interval', 2)); ?>" 
                               min="1" max="60" style="width: 100px;">
                        <p class="description"><?php esc_html_e('How often to check for pending archive status (1-60 minutes)', 'content-vault'); ?></p>
                    </div>
                    
                    <div class="webclyde-form-row">
                        <label for="max_attempts"><?php esc_html_e('Maximum Check Attempts', 'content-vault'); ?></label>
                        <input type="number" id="max_attempts" name="max_attempts" 
                               value="<?php echo esc_attr($this->settings->get('max_attempts', 15)); ?>" 
                               min="1" max="50" style="width: 100px;">
                        <p class="description"><?php esc_html_e('Maximum number of status checks before marking as error (1-50)', 'content-vault'); ?></p>
                    </div>
                    
                    <div class="webclyde-checkbox-row">
                        <input type="checkbox" id="check_link_health" name="check_link_health" value="1" 
                               <?php checked($this->settings->get('check_link_health'), 1); ?>>
                        <label for="check_link_health"><?php esc_html_e('Check Link Health', 'content-vault'); ?></label>
                    </div>
                    <p class="description" style="margin-left: 30px; margin-top: -5px;">
                        <?php esc_html_e('Verify that snapshot URLs are accessible after archiving', 'content-vault'); ?>
                    </p>
                </div>
                
                <div class="webclyde-box">
                    <h2><?php esc_html_e('404 Error Handling', 'content-vault'); ?></h2>
                    
                    <div class="webclyde-form-row">
                        <label for="broken_link_action"><?php esc_html_e('Broken Link Action', 'content-vault'); ?></label>
                        <select id="broken_link_action" name="broken_link_action" style="max-width: 400px; padding: 10px 15px; border: 2px solid #e5e7eb; border-radius: 8px;">
                            <option value="none" <?php selected($this->settings->get('broken_link_action', 'none'), 'none'); ?>><?php esc_html_e('Do Nothing (Default)', 'content-vault'); ?></option>
                            <option value="direct" <?php selected($this->settings->get('broken_link_action', 'none'), 'direct'); ?>><?php esc_html_e('Direct redirect to Wayback Machine', 'content-vault'); ?></option>
                            <option value="404_page" <?php selected($this->settings->get('broken_link_action', 'none'), '404_page'); ?>><?php esc_html_e('Show 404 page with Archive Link banner', 'content-vault'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('If a user hits a 404 page, check the internet archive for a snapshot.', 'content-vault'); ?></p>
                    </div>
                </div>
                
                <button type="submit" class="webclyde-btn webclyde-btn-primary">
                    <span class="dashicons dashicons-saved"></span>
                    <?php esc_html_e('Save Settings', 'content-vault'); ?>
                </button>
            </form>
        </div>
        <?php
    }
    
    public function render_logs_page() {
        $this->render_logs_table('');
    }
    
    public function render_post_logs_page() {
        $this->render_logs_table('post');
    }
    
    public function render_page_logs_page() {
        $this->render_logs_table('page');
    }
    
    private function render_logs_table($post_type = '') {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['paged']) ? max(1, (int) sanitize_text_field(wp_unslash($_GET['paged']))) : 1;
        $per_page = 20;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status_filter = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        
        $args = array(
            'page' => $page,
            'per_page' => $per_page,
            'status' => $status_filter,
            'post_type' => $post_type
        );
        
        $logs = $this->logger->get_all($args);
        $total = $this->logger->get_total($args);
        $total_pages = ceil($total / $per_page);
        $stats = $this->logger->get_stats($post_type);
        
        $page_titles = array(
            '' => __('All Logs', 'content-vault'),
            'post' => __('Post Logs', 'content-vault'),
            'page' => __('Page Logs', 'content-vault')
        );
        
        $page_descriptions = array(
            '' => __('View all archive logs for posts and pages', 'content-vault'),
            'post' => __('View archive logs for posts only', 'content-vault'),
            'page' => __('View archive logs for pages only', 'content-vault')
        );
        ?>
        <div class="wrap webclyde-wrap">
            <div class="webclyde-header">
                <h1><?php echo esc_html($page_titles[$post_type]); ?></h1>
                <p><?php echo esc_html($page_descriptions[$post_type]); ?></p>
            </div>
            
            
            <div class="webclyde-box">
                <h2><?php esc_html_e('Archive Logs', 'content-vault'); ?></h2>
                
                <div class="webclyde-filters">
                    <form method="get" style="display: flex; gap: 15px; align-items: center;">
                        <input type="hidden" name="page" value="<?php 
                            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                            echo isset( $_GET['page'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) : ''; 
                        ?>">
                        
                        <select name="status" onchange="this.form.submit()">
                            <option value=""><?php esc_html_e('All Statuses', 'content-vault'); ?></option>
                            <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php esc_html_e('Pending', 'content-vault'); ?></option>
                            <option value="processing" <?php selected($status_filter, 'processing'); ?>><?php esc_html_e('Processing', 'content-vault'); ?></option>
                            <option value="success" <?php selected($status_filter, 'success'); ?>><?php esc_html_e('Success', 'content-vault'); ?></option>
                            <option value="error" <?php selected($status_filter, 'error'); ?>><?php esc_html_e('Error', 'content-vault'); ?></option>
                        </select>
                    </form>
                    
                    <button type="button" id="webclyde-bulk-delete" class="webclyde-btn webclyde-btn-danger webclyde-btn-small">
                        <span class="dashicons dashicons-trash"></span>
                        <?php esc_html_e('Delete Selected', 'content-vault'); ?>
                    </button>
                </div>
                
                <?php if (empty($logs)): ?>
                    <p><?php esc_html_e('No logs found.', 'content-vault'); ?></p>
                <?php else: ?>
                    <table class="webclyde-table">
                        <thead>
                            <tr>
                                <th style="width: 30px;">
                                    <input type="checkbox" id="webclyde-select-all">
                                </th>
                                <th><?php esc_html_e('Content', 'content-vault'); ?></th>
                                <th><?php esc_html_e('URL', 'content-vault'); ?></th>
                                <th><?php esc_html_e('Archive Status', 'content-vault'); ?></th>
                                <th><?php esc_html_e('Link Health', 'content-vault'); ?></th>
                                <th><?php esc_html_e('Times Checked', 'content-vault'); ?></th>
                                <th><?php esc_html_e('Last Check', 'content-vault'); ?></th>
                                <th><?php esc_html_e('Actions', 'content-vault'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): 
                                $post_title = get_the_title($log->post_id);
                                if (empty($post_title)) {
                                    $post_title = __('(No title)', 'content-vault');
                                }
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="webclyde-log-checkbox" value="<?php echo esc_attr($log->id); ?>">
                                </td>
                                <td>
                                    <strong><?php echo esc_html($post_title); ?></strong>
                                    <div style="margin-top: 5px;">
                                        <span class="webclyde-badge <?php echo esc_attr($log->post_type); ?>">
                                            <?php echo esc_html(ucfirst($log->post_type)); ?>
                                        </span>
                                        <span class="webclyde-time">ID: <?php echo esc_html($log->post_id); ?></span>
                                    </div>
                                    <?php if ($log->job_id): ?>
                                        <div class="webclyde-time" style="margin-top: 3px;">
                                            Job: <?php echo esc_html(substr($log->job_id, 0, 20)); ?>...
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="webclyde-url">
                                        <a href="<?php echo esc_url($log->url); ?>" target="_blank">
                                            <?php echo esc_html($log->url); ?>
                                        </a>
                                    </div>
                                    <?php if ($log->snapshot_url): ?>
                                        <div class="webclyde-url" style="margin-top: 5px;">
                                            <a href="<?php echo esc_url($log->snapshot_url); ?>" target="_blank" style="color: #10b981;">
                                                📸 <?php esc_html_e('View Snapshot', 'content-vault'); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="webclyde-status <?php echo esc_attr($log->status); ?>">
                                        <?php
                                        $status_icons = array(
                                            'pending' => '⏳',
                                            'processing' => '🔄',
                                            'success' => '✓',
                                            'error' => '✗'
                                        );
                                        echo esc_html($status_icons[$log->status] ?? '•');
                                        echo ' ' . esc_html(ucfirst($log->status));
                                        ?>
                                    </span>
                                    <?php if ($log->error_message): ?>
                                        <div class="webclyde-error-msg">
                                            <?php echo esc_html(substr($log->error_message, 0, 50)); ?>...
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="webclyde-health <?php echo esc_attr($log->link_health); ?>">
                                        <?php
                                        $health_icons = array(
                                            'healthy' => '✓',
                                            'unhealthy' => '✗',
                                            'unknown' => '?'
                                        );
                                        echo esc_html($health_icons[$log->link_health] ?? '?');
                                        echo ' ' . esc_html(ucfirst($log->link_health));
                                        ?>
                                    </span>
                                    <?php if ($log->link_health_code): ?>
                                        <div class="webclyde-time">
                                            HTTP <?php echo esc_html($log->link_health_code); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($log->attempts); ?></strong>
                                    <div class="webclyde-time">
                                    
                                        <?php 
                                        /* translators: %d is the maximum number of attempts allowed. */
                                        printf( esc_html__('of %d max', 'content-vault'),
                                            (int) $this->settings->get('max_attempts', 15)
                                        ); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($log->last_checked): ?>
                                        <strong><?php echo esc_html(human_time_diff(strtotime($log->last_checked), current_time('timestamp'))); ?> ago</strong>
                                        <div class="webclyde-time">
                                            <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->last_checked))); ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="webclyde-time"><?php esc_html_e('Never', 'content-vault'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="webclyde-actions">
                                        <?php if ($log->job_id && in_array($log->status, array('pending', 'processing'))): ?>
                                            <button type="button" class="webclyde-btn webclyde-btn-secondary webclyde-btn-small webclyde-check-status" 
                                                    data-job-id="<?php echo esc_attr($log->job_id); ?>">
                                                <?php esc_html_e('Check', 'content-vault'); ?>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($log->snapshot_url): ?>
                                            <button type="button" class="webclyde-btn webclyde-btn-secondary webclyde-btn-small webclyde-check-health" 
                                                    data-log-id="<?php echo esc_attr($log->id); ?>">
                                                <?php esc_html_e('Health', 'content-vault'); ?>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($log->status === 'error'): ?>
                                            <button type="button" class="webclyde-btn webclyde-btn-primary webclyde-btn-small webclyde-retry" 
                                                    data-log-id="<?php echo esc_attr($log->id); ?>">
                                                <?php esc_html_e('Retry', 'content-vault'); ?>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <button type="button" class="webclyde-btn webclyde-btn-danger webclyde-btn-small webclyde-delete" 
                                                data-log-id="<?php echo esc_attr($log->id); ?>">
                                            <?php esc_html_e('Delete', 'content-vault'); ?>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php if ($total_pages > 1): ?>
                        <div class="webclyde-pagination">
                            <span><?php 
                            /* translators: %1$d-%2$d is the range of logs being displayed, %3$d is the total number of logs. */
                            printf( esc_html__('Showing %1$d-%2$d of %3$d logs', 'content-vault'),
                                (int) (($page - 1) * $per_page) + 1,
                                (int) min($page * $per_page, $total),
                                (int) $total
                            ); ?></span>
                            
                            <div>
                                <?php
                                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                                $current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
                                $base_url = add_query_arg(array(
                                    'page' => $current_page,
                                    'status' => $status_filter
                                ), admin_url('admin.php'));
                                
                                if ($page > 1): ?>
                                    <a href="<?php echo esc_url(add_query_arg('paged', $page - 1, $base_url)); ?>" 
                                       class="webclyde-btn webclyde-btn-secondary webclyde-btn-small">
                                        ← <?php esc_html_e('Previous', 'content-vault'); ?>
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="<?php echo esc_url(add_query_arg('paged', $page + 1, $base_url)); ?>" 
                                       class="webclyde-btn webclyde-btn-secondary webclyde-btn-small">
                                        <?php esc_html_e('Next', 'content-vault'); ?> →
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    // AJAX Handlers
    public function ajax_save_settings() {
        check_ajax_referer('webclyde_content_vault_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'content-vault'));
        }
        
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $post_data = isset($_POST['data']) ? wp_unslash($_POST['data']) : '';
        parse_str($post_data, $data);
        
        $fields = array('access_key', 'secret_key', 'enable_posts', 'enable_pages', 'check_interval', 'max_attempts', 'check_link_health', 'broken_link_action');
        
        foreach ($fields as $field) {
            $value = isset($data[$field]) ? $data[$field] : '';
            if (in_array($field, array('enable_posts', 'enable_pages', 'check_link_health'))) {
                $value = isset($data[$field]) ? 1 : 0;
            }
            $this->settings->set($field, $this->settings->sanitize($field, $value));
        }
        
        wp_send_json_success();
    }
    
    public function ajax_test_connection() {
        check_ajax_referer('webclyde_content_vault_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'content-vault'));
        }
        
        $result = $this->api->test_connection();
        
        if ($result['success']) {
            wp_send_json_success();
        } else {
            wp_send_json_error($result['error']);
        }
    }
    
    public function ajax_check_status() {
        check_ajax_referer('webclyde_content_vault_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'content-vault'));
        }
        
        $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
        $this->scheduler->manual_check($job_id);
        
        wp_send_json_success();
    }
    
    public function ajax_check_health() {
        check_ajax_referer('webclyde_content_vault_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'content-vault'));
        }
        
        $log_id = isset($_POST['log_id']) ? (int) sanitize_text_field(wp_unslash($_POST['log_id'])) : 0;
        $this->scheduler->manual_health_check($log_id);
        
        wp_send_json_success();
    }
    
    public function ajax_retry_archive() {
        check_ajax_referer('webclyde_content_vault_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'content-vault'));
        }
        
        $log_id = isset($_POST['log_id']) ? (int) sanitize_text_field(wp_unslash($_POST['log_id'])) : 0;
        $log = $this->logger->get($log_id);
        
        if (!$log) {
            wp_send_json_error(__('Log not found', 'content-vault'));
        }
        
        $result = $this->api->submit_url($log->url);
        
        if ($result['success']) {
            $this->logger->update($log_id, array(
                'job_id' => $result['job_id'],
                'status' => 'pending',
                'error_message' => null,
                'attempts' => 0,
                'link_health' => 'unknown',
                'link_health_code' => null,
                'last_checked' => null
            ));
            
            $this->scheduler->schedule_status_check($result['job_id']);
            wp_send_json_success();
        } else {
            wp_send_json_error($result['error']);
        }
    }
    
    public function ajax_delete_log() {
        check_ajax_referer('webclyde_content_vault_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'content-vault'));
        }
        
        $log_id = isset($_POST['log_id']) ? (int) sanitize_text_field(wp_unslash($_POST['log_id'])) : 0;
        $this->logger->delete($log_id);
        
        wp_send_json_success();
    }
    
    public function ajax_bulk_delete() {
        check_ajax_referer('webclyde_content_vault_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'content-vault'));
        }
        
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw_ids = isset($_POST['ids']) && is_array($_POST['ids']) ? wp_unslash($_POST['ids']) : array();
        $ids = array_map('intval', $raw_ids);
        $this->logger->delete_bulk($ids);
        
        wp_send_json_success();
    }
}
?>
