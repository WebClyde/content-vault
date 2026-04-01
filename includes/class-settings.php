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
            'broken_link_action',
        );

        public function __construct() {
            $this->load_options();
        }

        private function load_options() {

            foreach ( $this->option_keys as $key ) {

                $value = get_option( 'webclyde_content_vault_' . $key, null );

                // Normalize missing values
                if ( $value === null ) {

                    $value = $this->get_default_value( $key );
                }

                $this->options[ $key ] = $value;
            }
        }

        private function get_default_value( $key ) {

            switch ( $key ) {

                case 'enable_posts':
                case 'enable_pages':
                case 'check_link_health':
                    return 0;

                case 'check_interval':
                    return 10;

                case 'max_attempts':
                    return 15;

                case 'broken_link_action':
                    return 'none';

                case 'access_key':
                case 'secret_key':
                default:
                    return '';
            }
        }

        public function get( $key, $default = '' ) {

            return array_key_exists( $key, $this->options )
                ? $this->options[ $key ]
                : $default;
        }

        public function set( $key, $value ) {

            if ( ! in_array( $key, $this->option_keys, true ) ) {
                return false;
            }

            $value = $this->sanitize( $key, $value );

            $this->options[ $key ] = $value;

            return update_option(
                'webclyde_content_vault_' . $key,
                $value
            );
        }

        public function get_all() {
            return $this->options;
        }

        public function has_api_keys() {

            return ! empty( $this->options['access_key'] )
                && ! empty( $this->options['secret_key'] );
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

                case 'broken_link_action':
                    return in_array(
                        $value,
                        array( 'none', 'direct', '404_page' ),
                        true
                    ) ? $value : 'none';

                default:
                    return sanitize_text_field( $value );
            }
        }
    }
}