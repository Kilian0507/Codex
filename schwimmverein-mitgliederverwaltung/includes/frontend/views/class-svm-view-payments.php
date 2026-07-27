<?php
/**
 * Zahlungsansichten.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Zahlungserfassung, Journal, SEPA-Läufe, Bankprofile und Mahnwesen.
 */
class SVM_View_Payments {

	/**
	 * Reiter dieses Bereichs.
	 *
	 * @return array
	 */
	private static function tabs() {
		return array(
			'payments'     => __( 'Zahlungen', 'svm' ),
			'payment_new'  => __( 'Zahlung eintragen', 'svm' ),
			'sepa'         => __( 'Lastschrift', 'svm' ),
			'bankaccounts' => __( 'Bankdaten des Vereins', 'svm' ),
			'dunning'      => __( 'Mahnwesen', 'svm' ),
		);
	}

	/**
	 * Zahlungsjournal.
	 *
	 * @return void
	 */
	public static function listing() {
		$search = SVM_App::arg( 's' );
		$from   = SVM_App::arg( 'from' );
		$to     = SVM_App::arg( 'to' );

		$actions = array();

		if ( SVM_Permissions::current_user_can( 'payments_record' ) ) {
			$actions[] = array(
				'label'   => __( 'Zahlung eintragen', 'svm' ),
				'url'     => SVM_App::url( 'payment_new' ),
				'primary' => true,
			);
		}

		SVM_UI::page_head(
			__( 'Zahlungen', 'svm' ),
			__( 'Alle Zahlungseingänge mit dem tatsächlichen Zahler.', 'svm' ),
			$actions
		);

		SVM_App::subnav( self::tabs(), 'payments' );

		SVM_UI::filter_bar(
			array(
				SVM_UI::input( 's', $search, array( 'placeholder' => __( 'Zahler oder Verwendungszweck', 'svm' ) ) ),
				SVM_UI::input( 'from', $from, array( 'type' => 'date' ) ),
				SVM_UI::input( 'to', $to, array( 'type' => 'date' ) ),
			),
			array( 'svm_view' => 'payments' )
		);

		$payments = SVM_Payments::query(
			array(
				'search' => $search,
				'from'   => $from,
				'to'     => $to,
				'limit'  => 200,
			)
		);

		$rows = array();

		foreach ( $payments as $payment ) {
			$member_id   = (int) $payment['member_id'];
			$member_name = SVM_Members::display_name( $member_id );
			$differs     = 0 !== strcasecmp( trim( $payment['payer_name'] ), trim( $member_name ) );
			$method      = SVM_Payment_Methods::get( (int) $payment['payment_method_id'] );

			$parts = array();

			foreach ( SVM_Payments::allocations( (int) $payment['id'] ) as $allocation ) {
				$invoice = SVM_Invoices::get( (int) $allocation['invoice_id'] );
				$parts[] = ( $invoice ? $invoice['description'] : '#' . $allocation['invoice_id'] ) .
					' (' . number_format_i18n( (float) $allocation['amount'], 2 ) . ')';
			}

			$actions = '';

			if ( SVM_Permissions::current_user_can( 'payments_record' ) ) {
				if ( 'booked' === $payment['status'] ) {
					$actions .= SVM_View_Members::button_cell(
						'return_payment',
						array( 'id' => (int) $payment['id'] ),
						__( 'Rücklastschrift', 'svm' ),
						__( 'Zahlung als zurückgegangen kennzeichnen?', 'svm' )
					);
				}

				$actions .= SVM_View_Members::button_cell(
					'delete_payment',
					array( 'id' => (int) $payment['id'] ),
					__( 'Löschen', 'svm' ),
					__( 'Zahlung wirklich löschen? Die zugeordneten Forderungen werden wieder offen.', 'svm' )
				);

				$actions = '<div class="svm-button-row">' . $actions . '</div>';
			}

			$rows[] = array(
				esc_html( SVM_UI::date( $payment['paid_at'] ) ),
				'<a href="' . esc_url( SVM_App::url( 'member', array( 'id' => $member_id ) ) ) . '">' .
					esc_html( $member_name ) . '</a>',
				esc_html( $payment['payer_name'] ) . ( $differs ? ' ' . SVM_UI::badge( __( 'abweichend', 'svm' ), 'warn' ) : '' ),
				'<span class="svm-strong">' . esc_html( SVM_UI::money( $payment['amount'] ) ) . '</span>',
				esc_html( $method ? $method['label'] : '—' ),
				empty( $parts )
					? '<span class="svm-muted">' . esc_html__( 'Guthaben', 'svm' ) . '</span>'
					: esc_html( implode( ', ', $parts ) ),
				'returned' === $payment['status']
					? SVM_UI::badge( __( 'zurückgegangen', 'svm' ), 'bad' )
					: SVM_UI::badge( __( 'gebucht', 'svm' ), 'ok' ),
				$actions,
			);
		}

		SVM_UI::table(
			array(
				__( 'Datum', 'svm' ),
				__( 'Mitglied', 'svm' ),
				__( 'Gezahlt von', 'svm' ),
				__( 'Betrag', 'svm' ),
				__( 'Zahlart', 'svm' ),
				__( 'Verrechnet mit', 'svm' ),
				__( 'Status', 'svm' ),
				'',
			),
			$rows,
			__( 'Noch keine Zahlungen erfasst.', 'svm' )
		);
	}

