<?php
/**
 * Einstellungen.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Felder, Sparten, Status, Rollen, Zahlarten, Nummern und Vorlagen.
 */
class SVM_View_Config {

	/**
	 * Reiter dieses Bereichs.
	 *
	 * @return array
	 */
	private static function tabs() {
		return array(
			'settings'  => __( 'Verein', 'svm' ),
			'fields'    => __( 'Felder', 'svm' ),
			'units'     => __( 'Sparten', 'svm' ),
			'statuses'  => __( 'Status', 'svm' ),
			'roles'     => __( 'Rollen', 'svm' ),
			'team'      => __( 'Wer darf was', 'svm' ),
			'methods'   => __( 'Zahlarten', 'svm' ),
			'numbers'   => __( 'Nummern', 'svm' ),
			'templates' => __( 'Vorlagen', 'svm' ),
		);
	}

	/**
	 * Kopf mit Reitern.
	 *
	 * @param string $current  Aktiver Reiter.
	 * @param string $title    Titel.
	 * @param string $subtitle Erläuterung.
	 * @return void
	 */
	private static function head( $current, $title, $subtitle ) {
		SVM_UI::page_head( $title, $subtitle );
		SVM_App::subnav( self::tabs(), $current );
	}

	/**
	 * Grundeinstellungen.
	 *
	 * @return void
	 */
	public static function settings() {
		self::head(
			'settings',
			__( 'Verein', 'svm' ),
			__( 'Name, Seite der Verwaltung und Aufbewahrungsfristen.', 'svm' )
		);

		$pages = array( 0 => __( '— keine —', 'svm' ) );

		foreach ( get_pages( array( 'number' => 200 ) ) as $page ) {
			$pages[ (int) $page->ID ] = $page->post_title;
		}

		SVM_UI::card_open();
		SVM_UI::form_open( 'save_settings' );
		SVM_UI::grid_open();
		SVM_UI::field(
			__( 'Name des Vereins', 'svm' ),
			SVM_UI::input( 'club_name', get_option( 'svm_club_name', get_bloginfo( 'name' ) ) ),
			__( 'Erscheint im Verwendungszweck der Lastschriften und in E-Mails.', 'svm' )
		);
		SVM_UI::field(
			__( 'Seite der Verwaltung', 'svm' ),
			SVM_UI::select( 'portal_page_id', (int) get_option( 'svm_portal_page_id', 0 ), $pages ),
			__( 'Die Seite, auf der der Shortcode [svm_app] steht.', 'svm' )
		);
		SVM_UI::field(
			__( 'Geschäftsjahr beginnt am', 'svm' ),
			SVM_UI::input( 'fiscal_year_start', get_option( 'svm_fiscal_year_start', '01-01' ), array( 'placeholder' => '01-01' ) )
		);
		SVM_UI::field(
			__( 'Daten aufbewahren (Monate nach Austritt)', 'svm' ),
			SVM_UI::input( 'retention_months', (int) get_option( 'svm_retention_months', 120 ), array( 'type' => 'number' ) ),
			__( 'Voreinstellung 120 Monate wegen der zehnjährigen Aufbewahrungspflicht für Finanzunterlagen.', 'svm' )
		);
		SVM_UI::grid_close();
		SVM_UI::form_close( __( 'Einstellungen speichern', 'svm' ) );
		SVM_UI::card_close();

		$missing = SVM_Installer::known_missing();

		SVM_UI::card_open(
			__( 'Datenbank', 'svm' ),
			empty( $missing )
				? __( 'Alle Tabellen des Plugins sind vorhanden.', 'svm' )
				: sprintf(
					/* translators: %s: Liste der fehlenden Tabellen. */
					__( 'Es fehlen Tabellen: %s. Solange sie fehlen, lässt sich in den betroffenen Bereichen nichts speichern.', 'svm' ),
					implode( ', ', $missing )
				)
		);

		echo '<div class="svm-button-row">';
		SVM_UI::action_button(
			'repair_schema',
			array(),
			__( 'Datenbank prüfen und ergänzen', 'svm' ),
			empty( $missing ) ? 'secondary' : 'primary'
		);
		echo '</div>';

		SVM_UI::card_close();

		SVM_UI::card_open(
			__( 'Konfiguration sichern', 'svm' ),
			__( 'Felder, Rollen, Beitragsregeln und Vorlagen als Datei sichern — für den Umzug oder als Sicherung. Bankdaten werden dabei nicht mitexportiert.', 'svm' )
		);

		echo '<div class="svm-button-row">';
		SVM_UI::action_button( 'export_config', array(), __( 'Konfiguration herunterladen', 'svm' ) );
		echo '</div>';

		echo '<hr class="svm-divider" />';

		SVM_UI::form_open( 'import_config', array(), true );
		SVM_UI::field( __( 'Konfigurationsdatei', 'svm' ), '<input class="svm-input" type="file" name="config_file" accept=".json,application/json" required />' );
		SVM_UI::form_close(
			__( 'Konfiguration einspielen', 'svm' ),
			'secondary',
			__( 'Die vorhandene Konfiguration wird ersetzt. Fortfahren?', 'svm' )
		);

		SVM_UI::card_close();
	}

