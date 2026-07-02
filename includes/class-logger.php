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
				'post_id'          => 0,
				'post_type'        => 'post',
				'url'              => '',
				'job_id'           => null,
				'status'           => 'pending',
				'snapshot_url'     => null,
				'content_hash'     => null,
				'error_message'    => null,
				'attempts'         => 0,
				'link_health'      => 'unknown',
				'link_health_code' => null,
				'last_checked'     => null,
				'created_at'       => current_time( 'Y-m-d H:i:s' ),
				'updated_at'       => current_time( 'Y-m-d H:i:s' ),
			);
			$data   = wp_parse_args( $data, $defaults );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->insert( $this->table_name, $data );
			return $result ? $wpdb->insert_id : false;
		}

		public function update( $id, $data ) {
			global $wpdb;
			$data['updated_at'] = current_time( 'Y-m-d H:i:s' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->update(
				$this->table_name,
				$data,
				array( 'id' => $id )
			);
		}

		public function get( $id ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$this->table_name} WHERE id = %d",
					$id
				)
			);
		}

		public function get_by_job_id( string $job_id ): ?object {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$this->table_name} WHERE job_id = %s ORDER BY id DESC LIMIT 1",
					$job_id
				)
			);
		}

		public function get_by_post_id( int $post_id ): ?object {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$this->table_name} WHERE post_id = %d ORDER BY id DESC LIMIT 1",
					$post_id
				)
			);
		}

		/**
		 * Get the content hash stored on the most recent successfully completed
		 * WM archive for a given post. Used to skip re-archiving identical content.
		 */
		public function get_last_archived_hash( int $post_id ): ?string {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT content_hash FROM {$this->table_name}
					 WHERE post_id = %d AND status IN ('completed','completed_fallback')
					 AND content_hash IS NOT NULL
					 ORDER BY id DESC LIMIT 1",
					$post_id
				)
			);
		}

		public function get_all( $args = array() ) {
			global $wpdb;

			$defaults = array(
				'status'      => '',
				'post_type'   => '',
				'link_health' => '',
				'job_id'      => '',
				'per_page'    => 20,
				'page'        => 1,
				'orderby'     => 'created_at',
				'order'       => 'DESC',
			);
			$args = wp_parse_args( $args, $defaults );

			$where  = array( '1=1' );
			$values = array();

			if ( ! empty( $args['status'] ) ) {
				if ( is_array( $args['status'] ) ) {
					$placeholders = implode( ',', array_fill( 0, count( $args['status'] ), '%s' ) );
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$where[] = "status IN ($placeholders)";
					$values  = array_merge( $values, $args['status'] );
				} else {
					$where[]  = 'status = %s';
					$values[] = $args['status'];
				}
			}

			if ( ! empty( $args['post_type'] ) ) {
				$where[]  = 'post_type = %s';
				$values[] = $args['post_type'];
			}

			if ( ! empty( $args['link_health'] ) ) {
				$where[]  = 'link_health = %s';
				$values[] = $args['link_health'];
			}

			if ( ! empty( $args['job_id'] ) ) {
				$where[]  = 'job_id LIKE %s';
				$values[] = '%' . $wpdb->esc_like( $args['job_id'] ) . '%';
			}

			$where_clause = implode( ' AND ', $where );

			$orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
			if ( ! $orderby ) {
				$orderby = 'created_at DESC';
			}

			$offset   = (int) ( ( $args['page'] - 1 ) * $args['per_page'] );
			$per_page = (int) $args['per_page'];

			$table = esc_sql( $this->table_name );

			// Build values array: first the WHERE clause values, then LIMIT and OFFSET
			$query_values = array_merge( $values, array( $per_page, $offset ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} LIMIT %d OFFSET %d", $query_values ) );
		}

		public function get_total( $args = array() ) {
			global $wpdb;

			$where  = array( '1=1' );
			$values = array();

			if ( ! empty( $args['status'] ) ) {
				if ( is_array( $args['status'] ) ) {
					$placeholders = implode( ',', array_fill( 0, count( $args['status'] ), '%s' ) );
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$where[] = "status IN ($placeholders)";
					$values  = array_merge( $values, $args['status'] );
				} else {
					$where[]  = 'status = %s';
					$values[] = $args['status'];
				}
			}

			if ( ! empty( $args['post_type'] ) ) {
				$where[]  = 'post_type = %s';
				$values[] = $args['post_type'];
			}

			if ( ! empty( $args['link_health'] ) ) {
				$where[]  = 'link_health = %s';
				$values[] = $args['link_health'];
			}

			if ( ! empty( $args['job_id'] ) ) {
				$where[]  = 'job_id LIKE %s';
				$values[] = '%' . $wpdb->esc_like( $args['job_id'] ) . '%';
			}

			$where_clause = implode( ' AND ', $where );

			$table = esc_sql( $this->table_name );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}", $values ) );
		}

		/**
		 * Bulk-resolves all other pending/processing rows that share $job_id.
		 * Called after the primary row is updated to a terminal state so sibling
		 * rows from earlier submissions don't stay stuck as pending forever.
		 *
		 * @param string      $job_id     Wayback Machine job ID.
		 * @param int         $primary_id ID of the row already updated (excluded from this query).
		 * @param string      $status     Terminal status to apply ('completed' or 'completed_fallback').
		 * @param string|null $snapshot_url Snapshot URL to save on siblings.
		 */
		public function resolve_pending_siblings( string $job_id, int $primary_id, string $status, ?string $snapshot_url ): void {
			global $wpdb;
			$now = current_time( 'Y-m-d H:i:s' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"UPDATE {$this->table_name} SET status = %s, snapshot_url = %s, last_checked = %s, updated_at = %s WHERE job_id = %s AND id != %d AND status IN ('pending', 'processing')",
					$status,
					$snapshot_url,
					$now,
					$now,
					$job_id,
					$primary_id
				)
			);
		}

		public function get_pending() {
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table_name}
				 WHERE status IN ('pending', 'processing')
				 AND job_id IS NOT NULL
				 AND job_id != ''
				 ORDER BY created_at ASC"
			);
		}

		public function delete( $id ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->delete(
				$this->table_name,
				array( 'id' => $id )
			);
		}

		public function delete_bulk( $ids ) {
			global $wpdb;

			if ( empty( $ids ) || ! is_array( $ids ) ) {
				return false;
			}

			// All IDs are cast to integers — no user string input reaches the query.
			$ids          = array_map( 'intval', $ids );
			$placeholders = implode( ',', $ids ); // safe: all values are intval()'d integers
			$table        = esc_sql( $this->table_name );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$placeholders})" );
		}

		public function get_stats( $post_type = '' ) {
			global $wpdb;

			$table = esc_sql( $this->table_name );

			$stats = array(
				'total'              => 0,
				'pending'            => 0,
				'processing'         => 0,
				'success'            => 0,
				'error'              => 0,
				'completed'          => 0,
				'completed_fallback' => 0,
			);

			// Get status counts
			if ( ! empty( $post_type ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$results = $wpdb->get_results(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						"SELECT status, COUNT(*) as count FROM {$table} WHERE post_type = %s GROUP BY status",
						$post_type
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$results = $wpdb->get_results(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT status, COUNT(*) as count FROM {$table} GROUP BY status"
				);
			}

			foreach ( $results as $row ) {
				if ( isset( $stats[ $row->status ] ) ) {
					$stats[ $row->status ] = (int) $row->count;
					$stats['total']       += (int) $row->count;
				}
			}

			$stats['healthy']   = 0;
			$stats['unhealthy'] = 0;
			$stats['unknown']   = 0;

			// Get link_health counts
			if ( ! empty( $post_type ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$health_results = $wpdb->get_results(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						"SELECT link_health, COUNT(*) as count FROM {$table} WHERE post_type = %s GROUP BY link_health",
						$post_type
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$health_results = $wpdb->get_results(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT link_health, COUNT(*) as count FROM {$table} GROUP BY link_health"
				);
			}

			foreach ( $health_results as $row ) {
				if ( isset( $stats[ $row->link_health ] ) ) {
					$stats[ $row->link_health ] = (int) $row->count;
				}
			}

			// Get post type counts
			if ( empty( $post_type ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$type_results = $wpdb->get_results(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT post_type, COUNT(*) as count FROM {$table} GROUP BY post_type"
				);

				$stats['posts'] = 0;
				$stats['pages'] = 0;

				foreach ( $type_results as $row ) {
					if ( 'post' === $row->post_type ) {
						$stats['posts'] = (int) $row->count;
					}
					if ( 'page' === $row->post_type ) {
						$stats['pages'] = (int) $row->count;
					}
				}
			}

			return $stats;
		}
	}
}