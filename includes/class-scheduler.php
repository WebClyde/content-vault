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
            $interval = (int) $this->settings->get( 'check_interval', 10 );

            /* translators: %d is the number of minutes between checks. */
            $display = sprintf( __('Every %d minutes', 'content-vault'), $interval );

            $schedules['webclyde_two_minutes'] = array(
                'interval' => $interval * 60,
                'display'  => $display,
            );

            return $schedules;
        }

        private function has_action_scheduler() {
            return class_exists( 'ActionScheduler' ) && function_exists( 'as_schedule_single_action' );
        }

        public function is_action_scheduler_available(): bool {
            return $this->has_action_scheduler();
        }

        /**
         * Queue a single post for background archiving via Action Scheduler.
         * Used by bulk-archive: the AS action fires do_archive() on the main class.
         */
        public function schedule_post_archive( int $post_id ): void {
            if ( ! $this->has_action_scheduler() ) {
                return;
            }
            as_schedule_single_action(
                time() + 5,
                'webclyde_content_vault_archive_post',
                array( 'post_id' => $post_id ),
                'content-vault'
            );
        }

        public function schedule_status_check( string $job_id, int $delay = 0 ): void {
            $interval = (int) $this->settings->get( 'check_interval', 10 ) * 60;
            $delay    = $delay > 0 ? $delay : $interval;

            if ( $this->has_action_scheduler() ) {
                as_schedule_single_action(
                    time() + $delay,
                    'webclyde_content_vault_check_pending',
                    array( 'job_id' => $job_id ),
                    'content-vault'
                );
            }
        }

        private function is_rate_limit_error( string $message ): bool {
            return stripos( $message, 'limit of active' ) !== false
                || stripos( $message, 'rate-limit' ) !== false
                || stripos( $message, 'rate_limit' ) !== false
                || stripos( $message, 'too many' ) !== false;
        }

        public function schedule_health_check( $log_id ) {
            if ( $this->has_action_scheduler() ) {
                as_schedule_single_action(
                    time() + 30,
                    'webclyde_content_vault_check_health',
                    array( 'log_id' => $log_id ),
                    'content-vault'
                );
            } else {
                $this->check_link_health( $log_id );
            }
        }

        public function check_pending_job( $job_id ) {

            $log = $this->logger->get_by_job_id( $job_id );

            if ( ! $log ) {
                return;
            }

            // 🔴 TERMINAL STATE GUARD (must be first)
            if ( in_array( $log->status, array( 'success', 'completed', 'completed_fallback' ), true ) ) {
                return;
            }

            // 🔴 COOLDOWN CHECK
            $cooldown = (int) $this->settings->get( 'check_interval', 10 ) * 60;

            if (
                ! empty( $log->last_checked ) &&
                strtotime( $log->last_checked ) > ( time() - $cooldown )
            ) {
                return;
            }

            $max_attempts = (int) $this->settings->get( 'max_attempts', 15 );

            // If max attempts reached, let's proactively query the Availability API before giving up!
            if ( $log->attempts >= $max_attempts ) {
                $fallback_url = $this->api->get_archive_url( $log->url );
                if ( $fallback_url ) {
                    $this->logger->update( $log->id, array(
                        'status'       => 'completed_fallback',
                        'snapshot_url' => $fallback_url,
                        'last_checked' => current_time( 'Y-m-d H:i:s' ),
                        'finished_at'  => current_time( 'Y-m-d H:i:s' ),
                        'error_message'=> null,
                    ) );
                    $this->logger->resolve_pending_siblings( $job_id, $log->id, 'completed_fallback', $fallback_url );
                    return;
                }

                $this->logger->update( $log->id, array(
                    'status'        => 'error',
                    'error_message' => __( 'Max attempts reached', 'content-vault' ),
                    'last_checked'  => current_time( 'Y-m-d H:i:s' ),
                    'finished_at'   => current_time( 'Y-m-d H:i:s' ),
                ) );
                return;
            }

            $result = $this->api->check_status( $job_id );

            $update_data = array(
                'attempts'     => $log->attempts + 1,
                'last_checked' => current_time( 'Y-m-d H:i:s' ),
            );

            if ( $result['success'] ) {

                $update_data['status'] = $result['status'];

                // ---------------- SUCCESS ----------------
                if ( $result['status'] === 'success' ) {

                    if ( ! empty( $result['snapshot_url'] ) ) {
                        $update_data['snapshot_url'] = $result['snapshot_url'];
                    }

                    $update_data['finished_at'] = current_time( 'Y-m-d H:i:s' );
                    $update_data['status']      = 'completed';

                    $this->logger->update( $log->id, $update_data );
                    $this->logger->resolve_pending_siblings( $job_id, $log->id, 'completed', $update_data['snapshot_url'] ?? null );

                    if (
                        $this->settings->get( 'check_link_health' ) &&
                        ! empty( $result['snapshot_url'] )
                    ) {
                        $this->schedule_health_check( $log->id );
                    }

                    return;
                }

                // ---------------- ERROR (E.g. already archived or duplicate submission block) ----------------
                if ( $result['status'] === 'error' ) {

                    // Transient rate-limit error: WM's queue is full. Don't mark as final error —
                    // reschedule with a 30-minute backoff and leave status as pending.
                    $error_msg = isset( $result['message'] ) ? $result['message'] : '';
                    if ( $this->is_rate_limit_error( $error_msg ) ) {
                        $update_data['error_message'] = $error_msg;
                        $this->logger->update( $log->id, $update_data );
                        $this->schedule_status_check( $job_id, 30 * MINUTE_IN_SECONDS );
                        return;
                    }

                    // FALLBACK: Query the Availability API. If a snapshot is found, save it and complete the job!
                    $fallback_url = $this->api->get_archive_url( $log->url );
                    if ( $fallback_url ) {
                        $update_data['status']       = 'completed_fallback';
                        $update_data['snapshot_url'] = $fallback_url;
                        $update_data['finished_at']   = current_time( 'Y-m-d H:i:s' );
                        $update_data['error_message'] = null;

                        $this->logger->update( $log->id, $update_data );
                        $this->logger->resolve_pending_siblings( $job_id, $log->id, 'completed_fallback', $fallback_url );

                        if ( $this->settings->get( 'check_link_health' ) ) {
                            $this->schedule_health_check( $log->id );
                        }
                        return;
                    }

                    $update_data['error_message'] = isset( $result['message'] )
                        ? $result['message']
                        : __( 'Archive failed', 'content-vault' );
                    $update_data['finished_at'] = current_time( 'Y-m-d H:i:s' );
                    $update_data['status']      = 'error';
                    $this->logger->update( $log->id, $update_data );
                    return;
                }

                // ---------------- STILL PROCESSING ----------------
                if ( in_array( $result['status'], array( 'pending', 'processing' ), true ) ) {
                    
                    // Proactive Check: If we've been processing for 3+ attempts, try the Availability API
                    if ( $log->attempts >= 3 ) {
                        $fallback_url = $this->api->get_archive_url( $log->url );
                        if ( $fallback_url ) {
                            $update_data['status']       = 'completed_fallback';
                            $update_data['snapshot_url'] = $fallback_url;
                            $update_data['finished_at']   = current_time( 'Y-m-d H:i:s' );
                            $update_data['error_message'] = null;

                            $this->logger->update( $log->id, $update_data );
                            $this->logger->resolve_pending_siblings( $job_id, $log->id, 'completed_fallback', $fallback_url );

                            if ( $this->settings->get( 'check_link_health' ) ) {
                                $this->schedule_health_check( $log->id );
                            }
                            return;
                        }
                    }

                    $this->logger->update( $log->id, $update_data );
                    $this->schedule_status_check( $job_id );
                    return;
                }
            }

            // ---------------- API FAILURE / HTTP TIMEOUT ----------------
            // Try Availability check as a fallback first before incrementing attempts with errors
            $fallback_url = $this->api->get_archive_url( $log->url );
            if ( $fallback_url ) {
                $update_data['status']       = 'completed_fallback';
                $update_data['snapshot_url'] = $fallback_url;
                $update_data['finished_at']   = current_time( 'Y-m-d H:i:s' );
                $update_data['error_message'] = null;

                $this->logger->update( $log->id, $update_data );
                $this->logger->resolve_pending_siblings( $job_id, $log->id, 'completed_fallback', $fallback_url );

                if ( $this->settings->get( 'check_link_health' ) ) {
                    $this->schedule_health_check( $log->id );
                }
                return;
            }

            $update_data['error_message'] = $result['error'] ?? 'Unknown error';

            $this->logger->update( $log->id, $update_data );

            $this->schedule_status_check( $job_id );
        }

        public function check_all_pending() {

            $pending = $this->logger->get_pending();

            foreach ( $pending as $log ) {

                if (
                    empty( $log->job_id ) ||
                    in_array( $log->status, array( 'success', 'completed', 'completed_fallback' ), true )
                ) {
                    continue;
                }

                $this->check_pending_job( $log->job_id );
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
                'last_checked'     => current_time( 'Y-m-d H:i:s' ),
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