	/**
	 * Feldübersicht.
	 *
	 * @return void
	 */
	public static function fields() {
		self::head(
			'fields',
			__( 'Felder', 'svm' ),
			__( 'Welche Angaben zu einem Mitglied erfasst werden. Auch Name und Adresse sind normale Felder.', 'svm' )
		);

		$types = SVM_Field_Types::choices();
		$roles = SVM_Fields::system_roles();
		$rows  = array();

		foreach ( SVM_Fields::defs( 'member', false ) as $item ) {
			$flags = array();

			if ( $item['is_required'] ) {
				$flags[] = SVM_UI::badge( __( 'Pflicht', 'svm' ), 'warn' );
			}

			if ( $item['is_sensitive'] ) {
				$flags[] = SVM_UI::badge( __( 'sensibel', 'svm' ), 'bad' );
			}

			if ( $item['show_in_list'] ) {
				$flags[] = SVM_UI::badge( __( 'in Liste', 'svm' ), 'neutral' );
			}

			if ( ! $item['is_active'] ) {
				$flags[] = SVM_UI::badge( __( 'inaktiv', 'svm' ), 'neutral' );
			}

			$rows[] = array(
				'<a class="svm-strong" href="' . esc_url( SVM_App::url( 'field', array( 'id' => (int) $item['id'] ) ) ) . '">' .
					esc_html( $item['label'] ) . '</a>',
				esc_html( isset( $types[ $item['field_type'] ] ) ? $types[ $item['field_type'] ] : $item['field_type'] ),
				esc_html( $item['section'] ),
				esc_html( isset( $roles[ $item['system_role'] ] ) ? $roles[ $item['system_role'] ] : '' ),
				implode( ' ', $flags ),
			);
		}

		SVM_UI::table(
			array( __( 'Feld', 'svm' ), __( 'Typ', 'svm' ), __( 'Abschnitt', 'svm' ), __( 'Systemrolle', 'svm' ), '' ),
			$rows
		);

		echo '<div class="svm-button-row">';
		printf(
			'<a class="svm-btn svm-btn-primary" href="%s">%s</a>',
			esc_url( SVM_App::url( 'field', array( 'id' => 0 ) ) ),
			esc_html__( 'Neues Feld anlegen', 'svm' )
		);
		echo '</div>';
	}

	/**
	 * Feld bearbeiten.
	 *
	 * @return void
	 */
	public static function field() {
		$field_id = SVM_App::id();
		$def      = $field_id ? SVM_Fields::get_def( $field_id ) : null;

		SVM_UI::page_head(
			$def ? $def['label'] : __( 'Neues Feld', 'svm' ),
			__( 'Typ, Pflichtangabe und wer das Feld sehen und ändern darf.', 'svm' ),
			array(
				array(
					'label' => __( '← Alle Felder', 'svm' ),
					'url'   => SVM_App::url( 'fields' ),
				),
			)
		);

		$options_text = '';

		if ( $def ) {
			$lines = array();

			foreach ( SVM_Fields::options( $field_id ) as $option ) {
				$lines[] = $option['option_value'] === $option['option_label']
					? $option['option_value']
					: $option['option_value'] . '|' . $option['option_label'];
			}

			$options_text = implode( "\n", $lines );
		}

		SVM_UI::card_open();
		SVM_UI::form_open( 'save_field', array( 'id' => $field_id ) );
		SVM_UI::grid_open();
		SVM_UI::field( __( 'Bezeichnung', 'svm' ), SVM_UI::input( 'label', $def ? $def['label'] : '', array( 'required' => true ) ) );
		SVM_UI::field( __( 'Typ', 'svm' ), SVM_UI::select( 'field_type', $def ? $def['field_type'] : 'text', SVM_Field_Types::choices() ) );
		SVM_UI::field(
			__( 'Systemrolle', 'svm' ),
			SVM_UI::select( 'system_role', $def ? $def['system_role'] : '', SVM_Fields::system_roles() ),
			__( 'Sagt dem System, welches Feld für Name, Alter, E-Mail und Bankverbindung steht.', 'svm' )
		);
		SVM_UI::field(
			__( 'Abschnitt', 'svm' ),
			SVM_UI::input( 'section', $def ? $def['section'] : '', array( 'placeholder' => __( 'z. B. Kontakt', 'svm' ) ) ),
			__( 'Felder mit gleichem Abschnitt stehen im Formular zusammen.', 'svm' )
		);
		SVM_UI::field(
			__( 'Technischer Name', 'svm' ),
			SVM_UI::input( 'field_key', $def ? $def['field_key'] : '' ),
			__( 'Wird in Formeln als {name} verwendet. Leer lassen für automatische Vergabe.', 'svm' )
		);
		SVM_UI::field( __( 'Reihenfolge', 'svm' ), SVM_UI::input( 'sort_order', $def ? $def['sort_order'] : 0, array( 'type' => 'number' ) ) );
		SVM_UI::grid_close();

		SVM_UI::field( __( 'Hilfetext', 'svm' ), SVM_UI::input( 'help_text', $def ? $def['help_text'] : '' ) );
		SVM_UI::field(
			__( 'Auswahlmöglichkeiten', 'svm' ),
			SVM_UI::textarea( 'options', $options_text, array( 'rows' => 4, 'placeholder' => "w|weiblich\nm|männlich" ) ),
			__( 'Nur bei Auswahlfeldern. Eine Möglichkeit je Zeile.', 'svm' )
		);

		SVM_UI::grid_open();
		SVM_UI::field( __( 'Standardwert', 'svm' ), SVM_UI::input( 'default_value', $def ? $def['default_value'] : '' ) );
		SVM_UI::field(
			__( 'Formel', 'svm' ),
			SVM_UI::input( 'formula', $def ? $def['formula'] : '' ),
			__( 'Nur bei berechneten Feldern, z. B. {grundbeitrag} * 12.', 'svm' )
		);
		SVM_UI::field( __( 'Kleinster Wert', 'svm' ), SVM_UI::input( 'min_value', $def ? $def['min_value'] : '' ) );
		SVM_UI::field( __( 'Größter Wert', 'svm' ), SVM_UI::input( 'max_value', $def ? $def['max_value'] : '' ) );
		SVM_UI::field(
			__( 'Aufbewahrung (Monate)', 'svm' ),
			SVM_UI::input( 'retention_months', $def ? $def['retention_months'] : 0, array( 'type' => 'number' ) ),
			__( '0 = allgemeine Frist des Vereins.', 'svm' )
		);
		SVM_UI::field(
			__( 'Prüfmuster', 'svm' ),
			SVM_UI::input( 'validation_regex', $def ? $def['validation_regex'] : '' ),
			__( 'Optionaler regulärer Ausdruck.', 'svm' )
		);
		SVM_UI::grid_close();

		SVM_UI::field( '', SVM_UI::checkbox( 'is_required', $def ? $def['is_required'] : 0, __( 'Pflichtfeld', 'svm' ) ) );
		SVM_UI::field(
			'',
			SVM_UI::checkbox( 'is_sensitive', $def ? $def['is_sensitive'] : 0, __( 'Besonders schützenswert (Bankdaten, Gesundheit)', 'svm' ) ),
			__( 'Sensible Felder sind ohne ausdrückliche Freigabe unsichtbar und werden maskiert dargestellt.', 'svm' )
		);
		SVM_UI::field( '', SVM_UI::checkbox( 'show_in_list', $def ? $def['show_in_list'] : 0, __( 'Als Spalte in der Mitgliederliste zeigen', 'svm' ) ) );
		SVM_UI::field( '', SVM_UI::checkbox( 'in_gdpr_export', $def ? $def['in_gdpr_export'] : 1, __( 'In der Datenauskunft ausgeben', 'svm' ) ) );
		SVM_UI::field( '', SVM_UI::checkbox( 'is_active', $def ? $def['is_active'] : 1, __( 'Feld verwenden', 'svm' ) ) );

		SVM_UI::form_close( __( 'Feld speichern', 'svm' ) );
		SVM_UI::card_close();

		if ( ! $def ) {
			return;
		}

		self::field_access( $field_id );

		SVM_UI::card_open();
		echo '<div class="svm-button-row">';
		SVM_UI::action_button(
			'delete_field',
			array( 'id' => $field_id ),
			__( 'Feld löschen', 'svm' ),
			'danger',
			__( 'Feld und alle gespeicherten Werte wirklich löschen?', 'svm' )
		);
		echo '</div>';
		SVM_UI::card_close();
	}

