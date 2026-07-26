<?php
/**
 * Zahlungsseite.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Zahlungserfassung, Zahlungsjournal, Dateiprofile, Zahlläufe und Mahnwesen.
 */
class SVM_Page_Payments {

	/**
	 * Gibt die Seite aus.
	 *
	 * @return void
	 */
	public static function render() {
		$tabs = array(
			'record'   => __( 'Zahlung erfassen', 'svm' ),
			'journal'  => __( 'Zahlungsjournal', 'svm' ),
			'runs'     => __( 'Zahlläufe (SEPA)', 'svm' ),
			'profiles' => __( 'Dateiprofile', 'svm' ),
			'dunning'  => __( 'Mahnwesen', 'svm' ),
		);

		$current = SVM_Admin_Menu::current_tab( 'record' );

		echo '<div class="wrap svm-wrap">';
		echo '<h1>' . esc_html__( 'Zahlungen', 'svm' ) . '</h1>';

		SVM_Admin_Menu::notices();
		SVM_Admin_Menu::tabs( 'svm-payments', $tabs, $current );

		switch ( $current ) {
			case 'journal':
				self::render_journal();
				break;

			case 'runs':
				self::render_runs();
				break;

			case 'profiles':
				self::render_profiles();
				break;

			case 'dunning':
				self::render_dunning();
				break;

			default:
				self::render_record();
				break;
		}

		echo '</div>';
	}

