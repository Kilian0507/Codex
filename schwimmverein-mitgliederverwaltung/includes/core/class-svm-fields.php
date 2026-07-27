<?php
/**
 * Frei definierbare Felder: Definitionen, Werte, Sichtbarkeit.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Herzstück der Konfigurierbarkeit — alle fachlichen Stammdaten laufen hierüber.
 */
class SVM_Fields {

	/**
	 * Zwischenspeicher der Definitionen je Entität.
	 *
	 * @var array
	 */
	private static $def_cache = array();

	/* ---------------------------------------------------------------------
	 * Definitionen
	 * ------------------------------------------------------------------ */

	/**
	 * Alle Felddefinitionen einer Entität.
	 *
	 * @param string $entity_type Entitätstyp.
	 * @param bool   $only_active Nur aktive Felder.
	 * @return array
	 */
	public static function defs( $entity_type = 'member', $only_active = true ) {
		$cache_key = $entity_type . ( $only_active ? '_a' : '_all' );

		if ( isset( self::$def_cache[ $cache_key ] ) ) {
			return self::$def_cache[ $cache_key ];
		}

		global $wpdb;
		$sql = 'SELECT * FROM ' . SVM_DB::table( 'field_defs' ) . ' WHERE entity_type = %s';
		if ( $only_active ) {
			$sql .= ' AND is_active = 1';
		}
		$sql .= ' ORDER BY sort_order ASC, id ASC';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $entity_type ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$rows = $rows ? $rows : array();

		self::$def_cache[ $cache_key ] = $rows;

		return $rows;
	}

	/**
	 * Leert den Definitions-Zwischenspeicher.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$def_cache = array();
	}

	/**
	 * Einzelne Definition.
	 *
	 * @param int $id Feld-ID.
	 * @return array|null
	 */
	public static function get_def( $id ) {
		return SVM_DB::get( 'field_defs', $id );
	}

	/**
	 * Definition anhand des Schlüssels.
	 *
	 * @param string $key         Feldschlüssel.
	 * @param string $entity_type Entitätstyp.
	 * @return array|null
	 */
	public static function get_def_by_key( $key, $entity_type = 'member' ) {
		foreach ( self::defs( $entity_type, false ) as $def ) {
			if ( $def['field_key'] === $key ) {
				return $def;
			}
		}
		return null;
	}

	/**
	 * Definition anhand der Systemrolle (z. B. display_name, birthdate, iban).
	 *
	 * @param string $role        Systemrolle.
	 * @param string $entity_type Entitätstyp.
	 * @return array|null
	 */
	public static function get_def_by_system_role( $role, $entity_type = 'member' ) {
		foreach ( self::defs( $entity_type, false ) as $def ) {
			if ( $def['system_role'] === $role ) {
				return $def;
			}
		}
		return null;
	}

	/**
	 * Systemrollen, die Felder übernehmen können.
	 *
	 * @return array
	 */
	public static function system_roles() {
		return array(
			''            => __( '— keine —', 'svm' ),
			'first_name'  => __( 'Vorname', 'svm' ),
			'last_name'   => __( 'Nachname', 'svm' ),
			'display_name' => __( 'Anzeigename', 'svm' ),
			'birthdate'   => __( 'Geburtsdatum (für Altersregeln)', 'svm' ),
			'email'       => __( 'E-Mail (für Benachrichtigungen)', 'svm' ),
			'iban'        => __( 'IBAN (für SEPA)', 'svm' ),
			'bic'         => __( 'BIC', 'svm' ),
			'account_holder' => __( 'Kontoinhaber', 'svm' ),
			'street'      => __( 'Straße', 'svm' ),
			'postal_code' => __( 'Postleitzahl', 'svm' ),
			'city'        => __( 'Ort', 'svm' ),
		);
	}

