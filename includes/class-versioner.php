<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WebClyde_Content_Vault_Versioner' ) ) {

	class WebClyde_Content_Vault_Versioner {

		private string $table_name;

		public function __construct() {
			global $wpdb;
			$this->table_name = $wpdb->prefix . WEBCLYDE_CONTENT_VAULT_VERSIONS_TABLE;
		}

		/**
		 * SHA-256 hash of title + content + excerpt.
		 * Used to detect meaningful content changes before creating a WM archive.
		 */
		public function compute_hash( WP_Post $post ): string {
			return hash( 'sha256', $post->post_title . '||' . $post->post_content . '||' . $post->post_excerpt );
		}

		/**
		 * Save a local snapshot of the current post state.
		 *
		 * @return int|false Inserted version ID or false on failure.
		 */
		public function save( int $post_id, WP_Post $post, string $hash ): int|false {
			global $wpdb;

			$word_count = str_word_count( wp_strip_all_tags( $post->post_content ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->insert(
				$this->table_name,
				array(
					'post_id'      => $post_id,
					'post_type'    => $post->post_type,
					'title'        => $post->post_title,
					'content'      => $post->post_content,
					'excerpt'      => $post->post_excerpt,
					'word_count'   => $word_count,
					'content_hash' => $hash,
					'created_at'   => current_time( 'Y-m-d H:i:s' ),
				)
			);

			return $result ? $wpdb->insert_id : false;
		}

		/**
		 * Get the content hash of the most recently saved local version for a post.
		 */
		public function get_latest_hash( int $post_id ): ?string {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT content_hash FROM {$this->table_name} WHERE post_id = %d ORDER BY id DESC LIMIT 1",
					$post_id
				)
			);
		}

		/**
		 * Get a single version by ID.
		 */
		public function get( int $id ): ?object {
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

		/**
		 * Get all versions for a specific post (most recent first).
		 */
		public function get_for_post( int $post_id, int $limit = 20 ): array {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$this->table_name} WHERE post_id = %d ORDER BY id DESC LIMIT %d",
					$post_id,
					$limit
				)
			);
		}

		/**
		 * Get paginated versions across all posts for the admin Versions page.
		 */
		public function get_all( array $args = array() ): array {
			global $wpdb;

			$defaults = array(
				'post_type' => '',
				'per_page'  => 20,
				'page'      => 1,
			);
			$args = wp_parse_args( $args, $defaults );

			$where  = array( '1=1' );
			$values = array();

			if ( ! empty( $args['post_type'] ) ) {
				$where[]  = 'post_type = %s';
				$values[] = $args['post_type'];
			}

			$where_clause = implode( ' AND ', $where );
			$offset       = (int) ( ( $args['page'] - 1 ) * $args['per_page'] );
			$per_page     = (int) $args['per_page'];
			$table        = esc_sql( $this->table_name );

			$query_values = array_merge( $values, array( $per_page, $offset ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY id DESC LIMIT %d OFFSET %d", $query_values ) );
		}

		/**
		 * Get total count of versions for pagination.
		 */
		public function get_total( array $args = array() ): int {
			global $wpdb;

			$where  = array( '1=1' );
			$values = array();

			if ( ! empty( $args['post_type'] ) ) {
				$where[]  = 'post_type = %s';
				$values[] = $args['post_type'];
			}

			$where_clause = implode( ' AND ', $where );
			$table        = esc_sql( $this->table_name );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}", $values ) );
		}

		/**
		 * Delete a single version row.
		 */
		public function delete( int $id ): bool {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (bool) $wpdb->delete( $this->table_name, array( 'id' => $id ) );
		}

		/**
		 * Bulk delete version rows by ID.
		 */
		public function delete_bulk( array $ids ): bool {
			global $wpdb;

			if ( empty( $ids ) ) {
				return false;
			}

			$ids          = array_map( 'intval', $ids );
			$placeholders = implode( ',', $ids );
			$table        = esc_sql( $this->table_name );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (bool) $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$placeholders})" );
		}
	}
}
