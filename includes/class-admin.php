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
        
        // Post/Page Screen Integration & Archive Now Action
        add_action('add_meta_boxes', array($this, 'add_archive_metabox'));
        add_action('wp_ajax_webclyde_archive_now', array($this, 'ajax_archive_now'));
        
        // Custom Columns for Posts and Pages list table
        add_filter('manage_post_posts_columns', array($this, 'add_custom_columns'));
        add_filter('manage_page_pages_columns', array($this, 'add_custom_columns'));
        add_action('manage_post_posts_custom_column', array($this, 'render_custom_columns'), 10, 2);
        add_action('manage_page_pages_custom_column', array($this, 'render_custom_columns'), 10, 2);
        
        // Bulk Actions
        add_filter('bulk_actions-edit-post', array($this, 'add_bulk_archive_action'));
        add_filter('bulk_actions-edit-page', array($this, 'add_bulk_archive_action'));
        add_filter('handle_bulk_actions-edit-post', array($this, 'handle_bulk_archive_action'), 10, 3);
        add_filter('handle_bulk_actions-edit-page', array($this, 'handle_bulk_archive_action'), 10, 3);
        add_action('admin_notices', array($this, 'show_bulk_archive_admin_notice'));
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
            __('Logs', 'content-vault'),
            __('Logs', 'content-vault'),
            'manage_options',
            'content-vault-logs',
            array($this, 'render_logs_page')
        );
    }
    
    public function enqueue_assets($hook) {
        if (strpos($hook, 'content-vault') === false && $hook !== 'post.php' && $hook !== 'post-new.php' && $hook !== 'edit.php') {
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
    }
    
    public function render_dashboard_page() {
        $stats = $this->logger->get_stats();
        $enabled_types = $this->settings->get('enabled_post_types', array('post', 'page'));
        if (!is_array($enabled_types)) {
            $enabled_types = array();
        }
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
                        <div class="icon <?php echo !empty($enabled_types) ? 'active' : 'inactive'; ?>">
                            <?php echo !empty($enabled_types) ? '✓' : '✗'; ?>
                        </div>
                        <div>
                            <strong><?php esc_html_e('Automatic Archiving', 'content-vault'); ?></strong>
                            <div class="webclyde-time">
                                <?php echo !empty($enabled_types) 
                                    ? sprintf( 
                                        /* translators: %d is the count of enabled post types for automatic archiving */
                                        esc_html__( '%d Post Types Enabled', 'content-vault' ), 
                                        count( $enabled_types ) 
                                    )
                                    : esc_html__('All Disabled', 'content-vault'); ?>
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
                            <strong><?php esc_html_e('Logs List', 'content-vault'); ?></strong>
                            <div class="webclyde-time"><?php esc_html_e('View all archive logs with advanced filters', 'content-vault'); ?></div>
                        </div>
                    </a>
                </div>
            </div>
            
            <div class="webclyde-box">
                <h2><?php esc_html_e('Link Health Overview', 'content-vault'); ?></h2>
                <div class="webclyde-cards" style="margin-bottom: 0;">
                    <a href="<?php echo esc_url(add_query_arg(array(
                        'page' => 'content-vault-logs',
                        'link_health_filter' => 'healthy'
                    ), admin_url('admin.php'))); ?>" class="webclyde-card webclyde-card-link success">
                        <h3><?php esc_html_e('Healthy Links', 'content-vault'); ?></h3>
                        <div class="number"><?php echo esc_html($stats['healthy']); ?></div>
                    </a>
                    <a href="<?php echo esc_url(add_query_arg(array(
                        'page' => 'content-vault-logs',
                        'link_health_filter' => 'unknown'
                    ), admin_url('admin.php'))); ?>" class="webclyde-card webclyde-card-link">
                        <h3><?php esc_html_e('Unknown Status', 'content-vault'); ?></h3>
                        <div class="number"><?php echo esc_html($stats['unknown']); ?></div>
                    </a>
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
                    <h2><?php esc_html_e('Automatic Archiving', 'content-vault'); ?></h2>
                    <p class="description" style="margin-bottom: 20px;">
                        <?php esc_html_e('Select the post types that should be automatically sent to the Content Vault when published or updated.', 'content-vault'); ?>
                    </p>
                    
                    <div class="webclyde-checkbox-row" style="background: #f3f4f6; border-bottom: 1px solid #e5e7eb; font-weight: bold;">
                        <input type="checkbox" id="webclyde-select-all-post-types">
                        <label for="webclyde-select-all-post-types"><?php esc_html_e('Select All Post Types', 'content-vault'); ?></label>
                    </div>
                    
                    <div style="max-height: 250px; overflow-y: auto; padding-left: 5px; margin-top: 15px;">
                        <?php
                        $post_types = get_post_types( array( 'public' => true ), 'objects' );
                        $enabled_types = $this->settings->get( 'enabled_post_types', array( 'post', 'page' ) );
                        if ( ! is_array( $enabled_types ) ) {
                            $enabled_types = array();
                        }
                        
                        foreach ( $post_types as $pt ) {
                            if ( in_array( $pt->name, array( 'attachment', 'revision', 'nav_menu_item' ) ) ) {
                                continue;
                            }
                            $checked = in_array( $pt->name, $enabled_types, true ) ? 'checked' : '';
                            ?>
                            <div class="webclyde-checkbox-row" style="margin-bottom: 8px;">
                                <input type="checkbox" class="webclyde-post-type-checkbox" name="enabled_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $enabled_types, true ) ); ?> id="pt-<?php echo esc_attr( $pt->name ); ?>">
                                <label for="pt-<?php echo esc_attr( $pt->name ); ?>">
                                    <strong><?php echo esc_html( $pt->label ); ?></strong> <span style="font-size: 11px; color: #6b7280;">(<?php echo esc_html( $pt->name ); ?>)</span>
                                </label>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
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
                        <label for="cooldown_interval"><?php esc_html_e('Autosave/Publish Cooldown (minutes)', 'content-vault'); ?></label>
                        <input type="number" id="cooldown_interval" name="cooldown_interval" 
                               value="<?php echo esc_attr($this->settings->get('cooldown_interval', 5)); ?>" 
                               min="0" max="1440" style="width: 100px;">
                        <p class="description"><?php esc_html_e('Minimum wait time between automatic archives for the same post (0 to disable cooldown, manual archiving will always bypass this)', 'content-vault'); ?></p>
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
                            <option value="embed" <?php selected($this->settings->get('broken_link_action', 'none'), 'embed'); ?>><?php esc_html_e('Show Snapshot Embedded on our Website (Iframe)', 'content-vault'); ?></option>
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['paged']) ? max(1, (int) sanitize_text_field(wp_unslash($_GET['paged']))) : 1;
        $per_page = 20;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status_filter = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $post_type_filter = isset($_GET['post_type_filter']) ? sanitize_text_field(wp_unslash($_GET['post_type_filter'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $link_health_filter = isset($_GET['link_health_filter']) ? sanitize_text_field(wp_unslash($_GET['link_health_filter'])) : '';
        
        $args = array(
            'page' => $page,
            'per_page' => $per_page,
            'status' => $status_filter,
            'post_type' => $post_type_filter,
            'link_health' => $link_health_filter
        );
        
        $logs = $this->logger->get_all($args);
        $total = $this->logger->get_total($args);
        $total_pages = ceil($total / $per_page);
        
        ?>
        <div class="wrap webclyde-wrap">
            <div class="webclyde-header">
                <h1><?php esc_html_e('All Archive Logs', 'content-vault'); ?></h1>
                <p><?php esc_html_e('View and manage all archive logs and snapshots for your website content.', 'content-vault'); ?></p>
            </div>
            
            <div class="webclyde-box">
                <h2><?php esc_html_e('Archive Logs', 'content-vault'); ?></h2>
                
                <div class="webclyde-filters">
                    <form method="get" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                        <input type="hidden" name="page" value="content-vault-logs">
                        
                        <!-- Status Filter -->
                        <select name="status" onchange="this.form.submit()">
                            <option value=""><?php esc_html_e('All Statuses', 'content-vault'); ?></option>
                            <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php esc_html_e('Pending', 'content-vault'); ?></option>
                            <option value="processing" <?php selected($status_filter, 'processing'); ?>><?php esc_html_e('Processing', 'content-vault'); ?></option>
                            <option value="success" <?php selected($status_filter, 'success'); ?>><?php esc_html_e('Success', 'content-vault'); ?></option>
                            <option value="completed" <?php selected($status_filter, 'completed'); ?>><?php esc_html_e('Completed', 'content-vault'); ?></option>
                            <option value="completed_fallback" <?php selected($status_filter, 'completed_fallback'); ?>><?php esc_html_e('Completed (Fallback)', 'content-vault'); ?></option>
                            <option value="error" <?php selected($status_filter, 'error'); ?>><?php esc_html_e('Error', 'content-vault'); ?></option>
                        </select>
                        
                        <!-- Post Type Filter -->
                        <select name="post_type_filter" onchange="this.form.submit()">
                            <option value=""><?php esc_html_e('All Post Types', 'content-vault'); ?></option>
                            <?php
                            $registered_types = get_post_types( array( 'public' => true ), 'objects' );
                            foreach ( $registered_types as $pt ) {
                                if ( in_array( $pt->name, array( 'attachment', 'revision', 'nav_menu_item' ) ) ) {
                                    continue;
                                }
                                ?>
                                <option value="<?php echo esc_attr($pt->name); ?>" <?php selected($post_type_filter, $pt->name); ?>>
                                    <?php echo esc_html($pt->label); ?>
                                </option>
                                <?php
                            }
                            ?>
                        </select>
                        
                        <!-- Link Health Filter -->
                        <select name="link_health_filter" onchange="this.form.submit()">
                            <option value=""><?php esc_html_e('All Link Health', 'content-vault'); ?></option>
                            <option value="healthy" <?php selected($link_health_filter, 'healthy'); ?>><?php esc_html_e('Healthy', 'content-vault'); ?></option>
                            <option value="unhealthy" <?php selected($link_health_filter, 'unhealthy'); ?>><?php esc_html_e('Unhealthy', 'content-vault'); ?></option>
                            <option value="unknown" <?php selected($link_health_filter, 'unknown'); ?>><?php esc_html_e('Unknown', 'content-vault'); ?></option>
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
                                            'pending'            => '⏳',
                                            'processing'         => '🔄',
                                            'success'            => '✓',
                                            'completed'          => '✓',
                                            'completed_fallback' => '♻',
                                            'error'              => '✗'
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
                                $base_url = add_query_arg(array(
                                    'page' => 'content-vault-logs',
                                    'status' => $status_filter,
                                    'post_type_filter' => $post_type_filter,
                                    'link_health_filter' => $link_health_filter
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
        
        $fields = array('access_key', 'secret_key', 'check_interval', 'max_attempts', 'check_link_health', 'broken_link_action', 'cooldown_interval');
        
        foreach ($fields as $field) {
            $value = isset($data[$field]) ? $data[$field] : '';
            if (in_array($field, array('check_link_health'))) {
                $value = isset($data[$field]) ? 1 : 0;
            }
            $this->settings->set($field, $this->settings->sanitize($field, $value));
        }
        
        // Save multi-select post types list
        $enabled_post_types = isset($data['enabled_post_types']) ? $data['enabled_post_types'] : array();
        $this->settings->set('enabled_post_types', $this->settings->sanitize('enabled_post_types', $enabled_post_types));
        
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
    
    public function ajax_archive_now() {
        check_ajax_referer('webclyde_content_vault_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Permission denied', 'content-vault'));
        }
        
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        
        if (!$post_id) {
            wp_send_json_error(__('Invalid Post ID', 'content-vault'));
        }
        
        $url       = get_permalink( $post_id );
        $post_type = get_post_type( $post_id );
        
        // Call API and completely bypass any automatic edit/publish cooldown!
        $result = $this->api->submit_url( $url );
        
        if ( ! empty( $result['success'] ) ) {

            $log_id = $this->logger->create( array(
                'post_id'   => $post_id,
                'post_type' => $post_type,
                'url'       => $url,
                'job_id'    => $result['job_id'],
                'status'    => 'pending',
            ) );

            update_post_meta( $post_id, '_webclyde_last_archived', time() );

            $this->scheduler->schedule_status_check( $result['job_id'] );
            
            wp_send_json_success(array(
                'message' => __('Job created successfully!', 'content-vault'),
                'job_id' => $result['job_id'],
                'log_id' => $log_id
            ));

        } else {
            // Log the error
            $this->logger->create( array(
                'post_id'       => $post_id,
                'post_type'     => $post_type,
                'url'           => $url,
                'status'        => 'error',
                'error_message' => $result['error'] ?? 'Unknown error',
            ) );
            
            wp_send_json_error($result['error'] ?? 'Unknown error');
        }
    }
    
    public function add_archive_metabox() {
        $enabled_types = $this->settings->get( 'enabled_post_types', array( 'post', 'page' ) );
        if ( ! is_array( $enabled_types ) ) {
            $enabled_types = array();
        }
        
        foreach ($enabled_types as $post_type) {
            add_meta_box(
                'webclyde_content_vault_metabox',
                __('Content Vault', 'content-vault'),
                array($this, 'render_archive_metabox'),
                $post_type,
                'side',
                'high'
            );
        }
    }
    
    public function render_archive_metabox($post) {
        global $wpdb;
        $table_name = $wpdb->prefix . WEBCLYDE_CONTENT_VAULT_TABLE_NAME;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE post_id = %d ORDER BY created_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $post->ID
        ));
        
        ?>
        <div class="webclyde-metabox">
            <?php if (!$log): ?>
                <p><?php esc_html_e('This post has not been archived yet.', 'content-vault'); ?></p>
                <div style="margin-top:15px;">
                    <button type="button" id="webclyde-archive-now-btn" class="button button-primary button-large" style="width:100%; display:flex; justify-content:center; align-items:center; gap:5px;" data-post-id="<?php echo esc_attr($post->ID); ?>">
                        <span class="dashicons dashicons-backup" style="margin-top:3px;"></span>
                        <?php esc_html_e('Archive Now', 'content-vault'); ?>
                    </button>
                </div>
            <?php else: 
                $status_colors = array(
                    'pending'            => '#d97706',
                    'processing'         => '#2563eb',
                    'completed'          => '#10b981',
                    'completed_fallback' => '#059669',
                    'success'            => '#10b981',
                    'error'              => '#ef4444'
                );
                $color = $status_colors[$log->status] ?? '#6b7280';
                $status_label = ucfirst($log->status);
                if ( $log->status === 'completed' || $log->status === 'success' ) {
                    $status_label = __('Archived Successfully', 'content-vault');
                } elseif ( $log->status === 'completed_fallback' ) {
                    $status_label = __('Archived (Fallback Snapshot)', 'content-vault');
                }
            ?>
                <table class="form-table" style="margin:0; width:100%;">
                    <tbody>
                        <tr>
                            <td style="padding:5px 0; font-weight:600; width:90px;"><?php esc_html_e('Status:', 'content-vault'); ?></td>
                            <td style="padding:5px 0;">
                                <span class="webclyde-status <?php echo esc_attr($log->status); ?>" style="display:inline-block; padding:3px 8px; border-radius:4px; background:<?php echo esc_attr($color); ?>22; color:<?php echo esc_attr($color); ?>; font-weight:bold; font-size:12px;">
                                    <?php echo esc_html($status_label); ?>
                                </span>
                            </td>
                        </tr>
                        <?php if ($log->snapshot_url): ?>
                            <tr>
                                <td style="padding:5px 0; font-weight:600;"><?php esc_html_e('Snapshot:', 'content-vault'); ?></td>
                                <td style="padding:5px 0;">
                                    <a href="<?php echo esc_url($log->snapshot_url); ?>" target="_blank" style="text-decoration:none; color:#10b981; font-weight:500;">
                                        📸 <?php esc_html_e('View Snapshot', 'content-vault'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php if ($log->last_checked): ?>
                            <tr>
                                <td style="padding:5px 0; font-weight:600;"><?php esc_html_e('Last Check:', 'content-vault'); ?></td>
                                <td style="padding:5px 0; font-size:12px; color:#666;">
                                    <?php echo esc_html(human_time_diff(strtotime($log->last_checked), current_time('timestamp'))); ?> ago
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php if ($log->error_message): ?>
                            <tr>
                                <td style="padding:5px 0; font-weight:600; color:#ef4444;"><?php esc_html_e('Error:', 'content-vault'); ?></td>
                                <td style="padding:5px 0; font-size:12px; color:#ef4444; line-height:1.4;">
                                    <?php echo esc_html($log->error_message); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div style="margin-top:15px; border-top:1px solid #eee; padding-top:15px;">
                    <button type="button" id="webclyde-archive-now-btn" class="button button-secondary" style="width:100%; display:flex; justify-content:center; align-items:center; gap:5px;" data-post-id="<?php echo esc_attr($post->ID); ?>">
                        <span class="dashicons dashicons-backup" style="margin-top:3px;"></span>
                        <?php esc_html_e('Archive Again', 'content-vault'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function add_custom_columns($columns) {
        $columns['content_vault'] = __('Content Vault', 'content-vault');
        return $columns;
    }
    
    public function render_custom_columns($column, $post_id) {
        if ($column !== 'content_vault') {
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . WEBCLYDE_CONTENT_VAULT_TABLE_NAME;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE post_id = %d ORDER BY created_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $post_id
        ));
        
        if (!$log) {
            echo '<span style="color:#9ca3af;">—</span>';
            echo '<br><a href="#" class="webclyde-archive-now-inline" data-post-id="' . esc_attr($post_id) . '" style="font-size:11px; text-decoration:none;">' . esc_html__('Archive Now', 'content-vault') . '</a>';
            return;
        }
        
        $status_colors = array(
            'pending'            => '#d97706',
            'processing'         => '#2563eb',
            'completed'          => '#10b981',
            'completed_fallback' => '#059669',
            'success'            => '#10b981',
            'error'              => '#ef4444'
        );
        
        $color = $status_colors[$log->status] ?? '#6b7280';
        $status_label = ucfirst($log->status);
        if ( $log->status === 'completed' || $log->status === 'success' ) {
            $status_label = __('Archived', 'content-vault');
        } elseif ( $log->status === 'completed_fallback' ) {
            $status_label = __('Archived (Fallback)', 'content-vault');
        }
        
        echo '<div style="display:flex; align-items:center; gap:5px; font-weight:600; color:' . esc_attr($color) . ';">';
        echo '<span style="width:8px; height:8px; border-radius:50%; background-color:' . esc_attr($color) . '; display:inline-block;"></span>';
        echo esc_html($status_label);
        echo '</div>';
        
        if ($log->snapshot_url) {
            echo '<a href="' . esc_url($log->snapshot_url) . '" target="_blank" style="font-size:11px; text-decoration:none; color:#10b981; display:block; margin-top:3px;">' . esc_html__('View Snapshot 📸', 'content-vault') . '</a>';
        }
        
        echo '<a href="#" class="webclyde-archive-now-inline" data-post-id="' . esc_attr($post_id) . '" style="font-size:11px; text-decoration:none; display:block; margin-top:3px; color:#667eea;">' . esc_html__('Archive Again', 'content-vault') . '</a>';
    }
    
    public function add_bulk_archive_action($bulk_actions) {
        $bulk_actions['webclyde_bulk_archive'] = __('Archive to Content Vault', 'content-vault');
        return $bulk_actions;
    }
    
    public function handle_bulk_archive_action($redirect_to, $action, $post_ids) {
        if ($action !== 'webclyde_bulk_archive') {
            return $redirect_to;
        }
        
        $archived_count = 0;
        
        foreach ($post_ids as $post_id) {
            $url       = get_permalink($post_id);
            $post_type = get_post_type($post_id);
            
            $result = $this->api->submit_url($url);
            
            if (!empty($result['success'])) {
                $this->logger->create(array(
                    'post_id'   => $post_id,
                    'post_type' => $post_type,
                    'url'       => $url,
                    'job_id'    => $result['job_id'],
                    'status'    => 'pending',
                ));
                
                update_post_meta($post_id, '_webclyde_last_archived', time());
                $this->scheduler->schedule_status_check($result['job_id']);
                $archived_count++;
            } else {
                $this->logger->create(array(
                    'post_id'       => $post_id,
                    'post_type'     => $post_type,
                    'url'           => $url,
                    'status'        => 'error',
                    'error_message' => $result['error'] ?? 'Unknown error',
                ));
            }
        }
        
        $redirect_to = add_query_arg('webclyde_bulk_archived', $archived_count, $redirect_to);
        return $redirect_to;
    }
    
    public function show_bulk_archive_admin_notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (empty($_GET['webclyde_bulk_archived'])) {
            return;
        }
        
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $count = (int) $_GET['webclyde_bulk_archived'];
        
        /* translators: %d is the number of posts successfully sent to the archive queue */
        $message = sprintf(_n('%d post successfully added to archive queue.', '%d posts successfully added to archive queue.', $count, 'content-vault'), $count);
        
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}
?>