	/**
	 * Feldrechte je Rolle.
	 *
	 * @param int $field_id Feld-ID.
	 * @return void
	 */
	private static function field_access( $field_id ) {
		$rules = SVM_Fields::visibility( $field_id );

		SVM_UI::card_open(
			__( 'Wer darf dieses Feld sehen und ändern?', 'svm' ),
			__( 'Ohne Eintrag gilt: nicht sensible Felder sind sichtbar, Bearbeitung richtet sich nach dem Recht „Mitglieder bearbeiten“.', 'svm' )
		);

		SVM_UI::form_open( 'save_field_access', array( 'field_id' => $field_id ) );

		$rows = array();

		foreach ( SVM_Roles::all() as $role ) {
			$role_id = (int) $role['id'];
			$rule    = isset( $rules[ $role_id ] ) ? $rules[ $role_id ] : array(
				'can_view'          => 0,
				'can_edit'          => 0,
				'requires_approval' => 0,
			);

			$prefix = 'access[' . $role_id . ']';

			$rows[] = array(
				'<span class="svm-strong">' . esc_html( $role['label'] ) . '</span>',
				SVM_UI::checkbox( $prefix . '[can_view]', $rule['can_view'], __( 'sehen', 'svm' ) ),
				SVM_UI::checkbox( $prefix . '[can_edit]', $rule['can_edit'], __( 'ändern', 'svm' ) ),
				SVM_UI::checkbox( $prefix . '[requires_approval]', $rule['requires_approval'], __( 'nur mit Freigabe', 'svm' ) ),
			);
		}

		SVM_UI::table(
			array( __( 'Rolle', 'svm' ), __( 'Sehen', 'svm' ), __( 'Ändern', 'svm' ), __( 'Freigabe nötig', 'svm' ) ),
			$rows
		);

		SVM_UI::form_close( __( 'Rechte speichern', 'svm' ), 'secondary' );
		SVM_UI::card_close();
	}

