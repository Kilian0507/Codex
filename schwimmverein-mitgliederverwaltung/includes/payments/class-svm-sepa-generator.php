<?php
/**
 * Erzeugung von SEPA-Zahldateien.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Baut pain.008 (Lastschrift) und pain.001 (Überweisung) sowie freie CSV-Formate.
 *
 * Die XML-Struktur folgt dem ISO-20022-Standard; konfigurierbar sind Auswahl und
 * Parametrierung des Profils, nicht der Aufbau der Datei selbst.
 */
class SVM_SEPA_Generator {

	/**
	 * Erzeugt den Dateiinhalt zu einem Profil.
	 *
	 * @param array  $profile         Dateiprofil.
	 * @param array  $items           Positionen.
	 * @param string $collection_date Ausführungs-/Einzugsdatum.
	 * @param string $message_id      Nachrichten-ID.
	 * @return string|WP_Error
	 */
	public static function generate( array $profile, array $items, $collection_date, $message_id ) {
		if ( empty( $items ) ) {
			return new WP_Error( 'svm_sepa_empty', __( 'Der Zahllauf enthält keine Positionen.', 'svm' ) );
		}

		if ( 'csv' === $profile['format'] ) {
			return self::generate_csv( $profile, $items );
		}

		if ( 'credit' === SVM_File_Profiles::direction( $profile['format'] ) ) {
			return self::generate_credit_transfer( $profile, $items, $collection_date, $message_id );
		}

		return self::generate_direct_debit( $profile, $items, $collection_date, $message_id );
	}

	/**
	 * Erzeugt eine SEPA-Lastschriftdatei (pain.008).
	 *
	 * @param array  $profile         Dateiprofil.
	 * @param array  $items           Positionen.
	 * @param string $collection_date Einzugsdatum.
	 * @param string $message_id      Nachrichten-ID.
	 * @return string|WP_Error
	 */
	private static function generate_direct_debit( array $profile, array $items, $collection_date, $message_id ) {
		$error = self::validate_creditor( $profile, true );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$namespace = 'urn:iso:std:iso:20022:tech:xsd:' . $profile['format'];

		$dom               = new DOMDocument( '1.0', 'UTF-8' );
		$dom->formatOutput = true;

		$document = $dom->createElementNS( $namespace, 'Document' );
		$document->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance' );
		$dom->appendChild( $document );

		$root = $dom->createElement( 'CstmrDrctDbtInitn' );
		$document->appendChild( $root );

		$total = 0.0;
		foreach ( $items as $item ) {
			$total += (float) $item['amount'];
		}

		$root->appendChild( self::group_header( $dom, $message_id, count( $items ), $total, $profile['creditor_name'] ) );

		// Je Sequenztyp eine eigene Zahlungsinformation — die Vorlauffristen unterscheiden sich.
		$groups = array();
		foreach ( $items as $item ) {
			$sequence = isset( $item['sequence_type'] ) ? $item['sequence_type'] : 'RCUR';
			$groups[ $sequence ][] = $item;
		}

		$index = 1;
		foreach ( $groups as $sequence => $group_items ) {
			$root->appendChild(
				self::payment_info_debit( $dom, $profile, $group_items, $sequence, $collection_date, $message_id . '-' . $index )
			);
			$index++;
		}

		return $dom->saveXML();
	}

	/**
	 * Baut den Gruppenkopf.
	 *
	 * @param DOMDocument $dom        Dokument.
	 * @param string      $message_id Nachrichten-ID.
	 * @param int         $count      Anzahl Positionen.
	 * @param float       $total      Summe.
	 * @param string      $party      Auftraggeber.
	 * @return DOMElement
	 */
	private static function group_header( DOMDocument $dom, $message_id, $count, $total, $party ) {
		$header = $dom->createElement( 'GrpHdr' );

		$header->appendChild( $dom->createElement( 'MsgId', self::text( $message_id, 35 ) ) );
		$header->appendChild( $dom->createElement( 'CreDtTm', gmdate( 'Y-m-d\TH:i:s' ) ) );
		$header->appendChild( $dom->createElement( 'NbOfTxs', (string) $count ) );
		$header->appendChild( $dom->createElement( 'CtrlSum', self::amount( $total ) ) );

		$party_element = $dom->createElement( 'InitgPty' );
		$party_element->appendChild( $dom->createElement( 'Nm', self::text( $party, 70 ) ) );
		$header->appendChild( $party_element );

		return $header;
	}

