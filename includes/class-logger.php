<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WebClyde_Content_Vault_Logger' ) ) {
    class WebClyde_Content_Vault_Logger {
        private $table_name;

        public function __construct() {
            global $wpdb;
            $this->table_name = $wpdb->prefix . WEBCLYDE_CONTENT_VAULT_TABLE_NAME;
        }

        public function create( $data ) {
            global $wpdb;

            $defaults = array(
                'post_id'         => 0,
                'post_type'       => 'post',
                'url'             => '',
                'job_id'          => null,
                'status'          => 'pending',
                'snapshot_url'    => null,
                'error_message'   => null,
                'attempts'        => 0,
                'link_health'     => 'unknown',
                'link_health_code'=> null,
                'last_checked'    => null,
                'created_at'      => current_time('mysql'),
                'updated_at'      => current_time('mysql'),
            );

            $data = wp_parse_args( $data, $defaults );
            $result = $wpdb->insert( $this->table_name, $data );

            return $result ? $wpdb->insert_id : false;
        }

        public function update( $id, $data ) {
            global $wpdb;
            $data['updated_at'] = current_time('mysql');
            return $wpdb->update( $this->table_name, $data, array( 'id' => $id ) );
        }

        public function get( $id ) {
            global $wpdb;
            return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE id = %d", $id ) );
        }

        public function get_by_job_id( $job_id ) {
            global $wpdb;
            return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE job_id = %s", $job_id ) );
        }

        public function get_all( $args = array() ) {
            global $wpdb;

            $defaults = array(
                'status'   => '',
                'post_type'=> '',
                'per_page' => 20,
                'page'     => 1,
                'orderby'  => 'created_at',
                'order'    => 'DESC',
            );

            $args = wp_parse_args( $args, $defaults );

            $where = array( '1=1' );
            $values = array();

            if ( ! empty( $args['status'] ) ) {
                $where[] = 'status = %s';
                $values[] = $args['status'];
            }

            if ( ! empty( $args['post_type'] ) ) {
                $where[] = 'post_type = %s';
                $values[] = $args['post_type'];
            }

            $where_clause = implode( ' AND ', $where );
            $orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
            $offset = ( $args['page'] - 1 ) * $args['per_page'];

            $sql = "SELECT * FROM {$this->table_name} WHERE {$where_clause} ORDER BY {$orderby} LIMIT %d OFFSET %d";
            $values[] = $args['per_page'];
            $values[] = $offset;

            if ( ! empty( $values ) ) {
                $sql = $wpdb->prepare( $sql, $values );
            }

            return $wpdb->get_results( $sql );
        }

        public function get_total( $args = array() ) {
            global $wpdb;

            $where = array( '1=1' );
            $values = array();

            if ( ! empty( $args['status'] ) ) {
                $where[] = 'status = %s';
                $values[] = $args['status'];
            }

            if ( ! empty( $args['post_type'] ) ) {
                $where[] = 'post_type = %s';
                $values[] = $args['post_type'];
            }

            $where_clause = implode( ' AND ', $where );
            $sql = "SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_clause}";

            if ( ! empty( $values ) ) {
                $sql = $wpdb->prepare( $sql, $values );
            }

            return (int) $wpdb->get_var( $sql );
        }

        public function get_pending() {
            global $wpdb;
            return $wpdb->get_results( "SELECT * FROM {$this->table_name} WHERE status IN ('pending', 'processing') ORDER BY created_at ASC" );
        }

        public function delete( $id ) {
            global $wpdb;
            return $wpdb->delete( $this->table_name, array( 'id' => $id ) );
        }

        public function delete_bulk( $ids ) {
            global $wpdb;
            $ids = array_map( 'intval', $ids );
            $ids_string = implode( ',', $ids );
            return $wpdb->query( "DELETE FROM {$this->table_name} WHERE id IN ({$ids_string})" );
        }

        public function get_stats( $post_type = '' ) {
            global $wpdb;

            $where = '';
            if ( ! empty( $post_type ) ) {
                $where = $wpdb->prepare( " WHERE post_type = %s", $post_type );
            }

            $results = $wpdb->get_results( "SELECT status, COUNT(*) as count FROM {$this->table_name} {$where} GROUP BY status" );

            $stats = array(
                'total'      => 0,
                'pending'    => 0,
                'processing' => 0,
                'success'    => 0,
                'error'      => 0,
            );

            foreach ( $results as $row ) {
                $stats[ $row->status ] = (int) $row->count;
                $stats['total'] += (int) $row->count;
            }

            $health_results = $wpdb->get_results( "SELECT link_health, COUNT(*) as count FROM {$this->table_name} {$where} GROUP BY link_health" );
            $stats['healthy']   = 0;
            $stats['unhealthy'] = 0;
            $stats['unknown']   = 0;

            foreach ( $health_results as $row ) {
                $stats[ $row->link_health ] = (int) $row->count;
            }

            if ( empty( $post_type ) ) {
                $type_results = $wpdb->get_results( "SELECT post_type, COUNT(*) as count FROM {$this->table_name} GROUP BY post_type" );
                $stats['posts'] = 0;
                $stats['pages'] = 0;

                foreach ( $type_results as $row ) {
                    if ( $row->post_type === 'post' ) {
                        $stats['posts'] = (int) $row->count;
                    } elseif ( $row->post_type === 'page' ) {
                        $stats['pages'] = (int) $row->count;
                    }
                }
            }

            return $stats;
        }
    }
}
