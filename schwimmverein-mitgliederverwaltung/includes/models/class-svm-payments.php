<?php
/**
 * Zahlungen und ihre Zuordnung zu Forderungen.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Erfasst wird immer auch, wer tatsächlich gezahlt hat — der Zahler kann vom Mitglied abweichen.
 */
class SVM_Payments {

	/**
	 * Eine Zahlung.
	 *
	 * @param int $id Zahlungs-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		return SVM_DB::get( 'payments', $id );
	}

	/**
	 * Erfasst eine Zahlung und ordnet sie Forderungen zu.
	 *
	 * @param array $data        member_id, amount, paid_at, payer_name, reference, note, payment_method_id.
	 * @param array $allocations invoice_id => Betrag. Leer = automatische Zuordnung.
	 * @return int|WP_Error Zahlungs-ID.
	 */
	public static function record( array $data, array $allocations = array() ) {
		$data = wp_parse_args(
			$data,
			array(
				'member_id'         => 0,
				'payment_method_id' => 0,
				'amount'            => 0,
				'paid_at'           => gmdate( 'Y-m-d' ),
				'payer_name'        => '',
				'reference'         => '',
				'note'              => '',
				'status'            => 'booked',
				'run_id'            => 0,
			)
		);

		$data['amount'] = round( SVM_Fields::to_number( $data['amount'] ), 2 );

		if ( $data['amount'] <= 0 ) {
			return new WP_Error( 'svm_payment_amount', __( 'Der Zahlbetrag muss größer als 0,00 € sein.', 'svm' ) );
		}

		if ( (int) $data['member_id'] <= 0 ) {
			return new WP_Error( 'svm_payment_member', __( 'Bitte ein Mitglied auswählen.', 'svm' ) );
		}

		// Ist kein abweichender Zahler erfasst, gilt das Mitglied selbst als Zahler.
		if ( '' === trim( (string) $data['payer_name'] ) ) {
			$data['payer_name'] = SVM_Members::display_name( (int) $data['member_id'] );
		}

		$data['recorded_by'] = get_current_user_id();
		$data['created_at']  = SVM_DB::now();

		$payment_id = SVM_DB::insert( 'payments', $data );

		if ( empty( $allocations ) ) {
			$allocations = self::suggest_allocation( (int) $data['member_id'], (float) $data['amount'] );
		}

		self::set_allocations( $payment_id, $allocations );

		SVM_Audit::log(
			'payment',
			$payment_id,
			'recorded',
			'amount',
			'',
			$data['amount'] . ' / ' . $data['payer_name']
		);

		do_action( 'svm_payment_recorded', $payment_id );

		return $payment_id;
	}

	/**
	 * Aktualisiert eine Zahlung.
	 *
	 * @param int   $id          Zahlungs-ID.
	 * @param array $data        Daten.
	 * @param array $allocations Zuordnungen.
	 * @return void
	 */
	public static function update( $id, array $data, array $allocations = null ) {
		unset( $data['id'], $data['created_at'] );

		if ( isset( $data['amount'] ) ) {
			$data['amount'] = round( SVM_Fields::to_number( $data['amount'] ), 2 );
		}

		SVM_DB::update( 'payments', $id, $data );

		if ( null !== $allocations ) {
			self::set_allocations( $id, $allocations );
		}

		SVM_Audit::log( 'payment', $id, 'updated' );
	}

	/**
	 * Setzt die Zuordnungen einer Zahlung neu.
	 *
	 * @param int   $payment_id  Zahlungs-ID.
	 * @param array $allocations invoice_id => Betrag.
	 * @return void
	 */
	public static function set_allocations( $payment_id, array $allocations ) {
		global $wpdb;

		$payment_id = (int) $payment_id;
		$affected   = array();

		foreach ( self::allocations( $payment_id ) as $existing ) {
			$affected[] = (int) $existing['invoice_id'];
		}

		$wpdb->delete( SVM_DB::table( 'payment_allocations' ), array( 'payment_id' => $payment_id ) ); // phpcs:ignore WordPress.DB

		foreach ( $allocations as $invoice_id => $amount ) {
			$amount = round( SVM_Fields::to_number( $amount ), 2 );

			if ( $amount <= 0 ) {
				continue;
			}

			SVM_DB::insert(
				'payment_allocations',
				array(
					'payment_id' => $payment_id,
					'invoice_id' => (int) $invoice_id,
					'amount'     => $amount,
					'created_at' => SVM_DB::now(),
				)
			);

			$affected[] = (int) $invoice_id;
		}

		foreach ( array_unique( $affected ) as $invoice_id ) {
			SVM_Invoices::refresh_status( $invoice_id );
		}
	}

	/**
	 * Zuordnungen einer Zahlung.
	 *
	 * @param int $payment_id Zahlungs-ID.
	 * @return array
	 */
	public static function allocations( $payment_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'payment_allocations' ) . ' WHERE payment_id = %d',
				(int) $payment_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Schlägt eine Zuordnung auf die ältesten offenen Forderungen vor.
	 *
	 * @param int   $member_id Mitglieds-ID.
	 * @param float $amount    Zahlbetrag.
	 * @return array invoice_id => Betrag.
	 */
	public static function suggest_allocation( $member_id, $amount ) {
		$remaining   = round( (float) $amount, 2 );
		$allocations = array();

		foreach ( SVM_Invoices::open_for_member( $member_id ) as $invoice ) {
			if ( $remaining <= 0 ) {
				break;
			}

			$open = SVM_Invoices::remaining( $invoice );
			if ( $open <= 0 ) {
				continue;
			}

			$take                                  = min( $open, $remaining );
			$allocations[ (int) $invoice['id'] ]   = round( $take, 2 );
			$remaining                             = round( $remaining - $take, 2 );
		}

		return $allocations;
	}

