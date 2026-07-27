<?php
/**
 * Beitragslauf mit Simulation.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Erzeugt Forderungen aus dem Beitragsregelwerk — erst als Vorschau, dann verbindlich.
 */
class SVM_Fee_Run {

	/**
	 * Führt einen Beitragslauf aus.
	 *
	 * @param array $args   period_start, period_end, label, unit_id, status_id, fee_type_ids, skip_existing.
	 * @param bool  $commit true erzeugt Forderungen, false liefert nur die Vorschau.
	 * @return array
	 */
	public static function execute( array $args, $commit = false ) {
		$args = wp_parse_args(
			$args,
			array(
				'period_start'  => gmdate( 'Y-01-01' ),
				'period_end'    => gmdate( 'Y-12-31' ),
				'label'         => '',
				'unit_id'       => 0,
				'status_id'     => 0,
				'fee_type_ids'  => array(),
				'skip_existing' => true,
			)
		);

		$result = array(
			'args'     => $args,
			'rows'     => array(),
			'warnings' => array(),
			'totals'   => array(
				'members'  => 0,
				'invoices' => 0,
				'amount'   => 0.0,
				'skipped'  => 0,
			),
			'run_id'   => 0,
		);

		$fee_types = self::selected_fee_types( $args );

		if ( empty( $fee_types ) ) {
			$result['warnings'][] = __( 'Für den gewählten Zeitraum ist keine gültige Beitragsart konfiguriert.', 'svm' );
			return $result;
		}

		$member_ids = SVM_Members::query(
			array(
				'unit_id'       => (int) $args['unit_id'],
				'status_id'     => (int) $args['status_id'],
				'limit'         => 0,
				'ids_only'      => true,
				'respect_scope' => false,
			)
		);

		$by_family = array();

		foreach ( $member_ids as $member_id ) {
			$calculation = SVM_Fee_Calculator::calculate_for_member(
				$member_id,
				$args['period_start'],
				$args['period_end'],
				$fee_types
			);

			if ( empty( $calculation['lines'] ) ) {
				continue;
			}

			$member = SVM_Members::get( $member_id );
			$row    = array(
				'member_id'     => (int) $member_id,
				'member_number' => $member ? $member['member_number'] : '',
				'member_name'   => SVM_Members::display_name( $member_id ),
				'family_id'     => $member ? (int) $member['family_group_id'] : 0,
				'lines'         => $calculation['lines'],
				'total'         => $calculation['total'],
			);

			$result['rows'][] = $row;

			if ( $row['family_id'] > 0 ) {
				$by_family[ $row['family_id'] ][] = count( $result['rows'] ) - 1;
			}
		}

		$result = self::apply_family_caps( $result, $by_family );
		$result = self::filter_existing( $result, $args );

		foreach ( $result['rows'] as $row ) {
			$result['totals']['members']++;
			foreach ( $row['lines'] as $line ) {
				$result['totals']['invoices']++;
				$result['totals']['amount'] += (float) $line['amount'];
			}
		}

		$result['totals']['amount'] = round( $result['totals']['amount'], 2 );

		if ( 0 === $result['totals']['invoices'] ) {
			$result['warnings'][] = __( 'Es entstehen keine neuen Forderungen — entweder greifen keine Regeln oder alle Forderungen bestehen bereits.', 'svm' );
		}

		if ( $commit ) {
			$result = self::commit( $result, $args );
		}

		return $result;
	}

	/**
	 * Wählt die zu berücksichtigenden Beitragsarten.
	 *
	 * @param array $args Laufparameter.
	 * @return array
	 */
	private static function selected_fee_types( array $args ) {
		$valid = SVM_Fee_Types::valid_at( $args['period_start'] );

		if ( empty( $args['fee_type_ids'] ) ) {
			return $valid;
		}

		$wanted = array_map( 'intval', (array) $args['fee_type_ids'] );

		return array_values(
			array_filter(
				$valid,
				static function ( $fee_type ) use ( $wanted ) {
					return in_array( (int) $fee_type['id'], $wanted, true );
				}
			)
		);
	}

