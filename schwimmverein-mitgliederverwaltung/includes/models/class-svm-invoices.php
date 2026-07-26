<?php
/**
 * Forderungen (offene Posten).
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Forderungen werden nie gelöscht, sondern storniert — Voraussetzung für eine prüfbare Kasse.
 */
class SVM_Invoices {

	/**
	 * Eine Forderung.
	 *
	 * @param int $id Forderungs-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		return SVM_DB::get( 'invoices', $id );
	}

	/**
	 * Legt eine Forderung an.
	 *
	 * @param array $data Daten.
	 * @return int
	 */
	public static function create( array $data ) {
		$data = wp_parse_args(
			$data,
			array(
				'member_id'         => 0,
				'fee_type_id'       => 0,
				'run_id'            => 0,
				'description'       => '',
				'period_start'      => null,
				'period_end'        => null,
				'due_date'          => null,
				'amount'            => 0,
				'amount_paid'       => 0,
				'status'            => 'open',
				'payment_method_id' => 0,
				'calculation_log'   => '',
			)
		);

		if ( is_array( $data['calculation_log'] ) ) {
			$data['calculation_log'] = wp_json_encode( $data['calculation_log'] );
		}

		$data['created_at'] = SVM_DB::now();

		$id = SVM_DB::insert( 'invoices', $data );

		SVM_Audit::log( 'invoice', $id, 'created', 'amount', '', $data['amount'] );

		return $id;
	}

	/**
	 * Aktualisiert eine Forderung.
	 *
	 * @param int   $id   Forderungs-ID.
	 * @param array $data Daten.
	 * @return void
	 */
	public static function update( $id, array $data ) {
		SVM_DB::update( 'invoices', $id, $data );
	}

	/**
	 * Storniert eine Forderung.
	 *
	 * @param int    $id     Forderungs-ID.
	 * @param string $reason Begründung.
	 * @return void
	 */
	public static function cancel( $id, $reason = '' ) {
		SVM_DB::update( 'invoices', $id, array( 'status' => 'cancelled' ) );
		SVM_Audit::log( 'invoice', $id, 'cancelled', 'status', '', $reason );
	}

	/**
	 * Storniert alle offenen Forderungen eines Mitglieds.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return int Anzahl stornierter Forderungen.
	 */
	public static function cancel_open_for_member( $member_id ) {
		$count = 0;

		foreach ( self::open_for_member( $member_id ) as $invoice ) {
			self::cancel( (int) $invoice['id'], __( 'Statuswechsel des Mitglieds', 'svm' ) );
			$count++;
		}

		return $count;
	}