	/**
	 * Sparten und Gruppen.
	 *
	 * @return void
	 */
	public static function units() {
		$unit_id = SVM_App::id();
		$unit    = $unit_id ? SVM_Units::get( $unit_id ) : null;

		self::head(
			'units',
			__( 'Sparten und Gruppen', 'svm' ),
			__( 'Die Gliederung des Vereins. Untergruppen entstehen über die übergeordnete Einheit.', 'svm' )
		);

		$types = SVM_Units::type_options();
		$rows  = array();

		foreach ( SVM_Units::options() as $id => $label ) {
			$item = SVM_Units::get( $id );

			$rows[] = array(
				'<a class="svm-strong" href="' . esc_url( SVM_App::url( 'units', array( 'id' => $id ) ) ) . '">' .
					esc_html( $label ) . '</a>',
				esc_html( isset( $types[ (int) $item['unit_type_id'] ] ) ? $types[ (int) $item['unit_type_id'] ] : '—' ),
				esc_html( number_format_i18n( SVM_Units::member_count( $id ) ) ),
				'<code>' . esc_html( $id ) . '</code>',
			);
		}

		SVM_UI::table(
			array( __( 'Sparte', 'svm' ), __( 'Ebene', 'svm' ), __( 'Mitglieder', 'svm' ), __( 'Nummer', 'svm' ) ),
			$rows,
			__( 'Noch keine Sparte angelegt.', 'svm' )
		);

		$parents = array( 0 => __( '— oberste Ebene —', 'svm' ) ) + SVM_Units::options();
		unset( $parents[ $unit_id ] );

		SVM_UI::card_open( $unit ? __( 'Sparte bearbeiten', 'svm' ) : __( 'Neue Sparte', 'svm' ) );
		SVM_UI::form_open( 'save_unit', array( 'id' => $unit_id ) );
		SVM_UI::grid_open();
		SVM_UI::field( __( 'Name', 'svm' ), SVM_UI::input( 'name', $unit ? $unit['name'] : '', array( 'required' => true ) ) );
		SVM_UI::field( __( 'Kurzname', 'svm' ), SVM_UI::input( 'short_name', $unit ? $unit['short_name'] : '' ) );
		SVM_UI::field( __( 'Ebene', 'svm' ), SVM_UI::select( 'unit_type_id', $unit ? $unit['unit_type_id'] : 0, array( 0 => __( '— ohne —', 'svm' ) ) + $types ) );
		SVM_UI::field( __( 'Gehört zu', 'svm' ), SVM_UI::select( 'parent_id', $unit ? $unit['parent_id'] : 0, $parents ) );
		SVM_UI::field( __( 'Reihenfolge', 'svm' ), SVM_UI::input( 'sort_order', $unit ? $unit['sort_order'] : 0, array( 'type' => 'number' ) ) );
		SVM_UI::grid_close();
		SVM_UI::field( '', SVM_UI::checkbox( 'is_active', $unit ? $unit['is_active'] : 1, __( 'Sparte wird angeboten', 'svm' ) ) );
		SVM_UI::form_close( __( 'Sparte speichern', 'svm' ) );

		if ( $unit ) {
			echo '<div class="svm-button-row">';
			SVM_UI::action_button(
				'delete_unit',
				array( 'id' => $unit_id ),
				__( 'Sparte löschen', 'svm' ),
				'danger',
				__( 'Sparte wirklich löschen? Untergruppen rücken eine Ebene nach oben.', 'svm' )
			);
			echo '</div>';
		}

		SVM_UI::card_close();

		SVM_UI::card_open( __( 'Ebenen benennen', 'svm' ), __( 'Nennen Sie die Gliederungsebenen so, wie der Verein sie nennt — Sparte, Trainingsgruppe, Mannschaft.', 'svm' ) );

		$type_rows = array();

		foreach ( SVM_Units::types() as $type ) {
			$type_rows[] = array(
				esc_html( $type['label'] ),
				SVM_View_Members::button_cell(
					'delete_unit_type',
					array( 'id' => (int) $type['id'] ),
					__( 'Löschen', 'svm' ),
					__( 'Ebene wirklich löschen? Die Sparten bleiben bestehen und stehen danach ohne Ebene da.', 'svm' )
				),
			);
		}

		SVM_UI::table( array( __( 'Ebene', 'svm' ), '' ), $type_rows, __( 'Noch keine Ebene angelegt.', 'svm' ) );

		SVM_UI::form_open( 'save_unit_type' );
		SVM_UI::field( __( 'Neue Ebene', 'svm' ), SVM_UI::input( 'label', '', array( 'placeholder' => __( 'z. B. Mannschaft', 'svm' ) ) ) );
		SVM_UI::form_close( __( 'Hinzufügen', 'svm' ), 'secondary' );
		SVM_UI::card_close();
	}

