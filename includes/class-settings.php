<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WebClyde_Content_Vault_Settings' ) ) {
    class WebClyde_Content_Vault_Settings {
        private $options = array();

        private $option_keys = array(
            'access_key',
            'secret_key',
            'enable_posts',
            'enable_pages',
            'check_interval',
            'max_attempts',
            'check_link_health',
        );

        public function __construct() {
            $this->load_options();
        }

        private function load_options() {
            foreach ( $this->option_keys as $key ) {
                $this->options[ $key ] = get_option( 'webclyde_content_vault_' . $key, '' );
            }
        }

        public function get( $key, $default = '' ) {
            return isset( $this->options[ $key ] ) ? $this->options[ $key ] : $default;
        }

        public function set( $key, $value ) {
            if ( in_array( $key, $this->option_keys, true ) ) {
                $this->options[ $key ] = $value;
                return update_option( 'webclyde_content_vault_' . $key, $value );
            }
            return false;
        }

        public function get_all() {
            return $this->options;
        }

        public function has_api_keys() {
            return ! empty( $this->options['access_key'] ) && ! empty( $this->options['secret_key'] );
        }

        public function sanitize( $key, $value ) {
            switch ( $key ) {
                case 'access_key':
                case 'secret_key':
                    return sanitize_text_field( $value );
                case 'enable_posts':
                case 'enable_pages':
                case 'check_link_health':
                    return (int) (bool) $value;
                case 'check_interval':
                    return max( 1, min( 60, (int) $value ) );
                case 'max_attempts':
                    return max( 1, min( 50, (int) $value ) );
                default:
                    return sanitize_text_field( $value );
            }
        }
    }
}