	/**
	 * Baut einen Lastschrift-Zahlungsblock.
	 *
	 * @param DOMDocument $dom             Dokument.
	 * @param array       $profile         Profil.
	 * @param array       $items           Positionen.
	 * @param string      $sequence        Sequenztyp.
	 * @param string      $collection_date Einzugsdatum.
	 * @param string      $payment_info_id Block-ID.
	 * @return DOMElement
	 */
	private static function payment_info_debit( DOMDocument $dom, array $profile, array $items, $sequence, $collection_date, $payment_info_id ) {
		$total = 0.0;
		foreach ( $items as $item ) {
			$total += (float) $item['amount'];
		}

		$info = $dom->createElement( 'PmtInf' );
		$info->appendChild( $dom->createElement( 'PmtInfId', self::text( $payment_info_id, 35 ) ) );
		$info->appendChild( $dom->createElement( 'PmtMtd', 'DD' ) );
		$info->appendChild( $dom->createElement( 'BtchBookg', $profile['batch_booking'] ? 'true' : 'false' ) );
		$info->appendChild( $dom->createElement( 'NbOfTxs', (string) count( $items ) ) );
		$info->appendChild( $dom->createElement( 'CtrlSum', self::amount( $total ) ) );

		$type = $dom->createElement( 'PmtTpInf' );
		$level = $dom->createElement( 'SvcLvl' );
		$level->appendChild( $dom->createElement( 'Cd', 'SEPA' ) );
		$type->appendChild( $level );

		$local = $dom->createElement( 'LclInstrm' );
		$local->appendChild( $dom->createElement( 'Cd', 'CORE' ) );
		$type->appendChild( $local );

		$type->appendChild( $dom->createElement( 'SeqTp', $sequence ) );
		$info->appendChild( $type );

		$info->appendChild( $dom->createElement( 'ReqdColltnDt', $collection_date ) );

		$creditor = $dom->createElement( 'Cdtr' );
		$creditor->appendChild( $dom->createElement( 'Nm', self::text( $profile['creditor_name'], 70 ) ) );
		$info->appendChild( $creditor );

		$info->appendChild( self::account_element( $dom, 'CdtrAcct', $profile['creditor_iban'] ) );
		$info->appendChild( self::agent_element( $dom, 'CdtrAgt', $profile['creditor_bic'] ) );

		$info->appendChild( $dom->createElement( 'ChrgBr', 'SLEV' ) );

		$info->appendChild( self::creditor_scheme( $dom, $profile['creditor_id'] ) );

		foreach ( $items as $item ) {
			$info->appendChild( self::debit_transaction( $dom, $item ) );
		}

		return $info;
	}

	/**
	 * Gläubiger-Identifikationsnummer.
	 *
	 * @param DOMDocument $dom         Dokument.
	 * @param string      $creditor_id Gläubiger-ID.
	 * @return DOMElement
	 */
	private static function creditor_scheme( DOMDocument $dom, $creditor_id ) {
		$scheme  = $dom->createElement( 'CdtrSchmeId' );
		$id      = $dom->createElement( 'Id' );
		$private = $dom->createElement( 'PrvtId' );
		$other   = $dom->createElement( 'Othr' );

		$other->appendChild( $dom->createElement( 'Id', self::text( $creditor_id, 35 ) ) );

		$scheme_name = $dom->createElement( 'SchmeNm' );
		$scheme_name->appendChild( $dom->createElement( 'Prtry', 'SEPA' ) );
		$other->appendChild( $scheme_name );

		$private->appendChild( $other );
		$id->appendChild( $private );
		$scheme->appendChild( $id );

		return $scheme;
	}

