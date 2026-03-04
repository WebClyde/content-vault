<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WebClyde_Content_Vault_Scheduler' ) ) {
    class WebClyde_Content_Vault_Scheduler {
        private $api;
        private $logger;
        private $settings;

        public function __construct(
            WebClyde_Content_Vault_API $api,
            WebClyde_Content_Vault_Logger $logger,
            WebClyde_Content_Vault_Settings $settings
        ) {
            $this->api      = $api;
            $this->logger   = $logger;
            $this->settings = $settings;

            add_action( 'webclyde_content_vault_check_pending', array( $this, 'check_pending_job' ) );
            add_action( 'webclyde_content_vault_check_status', array( $this, 'check_all_pending' ) );
            add_action( 'webclyde_content_vault_check_health', array( $this, 'check_link_health' ) );

            if ( ! wp_next_scheduled( 'webclyde_content_vault_check_status' ) && ! $this->has_action_scheduler() ) {
                wp_schedule_event( time(), 'webclyde_two_minutes', 'webclyde_content_vault_check_status' );
            }

            add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
        }

        public function add_cron_interval( $schedules ) {
            $interval = (int) $this->settings->get( 'check_interval', 2 );
            $schedules['webclyde_two_minutes'] = array(
                'interval' => $interval * 60,
                'display'  => sprintf( __('Every %d minutes', 'webclyde-content-vault'), $interval ),
            );
            return $schedules;
        }

        private function has_action_scheduler() {
            return class_exists( 'ActionScheduler' ) && function_exists( 'as_schedule_single_action' );
        }

        public function schedule_status_check( $job_id ) {
            $interval = (int) $this->settings->get( 'check_interval', 2 ) * 60;

            if ( $this->has_action_scheduler() ) {
                as_schedule_single_action(
                    time() + $interval,
                    'webclyde_content_vault_check_pending',
                    array( 'job_id' => $job_id ),
                    'webclyde-content-vault'
                );
            }
        }

        public function schedule_health_check( $log_id ) {
            if ( $this->has_action_scheduler() ) {
                as_schedule_single_action(
                    time() + 30,
                    'webclyde_content_vault_check_health',
                    array( 'log_id' => $log_id ),
                    'webclyde-content-vault'
                );
            } else {
                $this->check_link_health( $log_id );
            }
        }

        public function check_pending_job( $job_id ) {
            $log = $this->logger->get_by_job_id( $job_id );

            if ( ! $log || ! in_array( $log->status, array( 'pending', 'processing' ), true ) ) {
                return;
            }

            $max_attempts = (int) $this->settings->get( 'max_attempts', 15 );

            if ( $log->attempts >= $max_attempts ) {
                $this->logger->update( $log->id, array(
                    'status'        => 'error',
                    'error_message' => __('Max attempts reached', 'webclyde-content-vault'),
                    'last_checked'  => current_time('mysql'),
                ) );
                return;
            }

            $result = $this->api->check_status( $job_id );

            $update_data = array(
                'attempts'      => $log->attempts + 1,
                'last_checked'  => current_time('mysql'),
            );

            if ( $result['success'] ) {
                $update_data['status'] = $result['status'];

                if ( $result['status'] === 'success' && ! empty( $result['snapshot_url'] ) ) {
                    $update_data['snapshot_url'] = $result['snapshot_url'];

                    if ( $this->settings->get( 'check_link_health' ) ) {
                        $this->logger->update( $log->id, $update_data );
                        $this->schedule_health_check( $log->id );
                        return;
                    }
                } elseif ( $result['status'] === 'error' ) {
                    $update_data['error_message'] = isset( $result['message'] ) ? $result['message'] : __('Archive failed', 'webclyde-content-vault');
                } elseif ( in_array( $result['status'], array( 'pending', 'processing' ), true ) ) {
                    $this->schedule_status_check( $job_id );
                }
            } else {
                $update_data['error_message'] = $result['error'];
                $this->schedule_status_check( $job_id );
            }

            $this->logger->update( $log->id, $update_data );
        }

        public function check_all_pending() {
            $pending = $this->logger->get_pending();
            foreach ( $pending as $log ) {
                if ( ! empty( $log->job_id ) ) {
                    $this->check_pending_job( $log->job_id );
                }
            }
        }

        public function check_link_health( $log_id ) {
            $log = $this->logger->get( $log_id );
            if ( ! $log || empty( $log->snapshot_url ) ) {
                return;
            }

            $result = $this->api->check_link_health( $log->snapshot_url );
            $this->logger->update( $log->id, array(
                'link_health'      => $result['healthy'] ? 'healthy' : 'unhealthy',
                'link_health_code' => $result['code'],
                'last_checked'     => current_time('mysql'),
            ) );
        }

        public function manual_check( $job_id ) {
            return $this->check_pending_job( $job_id );
        }

        public function manual_health_check( $log_id ) {
            return $this->check_link_health( $log_id );
        }
    }
}