	/**
	 * Mitgliedsstatus.
	 *
	 * @return void
	 */
	public static function statuses() {
		$status_id = SVM_App::id();
		$status    = $status_id ? SVM_Statuses::get( $status_id ) : null;

		self::head(
			'statuses',
			__( 'Mitgliedsstatus', 'svm' ),
			__( 'Aktiv, passiv, Ehrenmitglied — und was daraus folgt.', 'svm' )
		);

		$rows = array();

		foreach ( SVM_Statuses::all() as $item ) {
			$flags = array();

			if ( $item['is_billable'] ) {
				$flags[] = SVM_UI::badge( __( 'zahlt Beitrag', 'svm' ), 'ok' );
			}

			if ( ! $item['allows_login'] ) {
				$flags[] = SVM_UI::badge( __( 'kein Zugang', 'svm' ), 'neutral' );
			}

			if ( $item['is_default'] ) {
				$flags[] = SVM_UI::badge( __( 'Standard', 'svm' ), 'warn' );
			}

			$rows[] = array(
				'<a class="svm-strong" href="' . esc_url( SVM_App::url( 'statuses', array( 'id' => (int) $item['id'] ) ) ) . '">' .
					esc_html( $item['label'] ) . '</a>',
				implode( ' ', $flags ),
				'<code>' . esc_html( $item['id'] ) . '</code>',
			);
		}

		SVM_UI::table( array( __( 'Status', 'svm' ), '', __( 'Nummer', 'svm' ) ), $rows );

		SVM_UI::card_open( $status ? __( 'Status bearbeiten', 'svm' ) : __( 'Neuer Status', 'svm' ) );
		SVM_UI::form_open( 'save_status', array( 'id' => $status_id ) );
		SVM_UI::grid_open();
		SVM_UI::field( __( 'Bezeichnung', 'svm' ), SVM_UI::input( 'label', $status ? $status['label'] : '', array( 'required' => true ) ) );
		SVM_UI::field( __( 'Farbe', 'svm' ), SVM_UI::input( 'color', $status ? $status['color'] : '#2f855a', array( 'type' => 'color' ) ) );
		SVM_UI::field( __( 'Reihenfolge', 'svm' ), SVM_UI::input( 'sort_order', $status ? $status['sort_order'] : 0, array( 'type' => 'number' ) ) );
		SVM_UI::grid_close();
		SVM_UI::field(
			'',
			SVM_UI::checkbox( 'is_billable', $status ? $status['is_billable'] : 1, __( 'Mitglieder mit diesem Status zahlen Beitrag', 'svm' ) ),
			__( 'Ehrenmitglieder bekommen hier üblicherweise kein Häkchen.', 'svm' )
		);
		SVM_UI::field( '', SVM_UI::checkbox( 'counts_as_active', $status ? $status['counts_as_active'] : 1, __( 'Zählt als aktives Mitglied', 'svm' ) ) );
		SVM_UI::field( '', SVM_UI::checkbox( 'allows_login', $status ? $status['allows_login'] : 1, __( 'Darf sich anmelden', 'svm' ) ) );
		SVM_UI::field( '', SVM_UI::checkbox( 'is_default', $status ? $status['is_default'] : 0, __( 'Für neue Mitglieder voreingestellt', 'svm' ) ) );
		SVM_UI::form_close( __( 'Status speichern', 'svm' ) );

		if ( $status ) {
			echo '<div class="svm-button-row">';
			SVM_UI::action_button( 'delete_status', array( 'id' => $status_id ), __( 'Status löschen', 'svm' ), 'danger' );
			echo '</div>';
		}

		SVM_UI::card_close();

		self::transitions();
	}

	/**
	 * Erlaubte Statuswechsel.
	 *
	 * @return void
	 */
	private static function transitions() {
		$statuses = SVM_Statuses::options();

		if ( empty( $statuses ) ) {
			return;
		}

		$rows    = array();
		$actions = SVM_Statuses::transition_actions();

		foreach ( SVM_DB::all( 'status_transitions' ) as $row ) {
			$rows[] = array(
				esc_html( isset( $statuses[ (int) $row['from_status_id'] ] ) ? $statuses[ (int) $row['from_status_id'] ] : '—' ),
				esc_html( isset( $statuses[ (int) $row['to_status_id'] ] ) ? $statuses[ (int) $row['to_status_id'] ] : '—' ),
				esc_html( isset( $actions[ $row['on_transition'] ] ) ? $actions[ $row['on_transition'] ] : '—' ),
				SVM_View_Members::button_cell(
					'delete_transition',
					array( 'id' => (int) $row['id'] ),
					__( 'Löschen', 'svm' )
				),
			);
		}

		SVM_UI::card_open(
			__( 'Erlaubte Statuswechsel', 'svm' ),
			__( 'Solange kein Wechsel eingetragen ist, sind alle Wechsel erlaubt.', 'svm' )
		);

		SVM_UI::table(
			array( __( 'Von', 'svm' ), __( 'Nach', 'svm' ), __( 'Aktion dabei', 'svm' ), '' ),
			$rows,
			__( 'Kein Wechsel eingetragen — derzeit ist jeder Statuswechsel möglich.', 'svm' )
		);

		SVM_UI::form_open( 'save_transition' );
		SVM_UI::grid_open();
		SVM_UI::field( __( 'Von', 'svm' ), SVM_UI::select( 'from_status_id', 0, $statuses ) );
		SVM_UI::field( __( 'Nach', 'svm' ), SVM_UI::select( 'to_status_id', 0, $statuses ) );
		SVM_UI::field( __( 'Aktion beim Wechsel', 'svm' ), SVM_UI::select( 'on_transition', '', $actions ) );
		SVM_UI::grid_close();
		SVM_UI::form_close( __( 'Wechsel hinzufügen', 'svm' ), 'secondary' );
		SVM_UI::card_close();
	}

