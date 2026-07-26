<?php
/**
 * Beitragsberechnung.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wertet das Beitragsregelwerk für ein Mitglied aus und protokolliert jeden Schritt.
 */
class SVM_Fee_Calculator {

	/**
	 * Berechnet alle Beitragspositionen eines Mitglieds für einen Zeitraum.
	 *
	 * @param int    $member_id    Mitglieds-ID.
	 * @param string $period_start Beginn (Y-m-d).
	 * @param string $period_end   Ende (Y-m-d).
	 * @param array  $fee_types    Optionale Vorauswahl an Beitragsarten.
	 * @return array lines, total, skipped
	 */
	public static function calculate_for_member( $member_id, $period_start, $period_end, array $fee_types = null ) {
		$result = array(
			'member_id' => (int) $member_id,
			'lines'     => array(),
			'total'     => 0.0,
			'skipped'   => array(),
		);

		$context = SVM_Members::context( $member_id, $period_start );
		if ( ! $context ) {
			return $result;
		}

		$status = SVM_Statuses::get( (int) $context['status_id'] );
		if ( $status && ! $status['is_billable'] ) {
			$result['skipped'][] = sprintf(
				/* translators: %s: Statusbezeichnung. */
				__( 'Status „%s“ ist nicht beitragspflichtig.', 'svm' ),
				$status['label']
			);
			return $result;
		}

		$candidates = null !== $fee_types ? $fee_types : SVM_Fee_Types::valid_at( $period_start );

		foreach ( $candidates as $fee_type ) {
			$rule = SVM_Fee_Types::condition_rule( (int) $fee_type['id'] );

			if ( $rule && ! SVM_Rule_Engine::matches( $rule, $context ) ) {
				continue;
			}

			foreach ( self::periods_in_range( $fee_type, $period_start, $period_end ) as $period ) {
				$line = self::build_line( $fee_type, $period, $context, $rule );
				if ( $line ) {
					$result['lines'][] = $line;
				}
			}
		}

		$result = self::apply_member_adjustments( $result, $context );

		foreach ( $result['lines'] as $line ) {
			$result['total'] += (float) $line['amount'];
		}

		$result['total'] = round( $result['total'], 2 );

		return $result;
	}

	/**
	 * Baut eine einzelne Beitragsposition.
	 *
	 * @param array      $fee_type Beitragsart.
	 * @param array      $period   Zeitraum (start, end, due).
	 * @param array      $context  Mitgliedskontext.
	 * @param array|null $rule     Bedingungsregel.
	 * @return array|null
	 */
	private static function build_line( array $fee_type, array $period, array $context, $rule ) {
		$log = array();

		if ( $rule ) {
			$log[] = sprintf(
				/* translators: %s: Bedingungsbeschreibung. */
				__( 'Bedingung erfüllt: %s', 'svm' ),
				SVM_Rules::describe( $rule )
			);
		} else {
			$log[] = __( 'Beitragsart ohne Einschränkung — gilt für alle beitragspflichtigen Mitglieder.', 'svm' );
		}

		$base = self::base_amount( $fee_type, $context, $log );

		if ( null === $base ) {
			return null;
		}

		$amount = $base;

		$factor = self::proration_factor( $fee_type, $period, $context );
		if ( $factor < 1.0 ) {
			$amount = $amount * $factor;
			$log[]  = sprintf(
				/* translators: 1: Prozentwert, 2: Berechnungsart. */
				__( 'Anteilige Berechnung: %1$s %% (%2$s)', 'svm' ),
				number_format_i18n( $factor * 100, 1 ),
				$fee_type['proration']
			);
		}

		$amount = self::apply_line_adjustments( $amount, $fee_type, $context, $log );
		$amount = round( $amount, 2 );

		if ( $amount <= 0 ) {
			$log[] = __( 'Ergebnis 0,00 € — es wird keine Forderung erzeugt.', 'svm' );
			if ( $amount < 0 ) {
				$amount = 0.0;
			}
		}

		return array(
			'fee_type_id'  => (int) $fee_type['id'],
			'label'        => $fee_type['label'],
			'description'  => self::describe_period( $fee_type, $period ),
			'period_start' => $period['start'],
			'period_end'   => $period['end'],
			'due_date'     => $period['due'],
			'base_amount'  => round( $base, 2 ),
			'amount'       => $amount,
			'log'          => $log,
		);
	}