	/**
	 * Zahlung erfassen.
	 *
	 * @return void
	 */
	public static function record() {
		$member_id = SVM_App::id( 'member_id' );

		SVM_UI::page_head(
			__( 'Zahlung eintragen', 'svm' ),
			__( 'Für Überweisungen und Barzahlungen. Lastschriften werden beim Zahllauf automatisch gebucht.', 'svm' )
		);

		SVM_App::subnav( self::tabs(), 'payment_new' );

		SVM_UI::card_open( __( 'Wer hat gezahlt?', 'svm' ) );
		SVM_UI::filter_bar(
			array(
				SVM_UI::select( 'member_id', $member_id, SVM_App::member_options( __( '— Mitglied wählen —', 'svm' ) ) ),
			),
			array( 'svm_view' => 'payment_new' )
		);
		SVM_UI::card_close();

		if ( ! $member_id ) {
			SVM_UI::empty_state( __( 'Bitte zuerst das Mitglied auswählen, dem die Zahlung gutgeschrieben wird.', 'svm' ) );
			return;
		}

		$invoices = SVM_Invoices::open_for_member( $member_id );
		$open     = SVM_Invoices::open_total( $member_id );

		SVM_UI::card_open(
			SVM_Members::display_name( $member_id ),
			sprintf(
				/* translators: %s: offener Betrag. */
				__( 'Aktuell offen: %s', 'svm' ),
				SVM_UI::money( $open )
			)
		);

		SVM_UI::form_open( 'record_payment', array( 'member_id' => $member_id ) );

		SVM_UI::grid_open();
		SVM_UI::field(
			__( 'Betrag', 'svm' ),
			SVM_UI::input( 'amount', number_format( $open, 2, '.', '' ), array( 'type' => 'number', 'step' => '0.01', 'required' => true ) )
		);
		SVM_UI::field( __( 'Zahlungsdatum', 'svm' ), SVM_UI::input( 'paid_at', gmdate( 'Y-m-d' ), array( 'type' => 'date' ) ) );
		SVM_UI::field(
			__( 'Name des Zahlers', 'svm' ),
			SVM_UI::input( 'payer_name', '', array( 'placeholder' => SVM_Members::display_name( $member_id ) ) ),
			__( 'Leer lassen, wenn das Mitglied selbst gezahlt hat. Sonst z. B. den Namen des Elternteils eintragen.', 'svm' )
		);
		SVM_UI::field(
			__( 'Zahlart', 'svm' ),
			SVM_UI::select( 'payment_method_id', 0, array( 0 => __( '— keine Angabe —', 'svm' ) ) + SVM_Payment_Methods::options() )
		);
		SVM_UI::field( __( 'Verwendungszweck', 'svm' ), SVM_UI::input( 'reference', '' ) );
		SVM_UI::field( __( 'Notiz', 'svm' ), SVM_UI::input( 'note', '' ) );
		SVM_UI::grid_close();

		if ( ! empty( $invoices ) ) {
			echo '<h4 class="svm-section-title">' . esc_html__( 'Womit wird verrechnet?', 'svm' ) . '</h4>';
			echo '<p class="svm-help">' . esc_html__( 'Beträge anpassen für Teilzahlungen. Was übrig bleibt, wird als Guthaben verbucht.', 'svm' ) . '</p>';

			$rows = array();

			foreach ( $invoices as $invoice ) {
				$remaining = SVM_Invoices::remaining( $invoice );

				$rows[] = array(
					esc_html( $invoice['description'] ),
					esc_html( SVM_UI::date( $invoice['due_date'] ) ),
					esc_html( SVM_UI::money( $remaining ) ),
					SVM_UI::input(
						'allocation[' . (int) $invoice['id'] . ']',
						number_format( $remaining, 2, '.', '' ),
						array( 'type' => 'number', 'step' => '0.01' )
					),
				);
			}

			SVM_UI::table(
				array( __( 'Forderung', 'svm' ), __( 'Fällig', 'svm' ), __( 'Offen', 'svm' ), __( 'Davon zahlen', 'svm' ) ),
				$rows
			);
		} else {
			SVM_UI::notice( __( 'Dieses Mitglied hat keine offenen Forderungen — der Betrag wird als Guthaben verbucht.', 'svm' ), 'info' );
		}

		SVM_UI::form_close( __( 'Zahlung speichern', 'svm' ) );
		SVM_UI::card_close();
	}

