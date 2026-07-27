<?php
/**
 * Zahldatei-Profile.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Format, Gläubigerdaten, Fristen und Verwendungszweck sind je Profil konfigurierbar.
 */
class SVM_File_Profiles {

	/**
	 * Alle Profile.
	 *
	 * @param bool $only_active Nur aktive.
	 * @return array
	 */
	public static function all( $only_active = false ) {
		$where = $only_active ? 'is_active = 1' : '';
		return SVM_DB::all( 'file_profiles', $where, 'id ASC' );
	}

	/**
	 * Ein Profil.
	 *
	 * @param int $id Profil-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		return SVM_DB::get( 'file_profiles', $id );
	}

	/**
	 * Auswahlliste.
	 *
	 * @return array
	 */
	public static function options() {
		$out = array();
		foreach ( self::all( true ) as $profile ) {
			$out[ (int) $profile['id'] ] = $profile['label'];
		}
		return $out;
	}

	/**
	 * Verfügbare Formate.
	 *
	 * @return array
	 */
	public static function formats() {
		return apply_filters(
			'svm_file_formats',
			array(
				'pain.008.001.02' => __( 'SEPA-Lastschrift XML (pain.008.001.02)', 'svm' ),
				'pain.008.001.08' => __( 'SEPA-Lastschrift XML (pain.008.001.08)', 'svm' ),
				'pain.001.001.03' => __( 'SEPA-Überweisung XML (pain.001.001.03)', 'svm' ),
				'pain.001.001.09' => __( 'SEPA-Überweisung XML (pain.001.001.09)', 'svm' ),
				'csv'             => __( 'CSV nach eigenem Bankformat', 'svm' ),
			)
		);
	}

	/**
	 * Richtung eines Formats.
	 *
	 * @param string $format Format.
	 * @return string debit|credit
	 */
	public static function direction( $format ) {
		return ( 0 === strpos( $format, 'pain.001' ) ) ? 'credit' : 'debit';
	}

	/**
	 * Speichert ein Profil.
	 *
	 * @param array $data Daten.
	 * @return int|WP_Error
	 */
	public static function save( array $data ) {
		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		unset( $data['id'] );

		$data = wp_parse_args(
			$data,
			array(
				'label'            => '',
				'format'           => 'pain.008.001.02',
				'creditor_id'      => '',
				'creditor_name'    => '',
				'creditor_iban'    => '',
				'creditor_bic'     => '',
				'purpose_template' => '',
				'batch_booking'    => 1,
				'lead_days_frst'   => 5,
				'lead_days_rcur'   => 2,
				'csv_mapping'      => '',
				'csv_delimiter'    => ';',
				'is_active'        => 1,
			)
		);

		$data['creditor_iban'] = SVM_IBAN::normalize( $data['creditor_iban'] );

		if ( '' !== $data['creditor_iban'] && ! SVM_IBAN::is_valid( $data['creditor_iban'] ) ) {
			return new WP_Error( 'svm_profile_iban', __( 'Die IBAN des Vereinskontos ist ungültig.', 'svm' ) );
		}

		if ( is_array( $data['csv_mapping'] ) ) {
			$data['csv_mapping'] = wp_json_encode( $data['csv_mapping'] );
		}

		if ( $id > 0 ) {
			SVM_DB::update( 'file_profiles', $id, $data );
			return $id;
		}

		$new_id = SVM_DB::insert( 'file_profiles', $data );

		// Kein Erfolg melden, wenn nichts gespeichert wurde.
		if ( $new_id <= 0 ) {
			return new WP_Error(
				'svm_profile_not_saved',
				__( 'Das Bankprofil konnte nicht gespeichert werden. Vermutlich fehlt die zugehörige Datenbanktabelle — bitte unter „Einstellungen → Verein“ die Datenbank prüfen lassen.', 'svm' )
			);
		}

		return $new_id;
	}

	/**
	 * Löscht ein Profil.
	 *
	 * @param int $id Profil-ID.
	 * @return void
	 */
	public static function delete( $id ) {
		SVM_DB::delete( 'file_profiles', $id );
	}

	/**
	 * Platzhalter für den Verwendungszweck.
	 *
	 * @return array
	 */
	public static function purpose_placeholders() {
		return array(
			'{mitgliedsnummer}' => __( 'Mitgliedsnummer', 'svm' ),
			'{name}'            => __( 'Name des Mitglieds', 'svm' ),
			'{zeitraum}'        => __( 'Abrechnungszeitraum', 'svm' ),
			'{beschreibung}'    => __( 'Beschreibung der Forderung', 'svm' ),
			'{betrag}'          => __( 'Betrag', 'svm' ),
			'{jahr}'            => __( 'Jahr', 'svm' ),
			'{verein}'          => __( 'Vereinsname', 'svm' ),
		);
	}

	/**
	 * Verwendungszweck für einen Jahreseinzug, der mehrere Forderungen bündelt.
	 *
	 * @param array $profile   Profil.
	 * @param int   $member_id Mitglieds-ID.
	 * @param int   $year      Jahr.
	 * @param float $amount    Gesamtbetrag.
	 * @return string
	 */
	public static function render_annual_purpose( array $profile, $member_id, $year, $amount ) {
		$template = '' !== $profile['purpose_template']
			? $profile['purpose_template']
			: '{beschreibung} {mitgliedsnummer}';

		$member = SVM_Members::get( $member_id );

		$replacements = array(
			'{mitgliedsnummer}' => $member ? $member['member_number'] : '',
			'{name}'            => SVM_Members::display_name( $member_id ),
			'{zeitraum}'        => '01.01.' . $year . '-31.12.' . $year,
			/* translators: %d: Jahr. */
			'{beschreibung}'    => sprintf( __( 'Jahresbeitrag %d', 'svm' ), $year ),
			'{betrag}'          => number_format( (float) $amount, 2, ',', '' ),
			'{jahr}'            => (string) $year,
			'{verein}'          => get_option( 'svm_club_name', get_bloginfo( 'name' ) ),
		);

		return trim( str_replace( array_keys( $replacements ), array_values( $replacements ), $template ) );
	}

	/**
	 * Löst den Verwendungszweck für eine Forderung auf.
	 *
	 * @param array $profile Profil.
	 * @param array $invoice Forderung.
	 * @return string
	 */
	public static function render_purpose( array $profile, array $invoice ) {
		$template = '' !== $profile['purpose_template']
			? $profile['purpose_template']
			: '{beschreibung} {mitgliedsnummer}';

		$member = SVM_Members::get( (int) $invoice['member_id'] );

		$period = '';
		if ( ! empty( $invoice['period_start'] ) && ! empty( $invoice['period_end'] ) ) {
			$period = date_i18n( 'd.m.Y', strtotime( $invoice['period_start'] ) ) . '-' .
				date_i18n( 'd.m.Y', strtotime( $invoice['period_end'] ) );
		}

		$replacements = array(
			'{mitgliedsnummer}' => $member ? $member['member_number'] : '',
			'{name}'            => SVM_Members::display_name( (int) $invoice['member_id'] ),
			'{zeitraum}'        => $period,
			'{beschreibung}'    => $invoice['description'],
			'{betrag}'          => number_format( (float) $invoice['amount'], 2, ',', '' ),
			'{jahr}'            => ! empty( $invoice['period_start'] ) ? gmdate( 'Y', strtotime( $invoice['period_start'] ) ) : gmdate( 'Y' ),
			'{verein}'          => get_option( 'svm_club_name', get_bloginfo( 'name' ) ),
		);

		return trim( str_replace( array_keys( $replacements ), array_values( $replacements ), $template ) );
	}
}