	/**
	 * Ermittelt den Ausgangsbetrag.
	 *
	 * @param array $fee_type Beitragsart.
	 * @param array $context  Mitgliedskontext.
	 * @param array $log      Protokoll (per Referenz).
	 * @return float|null
	 */
	private static function base_amount( array $fee_type, array $context, array &$log ) {
		switch ( $fee_type['amount_mode'] ) {
			case 'field':
				$def = SVM_Fields::get_def( (int) $fee_type['amount_field_id'] );
				if ( ! $def ) {
					$log[] = __( 'Betragsfeld nicht gefunden — Position übersprungen.', 'svm' );
					return null;
				}
				$value  = isset( $context['fields'][ $def['field_key'] ] ) ? $context['fields'][ $def['field_key'] ] : null;
				$amount = SVM_Fields::to_number( $value );
				$log[]  = sprintf(
					/* translators: 1: Feldbezeichnung, 2: Betrag. */
					__( 'Betrag aus Feld „%1$s“: %2$s €', 'svm' ),
					$def['label'],
					number_format_i18n( $amount, 2 )
				);
				return $amount;

			case 'formula':
				$vars = $context['fields'];
				$vars['alter']            = null !== $context['age'] ? $context['age'] : 0;
				$vars['age']              = $vars['alter'];
				$vars['anzahl_sparten']   = $context['unit_count'];
				$vars['familienposition'] = $context['family_position'];

				$amount = SVM_Formula::evaluate( $fee_type['amount'], $vars );

				if ( is_wp_error( $amount ) ) {
					$log[] = sprintf(
						/* translators: %s: Fehlermeldung. */
						__( 'Formel fehlerhaft (%s) — Position übersprungen.', 'svm' ),
						$amount->get_error_message()
					);
					return null;
				}

				$log[] = sprintf(
					/* translators: 1: Formel, 2: Ergebnis. */
					__( 'Formel „%1$s“ ergibt %2$s €', 'svm' ),
					$fee_type['amount'],
					number_format_i18n( $amount, 2 )
				);

				return (float) $amount;

			case 'fixed':
			default:
				$amount = (float) $fee_type['amount'];
				$log[]  = sprintf(
					/* translators: 1: Beitragsart, 2: Betrag. */
					__( 'Grundbetrag „%1$s“: %2$s €', 'svm' ),
					$fee_type['label'],
					number_format_i18n( $amount, 2 )
				);
				return $amount;
		}
	}

	/**
	 * Anteilsfaktor bei unterjährigem Ein- oder Austritt.
	 *
	 * @param array $fee_type Beitragsart.
	 * @param array $period   Zeitraum.
	 * @param array $context  Mitgliedskontext.
	 * @return float
	 */
	private static function proration_factor( array $fee_type, array $period, array $context ) {
		if ( 'none' === $fee_type['proration'] ) {
			return 1.0;
		}

		$member       = $context['member'];
		$period_start = strtotime( $period['start'] );
		$period_end   = strtotime( $period['end'] );

		if ( ! $period_start || ! $period_end || $period_end <= $period_start ) {
			return 1.0;
		}

		$joined = ! empty( $member['joined_at'] ) ? strtotime( $member['joined_at'] ) : null;
		$left   = ! empty( $member['left_at'] ) ? strtotime( $member['left_at'] ) : null;

		$effective_start = ( $joined && $joined > $period_start ) ? $joined : $period_start;
		$effective_end   = ( $left && $left < $period_end ) ? $left : $period_end;

		if ( $effective_end <= $effective_start ) {
			return 0.0;
		}

		if ( 'monthly' === $fee_type['proration'] ) {
			$total_months  = self::month_span( $period_start, $period_end );
			$active_months = self::month_span( $effective_start, $effective_end );

			return $total_months > 0 ? min( 1.0, $active_months / $total_months ) : 1.0;
		}

		$total_days  = ( $period_end - $period_start ) / DAY_IN_SECONDS;
		$active_days = ( $effective_end - $effective_start ) / DAY_IN_SECONDS;

		return $total_days > 0 ? min( 1.0, $active_days / $total_days ) : 1.0;
	}

	/**
	 * Anzahl angefangener Monate zwischen zwei Zeitstempeln.
	 *
	 * @param int $from Beginn.
	 * @param int $to   Ende.
	 * @return int
	 */
	private static function month_span( $from, $to ) {
		$start = new DateTime( gmdate( 'Y-m-01', $from ) );
		$end   = new DateTime( gmdate( 'Y-m-01', $to ) );
		$diff  = $start->diff( $end );

		return (int) ( $diff->y * 12 + $diff->m ) + 1;
	}

