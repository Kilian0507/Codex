<?php
/**
 * Datenimport mit frei konfigurierbarem Spalten-Mapping.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Damit Altbestände ins System kommen, ohne dass Feldnamen vorgegeben sind.
 */
class SVM_Importer {

	/**
	 * Liest eine CSV-Datei ein.
	 *
	 * @param string $content   Dateiinhalt.
	 * @param string $delimiter Trennzeichen.
	 * @return array header, rows
	 */
	public static function parse_csv( $content, $delimiter = ';' ) {
		$content = self::normalize_encoding( $content );
		$handle  = fopen( 'php://temp', 'r+' );

		fwrite( $handle, $content );
		rewind( $handle );

		$header = array();
		$rows   = array();

		while ( false !== ( $data = fgetcsv( $handle, 0, $delimiter ) ) ) {
			if ( array( null ) === $data ) {
				continue;
			}

			if ( empty( $header ) ) {
				$header = array_map( 'trim', $data );
				continue;
			}

			$rows[] = $data;
		}

		fclose( $handle );

		return array(
			'header' => $header,
			'rows'   => $rows,
		);
	}

	/**
	 * Stellt sicher, dass der Inhalt UTF-8 ist.
	 *
	 * @param string $content Inhalt.
	 * @return string
	 */
	private static function normalize_encoding( $content ) {
		// BOM entfernen.
		$content = preg_replace( '/^\xEF\xBB\xBF/', '', $content );

		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $content, 'UTF-8' ) ) {
			$content = mb_convert_encoding( $content, 'UTF-8', 'ISO-8859-1' );
		}

		return $content;
	}

	/**
	 * Mögliche Importziele.
	 *
	 * @return array key => Label
	 */
	public static function target_options() {
		$targets = array(
			''                   => __( '— nicht importieren —', 'svm' ),
			'core:member_number' => __( 'Mitgliedsnummer', 'svm' ),
			'core:status'        => __( 'Status (Schlüssel oder Bezeichnung)', 'svm' ),
			'core:joined_at'     => __( 'Eintrittsdatum', 'svm' ),
			'core:left_at'       => __( 'Austrittsdatum', 'svm' ),
			'core:units'         => __( 'Sparten/Gruppen (kommagetrennt)', 'svm' ),
			'core:payment_method' => __( 'Zahlart (Bezeichnung)', 'svm' ),
			'core:iban'          => __( 'IBAN für SEPA-Mandat', 'svm' ),
			'core:mandate_ref'   => __( 'Mandatsreferenz', 'svm' ),
			'core:mandate_date'  => __( 'Datum der Mandatsunterschrift', 'svm' ),
			'core:family'        => __( 'Familie (Name)', 'svm' ),
			'core:relation'      => __( 'Rolle in der Familie', 'svm' ),
		);

		foreach ( SVM_Fields::defs( 'member' ) as $def ) {
			if ( 'heading' === $def['field_type'] || 'computed' === $def['field_type'] ) {
				continue;
			}

			$targets[ 'field:' . $def['id'] ] = $def['label'];
		}

		return $targets;
	}

	/**
	 * Schlägt anhand der Spaltenüberschriften ein Mapping vor.
	 *
	 * @param array $header Spaltenüberschriften.
	 * @return array Index => Zielschlüssel.
	 */
	public static function suggest_mapping( array $header ) {
		$targets = self::target_options();
		$mapping = array();

		foreach ( $header as $index => $label ) {
			$mapping[ $index ] = '';
			$normalized        = self::normalize_label( $label );

			foreach ( $targets as $key => $target_label ) {
				if ( '' === $key ) {
					continue;
				}

				if ( self::normalize_label( $target_label ) === $normalized ) {
					$mapping[ $index ] = $key;
					break;
				}
			}
		}

		return $mapping;
	}

	/**
	 * Normalisiert eine Bezeichnung für den Vergleich.
	 *
	 * @param string $label Bezeichnung.
	 * @return string
	 */
	private static function normalize_label( $label ) {
		$label = strtolower( remove_accents( (string) $label ) );

		return preg_replace( '/[^a-z0-9]/', '', $label );
	}

	/**
	 * Führt den Import aus.
	 *
	 * @param array $rows    Datenzeilen.
	 * @param array $mapping Index => Zielschlüssel.
	 * @param array $options dry_run, match_field, update_existing.
	 * @return array
	 */
	public static function run( array $rows, array $mapping, array $options = array() ) {
		$options = wp_parse_args(
			$options,
			array(
				'dry_run'         => true,
				'update_existing' => true,
			)
		);

		$result = array(
			'created'  => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'errors'   => array(),
			'preview'  => array(),
			'dry_run'  => (bool) $options['dry_run'],
		);

		$line_number = 1;

		foreach ( $rows as $row ) {
			$line_number++;

			$parsed = self::parse_row( $row, $mapping );

			if ( empty( $parsed['core'] ) && empty( $parsed['fields'] ) ) {
				$result['skipped']++;
				continue;
			}

			$existing = self::find_existing( $parsed );
			$errors   = self::validate_row( $parsed );

			if ( ! empty( $errors ) ) {
				$result['errors'][] = sprintf(
					/* translators: 1: Zeilennummer, 2: Fehlerbeschreibung. */
					__( 'Zeile %1$d: %2$s', 'svm' ),
					$line_number,
					implode( ' ', $errors )
				);
				$result['skipped']++;
				continue;
			}

			$result['preview'][] = array(
				'line'     => $line_number,
				'action'   => $existing ? __( 'aktualisieren', 'svm' ) : __( 'neu anlegen', 'svm' ),
				'name'     => self::preview_name( $parsed ),
				'existing' => $existing ? (int) $existing['id'] : 0,
			);

			if ( $options['dry_run'] ) {
				if ( $existing ) {
					$result['updated']++;
				} else {
					$result['created']++;
				}
				continue;
			}

			if ( $existing && ! $options['update_existing'] ) {
				$result['skipped']++;
				continue;
			}

			if ( $existing ) {
				self::apply_to_member( (int) $existing['id'], $parsed );
				$result['updated']++;
			} else {
				$member_id = SVM_Members::create( self::core_data( $parsed ) );
				self::apply_to_member( $member_id, $parsed );
				$result['created']++;
			}
		}

		if ( ! $options['dry_run'] ) {
			SVM_Audit::log( 'import', 0, 'executed', 'rows', '', $result['created'] . '/' . $result['updated'] );
		}

		return $result;
	}

	/**
	 * Zerlegt eine Zeile anhand des Mappings.
	 *
	 * @param array $row     Zeile.
	 * @param array $mapping Mapping.
	 * @return array core, fields
	 */
	private static function parse_row( array $row, array $mapping ) {
		$parsed = array( 'core' => array(), 'fields' => array() );

		foreach ( $mapping as $index => $target ) {
			if ( '' === $target || ! isset( $row[ $index ] ) ) {
				continue;
			}

			$value = trim( (string) $row[ $index ] );

			if ( '' === $value ) {
				continue;
			}

			list( $prefix, $key ) = array_pad( explode( ':', $target, 2 ), 2, '' );

			if ( 'field' === $prefix ) {
				$parsed['fields'][ (int) $key ] = $value;
			} else {
				$parsed['core'][ $key ] = $value;
			}
		}

		return $parsed;
	}

	/**
	 * Prüft die Werte einer Zeile.
	 *
	 * @param array $parsed Zerlegte Zeile.
	 * @return array Fehlermeldungen.
	 */
	private static function validate_row( array $parsed ) {
		$errors = array();

		foreach ( $parsed['fields'] as $field_id => $value ) {
			$def = SVM_Fields::get_def( $field_id );

			if ( ! $def ) {
				continue;
			}

			$check = SVM_Fields::validate( $def, $value );

			if ( is_wp_error( $check ) ) {
				$errors[] = $check->get_error_message();
			}
		}

		if ( isset( $parsed['core']['iban'] ) && ! SVM_IBAN::is_valid( $parsed['core']['iban'] ) ) {
			$errors[] = __( 'Die IBAN ist ungültig.', 'svm' );
		}

		return $errors;
	}

	/**
	 * Sucht einen bestehenden Datensatz.
	 *
	 * @param array $parsed Zerlegte Zeile.
	 * @return array|null
	 */
	private static function find_existing( array $parsed ) {
		if ( ! empty( $parsed['core']['member_number'] ) ) {
			return SVM_Members::get_by_number( $parsed['core']['member_number'] );
		}

		return null;
	}

	/**
	 * Kerndaten für die Neuanlage.
	 *
	 * @param array $parsed Zerlegte Zeile.
	 * @return array
	 */
	private static function core_data( array $parsed ) {
		$data = array();

		if ( ! empty( $parsed['core']['member_number'] ) ) {
			$data['member_number'] = $parsed['core']['member_number'];
		}

		if ( ! empty( $parsed['core']['joined_at'] ) ) {
			$timestamp = strtotime( $parsed['core']['joined_at'] );
			if ( $timestamp ) {
				$data['joined_at'] = gmdate( 'Y-m-d', $timestamp );
			}
		}

		if ( ! empty( $parsed['core']['status'] ) ) {
			$status = self::resolve_status( $parsed['core']['status'] );
			if ( $status ) {
				$data['status_id'] = (int) $status['id'];
			}
		}

		return $data;
	}

	/**
	 * Überträgt eine Zeile auf ein Mitglied.
	 *
	 * @param int   $member_id Mitglieds-ID.
	 * @param array $parsed    Zerlegte Zeile.
	 * @return void
	 */
	private static function apply_to_member( $member_id, array $parsed ) {
		if ( ! empty( $parsed['fields'] ) ) {
			SVM_Fields::save_values( 'member', $member_id, $parsed['fields'], false );
		}

		$core = self::core_data( $parsed );

		if ( ! empty( $parsed['core']['left_at'] ) ) {
			$timestamp = strtotime( $parsed['core']['left_at'] );
			if ( $timestamp ) {
				$core['left_at'] = gmdate( 'Y-m-d', $timestamp );
			}
		}

		if ( ! empty( $parsed['core']['payment_method'] ) ) {
			foreach ( SVM_Payment_Methods::all() as $method ) {
				if ( 0 === strcasecmp( $method['label'], $parsed['core']['payment_method'] ) ) {
					$core['payment_method_id'] = (int) $method['id'];
					break;
				}
			}
		}

		unset( $core['member_number'] );

		if ( ! empty( $core ) ) {
			SVM_Members::update( $member_id, $core );
		}

		if ( ! empty( $parsed['core']['units'] ) ) {
			self::assign_units( $member_id, $parsed['core']['units'] );
		}

		if ( ! empty( $parsed['core']['family'] ) ) {
			self::assign_family(
				$member_id,
				$parsed['core']['family'],
				isset( $parsed['core']['relation'] ) ? $parsed['core']['relation'] : ''
			);
		}

		if ( ! empty( $parsed['core']['iban'] ) ) {
			SVM_Mandates::save(
				array(
					'member_id'   => $member_id,
					'iban'        => $parsed['core']['iban'],
					'mandate_ref' => isset( $parsed['core']['mandate_ref'] ) ? $parsed['core']['mandate_ref'] : '',
					'signed_at'   => isset( $parsed['core']['mandate_date'] ) && strtotime( $parsed['core']['mandate_date'] )
						? gmdate( 'Y-m-d', strtotime( $parsed['core']['mandate_date'] ) )
						: null,
				)
			);
		}
	}

	/**
	 * Ordnet Einheiten anhand ihrer Namen zu.
	 *
	 * @param int    $member_id Mitglieds-ID.
	 * @param string $value     Kommagetrennte Namen.
	 * @return void
	 */
	private static function assign_units( $member_id, $value ) {
		$names = array_map( 'trim', explode( ',', $value ) );
		$ids   = SVM_Members::unit_ids( $member_id );

		foreach ( $names as $name ) {
			if ( '' === $name ) {
				continue;
			}

			foreach ( SVM_Units::all() as $unit ) {
				if ( 0 === strcasecmp( $unit['name'], $name ) || 0 === strcasecmp( $unit['short_name'], $name ) ) {
					$ids[] = (int) $unit['id'];
					break;
				}
			}
		}

		SVM_Members::set_units( $member_id, array_unique( $ids ) );
	}

	/**
	 * Ordnet ein Mitglied einer Familie zu und legt sie bei Bedarf an.
	 *
	 * @param int    $member_id Mitglieds-ID.
	 * @param string $name      Name der Familie.
	 * @param string $relation  Rolle in der Familie.
	 * @return void
	 */
	private static function assign_family( $member_id, $name, $relation ) {
		$name = trim( (string) $name );

		if ( '' === $name ) {
			return;
		}

		$family_id = 0;

		foreach ( SVM_Families::all() as $family ) {
			if ( 0 === strcasecmp( $family['name'], $name ) ) {
				$family_id = (int) $family['id'];
				break;
			}
		}

		if ( 0 === $family_id ) {
			$family_id = SVM_Families::save( array( 'name' => $name ) );
		}

		$key       = sanitize_key( $relation );
		$relations = SVM_Families::relations();

		if ( ! isset( $relations[ $key ] ) ) {
			$key = 'sonstig';

			foreach ( $relations as $candidate => $label ) {
				if ( 0 === strcasecmp( $label, (string) $relation ) ) {
					$key = $candidate;
					break;
				}
			}
		}

		SVM_Families::add_member( $family_id, $member_id, $key );
	}

	/**
	 * Findet einen Status anhand Schlüssel oder Bezeichnung.
	 *
	 * @param string $value Wert.
	 * @return array|null
	 */
	private static function resolve_status( $value ) {
		$status = SVM_Statuses::get_by_key( sanitize_key( $value ) );

		if ( $status ) {
			return $status;
		}

		foreach ( SVM_Statuses::all() as $candidate ) {
			if ( 0 === strcasecmp( $candidate['label'], $value ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Name für die Vorschau.
	 *
	 * @param array $parsed Zerlegte Zeile.
	 * @return string
	 */
	private static function preview_name( array $parsed ) {
		$parts = array();

		foreach ( array( 'first_name', 'last_name', 'display_name' ) as $role ) {
			$def = SVM_Fields::get_def_by_system_role( $role );

			if ( $def && isset( $parsed['fields'][ (int) $def['id'] ] ) ) {
				$parts[] = $parsed['fields'][ (int) $def['id'] ];
			}
		}

		if ( empty( $parts ) && ! empty( $parsed['core']['member_number'] ) ) {
			$parts[] = $parsed['core']['member_number'];
		}

		return implode( ' ', $parts );
	}
}