	/**
	 * SEPA-Lastschrift.
	 *
	 * @return void
	 */
	public static function sepa() {
		$profile_id = SVM_App::id( 'profile_id' );
		$mode       = 'annual' === SVM_App::arg( 'mode' ) ? 'annual' : 'due';
		$year       = SVM_App::id( 'year' );
		$year       = $year > 0 ? $year : (int) gmdate( 'Y' );
		$aggregate  = '0' !== SVM_App::arg( 'aggregate', '1' );

		SVM_UI::page_head(
			__( 'Lastschrift einziehen', 'svm' ),
			__( 'Erzeugt die Datei für das Online-Banking. Vorher zeigt die Vorschau, was eingezogen wird.', 'svm' )
		);

		SVM_App::subnav( self::tabs(), 'sepa' );

		$profiles = SVM_File_Profiles::options();

		if ( empty( $profiles ) ) {
			SVM_UI::empty_state(
				__( 'Es ist noch kein Bankprofil hinterlegt.', 'svm' ),
				SVM_App::url( 'bankaccounts' ),
				__( 'Bankdaten eintragen', 'svm' )
			);
			return;
		}

		SVM_UI::card_open(
			__( 'Was soll eingezogen werden?', 'svm' ),
			'annual' === $mode
				? __( 'Jahreseinzug: alle Beiträge des gewählten Jahres für jedes Mitglied mit Lastschrift — unabhängig davon, ob sie schon fällig sind.', 'svm' )
				: __( 'Fällige Beiträge: nur das, was bis heute fällig geworden ist.', 'svm' )
		);

		$years = array();

		for ( $y = (int) gmdate( 'Y' ) + 1; $y >= (int) gmdate( 'Y' ) - 3; $y-- ) {
			$years[ $y ] = (string) $y;
		}

		SVM_UI::filter_bar(
			array(
				SVM_UI::select( 'profile_id', $profile_id, array( 0 => __( '— Bankprofil wählen —', 'svm' ) ) + $profiles ),
				SVM_UI::select(
					'mode',
					$mode,
					array(
						'due'    => __( 'Nur fällige Beiträge', 'svm' ),
						'annual' => __( 'Jahreseinzug — ganzes Jahr', 'svm' ),
					)
				),
				SVM_UI::select( 'year', $year, $years ),
				SVM_UI::select(
					'aggregate',
					$aggregate ? '1' : '0',
					array(
						'1' => __( 'eine Buchung je Mitglied', 'svm' ),
						'0' => __( 'eine Buchung je Beitrag', 'svm' ),
					)
				),
			),
			array( 'svm_view' => 'sepa' )
		);

		SVM_UI::card_close();

		if ( $profile_id ) {
			$preview = SVM_Payment_Runs::preview(
				array(
					'file_profile_id' => $profile_id,
					'mode'            => $mode,
					'year'            => $year,
					'aggregate'       => $aggregate,
				)
			);

			foreach ( array_merge( $preview['warnings'], $preview['skipped'] ) as $warning ) {
				SVM_UI::notice( $warning, 'warning' );
			}

			self::missing_members( $preview );

			if ( ! empty( $preview['items'] ) ) {
				$file_items = ! empty( $preview['file_items'] ) ? $preview['file_items'] : $preview['items'];

				SVM_UI::stats(
					array(
						array( 'label' => __( 'Mitglieder', 'svm' ), 'value' => number_format_i18n( $preview['totals']['members'] ) ),
						array(
							'label' => __( 'Buchungen in der Datei', 'svm' ),
							'value' => number_format_i18n( count( $file_items ) ),
							'hint'  => sprintf(
								/* translators: %d: Anzahl Forderungen. */
								__( 'aus %d Forderungen', 'svm' ),
								$preview['totals']['count']
							),
						),
						array( 'label' => __( 'Summe', 'svm' ), 'value' => SVM_UI::money( $preview['totals']['amount'] ) ),
						array(
							'label' => __( 'Frühester Einzug', 'svm' ),
							'value' => SVM_UI::date( isset( $preview['collection_date'] ) ? $preview['collection_date'] : '' ),
						),
					)
				);

				$rows = array();

				foreach ( $file_items as $item ) {
					$count = isset( $item['invoice_count'] ) ? (int) $item['invoice_count'] : 1;

					$rows[] = array(
						esc_html( $item['member_name'] ),
						esc_html( SVM_IBAN::mask( $item['iban'] ) ),
						esc_html( $item['mandate_ref'] . ' · ' . $item['sequence_type'] ),
						'<span class="svm-strong">' . esc_html( SVM_UI::money( $item['amount'] ) ) . '</span>' .
							( $count > 1
								? '<br /><span class="svm-muted">' . esc_html(
									sprintf(
										/* translators: %d: Anzahl Beiträge. */
										_n( '%d Beitrag', '%d Beiträge', $count, 'svm' ),
										$count
									)
								) . '</span>'
								: '' ),
						esc_html( $item['purpose'] ),
					);
				}

				SVM_UI::card_open(
					__( 'Das wird eingezogen', 'svm' ),
					'annual' === $mode && $aggregate
						? __( 'Je Mitglied entsteht eine einzige Buchung über alle Beiträge des Jahres.', 'svm' )
						: ''
				);
				SVM_UI::table(
					array( __( 'Mitglied', 'svm' ), __( 'Konto', 'svm' ), __( 'Mandat', 'svm' ), __( 'Betrag', 'svm' ), __( 'Verwendungszweck', 'svm' ) ),
					$rows
				);
				SVM_UI::card_close();

				SVM_UI::card_open( __( 'Datei erzeugen', 'svm' ) );
				SVM_UI::form_open(
					'create_payment_run',
					array(
						'file_profile_id' => $profile_id,
						'mode'            => $mode,
						'year'            => $year,
						'aggregate'       => $aggregate ? 1 : 0,
					)
				);
				SVM_UI::grid_open();
				SVM_UI::field(
					__( 'Bezeichnung', 'svm' ),
					SVM_UI::input(
						'label',
						'annual' === $mode
							/* translators: %d: Jahr. */
							? sprintf( __( 'Jahreseinzug %d', 'svm' ), $year )
							: '',
						array( 'placeholder' => __( 'z. B. Beitragseinzug März', 'svm' ) )
					)
				);
				SVM_UI::field(
					__( 'Einzugsdatum', 'svm' ),
					SVM_UI::input(
						'collection_date',
						isset( $preview['collection_date'] ) ? $preview['collection_date'] : '',
						array( 'type' => 'date' )
					)
				);
				SVM_UI::grid_close();
				SVM_UI::form_close( __( 'Lastschriftdatei erzeugen', 'svm' ) );
				SVM_UI::card_close();
			}
		}

		self::run_list();
	}

