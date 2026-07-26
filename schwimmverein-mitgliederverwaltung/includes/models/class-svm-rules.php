<?php
/**
 * Regeln und Bedingungen.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Regeln tragen Beitragsbedingungen, Rabatte, Nachrichten-Zielgruppen und Exportfilter.
 */
class SVM_Rules {

	/**
	 * Regeln eines Besitzers.
	 *
	 * @param string $rule_type  Regelart.
	 * @param string $owner_type Besitzertyp.
	 * @param int    $owner_id   Besitzer-ID.
	 * @return array
	 */
	public static function for_owner( $rule_type, $owner_type = '', $owner_id = 0 ) {
		global $wpdb;

		$sql    = 'SELECT * FROM ' . SVM_DB::table( 'rules' ) . ' WHERE rule_type = %s';
		$params = array( $rule_type );

		if ( '' !== $owner_type ) {
			$sql     .= ' AND owner_type = %s';
			$params[] = $owner_type;
		}

		if ( $owner_id > 0 ) {
			$sql     .= ' AND owner_id = %d';
			$params[] = (int) $owner_id;
		}

		$sql .= ' ORDER BY priority ASC, id ASC';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Aktive Regeln einer Art.
	 *
	 * @param string $rule_type Regelart.
	 * @return array
	 */
	public static function active( $rule_type ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'rules' ) . ' WHERE rule_type = %s AND is_active = 1 ORDER BY priority ASC, id ASC',
				$rule_type
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Eine Regel.
	 *
	 * @param int $id Regel-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		return SVM_DB::get( 'rules', $id );
	}

	/**
	 * Speichert eine Regel samt Bedingungen.
	 *
	 * @param array $data       Regeldaten.
	 * @param array $conditions Bedingungen.
	 * @return int
	 */
	public static function save( array $data, array $conditions = null ) {
		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		unset( $data['id'] );

		$data = wp_parse_args(
			$data,
			array(
				'rule_type'        => 'fee_condition',
				'owner_type'       => '',
				'owner_id'         => 0,
				'label'            => '',
				'logic'            => 'AND',
				'adjustment_type'  => 'none',
				'adjustment_value' => 0,
				'applies_to'       => 'member',
				'stackable'        => 1,
				'priority'         => 10,
				'is_active'        => 1,
			)
		);

		if ( $id > 0 ) {
			SVM_DB::update( 'rules', $id, $data );
		} else {
			$id = SVM_DB::insert( 'rules', $data );
		}

		if ( null !== $conditions ) {
			self::set_conditions( $id, $conditions );
		}

		return $id;
	}

	/**
	 * Löscht eine Regel samt Bedingungen.
	 *
	 * @param int $id Regel-ID.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id = (int) $id;
		$wpdb->delete( SVM_DB::table( 'rule_conditions' ), array( 'rule_id' => $id ) ); // phpcs:ignore WordPress.DB
		SVM_DB::delete( 'rules', $id );
	}

	/**
	 * Bedingungen einer Regel.
	 *
	 * @param int $rule_id Regel-ID.
	 * @return array
	 */
	public static function conditions( $rule_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'rule_conditions' ) . ' WHERE rule_id = %d ORDER BY sort_order ASC, id ASC',
				(int) $rule_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Ersetzt die Bedingungen einer Regel.
	 *
	 * @param int   $rule_id    Regel-ID.
	 * @param array $conditions Bedingungen.
	 * @return void
	 */
	public static function set_conditions( $rule_id, array $conditions ) {
		global $wpdb;

		$rule_id = (int) $rule_id;
		$wpdb->delete( SVM_DB::table( 'rule_conditions' ), array( 'rule_id' => $rule_id ) ); // phpcs:ignore WordPress.DB

		$sort = 0;
		foreach ( $conditions as $condition ) {
			if ( empty( $condition['subject'] ) ) {
				continue;
			}

			$compare = isset( $condition['compare_value'] ) ? $condition['compare_value'] : '';
			if ( is_array( $compare ) ) {
				$compare = implode( ',', $compare );
			}

			SVM_DB::insert(
				'rule_conditions',
				array(
					'rule_id'         => $rule_id,
					'subject'         => sanitize_key( $condition['subject'] ),
					'field_id'        => isset( $condition['field_id'] ) ? (int) $condition['field_id'] : 0,
					'operator'        => isset( $condition['operator'] ) ? sanitize_key( $condition['operator'] ) : 'equals',
					'compare_value'   => $compare,
					'compare_value_2' => isset( $condition['compare_value_2'] ) ? $condition['compare_value_2'] : '',
					'reference_date'  => isset( $condition['reference_date'] ) ? sanitize_key( $condition['reference_date'] ) : 'year_start',
					'sort_order'      => $sort,
				)
			);

			$sort++;
		}
	}