	/**
	 * Formular zur manuellen Zahlungserfassung.
	 *
	 * @return void
	 */
	private static function render_record() {
		SVM_Permissions::require_permission( 'payments_record' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$member_id = isset( $_GET['member_id'] ) ? absint( $_GET['member_id'] ) : 0;

		echo '<h2>' . esc_html__( 'Überweisung oder Barzahlung erfassen', 'svm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Hier wird eingetragen, wer tatsächlich gezahlt hat — der Zahler kann vom Mitglied abweichen, etwa wenn ein Elternteil für ein Kind überweist.', 'svm' ) . '</p>';

		// Mitgliedsauswahl.
		echo '<form method="get" class="svm-filters">';
		echo '<input type="hidden" name="page" value="svm-payments" />';
		echo '<input type="hidden" name="tab" value="record" />';

		$members = array( 0 => __( '— Mitglied wählen —', 'svm' ) );

		foreach ( SVM_Members::query( array( 'limit' => 1000 ) ) as $member ) {
			$id             = (int) $member['id'];
			$open           = SVM_Invoices::open_total( $id );
			$members[ $id ] = SVM_Members::display_name( $id ) . ' (' . $member['member_number'] . ')' .
				( $open > 0 ? ' — ' . number_format_i18n( $open, 2 ) . ' € offen' : '' );
		}

		echo SVM_Form::select( 'member_id', $member_id, $members ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo ' <button type="submit" class="button">' . esc_html__( 'Auswählen', 'svm' ) . '</button>';
		echo '</form>';

		if ( ! $member_id ) {
			return;
		}

		$invoices = SVM_Invoices::open_for_member( $member_id );
		$open     = SVM_Invoices::open_total( $member_id );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields(
			'record_payment',
			array(
				'svm_page' => 'svm-payments',
				'svm_tab'  => 'journal',
			)
		);
		echo '<input type="hidden" name="member_id" value="' . esc_attr( $member_id ) . '" />';

		SVM_Form::open_table();

		SVM_Form::row(
			__( 'Mitglied', 'svm' ),
			'<strong>' . esc_html( SVM_Members::display_name( $member_id ) ) . '</strong> — ' .
			esc_html( sprintf( /* translators: %s: Betrag. */ __( 'offen: %s €', 'svm' ), number_format_i18n( $open, 2 ) ) )
		);

		SVM_Form::row(
			__( 'Wer hat gezahlt?', 'svm' ),
			SVM_Form::input( 'payer_name', '', array( 'placeholder' => SVM_Members::display_name( $member_id ) ) ),
			__( 'Leer lassen, wenn das Mitglied selbst gezahlt hat. Sonst den Namen des tatsächlichen Zahlers eintragen.', 'svm' )
		);

		SVM_Form::row(
			__( 'Betrag', 'svm' ),
			SVM_Form::input( 'amount', number_format( $open, 2, '.', '' ), array( 'type' => 'number', 'step' => '0.01', 'class' => 'small-text', 'required' => true ) )
		);

		SVM_Form::row( __( 'Zahlungsdatum', 'svm' ), SVM_Form::input( 'paid_at', gmdate( 'Y-m-d' ), array( 'type' => 'date' ) ) );

		SVM_Form::row(
			__( 'Zahlart', 'svm' ),
			SVM_Form::select( 'payment_method_id', 0, array( 0 => __( '— keine —', 'svm' ) ) + SVM_Payment_Methods::options() )
		);

		SVM_Form::row( __( 'Verwendungszweck / Referenz', 'svm' ), SVM_Form::input( 'reference', '' ) );
		SVM_Form::row( __( 'Notiz', 'svm' ), SVM_Form::textarea( 'note', '', array( 'rows' => 2 ) ) );

		SVM_Form::close_table();

		echo '<h3>' . esc_html__( 'Zuordnung zu offenen Forderungen', 'svm' ) . '</h3>';

		if ( empty( $invoices ) ) {
			echo '<p>' . esc_html__( 'Es gibt keine offenen Forderungen. Der Betrag wird als Guthaben verbucht.', 'svm' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Beträge anpassen für Teilzahlungen. Leer lassen für automatische Zuordnung auf die ältesten Forderungen.', 'svm' ) . '</p>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Forderung', 'svm' ) . '</th>';
			echo '<th>' . esc_html__( 'Fällig', 'svm' ) . '</th>';
			echo '<th>' . esc_html__( 'Offen', 'svm' ) . '</th>';
			echo '<th>' . esc_html__( 'Zuordnen', 'svm' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $invoices as $invoice ) {
				$remaining = SVM_Invoices::remaining( $invoice );

				echo '<tr>';
				echo '<td>' . esc_html( $invoice['description'] ) . '</td>';
				echo '<td>' . esc_html( $invoice['due_date'] ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( $remaining, 2 ) ) . ' €</td>';
				echo '<td>' . SVM_Form::input( // phpcs:ignore WordPress.Security.EscapeOutput
					'allocation[' . (int) $invoice['id'] . ']',
					number_format( $remaining, 2, '.', '' ),
					array( 'type' => 'number', 'step' => '0.01', 'class' => 'small-text' )
				) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		submit_button( __( 'Zahlung erfassen', 'svm' ) );
		echo '</form>';
	}

	/**
	 * Zahlungsjournal.
	 *
	 * @return void
	 */
	private static function render_journal() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$from   = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to     = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$payments = SVM_Payments::query(
			array(
				'search' => $search,
				'from'   => $from,
				'to'     => $to,
				'limit'  => 200,
			)
		);

		echo '<h2>' . esc_html__( 'Zahlungsjournal', 'svm' ) . '</h2>';

		echo '<form method="get" class="svm-filters">';
		echo '<input type="hidden" name="page" value="svm-payments" />';
		echo '<input type="hidden" name="tab" value="journal" />';
		echo SVM_Form::input( 's', $search, array( 'placeholder' => __( 'Zahler oder Referenz', 'svm' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo ' ' . SVM_Form::input( 'from', $from, array( 'type' => 'date' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo ' ' . SVM_Form::input( 'to', $to, array( 'type' => 'date' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo ' <button type="submit" class="button">' . esc_html__( 'Filtern', 'svm' ) . '</button>';
		echo '</form>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Datum', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Mitglied', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Zahler', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Betrag', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Zahlart', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Zugeordnet', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'svm' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( $payments as $payment ) {
			$method      = SVM_Payment_Methods::get( (int) $payment['payment_method_id'] );
			$allocations = SVM_Payments::allocations( (int) $payment['id'] );
			$parts       = array();

			foreach ( $allocations as $allocation ) {
				$invoice = SVM_Invoices::get( (int) $allocation['invoice_id'] );
				$parts[] = ( $invoice ? $invoice['description'] : '#' . $allocation['invoice_id'] ) .
					' (' . number_format_i18n( (float) $allocation['amount'], 2 ) . ' €)';
			}

			$member_name = SVM_Members::display_name( (int) $payment['member_id'] );
			$differs     = 0 !== strcasecmp( trim( $payment['payer_name'] ), trim( $member_name ) );

			echo '<tr>';
			echo '<td>' . esc_html( $payment['paid_at'] ) . '</td>';
			echo '<td>' . esc_html( $member_name ) . '</td>';
			echo '<td>' . esc_html( $payment['payer_name'] );
			if ( $differs ) {
				echo ' <span class="svm-badge">' . esc_html__( 'abweichend', 'svm' ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( number_format_i18n( (float) $payment['amount'], 2 ) ) . ' €</td>';
			echo '<td>' . esc_html( $method ? $method['label'] : '—' ) . '</td>';
			echo '<td>' . esc_html( empty( $parts ) ? __( 'Guthaben', 'svm' ) : implode( ', ', $parts ) ) . '</td>';
			echo '<td>' . esc_html( 'returned' === $payment['status'] ? __( 'Rücklastschrift', 'svm' ) : __( 'gebucht', 'svm' ) ) . '</td>';
			echo '<td>';

			if ( SVM_Permissions::current_user_can( 'payments_record' ) && 'booked' === $payment['status'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="svm-inline-form">';
				SVM_Form::action_fields( 'return_payment', array( 'id' => (int) $payment['id'], 'svm_page' => 'svm-payments', 'svm_tab' => 'journal' ) );
				echo SVM_Form::input( 'return_fee', '', array( 'type' => 'number', 'step' => '0.01', 'class' => 'small-text', 'placeholder' => __( 'Gebühr', 'svm' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo ' <button type="submit" class="button button-small">' . esc_html__( 'Rücklastschrift', 'svm' ) . '</button>';
				echo '</form>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Zahlläufe.
	 *
	 * @return void
	 */
	private static function render_runs() {
		SVM_Permissions::require_permission( 'payment_run' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$preview_profile = isset( $_GET['preview_profile'] ) ? absint( $_GET['preview_profile'] ) : 0;

		echo '<h2>' . esc_html__( 'Neuen Zahllauf erzeugen', 'svm' ) . '</h2>';

		$profiles = SVM_File_Profiles::options();

		if ( empty( $profiles ) ) {
			echo '<div class="notice notice-warning inline"><p>' .
				esc_html__( 'Es ist noch kein Dateiprofil angelegt. Legen Sie zuerst unter „Dateiprofile“ eines an.', 'svm' ) .
				'</p></div>';
			return;
		}

		echo '<form method="get" class="svm-filters">';
		echo '<input type="hidden" name="page" value="svm-payments" />';
		echo '<input type="hidden" name="tab" value="runs" />';
		echo SVM_Form::select( 'preview_profile', $preview_profile, array( 0 => __( '— Profil wählen —', 'svm' ) ) + $profiles ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo ' <button type="submit" class="button">' . esc_html__( 'Vorschau', 'svm' ) . '</button>';
		echo '</form>';

		if ( $preview_profile ) {
			$preview = SVM_Payment_Runs::preview( array( 'file_profile_id' => $preview_profile ) );

			foreach ( array_merge( $preview['warnings'], $preview['skipped'] ) as $warning ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html( $warning ) . '</p></div>';
			}

			echo '<p><strong>' . esc_html(
				sprintf(
					/* translators: 1: Anzahl, 2: Summe, 3: Datum. */
					__( '%1$d Positionen · %2$s € · frühestes Einzugsdatum %3$s', 'svm' ),
					$preview['totals']['count'],
					number_format_i18n( $preview['totals']['amount'], 2 ),
					isset( $preview['collection_date'] ) ? $preview['collection_date'] : '—'
				)
			) . '</strong></p>';

			if ( ! empty( $preview['items'] ) ) {
				echo '<table class="widefat striped"><thead><tr>';
				echo '<th>' . esc_html__( 'Mitglied', 'svm' ) . '</th>';
				echo '<th>' . esc_html__( 'IBAN', 'svm' ) . '</th>';
				echo '<th>' . esc_html__( 'Mandat', 'svm' ) . '</th>';
				echo '<th>' . esc_html__( 'Betrag', 'svm' ) . '</th>';
				echo '<th>' . esc_html__( 'Verwendungszweck', 'svm' ) . '</th>';
				echo '</tr></thead><tbody>';

				foreach ( $preview['items'] as $item ) {
					echo '<tr>';
					echo '<td>' . esc_html( $item['member_name'] ) . '</td>';
					echo '<td>' . esc_html( SVM_IBAN::mask( $item['iban'] ) ) . '</td>';
					echo '<td>' . esc_html( $item['mandate_ref'] . ' / ' . $item['sequence_type'] ) . '</td>';
					echo '<td>' . esc_html( number_format_i18n( (float) $item['amount'], 2 ) ) . ' €</td>';
					echo '<td>' . esc_html( $item['purpose'] ) . '</td>';
					echo '</tr>';
				}

				echo '</tbody></table>';

				echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
				SVM_Form::action_fields( 'create_payment_run', array( 'svm_page' => 'svm-payments', 'svm_tab' => 'runs' ) );
				echo '<input type="hidden" name="file_profile_id" value="' . esc_attr( $preview_profile ) . '" />';

				SVM_Form::open_table();
				SVM_Form::row( __( 'Bezeichnung', 'svm' ), SVM_Form::input( 'label', '' ) );
				SVM_Form::row(
					__( 'Einzugsdatum', 'svm' ),
					SVM_Form::input( 'collection_date', isset( $preview['collection_date'] ) ? $preview['collection_date'] : '', array( 'type' => 'date' ) )
				);
				SVM_Form::close_table();

				submit_button( __( 'Zahllauf erzeugen', 'svm' ) );
				echo '</form>';
			}
		}

		echo '<h2>' . esc_html__( 'Bisherige Zahlläufe', 'svm' ) . '</h2>';

		$runs     = SVM_Payment_Runs::all();
		$statuses = SVM_Payment_Runs::statuses();

		if ( empty( $runs ) ) {
			echo '<p>' . esc_html__( 'Noch keine Zahlläufe vorhanden.', 'svm' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Bezeichnung', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Datum', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Positionen', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Summe', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'svm' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( $runs as $run ) {
			echo '<tr>';
			echo '<td>' . esc_html( $run['label'] ) . '<br /><small>' . esc_html( $run['file_name'] ) . '</small></td>';
			echo '<td>' . esc_html( $run['collection_date'] ) . '</td>';
			echo '<td>' . esc_html( $run['item_count'] ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (float) $run['total_amount'], 2 ) ) . ' €</td>';
			echo '<td>' . esc_html( isset( $statuses[ $run['status'] ] ) ? $statuses[ $run['status'] ] : $run['status'] ) . '</td>';
			echo '<td><div class="svm-action-row">';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="svm-inline-form">';
			SVM_Form::action_fields( 'download_run_file', array( 'id' => (int) $run['id'], 'svm_page' => 'svm-payments', 'svm_tab' => 'runs' ) );
			echo '<button type="submit" class="button button-small">' . esc_html__( 'Datei laden', 'svm' ) . '</button>';
			echo '</form>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="svm-inline-form">';
			SVM_Form::action_fields( 'set_run_status', array( 'id' => (int) $run['id'], 'svm_page' => 'svm-payments', 'svm_tab' => 'runs' ) );
			echo SVM_Form::select( 'status', $run['status'], $statuses ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo ' <button type="submit" class="button button-small">' . esc_html__( 'Setzen', 'svm' ) . '</button>';
			echo '</form>';

			echo '</div></td></tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Beim Status „ausgeführt“ werden die Zahlungen automatisch gebucht und die Forderungen ausgeglichen.', 'svm' ) . '</p>';
	}

	/**
	 * Dateiprofile.
	 *
	 * @return void
	 */
	private static function render_profiles() {
		SVM_Permissions::require_permission( 'payment_run' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['profile_id'] ) ? absint( $_GET['profile_id'] ) : 0;
		$profile = $edit_id ? SVM_File_Profiles::get( $edit_id ) : null;

		echo '<h2>' . esc_html__( 'Dateiprofile', 'svm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Ein Profil beschreibt Format, Vereinskonto, Fristen und Verwendungszweck. Mehrere Profile sind möglich, etwa je Sparte ein eigenes Konto.', 'svm' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Bezeichnung', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Format', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Konto', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Gläubiger-ID', 'svm' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		$formats = SVM_File_Profiles::formats();

		foreach ( SVM_File_Profiles::all() as $item ) {
			echo '<tr>';
			echo '<td>' . esc_html( $item['label'] ) . '</td>';
			echo '<td>' . esc_html( isset( $formats[ $item['format'] ] ) ? $formats[ $item['format'] ] : $item['format'] ) . '</td>';
			echo '<td>' . esc_html( SVM_IBAN::mask( $item['creditor_iban'] ) ) . '</td>';
			echo '<td>' . esc_html( $item['creditor_id'] ) . '</td>';
			echo '<td><a href="' . esc_url( SVM_Admin_Menu::url( 'svm-payments', array( 'tab' => 'profiles', 'profile_id' => (int) $item['id'] ) ) ) . '">' .
				esc_html__( 'Bearbeiten', 'svm' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h3>' . esc_html( $profile ? __( 'Profil bearbeiten', 'svm' ) : __( 'Neues Profil', 'svm' ) ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields( 'save_file_profile', array( 'id' => $edit_id, 'svm_page' => 'svm-payments', 'svm_tab' => 'profiles' ) );

		SVM_Form::open_table();
		SVM_Form::row( __( 'Bezeichnung', 'svm' ), SVM_Form::input( 'label', $profile ? $profile['label'] : '', array( 'required' => true ) ) );
		SVM_Form::row(
			__( 'Format', 'svm' ),
			SVM_Form::select( 'format', $profile ? $profile['format'] : 'pain.008.001.02', $formats ),
			__( 'pain.008 = Lastschrift (Verein zieht ein). pain.001 = Überweisung (Verein zahlt aus).', 'svm' )
		);
		SVM_Form::row( __( 'Name des Vereins', 'svm' ), SVM_Form::input( 'creditor_name', $profile ? $profile['creditor_name'] : get_option( 'svm_club_name', '' ) ) );
		SVM_Form::row( __( 'IBAN des Vereinskontos', 'svm' ), SVM_Form::input( 'creditor_iban', $profile ? $profile['creditor_iban'] : '' ) );
		SVM_Form::row( __( 'BIC (optional)', 'svm' ), SVM_Form::input( 'creditor_bic', $profile ? $profile['creditor_bic'] : '' ) );
		SVM_Form::row(
			__( 'Gläubiger-Identifikationsnummer', 'svm' ),
			SVM_Form::input( 'creditor_id', $profile ? $profile['creditor_id'] : '' ),
			__( 'Nur für Lastschriften erforderlich; wird von der Bundesbank vergeben.', 'svm' )
		);

		$placeholders = implode( ' ', array_keys( SVM_File_Profiles::purpose_placeholders() ) );

		SVM_Form::row(
			__( 'Verwendungszweck', 'svm' ),
			SVM_Form::input( 'purpose_template', $profile ? $profile['purpose_template'] : 'Beitrag {zeitraum} {mitgliedsnummer}', array( 'class' => 'large-text' ) ),
			/* translators: %s: Liste der Platzhalter. */
			sprintf( __( 'Platzhalter: %s', 'svm' ), $placeholders )
		);

		SVM_Form::row(
			__( 'Vorlauffristen (Banktage)', 'svm' ),
			esc_html__( 'Erstlastschrift:', 'svm' ) . ' ' .
			SVM_Form::input( 'lead_days_frst', $profile ? $profile['lead_days_frst'] : 5, array( 'type' => 'number', 'class' => 'small-text' ) ) .
			' ' . esc_html__( 'Folgelastschrift:', 'svm' ) . ' ' .
			SVM_Form::input( 'lead_days_rcur', $profile ? $profile['lead_days_rcur'] : 2, array( 'type' => 'number', 'class' => 'small-text' ) )
		);

		SVM_Form::row( __( 'Sammelbuchung', 'svm' ), SVM_Form::checkbox( 'batch_booking', $profile ? $profile['batch_booking'] : 1, __( 'Als Sammelbuchung einreichen', 'svm' ) ) );
		SVM_Form::row( __( 'CSV-Trennzeichen', 'svm' ), SVM_Form::input( 'csv_delimiter', $profile ? $profile['csv_delimiter'] : ';', array( 'class' => 'small-text' ) ) );
		SVM_Form::row( __( 'Aktiv', 'svm' ), SVM_Form::checkbox( 'is_active', $profile ? $profile['is_active'] : 1, __( 'Profil steht für Zahlläufe zur Verfügung', 'svm' ) ) );
		SVM_Form::close_table();

		submit_button( __( 'Profil speichern', 'svm' ) );
		echo '</form>';
	}

	/**
	 * Mahnstufen.
	 *
	 * @return void
	 */
	private static function render_dunning() {
		SVM_Permissions::require_permission( 'dunning_manage' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['level_id'] ) ? absint( $_GET['level_id'] ) : 0;
		$level   = $edit_id ? SVM_Dunning::get_level( $edit_id ) : null;

		echo '<h2>' . esc_html__( 'Mahnstufen', 'svm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Wer nicht mahnen möchte, legt einfach keine Stufe an.', 'svm' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Stufe', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Bezeichnung', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Tage nach Fälligkeit', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Gebühr', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Automatisch', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Betroffen', 'svm' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( SVM_Dunning::levels() as $item ) {
			echo '<tr>';
			echo '<td>' . esc_html( $item['level_no'] ) . '</td>';
			echo '<td>' . esc_html( $item['label'] ) . '</td>';
			echo '<td>' . esc_html( $item['days_after_due'] ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (float) $item['fee'], 2 ) ) . ' €</td>';
			echo '<td>' . esc_html( $item['is_automatic'] ? __( 'ja', 'svm' ) : __( 'nein', 'svm' ) ) . '</td>';
			echo '<td>' . esc_html( count( SVM_Dunning::due_invoices( $item ) ) ) . '</td>';
			echo '<td><a href="' . esc_url( SVM_Admin_Menu::url( 'svm-payments', array( 'tab' => 'dunning', 'level_id' => (int) $item['id'] ) ) ) . '">' .
				esc_html__( 'Bearbeiten', 'svm' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields( 'apply_dunning', array( 'svm_page' => 'svm-payments', 'svm_tab' => 'dunning' ) );
		echo '<p>' . get_submit_button( __( 'Automatische Mahnstufen jetzt ausführen', 'svm' ), 'secondary', 'submit', false ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</form>';

		echo '<h3>' . esc_html( $level ? __( 'Mahnstufe bearbeiten', 'svm' ) : __( 'Neue Mahnstufe', 'svm' ) ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields( 'save_dunning_level', array( 'id' => $edit_id, 'svm_page' => 'svm-payments', 'svm_tab' => 'dunning' ) );

		SVM_Form::open_table();
		SVM_Form::row( __( 'Stufe', 'svm' ), SVM_Form::input( 'level_no', $level ? $level['level_no'] : 1, array( 'type' => 'number', 'class' => 'small-text' ) ) );
		SVM_Form::row( __( 'Bezeichnung', 'svm' ), SVM_Form::input( 'label', $level ? $level['label'] : '', array( 'required' => true ) ) );
		SVM_Form::row( __( 'Tage nach Fälligkeit', 'svm' ), SVM_Form::input( 'days_after_due', $level ? $level['days_after_due'] : 14, array( 'type' => 'number', 'class' => 'small-text' ) ) );
		SVM_Form::row( __( 'Mahngebühr', 'svm' ), SVM_Form::input( 'fee', $level ? $level['fee'] : '0.00', array( 'type' => 'number', 'step' => '0.01', 'class' => 'small-text' ) ) );
		SVM_Form::row( __( 'E-Mail-Vorlage', 'svm' ), SVM_Form::select( 'template_id', $level ? $level['template_id'] : 0, SVM_Templates::options( 'email' ) ) );
		SVM_Form::row( __( 'Automatisch', 'svm' ), SVM_Form::checkbox( 'is_automatic', $level ? $level['is_automatic'] : 0, __( 'Täglich automatisch anwenden', 'svm' ) ) );
		SVM_Form::row( __( 'Aktiv', 'svm' ), SVM_Form::checkbox( 'is_active', $level ? $level['is_active'] : 1, __( 'Stufe verwenden', 'svm' ) ) );
		SVM_Form::close_table();

		submit_button( __( 'Mahnstufe speichern', 'svm' ) );
		echo '</form>';
	}
}
