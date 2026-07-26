<?php
/**
 * Export-Motor.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Baut aus einem Profil eine Datei — inklusive aller selbst angelegten Felder.
 */
class SVM_Exporter {

	/**
	 * Verfügbare Spalten einer Datenbasis.
	 *
	 * @param string $entity_type Datenbasis.
	 * @return array key => Label
	 */
	public static function available_columns( $entity_type ) {
		$columns = array();

		switch ( $entity_type ) {
			case 'member':
				$columns = array(
					'core:member_number' => __( 'Mitgliedsnummer', 'svm' ),
					'core:status'        => __( 'Status', 'svm' ),
					'core:joined_at'     => __( 'Eintrittsdatum', 'svm' ),
					'core:left_at'       => __( 'Austrittsdatum', 'svm' ),
					'core:payment_method' => __( 'Zahlart', 'svm' ),
					'calc:units'         => __( 'Sparten/Gruppen', 'svm' ),
					'calc:age'           => __( 'Alter', 'svm' ),
					'calc:open_total'    => __( 'Offener Betrag', 'svm' ),
					'calc:credit'        => __( 'Guthaben', 'svm' ),
					'calc:mandate'       => __( 'Mandatsreferenz', 'svm' ),
				);

				foreach ( SVM_Fields::defs( 'member' ) as $def ) {
					if ( 'heading' === $def['field_type'] ) {
						continue;
					}
					$columns[ 'field:' . $def['id'] ] = $def['label'];
				}
				break;

			case 'invoice':
				$columns = array(
					'core:id'            => __( 'Forderungsnummer', 'svm' ),
					'calc:member_number' => __( 'Mitgliedsnummer', 'svm' ),
					'calc:member_name'   => __( 'Mitglied', 'svm' ),
					'core:description'   => __( 'Beschreibung', 'svm' ),
					'core:period_start'  => __( 'Zeitraum von', 'svm' ),
					'core:period_end'    => __( 'Zeitraum bis', 'svm' ),
					'core:due_date'      => __( 'Fällig am', 'svm' ),
					'core:amount'        => __( 'Betrag', 'svm' ),
					'core:amount_paid'   => __( 'Bezahlt', 'svm' ),
					'calc:open'          => __( 'Offen', 'svm' ),
					'core:status'        => __( 'Status', 'svm' ),
					'calc:fee_type'      => __( 'Beitragsart', 'svm' ),
					'core:dunning_level' => __( 'Mahnstufe', 'svm' ),
				);
				break;

			case 'payment':
				$columns = array(
					'core:id'            => __( 'Zahlungsnummer', 'svm' ),
					'calc:member_number' => __( 'Mitgliedsnummer', 'svm' ),
					'calc:member_name'   => __( 'Mitglied', 'svm' ),
					'core:payer_name'    => __( 'Zahler', 'svm' ),
					'core:amount'        => __( 'Betrag', 'svm' ),
					'core:paid_at'       => __( 'Zahlungsdatum', 'svm' ),
					'core:reference'     => __( 'Referenz/Verwendungszweck', 'svm' ),
					'calc:method'        => __( 'Zahlart', 'svm' ),
					'core:status'        => __( 'Status', 'svm' ),
					'calc:allocated'     => __( 'Zugeordnet auf', 'svm' ),
					'core:note'          => __( 'Notiz', 'svm' ),
				);
				break;

			case 'mandate':
				$columns = array(
					'calc:member_number' => __( 'Mitgliedsnummer', 'svm' ),
					'calc:member_name'   => __( 'Mitglied', 'svm' ),
					'core:mandate_ref'   => __( 'Mandatsreferenz', 'svm' ),
					'core:iban'          => __( 'IBAN', 'svm' ),
					'core:bic'           => __( 'BIC', 'svm' ),
					'core:account_holder' => __( 'Kontoinhaber', 'svm' ),
					'core:signed_at'     => __( 'Unterschrieben am', 'svm' ),
					'core:sequence_type' => __( 'Sequenztyp', 'svm' ),
					'core:status'        => __( 'Status', 'svm' ),
				);
				break;
		}

		/**
		 * Erlaubt Erweiterungen, eigene Exportspalten anzubieten.
		 *
		 * @param array  $columns     Spalten.
		 * @param string $entity_type Datenbasis.
		 */
		return apply_filters( 'svm_export_columns', $columns, $entity_type );
	}

