<?php
/**
 * Auswertungsseite.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Export-Profile, Import, DSGVO und Änderungsprotokoll.
 */
class SVM_Page_Data {

	/**
	 * Gibt die Seite aus.
	 *
	 * @return void
	 */
	public static function render() {
		$tabs = array(
			'exports' => __( 'Exporte', 'svm' ),
			'import'  => __( 'Import', 'svm' ),
			'gdpr'    => __( 'Datenschutz', 'svm' ),
			'audit'   => __( 'Änderungsprotokoll', 'svm' ),
		);

		$current = SVM_Admin_Menu::current_tab( 'exports' );

		echo '<div class="wrap svm-wrap">';
		echo '<h1>' . esc_html__( 'Auswertungen', 'svm' ) . '</h1>';

		SVM_Admin_Menu::notices();
		SVM_Admin_Menu::tabs( 'svm-data', $tabs, $current );

		switch ( $current ) {
			case 'import':
				self::render_import();
				break;

			case 'gdpr':
				self::render_gdpr();
				break;

			case 'audit':
				self::render_audit();
				break;

			default:
				self::render_exports();
				break;
		}

		echo '</div>';
	}

	/**
	 * Export-Profile.
	 *
	 * @return void
	 */
	private static function render_exports() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['profile_id'] ) ? absint( $_GET['profile_id'] ) : 0;
		$profile = $edit_id ? SVM_Export_Profiles::get( $edit_id ) : null;

		echo '<h2>' . esc_html__( 'Verfügbare Exporte', 'svm' ) . '</h2>';

		$entities = SVM_Export_Profiles::entity_types();
		$formats  = SVM_Export_Profiles::formats();

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Bezeichnung', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Datenbasis', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Format', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Spalten', 'svm' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( SVM_Export_Profiles::available() as $item ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $item['label'] ) . '</strong></td>';
			echo '<td>' . esc_html( isset( $entities[ $item['entity_type'] ] ) ? $entities[ $item['entity_type'] ] : $item['entity_type'] ) . '</td>';
			echo '<td>' . esc_html( isset( $formats[ $item['format'] ] ) ? $formats[ $item['format'] ] : $item['format'] ) . '</td>';
			echo '<td>' . esc_html( count( SVM_Export_Profiles::columns( $item ) ) ) . '</td>';
			echo '<td><div class="svm-action-row">';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="svm-inline-form">';
			SVM_Form::action_fields( 'download_export', array( 'id' => (int) $item['id'], 'svm_page' => 'svm-data', 'svm_tab' => 'exports' ) );
			echo '<button type="submit" class="button button-primary button-small">' . esc_html__( 'Herunterladen', 'svm' ) . '</button>';
			echo '</form>';

			if ( SVM_Permissions::current_user_can( 'export_manage' ) ) {
				echo '<a class="button button-small" href="' .
					esc_url( SVM_Admin_Menu::url( 'svm-data', array( 'tab' => 'exports', 'profile_id' => (int) $item['id'] ) ) ) . '">' .
					esc_html__( 'Bearbeiten', 'svm' ) . '</a>';
			}

			echo '</div></td></tr>';
		}

		echo '</tbody></table>';

		if ( ! SVM_Permissions::current_user_can( 'export_manage' ) ) {
			return;
		}

		$entity_type = $profile ? $profile['entity_type'] : 'member';
		$columns     = SVM_Exporter::available_columns( $entity_type );
		$selected    = $profile ? SVM_Export_Profiles::columns( $profile ) : array_keys( $columns );
		$roles       = array();

		foreach ( SVM_Roles::all() as $role ) {
			$roles[ (int) $role['id'] ] = $role['label'];
		}

		echo '<h2>' . esc_html( $profile ? __( 'Export-Profil bearbeiten', 'svm' ) : __( 'Neues Export-Profil', 'svm' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields( 'save_export_profile', array( 'id' => $edit_id, 'svm_page' => 'svm-data', 'svm_tab' => 'exports' ) );

		SVM_Form::open_table();
		SVM_Form::row( __( 'Bezeichnung', 'svm' ), SVM_Form::input( 'label', $profile ? $profile['label'] : '', array( 'required' => true ) ) );
		SVM_Form::row(
			__( 'Datenbasis', 'svm' ),
			SVM_Form::select( 'entity_type', $entity_type, $entities ),
			__( 'Nach dem Wechsel speichern, damit die passenden Spalten erscheinen.', 'svm' )
		);
		SVM_Form::row( __( 'Format', 'svm' ), SVM_Form::select( 'format', $profile ? $profile['format'] : 'csv', $formats ) );
		SVM_Form::row( __( 'Trennzeichen', 'svm' ), SVM_Form::input( 'delimiter', $profile ? $profile['delimiter'] : ';', array( 'class' => 'small-text' ) ) );
		SVM_Form::row(
			__( 'Zeichensatz', 'svm' ),
			SVM_Form::select( 'charset', $profile ? $profile['charset'] : 'UTF-8', array( 'UTF-8' => 'UTF-8', 'ISO-8859-1' => 'ISO-8859-1' ) )
		);
		SVM_Form::row(
			__( 'Ausführen dürfen', 'svm' ),
			SVM_Form::select(
				'allowed_roles',
				$profile ? (array) json_decode( (string) $profile['allowed_roles'], true ) : array(),
				$roles,
				array( 'multiple' => true, 'size' => 5 )
			),
			__( 'Nichts ausgewählt = alle mit dem Recht „Export ausführen“. Wichtig bei Profilen mit Bankdaten.', 'svm' )
		);
		SVM_Form::row( __( 'Aktiv', 'svm' ), SVM_Form::checkbox( 'is_active', $profile ? $profile['is_active'] : 1, __( 'Profil steht zur Verfügung', 'svm' ) ) );
		SVM_Form::close_table();

		echo '<h3>' . esc_html__( 'Spalten', 'svm' ) . '</h3>';
		echo '<div class="svm-column-grid">';

		foreach ( $columns as $key => $label ) {
			echo '<label><input type="checkbox" name="columns[]" value="' . esc_attr( $key ) . '"' .
				( in_array( $key, $selected, true ) ? ' checked' : '' ) . ' /> ' . esc_html( $label ) . '</label>';
		}

		echo '</div>';

		submit_button( __( 'Profil speichern', 'svm' ) );
		echo '</form>';
	}

	/**
	 * Import.
	 *
	 * @return void
	 */
	private static function render_import() {
		SVM_Permissions::require_permission( 'import_run' );

		$stored = get_transient( 'svm_import_' . get_current_user_id() );

		echo '<h2>' . esc_html__( 'Mitglieder importieren', 'svm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Schritt 1: CSV-Datei hochladen. Schritt 2: Spalten den eigenen Feldern zuordnen. Schritt 3: Testlauf, dann übernehmen.', 'svm' ) . '</p>';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields( 'run_import', array( 'svm_page' => 'svm-data', 'svm_tab' => 'import' ) );

		SVM_Form::open_table();
		SVM_Form::row( __( 'CSV-Datei', 'svm' ), '<input type="file" name="import_file" accept=".csv,text/csv" required />' );
		SVM_Form::row( __( 'Trennzeichen', 'svm' ), SVM_Form::input( 'delimiter', ';', array( 'class' => 'small-text' ) ) );
		SVM_Form::close_table();

		submit_button( __( 'Datei einlesen', 'svm' ), 'secondary' );
		echo '</form>';

		if ( ! is_array( $stored ) || empty( $stored['header'] ) ) {
			return;
		}

		$suggestion = SVM_Importer::suggest_mapping( $stored['header'] );
		$targets    = SVM_Importer::target_options();

		echo '<h3>' . esc_html__( 'Spalten zuordnen', 'svm' ) . '</h3>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: Anzahl Zeilen. */
				__( 'Eingelesen: %d Datenzeilen.', 'svm' ),
				count( $stored['rows'] )
			)
		) . '</p>';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields( 'run_import', array( 'svm_page' => 'svm-data', 'svm_tab' => 'import' ) );

		echo '<p>' . esc_html__( 'Bitte dieselbe Datei erneut auswählen:', 'svm' ) .
			' <input type="file" name="import_file" accept=".csv,text/csv" required /></p>';
		echo '<input type="hidden" name="delimiter" value=";" />';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Spalte in der Datei', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Beispielwert', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Ziel im System', 'svm' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $stored['header'] as $index => $label ) {
			$sample = isset( $stored['rows'][0][ $index ] ) ? $stored['rows'][0][ $index ] : '';

			echo '<tr>';
			echo '<td><strong>' . esc_html( $label ) . '</strong></td>';
			echo '<td><code>' . esc_html( $sample ) . '</code></td>';
			echo '<td>' . SVM_Form::select( // phpcs:ignore WordPress.Security.EscapeOutput
				'mapping[' . (int) $index . ']',
				isset( $suggestion[ $index ] ) ? $suggestion[ $index ] : '',
				$targets
			) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<p>' . SVM_Form::checkbox( 'update_existing', true, __( 'Bestehende Mitglieder anhand der Mitgliedsnummer aktualisieren', 'svm' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<p>';
		echo '<button type="submit" name="dry_run" value="yes" class="button">' . esc_html__( 'Testlauf (nichts speichern)', 'svm' ) . '</button> ';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Import durchführen', 'svm' ) . '</button>';
		echo '</p>';

		echo '</form>';
	}

	/**
	 * Datenschutzwerkzeuge.
	 *
	 * @return void
	 */
	private static function render_gdpr() {
		$months = (int) get_option( 'svm_retention_months', 120 );
		$due    = SVM_GDPR::due_for_anonymization( $months );

		echo '<h2>' . esc_html__( 'Aufbewahrungsfristen', 'svm' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: Anzahl Monate. */
				__( 'Eingestellte Frist nach Austritt: %d Monate. Finanzunterlagen unterliegen in der Regel einer zehnjährigen Aufbewahrungspflicht.', 'svm' ),
				$months
			)
		) . '</p>';

		if ( empty( $due ) ) {
			echo '<p>' . esc_html__( 'Derzeit ist für kein Mitglied die Frist abgelaufen.', 'svm' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Mitglied', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Ausgetreten', 'svm' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( $due as $member ) {
			echo '<tr>';
			echo '<td>' . esc_html( SVM_Members::display_name( (int) $member['id'] ) ) . '</td>';
			echo '<td>' . esc_html( $member['left_at'] ) . '</td>';
			echo '<td>';

			if ( SVM_Permissions::current_user_can( 'members_delete' ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" onsubmit="return confirm(\'' .
					esc_attr__( 'Personenbezogene Daten wirklich unwiderruflich anonymisieren?', 'svm' ) . '\');">';
				SVM_Form::action_fields(
					'anonymize_member',
					array(
						'member_id' => (int) $member['id'],
						'svm_page'  => 'svm-data',
						'svm_tab'   => 'gdpr',
					)
				);
				echo '<button type="submit" class="button button-small">' . esc_html__( 'Anonymisieren', 'svm' ) . '</button>';
				echo '</form>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Änderungsprotokoll.
	 *
	 * @return void
	 */
	private static function render_audit() {
		SVM_Permissions::require_permission( 'audit_view' );

		$entries = SVM_Audit::query( array( 'limit' => 200 ) );

		echo '<h2>' . esc_html__( 'Änderungsprotokoll', 'svm' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Zeitpunkt', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Benutzer', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Bereich', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Aktion', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Feld', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Vorher', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Nachher', 'svm' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$user = get_userdata( (int) $entry['user_id'] );

			echo '<tr>';
			echo '<td>' . esc_html( $entry['created_at'] ) . '</td>';
			echo '<td>' . esc_html( $user ? $user->display_name : '—' ) . '</td>';
			echo '<td>' . esc_html( $entry['entity_type'] . ' #' . $entry['entity_id'] ) . '</td>';
			echo '<td>' . esc_html( $entry['action'] ) . '</td>';
			echo '<td>' . esc_html( $entry['field_key'] ) . '</td>';
			echo '<td>' . esc_html( mb_substr( (string) $entry['old_value'], 0, 60 ) ) . '</td>';
			echo '<td>' . esc_html( mb_substr( (string) $entry['new_value'], 0, 60 ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