	/**
	 * Zeigt Lastschrift-Mitglieder, die nicht im Lauf enthalten sind.
	 *
	 * @param array $preview Vorschau.
	 * @return void
	 */
	private static function missing_members( array $preview ) {
		if ( empty( $preview['no_dues'] ) ) {
			return;
		}

		$rows = array();

		foreach ( $preview['no_dues'] as $entry ) {
			$rows[] = array(
				'<a href="' . esc_url( SVM_App::url( 'member', array( 'id' => (int) $entry['member_id'] ) ) ) . '">' .
					esc_html( $entry['name'] ) . '</a>',
				esc_html( $entry['reason'] ),
			);
		}

		SVM_UI::card_open(
			__( 'Nicht im Einzug enthalten', 'svm' ),
			__( 'Diese Mitglieder zahlen per Lastschrift, haben für den Zeitraum aber nichts Offenes. Fehlen Forderungen, berechnen Sie zuerst die Beiträge.', 'svm' )
		);

		SVM_UI::table( array( __( 'Mitglied', 'svm' ), __( 'Grund', 'svm' ) ), $rows );

		echo '<div class="svm-button-row">';
		printf(
			'<a class="svm-btn svm-btn-secondary svm-btn-small" href="%s">%s</a>',
			esc_url( SVM_App::url( 'feerun' ) ),
			esc_html__( 'Zur Beitragsberechnung', 'svm' )
		);
		echo '</div>';

		SVM_UI::card_close();
	}

