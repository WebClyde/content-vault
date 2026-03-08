<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WebClyde_Content_Vault_API' ) ) {
    class WebClyde_Content_Vault_API {
        private $settings;
        private $api_url    = 'https://web.archive.org/save';
        private $status_url = 'https://web.archive.org/save/status';

        public function __construct( WebClyde_Content_Vault_Settings $settings ) {
            $this->settings = $settings;
        }

        private function get_headers() {
            return array(
                'Authorization' => 'LOW ' . $this->settings->get('access_key') . ':' . $this->settings->get('secret_key'),
                'Accept'        => 'application/json',
            );
        }

        public function submit_url( $url ) {
            if ( ! $this->settings->has_api_keys() ) {
                return array(
                    'success' => false,
                    'error'   => __('API keys not configured', 'webclyde-content-vault'),
                );
            }

            $response = wp_remote_post( $this->api_url, array(
                'headers' => $this->get_headers(),
                'body'    => array(
                    'url'         => $url,
                    'capture_all' => 1,
                ),
                'timeout' => 30,
            ) );

            if ( is_wp_error( $response ) ) {
                return array(
                    'success' => false,
                    'error'   => $response->get_error_message(),
                );
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $code = wp_remote_retrieve_response_code( $response );

            if ( $code !== 200 || empty( $body ) ) {
                return array(
                    'success' => false,
                    'error'   => isset( $body['message'] ) ? $body['message'] : __('Unknown API error', 'webclyde-content-vault'),
                );
            }

            if ( isset( $body['job_id'] ) ) {
                return array(
                    'success' => true,
                    'job_id'  => $body['job_id'],
                    'status'  => isset( $body['status'] ) ? $body['status'] : 'pending',
                );
            }

            return array(
                'success' => false,
                'error'   => isset( $body['message'] ) ? $body['message'] : __('No job ID returned', 'webclyde-content-vault'),
            );
        }

        public function check_status( $job_id ) {
            if ( ! $this->settings->has_api_keys() ) {
                return array(
                    'success' => false,
                    'error'   => __('API keys not configured', 'webclyde-content-vault'),
                );
            }

            $response = wp_remote_get( $this->status_url . '/' . $job_id, array(
                'headers' => $this->get_headers(),
                'timeout' => 30,
            ) );

            if ( is_wp_error( $response ) ) {
                return array(
                    'success' => false,
                    'error'   => $response->get_error_message(),
                );
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( empty( $body ) ) {
                return array(
                    'success' => false,
                    'error'   => __('Empty response from API', 'webclyde-content-vault'),
                );
            }

            $status = isset( $body['status'] ) ? $body['status'] : 'unknown';

            $result = array(
                'success'       => true,
                'status'        => $status,
                'original_url'  => isset( $body['original_url'] ) ? $body['original_url'] : '',
                'timestamp'     => isset( $body['timestamp'] ) ? $body['timestamp'] : '',
            );

            if ( $status === 'success' && isset( $body['timestamp'] ) ) {
                $result['snapshot_url'] = 'https://web.archive.org/web/' . $body['timestamp'] . '/' . $body['original_url'];
            }

            if ( isset( $body['message'] ) ) {
                $result['message'] = $body['message'];
            }

            return $result;
        }

        public function test_connection() {
            if ( ! $this->settings->has_api_keys() ) {
                return array(
                    'success' => false,
                    'error'   => __('API keys not configured', 'webclyde-content-vault'),
                );
            }

            $response = wp_remote_get( 'https://web.archive.org/save/status/system', array(
                'headers' => $this->get_headers(),
                'timeout' => 15,
            ) );

            if ( is_wp_error( $response ) ) {
                return array(
                    'success' => false,
                    'error'   => $response->get_error_message(),
                );
            }

            $code = wp_remote_retrieve_response_code( $response );

            if ( $code === 200 ) {
                return array( 'success' => true );
            }

            return array(
                'success' => false,
                'error'   => sprintf( __('API returned status code: %d', 'webclyde-content-vault'), $code ),
            );
        }

        public function check_link_health( $url ) {
            $response = wp_remote_head( $url, array(
                'timeout'     => 15,
                'redirection' => 5,
            ) );

            if ( is_wp_error( $response ) ) {
                return array(
                    'healthy' => false,
                    'code'    => 0,
                    'error'   => $response->get_error_message(),
                );
            }

            $code = wp_remote_retrieve_response_code( $response );
            return array(
                'healthy' => ( $code >= 200 && $code < 400 ),
                'code'    => $code,
            );
        }

        public function get_archive_url( $url ) {
            $availability_url = 'https://archive.org/wayback/available?url=' . urlencode( $url );
            $response = wp_remote_get( $availability_url, array(
                'timeout' => 10,
            ) );

            if ( is_wp_error( $response ) ) {
                return false;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            
            if ( isset( $body['archived_snapshots']['closest']['available'] ) && $body['archived_snapshots']['closest']['available'] ) {
                return $body['archived_snapshots']['closest']['url'];
            }

            return false;
        }
    }
}
