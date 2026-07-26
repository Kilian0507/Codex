<?php
/**
 * Text- und E-Mail-Vorlagen.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Vorlagen arbeiten mit Platzhaltern und sind frei anlegbar.
 */
class SVM_Templates {

	/**
	 * Alle Vorlagen.
	 *
	 * @param string $type Optionaler Typfilter.
	 * @return array
	 */
	public static function all( $type = '' ) {
		global $wpdb;

		if ( '' === $type ) {
			return SVM_DB::all( 'templates', '', 'label ASC' );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'templates' ) . ' WHERE type = %s ORDER BY label ASC',
				$type
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Eine Vorlage.
	 *
	 * @param int $id Vorlagen-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		return SVM_DB::get( 'templates', $id );
	}

	/**
	 * Vorlage anhand des Schlüssels.
	 *
	 * @param string $key Schlüssel.
	 * @return array|null
	 */
	public static function get_by_key( $key ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . SVM_DB::table( 'templates' ) . ' WHERE template_key = %s', $key ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $row ? $row : null;
	}

	/**
	 * Auswahlliste.
	 *
	 * @param string $type Typfilter.
	 * @return array
	 */
	public static function options( $type = '' ) {
		$out = array( 0 => __( '— keine —', 'svm' ) );

		foreach ( self::all( $type ) as $template ) {
			$out[ (int) $template['id'] ] = $template['label'];
		}

		return $out;
	}

	/**
	 * Speichert eine Vorlage.
	 *
	 * @param array $data Daten.
	 * @return int
	 */
	public static function save( array $data ) {
		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		unset( $data['id'] );

		$data = wp_parse_args(
			$data,
			array(
				'template_key' => '',
				'label'        => '',
				'type'         => 'email',
				'subject'      => '',
				'body'         => '',
			)
		);

		if ( '' === $data['template_key'] ) {
			$data['template_key'] = sanitize_key( $data['label'] ) . '_' . wp_rand( 100, 999 );
		}

		if ( $id > 0 ) {
			SVM_DB::update( 'templates', $id, $data );
			return $id;
		}

		return SVM_DB::insert( 'templates', $data );
	}

	/**
	 * Löscht eine Vorlage.
	 *
	 * @param int $id Vorlagen-ID.
	 * @return void
	 */
	public static function delete( $id ) {
		SVM_DB::delete( 'templates', $id );
	}

	/**
	 * Setzt Platzhalter ein.
	 *
	 * @param array $template     Vorlage.
	 * @param array $replacements Platzhalter => Wert.
	 * @return array subject, body
	 */
	public static function render( array $template, array $replacements ) {
		$search  = array_keys( $replacements );
		$replace = array_values( $replacements );

		return array(
			'subject' => str_replace( $search, $replace, (string) $template['subject'] ),
			'body'    => wpautop( str_replace( $search, $replace, (string) $template['body'] ) ),
		);
	}

	/**
	 * Verfügbare Platzhalter.
	 *
	 * @return array
	 */
	public static function placeholders() {
		return array(
			'{name}'         => __( 'Name des Mitglieds', 'svm' ),
			'{betrag}'       => __( 'Offener Betrag', 'svm' ),
			'{beschreibung}' => __( 'Beschreibung der Forderung', 'svm' ),
			'{faelligkeit}'  => __( 'Fälligkeitsdatum', 'svm' ),
			'{mahnstufe}'    => __( 'Bezeichnung der Mahnstufe', 'svm' ),
			'{mahngebuehr}'  => __( 'Mahngebühr', 'svm' ),
			'{verein}'       => __( 'Vereinsname', 'svm' ),
			'{titel}'        => __( 'Titel der Nachricht', 'svm' ),
			'{inhalt}'       => __( 'Inhalt der Nachricht', 'svm' ),
		);
	}
}
