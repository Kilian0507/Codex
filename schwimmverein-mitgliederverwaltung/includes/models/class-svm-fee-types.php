<?php
/**
 * Beitragsarten.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Beitragsarten sind Stammdaten mit angehängtem Bedingungsregelwerk.
 */
class SVM_Fee_Types {

	/**
	 * Alle Beitragsarten.
	 *
	 * @param bool $only_active Nur aktive.
	 * @return array
	 */
	public static function all( $only_active = false ) {
		$where = $only_active ? 'is_active = 1' : '';
		return SVM_DB::all( 'fee_types', $where, 'priority ASC, id ASC' );
	}

	/**
	 * Beitragsarten, die zum Stichtag gültig sind.
	 *
	 * @param string $date Stichtag (Y-m-d).
	 * @return array
	 */
	public static function valid_at( $date ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'fee_types' ) . '
				WHERE is_active = 1
				AND (valid_from IS NULL OR valid_from <= %s)
				AND (valid_to IS NULL OR valid_to >= %s)
				ORDER BY priority ASC, id ASC',
				$date,
				$date
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Eine Beitragsart.
	 *
	 * @param int $id Beitragsart-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		return SVM_DB::get( 'fee_types', $id );
	}

	/**
	 * Speichert eine Beitragsart.
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
				'label'           => '',
				'description'     => '',
				'account_code'    => '',
				'amount'          => 0,
				'amount_mode'     => 'fixed',
				'amount_field_id' => 0,
				'cycle'           => 'yearly',
				'due_rule'        => '',
				'proration'       => 'none',
				'valid_from'      => null,
				'valid_to'        => null,
				'priority'        => 10,
				'is_active'       => 1,
			)
		);

		if ( $id > 0 ) {
			SVM_DB::update( 'fee_types', $id, $data );
		} else {
			$id = SVM_DB::insert( 'fee_types', $data );
		}

		SVM_Audit::log( 'fee_type', $id, 'saved', 'label', '', $data['label'] );

		return $id;
	}

	/**
	 * Löscht eine Beitragsart samt Regeln.
	 *
	 * @param int $id Beitragsart-ID.
	 * @return void
	 */
	public static function delete( $id ) {
		$id = (int) $id;

		foreach ( SVM_Rules::for_owner( 'fee_condition', 'fee_type', $id ) as $rule ) {
			SVM_Rules::delete( (int) $rule['id'] );
		}

		SVM_DB::delete( 'fee_types', $id );
		SVM_Audit::log( 'fee_type', $id, 'deleted' );
	}

	/**
	 * Verfügbare Turnusse.
	 *
	 * @return array
	 */
	public static function cycles() {
		return array(
			'once'       => __( 'einmalig', 'svm' ),
			'monthly'    => __( 'monatlich', 'svm' ),
			'quarterly'  => __( 'vierteljährlich', 'svm' ),
			'halfyearly' => __( 'halbjährlich', 'svm' ),
			'yearly'     => __( 'jährlich', 'svm' ),
		);
	}

	/**
	 * Anzahl der Fälligkeiten pro Jahr.
	 *
	 * @param string $cycle Turnus.
	 * @return int
	 */
	public static function periods_per_year( $cycle ) {
		switch ( $cycle ) {
			case 'monthly':
				return 12;
			case 'quarterly':
				return 4;
			case 'halfyearly':
				return 2;
			case 'yearly':
				return 1;
		}

		return 1;
	}

	/**
	 * Verfügbare Anteilsberechnungen.
	 *
	 * @return array
	 */
	public static function prorations() {
		return array(
			'none'    => __( 'keine (voller Betrag)', 'svm' ),
			'monthly' => __( 'monatsgenau', 'svm' ),
			'daily'   => __( 'taggenau', 'svm' ),
		);
	}

	/**
	 * Bedingungsregel einer Beitragsart (es gibt genau eine je Beitragsart).
	 *
	 * @param int $fee_type_id Beitragsart-ID.
	 * @return array|null
	 */
	public static function condition_rule( $fee_type_id ) {
		$rules = SVM_Rules::for_owner( 'fee_condition', 'fee_type', $fee_type_id );
		return empty( $rules ) ? null : $rules[0];
	}
}
