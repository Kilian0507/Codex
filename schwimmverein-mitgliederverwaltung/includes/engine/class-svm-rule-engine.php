<?php
/**
 * Auswertung frei konfigurierter Bedingungen.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dieselbe Mechanik trägt Beitragsbedingungen, Nachrichten-Zielgruppen und Exportfilter.
 */
class SVM_Rule_Engine {

	/**
	 * Verfügbare Bedingungssubjekte.
	 *
	 * @return array
	 */
	public static function subjects() {
		return apply_filters(
			'svm_rule_subjects',
			array(
				'field'           => __( 'Feldwert', 'svm' ),
				'status'          => __( 'Mitgliedsstatus', 'svm' ),
				'unit'            => __( 'Sparte/Gruppe', 'svm' ),
				'age'             => __( 'Alter', 'svm' ),
				'unit_count'      => __( 'Anzahl Mitgliedschaften', 'svm' ),
				'family_position' => __( 'Position im Familienverbund', 'svm' ),
				'open_invoices'   => __( 'Offene Forderungen', 'svm' ),
				'member_since'    => __( 'Mitglied seit (Jahre)', 'svm' ),
			)
		);
	}

	/**
	 * Verfügbare Operatoren.
	 *
	 * @return array
	 */
	public static function operators() {
		return apply_filters(
			'svm_rule_operators',
			array(
				'equals'       => __( 'ist gleich', 'svm' ),
				'not_equals'   => __( 'ist ungleich', 'svm' ),
				'contains'     => __( 'enthält', 'svm' ),
				'not_contains' => __( 'enthält nicht', 'svm' ),
				'in'           => __( 'ist eines von (kommagetrennt)', 'svm' ),
				'not_in'       => __( 'ist keines von (kommagetrennt)', 'svm' ),
				'gt'           => __( 'ist größer als', 'svm' ),
				'gte'          => __( 'ist größer oder gleich', 'svm' ),
				'lt'           => __( 'ist kleiner als', 'svm' ),
				'lte'          => __( 'ist kleiner oder gleich', 'svm' ),
				'between'      => __( 'liegt zwischen', 'svm' ),
				'is_empty'     => __( 'ist leer', 'svm' ),
				'not_empty'    => __( 'ist nicht leer', 'svm' ),
				'is_true'      => __( 'ist ja/wahr', 'svm' ),
				'is_false'     => __( 'ist nein/falsch', 'svm' ),
			)
		);
	}

	/**
	 * Stichtage für Altersregeln.
	 *
	 * @return array
	 */
	public static function reference_dates() {
		return array(
			'year_start' => __( 'Jahresbeginn', 'svm' ),
			'today'      => __( 'heute', 'svm' ),
			'period'     => __( 'Beginn des Abrechnungszeitraums', 'svm' ),
			'joined'     => __( 'Eintrittsdatum', 'svm' ),
		);
	}

	/**
	 * Wertet eine Regel gegen einen Mitgliedskontext aus.
	 *
	 * @param array $rule    Regel inkl. logic.
	 * @param array $context Mitgliedskontext aus SVM_Members::context().
	 * @return bool
	 */
	public static function matches( array $rule, array $context ) {
		$conditions = SVM_Rules::conditions( (int) $rule['id'] );

		if ( empty( $conditions ) ) {
			return true;
		}

		$logic = strtoupper( isset( $rule['logic'] ) ? $rule['logic'] : 'AND' );

		foreach ( $conditions as $condition ) {
			$result = self::evaluate_condition( $condition, $context );

			if ( 'OR' === $logic && $result ) {
				return true;
			}

			if ( 'AND' === $logic && ! $result ) {
				return false;
			}
		}

		return 'AND' === $logic;
	}

	/**
	 * Wertet eine einzelne Bedingung aus.
	 *
	 * @param array $condition Bedingung.
	 * @param array $context   Mitgliedskontext.
	 * @return bool
	 */
	public static function evaluate_condition( array $condition, array $context ) {
		$subject = $condition['subject'];
		$actual  = self::resolve_subject( $condition, $context );

		return self::compare( $actual, $condition, $subject );
	}

	/**
	 * Ermittelt den Ist-Wert einer Bedingung.
	 *
	 * @param array $condition Bedingung.
	 * @param array $context   Mitgliedskontext.
	 * @return mixed
	 */
	private static function resolve_subject( array $condition, array $context ) {
		switch ( $condition['subject'] ) {
			case 'field':
				$def = SVM_Fields::get_def( (int) $condition['field_id'] );
				if ( ! $def ) {
					return null;
				}
				return isset( $context['fields'][ $def['field_key'] ] ) ? $context['fields'][ $def['field_key'] ] : null;

			case 'status':
				return (int) $context['status_id'];

			case 'unit':
				return $context['unit_ids'];

			case 'age':
				return self::age_for_reference( $condition, $context );

			case 'unit_count':
				return (int) $context['unit_count'];

			case 'family_position':
				return (int) $context['family_position'];

			case 'open_invoices':
				return SVM_Invoices::open_total( (int) $context['member_id'] );

			case 'member_since':
				$member = $context['member'];
				if ( empty( $member['joined_at'] ) ) {
					return null;
				}
				$joined = strtotime( $member['joined_at'] );
				if ( ! $joined ) {
					return null;
				}
				return (int) floor( ( time() - $joined ) / YEAR_IN_SECONDS );
		}

		return apply_filters( 'svm_rule_resolve_subject', null, $condition, $context );
	}