	/**
	 * Offene Forderungen eines Mitglieds.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return array
	 */
	public static function open_for_member( $member_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'invoices' ) . '
				WHERE member_id = %d AND status IN (%s, %s) ORDER BY due_date ASC, id ASC',
				(int) $member_id,
				'open',
				'partial'
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Summe der offenen Beträge eines Mitglieds.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return float
	 */
	public static function open_total( $member_id ) {
		global $wpdb;

		$total = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(amount - amount_paid) FROM ' . SVM_DB::table( 'invoices' ) . '
				WHERE member_id = %d AND status IN (%s, %s)',
				(int) $member_id,
				'open',
				'partial'
			)
		); // phpcs:ignore WordPress.DB

		return (float) $total;
	}

	/**
	 * Prüft, ob für Mitglied, Beitragsart und Zeitraum bereits eine Forderung besteht.
	 *
	 * @param int    $member_id    Mitglieds-ID.
	 * @param int    $fee_type_id  Beitragsart-ID.
	 * @param string $period_start Zeitraumbeginn.
	 * @param string $period_end   Zeitraumende.
	 * @return bool
	 */
	public static function exists_for_period( $member_id, $fee_type_id, $period_start, $period_end ) {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . SVM_DB::table( 'invoices' ) . '
				WHERE member_id = %d AND fee_type_id = %d AND period_start = %s AND period_end = %s AND status <> %s',
				(int) $member_id,
				(int) $fee_type_id,
				$period_start,
				$period_end,
				'cancelled'
			)
		); // phpcs:ignore WordPress.DB

		return $count > 0;
	}

	/**
	 * Sucht Forderungen.
	 *
	 * @param array $args Filter.
	 * @return array
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'member_id'         => 0,
				'status'            => '',
				'unit_id'           => 0,
				'fee_type_id'       => 0,
				'payment_method_id' => 0,
				'due_before'        => '',
				'period_start'      => '',
				'period_end'        => '',
				'limit'             => 100,
				'offset'            => 0,
				'respect_scope'     => true,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( $args['member_id'] > 0 ) {
			$where[]  = 'i.member_id = %d';
			$params[] = (int) $args['member_id'];
		}

		if ( '' !== $args['status'] ) {
			if ( 'unpaid' === $args['status'] ) {
				$where[] = "i.status IN ('open','partial')";
			} else {
				$where[]  = 'i.status = %s';
				$params[] = $args['status'];
			}
		}

		if ( $args['fee_type_id'] > 0 ) {
			$where[]  = 'i.fee_type_id = %d';
			$params[] = (int) $args['fee_type_id'];
		}

		if ( $args['payment_method_id'] > 0 ) {
			$where[]  = '(i.payment_method_id = %d OR (i.payment_method_id = 0 AND m.payment_method_id = %d))';
			$params[] = (int) $args['payment_method_id'];
			$params[] = (int) $args['payment_method_id'];
		}

		if ( $args['unit_id'] > 0 ) {
			$where[]  = 'i.member_id IN (SELECT member_id FROM ' . SVM_DB::table( 'member_units' ) . ' WHERE unit_id = %d AND is_active = 1)';
			$params[] = (int) $args['unit_id'];
		}

		if ( '' !== $args['due_before'] ) {
			$where[]  = 'i.due_date <= %s';
			$params[] = $args['due_before'];
		}

		if ( '' !== $args['period_start'] ) {
			$where[]  = 'i.period_start >= %s';
			$params[] = $args['period_start'];
		}

		if ( '' !== $args['period_end'] ) {
			$where[]  = 'i.period_end <= %s';
			$params[] = $args['period_end'];
		}

		if ( $args['respect_scope'] ) {
			$where[] = SVM_Permissions::member_scope_sql( 'm' );
		}

		$sql = 'SELECT i.* FROM ' . SVM_DB::table( 'invoices' ) . ' i
			INNER JOIN ' . SVM_DB::table( 'members' ) . ' m ON m.id = i.member_id
			WHERE ' . implode( ' AND ', $where ) . '
			ORDER BY i.due_date ASC, i.id ASC';

		if ( $args['limit'] > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = (int) $args['limit'];
			$params[] = (int) $args['offset'];
		}

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Aktualisiert den Zahlstatus anhand der zugeordneten Zahlungen.
	 *
	 * @param int $invoice_id Forderungs-ID.
	 * @return void
	 */
	public static function refresh_status( $invoice_id ) {
		global $wpdb;

		$invoice = self::get( $invoice_id );
		if ( ! $invoice || 'cancelled' === $invoice['status'] ) {
			return;
		}

		$paid = (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(a.amount) FROM ' . SVM_DB::table( 'payment_allocations' ) . ' a
				INNER JOIN ' . SVM_DB::table( 'payments' ) . ' p ON p.id = a.payment_id
				WHERE a.invoice_id = %d AND p.status = %s',
				(int) $invoice_id,
				'booked'
			)
		); // phpcs:ignore WordPress.DB

		$amount = (float) $invoice['amount'];
		$status = 'open';

		if ( $paid >= $amount - 0.005 && $amount > 0 ) {
			$status = 'paid';
		} elseif ( $paid > 0 ) {
			$status = 'partial';
		}

		SVM_DB::update(
			'invoices',
			$invoice_id,
			array(
				'amount_paid' => round( $paid, 2 ),
				'status'      => $status,
			)
		);
	}

	/**
	 * Offener Restbetrag einer Forderung.
	 *
	 * @param array $invoice Forderung.
	 * @return float
	 */
	public static function remaining( array $invoice ) {
		return round( (float) $invoice['amount'] - (float) $invoice['amount_paid'], 2 );
	}

	/**
	 * Verfügbare Statuswerte.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'open'      => __( 'offen', 'svm' ),
			'partial'   => __( 'teilbezahlt', 'svm' ),
			'paid'      => __( 'bezahlt', 'svm' ),
			'cancelled' => __( 'storniert', 'svm' ),
		);
	}

	/**
	 * Summen für einen Filter.
	 *
	 * @param array $args Filter wie in query().
	 * @return array total, paid, open
	 */
	public static function totals( array $args = array() ) {
		$args['limit'] = 0;
		$rows          = self::query( $args );

		$total = 0.0;
		$paid  = 0.0;

		foreach ( $rows as $row ) {
			if ( 'cancelled' === $row['status'] ) {
				continue;
			}
			$total += (float) $row['amount'];
			$paid  += (float) $row['amount_paid'];
		}

		return array(
			'count' => count( $rows ),
			'total' => round( $total, 2 ),
			'paid'  => round( $paid, 2 ),
			'open'  => round( $total - $paid, 2 ),
		);
	}
}