	/**
	 * Speichert eine Felddefinition.
	 *
	 * @param array $data Felddaten.
	 * @return int Feld-ID.
	 */
	public static function save_def( array $data ) {
		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		unset( $data['id'] );

		$data = wp_parse_args(
			$data,
			array(
				'entity_type'      => 'member',
				'field_key'        => '',
				'label'            => '',
				'field_type'       => 'text',
				'help_text'        => '',
				'section'          => '',
				'is_required'      => 0,
				'validation_regex' => '',
				'min_value'        => '',
				'max_value'        => '',
				'default_value'    => '',
				'formula'          => '',
				'system_role'      => '',
				'unit_scope'       => '',
				'is_sensitive'     => 0,
				'in_gdpr_export'   => 1,
				'show_in_list'     => 0,
				'retention_months' => 0,
				'sort_order'       => 0,
				'is_active'        => 1,
			)
		);

		if ( '' === $data['field_key'] ) {
			$data['field_key'] = self::unique_key( sanitize_key( $data['label'] ), $data['entity_type'], $id );
		}

		if ( is_array( $data['unit_scope'] ) ) {
			$data['unit_scope'] = wp_json_encode( array_map( 'intval', $data['unit_scope'] ) );
		}

		if ( $id > 0 ) {
			SVM_DB::update( 'field_defs', $id, $data );
		} else {
			$id = SVM_DB::insert( 'field_defs', $data );
		}

		self::flush_cache();

		return $id;
	}

	/**
	 * Erzeugt einen eindeutigen Feldschlüssel.
	 *
	 * @param string $base        Ausgangsschlüssel.
	 * @param string $entity_type Entitätstyp.
	 * @param int    $ignore_id   Zu ignorierende Feld-ID.
	 * @return string
	 */
	private static function unique_key( $base, $entity_type, $ignore_id = 0 ) {
		$base = '' !== $base ? $base : 'feld';
		$key  = $base;
		$i    = 2;

		while ( true ) {
			$existing = self::get_def_by_key( $key, $entity_type );
			if ( ! $existing || (int) $existing['id'] === (int) $ignore_id ) {
				return $key;
			}
			$key = $base . '_' . $i;
			$i++;
		}
	}

	/**
	 * Löscht eine Felddefinition samt Werten.
	 *
	 * @param int $id Feld-ID.
	 * @return void
	 */
	public static function delete_def( $id ) {
		global $wpdb;

		$id = (int) $id;
		$wpdb->delete( SVM_DB::table( 'field_values' ), array( 'field_id' => $id ) ); // phpcs:ignore WordPress.DB
		$wpdb->delete( SVM_DB::table( 'field_options' ), array( 'field_id' => $id ) ); // phpcs:ignore WordPress.DB
		$wpdb->delete( SVM_DB::table( 'field_visibility' ), array( 'field_id' => $id ) ); // phpcs:ignore WordPress.DB
		SVM_DB::delete( 'field_defs', $id );

		self::flush_cache();
	}

	/* ---------------------------------------------------------------------
	 * Auswahloptionen
	 * ------------------------------------------------------------------ */

