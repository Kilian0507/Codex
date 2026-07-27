<?php
/**
 * Strukturbaum: Sparten, Gruppen und weitere Einheiten.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Die Anzahl und Benennung der Ebenen ist frei konfigurierbar.
 */
class SVM_Units {

	/**
	 * Alle Einheiten.
	 *
	 * @param bool $only_active Nur aktive.
	 * @return array
	 */
	public static function all( $only_active = false ) {
		$where = $only_active ? 'is_active = 1' : '';
		return SVM_DB::all( 'units', $where, 'sort_order ASC, name ASC' );
	}

	/**
	 * Eine Einheit.
	 *
	 * @param int $id Einheiten-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		return SVM_DB::get( 'units', $id );
	}

	/**
	 * Speichert eine Einheit.
	 *
	 * @param array $data Daten.
	 * @return int
	 */
	public static function save( array $data ) {
		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		unset( $data['id'] );

		$data = wp_parse_args(
			$data,
			array(
				'parent_id'      => 0,
				'unit_type_id'   => 0,
				'name'           => '',
				'short_name'     => '',
				'leader_user_id' => 0,
				'is_active'      => 1,
				'sort_order'     => 0,
			)
		);

		if ( $id > 0 ) {
			// Zyklen im Baum verhindern.
			if ( (int) $data['parent_id'] === $id || in_array( (int) $data['parent_id'], self::descendants( $id ), true ) ) {
				$data['parent_id'] = 0;
			}
			SVM_DB::update( 'units', $id, $data );
		} else {
			$data['created_at'] = SVM_DB::now();
			$id                 = SVM_DB::insert( 'units', $data );
		}

		SVM_Audit::log( 'unit', $id, $id ? 'saved' : 'created' );

		return $id;
	}

	/**
	 * Löscht eine Einheit; Untereinheiten rücken eine Ebene nach oben.
	 *
	 * @param int $id Einheiten-ID.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id   = (int) $id;
		$unit = self::get( $id );
		if ( ! $unit ) {
			return;
		}

		$wpdb->update( SVM_DB::table( 'units' ), array( 'parent_id' => (int) $unit['parent_id'] ), array( 'parent_id' => $id ) ); // phpcs:ignore WordPress.DB
		$wpdb->delete( SVM_DB::table( 'member_units' ), array( 'unit_id' => $id ) ); // phpcs:ignore WordPress.DB
		$wpdb->delete( SVM_DB::table( 'user_units' ), array( 'unit_id' => $id ) ); // phpcs:ignore WordPress.DB
		SVM_DB::delete( 'units', $id );

		SVM_Audit::log( 'unit', $id, 'deleted' );
	}

	/**
	 * Direkte Untereinheiten.
	 *
	 * @param int $parent_id Übergeordnete Einheit.
	 * @return array
	 */
	public static function children( $parent_id ) {
		$out = array();
		foreach ( self::all() as $unit ) {
			if ( (int) $unit['parent_id'] === (int) $parent_id ) {
				$out[] = $unit;
			}
		}
		return $out;
	}

	/**
	 * Alle Nachfahren einer Einheit.
	 *
	 * @param int $id Einheiten-ID.
	 * @return int[]
	 */
	public static function descendants( $id ) {
		$out   = array();
		$queue = array( (int) $id );

		while ( ! empty( $queue ) ) {
			$current = array_shift( $queue );
			foreach ( self::children( $current ) as $child ) {
				$child_id = (int) $child['id'];
				if ( ! in_array( $child_id, $out, true ) ) {
					$out[]   = $child_id;
					$queue[] = $child_id;
				}
			}
		}

		return $out;
	}

