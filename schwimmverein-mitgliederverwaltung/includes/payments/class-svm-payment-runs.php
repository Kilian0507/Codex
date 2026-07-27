<?php
/**
 * Zahlläufe: Auswahl, Vorschau, Dateierzeugung, Protokoll.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ein erzeugter Lauf wird protokolliert, damit keine Forderung doppelt eingezogen wird.
 */
class SVM_Payment_Runs {

	/**
	 * Ein Lauf.
	 *
	 * @param int $id Lauf-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		return SVM_DB::get( 'payment_runs', $id );
	}

	/**
	 * Alle Läufe.
	 *
	 * @param int $limit Anzahl.
	 * @return array
	 */
	public static function all( $limit = 50 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'payment_runs' ) . ' ORDER BY created_at DESC LIMIT %d',
				(int) $limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Positionen eines Laufs.
	 *
	 * @param int $run_id Lauf-ID.
	 * @return array
	 */
	public static function items( $run_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'payment_run_items' ) . ' WHERE run_id = %d ORDER BY id ASC',
				(int) $run_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Stellt die Positionen für einen Lauf zusammen.
	 *
	 * @param array $args file_profile_id, collection_date, unit_id, fee_type_id, due_before, period_start, period_end.
	 * @return array items, warnings, totals
	 */
	public static function preview( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'file_profile_id' => 0,
				'collection_date' => '',
				'unit_id'         => 0,
				'fee_type_id'     => 0,
				'due_before'      => '',
				'period_start'    => '',
				'period_end'      => '',
				'mode'            => 'due',
				'year'            => '',
				'aggregate'       => true,
			)
		);

		$annual = 'annual' === $args['mode'];

		// Beim Jahreseinzug zählt das gesamte Jahr, unabhängig von der Fälligkeit.
		if ( $annual ) {
			$year = (int) $args['year'];
			$year = $year > 0 ? $year : (int) gmdate( 'Y' );

			$args['year']         = $year;
			$args['period_start'] = $year . '-01-01';
			$args['period_end']   = $year . '-12-31';
			$args['due_before']   = '';
		}

		$result = array(
			'items'      => array(),
			'file_items' => array(),
			'warnings'   => array(),
			'skipped'    => array(),
			'no_dues'    => array(),
			'totals'     => array( 'count' => 0, 'amount' => 0.0, 'members' => 0 ),
			'profile'    => null,
			'mode'       => $args['mode'],
			'year'       => $args['year'],
		);

		$profile = SVM_File_Profiles::get( (int) $args['file_profile_id'] );

		if ( ! $profile ) {
			$result['warnings'][] = __( 'Bitte ein Dateiprofil auswählen.', 'svm' );
			return $result;
		}

		$result['profile'] = $profile;
		$direction         = SVM_File_Profiles::direction( $profile['format'] );

		$invoices = SVM_Invoices::query(
			array(
				'status'        => 'unpaid',
				'unit_id'       => (int) $args['unit_id'],
				'fee_type_id'   => (int) $args['fee_type_id'],
				'due_before'    => $args['due_before'],
				'period_start'  => $args['period_start'],
				'period_end'    => $args['period_end'],
				'limit'         => 0,
				'respect_scope' => false,
			)
		);

		foreach ( $invoices as $invoice ) {
			$member_id = (int) $invoice['member_id'];
			$name      = SVM_Members::display_name( $member_id );
			$remaining = SVM_Invoices::remaining( $invoice );

			if ( $remaining <= 0 ) {
				continue;
			}

			if ( self::already_collected( (int) $invoice['id'] ) ) {
				$result['skipped'][] = sprintf(
					/* translators: 1: Mitgliedsname, 2: Beschreibung. */
					__( '%1$s — „%2$s“ ist bereits in einem Zahllauf enthalten.', 'svm' ),
					$name,
					$invoice['description']
				);
				continue;
			}

			// Nur Forderungen einbeziehen, deren Zahlart eine Datei erzeugt.
			$method = SVM_Payment_Methods::for_invoice( $invoice );
			if ( ! $method || 'file' !== $method['behavior'] ) {
				continue;
			}

			$item = array(
				'invoice_id'    => (int) $invoice['id'],
				'member_id'     => $member_id,
				'member_name'   => $name,
				'amount'        => $remaining,
				'purpose'       => SVM_File_Profiles::render_purpose( $profile, $invoice ),
				'end_to_end_id' => self::end_to_end_id( $invoice ),
				'name'          => $name,
			);

			if ( 'debit' === $direction ) {
				$mandate = SVM_Mandates::active_for_member( $member_id );

				if ( ! $mandate ) {
					$result['warnings'][] = sprintf(
						/* translators: %s: Mitgliedsname. */
						__( '%s hat kein aktives SEPA-Mandat — die Forderung bleibt offen.', 'svm' ),
						$name
					);
					continue;
				}

				if ( ! SVM_IBAN::is_valid( $mandate['iban'] ) ) {
					$result['warnings'][] = sprintf(
						/* translators: %s: Mitgliedsname. */
						__( '%s hat eine ungültige IBAN im Mandat — die Forderung bleibt offen.', 'svm' ),
						$name
					);
					continue;
				}

				if ( empty( $mandate['signed_at'] ) ) {
					$result['warnings'][] = sprintf(
						/* translators: %s: Mitgliedsname. */
						__( '%s: Im Mandat fehlt das Unterschriftsdatum.', 'svm' ),
						$name
					);
					continue;
				}

				$item['mandate_id']    = (int) $mandate['id'];
				$item['mandate_ref']   = $mandate['mandate_ref'];
				$item['iban']          = $mandate['iban'];
				$item['bic']           = $mandate['bic'];
				$item['signed_at']     = $mandate['signed_at'];
				$item['sequence_type'] = $mandate['sequence_type'];
				$item['name']          = '' !== $mandate['account_holder'] ? $mandate['account_holder'] : $name;
			} else {
				$iban = SVM_Fields::get_system_value( 'member', $member_id, 'iban' );

				if ( ! $iban || ! SVM_IBAN::is_valid( $iban ) ) {
					$result['warnings'][] = sprintf(
						/* translators: %s: Mitgliedsname. */
						__( '%s hat keine gültige IBAN hinterlegt.', 'svm' ),
						$name
					);
					continue;
				}

				$item['iban']          = SVM_IBAN::normalize( $iban );
				$item['bic']           = (string) SVM_Fields::get_system_value( 'member', $member_id, 'bic' );
				$item['mandate_id']    = 0;
				$item['mandate_ref']   = '';
				$item['signed_at']     = '';
				$item['sequence_type'] = '';
			}

			$result['items'][] = $item;
			$result['totals']['count']++;
			$result['totals']['amount'] += $item['amount'];
		}

		$result['totals']['amount'] = round( $result['totals']['amount'], 2 );

		// Beim Jahreseinzug wird je Mitglied ein einziger Betrag abgebucht.
		$result['file_items'] = ( $annual && ! empty( $args['aggregate'] ) )
			? self::aggregate_items( $result['items'], $profile, (int) $args['year'] )
			: $result['items'];

		$result['totals']['members'] = count( self::group_by_member( $result['items'] ) );

		if ( $annual ) {
			$result['no_dues'] = self::members_without_dues( $result['items'], (int) $args['unit_id'] );
		}

		$collection_date = '' !== $args['collection_date'] ? $args['collection_date'] : self::earliest_collection_date( $profile, $result['items'] );
		$earliest        = self::earliest_collection_date( $profile, $result['items'] );

		if ( strtotime( $collection_date ) < strtotime( $earliest ) ) {
			$result['warnings'][] = sprintf(
				/* translators: %s: frühestmögliches Datum. */
				__( 'Die Vorlauffrist wird unterschritten. Frühestmögliches Einzugsdatum: %s', 'svm' ),
				date_i18n( 'd.m.Y', strtotime( $earliest ) )
			);
		}

		$result['collection_date'] = $collection_date;

		if ( empty( $result['items'] ) ) {
			$result['warnings'][] = $annual
				? sprintf(
					/* translators: %d: Jahr. */
					__( 'Für %d bestehen keine offenen Forderungen. Berechnen Sie zuerst unter „Beiträge → Beiträge berechnen“ die Jahresbeiträge.', 'svm' ),
					(int) $args['year']
				)
				: __( 'Es gibt keine passenden offenen Forderungen für dieses Profil.', 'svm' );
		}

		if ( ! empty( $result['no_dues'] ) ) {
			$result['warnings'][] = sprintf(
				/* translators: 1: Anzahl Mitglieder, 2: Jahr. */
				__( '%1$d Mitglieder mit Lastschrift haben für %2$d keine offene Forderung und sind nicht enthalten.', 'svm' ),
				count( $result['no_dues'] ),
				(int) $args['year']
			);
		}

		return $result;
	}

	/**
	 * Gruppiert Positionen nach Mitglied.
	 *
	 * @param array $items Positionen.
	 * @return array member_id => Positionen
	 */
	private static function group_by_member( array $items ) {
		$grouped = array();

		foreach ( $items as $item ) {
			$grouped[ (int) $item['member_id'] ][] = $item;
		}

		return $grouped;
	}

	/**
	 * Fasst die Forderungen eines Mitglieds zu einer Abbuchung zusammen.
	 *
	 * Die Einzelpositionen bleiben im Lauf erhalten — nur die Datei enthält
	 * je Mitglied eine Buchung statt vieler.
	 *
	 * @param array $items   Positionen.
	 * @param array $profile Profil.
	 * @param int   $year    Jahr.
	 * @return array
	 */
	private static function aggregate_items( array $items, array $profile, $year ) {
		$out = array();

		foreach ( self::group_by_member( $items ) as $member_id => $group ) {
			$first  = $group[0];
			$amount = 0.0;

			foreach ( $group as $item ) {
				$amount += (float) $item['amount'];
			}

			$amount = round( $amount, 2 );

			$entry = $first;

			$entry['amount']        = $amount;
			$entry['invoice_count'] = count( $group );
			$entry['purpose']       = SVM_File_Profiles::render_annual_purpose( $profile, $member_id, $year, $amount );
			$entry['end_to_end_id'] = self::annual_end_to_end_id( $member_id, $year );

			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * Mitglieder mit Lastschrift, die für den Zeitraum nichts offen haben.
	 *
	 * @param array $items   Ermittelte Positionen.
	 * @param int   $unit_id Optionale Einschränkung auf eine Sparte.
	 * @return array Liste aus Name und Grund.
	 */
	private static function members_without_dues( array $items, $unit_id = 0 ) {
		$covered = array_keys( self::group_by_member( $items ) );
		$out     = array();

		$member_ids = SVM_Members::query(
			array(
				'unit_id'       => (int) $unit_id,
				'limit'         => 0,
				'ids_only'      => true,
				'respect_scope' => false,
			)
		);

		foreach ( $member_ids as $member_id ) {
			$member_id = (int) $member_id;

			if ( in_array( $member_id, $covered, true ) ) {
				continue;
			}

			$member = SVM_Members::get( $member_id );

			if ( ! $member ) {
				continue;
			}

			$method = SVM_Payment_Methods::get( (int) $member['payment_method_id'] );

			// Nur Mitglieder betrachten, die per Lastschrift zahlen.
			if ( ! $method || 'file' !== $method['behavior'] ) {
				continue;
			}

			$out[] = array(
				'member_id' => $member_id,
				'name'      => SVM_Members::display_name( $member_id ),
				'reason'    => SVM_Mandates::active_for_member( $member_id )
					? __( 'keine offene Forderung im Zeitraum', 'svm' )
					: __( 'kein aktives Mandat', 'svm' ),
			);
		}

		return $out;
	}

	/**
	 * Ende-zu-Ende-Referenz eines Jahreseinzugs.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @param int $year      Jahr.
	 * @return string
	 */
	private static function annual_end_to_end_id( $member_id, $year ) {
		$member = SVM_Members::get( $member_id );
		$number = $member ? $member['member_number'] : (string) $member_id;

		return SVM_SEPA_Generator::text( $number . '-J' . $year, 35 );
	}

	/**
	 * Erzeugt einen Zahllauf samt Datei.
	 *
	 * @param array $args Wie bei preview(), zusätzlich label.
	 * @return array|WP_Error
	 */
	public static function create( array $args ) {
		$preview = self::preview( $args );

		if ( empty( $preview['items'] ) ) {
			return new WP_Error( 'svm_run_empty', __( 'Der Zahllauf enthält keine Positionen.', 'svm' ) );
		}

		$profile         = $preview['profile'];
		$collection_date = $preview['collection_date'];
		$message_id      = self::message_id();

		// Die Datei kann je Mitglied gebündelt sein, die Positionen bleiben einzeln.
		$file_items = ! empty( $preview['file_items'] ) ? $preview['file_items'] : $preview['items'];

		$content = SVM_SEPA_Generator::generate( $profile, $file_items, $collection_date, $message_id );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		// Referenz der gebündelten Buchung je Mitglied, damit sich eine Position
		// später der Buchung auf dem Kontoauszug zuordnen lässt.
		$member_reference = array();

		foreach ( $file_items as $file_item ) {
			$member_reference[ (int) $file_item['member_id'] ] = $file_item['end_to_end_id'];
		}

		$direction = SVM_File_Profiles::direction( $profile['format'] );
		$extension = ( 'csv' === $profile['format'] ) ? 'csv' : 'xml';

		$run_id = SVM_DB::insert(
			'payment_runs',
			array(
				'file_profile_id' => (int) $profile['id'],
				'label'           => isset( $args['label'] ) && '' !== $args['label']
					? $args['label']
					/* translators: %s: Datum. */
					: sprintf( __( 'Zahllauf %s', 'svm' ), date_i18n( 'd.m.Y' ) ),
				'direction'       => $direction,
				'collection_date' => $collection_date,
				'total_amount'    => $preview['totals']['amount'],
				'item_count'      => count( $file_items ),
				'status'          => 'created',
				'message_id'      => $message_id,
				'file_name'       => 'sepa-' . $message_id . '.' . $extension,
				'file_content'    => $content,
				'created_by'      => get_current_user_id(),
				'created_at'      => SVM_DB::now(),
			)
		);

		foreach ( $preview['items'] as $item ) {
			$member_id = (int) $item['member_id'];

			SVM_DB::insert(
				'payment_run_items',
				array(
					'run_id'        => $run_id,
					'invoice_id'    => (int) $item['invoice_id'],
					'member_id'     => $member_id,
					'mandate_id'    => (int) $item['mandate_id'],
					'amount'        => (float) $item['amount'],
					'end_to_end_id' => isset( $member_reference[ $member_id ] )
						? $member_reference[ $member_id ]
						: $item['end_to_end_id'],
					'sequence_type' => (string) $item['sequence_type'],
					'status'        => 'included',
				)
			);

			if ( ! empty( $item['mandate_id'] ) ) {
				SVM_Mandates::mark_used( (int) $item['mandate_id'], $collection_date );
			}
		}

		SVM_Audit::log( 'payment_run', $run_id, 'created', 'amount', '', $preview['totals']['amount'] );

		do_action( 'svm_payment_run_created', $run_id );

		return array(
			'run_id'   => $run_id,
			'warnings' => $preview['warnings'],
			'totals'   => $preview['totals'],
		);
	}

	/**
	 * Setzt den Status eines Laufs.
	 *
	 * Beim Wechsel auf „ausgeführt“ werden die Zahlungen gebucht.
	 *
	 * @param int    $run_id Lauf-ID.
	 * @param string $status created|submitted|executed|cancelled.
	 * @return void
	 */
	public static function set_status( $run_id, $status ) {
		$run = self::get( $run_id );

		if ( ! $run || $run['status'] === $status ) {
			return;
		}

		SVM_DB::update( 'payment_runs', $run_id, array( 'status' => $status ) );

		if ( 'executed' === $status ) {
			self::book_payments( $run );
		}

		if ( 'cancelled' === $status ) {
			self::cancel_bookings( $run );
		}

		SVM_Audit::log( 'payment_run', $run_id, 'status_changed', 'status', $run['status'], $status );
	}

	/**
	 * Bucht für jede Position eine Zahlung.
	 *
	 * @param array $run Lauf.
	 * @return void
	 */
	private static function book_payments( array $run ) {
		$method  = self::file_method_id();
		$run_id  = (int) $run['id'];

		foreach ( self::items( $run_id ) as $item ) {
			if ( 'included' !== $item['status'] ) {
				continue;
			}

			$mandate    = $item['mandate_id'] ? SVM_Mandates::get( (int) $item['mandate_id'] ) : null;
			$payer_name = $mandate && '' !== $mandate['account_holder']
				? $mandate['account_holder']
				: SVM_Members::display_name( (int) $item['member_id'] );

			$payment_id = SVM_Payments::record(
				array(
					'member_id'         => (int) $item['member_id'],
					'payment_method_id' => $method,
					'amount'            => (float) $item['amount'],
					'paid_at'           => $run['collection_date'],
					'payer_name'        => $payer_name,
					'reference'         => $item['end_to_end_id'],
					'note'              => sprintf(
						/* translators: %s: Bezeichnung des Zahllaufs. */
						__( 'Automatisch gebucht aus Zahllauf „%s“.', 'svm' ),
						$run['label']
					),
					'run_id'            => $run_id,
				),
				array( (int) $item['invoice_id'] => (float) $item['amount'] )
			);

			if ( ! is_wp_error( $payment_id ) ) {
				SVM_DB::update( 'payment_run_items', (int) $item['id'], array( 'status' => 'booked' ) );
			}
		}
	}

	/**
	 * Nimmt die Buchungen eines Laufs zurück.
	 *
	 * @param array $run Lauf.
	 * @return void
	 */
	private static function cancel_bookings( array $run ) {
		global $wpdb;

		$payment_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . SVM_DB::table( 'payments' ) . ' WHERE run_id = %d',
				(int) $run['id']
			)
		); // phpcs:ignore WordPress.DB

		foreach ( (array) $payment_ids as $payment_id ) {
			SVM_Payments::delete( (int) $payment_id );
		}

		foreach ( self::items( (int) $run['id'] ) as $item ) {
			SVM_DB::update( 'payment_run_items', (int) $item['id'], array( 'status' => 'cancelled' ) );
		}
	}

	/**
	 * Löscht einen Zahllauf endgültig.
	 *
	 * Möglich, sobald der Lauf storniert ist — dann sind die zugehörigen
	 * Buchungen bereits zurückgenommen und die Forderungen wieder offen.
	 *
	 * @param int $id Lauf-ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;

		$run = self::get( $id );

		if ( ! $run ) {
			return new WP_Error( 'svm_run_missing', __( 'Zahllauf nicht gefunden.', 'svm' ) );
		}

		if ( 'cancelled' !== $run['status'] ) {
			return new WP_Error(
				'svm_run_active',
				__( 'Bitte den Zahllauf zuerst stornieren. Danach lässt er sich löschen.', 'svm' )
			);
		}

		$id = (int) $id;

		$wpdb->delete( SVM_DB::table( 'payment_run_items' ), array( 'run_id' => $id ) ); // phpcs:ignore WordPress.DB
		SVM_DB::delete( 'payment_runs', $id );

		SVM_Audit::log( 'payment_run', $id, 'deleted', 'label', $run['label'] );

		return true;
	}

	/**
	 * Kennzeichnet eine Position als Rücklastschrift.
	 *
	 * @param int   $item_id    Positions-ID.
	 * @param float $return_fee Rückläufergebühr.
	 * @return void
	 */
	public static function mark_item_returned( $item_id, $return_fee = 0.0 ) {
		global $wpdb;

		$item = SVM_DB::get( 'payment_run_items', $item_id );

		if ( ! $item ) {
			return;
		}

		$payment_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT p.id FROM ' . SVM_DB::table( 'payments' ) . ' p
				INNER JOIN ' . SVM_DB::table( 'payment_allocations' ) . ' a ON a.payment_id = p.id
				WHERE p.run_id = %d AND a.invoice_id = %d LIMIT 1',
				(int) $item['run_id'],
				(int) $item['invoice_id']
			)
		); // phpcs:ignore WordPress.DB

		if ( $payment_id ) {
			SVM_Payments::mark_returned( (int) $payment_id, $return_fee );
		}

		SVM_DB::update( 'payment_run_items', (int) $item['id'], array( 'status' => 'returned' ) );
		SVM_Audit::log( 'payment_run_item', (int) $item['id'], 'returned' );
	}

	/**
	 * Prüft, ob eine Forderung bereits in einem gültigen Lauf enthalten ist.
	 *
	 * @param int $invoice_id Forderungs-ID.
	 * @return bool
	 */
	public static function already_collected( $invoice_id ) {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . SVM_DB::table( 'payment_run_items' ) . ' i
				INNER JOIN ' . SVM_DB::table( 'payment_runs' ) . ' r ON r.id = i.run_id
				WHERE i.invoice_id = %d AND i.status <> %s AND r.status <> %s',
				(int) $invoice_id,
				'returned',
				'cancelled'
			)
		); // phpcs:ignore WordPress.DB

		return $count > 0;
	}

	/**
	 * Frühestmögliches Einzugsdatum nach den Vorlauffristen.
	 *
	 * @param array $profile Profil.
	 * @param array $items   Positionen.
	 * @return string
	 */
	public static function earliest_collection_date( array $profile, array $items ) {
		$days = (int) $profile['lead_days_rcur'];

		foreach ( $items as $item ) {
			if ( isset( $item['sequence_type'] ) && in_array( $item['sequence_type'], array( 'FRST', 'OOFF' ), true ) ) {
				$days = max( $days, (int) $profile['lead_days_frst'] );
			}
		}

		return self::add_business_days( gmdate( 'Y-m-d' ), $days );
	}

	/**
	 * Addiert Banktage (ohne Wochenenden).
	 *
	 * @param string $date Startdatum.
	 * @param int    $days Anzahl Banktage.
	 * @return string
	 */
	public static function add_business_days( $date, $days ) {
		$current = new DateTime( $date );
		$added   = 0;

		while ( $added < $days ) {
			$current->modify( '+1 day' );

			if ( (int) $current->format( 'N' ) < 6 ) {
				$added++;
			}
		}

		return $current->format( 'Y-m-d' );
	}

	/**
	 * Erzeugt eine eindeutige Nachrichten-ID.
	 *
	 * @return string
	 */
	private static function message_id() {
		return 'SVM' . gmdate( 'YmdHis' ) . wp_rand( 100, 999 );
	}

	/**
	 * Ende-zu-Ende-Referenz einer Forderung.
	 *
	 * @param array $invoice Forderung.
	 * @return string
	 */
	private static function end_to_end_id( array $invoice ) {
		$member = SVM_Members::get( (int) $invoice['member_id'] );
		$number = $member ? $member['member_number'] : (string) $invoice['member_id'];

		return SVM_SEPA_Generator::text( $number . '-' . $invoice['id'], 35 );
	}

	/**
	 * Zahlart, unter der automatische Buchungen erfasst werden.
	 *
	 * @return int
	 */
	private static function file_method_id() {
		$methods = SVM_Payment_Methods::file_based();

		return empty( $methods ) ? 0 : (int) $methods[0]['id'];
	}

	/**
	 * Statuswerte eines Laufs.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'created'   => __( 'erzeugt', 'svm' ),
			'submitted' => __( 'bei der Bank eingereicht', 'svm' ),
			'executed'  => __( 'ausgeführt (Zahlungen gebucht)', 'svm' ),
			'cancelled' => __( 'storniert', 'svm' ),
		);
	}
}
