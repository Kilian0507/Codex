<?php
/**
 * Registry der verfügbaren Feldtypen.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Feldtypen bestimmen Eingabe, Validierung und Speicherspalte.
 */
class SVM_Field_Types {

	/**
	 * Zwischenspeicher.
	 *
	 * @var array|null
	 */
	private static $types = null;

	/**
	 * Alle Feldtypen.
	 *
	 * @return array type => array( label, storage, has_options, input )
	 */
	public static function all() {
		if ( null !== self::$types ) {
			return self::$types;
		}

		$types = array(
			'text'        => array( 'label' => __( 'Text', 'svm' ), 'storage' => 'text', 'input' => 'text' ),
			'textarea'    => array( 'label' => __( 'Text mehrzeilig', 'svm' ), 'storage' => 'text', 'input' => 'textarea' ),
			'number'      => array( 'label' => __( 'Zahl', 'svm' ), 'storage' => 'number', 'input' => 'number' ),
			'amount'      => array( 'label' => __( 'Betrag', 'svm' ), 'storage' => 'number', 'input' => 'number' ),
			'date'        => array( 'label' => __( 'Datum', 'svm' ), 'storage' => 'date', 'input' => 'date' ),
			'boolean'     => array( 'label' => __( 'Ja/Nein', 'svm' ), 'storage' => 'number', 'input' => 'checkbox' ),
			'select'      => array( 'label' => __( 'Einfachauswahl', 'svm' ), 'storage' => 'text', 'input' => 'select', 'has_options' => true ),
			'multiselect' => array( 'label' => __( 'Mehrfachauswahl', 'svm' ), 'storage' => 'text', 'input' => 'multiselect', 'has_options' => true ),
			'email'       => array( 'label' => __( 'E-Mail', 'svm' ), 'storage' => 'text', 'input' => 'email' ),
			'phone'       => array( 'label' => __( 'Telefon', 'svm' ), 'storage' => 'text', 'input' => 'tel' ),
			'url'         => array( 'label' => __( 'Internetadresse', 'svm' ), 'storage' => 'text', 'input' => 'url' ),
			'iban'        => array( 'label' => __( 'IBAN', 'svm' ), 'storage' => 'text', 'input' => 'text' ),
			'bic'         => array( 'label' => __( 'BIC', 'svm' ), 'storage' => 'text', 'input' => 'text' ),
			'country'     => array( 'label' => __( 'Land', 'svm' ), 'storage' => 'text', 'input' => 'text' ),
			'member_ref'  => array( 'label' => __( 'Verweis auf Mitglied', 'svm' ), 'storage' => 'number', 'input' => 'member' ),
			'file'        => array( 'label' => __( 'Datei/Anhang', 'svm' ), 'storage' => 'number', 'input' => 'file' ),
			'computed'    => array( 'label' => __( 'Berechnetes Feld (Formel)', 'svm' ), 'storage' => 'none', 'input' => 'none' ),
			'heading'     => array( 'label' => __( 'Überschrift/Trenner', 'svm' ), 'storage' => 'none', 'input' => 'none' ),
		);

		/**
		 * Erlaubt Erweiterungen, eigene Feldtypen zu registrieren.
		 *
		 * @param array $types Feldtypen.
		 */
		self::$types = apply_filters( 'svm_field_types', $types );

		return self::$types;
	}

	/**
	 * Definition eines Feldtyps.
	 *
	 * @param string $type Typschlüssel.
	 * @return array
	 */
	public static function get( $type ) {
		$all = self::all();
		return isset( $all[ $type ] ) ? $all[ $type ] : $all['text'];
	}

	/**
	 * Speicherspalte eines Feldtyps.
	 *
	 * @param string $type Typschlüssel.
	 * @return string text|number|date|none
	 */
	public static function storage( $type ) {
		$def = self::get( $type );
		return isset( $def['storage'] ) ? $def['storage'] : 'text';
	}

	/**
	 * Ob der Feldtyp Auswahloptionen besitzt.
	 *
	 * @param string $type Typschlüssel.
	 * @return bool
	 */
	public static function has_options( $type ) {
		$def = self::get( $type );
		return ! empty( $def['has_options'] );
	}

	/**
	 * Auswahlliste für Formulare.
	 *
	 * @return array
	 */
	public static function choices() {
		$out = array();
		foreach ( self::all() as $key => $def ) {
			$out[ $key ] = $def['label'];
		}
		return $out;
	}
}
