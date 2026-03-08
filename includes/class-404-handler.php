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

            add_action( 'template_redirect', array( $this, 'handle_404' ) );
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
            
            // For testing purposes during verification if request is un-archived, we can mock current url
            // $current_url = 'https://example.com/some/old/path';

            $archive_url = $this->api->get_archive_url( $current_url );

            if ( $action === 'direct' ) {
                if ( $archive_url ) {
                    wp_redirect( $archive_url );
                    exit;
                }
                return;
            }

            if ( $action === '404_page' ) {
                $this->render_custom_404_page( $archive_url );
                exit;
            }
        }

        private function render_custom_404_page( $archive_url ) {
            ?>
            <!DOCTYPE html>
            <html <?php language_attributes(); ?>>
            <head>
                <meta charset="<?php bloginfo( 'charset' ); ?>">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title><?php esc_html_e( 'Page Not Found', 'webclyde-content-vault' ); ?> - <?php bloginfo( 'name' ); ?></title>
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
                    <h2><?php esc_html_e( 'Page Not Found', 'webclyde-content-vault' ); ?></h2>
                    <p><?php esc_html_e( 'The page you are looking for might have been removed, had its name changed, or is temporarily unavailable.', 'webclyde-content-vault' ); ?></p>
                    
                    <?php if ( $archive_url ) : ?>
                        <div class="webclyde-archive-box">
                            <h3><?php esc_html_e( 'Good News!', 'webclyde-content-vault' ); ?></h3>
                            <p><?php esc_html_e( 'We found an archived version of this page from the Wayback Machine. You can view it below.', 'webclyde-content-vault' ); ?></p>
                            <a href="<?php echo esc_url( $archive_url ); ?>" class="webclyde-btn" target="_blank">
                                📸 <?php esc_html_e( 'View Archived Page', 'webclyde-content-vault' ); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="webclyde-back-link">&larr; <?php esc_html_e( 'Back to Homepage', 'webclyde-content-vault' ); ?></a>
                </div>
            </body>
            </html>
            <?php
        }
    }
}
