<?php
/**
 * Konfigurationsseite.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hier wird festgelegt, wie das System aussieht — Felder, Struktur, Status, Rollen, Zahlarten.
 */
class SVM_Page_Config {

	/**
	 * Gibt die Seite aus.
	 *
	 * @return void
	 */
	public static function render() {
		$tabs = array(
			'fields'   => __( 'Felder', 'svm' ),
			'units'    => __( 'Struktur', 'svm' ),
			'statuses' => __( 'Status', 'svm' ),
			'roles'    => __( 'Rollen & Rechte', 'svm' ),
			'users'    => __( 'Benutzerzuordnung', 'svm' ),
			'methods'  => __( 'Zahlarten', 'svm' ),
			'numbers'  => __( 'Nummernkreise', 'svm' ),
			'templates' => __( 'Vorlagen', 'svm' ),
			'settings' => __( 'Einstellungen', 'svm' ),
		);

		$current = SVM_Admin_Menu::current_tab( 'fields' );

		echo '<div class="wrap svm-wrap">';
		echo '<h1>' . esc_html__( 'Konfiguration', 'svm' ) . '</h1>';

		SVM_Admin_Menu::notices();
		SVM_Admin_Menu::tabs( 'svm-config', $tabs, $current );

		switch ( $current ) {
			case 'units':
				self::render_units();
				break;

			case 'statuses':
				self::render_statuses();
				break;

			case 'roles':
				self::render_roles();
				break;

			case 'users':
				self::render_users();
				break;

			case 'methods':
				self::render_methods();
				break;

			case 'numbers':
				self::render_numbers();
				break;

			case 'templates':
				self::render_templates();
				break;

			case 'settings':
				self::render_settings();
				break;

			default:
				self::render_fields();
				break;
		}

		echo '</div>';
	}

