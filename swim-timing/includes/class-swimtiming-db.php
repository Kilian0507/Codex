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
	 * Normalize a clock time (Startzeit) into HH:MM, or null if empty.
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
	 * Normalize a duration (Meldezeit/Endzeit/Zwischenzeit) into MM:SS:CS
	 * (Minute:Sekunde:Hundertstel), or null if empty.
	 *
	 * The Stoppuhr-Tippfeld always displays 00:00:00 by default, so an
	 * all-zero value is indistinguishable from "nothing entered" - it is
	 * therefore treated as empty (null), not as a real zero duration.
	 */
	public static function normalize_time( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}
		$raw = str_replace( '.', ':', $raw );
		$parts = explode( ':', $raw );
		$parts = array_map( 'trim', $parts );

		$m = isset( $parts[0] ) && '' !== $parts[0] ? (int) $parts[0] : 0;
		$s = isset( $parts[1] ) && '' !== $parts[1] ? (int) $parts[1] : 0;
		$cs = isset( $parts[2] ) && '' !== $parts[2] ? (int) $parts[2] : 0;

		$s = max( 0, min( 59, $s ) );
		$cs = max( 0, min( 99, $cs ) );
		$m = max( 0, $m );

		if ( 0 === $m && 0 === $s && 0 === $cs ) {
			return null;
		}

		return sprintf( '%02d:%02d:%02d', $m, $s, $cs );
	}

	public static function time_to_cs( $time ) {
		if ( empty( $time ) ) {
			return null;
		}
		$parts = explode( ':', $time );
		if ( count( $parts ) < 3 ) {
			return null;
		}
		list( $m, $s, $cs ) = array_map( 'intval', $parts );
		return ( ( $m * 60 + $s ) * 100 ) + $cs;
	}

	/**
	 * Whitelist a Staffel/team value.
	 */
	public static function sanitize_team( $raw ) {
		$raw = strtolower( trim( (string) $raw ) );
		if ( in_array( $raw, array( 'rot', 'gelb' ), true ) ) {
			return $raw;
		}
		return '';
	}

	/**
	 * Add a duration (MM:SS:CS) plus a fixed 1-minute changeover buffer to
	 * a clock time (HH:MM), rounding to the nearest minute. Used to derive
	 * the next relay swimmer's start time: Startzeit(nächster) =
	 * Startzeit(vorherige) + Endzeit(vorherige) + 1 Minute.
	 *
	 * The event runs overnight (e.g. starts 17:50, continues past
	 * midnight), so this wraps around at 24h instead of capping at
	 * 23:59 - a swimmer starting at 23:50 with a 20-minute leg correctly
	 * hands off at 00:11, not a clamped 23:59.
	 */
	public static function add_duration_to_clock( $clock, $duration ) {
		if ( empty( $clock ) ) {
			return $clock;
		}
		$cparts = explode( ':', $clock );
		$total_minutes = ( isset( $cparts[0] ) ? (int) $cparts[0] : 0 ) * 60 + ( isset( $cparts[1] ) ? (int) $cparts[1] : 0 );

		if ( ! empty( $duration ) ) {
			$dparts = explode( ':', $duration );
			$dm = isset( $dparts[0] ) ? (int) $dparts[0] : 0;
			$ds = isset( $dparts[1] ) ? (int) $dparts[1] : 0;
			$total_minutes += $dm + ( $ds >= 30 ? 1 : 0 ) + 1;
		}

		$total_minutes = ( ( $total_minutes % 1440 ) + 1440 ) % 1440;
		return sprintf( '%02d:%02d', (int) ( $total_minutes / 60 ), $total_minutes % 60 );
	}

	/**
	 * Sort key for an overnight-event clock time (HH:MM): times before
	 * noon are treated as "the next day" (event started in the
	 * afternoon/evening and runs past midnight), so 00:10 sorts after
	 * 23:50, not before 17:50.
	 */
	public static function clock_sort_key( $clock ) {
		if ( empty( $clock ) ) {
			return PHP_INT_MAX;
		}
		$parts = explode( ':', $clock );
		$h = isset( $parts[0] ) ? (int) $parts[0] : 0;
		$m = isset( $parts[1] ) ? (int) $parts[1] : 0;
		$minutes = $h * 60 + $m;
		if ( $h < 12 ) {
			$minutes += 1440;
		}
		return $minutes;
	}

	public static function insert_starter( $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		$wpdb->insert(
			self::starters_table(),
			array(
				'first_name'  => sanitize_text_field( $data['first_name'] ),
				'last_name'   => sanitize_text_field( $data['last_name'] ),
				'team'        => self::sanitize_team( $data['team'] ?? '' ),
				'report_time' => self::normalize_time( $data['report_time'] ?? '' ),
				'start_time'  => self::normalize_clock_time( $data['start_time'] ?? '' ),
				'end_time'    => self::normalize_time( $data['end_time'] ?? '' ),
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
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
		if ( array_key_exists( 'team', $data ) ) {
			$fields['team'] = self::sanitize_team( $data['team'] );
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

		$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY last_name ASC";

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		// Sortiert per PHP statt SQL, weil die Veranstaltung über Nacht geht:
		// Startzeiten nach Mitternacht (z. B. 00:10) müssen NACH den
		// Startzeiten vor Mitternacht (z. B. 23:50) einsortiert werden.
		usort( $rows, function ( $a, $b ) {
			$key_a = self::clock_sort_key( $a['start_time'] );
			$key_b = self::clock_sort_key( $b['start_time'] );
			if ( $key_a === $key_b ) {
				return strcmp( $a['last_name'], $b['last_name'] );
			}
			return $key_a <=> $key_b;
		} );

		return $rows;
	}

	/**
	 * Search starters by name for the public, unauthenticated entry mask
	 * (typeahead: type a name, pick the matching starter from the list).
	 */
	public static function search_starters( $query, $limit = 8 ) {
		global $wpdb;
		$table = self::starters_table();
		$query = trim( (string) $query );
		if ( '' === $query ) {
			return array();
		}
		$like = '%' . $wpdb->esc_like( $query ) . '%';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, first_name, last_name, team FROM {$table}
				WHERE first_name LIKE %s OR last_name LIKE %s OR CONCAT(first_name, ' ', last_name) LIKE %s
				ORDER BY last_name ASC, first_name ASC
				LIMIT %d",
				$like,
				$like,
				$like,
				max( 1, (int) $limit )
			),
			ARRAY_A
		);
	}

	/**
	 * Public, read-only schedule of start times (no login required).
	 */
	public static function get_public_schedule() {
		global $wpdb;
		$table = self::starters_table();
		$rows = $wpdb->get_results(
			"SELECT first_name, last_name, team, start_time FROM {$table}
			WHERE start_time IS NOT NULL AND start_time <> ''
			ORDER BY team ASC, last_name ASC",
			ARRAY_A
		);

		// Sortiert per PHP statt SQL: die Veranstaltung geht über Nacht, daher
		// müssen Startzeiten nach Mitternacht hinter denen vor Mitternacht
		// stehen (17:50 -> ... -> 23:59 -> 00:00 -> ... ), siehe get_starters().
		usort( $rows, function ( $a, $b ) {
			if ( $a['team'] !== $b['team'] ) {
				return strcmp( $a['team'], $b['team'] );
			}
			$key_a = self::clock_sort_key( $a['start_time'] );
			$key_b = self::clock_sort_key( $b['start_time'] );
			if ( $key_a === $key_b ) {
				return strcmp( $a['last_name'], $b['last_name'] );
			}
			return $key_a <=> $key_b;
		} );

		return $rows;
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

	/**
	 * Find the next swimmer in the same Staffel (relay team), i.e. the one
	 * with the next-larger Meldezeit (report_time is used as the seed time
	 * that fixes the swim order within a team).
	 */
	public static function get_next_in_team( $team, $report_time, $exclude_id ) {
		global $wpdb;
		if ( empty( $team ) || empty( $report_time ) ) {
			return null;
		}
		$table = self::starters_table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE team = %s AND id != %d AND report_time > %s ORDER BY report_time ASC LIMIT 1",
				$team,
				(int) $exclude_id,
				$report_time
			),
			ARRAY_A
		);
	}

	/**
	 * Recomputes the next swimmer's Startzeit in the same Staffel from
	 * this swimmer's own Startzeit + Endzeit + 1 Minute Wechselzeit.
	 * Must be called whenever either this swimmer's Startzeit OR Endzeit
	 * changes - both feed into the formula - and cascades down the whole
	 * relay chain.
	 */
	public static function cascade_after_end_time_change( $starter_id, $depth = 0 ) {
		if ( $depth > 50 ) {
			return; // Safety net against accidental cycles.
		}
		$starter = self::get_starter( $starter_id );
		if ( ! $starter || empty( $starter['team'] ) || empty( $starter['end_time'] ) || empty( $starter['start_time'] ) ) {
			return;
		}

		$next = self::get_next_in_team( $starter['team'], $starter['report_time'], $starter['id'] );
		if ( ! $next ) {
			return;
		}

		$new_start = self::add_duration_to_clock( $starter['start_time'], $starter['end_time'] );
		self::update_starter( $next['id'], array( 'start_time' => $new_start ) );

		self::cascade_after_end_time_change( $next['id'], $depth + 1 );
	}

	public static function delete_all_data() {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE " . self::splits_table() ); // phpcs:ignore -- table name from own prefix, not user input.
		$wpdb->query( "TRUNCATE TABLE " . self::starters_table() ); // phpcs:ignore -- table name from own prefix, not user input.
	}
}