	/**
	 * Nicht zugeordneter Betrag einer Zahlung (Guthaben).
	 *
	 * @param int $payment_id Zahlungs-ID.
	 * @return float
	 */
	public static function unallocated( $payment_id ) {
		$payment = self::get( $payment_id );
		if ( ! $payment ) {
			return 0.0;
		}

		$allocated = 0.0;
		foreach ( self::allocations( $payment_id ) as $allocation ) {
			$allocated += (float) $allocation['amount'];
		}

		return round( (float) $payment['amount'] - $allocated, 2 );
	}

	/**
	 * Guthaben eines Mitglieds aus nicht zugeordneten Zahlungen.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return float
	 */
	public static function credit_balance( $member_id ) {
		global $wpdb;

		$total = (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(amount) FROM ' . SVM_DB::table( 'payments' ) . ' WHERE member_id = %d AND status = %s',
				(int) $member_id,
				'booked'
			)
		); // phpcs:ignore WordPress.DB

		$allocated = (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(a.amount) FROM ' . SVM_DB::table( 'payment_allocations' ) . ' a
				INNER JOIN ' . SVM_DB::table( 'payments' ) . ' p ON p.id = a.payment_id
				WHERE p.member_id = %d AND p.status = %s',
				(int) $member_id,
				'booked'
			)
		); // phpcs:ignore WordPress.DB

		return round( $total - $allocated, 2 );
	}

	/**
	 * Kennzeichnet eine Zahlung als Rücklastschrift.
	 *
	 * @param int   $payment_id  Zahlungs-ID.
	 * @param float $return_fee  Rückläufergebühr.
	 * @return void
	 */
	public static function mark_returned( $payment_id, $return_fee = 0.0 ) {
		$payment = self::get( $payment_id );
		if ( ! $payment ) {
			return;
		}

		$invoice_ids = array();
		foreach ( self::allocations( $payment_id ) as $allocation ) {
			$invoice_ids[] = (int) $allocation['invoice_id'];
		}

		SVM_DB::update( 'payments', $payment_id, array( 'status' => 'returned' ) );

		foreach ( $invoice_ids as $invoice_id ) {
			SVM_Invoices::refresh_status( $invoice_id );
		}

		$return_fee = round( SVM_Fields::to_number( $return_fee ), 2 );

		if ( $return_fee > 0 ) {
			SVM_Invoices::create(
				array(
					'member_id'    => (int) $payment['member_id'],
					'description'  => __( 'Rücklastschriftgebühr', 'svm' ),
					'period_start' => $payment['paid_at'],
					'period_end'   => $payment['paid_at'],
					'due_date'     => gmdate( 'Y-m-d', strtotime( '+14 days' ) ),
					'amount'       => $return_fee,
				)
			);
		}

		SVM_Audit::log( 'payment', $payment_id, 'returned', 'fee', '', $return_fee );
	}

	/**
	 * Sucht Zahlungen.
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
				'payment_method_id' => 0,
				'from'              => '',
				'to'                => '',
				'search'            => '',
				'limit'             => 100,
				'offset'            => 0,
				'respect_scope'     => true,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( $args['member_id'] > 0 ) {
			$where[]  = 'p.member_id = %d';
			$params[] = (int) $args['member_id'];
		}

		if ( '' !== $args['status'] ) {
			$where[]  = 'p.status = %s';
			$params[] = $args['status'];
		}

		if ( $args['payment_method_id'] > 0 ) {
			$where[]  = 'p.payment_method_id = %d';
			$params[] = (int) $args['payment_method_id'];
		}

		if ( '' !== $args['from'] ) {
			$where[]  = 'p.paid_at >= %s';
			$params[] = $args['from'];
		}

		if ( '' !== $args['to'] ) {
			$where[]  = 'p.paid_at <= %s';
			$params[] = $args['to'];
		}

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(p.payer_name LIKE %s OR p.reference LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		if ( $args['respect_scope'] ) {
			$where[] = SVM_Permissions::member_scope_sql( 'm' );
		}

		$sql = 'SELECT p.* FROM ' . SVM_DB::table( 'payments' ) . ' p
			INNER JOIN ' . SVM_DB::table( 'members' ) . ' m ON m.id = p.member_id
			WHERE ' . implode( ' AND ', $where ) . '
			ORDER BY p.paid_at DESC, p.id DESC';

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
	 * Löscht eine Zahlung samt Zuordnungen.
	 *
	 * @param int $id Zahlungs-ID.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id       = (int) $id;
		$invoices = array();

		foreach ( self::allocations( $id ) as $allocation ) {
			$invoices[] = (int) $allocation['invoice_id'];
		}

		$wpdb->delete( SVM_DB::table( 'payment_allocations' ), array( 'payment_id' => $id ) ); // phpcs:ignore WordPress.DB
		SVM_DB::delete( 'payments', $id );

		foreach ( array_unique( $invoices ) as $invoice_id ) {
			SVM_Invoices::refresh_status( $invoice_id );
		}

		SVM_Audit::log( 'payment', $id, 'deleted' );
	}
}