	/**
	 * Einzelne Lastschriftposition.
	 *
	 * @param DOMDocument $dom  Dokument.
	 * @param array       $item Position.
	 * @return DOMElement
	 */
	private static function debit_transaction( DOMDocument $dom, array $item ) {
		$transaction = $dom->createElement( 'DrctDbtTxInf' );

		$payment_id = $dom->createElement( 'PmtId' );
		$payment_id->appendChild( $dom->createElement( 'EndToEndId', self::text( $item['end_to_end_id'], 35 ) ) );
		$transaction->appendChild( $payment_id );

		$amount = $dom->createElement( 'InstdAmt', self::amount( $item['amount'] ) );
		$amount->setAttribute( 'Ccy', 'EUR' );
		$transaction->appendChild( $amount );

		$debit   = $dom->createElement( 'DrctDbtTx' );
		$mandate = $dom->createElement( 'MndtRltdInf' );
		$mandate->appendChild( $dom->createElement( 'MndtId', self::text( $item['mandate_ref'], 35 ) ) );
		$mandate->appendChild( $dom->createElement( 'DtOfSgntr', $item['signed_at'] ) );
		$debit->appendChild( $mandate );
		$transaction->appendChild( $debit );

		$transaction->appendChild( self::agent_element( $dom, 'DbtrAgt', isset( $item['bic'] ) ? $item['bic'] : '' ) );

		$debtor = $dom->createElement( 'Dbtr' );
		$debtor->appendChild( $dom->createElement( 'Nm', self::text( $item['name'], 70 ) ) );
		$transaction->appendChild( $debtor );

		$transaction->appendChild( self::account_element( $dom, 'DbtrAcct', $item['iban'] ) );

		if ( ! empty( $item['purpose'] ) ) {
			$remittance = $dom->createElement( 'RmtInf' );
			$remittance->appendChild( $dom->createElement( 'Ustrd', self::text( $item['purpose'], 140 ) ) );
			$transaction->appendChild( $remittance );
		}

		return $transaction;
	}