	/**
	 * Wendet Höchstbeträge je Familienverbund an.
	 *
	 * @param array $result    Zwischenergebnis.
	 * @param array $by_family Zeilenindizes je Familie.
	 * @return array
	 */
	private static function apply_family_caps( array $result, array $by_family ) {
		$caps = array();

		foreach ( SVM_Rules::active( 'fee_adjustment' ) as $rule ) {
			if ( 'family' === $rule['applies_to'] || 'cap_family' === $rule['adjustment_type'] ) {
				$caps[] = $rule;
			}
		}

		if ( empty( $caps ) || empty( $by_family ) ) {
			return $result;
		}

		foreach ( $by_family as $family_id => $indexes ) {
			$family_total = 0.0;
			foreach ( $indexes as $index ) {
				$family_total += (float) $result['rows'][ $index ]['total'];
			}

			foreach ( $caps as $rule ) {
				$first_member = $result['rows'][ $indexes[0] ]['member_id'];
				$context      = SVM_Members::context( $first_member );

				if ( ! $context || ! SVM_Rule_Engine::matches( $rule, $context ) ) {
					continue;
				}

				$new_total = SVM_Fee_Calculator::apply_adjustment( $family_total, $rule );

				if ( $family_total > 0 && abs( $new_total - $family_total ) > 0.001 ) {
					$factor = $new_total / $family_total;
					$note   = sprintf(
						/* translators: 1: Regelbezeichnung, 2: alter Betrag, 3: neuer Betrag. */
						__( 'Familienregel „%1$s“: Verbund %2$s € → %3$s €', 'svm' ),
						$rule['label'],
						number_format_i18n( $family_total, 2 ),
						number_format_i18n( $new_total, 2 )
					);

					foreach ( $indexes as $index ) {
						$row_total = 0.0;

						foreach ( $result['rows'][ $index ]['lines'] as $line_index => $line ) {
							$new_amount = round( (float) $line['amount'] * $factor, 2 );

							$result['rows'][ $index ]['lines'][ $line_index ]['amount'] = $new_amount;
							$result['rows'][ $index ]['lines'][ $line_index ]['log'][]  = $note;

							$row_total += $new_amount;
						}

						$result['rows'][ $index ]['total'] = round( $row_total, 2 );
					}

					$family_total = $new_total;
				}
			}

			unset( $family_id );
		}

		return $result;
	}

	/**
	 * Entfernt Positionen, für die bereits eine Forderung besteht.
	 *
	 * @param array $result Zwischenergebnis.
	 * @param array $args   Laufparameter.
	 * @return array
	 */
	private static function filter_existing( array $result, array $args ) {
		if ( empty( $args['skip_existing'] ) ) {
			return $result;
		}

		foreach ( $result['rows'] as $index => $row ) {
			$kept = array();

			foreach ( $row['lines'] as $line ) {
				$exists = SVM_Invoices::exists_for_period(
					$row['member_id'],
					$line['fee_type_id'],
					$line['period_start'],
					$line['period_end']
				);

				if ( $exists ) {
					$result['totals']['skipped']++;
					continue;
				}

				$kept[] = $line;
			}

			if ( empty( $kept ) ) {
				unset( $result['rows'][ $index ] );
				continue;
			}

			$total = 0.0;
			foreach ( $kept as $line ) {
				$total += (float) $line['amount'];
			}

			$result['rows'][ $index ]['lines'] = $kept;
			$result['rows'][ $index ]['total'] = round( $total, 2 );
		}

		$result['rows'] = array_values( $result['rows'] );

		if ( $result['totals']['skipped'] > 0 ) {
			$result['warnings'][] = sprintf(
				/* translators: %d: Anzahl übersprungener Positionen. */
				__( '%d Positionen wurden übersprungen, weil dafür bereits eine Forderung besteht.', 'svm' ),
				$result['totals']['skipped']
			);
		}

		return $result;
	}

