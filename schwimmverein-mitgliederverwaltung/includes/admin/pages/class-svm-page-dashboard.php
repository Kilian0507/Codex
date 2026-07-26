<?php
/**
 * Übersichtsseite.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Zeigt Kennzahlen und offene Aufgaben.
 */
class SVM_Page_Dashboard {

	/**
	 * Gibt die Seite aus.
	 *
	 * @return void
	 */
	public static function render() {
		$members  = SVM_Members::count( array( 'respect_scope' => true ) );
		$totals   = SVM_Invoices::totals( array( 'status' => 'unpaid' ) );
		$pending  = SVM_Change_Requests::pending_count();
		$units    = SVM_Units::all( true );
		$has_data = ! empty( SVM_Fields::defs( 'member' ) );

		echo '<div class="wrap svm-wrap">';
		echo '<h1>' . esc_html__( 'Mitgliederverwaltung', 'svm' ) . '</h1>';

		SVM_Admin_Menu::notices();

		if ( ! $has_data ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'Es sind noch keine Felder konfiguriert. Öffnen Sie die Konfiguration, um die Startkonfiguration einzurichten.', 'svm' ) .
				'</p></div>';
		}

		echo '<div class="svm-cards">';

		self::card(
			__( 'Mitglieder', 'svm' ),
			number_format_i18n( $members ),
			SVM_Admin_Menu::url( 'svm-members' ),
			__( 'Mitglieder verwalten', 'svm' )
		);

		self::card(
			__( 'Offene Forderungen', 'svm' ),
			number_format_i18n( $totals['open'], 2 ) . ' €',
			SVM_Admin_Menu::url( 'svm-fees', array( 'tab' => 'invoices' ) ),
			sprintf(
				/* translators: %d: Anzahl. */
				__( '%d Posten', 'svm' ),
				$totals['count']
			)
		);

		self::card(
			__( 'Sparten/Gruppen', 'svm' ),
			number_format_i18n( count( $units ) ),
			SVM_Admin_Menu::url( 'svm-config', array( 'tab' => 'units' ) ),
			__( 'Struktur bearbeiten', 'svm' )
		);

		self::card(
			__( 'Offene Änderungsanträge', 'svm' ),
			number_format_i18n( $pending ),
			SVM_Admin_Menu::url( 'svm-members', array( 'view' => 'requests' ) ),
			__( 'Anträge prüfen', 'svm' )
		);

		echo '</div>';

		self::unit_overview( $units );
		self::recent_activity();

		echo '</div>';
	}

	/**
	 * Gibt eine Kennzahlenkachel aus.
	 *
	 * @param string $title Titel.
	 * @param string $value Wert.
	 * @param string $url   Ziel-URL.
	 * @param string $link  Linktext.
	 * @return void
	 */
	private static function card( $title, $value, $url, $link ) {
		echo '<div class="svm-card">';
		echo '<h2>' . esc_html( $title ) . '</h2>';
		echo '<p class="svm-card-value">' . esc_html( $value ) . '</p>';
		echo '<p><a href="' . esc_url( $url ) . '">' . esc_html( $link ) . ' →</a></p>';
		echo '</div>';
	}

	/**
	 * Mitgliederzahlen je Einheit.
	 *
	 * @param array $units Einheiten.
	 * @return void
	 */
	private static function unit_overview( array $units ) {
		if ( empty( $units ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Mitglieder je Sparte/Gruppe', 'svm' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Einheit', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Mitglieder', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Offener Betrag', 'svm' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $units as $unit ) {
			$totals = SVM_Invoices::totals(
				array(
					'status'        => 'unpaid',
					'unit_id'       => (int) $unit['id'],
					'respect_scope' => false,
				)
			);

			echo '<tr>';
			echo '<td><a href="' . esc_url( SVM_Admin_Menu::url( 'svm-members', array( 'unit_id' => (int) $unit['id'] ) ) ) . '">' .
				esc_html( $unit['name'] ) . '</a></td>';
			echo '<td>' . esc_html( number_format_i18n( SVM_Units::member_count( (int) $unit['id'] ) ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $totals['open'], 2 ) ) . ' €</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Letzte Protokolleinträge.
	 *
	 * @return void
	 */
	private static function recent_activity() {
		if ( ! SVM_Permissions::current_user_can( 'audit_view' ) ) {
			return;
		}

		$entries = SVM_Audit::query( array( 'limit' => 10 ) );

		if ( empty( $entries ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Letzte Änderungen', 'svm' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Zeitpunkt', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Bereich', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Aktion', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Benutzer', 'svm' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$user = get_userdata( (int) $entry['user_id'] );

			echo '<tr>';
			echo '<td>' . esc_html( $entry['created_at'] ) . '</td>';
			echo '<td>' . esc_html( $entry['entity_type'] . ' #' . $entry['entity_id'] ) . '</td>';
			echo '<td>' . esc_html( $entry['action'] . ( '' !== $entry['field_key'] ? ' (' . $entry['field_key'] . ')' : '' ) ) . '</td>';
			echo '<td>' . esc_html( $user ? $user->display_name : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