	/**
	 * Alter zum konfigurierten Stichtag.
	 *
	 * @param array $condition Bedingung.
	 * @param array $context   Mitgliedskontext.
	 * @return int|null
	 */
	private static function age_for_reference( array $condition, array $context ) {
		switch ( $condition['reference_date'] ) {
			case 'today':
				$date = gmdate( 'Y-m-d' );
				break;

			case 'period':
				$date = $context['reference_date'];
				break;

			case 'joined':
				$date = ! empty( $context['member']['joined_at'] ) ? $context['member']['joined_at'] : gmdate( 'Y-m-d' );
				break;

			case 'year_start':
			default:
				$year = substr( (string) $context['reference_date'], 0, 4 );
				$date = ( $year ? $year : gmdate( 'Y' ) ) . '-01-01';
				break;
		}

		return SVM_Members::age( (int) $context['member_id'], $date );
	}

	/**
	 * Vergleicht Ist-Wert und Sollwert.
	 *
	 * @param mixed  $actual    Ist-Wert.
	 * @param array  $condition Bedingung.
	 * @param string $subject   Subjekt.
	 * @return bool
	 */
	private static function compare( $actual, array $condition, $subject ) {
		$operator = $condition['operator'];
		$expected = $condition['compare_value'];

		// Mehrwertige Subjekte (Einheitenzuordnung) gesondert behandeln.
		if ( 'unit' === $subject ) {
			$units    = is_array( $actual ) ? array_map( 'intval', $actual ) : array();
			$expected_ids = array_map( 'intval', self::split_list( $expected ) );

			switch ( $operator ) {
				case 'not_equals':
				case 'not_in':
				case 'not_contains':
					return empty( array_intersect( $units, $expected_ids ) );
				case 'is_empty':
					return empty( $units );
				case 'not_empty':
					return ! empty( $units );
				default:
					return ! empty( array_intersect( $units, $expected_ids ) );
			}
		}

		switch ( $operator ) {
			case 'is_empty':
				return ( null === $actual || '' === $actual || array() === $actual );

			case 'not_empty':
				return ! ( null === $actual || '' === $actual || array() === $actual );

			case 'is_true':
				return ! empty( $actual ) && '0' !== (string) $actual;

			case 'is_false':
				return empty( $actual ) || '0' === (string) $actual;
		}

		if ( null === $actual ) {
			return false;
		}

		switch ( $operator ) {
			case 'equals':
				return self::loose_equals( $actual, $expected );

			case 'not_equals':
				return ! self::loose_equals( $actual, $expected );

			case 'contains':
				return false !== stripos( (string) $actual, (string) $expected );

			case 'not_contains':
				return false === stripos( (string) $actual, (string) $expected );

			case 'in':
				foreach ( self::split_list( $expected ) as $item ) {
					if ( self::loose_equals( $actual, $item ) ) {
						return true;
					}
				}
				return false;

			case 'not_in':
				foreach ( self::split_list( $expected ) as $item ) {
					if ( self::loose_equals( $actual, $item ) ) {
						return false;
					}
				}
				return true;

			case 'gt':
				return self::to_comparable( $actual ) > self::to_comparable( $expected );

			case 'gte':
				return self::to_comparable( $actual ) >= self::to_comparable( $expected );

			case 'lt':
				return self::to_comparable( $actual ) < self::to_comparable( $expected );

			case 'lte':
				return self::to_comparable( $actual ) <= self::to_comparable( $expected );

			case 'between':
				$value = self::to_comparable( $actual );
				return $value >= self::to_comparable( $expected ) && $value <= self::to_comparable( $condition['compare_value_2'] );
		}

		return false;
	}

	/**
	 * Vergleicht zwei Werte typtolerant.
	 *
	 * @param mixed $a Erster Wert.
	 * @param mixed $b Zweiter Wert.
	 * @return bool
	 */
	private static function loose_equals( $a, $b ) {
		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			return abs( (float) $a - (float) $b ) < 0.00001;
		}

		return 0 === strcasecmp( trim( (string) $a ), trim( (string) $b ) );
	}

	/**
	 * Wandelt Werte für Größenvergleiche um (Datum als Zeitstempel).
	 *
	 * @param mixed $value Wert.
	 * @return float
	 */
	private static function to_comparable( $value ) {
		if ( is_numeric( $value ) ) {
			return (float) $value;
		}

		$value = (string) $value;

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $value ) ) {
			$timestamp = strtotime( $value );
			return $timestamp ? (float) $timestamp : 0.0;
		}

		return SVM_Fields::to_number( $value );
	}

	/**
	 * Zerlegt eine kommagetrennte Liste.
	 *
	 * @param string $value Liste.
	 * @return array
	 */
	private static function split_list( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}

		$parts = explode( ',', (string) $value );

		return array_values(
			array_filter(
				array_map( 'trim', $parts ),
				static function ( $item ) {
					return '' !== $item;
				}
			)
		);
	}
}