	/**
	 * Wendet Rabatte und Zuschläge auf Positionsebene an.
	 *
	 * @param float $amount   Betrag.
	 * @param array $fee_type Beitragsart.
	 * @param array $context  Mitgliedskontext.
	 * @param array $log      Protokoll (per Referenz).
	 * @return float
	 */
	private static function apply_line_adjustments( $amount, array $fee_type, array $context, array &$log ) {
		$rules = SVM_Rules::active( 'fee_adjustment' );

		foreach ( $rules as $rule ) {
			if ( 'line' !== $rule['applies_to'] ) {
				continue;
			}

			// Auf eine bestimmte Beitragsart begrenzt?
			if ( 'fee_type' === $rule['owner_type'] && (int) $rule['owner_id'] !== (int) $fee_type['id'] ) {
				continue;
			}

			if ( ! SVM_Rule_Engine::matches( $rule, $context ) ) {
				continue;
			}

			$before = $amount;
			$amount = self::apply_adjustment( $amount, $rule );

			if ( abs( $before - $amount ) > 0.001 ) {
				$log[] = sprintf(
					/* translators: 1: Regelbezeichnung, 2: alter Betrag, 3: neuer Betrag. */
					__( 'Regel „%1$s“: %2$s € → %3$s €', 'svm' ),
					$rule['label'],
					number_format_i18n( $before, 2 ),
					number_format_i18n( $amount, 2 )
				);
			}

			if ( ! $rule['stackable'] ) {
				break;
			}
		}

		return $amount;
	}

	/**
	 * Wendet Anpassungen auf Mitgliedsebene an (z. B. Höchstbetrag je Mitglied).
	 *
	 * @param array $result  Zwischenergebnis.
	 * @param array $context Mitgliedskontext.
	 * @return array
	 */
	private static function apply_member_adjustments( array $result, array $context ) {
		if ( empty( $result['lines'] ) ) {
			return $result;
		}

		$total = 0.0;
		foreach ( $result['lines'] as $line ) {
			$total += (float) $line['amount'];
		}

		foreach ( SVM_Rules::active( 'fee_adjustment' ) as $rule ) {
			if ( 'member' !== $rule['applies_to'] ) {
				continue;
			}

			if ( ! SVM_Rule_Engine::matches( $rule, $context ) ) {
				continue;
			}

			$new_total = self::apply_adjustment( $total, $rule );

			if ( abs( $new_total - $total ) > 0.001 && $total > 0 ) {
				$factor = $new_total / $total;
				$note   = sprintf(
					/* translators: 1: Regelbezeichnung, 2: alter Gesamtbetrag, 3: neuer Gesamtbetrag. */
					__( 'Regel „%1$s“ auf Mitgliedsebene: Gesamt %2$s € → %3$s €', 'svm' ),
					$rule['label'],
					number_format_i18n( $total, 2 ),
					number_format_i18n( $new_total, 2 )
				);

				foreach ( $result['lines'] as $index => $line ) {
					$result['lines'][ $index ]['amount'] = round( (float) $line['amount'] * $factor, 2 );
					$result['lines'][ $index ]['log'][]  = $note;
				}

				$total = $new_total;
			}
		}

		return $result;
	}

	/**
	 * Wendet eine einzelne Anpassung an.
	 *
	 * @param float $amount Betrag.
	 * @param array $rule   Regel.
	 * @return float
	 */
	public static function apply_adjustment( $amount, array $rule ) {
		$value = (float) $rule['adjustment_value'];

		switch ( $rule['adjustment_type'] ) {
			case 'discount_abs':
				return max( 0, $amount - $value );

			case 'discount_pct':
				return $amount * ( 1 - ( $value / 100 ) );

			case 'surcharge_abs':
				return $amount + $value;

			case 'surcharge_pct':
				return $amount * ( 1 + ( $value / 100 ) );

			case 'set_amount':
				return $value;

			case 'cap_member':
			case 'cap_family':
				return min( $amount, $value );
		}

		return $amount;
	}

	/* ---------------------------------------------------------------------
	 * Zeiträume
	 * ------------------------------------------------------------------ */

