<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access layer for starters and splits.
 */
class SwimTiming_DB {

	public static function starters_table() {
		global $wpdb;
		return $wpdb->prefix . 'swimtiming_starters';
	}

	public static function splits_table() {
		global $wpdb;
		return $wpdb->prefix . 'swimtiming_splits';
	}

	/**
	 * Normalize a clock time (Meldezeit/Startzeit/Endzeit) into HH:MM, or null if empty.
	 */
	public static function normalize_clock_time( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}
		$raw = str_replace( '.', ':', $raw );
		$parts = explode( ':', $raw );
		$parts = array_map( 'trim', $parts );

		$h = isset( $parts[0] ) && '' !== $parts[0] ? (int) $parts[0] : 0;
		$m = isset( $parts[1] ) && '' !== $parts[1] ? (int) $parts[1] : 0;

		$h = max( 0, min( 23, $h ) );
		$m = max( 0, min( 59, $m ) );

		return sprintf( '%02d:%02d', $h, $m );
	}

	/**
	 * Normalize a duration/split time string into HH:MM:SS:mmm, or null if empty.
	 */
	public static function normalize_time( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}
		$raw = str_replace( '.', ':', $raw );
		$parts = explode( ':', $raw );
		$parts = array_map( 'trim', $parts );

		$h = isset( $parts[0] ) && '' !== $parts[0] ? (int) $parts[0] : 0;
		$m = isset( $parts[1] ) && '' !== $parts[1] ? (int) $parts[1] : 0;
		$s = isset( $parts[2] ) && '' !== $parts[2] ? (int) $parts[2] : 0;
		$ms = isset( $parts[3] ) && '' !== $parts[3] ? (int) $parts[3] : 0;

		$m = max( 0, min( 59, $m ) );
		$s = max( 0, min( 59, $s ) );
		$ms = max( 0, min( 999, $ms ) );
		$h = max( 0, $h );

		return sprintf( '%02d:%02d:%02d:%03d', $h, $m, $s, $ms );
	}

	public static function time_to_ms( $time ) {
		if ( empty( $time ) ) {
			return null;
		}
		$parts = explode( ':', $time );
		if ( count( $parts ) < 4 ) {
			return null;
		}
		list( $h, $m, $s, $ms ) = array_map( 'intval', $parts );
		return ( ( $h * 3600 + $m * 60 + $s ) * 1000 ) + $ms;
	}

	public static function insert_starter( $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		$wpdb->insert(
			self::starters_table(),
			array(
				'first_name'  => sanitize_text_field( $data['first_name'] ),
				'last_name'   => sanitize_text_field( $data['last_name'] ),
				'report_time' => self::normalize_time( $data['report_time'] ?? '' ),
				'start_time'  => self::normalize_clock_time( $data['start_time'] ?? '' ),
				'end_time'    => self::normalize_time( $data['end_time'] ?? '' ),
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public static function update_starter( $id, $data ) {
		global $wpdb;

		$fields = array( 'updated_at' => current_time( 'mysql' ) );
		$formats = array( '%s' );

		if ( isset( $data['first_name'] ) ) {
			$fields['first_name'] = sanitize_text_field( $data['first_name'] );
			$formats[] = '%s';
		}
		if ( isset( $data['last_name'] ) ) {
			$fields['last_name'] = sanitize_text_field( $data['last_name'] );
			$formats[] = '%s';
		}
		if ( array_key_exists( 'report_time', $data ) ) {
			$fields['report_time'] = self::normalize_time( $data['report_time'] );
			$formats[] = '%s';
		}
		if ( array_key_exists( 'start_time', $data ) ) {
			$fields['start_time'] = self::normalize_clock_time( $data['start_time'] );
			$formats[] = '%s';
		}
		if ( array_key_exists( 'end_time', $data ) ) {
			$fields['end_time'] = self::normalize_time( $data['end_time'] );
			$formats[] = '%s';
		}

		return $wpdb->update(
			self::starters_table(),
			$fields,
			array( 'id' => (int) $id ),
			$formats,
			array( '%d' )
		);
	}

	public static function delete_starter( $id ) {
		global $wpdb;
		$id = (int) $id;
		$wpdb->delete( self::splits_table(), array( 'starter_id' => $id ), array( '%d' ) );
		return $wpdb->delete( self::starters_table(), array( 'id' => $id ), array( '%d' ) );
	}

	public static function get_starter( $id ) {
		global $wpdb;
		$table = self::starters_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
	}

	public static function find_starter_by_identity( $first_name, $last_name, $start_time ) {
		global $wpdb;
		$table = self::starters_table();
		$start_time = self::normalize_clock_time( $start_time );

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE first_name = %s AND last_name = %s AND start_time = %s LIMIT 1",
				sanitize_text_field( $first_name ),
				sanitize_text_field( $last_name ),
				$start_time
			),
			ARRAY_A
		);
	}

	public static function get_starters( $args = array() ) {
		global $wpdb;
		$table = self::starters_table();

		$where = '1=1';
		$params = array();

		if ( ! empty( $args['search'] ) ) {
			$where .= ' AND (first_name LIKE %s OR last_name LIKE %s)';
			$like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY start_time ASC, last_name ASC";

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	public static function count_splits( $starter_id ) {
		global $wpdb;
		$table = self::splits_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE starter_id = %d", (int) $starter_id ) );
	}

	public static function insert_split( $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		$wpdb->insert(
			self::splits_table(),
			array(
				'starter_id'   => (int) $data['starter_id'],
				'split_number' => (int) $data['split_number'],
				'split_time'   => self::normalize_time( $data['split_time'] ?? '' ),
				'comment'      => isset( $data['comment'] ) ? sanitize_textarea_field( $data['comment'] ) : '',
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public static function update_split( $id, $data ) {
		global $wpdb;

		$fields = array( 'updated_at' => current_time( 'mysql' ) );
		$formats = array( '%s' );

		if ( array_key_exists( 'split_time', $data ) ) {
			$fields['split_time'] = self::normalize_time( $data['split_time'] );
			$formats[] = '%s';
		}
		if ( array_key_exists( 'comment', $data ) ) {
			$fields['comment'] = sanitize_textarea_field( $data['comment'] );
			$formats[] = '%s';
		}
		if ( array_key_exists( 'split_number', $data ) ) {
			$fields['split_number'] = (int) $data['split_number'];
			$formats[] = '%d';
		}

		return $wpdb->update(
			self::splits_table(),
			$fields,
			array( 'id' => (int) $id ),
			$formats,
			array( '%d' )
		);
	}

	public static function delete_split( $id ) {
		global $wpdb;
		return $wpdb->delete( self::splits_table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	public static function get_splits_for_starter( $starter_id ) {
		global $wpdb;
		$table = self::splits_table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE starter_id = %d ORDER BY split_number ASC", (int) $starter_id ),
			ARRAY_A
		);
	}

	public static function get_next_split_number( $starter_id ) {
		global $wpdb;
		$table = self::splits_table();
		$max = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(split_number) FROM {$table} WHERE starter_id = %d", (int) $starter_id ) );
		return $max ? ( (int) $max + 1 ) : 1;
	}

	public static function find_starter_by_name( $first_name, $last_name ) {
		global $wpdb;
		$table = self::starters_table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE first_name = %s AND last_name = %s ORDER BY id DESC LIMIT 1",
				sanitize_text_field( $first_name ),
				sanitize_text_field( $last_name )
			),
			ARRAY_A
		);
	}

	public static function delete_all_data() {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE " . self::splits_table() ); // phpcs:ignore -- table name from own prefix, not user input.
		$wpdb->query( "TRUNCATE TABLE " . self::starters_table() ); // phpcs:ignore -- table name from own prefix, not user input.
	}
}
