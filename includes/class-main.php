<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WebClyde_Content_Vault' ) ) {

    final class WebClyde_Content_Vault {

        private static $instance = null;

        public $settings;
        public $logger;
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

            update_option(
                'webclyde_content_vault_db_version',
                WEBCLYDE_CONTENT_VAULT_VERSION
            );
        }

        private function set_default_options() {

            $defaults = array(
                'access_key'         => '',
                'secret_key'         => '',
                'check_interval'     => 2,
                'max_attempts'       => 15,
                'check_link_health'  => 1,
                'broken_link_action' => 'none',
                'cooldown_interval'  => 5,
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

        public function init_plugin() {

            $this->settings  = new WebClyde_Content_Vault_Settings();
            $this->logger    = new WebClyde_Content_Vault_Logger();
            $this->api       = new WebClyde_Content_Vault_API( $this->settings );
            $this->scheduler = new WebClyde_Content_Vault_Scheduler(
                $this->api,
                $this->logger,
                $this->settings
            );

            $this->admin = new WebClyde_Content_Vault_Admin(
                $this->settings,
                $this->logger,
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

            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return;
            }

            if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
                return;
            }

            if ( $post->post_status !== 'publish' ) {
                return;
            }

            $enabled_types = $this->settings->get( 'enabled_post_types', array( 'post', 'page' ) );
            if ( ! is_array( $enabled_types ) || ! in_array( $post->post_type, $enabled_types, true ) ) {
                return;
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

            $last_archived = get_post_meta( $post_id, '_webclyde_last_archived', true );
            $cooldown_minutes = (int) $this->settings->get( 'cooldown_interval', 5 );
            $cooldown_seconds = $cooldown_minutes * 60;

            if ( $cooldown_seconds > 0 && $last_archived && ( time() - (int) $last_archived ) < $cooldown_seconds ) {
                return;
            }

            $url       = get_permalink( $post_id );
            $post_type = get_post_type( $post_id );

            $result = $this->api->submit_url( $url );

            if ( ! empty( $result['success'] ) ) {

                $this->logger->create( array(
                    'post_id'   => $post_id,
                    'post_type' => $post_type,
                    'url'       => $url,
                    'job_id'    => $result['job_id'],
                    'status'    => 'pending',
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
