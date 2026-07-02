<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WebClyde_Content_Vault' ) ) {

    final class WebClyde_Content_Vault {

        private static $instance = null;

        public $settings;
        public $logger;
        public $versioner;
        public $api;
        public $scheduler;
        public $admin;
        public $handler_404;

        public static function get_instance() {

            if ( null === self::$instance ) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        private function __construct() {
            $this->init_hooks();
        }

        private function init_hooks() {

            register_activation_hook(
                WEBCLYDE_CONTENT_VAULT_PLUGIN_FILE,
                array( $this, 'activate' )
            );

            register_deactivation_hook(
                WEBCLYDE_CONTENT_VAULT_PLUGIN_FILE,
                array( $this, 'deactivate' )
            );

            add_action( 'init', array( $this, 'init_plugin' ) );
            add_action( 'admin_init', array( $this, 'check_action_scheduler' ) );
        }

        public function activate() {

            $this->create_database_table();
            $this->set_default_options();

            flush_rewrite_rules();
        }

        public function deactivate() {

            wp_clear_scheduled_hook( 'webclyde_content_vault_check_status' );

            if ( function_exists( 'as_unschedule_all_actions' ) ) {
                as_unschedule_all_actions( 'webclyde_content_vault_check_pending' );
            }
        }

        private function create_database_table() {

            global $wpdb;

            $table_name = $wpdb->prefix . WEBCLYDE_CONTENT_VAULT_TABLE_NAME;
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                post_id bigint(20) NOT NULL,
                post_type varchar(50) NOT NULL DEFAULT 'post',
                url text NOT NULL,
                job_id varchar(255) DEFAULT NULL,
                status varchar(50) NOT NULL DEFAULT 'pending',
                snapshot_url text DEFAULT NULL,
                content_hash varchar(64) DEFAULT NULL,
                error_message text DEFAULT NULL,
                attempts int(11) NOT NULL DEFAULT 0,
                link_health varchar(20) DEFAULT 'unknown',
                link_health_code int(11) DEFAULT NULL,
                last_checked datetime DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY post_id (post_id),
                KEY status (status),
                KEY job_id (job_id),
                KEY post_type (post_type)
            ) $charset_collate;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );

            $this->create_versions_table( $charset_collate );

            update_option(
                'webclyde_content_vault_db_version',
                WEBCLYDE_CONTENT_VAULT_VERSION
            );
        }

        private function create_versions_table( string $charset_collate ): void {
            global $wpdb;

            $table_name = $wpdb->prefix . WEBCLYDE_CONTENT_VAULT_VERSIONS_TABLE;

            $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                post_id bigint(20) NOT NULL,
                post_type varchar(50) NOT NULL DEFAULT 'post',
                title text NOT NULL,
                content longtext NOT NULL,
                excerpt text DEFAULT NULL,
                word_count int(11) NOT NULL DEFAULT 0,
                content_hash varchar(64) NOT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY post_id (post_id),
                KEY post_id_hash (post_id, content_hash)
            ) $charset_collate;";

            dbDelta( $sql );
        }

        private function set_default_options() {

            $defaults = array(
                'access_key'         => '',
                'secret_key'         => '',
                'check_interval'     => 2,
                'max_attempts'       => 15,
                'check_link_health'  => 1,
                'broken_link_action' => 'none',
                'cooldown_interval'  => 1440,
                'enabled_post_types' => array( 'post', 'page' ),
            );

            foreach ( $defaults as $key => $value ) {

                $option_name = 'webclyde_content_vault_' . $key;

                if ( get_option( $option_name, null ) === null ) {
                    update_option( $option_name, $value );
                }
            }
        }

        public function check_action_scheduler() {

            if ( class_exists( 'ActionScheduler' ) ) {
                return;
            }

            if ( ! wp_next_scheduled( 'webclyde_install_action_scheduler' ) ) {
                $this->install_action_scheduler();
            }
        }

        private function install_action_scheduler() {

            if ( class_exists( 'ActionScheduler' ) ) {
                return true;
            }

            if ( ! function_exists( 'plugins_api' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            }

            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            if ( ! class_exists( 'Plugin_Upgrader' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            }

            $plugin_slug = 'action-scheduler';
            $plugin_file = 'action-scheduler/action-scheduler.php';

            $installed_plugins = get_plugins();

            if ( ! isset( $installed_plugins[ $plugin_file ] ) ) {

                $api = plugins_api( 'plugin_information', array(
                    'slug'   => $plugin_slug,
                    'fields' => array( 'sections' => false ),
                ) );

                if ( is_wp_error( $api ) ) {
                    return false;
                }

                $upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
                $result   = $upgrader->install( $api->download_link );

                if ( is_wp_error( $result ) || ! $result ) {
                    return false;
                }
            }

            if ( ! is_plugin_active( $plugin_file ) ) {
                activate_plugin( $plugin_file );
            }

            return true;
        }

        private function maybe_migrate_settings(): void {
            if ( false !== get_option( 'webclyde_content_vault_cooldown_migrated_v2' ) ) {
                return;
            }

            // Migrate old 5-minute default cooldown to once-per-day.
            $current = (int) get_option( 'webclyde_content_vault_cooldown_interval', -1 );
            if ( 5 === $current ) {
                update_option( 'webclyde_content_vault_cooldown_interval', 1440 );
            }

            // Ensure both DB tables exist for sites upgrading from older versions.
            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset_collate = $wpdb->get_charset_collate();

            // Add content_hash column to logs table if absent (dbDelta is safe to re-run).
            $logs_table = $wpdb->prefix . WEBCLYDE_CONTENT_VAULT_TABLE_NAME;
            $logs_sql   = "CREATE TABLE $logs_table (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                post_id bigint(20) NOT NULL,
                post_type varchar(50) NOT NULL DEFAULT 'post',
                url text NOT NULL,
                job_id varchar(255) DEFAULT NULL,
                status varchar(50) NOT NULL DEFAULT 'pending',
                snapshot_url text DEFAULT NULL,
                content_hash varchar(64) DEFAULT NULL,
                error_message text DEFAULT NULL,
                attempts int(11) NOT NULL DEFAULT 0,
                link_health varchar(20) DEFAULT 'unknown',
                link_health_code int(11) DEFAULT NULL,
                last_checked datetime DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY post_id (post_id),
                KEY status (status),
                KEY job_id (job_id),
                KEY post_type (post_type)
            ) $charset_collate;";
            dbDelta( $logs_sql );

            $this->create_versions_table( $charset_collate );

            update_option( 'webclyde_content_vault_cooldown_migrated_v2', true );
        }

        public function init_plugin() {

            $this->maybe_migrate_settings();

            $this->settings  = new WebClyde_Content_Vault_Settings();
            $this->logger    = new WebClyde_Content_Vault_Logger();
            $this->versioner = new WebClyde_Content_Vault_Versioner();
            $this->api       = new WebClyde_Content_Vault_API( $this->settings );
            $this->scheduler = new WebClyde_Content_Vault_Scheduler(
                $this->api,
                $this->logger,
                $this->settings
            );

            $this->admin = new WebClyde_Content_Vault_Admin(
                $this->settings,
                $this->logger,
                $this->versioner,
                $this->api,
                $this->scheduler
            );

            $this->handler_404 = new WebClyde_Content_Vault_404_Handler(
                $this->settings,
                $this->api
            );

            // Use save_post so both first-publish AND subsequent edits of already-published
            // posts trigger archiving (publish_{post_type} only fires on the status transition,
            // not on updates of posts that are already published).
            add_action( 'save_post', array( $this, 'handle_save' ), 10, 3 );

            // Action Scheduler hook: fired per-post during bulk archiving.
            add_action( 'webclyde_content_vault_archive_post', array( $this, 'do_archive' ) );
        }

        /**
         * Fired on save_post — gates to published posts of enabled types only.
         * Skips autosaves, revisions, and posts not yet in publish status.
         * Delegates to handle_publish() which owns the cooldown + logging logic.
         *
         * @param int     $post_id Post ID.
         * @param WP_Post $post    Post object.
         * @param bool    $update  Whether this is an update (true) or a new insert (false).
         */
        public function handle_save( $post_id, $post, $update ) {

            // WordPress can fire save_post more than once per request — e.g. Gutenberg
            // issues a REST save while a third-party plugin's save_post hook calls
            // wp_update_post() internally. Both calls read the same "old" hash before
            // either has written the new version row, producing duplicate version entries.
            // Track processed IDs for the lifetime of this request to prevent that.
            static $processed = array();
            if ( isset( $processed[ $post_id ] ) ) {
                return;
            }

            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return;
            }

            if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
                return;
            }

            if ( $post->post_status !== 'publish' ) {
                return;
            }

            // Never archive password-protected content.
            if ( ! empty( $post->post_password ) ) {
                return;
            }

            $enabled_types = $this->settings->get( 'enabled_post_types', array( 'post', 'page' ) );
            if ( ! is_array( $enabled_types ) || ! in_array( $post->post_type, $enabled_types, true ) ) {
                return;
            }

            /**
             * Allow third-party plugins (e.g. Yoast SEO, RankMath) to prevent archiving.
             *
             * @param bool    $skip    Whether to skip archiving this post.
             * @param int     $post_id Post ID.
             * @param WP_Post $post    Post object.
             */
            if ( apply_filters( 'webclyde_content_vault_skip_archive', false, $post_id, $post ) ) {
                return;
            }

            // All gates passed — mark this post_id as processed for this request.
            $processed[ $post_id ] = true;

            // ── Layer 1: Local version history ─────────────────────────────────
            // Compute a content hash and save a local snapshot on every qualifying
            // save. If the content is identical to the last saved version, nothing
            // has changed — skip both local snapshot and WM submission.
            $current_hash = $this->versioner->compute_hash( $post );
            $last_version_hash = $this->versioner->get_latest_hash( $post_id );

            if ( $last_version_hash === $current_hash ) {
                return; // Content unchanged — no local version, no WM archive.
            }

            $this->versioner->save( $post_id, $post, $current_hash );

            // ── Layer 2: Wayback Machine ────────────────────────────────────────
            // Only submit to WM if content differs from the last successfully
            // archived version. Prevents re-archiving the same content twice.
            $last_archived_hash = $this->logger->get_last_archived_hash( $post_id );

            if ( $last_archived_hash === $current_hash ) {
                return; // WM already has this exact content.
            }

            $this->handle_publish( $post_id, $post );
        }

        public function handle_publish( $post_id, $post ) {

            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return;
            }

            if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
                return;
            }

            $last_archived    = get_post_meta( $post_id, '_webclyde_last_archived', true );
            $cooldown_minutes = (int) $this->settings->get( 'cooldown_interval', 5 );
            $cooldown_seconds = $cooldown_minutes * 60;

            if ( $cooldown_seconds > 0 && $last_archived && ( time() - (int) $last_archived ) < $cooldown_seconds ) {
                return;
            }

            $this->do_archive( $post_id );
        }

        /**
         * Submit a post to Wayback Machine and create a log entry.
         * No cooldown — callers gate if needed. Always requests a fresh WM capture
         * so each publish/update gets its own snapshot attempt and log row.
         * Also fired via the `webclyde_content_vault_archive_post` AS action (bulk queue).
         *
         * @param int $post_id Post ID.
         */
        public function do_archive( int $post_id ): void {

            $post = get_post( $post_id );

            if ( ! $post || $post->post_status !== 'publish' || ! empty( $post->post_password ) ) {
                return;
            }

            if ( apply_filters( 'webclyde_content_vault_skip_archive', false, $post_id, $post ) ) {
                return;
            }

            $url          = get_permalink( $post_id );
            $post_type    = $post->post_type;
            $content_hash = $this->versioner->compute_hash( $post );
            $result       = $this->api->submit_url( $url );

            if ( ! empty( $result['success'] ) ) {

                // Always create a fresh pending row — no duplicate-detection shortcut.
                // The scheduler resolves this row via check_status(); resolve_pending_siblings()
                // closes out any older pending rows that share the same WM job_id.
                // content_hash is stored so get_last_archived_hash() can detect unchanged
                // content on future saves and skip redundant WM submissions.
                $this->logger->create( array(
                    'post_id'      => $post_id,
                    'post_type'    => $post_type,
                    'url'          => $url,
                    'job_id'       => $result['job_id'],
                    'status'       => 'pending',
                    'content_hash' => $content_hash,
                ) );

                update_post_meta( $post_id, '_webclyde_last_archived', time() );
                $this->scheduler->schedule_status_check( $result['job_id'] );

            } else {

                $this->logger->create( array(
                    'post_id'       => $post_id,
                    'post_type'     => $post_type,
                    'url'           => $url,
                    'status'        => 'error',
                    'error_message' => $result['error'] ?? 'Unknown error',
                ) );
            }
        }
    }
}