	/**
	 * Schreibt den Lauf und die Forderungen fest.
	 *
	 * @param array $result Vorschauergebnis.
	 * @param array $args   Laufparameter.
	 * @return array
	 */
	private static function commit( array $result, array $args ) {
		$label = '' !== $args['label']
			? $args['label']
			: sprintf(
				/* translators: 1: Beginn, 2: Ende. */
				__( 'Beitragslauf %1$s – %2$s', 'svm' ),
				$args['period_start'],
				$args['period_end']
			);

		$run_id = SVM_DB::insert(
			'fee_runs',
			array(
				'label'         => $label,
				'period_start'  => $args['period_start'],
				'period_end'    => $args['period_end'],
				'status'        => 'committed',
				'member_count'  => (int) $result['totals']['members'],
				'invoice_count' => (int) $result['totals']['invoices'],
				'total_amount'  => (float) $result['totals']['amount'],
				'summary'       => wp_json_encode( $result['totals'] ),
				'created_by'    => get_current_user_id(),
				'created_at'    => SVM_DB::now(),
			)
		);

		foreach ( $result['rows'] as $row ) {
			$member = SVM_Members::get( $row['member_id'] );

			foreach ( $row['lines'] as $line ) {
				SVM_Invoices::create(
					array(
						'member_id'         => $row['member_id'],
						'fee_type_id'       => $line['fee_type_id'],
						'run_id'            => $run_id,
						'description'       => $line['description'],
						'period_start'      => $line['period_start'],
						'period_end'        => $line['period_end'],
						'due_date'          => $line['due_date'],
						'amount'            => $line['amount'],
						'payment_method_id' => $member ? (int) $member['payment_method_id'] : 0,
						'calculation_log'   => $line['log'],
					)
				);
			}
		}

		SVM_Audit::log( 'fee_run', $run_id, 'committed', 'total', '', $result['totals']['amount'] );

		$result['run_id'] = $run_id;

		do_action( 'svm_fee_run_committed', $run_id, $result );

		return $result;
	}

	/**
	 * Ein Lauf.
	 *
	 * @param int $id Lauf-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		return SVM_DB::get( 'fee_runs', $id );
	}

	/**
	 * Forderungen eines Laufs.
	 *
	 * @param int $run_id Lauf-ID.
	 * @return array
	 */
	public static function invoices( $run_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'invoices' ) . ' WHERE run_id = %d',
				(int) $run_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Prüft, ob ein Lauf zurückgenommen werden darf.
	 *
	 * @param int $run_id Lauf-ID.
	 * @return bool
	 */
	public static function can_delete( $run_id ) {
		$ids = array();

		foreach ( self::invoices( $run_id ) as $invoice ) {
			$ids[] = (int) $invoice['id'];
		}

		if ( empty( $ids ) ) {
			return true;
		}

		// Nur zurücknehmbar, wenn jede einzelne Forderung noch löschbar ist.
		return count( SVM_Invoices::deletable_ids( $ids ) ) === count( $ids );
	}

	/**
	 * Nimmt einen Beitragslauf komplett zurück: löscht die daraus erzeugten
	 * Forderungen und den Lauf selbst.
	 *
	 * Möglich, solange auf keine dieser Forderungen gezahlt wurde.
	 *
	 * @param int $run_id Lauf-ID.
	 * @return int|WP_Error Anzahl gelöschter Forderungen.
	 */
	public static function delete( $run_id ) {
		$run = self::get( $run_id );

		if ( ! $run ) {
			return new WP_Error( 'svm_run_missing', __( 'Beitragslauf nicht gefunden.', 'svm' ) );
		}

		if ( ! self::can_delete( $run_id ) ) {
			return new WP_Error(
				'svm_run_paid',
				__( 'Auf Forderungen aus diesem Lauf wurde bereits gezahlt. Er kann nicht mehr zurückgenommen werden — stornieren Sie stattdessen einzelne Forderungen.', 'svm' )
			);
		}

		$count = 0;

		foreach ( self::invoices( $run_id ) as $invoice ) {
			$result = SVM_Invoices::delete( (int) $invoice['id'] );

			if ( ! is_wp_error( $result ) ) {
				$count++;
			}
		}

		SVM_DB::delete( 'fee_runs', (int) $run_id );
		SVM_Audit::log( 'fee_run', (int) $run_id, 'reverted', 'invoices', '', $count );

		return $count;
	}

	/**
	 * Bisherige Läufe.
	 *
	 * @param int $limit Anzahl.
	 * @return array
	 */
	public static function history( $limit = 20 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'fee_runs' ) . ' ORDER BY created_at DESC LIMIT %d',
				(int) $limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}
}
