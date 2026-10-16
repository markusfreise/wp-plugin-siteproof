<?php
/**
 * Storage: one audit run = one row in {prefix}siteproof_runs,
 * raw data per page and step in {prefix}siteproof_pages.
 *
 * @package Siteproof
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database access.
 */
class Siteproof_DB {

	/** Steps per page, one column each (written independently, no read-modify-write races). */
	const STEPS = array( 'server', 'browser', 'psi', 'review' );

	/**
	 * Table names.
	 *
	 * @return string[]
	 */
	public static function tables() {
		global $wpdb;
		return array(
			'runs'  => $wpdb->prefix . 'siteproof_runs',
			'pages' => $wpdb->prefix . 'siteproof_pages',
		);
	}

	/**
	 * Create or update tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::tables();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$t['runs']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created datetime NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'running',
				settings longtext NULL,
				data longtext NULL,
				summary longtext NULL,
				PRIMARY KEY  (id)
			) $charset;"
		);
		dbDelta(
			"CREATE TABLE {$t['pages']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id bigint(20) unsigned NOT NULL,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				position int(11) NOT NULL DEFAULT 0,
				url text NOT NULL,
				title text NULL,
				server longtext NULL,
				browser longtext NULL,
				psi longtext NULL,
				review longtext NULL,
				PRIMARY KEY  (id),
				KEY run_post (run_id,post_id)
			) $charset;"
		);
		update_option( 'siteproof_db', SITEPROOF_DB, false );
	}

	/**
	 * Update tables after plugin updates.
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( 'siteproof_db' ) !== SITEPROOF_DB ) {
			self::install();
		}
	}

	/**
	 * Decode a JSON column.
	 *
	 * @param string|null $json Column value.
	 * @return array|null
	 */
	public static function decode( $json ) {
		if ( null === $json || '' === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Encode for a JSON column.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	public static function encode( $data ) {
		return (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
	}

	/**
	 * Create a run with its page list.
	 *
	 * @param array $settings Run settings.
	 * @param array $data     Run data (site info).
	 * @param array $pages    Pages: post_id, url, title.
	 * @return int Run ID.
	 */
	public static function create_run( array $settings, array $data, array $pages ) {
		global $wpdb;
		$t = self::tables();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
			$t['runs'],
			array(
				'created'  => current_time( 'mysql', true ),
				'status'   => 'running',
				'settings' => self::encode( $settings ),
				'data'     => self::encode( $data ),
			)
		);
		$run_id = (int) $wpdb->insert_id;
		foreach ( array_values( $pages ) as $i => $p ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
				$t['pages'],
				array(
					'run_id'   => $run_id,
					'post_id'  => (int) $p['post_id'],
					'position' => $i,
					'url'      => $p['url'],
					'title'    => $p['title'],
				)
			);
		}
		self::touch();
		return $run_id;
	}

	/**
	 * Read a run (JSON columns decoded).
	 *
	 * @param int $id Run ID.
	 * @return array|null
	 */
	public static function get_run( $id ) {
		global $wpdb;
		$t   = self::tables();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $t['runs'], $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
		if ( ! $row ) {
			return null;
		}
		foreach ( array( 'settings', 'data', 'summary' ) as $col ) {
			$row[ $col ] = self::decode( $row[ $col ] ) ?? array();
		}
		$row['id'] = (int) $row['id'];
		return $row;
	}

	/**
	 * All runs (without raw data), newest first.
	 *
	 * @return array[]
	 */
	public static function list_runs() {
		global $wpdb;
		$t    = self::tables();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT r.id, r.created, r.status, r.settings, r.summary, (SELECT COUNT(*) FROM %i p WHERE p.run_id = r.id) AS pages FROM %i r ORDER BY r.id DESC', $t['pages'], $t['runs'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
		foreach ( $rows as &$row ) {
			$row['id']       = (int) $row['id'];
			$row['settings'] = self::decode( $row['settings'] ) ?? array();
			$row['summary']  = self::decode( $row['summary'] ) ?? array();
		}
		return $rows;
	}

	/**
	 * Number of stored runs.
	 *
	 * @return int
	 */
	public static function count_runs() {
		global $wpdb;
		$t = self::tables();
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t['runs'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
	}

	/**
	 * Update run columns.
	 *
	 * @param int   $id     Run ID.
	 * @param array $fields status|data|summary|settings.
	 */
	public static function update_run( $id, array $fields ) {
		global $wpdb;
		$t = self::tables();
		foreach ( array( 'settings', 'data', 'summary' ) as $col ) {
			if ( array_key_exists( $col, $fields ) ) {
				$fields[ $col ] = self::encode( $fields[ $col ] );
			}
		}
		$wpdb->update( $t['runs'], $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		if ( isset( $fields['status'] ) ) {
			self::touch();
		}
	}

	/**
	 * Pages of a run.
	 *
	 * @param int  $run_id Run ID.
	 * @param bool $full   Include raw data.
	 * @return array[]
	 */
	public static function get_pages( $run_id, $full = true ) {
		global $wpdb;
		$t    = self::tables();
		if ( $full ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %d ORDER BY position', $t['pages'], $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, post_id, url, title, server IS NOT NULL AS has_server, browser IS NOT NULL AS has_browser, psi IS NOT NULL AS has_psi, review IS NOT NULL AS has_review FROM %i WHERE run_id = %d ORDER BY position', $t['pages'], $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
		}
		foreach ( $rows as &$row ) {
			$row['post_id'] = (int) $row['post_id'];
			if ( $full ) {
				foreach ( self::STEPS as $step ) {
					$row[ $step ] = self::decode( $row[ $step ] );
				}
			}
		}
		return $rows;
	}

	/**
	 * One page of a run.
	 *
	 * @param int $run_id  Run ID.
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function get_page( $run_id, $post_id ) {
		global $wpdb;
		$t   = self::tables();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %d AND post_id = %d', $t['pages'], $run_id, $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
		if ( ! $row ) {
			return null;
		}
		$row['post_id'] = (int) $row['post_id'];
		foreach ( self::STEPS as $step ) {
			$row[ $step ] = self::decode( $row[ $step ] );
		}
		return $row;
	}

	/**
	 * Store the result of one step for one page.
	 *
	 * @param int    $run_id  Run ID.
	 * @param int    $post_id Post ID.
	 * @param string $step    server|browser|psi|review.
	 * @param array  $data    Result.
	 */
	public static function save_step( $run_id, $post_id, $step, array $data ) {
		global $wpdb;
		if ( ! in_array( $step, self::STEPS, true ) ) {
			return;
		}
		$t = self::tables();
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
			$t['pages'],
			array( $step => self::encode( $data ) ),
			array(
				'run_id'  => $run_id,
				'post_id' => $post_id,
			)
		);
	}

	/**
	 * Delete a run and its pages.
	 *
	 * @param int $id Run ID.
	 */
	public static function delete_run( $id ) {
		global $wpdb;
		$t = self::tables();
		$wpdb->delete( $t['pages'], array( 'run_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->delete( $t['runs'], array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		self::touch();
	}

	/**
	 * Delete all runs.
	 */
	public static function delete_all() {
		global $wpdb;
		$t = self::tables();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $t['pages'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $t['runs'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
		self::touch();
	}

	/**
	 * Most recent finished run before a given run (for the comparison).
	 *
	 * @param int $before Run ID.
	 * @return array|null
	 */
	public static function previous_done( $before ) {
		global $wpdb;
		$t  = self::tables();
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE status = 'done' AND id < %d ORDER BY id DESC LIMIT 1", $t['runs'], $before ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
		return $id ? self::get_run( (int) $id ) : null;
	}

	/**
	 * Mark that the stored runs changed (re-shows the dashboard warning).
	 */
	public static function touch() {
		update_option( 'siteproof_state', wp_generate_password( 12, false ), false );
	}
}
