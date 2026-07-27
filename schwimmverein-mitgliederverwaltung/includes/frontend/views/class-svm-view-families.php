<?php
/**
 * Familienansichten.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Familien mit Verzweigungen: Hauptmitglied, Partner und Kinder,
 * Kinder können eigene Zweige haben.
 */
class SVM_View_Families {

	/**
	 * Familienliste.
	 *
	 * @return void
	 */
	public static function listing() {
		SVM_UI::page_head(
			__( 'Familien', 'svm' ),
			__( 'Mehrere Mitglieder zu einer Familie bündeln — dadurch greifen Familienrabatte und Höchstbeträge.', 'svm' )
		);

		$families = SVM_Families::all();
		$rows     = array();

		foreach ( $families as $family ) {
			$summary = SVM_Families::summary( (int) $family['id'] );
			$primary = (int) $family['primary_member_id'];

			$rows[] = array(
				'<a class="svm-strong" href="' . esc_url( SVM_App::url( 'family', array( 'id' => (int) $family['id'] ) ) ) . '">' .
					esc_html( $family['name'] ) . '</a>',
				$primary ? esc_html( SVM_Members::display_name( $primary ) ) : '<span class="svm-muted">—</span>',
				esc_html( number_format_i18n( $summary['count'] ) ),
				esc_html( SVM_UI::money( $summary['open'] ) ),
				esc_html( implode( ', ', array_slice( $summary['names'], 0, 4 ) ) ),
			);
		}

		SVM_UI::table(
			array(
				__( 'Familie', 'svm' ),
				__( 'Hauptmitglied', 'svm' ),
				__( 'Personen', 'svm' ),
				__( 'Offen', 'svm' ),
				__( 'Mitglieder', 'svm' ),
			),
			$rows,
			__( 'Noch keine Familie angelegt. Öffnen Sie ein Mitglied und gründen Sie dort eine Familie.', 'svm' )
		);

		if ( SVM_Permissions::current_user_can( 'members_edit' ) ) {
			SVM_UI::card_open(
				__( 'Neue Familie anlegen', 'svm' ),
				__( 'Wählen Sie das Hauptmitglied — weitere Personen fügen Sie anschließend hinzu.', 'svm' )
			);

			SVM_UI::form_open( 'save_family' );
			SVM_UI::grid_open();
			SVM_UI::field( __( 'Name der Familie', 'svm' ), SVM_UI::input( 'name', '', array( 'placeholder' => __( 'z. B. Familie Meier', 'svm' ) ) ) );
			SVM_UI::field(
				__( 'Hauptmitglied', 'svm' ),
				SVM_UI::select( 'primary_member_id', 0, SVM_App::member_options( __( '— später festlegen —', 'svm' ) ) )
			);
			SVM_UI::grid_close();
			SVM_UI::form_close( __( 'Familie anlegen', 'svm' ) );
			SVM_UI::card_close();
		}
	}

	/**
	 * Detailansicht einer Familie.
	 *
	 * @return void
	 */
	public static function detail() {
		$family_id = SVM_App::id();
		$family    = $family_id ? SVM_Families::get( $family_id ) : null;

		if ( ! $family ) {
			SVM_UI::notice( __( 'Familie nicht gefunden.', 'svm' ), 'error' );
			return;
		}

		$summary = SVM_Families::summary( $family_id );

		SVM_UI::page_head(
			$family['name'],
			sprintf(
				/* translators: 1: Anzahl Personen, 2: offener Betrag. */
				__( '%1$d Personen · offen: %2$s', 'svm' ),
				$summary['count'],
				SVM_UI::money( $summary['open'] )
			),
			array(
				array(
					'label' => __( '← Alle Familien', 'svm' ),
					'url'   => SVM_App::url( 'families' ),
				),
			)
		);

		self::tree( $family_id );

		if ( SVM_Permissions::current_user_can( 'members_edit' ) ) {
			self::add_form( $family_id );
			self::settings_form( $family );
		}
	}