	/**
	 * Beschreibt eine Bedingung in Klartext (für Protokolle und Oberfläche).
	 *
	 * @param array $condition Bedingung.
	 * @return string
	 */
	public static function describe_condition( array $condition ) {
		$subjects  = SVM_Rule_Engine::subjects();
		$operators = SVM_Rule_Engine::operators();

		$subject = isset( $subjects[ $condition['subject'] ] ) ? $subjects[ $condition['subject'] ] : $condition['subject'];

		if ( 'field' === $condition['subject'] ) {
			$def = SVM_Fields::get_def( (int) $condition['field_id'] );
			if ( $def ) {
				$subject = $def['label'];
			}
		}

		$operator = isset( $operators[ $condition['operator'] ] ) ? $operators[ $condition['operator'] ] : $condition['operator'];
		$value    = (string) $condition['compare_value'];

		if ( 'unit' === $condition['subject'] ) {
			$names = array();
			foreach ( explode( ',', $value ) as $unit_id ) {
				$name = SVM_Units::name( (int) trim( $unit_id ) );
				if ( '' !== $name ) {
					$names[] = $name;
				}
			}
			$value = implode( ', ', $names );
		}

		if ( 'status' === $condition['subject'] ) {
			$status = SVM_Statuses::get( (int) $value );
			$value  = $status ? $status['label'] : $value;
		}

		if ( 'between' === $condition['operator'] ) {
			$value .= ' – ' . $condition['compare_value_2'];
		}

		if ( in_array( $condition['operator'], array( 'is_empty', 'not_empty', 'is_true', 'is_false' ), true ) ) {
			return trim( $subject . ' ' . $operator );
		}

		return trim( $subject . ' ' . $operator . ' ' . $value );
	}

	/**
	 * Beschreibt eine ganze Regel.
	 *
	 * @param array $rule Regel.
	 * @return string
	 */
	public static function describe( array $rule ) {
		$parts = array();

		foreach ( self::conditions( (int) $rule['id'] ) as $condition ) {
			$parts[] = self::describe_condition( $condition );
		}

		if ( empty( $parts ) ) {
			return __( 'ohne Einschränkung', 'svm' );
		}

		$glue = ( 'OR' === strtoupper( $rule['logic'] ) ) ? __( ' ODER ', 'svm' ) : __( ' UND ', 'svm' );

		return implode( $glue, $parts );
	}

	/**
	 * Arten von Betragsanpassungen.
	 *
	 * @return array
	 */
	public static function adjustment_types() {
		return array(
			'none'          => __( '— keine —', 'svm' ),
			'discount_abs'  => __( 'Rabatt in Euro', 'svm' ),
			'discount_pct'  => __( 'Rabatt in Prozent', 'svm' ),
			'surcharge_abs' => __( 'Zuschlag in Euro', 'svm' ),
			'surcharge_pct' => __( 'Zuschlag in Prozent', 'svm' ),
			'set_amount'    => __( 'Betrag festsetzen auf', 'svm' ),
			'cap_member'    => __( 'Höchstbetrag je Mitglied', 'svm' ),
			'cap_family'    => __( 'Höchstbetrag je Familienverbund', 'svm' ),
		);
	}
}