	/**
	 * Rollen.
	 *
	 * @return void
	 */
	public static function roles() {
		$role_id = SVM_App::id();
		$role    = $role_id ? SVM_Roles::get( $role_id ) : null;
		$scopes  = SVM_Roles::scopes();

		self::head(
			'roles',
			__( 'Rollen', 'svm' ),
			__( 'Was jemand darf und auf welche Mitglieder sich das bezieht.', 'svm' )
		);

		$rows = array();

		foreach ( SVM_Roles::all() as $item ) {
			$rows[] = array(
				'<a class="svm-strong" href="' . esc_url( SVM_App::url( 'roles', array( 'id' => (int) $item['id'] ) ) ) . '">' .
					esc_html( $item['label'] ) . '</a>',
				esc_html( isset( $scopes[ $item['scope'] ] ) ? $scopes[ $item['scope'] ] : $item['scope'] ),
				esc_html( number_format_i18n( count( SVM_Roles::permissions( (int) $item['id'] ) ) ) ),
				esc_html( $item['description'] ),
			);
		}

		SVM_UI::table(
			array( __( 'Rolle', 'svm' ), __( 'Gilt für', 'svm' ), __( 'Rechte', 'svm' ), __( 'Beschreibung', 'svm' ) ),
			$rows
		);

		$granted = $role ? SVM_Roles::permissions( $role_id ) : array();

		SVM_UI::card_open( $role ? __( 'Rolle bearbeiten', 'svm' ) : __( 'Neue Rolle', 'svm' ) );
		SVM_UI::form_open( 'save_role', array( 'id' => $role_id ) );
		SVM_UI::grid_open();
		SVM_UI::field( __( 'Bezeichnung', 'svm' ), SVM_UI::input( 'label', $role ? $role['label'] : '', array( 'required' => true ) ) );
		SVM_UI::field(
			__( 'Gilt für', 'svm' ),
			SVM_UI::select( 'scope', $role ? $role['scope'] : 'self', $scopes ),
			__( '„Nur zugeordnete Sparten“ macht aus einer Rolle einen Sparten-Leiter für beliebig viele Sparten.', 'svm' )
		);
		SVM_UI::grid_close();
		SVM_UI::field( __( 'Beschreibung', 'svm' ), SVM_UI::input( 'description', $role ? $role['description'] : '' ) );
		SVM_UI::field( '', SVM_UI::checkbox( 'include_subunits', $role ? $role['include_subunits'] : 1, __( 'Untergruppen einschließen', 'svm' ) ) );
		SVM_UI::field( '', SVM_UI::checkbox( 'is_member_role', $role ? $role['is_member_role'] : 0, __( 'Standardrolle für Mitglieder', 'svm' ) ) );

		echo '<h4 class="svm-section-title">' . esc_html__( 'Rechte', 'svm' ) . '</h4>';

		foreach ( SVM_Permissions::catalog() as $group => $permissions ) {
			echo '<p class="svm-group-title">' . esc_html( $group ) . '</p>';
			echo '<div class="svm-checklist">';

			foreach ( $permissions as $key => $label ) {
				printf(
					'<label class="svm-check"><input type="checkbox" name="permissions[]" value="%s"%s /> <span>%s</span></label>',
					esc_attr( $key ),
					in_array( $key, $granted, true ) ? ' checked' : '',
					esc_html( $label )
				);
			}

			echo '</div>';
		}

		SVM_UI::form_close( __( 'Rolle speichern', 'svm' ) );

		if ( $role ) {
			echo '<div class="svm-button-row">';
			SVM_UI::action_button( 'delete_role', array( 'id' => $role_id ), __( 'Rolle löschen', 'svm' ), 'danger' );
			echo '</div>';
		}

		SVM_UI::card_close();
	}

	/**
	 * Zuordnung von Benutzern zu Rollen.
	 *
	 * @return void
	 */
	public static function team() {
		$user_id = SVM_App::id( 'user_id' );

		self::head(
			'team',
			__( 'Wer darf was', 'svm' ),
			__( 'Rollen an Personen vergeben und festlegen, für welche Sparten sie zuständig sind.', 'svm' )
		);

		$roles = array();

		foreach ( SVM_Roles::all() as $role ) {
			$roles[ (int) $role['id'] ] = $role['label'];
		}

		$rows = array();

		foreach ( get_users( array( 'number' => 300 ) ) as $user ) {
			$user_roles = SVM_Roles::user_role_ids( (int) $user->ID );

			if ( empty( $user_roles ) ) {
				continue;
			}

			$role_labels = array();

			foreach ( $user_roles as $role_id ) {
				$role_labels[] = isset( $roles[ $role_id ] ) ? $roles[ $role_id ] : '#' . $role_id;
			}

			$unit_labels = array();

			foreach ( SVM_Roles::user_unit_ids( (int) $user->ID ) as $unit_id ) {
				$unit_labels[] = SVM_Units::name( $unit_id );
			}

			$rows[] = array(
				'<a class="svm-strong" href="' . esc_url( SVM_App::url( 'team', array( 'user_id' => (int) $user->ID ) ) ) . '">' .
					esc_html( $user->display_name ) . '</a>',
				esc_html( implode( ', ', $role_labels ) ),
				esc_html( implode( ', ', array_filter( $unit_labels ) ) ),
			);
		}

		SVM_UI::table(
			array( __( 'Person', 'svm' ), __( 'Rollen', 'svm' ), __( 'Zuständig für', 'svm' ) ),
			$rows,
			__( 'Bisher wurde niemandem eine Rolle zugewiesen.', 'svm' )
		);

		$users = array( 0 => __( '— Person wählen —', 'svm' ) );

		foreach ( get_users( array( 'number' => 300, 'fields' => array( 'ID', 'display_name', 'user_email' ) ) ) as $user ) {
			$users[ (int) $user->ID ] = $user->display_name . ' · ' . $user->user_email;
		}

		SVM_UI::card_open( __( 'Rollen vergeben', 'svm' ) );
		SVM_UI::form_open( 'assign_user' );
		SVM_UI::grid_open();
		SVM_UI::field( __( 'Person', 'svm' ), SVM_UI::select( 'user_id', $user_id, $users ) );
		SVM_UI::field(
			__( 'Rollen', 'svm' ),
			SVM_UI::select( 'role_ids', $user_id ? SVM_Roles::user_role_ids( $user_id ) : array(), $roles, array( 'multiple' => true, 'size' => 5 ) )
		);
		SVM_UI::field(
			__( 'Zuständig für Sparten', 'svm' ),
			SVM_UI::select( 'unit_ids', $user_id ? SVM_Roles::user_unit_ids( $user_id ) : array(), SVM_Units::options(), array( 'multiple' => true, 'size' => 5 ) ),
			__( 'Wirkt bei Rollen, die nur für zugeordnete Sparten gelten.', 'svm' )
		);
		SVM_UI::grid_close();
		SVM_UI::form_close( __( 'Zuordnung speichern', 'svm' ) );
		SVM_UI::card_close();
	}