	/**
	 * Zeigt den Familienbaum.
	 *
	 * @param int $family_id Familien-ID.
	 * @return void
	 */
	private static function tree( $family_id ) {
		$nodes = SVM_Families::flat_tree( $family_id );

		SVM_UI::card_open(
			__( 'Wer gehört zur Familie?', 'svm' ),
			__( 'Eingerückte Personen sind der darüberstehenden zugeordnet — so entstehen Verzweigungen wie Kinder unter einem Elternteil.', 'svm' )
		);

		if ( empty( $nodes ) ) {
			SVM_UI::empty_state( __( 'Dieser Familie ist noch niemand zugeordnet.', 'svm' ) );
			SVM_UI::card_close();
			return;
		}

		echo '<ul class="svm-tree">';

		foreach ( $nodes as $node ) {
			$member    = $node['member'];
			$member_id = (int) $member['id'];
			$open      = SVM_Invoices::open_total( $member_id );
			$age       = SVM_Members::age( $member_id );

			printf( '<li class="svm-tree-item svm-depth-%d">', min( 3, (int) $node['depth'] ) );

			echo '<div class="svm-tree-main">';
			printf(
				'<a class="svm-strong" href="%s">%s</a>',
				esc_url( SVM_App::url( 'member', array( 'id' => $member_id ) ) ),
				esc_html( SVM_Members::display_name( $member_id ) )
			);

			$meta = array( SVM_Families::relation_label( $member['relation_type'] ) );

			if ( null !== $age ) {
				/* translators: %d: Alter in Jahren. */
				$meta[] = sprintf( __( '%d Jahre', 'svm' ), $age );
			}

			$meta[] = $member['member_number'];

			echo '<span class="svm-muted"> · ' . esc_html( implode( ' · ', array_filter( $meta ) ) ) . '</span>';
			echo '</div>';

			echo '<div class="svm-tree-side">';

			if ( $open > 0 ) {
				echo '<span class="svm-strong">' . esc_html( SVM_UI::money( $open ) ) . '</span>';
			}

			if ( SVM_Permissions::current_user_can( 'members_edit' ) ) {
				SVM_UI::action_button(
					'remove_family_member',
					array( 'family_id' => $family_id, 'member_id' => $member_id ),
					__( 'Lösen', 'svm' )
				);
			}

			echo '</div>';
			echo '</li>';
		}

		echo '</ul>';
		SVM_UI::card_close();
	}

	/**
	 * Formular zum Hinzufügen einer Person.
	 *
	 * @param int $family_id Familien-ID.
	 * @return void
	 */
	private static function add_form( $family_id ) {
		$members = SVM_Families::members( $family_id );
		$parents = array( 0 => __( '— direkt an die Familie —', 'svm' ) );

		foreach ( $members as $member ) {
			$parents[ (int) $member['id'] ] = SVM_Members::display_name( (int) $member['id'] );
		}

		SVM_UI::card_open(
			__( 'Person hinzufügen', 'svm' ),
			__( 'Über „zugeordnet zu“ entstehen Verzweigungen, etwa Kinder unter einem Elternteil.', 'svm' )
		);

		SVM_UI::form_open( 'add_family_member', array( 'family_id' => $family_id ) );
		SVM_UI::grid_open();
		SVM_UI::field(
			__( 'Mitglied', 'svm' ),
			SVM_UI::select( 'member_id', 0, SVM_App::member_options( __( '— Mitglied wählen —', 'svm' ) ) )
		);
		SVM_UI::field( __( 'Rolle in der Familie', 'svm' ), SVM_UI::select( 'relation_type', 'kind', SVM_Families::relations() ) );
		SVM_UI::field( __( 'Zugeordnet zu', 'svm' ), SVM_UI::select( 'parent_member_id', 0, $parents ) );
		SVM_UI::grid_close();
		SVM_UI::form_close( __( 'Hinzufügen', 'svm' ) );
		SVM_UI::card_close();
	}

	/**
	 * Einstellungen der Familie.
	 *
	 * @param array $family Familie.
	 * @return void
	 */
	private static function settings_form( array $family ) {
		$family_id = (int) $family['id'];
		$members   = array( 0 => __( '— keines —', 'svm' ) );

		foreach ( SVM_Families::members( $family_id ) as $member ) {
			$members[ (int) $member['id'] ] = SVM_Members::display_name( (int) $member['id'] );
		}

		SVM_UI::card_open( __( 'Einstellungen der Familie', 'svm' ) );
		SVM_UI::form_open( 'save_family', array( 'id' => $family_id ) );
		SVM_UI::grid_open();
		SVM_UI::field( __( 'Name', 'svm' ), SVM_UI::input( 'name', $family['name'], array( 'required' => true ) ) );
		SVM_UI::field( __( 'Hauptmitglied', 'svm' ), SVM_UI::select( 'primary_member_id', $family['primary_member_id'], $members ) );
		SVM_UI::field(
			__( 'Abrechnung', 'svm' ),
			SVM_UI::select( 'billing_mode', $family['billing_mode'], SVM_Families::billing_modes() ),
			__( 'Hinweis für die Kasse — die Zuordnung der Zahlungen erfolgt weiterhin je Mitglied.', 'svm' )
		);
		SVM_UI::field( __( 'Notiz', 'svm' ), SVM_UI::input( 'note', $family['note'] ) );
		SVM_UI::grid_close();
		SVM_UI::form_close( __( 'Speichern', 'svm' ) );

		echo '<div class="svm-button-row">';
		SVM_UI::action_button(
			'delete_family',
			array( 'id' => $family_id ),
			__( 'Familie auflösen', 'svm' ),
			'danger',
			__( 'Familie wirklich auflösen? Die Mitglieder bleiben bestehen.', 'svm' )
		);
		echo '</div>';

		SVM_UI::card_close();
	}
}
