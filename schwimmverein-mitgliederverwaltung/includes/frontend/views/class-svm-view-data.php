<?php
/**
 * Import, Export, Datenschutz und Protokoll.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mitgliederlisten herunterladen und Bestände einlesen.
 */
class SVM_View_Data {

	/**
	 * Reiter dieses Bereichs.
	 *
	 * @return array
	 */
	private static function tabs() {
		return array(
			'export'  => __( 'Exportieren', 'svm' ),
			'import'  => __( 'Importieren', 'svm' ),
			'privacy' => __( 'Datenschutz', 'svm' ),
			'audit'   => __( 'Protokoll', 'svm' ),
		);
	}

	/**
	 * Export.
	 *
	 * @return void
	 */
	public static function export() {
		$profile_id = SVM_App::id();
		$profile    = $profile_id ? SVM_Export_Profiles::get( $profile_id ) : null;

		SVM_UI::page_head(
			__( 'Daten exportieren', 'svm' ),
			__( 'Listen als Datei herunterladen — für Excel, den Verband oder die eigene Ablage.', 'svm' )
		);

		SVM_App::subnav( self::tabs(), 'export' );

		$entities = SVM_Export_Profiles::entity_types();
		$formats  = SVM_Export_Profiles::formats();
		$rows     = array();

		foreach ( SVM_Export_Profiles::available() as $item ) {
			ob_start();
			echo '<div class="svm-button-row">';
			SVM_UI::action_button( 'download_export', array( 'id' => (int) $item['id'] ), __( 'Herunterladen', 'svm' ), 'primary' );

			if ( SVM_Permissions::current_user_can( 'export_manage' ) ) {
				printf(
					'<a class="svm-btn svm-btn-secondary svm-btn-small" href="%s">%s</a>',
					esc_url( SVM_App::url( 'export', array( 'id' => (int) $item['id'] ) ) ),
					esc_html__( 'Ändern', 'svm' )
				);
			}

			echo '</div>';
			$actions = (string) ob_get_clean();

			$rows[] = array(
				'<span class="svm-strong">' . esc_html( $item['label'] ) . '</span>',
				esc_html( isset( $entities[ $item['entity_type'] ] ) ? $entities[ $item['entity_type'] ] : $item['entity_type'] ),
				esc_html( isset( $formats[ $item['format'] ] ) ? $formats[ $item['format'] ] : $item['format'] ),
				esc_html( number_format_i18n( count( SVM_Export_Profiles::columns( $item ) ) ) ),
				$actions,
			);
		}

		SVM_UI::table(
			array( __( 'Vorlage', 'svm' ), __( 'Inhalt', 'svm' ), __( 'Format', 'svm' ), __( 'Spalten', 'svm' ), '' ),
			$rows,
			__( 'Es steht noch keine Export-Vorlage bereit.', 'svm' )
		);

		if ( ! SVM_Permissions::current_user_can( 'export_manage' ) ) {
			return;
		}

		$entity_type = $profile ? $profile['entity_type'] : 'member';
		$columns     = SVM_Exporter::available_columns( $entity_type );
		$selected    = $profile ? SVM_Export_Profiles::columns( $profile ) : array_keys( $columns );

		$roles = array();

		foreach ( SVM_Roles::all() as $role ) {
			$roles[ (int) $role['id'] ] = $role['label'];
		}

		SVM_UI::card_open(
			$profile ? __( 'Vorlage bearbeiten', 'svm' ) : __( 'Neue Export-Vorlage', 'svm' ),
			__( 'Wählen Sie Inhalt, Format und die Spalten. Nach dem Wechsel des Inhalts einmal speichern, dann erscheinen die passenden Spalten.', 'svm' )
		);

		SVM_UI::form_open( 'save_export_profile', array( 'id' => $profile_id ) );
		SVM_UI::grid_open();
		SVM_UI::field( __( 'Bezeichnung', 'svm' ), SVM_UI::input( 'label', $profile ? $profile['label'] : '', array( 'required' => true ) ) );
		SVM_UI::field( __( 'Inhalt', 'svm' ), SVM_UI::select( 'entity_type', $entity_type, $entities ) );
		SVM_UI::field( __( 'Format', 'svm' ), SVM_UI::select( 'format', $profile ? $profile['format'] : 'xlsx', $formats ) );
		SVM_UI::field( __( 'Trennzeichen', 'svm' ), SVM_UI::input( 'delimiter', $profile ? $profile['delimiter'] : ';' ) );
		SVM_UI::field(
			__( 'Zeichensatz', 'svm' ),
			SVM_UI::select( 'charset', $profile ? $profile['charset'] : 'UTF-8', array( 'UTF-8' => 'UTF-8', 'ISO-8859-1' => 'ISO-8859-1' ) )
		);
		SVM_UI::field(
			__( 'Wer darf herunterladen?', 'svm' ),
			SVM_UI::select(
				'allowed_roles',
				$profile ? (array) json_decode( (string) $profile['allowed_roles'], true ) : array(),
				$roles,
				array( 'multiple' => true, 'size' => 5 )
			),
			__( 'Nichts ausgewählt = alle mit Exportrecht. Wichtig bei Listen mit Bankdaten.', 'svm' )
		);
		SVM_UI::field( '', SVM_UI::checkbox( 'is_active', $profile ? $profile['is_active'] : 1, __( 'Vorlage anbieten', 'svm' ) ) );
		SVM_UI::grid_close();

		echo '<h4 class="svm-section-title">' . esc_html__( 'Welche Spalten sollen enthalten sein?', 'svm' ) . '</h4>';
		echo '<div class="svm-checklist">';

		foreach ( $columns as $key => $label ) {
			printf(
				'<label class="svm-check"><input type="checkbox" name="columns[]" value="%s"%s /> <span>%s</span></label>',
				esc_attr( $key ),
				in_array( $key, $selected, true ) ? ' checked' : '',
				esc_html( $label )
			);
		}

		echo '</div>';

		SVM_UI::form_close( __( 'Vorlage speichern', 'svm' ) );

		if ( $profile ) {
			echo '<div class="svm-button-row">';
			SVM_UI::action_button( 'delete_export_profile', array( 'id' => $profile_id ), __( 'Vorlage löschen', 'svm' ), 'danger' );
			echo '</div>';
		}

		SVM_UI::card_close();
	}