	/**
	 * Zahlarten.
	 *
	 * @return void
	 */
	public static function methods() {
		$method_id = SVM_App::id();
		$method    = $method_id ? SVM_Payment_Methods::get( $method_id ) : null;
		$behaviors = SVM_Payment_Methods::behaviors();

		self::head(
			'methods',
			__( 'Zahlarten', 'svm' ),
			__( 'Wie Beiträge bezahlt werden: Lastschrift, Überweisung, bar.', 'svm' )
		);

		$rows = array();

		foreach ( SVM_Payment_Methods::all() as $item ) {
			$rows[] = array(
				'<a class="svm-strong" href="' . esc_url( SVM_App::url( 'methods', array( 'id' => (int) $item['id'] ) ) ) . '">' .
					esc_html( $item['label'] ) . '</a>',
				esc_html( isset( $behaviors[ $item['behavior'] ] ) ? $behaviors[ $item['behavior'] ] : $item['behavior'] ),
				$item['is_default'] ? SVM_UI::badge( __( 'Standard', 'svm' ), 'ok' ) : '—',
			);
		}

		SVM_UI::table( array( __( 'Zahlart', 'svm' ), __( 'Ablauf', 'svm' ), '' ), $rows );

		SVM_UI::card_open( $method ? __( 'Zahlart bearbeiten', 'svm' ) : __( 'Neue Zahlart', 'svm' ) );
		SVM_UI::form_open( 'save_payment_method', array( 'id' => $method_id ) );
		SVM_UI::grid_open();
		SVM_UI::field( __( 'Bezeichnung', 'svm' ), SVM_UI::input( 'label', $method ? $method['label'] : '', array( 'required' => true ) ) );
		SVM_UI::field( __( 'Ablauf', 'svm' ), SVM_UI::select( 'behavior', $method ? $method['behavior'] : 'manual', $behaviors ) );
		SVM_UI::grid_close();
		SVM_UI::field( '', SVM_UI::checkbox( 'requires_mandate', $method ? $method['requires_mandate'] : 0, __( 'Benötigt ein SEPA-Mandat', 'svm' ) ) );
		SVM_UI::field( '', SVM_UI::checkbox( 'is_default', $method ? $method['is_default'] : 0, __( 'Für neue Mitglieder voreingestellt', 'svm' ) ) );
		SVM_UI::field( '', SVM_UI::checkbox( 'allow_self_select', $method ? $method['allow_self_select'] : 0, __( 'Mitglieder dürfen sie selbst wählen', 'svm' ) ) );
		SVM_UI::field( '', SVM_UI::checkbox( 'is_active', $method ? $method['is_active'] : 1, __( 'Zahlart anbieten', 'svm' ) ) );
		SVM_UI::form_close( __( 'Zahlart speichern', 'svm' ) );

		if ( $method ) {
			echo '<div class="svm-button-row">';
			SVM_UI::action_button( 'delete_payment_method', array( 'id' => $method_id ), __( 'Zahlart löschen', 'svm' ), 'danger' );
			echo '</div>';
		}

		SVM_UI::card_close();
	}