	/**
	 * Zerlegt den Laufzeitraum in die Fälligkeiten der Beitragsart.
	 *
	 * @param array  $fee_type Beitragsart.
	 * @param string $start    Beginn.
	 * @param string $end      Ende.
	 * @return array Liste aus start, end, due.
	 */
	public static function periods_in_range( array $fee_type, $start, $end ) {
		$start_ts = strtotime( $start );
		$end_ts   = strtotime( $end );

		if ( ! $start_ts || ! $end_ts || $end_ts < $start_ts ) {
			return array();
		}

		if ( 'once' === $fee_type['cycle'] ) {
			return array(
				array(
					'start' => gmdate( 'Y-m-d', $start_ts ),
					'end'   => gmdate( 'Y-m-d', $end_ts ),
					'due'   => self::due_date( $fee_type, gmdate( 'Y-m-d', $start_ts ) ),
				),
			);
		}

		$months = array(
			'monthly'    => 1,
			'quarterly'  => 3,
			'halfyearly' => 6,
			'yearly'     => 12,
		);

		$step    = isset( $months[ $fee_type['cycle'] ] ) ? $months[ $fee_type['cycle'] ] : 12;
		$periods = array();
		$cursor  = new DateTime( gmdate( 'Y-m-d', $start_ts ) );
		$limit   = new DateTime( gmdate( 'Y-m-d', $end_ts ) );
		$guard   = 0;

		while ( $cursor <= $limit && $guard < 240 ) {
			$period_start = clone $cursor;
			$period_end   = clone $cursor;
			$period_end->modify( '+' . $step . ' months' );
			$period_end->modify( '-1 day' );

			if ( $period_end > $limit ) {
				$period_end = clone $limit;
			}

			$periods[] = array(
				'start' => $period_start->format( 'Y-m-d' ),
				'end'   => $period_end->format( 'Y-m-d' ),
				'due'   => self::due_date( $fee_type, $period_start->format( 'Y-m-d' ) ),
			);

			$cursor->modify( '+' . $step . ' months' );
			$guard++;
		}

		return $periods;
	}

	/**
	 * Ermittelt das Fälligkeitsdatum aus der Fälligkeitsregel.
	 *
	 * Unterstützt „TT.MM“ (fester Termin im Jahr) und „+Nd“ / „+Nm“ (Frist ab Periodenbeginn).
	 *
	 * @param array  $fee_type     Beitragsart.
	 * @param string $period_start Periodenbeginn.
	 * @return string
	 */
	public static function due_date( array $fee_type, $period_start ) {
		$rule  = trim( (string) $fee_type['due_rule'] );
		$start = strtotime( $period_start );

		if ( ! $start ) {
			return $period_start;
		}

		if ( '' === $rule ) {
			return gmdate( 'Y-m-d', $start );
		}

		if ( preg_match( '/^\+(\d+)\s*([dmw])$/i', $rule, $matches ) ) {
			$unit = strtolower( $matches[2] );
			$map  = array( 'd' => 'days', 'w' => 'weeks', 'm' => 'months' );
			$date = new DateTime( gmdate( 'Y-m-d', $start ) );
			$date->modify( '+' . (int) $matches[1] . ' ' . $map[ $unit ] );

			return $date->format( 'Y-m-d' );
		}

		if ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.?$/', $rule, $matches ) ) {
			$day   = str_pad( $matches[1], 2, '0', STR_PAD_LEFT );
			$month = str_pad( $matches[2], 2, '0', STR_PAD_LEFT );
			$year  = gmdate( 'Y', $start );
			$due   = strtotime( "{$year}-{$month}-{$day}" );

			if ( $due && $due < $start ) {
				$due = strtotime( '+1 year', $due );
			}

			return $due ? gmdate( 'Y-m-d', $due ) : gmdate( 'Y-m-d', $start );
		}

		$parsed = strtotime( $rule );

		return $parsed ? gmdate( 'Y-m-d', $parsed ) : gmdate( 'Y-m-d', $start );
	}

	/**
	 * Beschreibungstext einer Position.
	 *
	 * @param array $fee_type Beitragsart.
	 * @param array $period   Zeitraum.
	 * @return string
	 */
	private static function describe_period( array $fee_type, array $period ) {
		if ( 'once' === $fee_type['cycle'] ) {
			return $fee_type['label'];
		}

		return sprintf(
			'%s (%s – %s)',
			$fee_type['label'],
			date_i18n( 'd.m.Y', strtotime( $period['start'] ) ),
			date_i18n( 'd.m.Y', strtotime( $period['end'] ) )
		);
	}
}
