<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WebClyde_Content_Vault_404_Handler' ) ) {
    class WebClyde_Content_Vault_404_Handler {
        private $settings;
        private $api;

        public function __construct( WebClyde_Content_Vault_Settings $settings, WebClyde_Content_Vault_API $api ) {
            $this->settings = $settings;
            $this->api = $api;

            // Hook with priority 1 to handle 404s before WordPress canonical redirections
            add_action( 'template_redirect', array( $this, 'handle_404' ), 1 );
        }

        public function handle_404() {
            if ( ! is_404() ) {
                return;
            }

            $action = $this->settings->get( 'broken_link_action', 'none' );
            if ( $action === 'none' ) {
                return;
            }

            global $wp;
            $current_url = home_url( add_query_arg( array(), $wp->request ) );
            if ( empty( $current_url ) ) {
                return;
            }
            
            // 1. Check local database logs first for a matching archived snapshot
            $archive_url = $this->get_local_archive_url( $current_url );

            // 2. If not found locally, query Wayback Machine available API
            if ( ! $archive_url ) {
                $archive_url = $this->api->get_archive_url( $current_url );
            }

            if ( ! $archive_url ) {
                return;
            }

            if ( $action === 'direct' ) {
                // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
                wp_redirect( $archive_url );
                exit;
            }

            if ( $action === '404_page' ) {
                $this->render_custom_404_page( $archive_url );
                exit;
            }

            if ( $action === 'embed' ) {
                $this->render_embedded_snapshot_page( $archive_url, $current_url );
                exit;
            }
        }

        /**
         * Search our local database logs for a successfully completed archive snapshot for this URL.
         */
        private function get_local_archive_url( $url ) {
            global $wpdb;
            $table_name = $wpdb->prefix . WEBCLYDE_CONTENT_VAULT_TABLE_NAME;

            // Create variations of URL to find the exact match
            $variants = array( $url );
            $trimmed = rtrim( $url, '/' );
            $variants[] = $trimmed;
            $variants[] = $trimmed . '/';
            $variants = array_unique( $variants );

            foreach ( $variants as $variant ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $snapshot_url = $wpdb->get_var( $wpdb->prepare(
                    "SELECT snapshot_url FROM {$table_name} WHERE url = %s AND status IN ('completed','completed_fallback') AND snapshot_url IS NOT NULL AND snapshot_url != '' ORDER BY created_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $variant
                ) );

                if ( ! empty( $snapshot_url ) ) {
                    return $snapshot_url;
                }
            }

            return false;
        }

        private function render_embedded_snapshot_page( $archive_url, $current_url ) {
            ?>
            <!DOCTYPE html>
            <html <?php language_attributes(); ?>>
            <head>
                <meta charset="<?php bloginfo( 'charset' ); ?>">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title><?php esc_html_e( 'Archived Snapshot', 'content-vault' ); ?> - <?php bloginfo( 'name' ); ?></title>
                <style>
                    html, body {
                        margin: 0;
                        padding: 0;
                        height: 100%;
                        overflow: hidden;
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                    }
                    .webclyde-bar {
                        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                        color: white;
                        height: 60px;
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        padding: 0 20px;
                        box-shadow: 0 2px 10px rgba(0,0,0,0.15);
                        position: relative;
                        z-index: 99999;
                    }
                    .webclyde-info {
                        display: flex;
                        align-items: center;
                        gap: 12px;
                    }
                    .webclyde-info-icon {
                        font-size: 24px;
                    }
                    .webclyde-info h3 {
                        margin: 0;
                        font-size: 16px;
                        font-weight: 600;
                    }
                    .webclyde-info p {
                        margin: 2px 0 0;
                        font-size: 13px;
                        opacity: 0.85;
                    }
                    .webclyde-actions {
                        display: flex;
                        align-items: center;
                        gap: 12px;
                    }
                    .webclyde-btn {
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        background: rgba(255, 255, 255, 0.15);
                        color: white;
                        padding: 8px 16px;
                        text-decoration: none;
                        border-radius: 6px;
                        font-weight: 500;
                        font-size: 13px;
                        transition: all 0.2s;
                        border: 1px solid rgba(255, 255, 255, 0.2);
                    }
                    .webclyde-btn:hover {
                        background: rgba(255, 255, 255, 0.25);
                        transform: translateY(-1px);
                    }
                    .webclyde-btn-primary {
                        background: white;
                        color: #667eea;
                        border: none;
                        font-weight: 600;
                    }
                    .webclyde-btn-primary:hover {
                        background: #f3f4f6;
                        color: #764ba2;
                    }
                    .webclyde-iframe-container {
                        width: 100%;
                        height: calc(100% - 60px);
                        border: none;
                    }
                    iframe {
                        width: 100%;
                        height: 100%;
                        border: none;
                    }
                </style>
            </head>
            <body>
                <div class="webclyde-bar">
                    <div class="webclyde-info">
                        <span class="webclyde-info-icon">📸</span>
                        <div>
                            <h3><?php esc_html_e( 'Viewing Archived Version', 'content-vault' ); ?></h3>
                            <p><?php echo esc_html( sprintf( 
                                /* translators: %s: relative URL path of the offline page */
                                __( 'The requested page at %s is offline. Showing an archived snapshot.', 'content-vault' ), 
                                wp_parse_url( $current_url, PHP_URL_PATH ) 
                            ) ); ?></p>
                        </div>
                    </div>
                    <div class="webclyde-actions">
                        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="webclyde-btn">
                            &larr; <?php esc_html_e( 'Back to Site', 'content-vault' ); ?>
                        </a>
                        <a href="<?php echo esc_url( $archive_url ); ?>" class="webclyde-btn webclyde-btn-primary" target="_blank">
                            <?php esc_html_e( 'Open on Wayback Machine', 'content-vault' ); ?> &rarr;
                        </a>
                    </div>
                </div>
                <div class="webclyde-iframe-container">
                    <iframe src="<?php echo esc_url( $archive_url ); ?>"></iframe>
                </div>
            </body>
            </html>
            <?php
        }

        private function render_custom_404_page( $archive_url ) {
            ?>
            <!DOCTYPE html>
            <html <?php language_attributes(); ?>>
            <head>
                <meta charset="<?php bloginfo( 'charset' ); ?>">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title><?php esc_html_e( 'Page Not Found', 'content-vault' ); ?> - <?php bloginfo( 'name' ); ?></title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; background: #f3f4f6; color: #374151; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
                    .webclyde-404-container { background: white; padding: 50px 40px; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1); text-align: center; max-width: 550px; width: 90%; }
                    h1 { font-size: 80px; margin: 0 0 10px; color: #667eea; font-weight: 800; line-height: 1; }
                    h2 { font-size: 24px; margin: 0 0 20px; color: #1f2937; font-weight: 600; }
                    p { margin: 0 0 30px; line-height: 1.6; color: #6b7280; font-size: 16px; }
                    a.webclyde-btn { display: inline-flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 6px rgba(102, 126, 234, 0.25); }
                    a.webclyde-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 15px rgba(102, 126, 234, 0.4); }
                    .webclyde-archive-box { background: #f0fdf4; border: 1px solid #bbf7d0; padding: 25px; border-radius: 10px; margin-bottom: 35px; }
                    .webclyde-archive-box h3 { margin: 0 0 10px; color: #166534; font-size: 18px; }
                    .webclyde-archive-box p { color: #15803d; margin-bottom: 20px; font-size: 15px; }
                    .webclyde-back-link { display: inline-block; color: #6b7280; text-decoration: none; font-weight: 500; transition: color 0.2s; }
                    .webclyde-back-link:hover { color: #374151; }
                </style>
            </head>
            <body>
                <div class="webclyde-404-container">
                    <h1>404</h1>
                    <h2><?php esc_html_e( 'Page Not Found', 'content-vault' ); ?></h2>
                    <p><?php esc_html_e( 'The page you are looking for might have been removed, had its name changed, or is temporarily unavailable.', 'content-vault' ); ?></p>
                    
                    <?php if ( $archive_url ) : ?>
                        <div class="webclyde-archive-box">
                            <h3><?php esc_html_e( 'Good News!', 'content-vault' ); ?></h3>
                            <p><?php esc_html_e( 'We found an archived version of this page from the Wayback Machine. You can view it below.', 'content-vault' ); ?></p>
                            <a href="<?php echo esc_url( $archive_url ); ?>" class="webclyde-btn" target="_blank">
                                📸 <?php esc_html_e( 'View Archived Page', 'content-vault' ); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="webclyde-back-link">&larr; <?php esc_html_e( 'Back to Homepage', 'content-vault' ); ?></a>
                </div>
            </body>
            </html>
            <?php
        }
    }
}