	/**
	 * Nummernkreise.
	 *
	 * @return void
	 */
	public static function numbers() {
		self::head(
			'numbers',
			__( 'Nummern', 'svm' ),
			__( 'Aufbau der Mitgliedsnummern und Mandatsreferenzen.', 'svm' )
		);

		foreach ( SVM_Number_Range::all() as $range ) {
			SVM_UI::card_open(
				'' !== $range['label'] ? $range['label'] : $range['range_key'],
				sprintf(
					/* translators: %s: Beispielnummer. */
					__( 'Nächste Nummer: %s', 'svm' ),
					SVM_Number_Range::render( $range, (int) $range['next_value'] )
				)
			);

			SVM_UI::form_open(
				'save_number_range',
				array(
					'id'        => (int) $range['id'],
					'range_key' => $range['range_key'],
					'label'     => $range['label'],
				)
			);

			SVM_UI::grid_open();
			SVM_UI::field( __( 'Vorsatz', 'svm' ), SVM_UI::input( 'prefix', $range['prefix'], array( 'placeholder' => 'SV' ) ) );
			SVM_UI::field( __( 'Stellen', 'svm' ), SVM_UI::input( 'digits', $range['digits'], array( 'type' => 'number', 'min' => '1' ) ) );
			SVM_UI::field( __( 'Nächster Wert', 'svm' ), SVM_UI::input( 'next_value', $range['next_value'], array( 'type' => 'number', 'min' => '1' ) ) );
			SVM_UI::grid_close();
			SVM_UI::field( '', SVM_UI::checkbox( 'use_year', $range['use_year'], __( 'Jahreszahl einfügen', 'svm' ) ) );
			SVM_UI::form_close( __( 'Speichern', 'svm' ), 'secondary' );

			echo '<div class="svm-button-row">';
			SVM_UI::action_button(
				'delete_number_range',
				array( 'id' => (int) $range['id'] ),
				__( 'Nummernkreis löschen', 'svm' ),
				'danger',
				__( 'Nummernkreis wirklich löschen? Bereits vergebene Nummern bleiben erhalten.', 'svm' )
			);
			echo '</div>';

			SVM_UI::card_close();
		}

		SVM_UI::card_open(
			__( 'Neuer Nummernkreis', 'svm' ),
			__( 'Für eigene Nummernfolgen, etwa je Sparte. Der technische Name wird im Code angesprochen.', 'svm' )
		);

		SVM_UI::form_open( 'save_number_range' );
		SVM_UI::grid_open();
		SVM_UI::field( __( 'Bezeichnung', 'svm' ), SVM_UI::input( 'label', '', array( 'required' => true ) ) );
		SVM_UI::field( __( 'Technischer Name', 'svm' ), SVM_UI::input( 'range_key', '', array( 'placeholder' => 'wasserball' ) ) );
		SVM_UI::field( __( 'Vorsatz', 'svm' ), SVM_UI::input( 'prefix', '' ) );
		SVM_UI::field( __( 'Stellen', 'svm' ), SVM_UI::input( 'digits', 5, array( 'type' => 'number', 'min' => '1' ) ) );
		SVM_UI::grid_close();
		SVM_UI::field( '', SVM_UI::checkbox( 'use_year', false, __( 'Jahreszahl einfügen', 'svm' ) ) );
		SVM_UI::form_close( __( 'Nummernkreis anlegen', 'svm' ) );
		SVM_UI::card_close();
	}

	/**
	 * Vorlagen.
	 *
	 * @return void
	 */
	public static function templates() {
		$template_id = SVM_App::id();
		$template    = $template_id ? SVM_Templates::get( $template_id ) : null;

		self::head(
			'templates',
			__( 'Vorlagen', 'svm' ),
			__( 'Texte für Zahlungserinnerungen und andere E-Mails.', 'svm' )
		);

		$rows = array();

		foreach ( SVM_Templates::all() as $item ) {
			$rows[] = array(
				'<a class="svm-strong" href="' . esc_url( SVM_App::url( 'templates', array( 'id' => (int) $item['id'] ) ) ) . '">' .
					esc_html( $item['label'] ) . '</a>',
				esc_html( $item['subject'] ),
			);
		}

		SVM_UI::table( array( __( 'Vorlage', 'svm' ), __( 'Betreff', 'svm' ) ), $rows );

		$placeholders = array();

		foreach ( SVM_Templates::placeholders() as $key => $label ) {
			$placeholders[] = $key . ' = ' . $label;
		}

		SVM_UI::card_open( $template ? __( 'Vorlage bearbeiten', 'svm' ) : __( 'Neue Vorlage', 'svm' ) );
		SVM_UI::form_open( 'save_template', array( 'id' => $template_id ) );
		SVM_UI::grid_open();
		SVM_UI::field( __( 'Bezeichnung', 'svm' ), SVM_UI::input( 'label', $template ? $template['label'] : '', array( 'required' => true ) ) );
		SVM_UI::field(
			__( 'Art', 'svm' ),
			SVM_UI::select( 'type', $template ? $template['type'] : 'email', array( 'email' => __( 'E-Mail', 'svm' ), 'pdf' => __( 'Brief', 'svm' ) ) )
		);
		SVM_UI::grid_close();
		SVM_UI::field( __( 'Betreff', 'svm' ), SVM_UI::input( 'subject', $template ? $template['subject'] : '' ) );
		SVM_UI::field(
			__( 'Text', 'svm' ),
			SVM_UI::textarea( 'body', $template ? $template['body'] : '', array( 'rows' => 8 ) ),
			implode( ' · ', $placeholders )
		);
		SVM_UI::form_close( __( 'Vorlage speichern', 'svm' ) );

		if ( $template ) {
			echo '<div class="svm-button-row">';
			SVM_UI::action_button( 'delete_template', array( 'id' => $template_id ), __( 'Vorlage löschen', 'svm' ), 'danger' );
			echo '</div>';
		}

		SVM_UI::card_close();
	}
}