	/**
	 * Import in drei Schritten.
	 *
	 * @return void
	 */
	public static function import() {
		$step   = SVM_App::arg( 'step' );
		$stored = get_transient( 'svm_import_' . get_current_user_id() );

		SVM_UI::page_head(
			__( 'Mitglieder importieren', 'svm' ),
			__( 'Bestehende Mitgliederlisten als CSV einlesen — in drei Schritten und mit Testlauf.', 'svm' )
		);

		SVM_App::subnav( self::tabs(), 'import' );

		$has_data = is_array( $stored ) && ! empty( $stored['header'] );

		echo '<ol class="svm-steps">';
		printf(
			'<li class="svm-step%s"><span>1</span>%s</li>',
			$has_data ? ' is-done' : ' is-active',
			esc_html__( 'Datei hochladen', 'svm' )
		);
		printf(
			'<li class="svm-step%s"><span>2</span>%s</li>',
			$has_data ? ' is-active' : '',
			esc_html__( 'Spalten zuordnen', 'svm' )
		);
		printf( '<li class="svm-step"><span>3</span>%s</li>', esc_html__( 'Testlauf und Übernahme', 'svm' ) );
		echo '</ol>';

		SVM_UI::card_open( __( 'Schritt 1: Datei hochladen', 'svm' ), __( 'Eine CSV-Datei mit Kopfzeile. Die Spaltennamen sind frei — sie werden im nächsten Schritt zugeordnet.', 'svm' ) );
		SVM_UI::form_open( 'read_import', array(), true );
		SVM_UI::grid_open();
		SVM_UI::field( __( 'CSV-Datei', 'svm' ), '<input class="svm-input" type="file" name="import_file" accept=".csv,text/csv" required />' );
		SVM_UI::field( __( 'Trennzeichen', 'svm' ), SVM_UI::input( 'delimiter', ';' ), __( 'In Deutschland meist ein Semikolon.', 'svm' ) );
		SVM_UI::grid_close();
		SVM_UI::form_close( __( 'Datei einlesen', 'svm' ), 'secondary' );
		SVM_UI::card_close();

		if ( ! $has_data ) {
			return;
		}

		unset( $step );

		$suggestion = SVM_Importer::suggest_mapping( $stored['header'] );
		$targets    = SVM_Importer::target_options();

		SVM_UI::card_open(
			__( 'Schritt 2: Spalten zuordnen', 'svm' ),
			sprintf(
				/* translators: %d: Anzahl Zeilen. */
				__( '%d Datenzeilen eingelesen. Ordnen Sie jede Spalte einem Feld zu — nicht benötigte Spalten bleiben auf „nicht importieren“.', 'svm' ),
				count( $stored['rows'] )
			)
		);

		SVM_UI::form_open( 'run_import' );

		$rows = array();

		foreach ( $stored['header'] as $index => $label ) {
			$sample = isset( $stored['rows'][0][ $index ] ) ? $stored['rows'][0][ $index ] : '';

			$rows[] = array(
				'<span class="svm-strong">' . esc_html( $label ) . '</span>',
				'<span class="svm-muted">' . esc_html( $sample ) . '</span>',
				SVM_UI::select(
					'mapping[' . (int) $index . ']',
					isset( $suggestion[ $index ] ) ? $suggestion[ $index ] : '',
					$targets
				),
			);
		}

		SVM_UI::table(
			array( __( 'Spalte in der Datei', 'svm' ), __( 'Beispiel', 'svm' ), __( 'Ziel im System', 'svm' ) ),
			$rows
		);

		echo '<h4 class="svm-section-title">' . esc_html__( 'Schritt 3: Testlauf und Übernahme', 'svm' ) . '</h4>';
		SVM_UI::field(
			'',
			SVM_UI::checkbox( 'update_existing', true, __( 'Bestehende Mitglieder anhand der Mitgliedsnummer aktualisieren', 'svm' ) )
		);

		echo '<p class="svm-form-actions">';
		echo '<button type="submit" name="dry_run" value="yes" class="svm-btn svm-btn-secondary">' .
			esc_html__( 'Testlauf — nichts speichern', 'svm' ) . '</button> ';
		echo '<button type="submit" class="svm-btn svm-btn-primary">' . esc_html__( 'Jetzt importieren', 'svm' ) . '</button>';
		echo '</p>';

		echo '</form>';
		SVM_UI::card_close();
	}