	/**
	 * Einheiten inklusive aller Nachfahren.
	 *
	 * @param int[] $ids Ausgangs-IDs.
	 * @return int[]
	 */
	public static function with_descendants( array $ids ) {
		$out = array_map( 'intval', $ids );

		foreach ( $ids as $id ) {
			$out = array_merge( $out, self::descendants( (int) $id ) );
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Auswahlliste mit Einrückung nach Hierarchieebene.
	 *
	 * @param bool $only_active Nur aktive.
	 * @return array id => Label
	 */
	public static function options( $only_active = false ) {
		$units = self::all( $only_active );
		$by_parent = array();

		foreach ( $units as $unit ) {
			$by_parent[ (int) $unit['parent_id'] ][] = $unit;
		}

		$out = array();
		self::build_options( $by_parent, 0, 0, $out );

		return $out;
	}

	/**
	 * Rekursiver Aufbau der Auswahlliste.
	 *
	 * @param array $by_parent Einheiten gruppiert nach Elternteil.
	 * @param int   $parent_id Aktueller Elternteil.
	 * @param int   $depth     Ebene.
	 * @param array $out       Ergebnis.
	 * @return void
	 */
	private static function build_options( array $by_parent, $parent_id, $depth, array &$out ) {
		if ( empty( $by_parent[ $parent_id ] ) || $depth > 10 ) {
			return;
		}

		foreach ( $by_parent[ $parent_id ] as $unit ) {
			$out[ (int) $unit['id'] ] = str_repeat( '— ', $depth ) . $unit['name'];
			self::build_options( $by_parent, (int) $unit['id'], $depth + 1, $out );
		}
	}

	/**
	 * Name einer Einheit.
	 *
	 * @param int $id Einheiten-ID.
	 * @return string
	 */
	public static function name( $id ) {
		$unit = self::get( $id );
		return $unit ? $unit['name'] : '';
	}

	/* ---------------------------------------------------------------------
	 * Einheitstypen
	 * ------------------------------------------------------------------ */

	/**
	 * Alle Einheitstypen.
	 *
	 * @return array
	 */
	public static function types() {
		return SVM_DB::all( 'unit_types', '', 'sort_order ASC, id ASC' );
	}

	/**
	 * Einheitstypen als Auswahlliste.
	 *
	 * @return array
	 */
	public static function type_options() {
		$out = array();
		foreach ( self::types() as $type ) {
			$out[ (int) $type['id'] ] = $type['label'];
		}
		return $out;
	}

	/**
	 * Speichert einen Einheitstyp.
	 *
	 * @param array $data Daten.
	 * @return int
	 */
	public static function save_type( array $data ) {
		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		unset( $data['id'] );

		$data = wp_parse_args(
			$data,
			array(
				'type_key'   => '',
				'label'      => '',
				'sort_order' => 0,
			)
		);

		if ( '' === $data['type_key'] ) {
			$data['type_key'] = sanitize_key( $data['label'] );
		}

		if ( $id > 0 ) {
			SVM_DB::update( 'unit_types', $id, $data );
			return $id;
		}

		return SVM_DB::insert( 'unit_types', $data );
	}

	/**
	 * Löscht einen Einheitstyp. Die Einheiten selbst bleiben bestehen und
	 * stehen danach ohne Ebene da.
	 *
	 * @param int $id Typ-ID.
	 * @return void
	 */
	public static function delete_type( $id ) {
		global $wpdb;

		$id = (int) $id;

		$wpdb->update( SVM_DB::table( 'units' ), array( 'unit_type_id' => 0 ), array( 'unit_type_id' => $id ) ); // phpcs:ignore WordPress.DB
		SVM_DB::delete( 'unit_types', $id );

		SVM_Audit::log( 'unit_type', $id, 'deleted' );
	}

	/**
	 * Mitgliederzahl einer Einheit.
	 *
	 * @param int $unit_id Einheiten-ID.
	 * @return int
	 */
	public static function member_count( $unit_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT mu.member_id) FROM ' . SVM_DB::table( 'member_units' ) . ' mu
				INNER JOIN ' . SVM_DB::table( 'members' ) . ' m ON m.id = mu.member_id
				WHERE mu.unit_id = %d AND mu.is_active = 1 AND m.deleted_at IS NULL',
				(int) $unit_id
			)
		); // phpcs:ignore WordPress.DB
	}
}
