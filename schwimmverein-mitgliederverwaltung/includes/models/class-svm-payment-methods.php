<?php
/**
 * Zahlarten.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Zahlarten sind Stammdaten — Lastschrift, Überweisung, Bar und alles Weitere.
 */
class SVM_Payment_Methods {

	/**
	 * Alle Zahlarten.
	 *
	 * @param bool $only_active Nur aktive.
	 * @return array
	 */
	public static function all( $only_active = false ) {
		$where = $only_active ? 'is_active = 1' : '';
		return SVM_DB::all( 'payment_methods', $where, 'sort_order ASC, id ASC' );
	}

	/**
	 * Eine Zahlart.
	 *
	 * @param int $id Zahlart-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		return SVM_DB::get( 'payment_methods', $id );
	}

	/**
	 * Zahlart anhand des Schlüssels.
	 *
	 * @param string $key Schlüssel.
	 * @return array|null
	 */
	public static function get_by_key( $key ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . SVM_DB::table( 'payment_methods' ) . ' WHERE method_key = %s', $key ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $row ? $row : null;
	}

	/**
	 * Auswahlliste.
	 *
	 * @param bool $only_active Nur aktive.
	 * @return array
	 */
	public static function options( $only_active = true ) {
		$out = array();
		foreach ( self::all( $only_active ) as $method ) {
			$out[ (int) $method['id'] ] = $method['label'];
		}
		return $out;
	}

	/**
	 * Zahlarten, die eine Zahldatei erzeugen.
	 *
	 * @return array
	 */
	public static function file_based() {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'payment_methods' ) . ' WHERE behavior = %s AND is_active = 1',
				'file'
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Speichert eine Zahlart.
	 *
	 * @param array $data Daten.
	 * @return int
	 */
	public static function save( array $data ) {
		global $wpdb;

		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		unset( $data['id'] );

		$data = wp_parse_args(
			$data,
			array(
				'label'                 => '',
				'method_key'            => '',
				'behavior'              => 'manual',
				'requires_mandate'      => 0,
				'requires_bank_account' => 0,
				'is_default'            => 0,
				'allow_self_select'     => 0,
				'is_active'             => 1,
				'sort_order'            => 0,
			)
		);

		if ( '' === $data['method_key'] ) {
			$data['method_key'] = sanitize_key( $data['label'] );
		}

		if ( $id > 0 ) {
			SVM_DB::update( 'payment_methods', $id, $data );
		} else {
			$id = SVM_DB::insert( 'payment_methods', $data );
		}

		if ( ! empty( $data['is_default'] ) ) {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . SVM_DB::table( 'payment_methods' ) . ' SET is_default = 0 WHERE id <> %d',
					$id
				)
			); // phpcs:ignore WordPress.DB
		}

		return $id;
	}

	/**
	 * Löscht eine Zahlart, sofern nicht in Verwendung.
	 *
	 * @param int $id Zahlart-ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id    = (int) $id;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . SVM_DB::table( 'members' ) . ' WHERE payment_method_id = %d', $id )
		); // phpcs:ignore WordPress.DB

		if ( $count > 0 ) {
			return new WP_Error(
				'svm_method_in_use',
				sprintf(
					/* translators: %d: Anzahl Mitglieder. */
					__( 'Diese Zahlart ist %d Mitgliedern zugeordnet und kann nicht gelöscht werden.', 'svm' ),
					$count
				)
			);
		}

		SVM_DB::delete( 'payment_methods', $id );

		return true;
	}

	/**
	 * Verhaltensarten.
	 *
	 * @return array
	 */
	public static function behaviors() {
		return array(
			'file'   => __( 'Erzeugt Zahldatei (z. B. SEPA-Lastschrift)', 'svm' ),
			'manual' => __( 'Wird manuell erfasst (z. B. Überweisung, Bar)', 'svm' ),
			'import' => __( 'Wird über Kontoauszug importiert', 'svm' ),
		);
	}

	/**
	 * Ermittelt die wirksame Zahlart einer Forderung.
	 *
	 * @param array $invoice Forderung.
	 * @return array|null
	 */
	public static function for_invoice( array $invoice ) {
		if ( (int) $invoice['payment_method_id'] > 0 ) {
			return self::get( (int) $invoice['payment_method_id'] );
		}

		$member = SVM_Members::get( (int) $invoice['member_id'] );

		return $member ? self::get( (int) $member['payment_method_id'] ) : null;
	}
}
