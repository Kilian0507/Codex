<?php
/**
 * Übersichtsseite.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Zeigt die wichtigsten Zahlen und die nächsten offenen Aufgaben.
 */
class SVM_View_Dashboard {

	/**
	 * Gibt die Ansicht aus.
	 *
	 * @return void
	 */
	public static function render() {
		SVM_UI::page_head(
			__( 'Übersicht', 'svm' ),
			__( 'Der aktuelle Stand des Vereins auf einen Blick.', 'svm' )
		);

		self::stats();
		self::todos();
		self::units();
	}

	/**
	 * Kennzahlen.
	 *
	 * @return void
	 */
	private static function stats() {
		$members = SVM_Members::count();
		$stats   = array(
			array(
				'label' => __( 'Mitglieder', 'svm' ),
				'value' => number_format_i18n( $members ),
				'url'   => SVM_App::url( 'members' ),
			),
		);

		if ( SVM_Permissions::current_user_can( 'invoices_view' ) ) {
			$open = SVM_Invoices::totals( array( 'status' => 'unpaid' ) );

			$stats[] = array(
				'label' => __( 'Offene Beiträge', 'svm' ),
				'value' => SVM_UI::money( $open['open'] ),
				'hint'  => sprintf(
					/* translators: %d: Anzahl offener Posten. */
					__( '%d offene Posten', 'svm' ),
					$open['count']
				),
				'url'   => SVM_App::url( 'invoices' ),
			);
		}

		$stats[] = array(
			'label' => __( 'Familien', 'svm' ),
			'value' => number_format_i18n( count( SVM_Families::all() ) ),
			'url'   => SVM_App::url( 'families' ),
		);

		if ( SVM_Permissions::current_user_can( 'change_requests' ) ) {
			$pending = SVM_Change_Requests::pending_count();

			$stats[] = array(
				'label' => __( 'Zu prüfen', 'svm' ),
				'value' => number_format_i18n( $pending ),
				'hint'  => __( 'Änderungswünsche von Mitgliedern', 'svm' ),
				'url'   => SVM_App::url( 'requests' ),
			);
		}

		SVM_UI::stats( $stats );
	}

	/**
	 * Nächste Schritte.
	 *
	 * @return void
	 */
	private static function todos() {
		$todos = array();

		if ( empty( SVM_Members::query( array( 'limit' => 1 ) ) ) && SVM_Permissions::current_user_can( 'members_create' ) ) {
			$todos[] = array(
				__( 'Es ist noch kein Mitglied erfasst.', 'svm' ),
				__( 'Erstes Mitglied anlegen', 'svm' ),
				SVM_App::url( 'member_new' ),
			);
		}

		if ( SVM_Permissions::current_user_can( 'payment_run' ) ) {
			foreach ( SVM_File_Profiles::all( true ) as $profile ) {
				if ( '' === trim( (string) $profile['creditor_iban'] ) ) {
					$todos[] = array(
						sprintf(
							/* translators: %s: Bezeichnung des Bankprofils. */
							__( 'Im Bankprofil „%s“ fehlen noch IBAN und Gläubiger-ID.', 'svm' ),
							$profile['label']
						),
						__( 'Bankdaten ergänzen', 'svm' ),
						SVM_App::url( 'bankaccounts' ),
					);
					break;
				}
			}
		}

		if ( SVM_Permissions::current_user_can( 'change_requests' ) && SVM_Change_Requests::pending_count() > 0 ) {
			$todos[] = array(
				__( 'Mitglieder haben Änderungen an ihren Daten beantragt.', 'svm' ),
				__( 'Jetzt prüfen', 'svm' ),
				SVM_App::url( 'requests' ),
			);
		}

		if ( SVM_Permissions::current_user_can( 'invoices_view' ) ) {
			$overdue = SVM_Invoices::query(
				array(
					'status'     => 'unpaid',
					'due_before' => gmdate( 'Y-m-d' ),
					'limit'      => 1,
				)
			);

			if ( ! empty( $overdue ) ) {
				$todos[] = array(
					__( 'Es gibt überfällige Beiträge.', 'svm' ),
					__( 'Offene Posten ansehen', 'svm' ),
					SVM_App::url( 'invoices' ),
				);
			}
		}

		if ( empty( $todos ) ) {
			return;
		}

		SVM_UI::card_open( __( 'Nächste Schritte', 'svm' ) );
		echo '<ul class="svm-todo">';

		foreach ( $todos as $todo ) {
			printf(
				'<li><span>%s</span><a class="svm-btn svm-btn-secondary svm-btn-small" href="%s">%s</a></li>',
				esc_html( $todo[0] ),
				esc_url( $todo[2] ),
				esc_html( $todo[1] )
			);
		}

		echo '</ul>';
		SVM_UI::card_close();
	}

	/**
	 * Mitglieder je Sparte.
	 *
	 * @return void
	 */
	private static function units() {
		$units = SVM_Units::all( true );

		if ( empty( $units ) ) {
			return;
		}

		$rows = array();

		foreach ( $units as $unit ) {
			$unit_id = (int) $unit['id'];
			$totals  = SVM_Invoices::totals( array( 'status' => 'unpaid', 'unit_id' => $unit_id ) );

			$rows[] = array(
				'<a href="' . esc_url( SVM_App::url( 'members', array( 'unit_id' => $unit_id ) ) ) . '">' .
					esc_html( $unit['name'] ) . '</a>',
				esc_html( number_format_i18n( SVM_Units::member_count( $unit_id ) ) ),
				esc_html( SVM_UI::money( $totals['open'] ) ),
			);
		}

		SVM_UI::card_open( __( 'Sparten', 'svm' ) );
		SVM_UI::table(
			array( __( 'Sparte', 'svm' ), __( 'Mitglieder', 'svm' ), __( 'Offen', 'svm' ) ),
			$rows
		);
		SVM_UI::card_close();
	}
}
