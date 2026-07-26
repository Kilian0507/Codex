<?php
/**
 * Nummernkreise für Mitgliedsnummern und Mandatsreferenzen.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Aufbau der Nummern ist frei konfigurierbar.
 */
class SVM_Number_Range {

	/**
	 * Alle Nummernkreise.
	 *
	 * @return array
	 */
	public static function all() {
		return SVM_DB::all( 'number_ranges', '', 'id ASC' );
	}

	/**
	 * Nummernkreis anhand des Schlüssels.
	 *
	 * @param string $key Schlüssel.
	 * @return array|null
	 */
	public static function get( $key ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . SVM_DB::table( 'number_ranges' ) . ' WHERE range_key = %s', $key ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB
		return $row ? $row : null;
	}

	/**
	 * Speichert einen Nummernkreis.
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
				'range_key'  => '',
				'label'      => '',
				'prefix'     => '',
				'use_year'   => 0,
				'digits'     => 5,
				'next_value' => 1,
			)
		);

		if ( $id > 0 ) {
			SVM_DB::update( 'number_ranges', $id, $data );
			return $id;
		}

		return SVM_DB::insert( 'number_ranges', $data );
	}

	/**
	 * Erzeugt die nächste Nummer und zählt den Kreis hoch.
	 *
	 * @param string $key Schlüssel des Nummernkreises.
	 * @return string
	 */
	public static function next( $key ) {
		global $wpdb;

		$range = self::get( $key );

		if ( ! $range ) {
			$id    = self::save(
				array(
					'range_key'  => $key,
					'label'      => $key,
					'digits'     => 5,
					'next_value' => 1,
				)
			);
			$range = SVM_DB::get( 'number_ranges', $id );
		}

		$value = (int) $range['next_value'];

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . SVM_DB::table( 'number_ranges' ) . ' SET next_value = next_value + 1 WHERE id = %d',
				(int) $range['id']
			)
		); // phpcs:ignore WordPress.DB

		return self::render( $range, $value );
	}

	/**
	 * Setzt eine Nummer aus der Konfiguration zusammen.
	 *
	 * @param array $range Nummernkreis.
	 * @param int   $value Laufende Nummer.
	 * @return string
	 */
	public static function render( array $range, $value ) {
		$parts = array();

		if ( '' !== $range['prefix'] ) {
			$parts[] = $range['prefix'];
		}

		if ( $range['use_year'] ) {
			$parts[] = gmdate( 'Y' );
		}

		$digits  = max( 1, (int) $range['digits'] );
		$parts[] = str_pad( (string) $value, $digits, '0', STR_PAD_LEFT );

		return implode( '-', $parts );
	}
}