	/**
	 * Erzeugt eine SEPA-Überweisungsdatei (pain.001) für Auszahlungen des Vereins.
	 *
	 * @param array  $profile         Dateiprofil.
	 * @param array  $items           Positionen.
	 * @param string $execution_date  Ausführungsdatum.
	 * @param string $message_id      Nachrichten-ID.
	 * @return string|WP_Error
	 */
	private static function generate_credit_transfer( array $profile, array $items, $execution_date, $message_id ) {
		$error = self::validate_creditor( $profile, false );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$namespace = 'urn:iso:std:iso:20022:tech:xsd:' . $profile['format'];

		$dom               = new DOMDocument( '1.0', 'UTF-8' );
		$dom->formatOutput = true;

		$document = $dom->createElementNS( $namespace, 'Document' );
		$document->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance' );
		$dom->appendChild( $document );

		$root = $dom->createElement( 'CstmrCdtTrfInitn' );
		$document->appendChild( $root );

		$total = 0.0;
		foreach ( $items as $item ) {
			$total += (float) $item['amount'];
		}

		$root->appendChild( self::group_header( $dom, $message_id, count( $items ), $total, $profile['creditor_name'] ) );

		$info = $dom->createElement( 'PmtInf' );
		$info->appendChild( $dom->createElement( 'PmtInfId', self::text( $message_id . '-1', 35 ) ) );
		$info->appendChild( $dom->createElement( 'PmtMtd', 'TRF' ) );
		$info->appendChild( $dom->createElement( 'BtchBookg', $profile['batch_booking'] ? 'true' : 'false' ) );
		$info->appendChild( $dom->createElement( 'NbOfTxs', (string) count( $items ) ) );
		$info->appendChild( $dom->createElement( 'CtrlSum', self::amount( $total ) ) );

		$type  = $dom->createElement( 'PmtTpInf' );
		$level = $dom->createElement( 'SvcLvl' );
		$level->appendChild( $dom->createElement( 'Cd', 'SEPA' ) );
		$type->appendChild( $level );
		$info->appendChild( $type );

		$info->appendChild( $dom->createElement( 'ReqdExctnDt', $execution_date ) );

		$debtor = $dom->createElement( 'Dbtr' );
		$debtor->appendChild( $dom->createElement( 'Nm', self::text( $profile['creditor_name'], 70 ) ) );
		$info->appendChild( $debtor );

		$info->appendChild( self::account_element( $dom, 'DbtrAcct', $profile['creditor_iban'] ) );
		$info->appendChild( self::agent_element( $dom, 'DbtrAgt', $profile['creditor_bic'] ) );
		$info->appendChild( $dom->createElement( 'ChrgBr', 'SLEV' ) );

		foreach ( $items as $item ) {
			$transaction = $dom->createElement( 'CdtTrfTxInf' );

			$payment_id = $dom->createElement( 'PmtId' );
			$payment_id->appendChild( $dom->createElement( 'EndToEndId', self::text( $item['end_to_end_id'], 35 ) ) );
			$transaction->appendChild( $payment_id );

			$amount_element = $dom->createElement( 'Amt' );
			$instructed     = $dom->createElement( 'InstdAmt', self::amount( $item['amount'] ) );
			$instructed->setAttribute( 'Ccy', 'EUR' );
			$amount_element->appendChild( $instructed );
			$transaction->appendChild( $amount_element );

			$transaction->appendChild( self::agent_element( $dom, 'CdtrAgt', isset( $item['bic'] ) ? $item['bic'] : '' ) );

			$creditor = $dom->createElement( 'Cdtr' );
			$creditor->appendChild( $dom->createElement( 'Nm', self::text( $item['name'], 70 ) ) );
			$transaction->appendChild( $creditor );

			$transaction->appendChild( self::account_element( $dom, 'CdtrAcct', $item['iban'] ) );

			if ( ! empty( $item['purpose'] ) ) {
				$remittance = $dom->createElement( 'RmtInf' );
				$remittance->appendChild( $dom->createElement( 'Ustrd', self::text( $item['purpose'], 140 ) ) );
				$transaction->appendChild( $remittance );
			}

			$info->appendChild( $transaction );
		}

		$root->appendChild( $info );

		return $dom->saveXML();
	}

	/**
	 * Kontoelement mit IBAN.
	 *
	 * @param DOMDocument $dom  Dokument.
	 * @param string      $tag  Elementname.
	 * @param string      $iban IBAN.
	 * @return DOMElement
	 */
	private static function account_element( DOMDocument $dom, $tag, $iban ) {
		$account = $dom->createElement( $tag );
		$id      = $dom->createElement( 'Id' );
		$id->appendChild( $dom->createElement( 'IBAN', SVM_IBAN::normalize( $iban ) ) );
		$account->appendChild( $id );

		return $account;
	}

	/**
	 * Institutselement mit BIC (oder NOTPROVIDED).
	 *
	 * @param DOMDocument $dom Dokument.
	 * @param string      $tag Elementname.
	 * @param string      $bic BIC.
	 * @return DOMElement
	 */
	private static function agent_element( DOMDocument $dom, $tag, $bic ) {
		$agent       = $dom->createElement( $tag );
		$institution = $dom->createElement( 'FinInstnId' );
		$bic         = strtoupper( trim( (string) $bic ) );

		if ( '' !== $bic && SVM_IBAN::is_valid_bic( $bic ) ) {
			$institution->appendChild( $dom->createElement( 'BIC', $bic ) );
		} else {
			$other = $dom->createElement( 'Othr' );
			$other->appendChild( $dom->createElement( 'Id', 'NOTPROVIDED' ) );
			$institution->appendChild( $other );
		}

		$agent->appendChild( $institution );

		return $agent;
	}