	/**
	 * Optionen eines Auswahlfeldes.
	 *
	 * @param int $field_id Feld-ID.
	 * @return array
	 */
	public static function options( $field_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'field_options' ) . ' WHERE field_id = %d ORDER BY sort_order ASC, id ASC',
				(int) $field_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB
		return $rows ? $rows : array();
	}

	/**
	 * Optionen als Wert => Label.
	 *
	 * @param int $field_id Feld-ID.
	 * @return array
	 */
	public static function option_map( $field_id ) {
		$map = array();
		foreach ( self::options( $field_id ) as $option ) {
			$map[ $option['option_value'] ] = $option['option_label'];
		}
		return $map;
	}

	/**
	 * Ersetzt die Optionen eines Feldes.
	 *
	 * @param int   $field_id Feld-ID.
	 * @param array $options  Liste aus value|label-Zeilen oder Strings.
	 * @return void
	 */
	public static function set_options( $field_id, array $options ) {
		global $wpdb;

		$field_id = (int) $field_id;
		$wpdb->delete( SVM_DB::table( 'field_options' ), array( 'field_id' => $field_id ) ); // phpcs:ignore WordPress.DB

		$sort = 0;
		foreach ( $options as $option ) {
			if ( is_string( $option ) ) {
				$option = trim( $option );
				if ( '' === $option ) {
					continue;
				}
				$parts = array_map( 'trim', explode( '|', $option ) );
				$value = $parts[0];
				$label = isset( $parts[1] ) ? $parts[1] : $parts[0];
			} else {
				$value = isset( $option['value'] ) ? $option['value'] : '';
				$label = isset( $option['label'] ) ? $option['label'] : $value;
			}

			if ( '' === $value ) {
				continue;
			}

			SVM_DB::insert(
				'field_options',
				array(
					'field_id'     => $field_id,
					'option_value' => $value,
					'option_label' => $label,
					'sort_order'   => $sort,
				)
			);
			$sort++;
		}
	}

	/* ---------------------------------------------------------------------
	 * Werte
	 * ------------------------------------------------------------------ */

	/**
	 * Alle Feldwerte einer Entität als field_id => Rohwert.
	 *
	 * @param string $entity_type Entitätstyp.
	 * @param int    $entity_id   Datensatz-ID.
	 * @return array
	 */
	public static function values( $entity_type, $entity_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT field_id, value_text, value_number, value_date FROM ' . SVM_DB::table( 'field_values' ) . '
				WHERE entity_type = %s AND entity_id = %d',
				$entity_type,
				(int) $entity_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['field_id'] ] = $row['value_text'];
		}

		return $out;
	}

	/**
	 * Feldwerte als Schlüssel => Rohwert.
	 *
	 * @param string $entity_type Entitätstyp.
	 * @param int    $entity_id   Datensatz-ID.
	 * @return array
	 */
	public static function values_by_key( $entity_type, $entity_id ) {
		$values = self::values( $entity_type, $entity_id );
		$out    = array();

		foreach ( self::defs( $entity_type, false ) as $def ) {
			$id                       = (int) $def['id'];
			$out[ $def['field_key'] ] = isset( $values[ $id ] ) ? $values[ $id ] : null;
		}

		return $out;
	}

	/**
	 * Einzelner Feldwert anhand des Schlüssels.
	 *
	 * @param string $entity_type Entitätstyp.
	 * @param int    $entity_id   Datensatz-ID.
	 * @param string $field_key   Feldschlüssel.
	 * @return mixed|null
	 */
	public static function get_value( $entity_type, $entity_id, $field_key ) {
		$def = self::get_def_by_key( $field_key, $entity_type );
		if ( ! $def ) {
			return null;
		}
		return self::get_value_by_id( $entity_type, $entity_id, (int) $def['id'] );
	}

	/**
	 * Einzelner Feldwert anhand der Feld-ID.
	 *
	 * @param string $entity_type Entitätstyp.
	 * @param int    $entity_id   Datensatz-ID.
	 * @param int    $field_id    Feld-ID.
	 * @return mixed|null
	 */
	public static function get_value_by_id( $entity_type, $entity_id, $field_id ) {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT value_text FROM ' . SVM_DB::table( 'field_values' ) . '
				WHERE entity_type = %s AND entity_id = %d AND field_id = %d',
				$entity_type,
				(int) $entity_id,
				(int) $field_id
			)
		); // phpcs:ignore WordPress.DB

		return $value;
	}

	/**
	 * Wert anhand der Systemrolle.
	 *
	 * @param string $entity_type Entitätstyp.
	 * @param int    $entity_id   Datensatz-ID.
	 * @param string $role        Systemrolle.
	 * @return mixed|null
	 */
	public static function get_system_value( $entity_type, $entity_id, $role ) {
		$def = self::get_def_by_system_role( $role, $entity_type );
		if ( ! $def ) {
			return null;
		}
		return self::get_value_by_id( $entity_type, $entity_id, (int) $def['id'] );
	}

	/**
	 * Setzt einen Feldwert.
	 *
	 * @param string $entity_type Entitätstyp.
	 * @param int    $entity_id   Datensatz-ID.
	 * @param int    $field_id    Feld-ID.
	 * @param mixed  $value       Wert.
	 * @return void
	 */
	public static function set_value( $entity_type, $entity_id, $field_id, $value ) {
		global $wpdb;

		$def = self::get_def( $field_id );
		if ( ! $def ) {
			return;
		}

		$storage = SVM_Field_Types::storage( $def['field_type'] );
		if ( 'none' === $storage ) {
			return;
		}

		if ( is_array( $value ) ) {
			$value = wp_json_encode( array_values( $value ) );
		}

		$data = array(
			'entity_type'  => $entity_type,
			'entity_id'    => (int) $entity_id,
			'field_id'     => (int) $field_id,
			'value_text'   => ( null === $value || '' === $value ) ? null : (string) $value,
			'value_number' => null,
			'value_date'   => null,
		);

		if ( 'number' === $storage && null !== $data['value_text'] ) {
			$data['value_number'] = self::to_number( $data['value_text'] );
		}

		if ( 'date' === $storage && null !== $data['value_text'] ) {
			$timestamp = strtotime( $data['value_text'] );
			if ( $timestamp ) {
				$data['value_date'] = gmdate( 'Y-m-d H:i:s', $timestamp );
				$data['value_text'] = gmdate( 'Y-m-d', $timestamp );
			}
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . SVM_DB::table( 'field_values' ) . '
				WHERE entity_type = %s AND entity_id = %d AND field_id = %d',
				$entity_type,
				(int) $entity_id,
				(int) $field_id
			)
		); // phpcs:ignore WordPress.DB

		if ( $existing ) {
			SVM_DB::update( 'field_values', (int) $existing, $data );
		} else {
			SVM_DB::insert( 'field_values', $data );
		}
	}

	/**
	 * Speichert mehrere Werte (field_id => Wert) und protokolliert Änderungen.
	 *
	 * @param string $entity_type Entitätstyp.
	 * @param int    $entity_id   Datensatz-ID.
	 * @param array  $values      Werte.
	 * @param bool   $audit       Änderungsprotokoll schreiben.
	 * @return void
	 */
	public static function save_values( $entity_type, $entity_id, array $values, $audit = true ) {
		$before = $audit ? self::values( $entity_type, $entity_id ) : array();

		foreach ( $values as $field_id => $value ) {
			self::set_value( $entity_type, $entity_id, (int) $field_id, $value );
		}

		if ( ! $audit ) {
			return;
		}

		foreach ( $values as $field_id => $value ) {
			$field_id = (int) $field_id;
			$old      = isset( $before[ $field_id ] ) ? $before[ $field_id ] : null;
			$new      = is_array( $value ) ? wp_json_encode( array_values( $value ) ) : $value;

			if ( (string) $old === (string) $new ) {
				continue;
			}

			$def = self::get_def( $field_id );
			SVM_Audit::log(
				$entity_type,
				$entity_id,
				'field_changed',
				$def ? $def['field_key'] : (string) $field_id,
				$old,
				$new
			);
		}
	}

	/**
	 * Löscht alle Werte einer Entität.
	 *
	 * @param string $entity_type Entitätstyp.
	 * @param int    $entity_id   Datensatz-ID.
	 * @return void
	 */
	public static function delete_values( $entity_type, $entity_id ) {
		global $wpdb;
		$wpdb->delete(
			SVM_DB::table( 'field_values' ),
			array(
				'entity_type' => $entity_type,
				'entity_id'   => (int) $entity_id,
			)
		); // phpcs:ignore WordPress.DB
	}

	/* ---------------------------------------------------------------------
	 * Validierung und Darstellung
	 * ------------------------------------------------------------------ */

	/**
	 * Wandelt eine Eingabe in eine Zahl (akzeptiert Komma als Dezimaltrenner).
	 *
	 * @param string $value Eingabe.
	 * @return float
	 */
	public static function to_number( $value ) {
		$value = str_replace( array( ' ', "\xc2\xa0" ), '', (string) $value );
		$value = str_replace( ',', '.', $value );
		return (float) $value;
	}

	/**
	 * Validiert einen Wert gegen seine Definition.
	 *
	 * @param array $def   Felddefinition.
	 * @param mixed $value Wert.
	 * @return true|WP_Error
	 */
	public static function validate( array $def, $value ) {
		$label  = $def['label'];
		$is_set = ! ( null === $value || '' === $value || array() === $value );

		if ( $def['is_required'] && ! $is_set ) {
			/* translators: %s: Feldbezeichnung. */
			return new WP_Error( 'svm_field_required', sprintf( __( '„%s“ ist ein Pflichtfeld.', 'svm' ), $label ) );
		}

		if ( ! $is_set ) {
			return true;
		}

		switch ( $def['field_type'] ) {
			case 'email':
				if ( ! is_email( $value ) ) {
					/* translators: %s: Feldbezeichnung. */
					return new WP_Error( 'svm_field_email', sprintf( __( '„%s“ enthält keine gültige E-Mail-Adresse.', 'svm' ), $label ) );
				}
				break;

			case 'url':
				if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
					/* translators: %s: Feldbezeichnung. */
					return new WP_Error( 'svm_field_url', sprintf( __( '„%s“ enthält keine gültige Internetadresse.', 'svm' ), $label ) );
				}
				break;

			case 'iban':
				if ( ! SVM_IBAN::is_valid( $value ) ) {
					/* translators: %s: Feldbezeichnung. */
					return new WP_Error( 'svm_field_iban', sprintf( __( '„%s“ enthält keine gültige IBAN (Prüfziffer stimmt nicht).', 'svm' ), $label ) );
				}
				break;

			case 'number':
			case 'amount':
				$number = self::to_number( $value );
				if ( '' !== $def['min_value'] && $number < (float) $def['min_value'] ) {
					/* translators: 1: Feldbezeichnung, 2: Mindestwert. */
					return new WP_Error( 'svm_field_min', sprintf( __( '„%1$s“ darf nicht kleiner als %2$s sein.', 'svm' ), $label, $def['min_value'] ) );
				}
				if ( '' !== $def['max_value'] && $number > (float) $def['max_value'] ) {
					/* translators: 1: Feldbezeichnung, 2: Höchstwert. */
					return new WP_Error( 'svm_field_max', sprintf( __( '„%1$s“ darf nicht größer als %2$s sein.', 'svm' ), $label, $def['max_value'] ) );
				}
				break;

			case 'date':
				if ( ! strtotime( (string) $value ) ) {
					/* translators: %s: Feldbezeichnung. */
					return new WP_Error( 'svm_field_date', sprintf( __( '„%s“ enthält kein gültiges Datum.', 'svm' ), $label ) );
				}
				break;
		}

		if ( '' !== $def['validation_regex'] && is_string( $value ) ) {
			$pattern = '/' . str_replace( '/', '\/', $def['validation_regex'] ) . '/u';
			if ( ! @preg_match( $pattern, $value ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return true; // Ungültiges Muster darf die Eingabe nicht blockieren.
			}
			if ( ! preg_match( $pattern, $value ) ) {
				/* translators: %s: Feldbezeichnung. */
				return new WP_Error( 'svm_field_pattern', sprintf( __( '„%s“ entspricht nicht dem erwarteten Format.', 'svm' ), $label ) );
			}
		}

		return true;
	}

	/**
	 * Bereitet einen Wert für die Anzeige auf.
	 *
	 * @param array $def   Felddefinition.
	 * @param mixed $value Rohwert.
	 * @return string
	 */
	public static function format_value( array $def, $value ) {
		if ( null === $value || '' === $value ) {
			return '';
		}

		switch ( $def['field_type'] ) {
			case 'boolean':
				return $value ? __( 'Ja', 'svm' ) : __( 'Nein', 'svm' );

			case 'date':
				$timestamp = strtotime( (string) $value );
				return $timestamp ? date_i18n( get_option( 'date_format' ), $timestamp ) : (string) $value;

			case 'amount':
				return number_format_i18n( self::to_number( $value ), 2 ) . ' €';

			case 'select':
				$map = self::option_map( (int) $def['id'] );
				return isset( $map[ $value ] ) ? $map[ $value ] : (string) $value;

			case 'multiselect':
				$map      = self::option_map( (int) $def['id'] );
				$selected = json_decode( (string) $value, true );
				if ( ! is_array( $selected ) ) {
					$selected = array( $value );
				}
				$labels = array();
				foreach ( $selected as $item ) {
					$labels[] = isset( $map[ $item ] ) ? $map[ $item ] : $item;
				}
				return implode( ', ', $labels );

			case 'iban':
				return SVM_IBAN::format( (string) $value );

			case 'member_ref':
				$member = SVM_Members::get( (int) $value );
				return $member ? SVM_Members::display_name( (int) $value ) : (string) $value;

			case 'file':
				$url = wp_get_attachment_url( (int) $value );
				return $url ? $url : '';
		}

		return (string) $value;
	}

	/* ---------------------------------------------------------------------
	 * Sichtbarkeit und Bearbeitbarkeit
	 * ------------------------------------------------------------------ */

	/**
	 * Sichtbarkeitsregeln eines Feldes je Rolle.
	 *
	 * @param int $field_id Feld-ID.
	 * @return array role_id => array
	 */
	public static function visibility( $field_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'field_visibility' ) . ' WHERE field_id = %d',
				(int) $field_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['role_id'] ] = $row;
		}

		return $out;
	}

	/**
	 * Setzt die Sichtbarkeit eines Feldes für eine Rolle.
	 *
	 * @param int   $field_id Feld-ID.
	 * @param int   $role_id  Rollen-ID.
	 * @param array $rules    can_view, can_edit, requires_approval.
	 * @return void
	 */
	public static function set_visibility( $field_id, $role_id, array $rules ) {
		global $wpdb;

		$data = array(
			'field_id'          => (int) $field_id,
			'role_id'           => (int) $role_id,
			'can_view'          => empty( $rules['can_view'] ) ? 0 : 1,
			'can_edit'          => empty( $rules['can_edit'] ) ? 0 : 1,
			'requires_approval' => empty( $rules['requires_approval'] ) ? 0 : 1,
		);

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . SVM_DB::table( 'field_visibility' ) . ' WHERE field_id = %d AND role_id = %d',
				(int) $field_id,
				(int) $role_id
			)
		); // phpcs:ignore WordPress.DB

		if ( $existing ) {
			SVM_DB::update( 'field_visibility', (int) $existing, $data );
		} else {
			SVM_DB::insert( 'field_visibility', $data );
		}
	}

	/**
	 * Ermittelt die effektiven Feldrechte eines Benutzers.
	 *
	 * @param array $def     Felddefinition.
	 * @param int   $user_id Benutzer-ID.
	 * @return array can_view, can_edit, requires_approval
	 */
	public static function user_field_rules( array $def, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( user_can( $user_id, 'manage_options' ) ) {
			return array(
				'can_view'          => true,
				'can_edit'          => true,
				'requires_approval' => false,
			);
		}

		$role_ids = SVM_Roles::user_role_ids( $user_id );
		$rules    = self::visibility( (int) $def['id'] );

		// Vorbelegung, solange für keine Rolle etwas Explizites hinterlegt ist.
		$result = array(
			'can_view'          => ! $def['is_sensitive'],
			'can_edit'          => false,
			'requires_approval' => false,
		);

		$explicit = false;

		foreach ( $role_ids as $role_id ) {
			if ( ! isset( $rules[ $role_id ] ) ) {
				continue;
			}

			$explicit = true;
			$rule     = $rules[ $role_id ];

			if ( $rule['can_view'] ) {
				$result['can_view'] = true;
			}
			if ( $rule['can_edit'] ) {
				$result['can_edit'] = true;
				if ( $rule['requires_approval'] ) {
					$result['requires_approval'] = true;
				}
			}
		}

		if ( ! $explicit && SVM_Permissions::user_can( $user_id, 'members_edit' ) ) {
			$result['can_edit'] = ! $def['is_sensitive'];
		}

		if ( $def['is_sensitive'] && ! $explicit && SVM_Permissions::user_can( $user_id, 'bank_data_view' ) ) {
			$result['can_view'] = true;
		}

		return $result;
	}

	/**
	 * Felder, die ein Benutzer sehen darf.
	 *
	 * @param string $entity_type Entitätstyp.
	 * @param int    $user_id     Benutzer-ID.
	 * @return array
	 */
	public static function viewable_defs( $entity_type = 'member', $user_id = 0 ) {
		$out = array();

		foreach ( self::defs( $entity_type ) as $def ) {
			$rules = self::user_field_rules( $def, $user_id );
			if ( $rules['can_view'] ) {
				$def['_rules'] = $rules;
				$out[]         = $def;
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Eingabeverarbeitung
	 * ------------------------------------------------------------------ */

	/**
	 * Wert eines berechneten Feldes.
	 *
	 * @param array  $def         Felddefinition.
	 * @param string $entity_type Entitätstyp.
	 * @param int    $entity_id   Datensatz-ID.
	 * @return string
	 */
	public static function computed_value( array $def, $entity_type, $entity_id ) {
		if ( ! $entity_id || '' === (string) $def['formula'] ) {
			return '';
		}

		$vars = self::values_by_key( $entity_type, $entity_id );

		if ( 'member' === $entity_type ) {
			$age                    = SVM_Members::age( $entity_id );
			$vars['alter']          = null === $age ? 0 : $age;
			$vars['age']            = $vars['alter'];
			$vars['anzahl_sparten'] = count( SVM_Members::unit_ids( $entity_id ) );
		}

		$result = SVM_Formula::evaluate( $def['formula'], $vars );

		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		return number_format_i18n( (float) $result, 2 );
	}

	/**
	 * Verarbeitet abgeschickte Feldwerte.
	 *
	 * Felder mit Freigabepflicht landen im Änderungsantrag statt direkt im Datensatz.
	 *
	 * @param string $entity_type Entitätstyp.
	 * @param int    $entity_id   Datensatz-ID.
	 * @param array  $input       Rohdaten aus dem Formular.
	 * @return array saved, pending, errors
	 */
	public static function collect_input( $entity_type, $entity_id, array $input ) {
		$result = array(
			'saved'   => array(),
			'pending' => array(),
			'errors'  => array(),
		);

		foreach ( self::defs( $entity_type ) as $def ) {
			$field_id = (int) $def['id'];
			$storage  = SVM_Field_Types::storage( $def['field_type'] );

			if ( 'none' === $storage ) {
				continue;
			}

			$rules = self::user_field_rules( $def );

			if ( empty( $rules['can_edit'] ) ) {
				continue;
			}

			if ( 'boolean' === $def['field_type'] ) {
				$value = isset( $input[ $field_id ] ) ? 1 : 0;
			} elseif ( ! array_key_exists( $field_id, $input ) ) {
				continue;
			} else {
				$value = $input[ $field_id ];
			}

			if ( is_array( $value ) ) {
				$value = array_map( 'sanitize_text_field', $value );
			} else {
				$value = 'textarea' === $def['field_type']
					? sanitize_textarea_field( $value )
					: sanitize_text_field( $value );
			}

			if ( 'iban' === $def['field_type'] ) {
				$value = SVM_IBAN::normalize( $value );
			}

			$check = self::validate( $def, $value );

			if ( is_wp_error( $check ) ) {
				$result['errors'][] = $check->get_error_message();
				continue;
			}

			if ( ! empty( $rules['requires_approval'] ) && $entity_id ) {
				$current = self::get_value_by_id( $entity_type, $entity_id, $field_id );
				$new     = is_array( $value ) ? wp_json_encode( array_values( $value ) ) : $value;

				if ( (string) $current !== (string) $new ) {
					$result['pending'][ $field_id ] = $value;
					continue;
				}
			}

			$result['saved'][ $field_id ] = $value;
		}

		return $result;
	}
}