	/**
	 * Bisherige Zahlläufe.
	 *
	 * @return void
	 */
	private static function run_list() {
		$runs     = SVM_Payment_Runs::all();
		$statuses = SVM_Payment_Runs::statuses();

		if ( empty( $runs ) ) {
			return;
		}

		$rows = array();

		foreach ( $runs as $run ) {
			$run_id = (int) $run['id'];

			ob_start();
			echo '<div class="svm-button-row">';
			SVM_UI::action_button( 'download_run_file', array( 'id' => $run_id ), __( 'Datei laden', 'svm' ), 'primary' );

			// Ein stornierter Lauf ist zurückgebucht und kann weg.
			if ( 'cancelled' === $run['status'] ) {
				SVM_UI::action_button(
					'delete_payment_run',
					array( 'id' => $run_id ),
					__( 'Löschen', 'svm' ),
					'danger',
					__( 'Stornierten Zahllauf endgültig löschen?', 'svm' )
				);
			}

			echo '</div>';
			$download = (string) ob_get_clean();

			ob_start();
			SVM_UI::form_open( 'set_run_status', array( 'id' => $run_id ) );
			echo SVM_UI::select( 'status', $run['status'], $statuses ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<button type="submit" class="svm-btn svm-btn-secondary svm-btn-small">' . esc_html__( 'Setzen', 'svm' ) . '</button>';
			echo '</form>';
			$status_form = (string) ob_get_clean();

			$rows[] = array(
				esc_html( $run['label'] ),
				esc_html( SVM_UI::date( $run['collection_date'] ) ),
				esc_html( number_format_i18n( $run['item_count'] ) ),
				esc_html( SVM_UI::money( $run['total_amount'] ) ),
				$status_form,
				$download,
			);
		}

		SVM_UI::card_open(
			__( 'Bisherige Einzüge', 'svm' ),
			__( 'Sobald die Bank ausgeführt hat, den Status auf „ausgeführt“ setzen — dann werden die Zahlungen automatisch gebucht.', 'svm' )
		);
		SVM_UI::table(
			array( __( 'Bezeichnung', 'svm' ), __( 'Einzug', 'svm' ), __( 'Positionen', 'svm' ), __( 'Summe', 'svm' ), __( 'Status', 'svm' ), '' ),
			$rows
		);
		SVM_UI::card_close();
	}

	/**
	 * Bankprofile des Vereins.
	 *
	 * @return void
	 */
	public static function profiles() {
		$profile_id = SVM_App::id();
		$profile    = $profile_id ? SVM_File_Profiles::get( $profile_id ) : null;

		SVM_UI::page_head(
			__( 'Bankdaten des Vereins', 'svm' ),
			__( 'Konto und Gläubiger-Identifikationsnummer für den Lastschrifteinzug.', 'svm' )
		);

		SVM_App::subnav( self::tabs(), 'bankaccounts' );

		$formats = SVM_File_Profiles::formats();
		$rows    = array();

		foreach ( SVM_File_Profiles::all() as $item ) {
			$complete = '' !== trim( $item['creditor_iban'] ) && '' !== trim( $item['creditor_id'] );

			$rows[] = array(
				'<a class="svm-strong" href="' . esc_url( SVM_App::url( 'bankaccounts', array( 'id' => (int) $item['id'] ) ) ) . '">' .
					esc_html( $item['label'] ) . '</a>',
				esc_html( isset( $formats[ $item['format'] ] ) ? $formats[ $item['format'] ] : $item['format'] ),
				esc_html( SVM_IBAN::mask( $item['creditor_iban'] ) ),
				$complete
					? SVM_UI::badge( __( 'einsatzbereit', 'svm' ), 'ok' )
					: SVM_UI::badge( __( 'unvollständig', 'svm' ), 'warn' ),
			);
		}

		SVM_UI::table(
			array( __( 'Bezeichnung', 'svm' ), __( 'Verfahren', 'svm' ), __( 'Konto', 'svm' ), __( 'Status', 'svm' ) ),
			$rows,
			__( 'Noch kein Bankprofil angelegt.', 'svm' )
		);

		SVM_UI::card_open(
			$profile ? __( 'Bankprofil bearbeiten', 'svm' ) : __( 'Neues Bankprofil', 'svm' ),
			__( 'Für den Beitragseinzug wird eine Lastschriftdatei erzeugt — der Verein zieht ein. Eine Überweisungsdatei brauchen Sie nur für eigene Auszahlungen.', 'svm' )
		);

		SVM_UI::form_open( 'save_file_profile', array( 'id' => $profile_id ) );
		SVM_UI::grid_open();
		SVM_UI::field( __( 'Bezeichnung', 'svm' ), SVM_UI::input( 'label', $profile ? $profile['label'] : '', array( 'required' => true ) ) );
		SVM_UI::field( __( 'Verfahren', 'svm' ), SVM_UI::select( 'format', $profile ? $profile['format'] : 'pain.008.001.02', $formats ) );
		SVM_UI::field(
			__( 'Name des Vereins', 'svm' ),
			SVM_UI::input( 'creditor_name', $profile ? $profile['creditor_name'] : get_option( 'svm_club_name', '' ) )
		);
		SVM_UI::field( __( 'IBAN des Vereinskontos', 'svm' ), SVM_UI::input( 'creditor_iban', $profile ? $profile['creditor_iban'] : '' ) );
		SVM_UI::field( __( 'BIC', 'svm' ), SVM_UI::input( 'creditor_bic', $profile ? $profile['creditor_bic'] : '', array( 'placeholder' => __( 'optional', 'svm' ) ) ) );
		SVM_UI::field(
			__( 'Gläubiger-Identifikationsnummer', 'svm' ),
			SVM_UI::input( 'creditor_id', $profile ? $profile['creditor_id'] : '' ),
			__( 'Wird von der Deutschen Bundesbank vergeben und ist für Lastschriften zwingend.', 'svm' )
		);
		SVM_UI::field(
			__( 'Verwendungszweck', 'svm' ),
			SVM_UI::input( 'purpose_template', $profile ? $profile['purpose_template'] : 'Beitrag {zeitraum} {mitgliedsnummer}' ),
			__( 'Platzhalter: {zeitraum} {mitgliedsnummer} {name} {beschreibung} {verein}', 'svm' )
		);
		SVM_UI::field(
			__( 'Vorlauf Erstlastschrift (Banktage)', 'svm' ),
			SVM_UI::input( 'lead_days_frst', $profile ? $profile['lead_days_frst'] : 5, array( 'type' => 'number' ) )
		);
		SVM_UI::field(
			__( 'Vorlauf Folgelastschrift (Banktage)', 'svm' ),
			SVM_UI::input( 'lead_days_rcur', $profile ? $profile['lead_days_rcur'] : 2, array( 'type' => 'number' ) )
		);
		SVM_UI::field( '', SVM_UI::checkbox( 'batch_booking', $profile ? $profile['batch_booking'] : 1, __( 'Als Sammelbuchung einreichen', 'svm' ) ) );
		SVM_UI::field( '', SVM_UI::checkbox( 'is_active', $profile ? $profile['is_active'] : 1, __( 'Profil verwenden', 'svm' ) ) );
		SVM_UI::grid_close();
		SVM_UI::form_close( __( 'Bankprofil speichern', 'svm' ) );

		if ( $profile ) {
			echo '<div class="svm-button-row">';
			SVM_UI::action_button( 'delete_file_profile', array( 'id' => $profile_id ), __( 'Profil löschen', 'svm' ), 'danger' );
			echo '</div>';
		}

		SVM_UI::card_close();
	}

	/**
	 * Mahnwesen.
	 *
	 * @return void
	 */
	public static function dunning() {
		$level_id = SVM_App::id();
		$level    = $level_id ? SVM_Dunning::get_level( $level_id ) : null;

		SVM_UI::page_head(
			__( 'Mahnwesen', 'svm' ),
			__( 'Erinnerungen an überfällige Beiträge. Wer nicht mahnen möchte, legt einfach keine Stufe an.', 'svm' )
		);

		SVM_App::subnav( self::tabs(), 'dunning' );

		$rows = array();

		foreach ( SVM_Dunning::levels() as $item ) {
			$rows[] = array(
				'<a class="svm-strong" href="' . esc_url( SVM_App::url( 'dunning', array( 'id' => (int) $item['id'] ) ) ) . '">' .
					esc_html( $item['label'] ) . '</a>',
				esc_html( sprintf( /* translators: %d: Tage. */ __( '%d Tage nach Fälligkeit', 'svm' ), (int) $item['days_after_due'] ) ),
				esc_html( SVM_UI::money( $item['fee'] ) ),
				$item['is_automatic'] ? SVM_UI::badge( __( 'automatisch', 'svm' ), 'ok' ) : SVM_UI::badge( __( 'manuell', 'svm' ), 'neutral' ),
				esc_html( number_format_i18n( count( SVM_Dunning::due_invoices( $item ) ) ) ),
			);
		}

		SVM_UI::table(
			array( __( 'Stufe', 'svm' ), __( 'Frist', 'svm' ), __( 'Gebühr', 'svm' ), __( 'Auslösung', 'svm' ), __( 'Betroffen', 'svm' ) ),
			$rows,
			__( 'Es ist keine Mahnstufe angelegt.', 'svm' )
		);

		if ( ! empty( $rows ) ) {
			SVM_UI::card_open();
			echo '<div class="svm-button-row">';
			SVM_UI::action_button(
				'apply_dunning',
				array(),
				__( 'Automatische Stufen jetzt ausführen', 'svm' ),
				'secondary',
				__( 'Mahnstufen jetzt anwenden?', 'svm' )
			);
			echo '</div>';
			SVM_UI::card_close();
		}

		SVM_UI::card_open( $level ? __( 'Mahnstufe bearbeiten', 'svm' ) : __( 'Neue Mahnstufe', 'svm' ) );
		SVM_UI::form_open( 'save_dunning_level', array( 'id' => $level_id ) );
		SVM_UI::grid_open();
		SVM_UI::field( __( 'Bezeichnung', 'svm' ), SVM_UI::input( 'label', $level ? $level['label'] : '', array( 'required' => true ) ) );
		SVM_UI::field( __( 'Stufe', 'svm' ), SVM_UI::input( 'level_no', $level ? $level['level_no'] : 1, array( 'type' => 'number', 'min' => '1' ) ) );
		SVM_UI::field( __( 'Tage nach Fälligkeit', 'svm' ), SVM_UI::input( 'days_after_due', $level ? $level['days_after_due'] : 14, array( 'type' => 'number' ) ) );
		SVM_UI::field( __( 'Mahngebühr', 'svm' ), SVM_UI::input( 'fee', $level ? $level['fee'] : '0.00', array( 'type' => 'number', 'step' => '0.01' ) ) );
		SVM_UI::field( __( 'E-Mail-Vorlage', 'svm' ), SVM_UI::select( 'template_id', $level ? $level['template_id'] : 0, SVM_Templates::options( 'email' ) ) );
		SVM_UI::field( '', SVM_UI::checkbox( 'is_automatic', $level ? $level['is_automatic'] : 0, __( 'Täglich automatisch anwenden', 'svm' ) ) );
		SVM_UI::field( '', SVM_UI::checkbox( 'is_active', $level ? $level['is_active'] : 1, __( 'Stufe verwenden', 'svm' ) ) );
		SVM_UI::grid_close();
		SVM_UI::form_close( __( 'Mahnstufe speichern', 'svm' ) );

		if ( $level ) {
			echo '<div class="svm-button-row">';
			SVM_UI::action_button( 'delete_dunning_level', array( 'id' => $level_id ), __( 'Stufe löschen', 'svm' ), 'danger' );
			echo '</div>';
		}

		SVM_UI::card_close();
	}
}