	/**
	 * Erzeugt eine CSV-Datei nach freiem Bankformat.
	 *
	 * @param array $profile Profil.
	 * @param array $items   Positionen.
	 * @return string
	 */
	private static function generate_csv( array $profile, array $items ) {
		$mapping = json_decode( (string) $profile['csv_mapping'], true );

		if ( ! is_array( $mapping ) || empty( $mapping ) ) {
			$mapping = array(
				'Name'              => 'name',
				'IBAN'              => 'iban',
				'BIC'               => 'bic',
				'Betrag'            => 'amount',
				'Verwendungszweck'  => 'purpose',
				'Mandatsreferenz'   => 'mandate_ref',
				'Mandatsdatum'      => 'signed_at',
				'Sequenz'           => 'sequence_type',
				'Ende-zu-Ende-ID'   => 'end_to_end_id',
			);
		}

		$delimiter = '' !== $profile['csv_delimiter'] ? $profile['csv_delimiter'] : ';';
		$handle    = fopen( 'php://temp', 'r+' );

		fputcsv( $handle, array_keys( $mapping ), $delimiter );

		foreach ( $items as $item ) {
			$row = array();

			foreach ( $mapping as $source ) {
				$value = isset( $item[ $source ] ) ? $item[ $source ] : '';

				if ( 'amount' === $source ) {
					$value = number_format( (float) $value, 2, ',', '' );
				}

				$row[] = $value;
			}

			fputcsv( $handle, $row, $delimiter );
		}

		rewind( $handle );
		$content = stream_get_contents( $handle );
		fclose( $handle );

		return $content;
	}

	/**
	 * Prüft die Gläubigerangaben des Profils.
	 *
	 * @param array $profile        Profil.
	 * @param bool  $needs_creditor Gläubiger-ID erforderlich (nur Lastschrift).
	 * @return true|WP_Error
	 */
	private static function validate_creditor( array $profile, $needs_creditor ) {
		if ( '' === trim( (string) $profile['creditor_name'] ) ) {
			return new WP_Error( 'svm_sepa_name', __( 'Im Dateiprofil fehlt der Name des Vereins.', 'svm' ) );
		}

		if ( ! SVM_IBAN::is_valid( $profile['creditor_iban'] ) ) {
			return new WP_Error( 'svm_sepa_iban', __( 'Im Dateiprofil fehlt eine gültige IBAN des Vereinskontos.', 'svm' ) );
		}

		if ( $needs_creditor && '' === trim( (string) $profile['creditor_id'] ) ) {
			return new WP_Error( 'svm_sepa_creditor', __( 'Für Lastschriften wird die Gläubiger-Identifikationsnummer benötigt.', 'svm' ) );
		}

		return true;
	}

	/**
	 * Bereitet Text für SEPA auf: erlaubter Zeichensatz, Längenbegrenzung.
	 *
	 * @param string $value  Text.
	 * @param int    $length Maximale Länge.
	 * @return string
	 */
	public static function text( $value, $length = 140 ) {
		$value = (string) $value;

		$map = array(
			'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
			'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
			'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
			'á' => 'a', 'à' => 'a', 'â' => 'a', 'å' => 'a',
			'í' => 'i', 'ì' => 'i', 'î' => 'i',
			'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ø' => 'o',
			'ú' => 'u', 'ù' => 'u', 'û' => 'u',
			'ñ' => 'n', 'ç' => 'c', '&' => '+',
		);

		$value = strtr( $value, $map );

		// SEPA erlaubt nur diesen Zeichensatz.
		$value = preg_replace( "/[^A-Za-z0-9\/\-?:().,'+ ]/", ' ', $value );
		$value = preg_replace( '/\s+/', ' ', (string) $value );
		$value = trim( (string) $value );

		if ( '' === $value ) {
			$value = 'NOTPROVIDED';
		}

		return substr( $value, 0, $length );
	}

	/**
	 * Formatiert einen Betrag mit zwei Nachkommastellen und Punkt.
	 *
	 * @param float $amount Betrag.
	 * @return string
	 */
	public static function amount( $amount ) {
		return number_format( (float) $amount, 2, '.', '' );
	}
}
