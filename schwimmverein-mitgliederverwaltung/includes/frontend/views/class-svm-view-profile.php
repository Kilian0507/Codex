<?php
/**
 * Eigener Bereich der Mitglieder.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Was ein Mitglied ohne Verwaltungsrechte sieht: Nachrichten,
 * die eigenen Stammdaten und die eigenen Beiträge.
 */
class SVM_View_Profile {

	/**
	 * Gibt die Ansicht aus.
	 *
	 * @return void
	 */
	public static function render() {
		$member = SVM_Members::get_by_user( get_current_user_id() );

		if ( ! $member ) {
			SVM_UI::page_head( __( 'Meine Daten', 'svm' ) );
			SVM_UI::notice(
				__( 'Ihrem Benutzerkonto ist noch kein Mitgliedsdatensatz zugeordnet. Bitte wenden Sie sich an die Vereinsverwaltung.', 'svm' ),
				'info'
			);
			return;
		}

		$member_id = (int) $member['id'];
		$status    = SVM_Statuses::get( (int) $member['status_id'] );

		if ( $status && ! $status['allows_login'] ) {
			SVM_UI::page_head( __( 'Meine Daten', 'svm' ) );
			SVM_UI::notice( __( 'Für Ihren Mitgliedsstatus ist der Zugang nicht freigeschaltet.', 'svm' ), 'warning' );
			return;
		}

		SVM_UI::page_head(
			__( 'Meine Daten', 'svm' ),
			sprintf(
				/* translators: 1: Mitgliedsnummer, 2: Status. */
				__( 'Mitgliedsnummer %1$s · %2$s', 'svm' ),
				$member['member_number'],
				$status ? $status['label'] : ''
			)
		);

		self::messages( $member_id );
		self::profile( $member_id );
		self::finance( $member_id );
	}

	/**
	 * Nachrichten.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return void
	 */
	private static function messages( $member_id ) {
		$messages = SVM_Messages::for_member( $member_id, 20 );

		if ( empty( $messages ) ) {
			return;
		}

		SVM_UI::card_open( __( 'Nachrichten', 'svm' ) );

		foreach ( $messages as $message ) {
			printf(
				'<article class="svm-message%s">',
				$message['is_important'] ? ' is-important' : ''
			);

			echo '<h4>' . esc_html( $message['title'] ) . '</h4>';
			echo '<p class="svm-muted">' . esc_html( SVM_UI::date( $message['created_at'] ) ) . '</p>';
			echo '<div class="svm-message-body">' . wp_kses_post( wpautop( $message['body'] ) ) . '</div>';

			if ( $message['requires_ack'] ) {
				echo '<div class="svm-button-row">';
				SVM_UI::action_button(
					'mark_message_read',
					array( 'message_id' => (int) $message['id'] ),
					__( 'Kenntnis genommen', 'svm' )
				);
				echo '</div>';
			}

			echo '</article>';
		}

		SVM_UI::card_close();
	}

	/**
	 * Eigene Stammdaten.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return void
	 */
	private static function profile( $member_id ) {
		$can_edit = SVM_Permissions::current_user_can( 'own_profile_edit' );

		SVM_UI::card_open(
			__( 'Meine Stammdaten', 'svm' ),
			$can_edit
				? __( 'Änderungen an Name, Geburtsdatum und Bankverbindung prüft die Vereinsverwaltung, bevor sie wirksam werden.', 'svm' )
				: __( 'Ihre Daten können nur von der Vereinsverwaltung geändert werden.', 'svm' )
		);

		if ( ! $can_edit ) {
			SVM_UI::entity_fields( 'member', $member_id, true );
			SVM_UI::card_close();
			return;
		}

		SVM_UI::form_open( 'save_own_profile' );
		$editable = SVM_UI::entity_fields( 'member', $member_id );

		if ( $editable > 0 ) {
			SVM_UI::form_close( __( 'Änderungen speichern', 'svm' ) );
		} else {
			echo '</form>';
			SVM_UI::notice( __( 'Für Ihre Rolle ist derzeit kein Feld zur Bearbeitung freigegeben.', 'svm' ), 'info' );
		}

		SVM_UI::card_close();
	}

	/**
	 * Eigene Beiträge und Zahlungen.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return void
	 */
	private static function finance( $member_id ) {
		if ( ! SVM_Permissions::current_user_can( 'own_finance_view' ) ) {
			return;
		}

		$open  = SVM_Invoices::open_total( $member_id );
		$units = array();

		foreach ( SVM_Members::unit_ids( $member_id ) as $unit_id ) {
			$units[] = SVM_Units::name( $unit_id );
		}

		$stats = array(
			array( 'label' => __( 'Offener Betrag', 'svm' ), 'value' => SVM_UI::money( $open ) ),
		);

		if ( ! empty( array_filter( $units ) ) ) {
			$stats[] = array(
				'label' => __( 'Meine Sparten', 'svm' ),
				'value' => implode( ', ', array_filter( $units ) ),
			);
		}

		$mandate = SVM_Mandates::active_for_member( $member_id );

		if ( $mandate ) {
			$stats[] = array(
				'label' => __( 'Lastschrift', 'svm' ),
				'value' => SVM_IBAN::mask( $mandate['iban'] ),
				'hint'  => __( 'Der Beitrag wird eingezogen.', 'svm' ),
			);
		}

		SVM_UI::stats( $stats );

		$statuses = SVM_Invoices::statuses();
		$invoices = SVM_Invoices::query( array( 'member_id' => $member_id, 'limit' => 50, 'respect_scope' => false ) );
		$rows     = array();

		foreach ( $invoices as $invoice ) {
			$rows[] = array(
				esc_html( $invoice['description'] ),
				esc_html( SVM_UI::date( $invoice['due_date'] ) ),
				esc_html( SVM_UI::money( $invoice['amount'] ) ),
				SVM_UI::badge(
					isset( $statuses[ $invoice['status'] ] ) ? $statuses[ $invoice['status'] ] : $invoice['status'],
					'paid' === $invoice['status'] ? 'ok' : 'warn'
				),
			);
		}

		SVM_UI::card_open( __( 'Meine Beiträge', 'svm' ) );
		SVM_UI::table(
			array( __( 'Beitrag', 'svm' ), __( 'Fällig', 'svm' ), __( 'Betrag', 'svm' ), __( 'Status', 'svm' ) ),
			$rows,
			__( 'Für Sie liegen keine Beiträge vor.', 'svm' )
		);
		SVM_UI::card_close();

		$payments = SVM_Payments::query( array( 'member_id' => $member_id, 'limit' => 30, 'respect_scope' => false ) );

		if ( empty( $payments ) ) {
			return;
		}

		$rows = array();

		foreach ( $payments as $payment ) {
			$rows[] = array(
				esc_html( SVM_UI::date( $payment['paid_at'] ) ),
				esc_html( SVM_UI::money( $payment['amount'] ) ),
				esc_html( $payment['payer_name'] ),
				esc_html( $payment['reference'] ),
			);
		}

		SVM_UI::card_open( __( 'Meine Zahlungen', 'svm' ) );
		SVM_UI::table(
			array( __( 'Datum', 'svm' ), __( 'Betrag', 'svm' ), __( 'Gezahlt von', 'svm' ), __( 'Verwendungszweck', 'svm' ) ),
			$rows
		);
		SVM_UI::card_close();
	}
}