	/**
	 * Felderverwaltung.
	 *
	 * @return void
	 */
	private static function render_fields() {
		SVM_Permissions::require_permission( 'fields_manage' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['field_id'] ) ? absint( $_GET['field_id'] ) : 0;
		$def     = $edit_id ? SVM_Fields::get_def( $edit_id ) : null;

		echo '<h2>' . esc_html__( 'Stammdatenfelder', 'svm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Auch Name, Adresse und Geburtsdatum sind normale Felder — sie können umbenannt, ergänzt oder entfernt werden. Damit das System weiß, welches Feld wofür steht, gibt es die Systemrolle.', 'svm' ) . '</p>';

		$types = SVM_Field_Types::choices();
		$roles = SVM_Fields::system_roles();

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Bezeichnung', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Schlüssel', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Typ', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Abschnitt', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Systemrolle', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Pflicht', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Sensibel', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Liste', 'svm' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( SVM_Fields::defs( 'member', false ) as $item ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $item['label'] ) . '</strong>';
			if ( ! $item['is_active'] ) {
				echo ' <em>(' . esc_html__( 'inaktiv', 'svm' ) . ')</em>';
			}
			echo '</td>';
			echo '<td><code>' . esc_html( $item['field_key'] ) . '</code></td>';
			echo '<td>' . esc_html( isset( $types[ $item['field_type'] ] ) ? $types[ $item['field_type'] ] : $item['field_type'] ) . '</td>';
			echo '<td>' . esc_html( $item['section'] ) . '</td>';
			echo '<td>' . esc_html( isset( $roles[ $item['system_role'] ] ) ? $roles[ $item['system_role'] ] : $item['system_role'] ) . '</td>';
			echo '<td>' . esc_html( $item['is_required'] ? __( 'ja', 'svm' ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $item['is_sensitive'] ? __( 'ja', 'svm' ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $item['show_in_list'] ? __( 'ja', 'svm' ) : '—' ) . '</td>';
			echo '<td><a href="' . esc_url( SVM_Admin_Menu::url( 'svm-config', array( 'tab' => 'fields', 'field_id' => (int) $item['id'] ) ) ) . '">' .
				esc_html__( 'Bearbeiten', 'svm' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html( $def ? __( 'Feld bearbeiten', 'svm' ) : __( 'Neues Feld', 'svm' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields( 'save_field', array( 'id' => $edit_id, 'svm_page' => 'svm-config', 'svm_tab' => 'fields' ) );
		echo '<input type="hidden" name="entity_type" value="member" />';

		SVM_Form::open_table();
		SVM_Form::row( __( 'Bezeichnung', 'svm' ), SVM_Form::input( 'label', $def ? $def['label'] : '', array( 'required' => true ) ) );
		SVM_Form::row(
			__( 'Schlüssel', 'svm' ),
			SVM_Form::input( 'field_key', $def ? $def['field_key'] : '' ),
			__( 'Technischer Name, wird in Formeln als {schluessel} verwendet. Leer = automatisch.', 'svm' )
		);
		SVM_Form::row( __( 'Feldtyp', 'svm' ), SVM_Form::select( 'field_type', $def ? $def['field_type'] : 'text', $types ) );
		SVM_Form::row(
			__( 'Systemrolle', 'svm' ),
			SVM_Form::select( 'system_role', $def ? $def['system_role'] : '', $roles ),
			__( 'Legt fest, welches Feld das System für Anzeige, Alter, E-Mail und SEPA verwendet.', 'svm' )
		);
		SVM_Form::row( __( 'Abschnitt', 'svm' ), SVM_Form::input( 'section', $def ? $def['section'] : '' ), __( 'Felder mit gleichem Abschnitt werden gruppiert.', 'svm' ) );
		SVM_Form::row( __( 'Hilfetext', 'svm' ), SVM_Form::textarea( 'help_text', $def ? $def['help_text'] : '', array( 'rows' => 2 ) ) );

		SVM_Form::row(
			__( 'Auswahloptionen', 'svm' ),
			SVM_Form::textarea( 'options', $def ? self::options_text( $edit_id ) : '', array( 'rows' => 4 ) ),
			__( 'Eine Option je Zeile. Format „wert|Beschriftung“ oder nur „wert“.', 'svm' )
		);

		SVM_Form::row( __( 'Standardwert', 'svm' ), SVM_Form::input( 'default_value', $def ? $def['default_value'] : '' ) );
		SVM_Form::row(
			__( 'Formel (berechnetes Feld)', 'svm' ),
			SVM_Form::input( 'formula', $def ? $def['formula'] : '', array( 'class' => 'large-text' ) ),
			__( 'Nur bei Feldtyp „Berechnetes Feld“, z. B. „{grundbeitrag} * 12“.', 'svm' )
		);
		SVM_Form::row(
			__( 'Prüfmuster', 'svm' ),
			SVM_Form::input( 'validation_regex', $def ? $def['validation_regex'] : '' ),
			__( 'Optionaler regulärer Ausdruck ohne Schrägstriche.', 'svm' )
		);
		SVM_Form::row(
			__( 'Min / Max', 'svm' ),
			SVM_Form::input( 'min_value', $def ? $def['min_value'] : '', array( 'class' => 'small-text' ) ) . ' ' .
			SVM_Form::input( 'max_value', $def ? $def['max_value'] : '', array( 'class' => 'small-text' ) )
		);
		SVM_Form::row( __( 'Pflichtfeld', 'svm' ), SVM_Form::checkbox( 'is_required', $def ? $def['is_required'] : 0, __( 'Muss ausgefüllt werden', 'svm' ) ) );
		SVM_Form::row(
			__( 'Sensibel', 'svm' ),
			SVM_Form::checkbox( 'is_sensitive', $def ? $def['is_sensitive'] : 0, __( 'Besonders schützenswert (z. B. Bankdaten, Gesundheitsangaben)', 'svm' ) ),
			__( 'Sensible Felder sind ohne ausdrückliche Freigabe nicht sichtbar und werden maskiert dargestellt.', 'svm' )
		);
		SVM_Form::row( __( 'In DSGVO-Auskunft', 'svm' ), SVM_Form::checkbox( 'in_gdpr_export', $def ? $def['in_gdpr_export'] : 1, __( 'In der Auskunft ausgeben', 'svm' ) ) );
		SVM_Form::row( __( 'In Mitgliederliste', 'svm' ), SVM_Form::checkbox( 'show_in_list', $def ? $def['show_in_list'] : 0, __( 'Als Spalte in der Übersicht anzeigen', 'svm' ) ) );
		SVM_Form::row(
			__( 'Aufbewahrungsfrist (Monate)', 'svm' ),
			SVM_Form::input( 'retention_months', $def ? $def['retention_months'] : 0, array( 'type' => 'number', 'class' => 'small-text' ) ),
			__( '0 = allgemeine Frist des Vereins.', 'svm' )
		);
		SVM_Form::row( __( 'Reihenfolge', 'svm' ), SVM_Form::input( 'sort_order', $def ? $def['sort_order'] : 0, array( 'type' => 'number', 'class' => 'small-text' ) ) );
		SVM_Form::row( __( 'Aktiv', 'svm' ), SVM_Form::checkbox( 'is_active', $def ? $def['is_active'] : 1, __( 'Feld wird angezeigt', 'svm' ) ) );
		SVM_Form::close_table();

		submit_button( __( 'Feld speichern', 'svm' ) );
		echo '</form>';

		if ( $def ) {
			self::render_field_access( $edit_id );

			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" onsubmit="return confirm(\'' .
				esc_attr__( 'Feld und alle gespeicherten Werte wirklich löschen?', 'svm' ) . '\');">';
			SVM_Form::action_fields( 'delete_field', array( 'id' => $edit_id, 'svm_page' => 'svm-config', 'svm_tab' => 'fields' ) );
			echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Feld löschen', 'svm' ) . '</button>';
			echo '</form>';
		}
	}

	/**
	 * Optionen eines Feldes als Text.
	 *
	 * @param int $field_id Feld-ID.
	 * @return string
	 */
	private static function options_text( $field_id ) {
		$lines = array();

		foreach ( SVM_Fields::options( $field_id ) as $option ) {
			$lines[] = $option['option_value'] === $option['option_label']
				? $option['option_value']
				: $option['option_value'] . '|' . $option['option_label'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * Sichtbarkeit und Bearbeitbarkeit je Rolle.
	 *
	 * @param int $field_id Feld-ID.
	 * @return void
	 */
	private static function render_field_access( $field_id ) {
		$rules = SVM_Fields::visibility( $field_id );

		echo '<h3>' . esc_html__( 'Wer darf dieses Feld sehen und ändern?', 'svm' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Ohne Eintrag gilt: nicht sensible Felder sind sichtbar, Bearbeitung richtet sich nach dem Recht „Mitglieder bearbeiten“.', 'svm' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields( 'save_field_access', array( 'field_id' => $field_id, 'svm_page' => 'svm-config', 'svm_tab' => 'fields' ) );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Rolle', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Sehen', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Ändern', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Änderung nur mit Freigabe', 'svm' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( SVM_Roles::all() as $role ) {
			$role_id = (int) $role['id'];
			$rule    = isset( $rules[ $role_id ] ) ? $rules[ $role_id ] : array( 'can_view' => 0, 'can_edit' => 0, 'requires_approval' => 0 );
			$prefix  = 'access[' . $role_id . ']';

			echo '<tr>';
			echo '<td>' . esc_html( $role['label'] ) . '</td>';
			echo '<td>' . SVM_Form::checkbox( $prefix . '[can_view]', $rule['can_view'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . SVM_Form::checkbox( $prefix . '[can_edit]', $rule['can_edit'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . SVM_Form::checkbox( $prefix . '[requires_approval]', $rule['requires_approval'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</tr>';
		}

		echo '</tbody></table>';

		submit_button( __( 'Feldrechte speichern', 'svm' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Strukturbaum.
	 *
	 * @return void
	 */
	private static function render_units() {
		SVM_Permissions::require_permission( 'units_manage' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['unit_id'] ) ? absint( $_GET['unit_id'] ) : 0;
		$unit    = $edit_id ? SVM_Units::get( $edit_id ) : null;

		echo '<h2>' . esc_html__( 'Sparten, Gruppen und weitere Einheiten', 'svm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Die Anzahl der Ebenen ist frei — eine Sparte kann Trainingsgruppen enthalten, diese wiederum Mannschaften.', 'svm' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Typ', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Übergeordnet', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Mitglieder', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'ID', 'svm' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		$types = SVM_Units::type_options();

		foreach ( SVM_Units::options() as $id => $label ) {
			$item = SVM_Units::get( $id );

			echo '<tr>';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '<td>' . esc_html( isset( $types[ (int) $item['unit_type_id'] ] ) ? $types[ (int) $item['unit_type_id'] ] : '—' ) . '</td>';
			echo '<td>' . esc_html( $item['parent_id'] ? SVM_Units::name( (int) $item['parent_id'] ) : '—' ) . '</td>';
			echo '<td>' . esc_html( SVM_Units::member_count( $id ) ) . '</td>';
			echo '<td><code>' . esc_html( $id ) . '</code></td>';
			echo '<td><a href="' . esc_url( SVM_Admin_Menu::url( 'svm-config', array( 'tab' => 'units', 'unit_id' => $id ) ) ) . '">' .
				esc_html__( 'Bearbeiten', 'svm' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h3>' . esc_html( $unit ? __( 'Einheit bearbeiten', 'svm' ) : __( 'Neue Einheit', 'svm' ) ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields( 'save_unit', array( 'id' => $edit_id, 'svm_page' => 'svm-config', 'svm_tab' => 'units' ) );

		$parents = array( 0 => __( '— oberste Ebene —', 'svm' ) ) + SVM_Units::options();
		unset( $parents[ $edit_id ] );

		$leaders = array( 0 => __( '— keine —', 'svm' ) );

		foreach ( get_users( array( 'number' => 200, 'fields' => array( 'ID', 'display_name' ) ) ) as $user ) {
			$leaders[ (int) $user->ID ] = $user->display_name;
		}

		SVM_Form::open_table();
		SVM_Form::row( __( 'Name', 'svm' ), SVM_Form::input( 'name', $unit ? $unit['name'] : '', array( 'required' => true ) ) );
		SVM_Form::row( __( 'Kurzbezeichnung', 'svm' ), SVM_Form::input( 'short_name', $unit ? $unit['short_name'] : '', array( 'class' => 'small-text' ) ) );
		SVM_Form::row( __( 'Typ', 'svm' ), SVM_Form::select( 'unit_type_id', $unit ? $unit['unit_type_id'] : 0, array( 0 => __( '— ohne —', 'svm' ) ) + $types ) );
		SVM_Form::row( __( 'Übergeordnete Einheit', 'svm' ), SVM_Form::select( 'parent_id', $unit ? $unit['parent_id'] : 0, $parents ) );
		SVM_Form::row( __( 'Leitung', 'svm' ), SVM_Form::select( 'leader_user_id', $unit ? $unit['leader_user_id'] : 0, $leaders ) );
		SVM_Form::row( __( 'Reihenfolge', 'svm' ), SVM_Form::input( 'sort_order', $unit ? $unit['sort_order'] : 0, array( 'type' => 'number', 'class' => 'small-text' ) ) );
		SVM_Form::row( __( 'Aktiv', 'svm' ), SVM_Form::checkbox( 'is_active', $unit ? $unit['is_active'] : 1, __( 'Einheit wird angeboten', 'svm' ) ) );
		SVM_Form::close_table();

		submit_button( __( 'Einheit speichern', 'svm' ) );
		echo '</form>';

		echo '<h3>' . esc_html__( 'Einheitstypen', 'svm' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Benennen Sie die Ebenen so, wie der Verein sie nennt — etwa Sparte, Trainingsgruppe, Mannschaft, Standort.', 'svm' ) . '</p>';
		echo '<p>' . esc_html( implode( ' · ', $types ) ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields( 'save_unit_type', array( 'svm_page' => 'svm-config', 'svm_tab' => 'units' ) );
		echo '<p>' . SVM_Form::input( 'label', '', array( 'placeholder' => __( 'Neuer Einheitstyp', 'svm' ) ) ) . // phpcs:ignore WordPress.Security.EscapeOutput
			' <button type="submit" class="button">' . esc_html__( 'Hinzufügen', 'svm' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * Statusverwaltung.
	 *
	 * @return void
	 */
	private static function render_statuses() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['status_id'] ) ? absint( $_GET['status_id'] ) : 0;
		$status  = $edit_id ? SVM_Statuses::get( $edit_id ) : null;

		echo '<h2>' . esc_html__( 'Mitgliedsstatus', 'svm' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Bezeichnung', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'ID', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Zählt als aktiv', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Beitragspflichtig', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Login möglich', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Standard', 'svm' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( SVM_Statuses::all() as $item ) {
			echo '<tr>';
			echo '<td>' . esc_html( $item['label'] ) . '</td>';
			echo '<td><code>' . esc_html( $item['id'] ) . '</code></td>';
			echo '<td>' . esc_html( $item['counts_as_active'] ? __( 'ja', 'svm' ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $item['is_billable'] ? __( 'ja', 'svm' ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $item['allows_login'] ? __( 'ja', 'svm' ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $item['is_default'] ? __( 'ja', 'svm' ) : '—' ) . '</td>';
			echo '<td><a href="' . esc_url( SVM_Admin_Menu::url( 'svm-config', array( 'tab' => 'statuses', 'status_id' => (int) $item['id'] ) ) ) . '">' .
				esc_html__( 'Bearbeiten', 'svm' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h3>' . esc_html( $status ? __( 'Status bearbeiten', 'svm' ) : __( 'Neuer Status', 'svm' ) ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields( 'save_status', array( 'id' => $edit_id, 'svm_page' => 'svm-config', 'svm_tab' => 'statuses' ) );

		SVM_Form::open_table();
		SVM_Form::row( __( 'Bezeichnung', 'svm' ), SVM_Form::input( 'label', $status ? $status['label'] : '', array( 'required' => true ) ) );
		SVM_Form::row( __( 'Schlüssel', 'svm' ), SVM_Form::input( 'status_key', $status ? $status['status_key'] : '' ) );
		SVM_Form::row( __( 'Farbe', 'svm' ), SVM_Form::input( 'color', $status ? $status['color'] : '', array( 'type' => 'color' ) ) );
		SVM_Form::row(
			__( 'Zählt als aktives Mitglied', 'svm' ),
			SVM_Form::checkbox( 'counts_as_active', $status ? $status['counts_as_active'] : 1, __( 'In Statistiken und Verbandsmeldungen mitzählen', 'svm' ) )
		);
		SVM_Form::row(
			__( 'Beitragspflichtig', 'svm' ),
			SVM_Form::checkbox( 'is_billable', $status ? $status['is_billable'] : 1, __( 'Im Beitragslauf berücksichtigen', 'svm' ) ),
			__( 'Ehrenmitglieder erhalten hier üblicherweise kein Häkchen.', 'svm' )
		);
		SVM_Form::row( __( 'Login möglich', 'svm' ), SVM_Form::checkbox( 'allows_login', $status ? $status['allows_login'] : 1, __( 'Zugang zum Mitgliederportal', 'svm' ) ) );
		SVM_Form::row( __( 'Standard', 'svm' ), SVM_Form::checkbox( 'is_default', $status ? $status['is_default'] : 0, __( 'Für neue Mitglieder voreingestellt', 'svm' ) ) );
		SVM_Form::row( __( 'Reihenfolge', 'svm' ), SVM_Form::input( 'sort_order', $status ? $status['sort_order'] : 0, array( 'type' => 'number', 'class' => 'small-text' ) ) );
		SVM_Form::close_table();

		submit_button( __( 'Status speichern', 'svm' ) );
		echo '</form>';

		self::render_transitions();
	}

	/**
	 * Statusübergänge.
	 *
	 * @return void
	 */
	private static function render_transitions() {
		$statuses = SVM_Statuses::options();

		if ( empty( $statuses ) ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Erlaubte Statuswechsel', 'svm' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Solange kein Übergang definiert ist, sind alle Wechsel erlaubt.', 'svm' ) . '</p>';

		$rows = SVM_DB::all( 'status_transitions' );

		if ( ! empty( $rows ) ) {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Von', 'svm' ) . '</th>';
			echo '<th>' . esc_html__( 'Nach', 'svm' ) . '</th>';
			echo '<th>' . esc_html__( 'Aktion', 'svm' ) . '</th>';
			echo '</tr></thead><tbody>';

			$actions = SVM_Statuses::transition_actions();

			foreach ( $rows as $row ) {
				echo '<tr>';
				echo '<td>' . esc_html( isset( $statuses[ (int) $row['from_status_id'] ] ) ? $statuses[ (int) $row['from_status_id'] ] : '—' ) . '</td>';
				echo '<td>' . esc_html( isset( $statuses[ (int) $row['to_status_id'] ] ) ? $statuses[ (int) $row['to_status_id'] ] : '—' ) . '</td>';
				echo '<td>' . esc_html( isset( $actions[ $row['on_transition'] ] ) ? $actions[ $row['on_transition'] ] : '—' ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields( 'save_transition', array( 'svm_page' => 'svm-config', 'svm_tab' => 'statuses' ) );

		SVM_Form::open_table();
		SVM_Form::row( __( 'Von', 'svm' ), SVM_Form::select( 'from_status_id', 0, $statuses ) );
		SVM_Form::row( __( 'Nach', 'svm' ), SVM_Form::select( 'to_status_id', 0, $statuses ) );
		SVM_Form::row(
			__( 'Aktion beim Wechsel', 'svm' ),
			SVM_Form::select( 'on_transition', '', SVM_Statuses::transition_actions() )
		);
		SVM_Form::row( __( 'Begründung erforderlich', 'svm' ), SVM_Form::checkbox( 'requires_reason', 0, __( 'Ja', 'svm' ) ) );
		SVM_Form::close_table();

		submit_button( __( 'Übergang hinzufügen', 'svm' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Rollen und Rechte.
	 *
	 * @return void
	 */
	private static function render_roles() {
		SVM_Permissions::require_permission( 'roles_manage' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['role_id'] ) ? absint( $_GET['role_id'] ) : 0;
		$role    = $edit_id ? SVM_Roles::get( $edit_id ) : null;
		$scopes  = SVM_Roles::scopes();

		echo '<h2>' . esc_html__( 'Rollen', 'svm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Rollen sind frei anlegbar. Der Geltungsbereich entscheidet, auf welche Mitglieder sich die Rechte beziehen — so genügt eine einzige Rolle „Sparten-Leiter“ für alle Sparten.', 'svm' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Bezeichnung', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Geltungsbereich', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Rechte', 'svm' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( SVM_Roles::all() as $item ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $item['label'] ) . '</strong></td>';
			echo '<td>' . esc_html( isset( $scopes[ $item['scope'] ] ) ? $scopes[ $item['scope'] ] : $item['scope'] ) . '</td>';
			echo '<td>' . esc_html( count( SVM_Roles::permissions( (int) $item['id'] ) ) ) . '</td>';
			echo '<td><a href="' . esc_url( SVM_Admin_Menu::url( 'svm-config', array( 'tab' => 'roles', 'role_id' => (int) $item['id'] ) ) ) . '">' .
				esc_html__( 'Bearbeiten', 'svm' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$granted = $role ? SVM_Roles::permissions( $edit_id ) : array();

		echo '<h3>' . esc_html( $role ? __( 'Rolle bearbeiten', 'svm' ) : __( 'Neue Rolle', 'svm' ) ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields( 'save_role', array( 'id' => $edit_id, 'svm_page' => 'svm-config', 'svm_tab' => 'roles' ) );

		SVM_Form::open_table();
		SVM_Form::row( __( 'Bezeichnung', 'svm' ), SVM_Form::input( 'label', $role ? $role['label'] : '', array( 'required' => true ) ) );
		SVM_Form::row( __( 'Schlüssel', 'svm' ), SVM_Form::input( 'role_key', $role ? $role['role_key'] : '' ) );
		SVM_Form::row( __( 'Beschreibung', 'svm' ), SVM_Form::textarea( 'description', $role ? $role['description'] : '', array( 'rows' => 2 ) ) );
		SVM_Form::row( __( 'Geltungsbereich', 'svm' ), SVM_Form::select( 'scope', $role ? $role['scope'] : 'self', $scopes ) );
		SVM_Form::row(
			__( 'Untereinheiten einschließen', 'svm' ),
			SVM_Form::checkbox( 'include_subunits', $role ? $role['include_subunits'] : 1, __( 'Zugriff erstreckt sich auf untergeordnete Gruppen', 'svm' ) )
		);
		SVM_Form::row(
			__( 'Mitgliedsrolle', 'svm' ),
			SVM_Form::checkbox( 'is_member_role', $role ? $role['is_member_role'] : 0, __( 'Wird neuen Portalbenutzern automatisch zugewiesen', 'svm' ) )
		);
		SVM_Form::close_table();

		echo '<h4>' . esc_html__( 'Rechte', 'svm' ) . '</h4>';

		foreach ( SVM_Permissions::catalog() as $group => $permissions ) {
			echo '<h4 class="svm-permission-group">' . esc_html( $group ) . '</h4>';
			echo '<div class="svm-column-grid">';

			foreach ( $permissions as $key => $label ) {
				echo '<label><input type="checkbox" name="permissions[]" value="' . esc_attr( $key ) . '"' .
					( in_array( $key, $granted, true ) ? ' checked' : '' ) . ' /> ' . esc_html( $label ) . '</label>';
			}

			echo '</div>';
		}

		submit_button( __( 'Rolle speichern', 'svm' ) );
		echo '</form>';

		if ( $role ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
			SVM_Form::action_fields( 'delete_role', array( 'id' => $edit_id, 'svm_page' => 'svm-config', 'svm_tab' => 'roles' ) );
			echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Rolle löschen', 'svm' ) . '</button>';
			echo '</form>';
		}
	}

	/**
	 * Benutzerzuordnung.
	 *
	 * @return void
	 */
	private static function render_users() {
		SVM_Permissions::require_permission( 'roles_manage' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;

		echo '<h2>' . esc_html__( 'Benutzer, Rollen und Zuständigkeiten', 'svm' ) . '</h2>';

		$roles = array();

		foreach ( SVM_Roles::all() as $role ) {
			$roles[ (int) $role['id'] ] = $role['label'];
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Benutzer', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Rollen', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Zuständig für', 'svm' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( get_users( array( 'number' => 200 ) ) as $user ) {
			$user_roles = SVM_Roles::user_role_ids( (int) $user->ID );
			$user_units = SVM_Roles::user_unit_ids( (int) $user->ID );

			if ( empty( $user_roles ) && (int) $user->ID !== $user_id ) {
				continue;
			}

			$role_labels = array();
			foreach ( $user_roles as $role_id ) {
				$role_labels[] = isset( $roles[ $role_id ] ) ? $roles[ $role_id ] : '#' . $role_id;
			}

			$unit_labels = array();
			foreach ( $user_units as $unit_id ) {
				$unit_labels[] = SVM_Units::name( $unit_id );
			}

			echo '<tr>';
			echo '<td>' . esc_html( $user->display_name ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', $role_labels ) ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', array_filter( $unit_labels ) ) ) . '</td>';
			echo '<td><a href="' . esc_url( SVM_Admin_Menu::url( 'svm-config', array( 'tab' => 'users', 'user_id' => (int) $user->ID ) ) ) . '">' .
				esc_html__( 'Bearbeiten', 'svm' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Zuordnung ändern', 'svm' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields( 'assign_user', array( 'svm_page' => 'svm-config', 'svm_tab' => 'users' ) );

		$users = array( 0 => __( '— Benutzer wählen —', 'svm' ) );

		foreach ( get_users( array( 'number' => 300, 'fields' => array( 'ID', 'display_name', 'user_email' ) ) ) as $user ) {
			$users[ (int) $user->ID ] = $user->display_name . ' (' . $user->user_email . ')';
		}

		SVM_Form::open_table();
		SVM_Form::row( __( 'Benutzer', 'svm' ), SVM_Form::select( 'user_id', $user_id, $users ) );
		SVM_Form::row(
			__( 'Rollen', 'svm' ),
			SVM_Form::select( 'role_ids', $user_id ? SVM_Roles::user_role_ids( $user_id ) : array(), $roles, array( 'multiple' => true, 'size' => 6 ) )
		);
		SVM_Form::row(
			__( 'Zuständig für Sparten/Gruppen', 'svm' ),
			SVM_Form::select( 'unit_ids', $user_id ? SVM_Roles::user_unit_ids( $user_id ) : array(), SVM_Units::options(), array( 'multiple' => true, 'size' => 6 ) ),
			__( 'Wirkt bei Rollen mit dem Geltungsbereich „Nur zugeordnete Sparten/Gruppen“.', 'svm' )
		);
		SVM_Form::close_table();

		submit_button( __( 'Zuordnung speichern', 'svm' ) );
		echo '</form>';
	}

	/**
	 * Zahlarten.
	 *
	 * @return void
	 */
	private static function render_methods() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['method_id'] ) ? absint( $_GET['method_id'] ) : 0;
		$method  = $edit_id ? SVM_Payment_Methods::get( $edit_id ) : null;

		$behaviors = SVM_Payment_Methods::behaviors();

		echo '<h2>' . esc_html__( 'Zahlarten', 'svm' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Bezeichnung', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Verhalten', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Mandat nötig', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Standard', 'svm' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( SVM_Payment_Methods::all() as $item ) {
			echo '<tr>';
			echo '<td>' . esc_html( $item['label'] ) . '</td>';
			echo '<td>' . esc_html( isset( $behaviors[ $item['behavior'] ] ) ? $behaviors[ $item['behavior'] ] : $item['behavior'] ) . '</td>';
			echo '<td>' . esc_html( $item['requires_mandate'] ? __( 'ja', 'svm' ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $item['is_default'] ? __( 'ja', 'svm' ) : '—' ) . '</td>';
			echo '<td><a href="' . esc_url( SVM_Admin_Menu::url( 'svm-config', array( 'tab' => 'methods', 'method_id' => (int) $item['id'] ) ) ) . '">' .
				esc_html__( 'Bearbeiten', 'svm' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h3>' . esc_html( $method ? __( 'Zahlart bearbeiten', 'svm' ) : __( 'Neue Zahlart', 'svm' ) ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields( 'save_payment_method', array( 'id' => $edit_id, 'svm_page' => 'svm-config', 'svm_tab' => 'methods' ) );

		SVM_Form::open_table();
		SVM_Form::row( __( 'Bezeichnung', 'svm' ), SVM_Form::input( 'label', $method ? $method['label'] : '', array( 'required' => true ) ) );
		SVM_Form::row( __( 'Schlüssel', 'svm' ), SVM_Form::input( 'method_key', $method ? $method['method_key'] : '' ) );
		SVM_Form::row(
			__( 'Verhalten', 'svm' ),
			SVM_Form::select( 'behavior', $method ? $method['behavior'] : 'manual', $behaviors )
		);
		SVM_Form::row( __( 'Mandat erforderlich', 'svm' ), SVM_Form::checkbox( 'requires_mandate', $method ? $method['requires_mandate'] : 0, __( 'Ja', 'svm' ) ) );
		SVM_Form::row( __( 'Bankverbindung erforderlich', 'svm' ), SVM_Form::checkbox( 'requires_bank_account', $method ? $method['requires_bank_account'] : 0, __( 'Ja', 'svm' ) ) );
		SVM_Form::row( __( 'Standard', 'svm' ), SVM_Form::checkbox( 'is_default', $method ? $method['is_default'] : 0, __( 'Für neue Mitglieder voreingestellt', 'svm' ) ) );
		SVM_Form::row(
			__( 'Im Portal wählbar', 'svm' ),
			SVM_Form::checkbox( 'allow_self_select', $method ? $method['allow_self_select'] : 0, __( 'Mitglieder dürfen diese Zahlart selbst wählen', 'svm' ) )
		);
		SVM_Form::row( __( 'Aktiv', 'svm' ), SVM_Form::checkbox( 'is_active', $method ? $method['is_active'] : 1, __( 'Zahlart wird angeboten', 'svm' ) ) );
		SVM_Form::close_table();

		submit_button( __( 'Zahlart speichern', 'svm' ) );
		echo '</form>';
	}

	/**
	 * Nummernkreise.
	 *
	 * @return void
	 */
	private static function render_numbers() {
		echo '<h2>' . esc_html__( 'Nummernkreise', 'svm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Aufbau der Mitgliedsnummern und Mandatsreferenzen. Beispiel: Präfix „SV“ + Jahr + 5 Stellen ergibt SV-2026-00042.', 'svm' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Schlüssel', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Präfix', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Jahr', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Stellen', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Nächster Wert', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Beispiel', 'svm' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( SVM_Number_Range::all() as $range ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( $range['range_key'] ) . '</code></td>';
			echo '<td>' . esc_html( $range['prefix'] ) . '</td>';
			echo '<td>' . esc_html( $range['use_year'] ? __( 'ja', 'svm' ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $range['digits'] ) . '</td>';
			echo '<td>' . esc_html( $range['next_value'] ) . '</td>';
			echo '<td><code>' . esc_html( SVM_Number_Range::render( $range, (int) $range['next_value'] ) ) . '</code></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		foreach ( SVM_Number_Range::all() as $range ) {
			echo '<h3>' . esc_html( $range['label'] ? $range['label'] : $range['range_key'] ) . '</h3>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
			SVM_Form::action_fields( 'save_number_range', array( 'id' => (int) $range['id'], 'svm_page' => 'svm-config', 'svm_tab' => 'numbers' ) );
			echo '<input type="hidden" name="range_key" value="' . esc_attr( $range['range_key'] ) . '" />';
			echo '<input type="hidden" name="label" value="' . esc_attr( $range['label'] ) . '" />';

			SVM_Form::open_table();
			SVM_Form::row( __( 'Präfix', 'svm' ), SVM_Form::input( 'prefix', $range['prefix'], array( 'class' => 'small-text' ) ) );
			SVM_Form::row( __( 'Jahr einfügen', 'svm' ), SVM_Form::checkbox( 'use_year', $range['use_year'], __( 'Ja', 'svm' ) ) );
			SVM_Form::row( __( 'Stellen', 'svm' ), SVM_Form::input( 'digits', $range['digits'], array( 'type' => 'number', 'class' => 'small-text' ) ) );
			SVM_Form::row( __( 'Nächster Wert', 'svm' ), SVM_Form::input( 'next_value', $range['next_value'], array( 'type' => 'number', 'class' => 'small-text' ) ) );
			SVM_Form::close_table();

			submit_button( __( 'Speichern', 'svm' ), 'secondary' );
			echo '</form>';
		}
	}

	/**
	 * Vorlagen.
	 *
	 * @return void
	 */
	private static function render_templates() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id  = isset( $_GET['template_id'] ) ? absint( $_GET['template_id'] ) : 0;
		$template = $edit_id ? SVM_Templates::get( $edit_id ) : null;

		echo '<h2>' . esc_html__( 'E-Mail- und Textvorlagen', 'svm' ) . '</h2>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Bezeichnung', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Typ', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Betreff', 'svm' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( SVM_Templates::all() as $item ) {
			echo '<tr>';
			echo '<td>' . esc_html( $item['label'] ) . '</td>';
			echo '<td>' . esc_html( $item['type'] ) . '</td>';
			echo '<td>' . esc_html( $item['subject'] ) . '</td>';
			echo '<td><a href="' . esc_url( SVM_Admin_Menu::url( 'svm-config', array( 'tab' => 'templates', 'template_id' => (int) $item['id'] ) ) ) . '">' .
				esc_html__( 'Bearbeiten', 'svm' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$placeholders = array();

		foreach ( SVM_Templates::placeholders() as $key => $label ) {
			$placeholders[] = $key . ' = ' . $label;
		}

		echo '<h3>' . esc_html( $template ? __( 'Vorlage bearbeiten', 'svm' ) : __( 'Neue Vorlage', 'svm' ) ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields( 'save_template', array( 'id' => $edit_id, 'svm_page' => 'svm-config', 'svm_tab' => 'templates' ) );

		SVM_Form::open_table();
		SVM_Form::row( __( 'Bezeichnung', 'svm' ), SVM_Form::input( 'label', $template ? $template['label'] : '', array( 'required' => true ) ) );
		SVM_Form::row(
			__( 'Typ', 'svm' ),
			SVM_Form::select( 'type', $template ? $template['type'] : 'email', array( 'email' => __( 'E-Mail', 'svm' ), 'pdf' => __( 'PDF/Brief', 'svm' ) ) )
		);
		SVM_Form::row( __( 'Betreff', 'svm' ), SVM_Form::input( 'subject', $template ? $template['subject'] : '', array( 'class' => 'large-text' ) ) );
		SVM_Form::row(
			__( 'Inhalt', 'svm' ),
			SVM_Form::textarea( 'body', $template ? $template['body'] : '', array( 'rows' => 8 ) ),
			implode( ' · ', $placeholders )
		);
		SVM_Form::close_table();

		submit_button( __( 'Vorlage speichern', 'svm' ) );
		echo '</form>';
	}

	/**
	 * Grundeinstellungen und Konfigurationstransfer.
	 *
	 * @return void
	 */
	private static function render_settings() {
		echo '<h2>' . esc_html__( 'Grundeinstellungen', 'svm' ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields( 'save_settings', array( 'svm_page' => 'svm-config', 'svm_tab' => 'settings' ) );

		$pages = array( 0 => __( '— keine —', 'svm' ) );

		foreach ( get_pages( array( 'number' => 200 ) ) as $page ) {
			$pages[ (int) $page->ID ] = $page->post_title;
		}

		SVM_Form::open_table();
		SVM_Form::row( __( 'Name des Vereins', 'svm' ), SVM_Form::input( 'club_name', get_option( 'svm_club_name', get_bloginfo( 'name' ) ) ) );
		SVM_Form::row(
			__( 'Seite des Mitgliederportals', 'svm' ),
			SVM_Form::select( 'portal_page_id', (int) get_option( 'svm_portal_page_id', 0 ), $pages ),
			__( 'Auf dieser Seite den Shortcode [svm_portal] einfügen.', 'svm' )
		);
		SVM_Form::row(
			__( 'Geschäftsjahresbeginn', 'svm' ),
			SVM_Form::input( 'fiscal_year_start', get_option( 'svm_fiscal_year_start', '01-01' ), array( 'class' => 'small-text' ) ),
			__( 'Format TT-MM.', 'svm' )
		);
		SVM_Form::row(
			__( 'Aufbewahrungsfrist nach Austritt (Monate)', 'svm' ),
			SVM_Form::input( 'retention_months', (int) get_option( 'svm_retention_months', 120 ), array( 'type' => 'number', 'class' => 'small-text' ) ),
			__( 'Standard 120 Monate wegen der zehnjährigen Aufbewahrungspflicht für Finanzunterlagen.', 'svm' )
		);
		SVM_Form::row(
			__( 'Sparten im Portal wählbar', 'svm' ),
			SVM_Form::checkbox( 'self_service_units', get_option( 'svm_self_service_units', 0 ), __( 'Mitglieder dürfen ihre Sparten selbst ändern (erzeugt Antrag)', 'svm' ) )
		);
		SVM_Form::close_table();

		submit_button( __( 'Einstellungen speichern', 'svm' ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Konfiguration sichern und übertragen', 'svm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Felder, Rollen, Beitragsregeln, Profile und Vorlagen als Datei sichern — für den Umzug auf ein anderes System oder als Startvorlage für einen anderen Verein. Bankdaten und Gläubiger-ID werden dabei bewusst nicht mitexportiert.', 'svm' ) . '</p>';

		echo '<div class="svm-action-row">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="svm-inline-form">';
		SVM_Form::action_fields( 'export_config', array( 'svm_page' => 'svm-config', 'svm_tab' => 'settings' ) );
		echo '<button type="submit" class="button">' . esc_html__( 'Konfiguration exportieren', 'svm' ) . '</button>';
		echo '</form>';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="svm-inline-form" ' .
			'onsubmit="return confirm(\'' . esc_attr__( 'Die vorhandene Konfiguration wird ersetzt. Fortfahren?', 'svm' ) . '\');">';
		SVM_Form::action_fields( 'import_config', array( 'svm_page' => 'svm-config', 'svm_tab' => 'settings' ) );
		echo '<input type="file" name="config_file" accept=".json,application/json" required /> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Konfiguration importieren', 'svm' ) . '</button>';
		echo '</form>';

		echo '</div>';
	}
}
