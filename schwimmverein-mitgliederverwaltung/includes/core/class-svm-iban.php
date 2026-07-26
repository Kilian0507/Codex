<?php
/**
 * IBAN-Hilfsfunktionen.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prüfung, Formatierung und Maskierung von IBANs.
 */
class SVM_IBAN {

	/**
	 * Entfernt Leerzeichen und normalisiert die Schreibweise.
	 *
	 * @param string $iban IBAN.
	 * @return string
	 */
	public static function normalize( $iban ) {
		return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $iban ) );
	}

	/**
	 * Prüft Länge und Prüfziffer (ISO 13616, Modulo 97).
	 *
	 * @param string $iban IBAN.
	 * @return bool
	 */
	public static function is_valid( $iban ) {
		$iban = self::normalize( $iban );

		if ( strlen( $iban ) < 15 || strlen( $iban ) > 34 ) {
			return false;
		}

		if ( ! preg_match( '/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $iban ) ) {
			return false;
		}

		$rearranged = substr( $iban, 4 ) . substr( $iban, 0, 4 );
		$numeric    = '';

		for ( $i = 0, $len = strlen( $rearranged ); $i < $len; $i++ ) {
			$char = $rearranged[ $i ];
			if ( ctype_digit( $char ) ) {
				$numeric .= $char;
			} else {
				$numeric .= (string) ( ord( $char ) - 55 );
			}
		}

		return 1 === self::mod97( $numeric );
	}

	/**
	 * Modulo 97 für beliebig lange Zahlenketten.
	 *
	 * @param string $numeric Zahlenkette.
	 * @return int
	 */
	private static function mod97( $numeric ) {
		$remainder = 0;

		for ( $i = 0, $len = strlen( $numeric ); $i < $len; $i++ ) {
			$remainder = ( $remainder * 10 + (int) $numeric[ $i ] ) % 97;
		}

		return $remainder;
	}

	/**
	 * Formatiert eine IBAN in Vierergruppen.
	 *
	 * @param string $iban IBAN.
	 * @return string
	 */
	public static function format( $iban ) {
		return trim( chunk_split( self::normalize( $iban ), 4, ' ' ) );
	}

	/**
	 * Maskiert eine IBAN für Listenansichten.
	 *
	 * @param string $iban IBAN.
	 * @return string
	 */
	public static function mask( $iban ) {
		$iban = self::normalize( $iban );
		$len  = strlen( $iban );

		if ( $len < 8 ) {
			return $iban ? str_repeat( '*', $len ) : '';
		}

		return substr( $iban, 0, 4 ) . str_repeat( '*', $len - 8 ) . substr( $iban, -4 );
	}

	/**
	 * Leitet für deutsche IBANs keine BIC ab, prüft aber das Format.
	 *
	 * @param string $bic BIC.
	 * @return bool
	 */
	public static function is_valid_bic( $bic ) {
		$bic = strtoupper( preg_replace( '/\s+/', '', (string) $bic ) );
		return (bool) preg_match( '/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic );
	}
}