	/**
	 * Baut die Datenzeilen eines Profils.
	 *
	 * @param array $profile Profil.
	 * @param array $args    Zusätzliche Filter.
	 * @return array Liste aus Spaltenlabel => Wert.
	 */
	public static function rows( array $profile, array $args = array() ) {
		$columns   = SVM_Export_Profiles::columns( $profile );
		$available = self::available_columns( $profile['entity_type'] );
		$records   = self::fetch_records( $profile, $args );
		$rows      = array();

		foreach ( $records as $record ) {
			$row = array();

			foreach ( $columns as $column ) {
				if ( ! isset( $available[ $column ] ) ) {
					continue;
				}

				$value = self::column_value( $profile['entity_type'], $column, $record );

				if ( null === $value ) {
					continue;
				}

				$row[ $available[ $column ] ] = $value;
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Holt die Datensätze der Datenbasis.
	 *
	 * @param array $profile Profil.
	 * @param array $args    Zusätzliche Filter.
	 * @return array
	 */
	private static function fetch_records( array $profile, array $args ) {
		$args = wp_parse_args( $args, array( 'limit' => 0 ) );

		switch ( $profile['entity_type'] ) {
			case 'member':
				$records = SVM_Members::query( $args );

				if ( (int) $profile['filter_rule_id'] > 0 ) {
					$rule    = SVM_Rules::get( (int) $profile['filter_rule_id'] );
					$records = self::filter_members( $records, $rule );
				}

				return $records;

			case 'invoice':
				return SVM_Invoices::query( $args );

			case 'payment':
				return SVM_Payments::query( $args );

			case 'mandate':
				return SVM_DB::all( 'mandates', '', 'id ASC' );
		}

		return array();
	}

	/**
	 * Wendet eine Filterregel auf Mitglieder an.
	 *
	 * @param array      $records Datensätze.
	 * @param array|null $rule    Regel.
	 * @return array
	 */
	private static function filter_members( array $records, $rule ) {
		if ( ! $rule ) {
			return $records;
		}

		$out = array();

		foreach ( $records as $record ) {
			$context = SVM_Members::context( (int) $record['id'] );

			if ( $context && SVM_Rule_Engine::matches( $rule, $context ) ) {
				$out[] = $record;
			}
		}

		return $out;
	}

	/**
	 * Ermittelt den Wert einer Spalte.
	 *
	 * @param string $entity_type Datenbasis.
	 * @param string $column      Spaltenschlüssel.
	 * @param array  $record      Datensatz.
	 * @return string|null null bedeutet: für diesen Benutzer nicht sichtbar.
	 */
	private static function column_value( $entity_type, $column, array $record ) {
		list( $prefix, $key ) = array_pad( explode( ':', $column, 2 ), 2, '' );

		if ( 'field' === $prefix ) {
			$def = SVM_Fields::get_def( (int) $key );

			if ( ! $def ) {
				return '';
			}

			// Feldsichtbarkeit gilt auch im Export.
			$rules = SVM_Fields::user_field_rules( $def );

			if ( empty( $rules['can_view'] ) ) {
				return null;
			}

			$value = SVM_Fields::get_value_by_id( 'member', (int) $record['id'], (int) $def['id'] );

			return SVM_Fields::format_value( $def, $value );
		}

		if ( 'core' === $prefix ) {
			if ( 'status' === $key && 'member' === $entity_type ) {
				$status = SVM_Statuses::get( (int) $record['status_id'] );
				return $status ? $status['label'] : '';
			}

			if ( 'status' === $key && 'invoice' === $entity_type ) {
				$statuses = SVM_Invoices::statuses();
				return isset( $statuses[ $record['status'] ] ) ? $statuses[ $record['status'] ] : $record['status'];
			}

			if ( 'payment_method' === $key ) {
				$method = SVM_Payment_Methods::get( (int) $record['payment_method_id'] );
				return $method ? $method['label'] : '';
			}

			if ( 'iban' === $key ) {
				return SVM_Permissions::current_user_can( 'bank_data_view' )
					? SVM_IBAN::format( $record['iban'] )
					: SVM_IBAN::mask( $record['iban'] );
			}

			return isset( $record[ $key ] ) ? (string) $record[ $key ] : '';
		}

		if ( 'calc' === $prefix ) {
			return self::calculated_value( $entity_type, $key, $record );
		}

		return '';
	}

	/**
	 * Berechnete Spalten.
	 *
	 * @param string $entity_type Datenbasis.
	 * @param string $key         Spaltenschlüssel.
	 * @param array  $record      Datensatz.
	 * @return string
	 */
	private static function calculated_value( $entity_type, $key, array $record ) {
		$member_id = 'member' === $entity_type ? (int) $record['id'] : (int) $record['member_id'];

		switch ( $key ) {
			case 'member_number':
				$member = SVM_Members::get( $member_id );
				return $member ? $member['member_number'] : '';

			case 'member_name':
				return SVM_Members::display_name( $member_id );

			case 'units':
				$names = array();
				foreach ( SVM_Members::unit_ids( $member_id ) as $unit_id ) {
					$names[] = SVM_Units::name( $unit_id );
				}
				return implode( ', ', array_filter( $names ) );

			case 'age':
				$age = SVM_Members::age( $member_id );
				return null === $age ? '' : (string) $age;

			case 'open_total':
				return number_format( SVM_Invoices::open_total( $member_id ), 2, ',', '' );

			case 'credit':
				return number_format( SVM_Payments::credit_balance( $member_id ), 2, ',', '' );

			case 'mandate':
				$mandate = SVM_Mandates::active_for_member( $member_id );
				return $mandate ? $mandate['mandate_ref'] : '';

			case 'open':
				return number_format( SVM_Invoices::remaining( $record ), 2, ',', '' );

			case 'fee_type':
				$fee_type = SVM_Fee_Types::get( (int) $record['fee_type_id'] );
				return $fee_type ? $fee_type['label'] : '';

			case 'method':
				$method = SVM_Payment_Methods::get( (int) $record['payment_method_id'] );
				return $method ? $method['label'] : '';

			case 'allocated':
				$parts = array();
				foreach ( SVM_Payments::allocations( (int) $record['id'] ) as $allocation ) {
					$invoice = SVM_Invoices::get( (int) $allocation['invoice_id'] );
					$parts[] = ( $invoice ? $invoice['description'] : '#' . $allocation['invoice_id'] ) .
						' (' . number_format( (float) $allocation['amount'], 2, ',', '' ) . ')';
				}
				return implode( '; ', $parts );
		}

		return '';
	}

	/**
	 * Erzeugt den Dateiinhalt.
	 *
	 * @param array $profile Profil.
	 * @param array $rows    Datenzeilen.
	 * @return string
	 */
	public static function render( array $profile, array $rows ) {
		switch ( $profile['format'] ) {
			case 'json':
				return wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

			case 'xml':
				return self::render_xml( $profile, $rows );

			case 'xlsx':
				// Excel öffnet UTF-8-CSV nur mit BOM zuverlässig.
				return "\xEF\xBB\xBF" . self::render_csv( $profile, $rows );

			case 'csv':
			default:
				return self::render_csv( $profile, $rows );
		}
	}

	/**
	 * CSV-Ausgabe.
	 *
	 * @param array $profile Profil.
	 * @param array $rows    Datenzeilen.
	 * @return string
	 */
	private static function render_csv( array $profile, array $rows ) {
		$delimiter = '' !== $profile['delimiter'] ? $profile['delimiter'] : ';';
		$handle    = fopen( 'php://temp', 'r+' );

		if ( ! empty( $rows ) ) {
			fputcsv( $handle, array_keys( $rows[0] ), $delimiter );
		}

		foreach ( $rows as $row ) {
			fputcsv( $handle, array_values( $row ), $delimiter );
		}

		rewind( $handle );
		$content = stream_get_contents( $handle );
		fclose( $handle );

		if ( 'ISO-8859-1' === $profile['charset'] && function_exists( 'mb_convert_encoding' ) ) {
			$content = mb_convert_encoding( $content, 'ISO-8859-1', 'UTF-8' );
		}

		return $content;
	}

	/**
	 * XML-Ausgabe.
	 *
	 * @param array $profile Profil.
	 * @param array $rows    Datenzeilen.
	 * @return string
	 */
	private static function render_xml( array $profile, array $rows ) {
		$dom               = new DOMDocument( '1.0', 'UTF-8' );
		$dom->formatOutput = true;

		$root = $dom->createElement( 'export' );
		$root->setAttribute( 'type', $profile['entity_type'] );
		$root->setAttribute( 'created', gmdate( 'c' ) );
		$dom->appendChild( $root );

		foreach ( $rows as $row ) {
			$item = $dom->createElement( 'record' );

			foreach ( $row as $label => $value ) {
				$tag = sanitize_key( remove_accents( $label ) );
				$tag = '' !== $tag ? $tag : 'feld';

				if ( is_numeric( $tag[0] ) ) {
					$tag = 'f_' . $tag;
				}

				$item->appendChild( $dom->createElement( $tag, htmlspecialchars( (string) $value, ENT_XML1 ) ) );
			}

			$root->appendChild( $item );
		}

		return $dom->saveXML();
	}

	/**
	 * Dateiendung eines Formats.
	 *
	 * @param string $format Format.
	 * @return string
	 */
	public static function extension( $format ) {
		switch ( $format ) {
			case 'json':
				return 'json';
			case 'xml':
				return 'xml';
			default:
				return 'csv';
		}
	}

	/**
	 * MIME-Typ eines Formats.
	 *
	 * @param string $format Format.
	 * @return string
	 */
	public static function mime_type( $format ) {
		switch ( $format ) {
			case 'json':
				return 'application/json';
			case 'xml':
				return 'application/xml';
			default:
				return 'text/csv';
		}
	}

	/**
	 * Sendet einen Export als Download.
	 *
	 * @param array $profile Profil.
	 * @param array $args    Zusätzliche Filter.
	 * @return void
	 */
	public static function download( array $profile, array $args = array() ) {
		$rows     = self::rows( $profile, $args );
		$content  = self::render( $profile, $rows );
		$filename = sanitize_file_name( $profile['label'] ) . '-' . gmdate( 'Y-m-d' ) . '.' . self::extension( $profile['format'] );

		SVM_Audit::log( 'export', (int) $profile['id'], 'executed', 'rows', '', count( $rows ) );

		nocache_headers();
		header( 'Content-Type: ' . self::mime_type( $profile['format'] ) . '; charset=' . $profile['charset'] );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $content ) );

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
}