	/**
	 * Datenschutz.
	 *
	 * @return void
	 */
	public static function privacy() {
		$months = (int) get_option( 'svm_retention_months', 120 );
		$due    = SVM_GDPR::due_for_anonymization( $months );

		SVM_UI::page_head(
			__( 'Datenschutz', 'svm' ),
			__( 'Auskunft für einzelne Mitglieder und Löschung nach Ablauf der Aufbewahrungsfrist.', 'svm' )
		);

		SVM_App::subnav( self::tabs(), 'privacy' );

		SVM_UI::card_open(
			__( 'Aufbewahrungsfrist', 'svm' ),
			sprintf(
				/* translators: %d: Monate. */
				__( 'Eingestellt sind %d Monate nach Austritt. Finanzunterlagen unterliegen in der Regel einer zehnjährigen Aufbewahrungspflicht.', 'svm' ),
				$months
			)
		);

		if ( empty( $due ) ) {
			SVM_UI::empty_state( __( 'Derzeit ist für kein Mitglied die Frist abgelaufen.', 'svm' ) );
			SVM_UI::card_close();
			return;
		}

		$rows = array();

		foreach ( $due as $member ) {
			$member_id = (int) $member['id'];
			$actions   = '';

			if ( SVM_Permissions::current_user_can( 'members_delete' ) ) {
				$actions = SVM_View_Members::button_cell(
					'anonymize_member',
					array( 'member_id' => $member_id ),
					__( 'Anonymisieren', 'svm' ),
					__( 'Personenbezogene Daten unwiderruflich entfernen? Die Finanzhistorie bleibt in Summen erhalten.', 'svm' )
				);
			}

			$rows[] = array(
				esc_html( SVM_Members::display_name( $member_id ) ),
				esc_html( SVM_UI::date( $member['left_at'] ) ),
				$actions,
			);
		}

		SVM_UI::table(
			array( __( 'Mitglied', 'svm' ), __( 'Ausgetreten', 'svm' ), '' ),
			$rows
		);

		SVM_UI::card_close();
	}

	/**
	 * Änderungsprotokoll.
	 *
	 * @return void
	 */
	public static function audit() {
		SVM_UI::page_head(
			__( 'Änderungsprotokoll', 'svm' ),
			__( 'Wer hat wann was geändert.', 'svm' )
		);

		SVM_App::subnav( self::tabs(), 'audit' );

		$rows = array();

		foreach ( SVM_Audit::query( array( 'limit' => 150 ) ) as $entry ) {
			$user = get_userdata( (int) $entry['user_id'] );

			$rows[] = array(
				esc_html( $entry['created_at'] ),
				esc_html( $user ? $user->display_name : '—' ),
				esc_html( $entry['entity_type'] . ' #' . $entry['entity_id'] ),
				esc_html( $entry['action'] . ( '' !== $entry['field_key'] ? ' · ' . $entry['field_key'] : '' ) ),
				'<span class="svm-muted">' . esc_html( mb_substr( (string) $entry['old_value'], 0, 40 ) ) . '</span>',
				esc_html( mb_substr( (string) $entry['new_value'], 0, 40 ) ),
			);
		}

		SVM_UI::table(
			array(
				__( 'Zeitpunkt', 'svm' ),
				__( 'Benutzer', 'svm' ),
				__( 'Bereich', 'svm' ),
				__( 'Aktion', 'svm' ),
				__( 'Vorher', 'svm' ),
				__( 'Nachher', 'svm' ),
			),
			$rows,
			__( 'Noch keine Einträge.', 'svm' )
		);
	}
}
