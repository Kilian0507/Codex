<?php
/**
 * DSGVO: Auskunft, Aufbewahrungsfristen, Anonymisierung.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Der Datenschutz hängt am Feld, weil Felder frei anlegbar sind.
 */
class SVM_GDPR {

	/**
	 * Stellt alle Daten zu einem Mitglied zusammen.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return array
	 */
	public static function collect( $member_id ) {
		$member = SVM_Members::get( $member_id );

		if ( ! $member ) {
			return array();
		}

		$status = SVM_Statuses::get( (int) $member['status_id'] );

		$data = array(
			'stammdaten'     => array(
				__( 'Mitgliedsnummer', 'svm' )  => $member['member_number'],
				__( 'Status', 'svm' )           => $status ? $status['label'] : '',
				__( 'Eintrittsdatum', 'svm' )   => $member['joined_at'],
				__( 'Austrittsdatum', 'svm' )   => $member['left_at'],
				__( 'Angelegt am', 'svm' )      => $member['created_at'],
			),
			'felder'         => array(),
			'mitgliedschaften' => array(),
			'forderungen'    => array(),
			'zahlungen'      => array(),
			'mandate'        => array(),
			'protokoll'      => array(),
		);

		foreach ( SVM_Fields::defs( 'member', false ) as $def ) {
			if ( empty( $def['in_gdpr_export'] ) || 'heading' === $def['field_type'] ) {
				continue;
			}

			$value = SVM_Fields::get_value_by_id( 'member', $member_id, (int) $def['id'] );

			$data['felder'][ $def['label'] ] = SVM_Fields::format_value( $def, $value );
		}

		foreach ( SVM_Members::memberships( $member_id, false ) as $membership ) {
			$data['mitgliedschaften'][] = array(
				__( 'Einheit', 'svm' ) => SVM_Units::name( (int) $membership['unit_id'] ),
				__( 'Von', 'svm' )     => $membership['joined_at'],
				__( 'Bis', 'svm' )     => $membership['left_at'],
				__( 'Aktiv', 'svm' )   => $membership['is_active'] ? __( 'ja', 'svm' ) : __( 'nein', 'svm' ),
			);
		}

		foreach ( SVM_Invoices::query( array( 'member_id' => $member_id, 'limit' => 0, 'respect_scope' => false ) ) as $invoice ) {
			$data['forderungen'][] = array(
				__( 'Beschreibung', 'svm' ) => $invoice['description'],
				__( 'Zeitraum', 'svm' )     => $invoice['period_start'] . ' – ' . $invoice['period_end'],
				__( 'Betrag', 'svm' )       => $invoice['amount'],
				__( 'Bezahlt', 'svm' )      => $invoice['amount_paid'],
				__( 'Status', 'svm' )       => $invoice['status'],
			);
		}

		foreach ( SVM_Payments::query( array( 'member_id' => $member_id, 'limit' => 0, 'respect_scope' => false ) ) as $payment ) {
			$data['zahlungen'][] = array(
				__( 'Datum', 'svm' )  => $payment['paid_at'],
				__( 'Betrag', 'svm' ) => $payment['amount'],
				__( 'Zahler', 'svm' ) => $payment['payer_name'],
				__( 'Referenz', 'svm' ) => $payment['reference'],
				__( 'Status', 'svm' ) => $payment['status'],
			);
		}

		foreach ( SVM_Mandates::for_member( $member_id ) as $mandate ) {
			$data['mandate'][] = array(
				__( 'Mandatsreferenz', 'svm' ) => $mandate['mandate_ref'],
				__( 'IBAN', 'svm' )            => SVM_IBAN::mask( $mandate['iban'] ),
				__( 'Unterschrieben am', 'svm' ) => $mandate['signed_at'],
				__( 'Status', 'svm' )          => $mandate['status'],
			);
		}

		foreach ( SVM_Audit::query( array( 'entity_type' => 'member', 'entity_id' => $member_id, 'limit' => 200 ) ) as $entry ) {
			$data['protokoll'][] = array(
				__( 'Zeitpunkt', 'svm' ) => $entry['created_at'],
				__( 'Aktion', 'svm' )    => $entry['action'],
				__( 'Feld', 'svm' )      => $entry['field_key'],
			);
		}

		return $data;
	}

	/**
	 * Sendet die Auskunft als JSON-Download.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return void
	 */
	public static function download( $member_id ) {
		$data   = self::collect( $member_id );
		$member = SVM_Members::get( $member_id );

		$filename = 'dsgvo-auskunft-' . sanitize_file_name( $member ? $member['member_number'] : $member_id ) . '.json';

		SVM_Audit::log( 'member', $member_id, 'gdpr_export' );

		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * Findet Mitglieder, deren Aufbewahrungsfrist abgelaufen ist.
	 *
	 * @param int $months Frist nach Austritt in Monaten.
	 * @return array
	 */
	public static function due_for_anonymization( $months = 120 ) {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d', strtotime( '-' . (int) $months . ' months' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'members' ) . '
				WHERE left_at IS NOT NULL AND left_at <= %s ORDER BY left_at ASC',
				$cutoff
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Anonymisiert ein Mitglied feldweise.
	 *
	 * Finanzdaten bleiben als Summen erhalten, personenbezogene Felder werden geleert.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return void
	 */
	public static function anonymize( $member_id ) {
		$member = SVM_Members::get( $member_id );

		if ( ! $member ) {
			return;
		}

		foreach ( SVM_Fields::defs( 'member', false ) as $def ) {
			if ( 'heading' === $def['field_type'] ) {
				continue;
			}

			// Felder mit eigener Aufbewahrungsfrist erst nach deren Ablauf löschen.
			if ( (int) $def['retention_months'] > 0 && ! self::retention_expired( $member, (int) $def['retention_months'] ) ) {
				continue;
			}

			SVM_Fields::set_value( 'member', $member_id, (int) $def['id'], null );
		}

		foreach ( SVM_Mandates::for_member( $member_id ) as $mandate ) {
			SVM_DB::update(
				'mandates',
				(int) $mandate['id'],
				array(
					'iban'           => '',
					'bic'            => '',
					'account_holder' => __( 'anonymisiert', 'svm' ),
					'status'         => 'revoked',
				)
			);
		}

		SVM_Members::update(
			$member_id,
			array(
				'wp_user_id' => 0,
				'deleted_at' => SVM_DB::now(),
			)
		);

		SVM_Audit::log( 'member', $member_id, 'anonymized' );
	}

	/**
	 * Prüft, ob eine Feldfrist abgelaufen ist.
	 *
	 * @param array $member Mitglied.
	 * @param int   $months Frist in Monaten.
	 * @return bool
	 */
	private static function retention_expired( array $member, $months ) {
		$reference = ! empty( $member['left_at'] ) ? $member['left_at'] : $member['created_at'];
		$timestamp = strtotime( (string) $reference );

		if ( ! $timestamp ) {
			return false;
		}

		return strtotime( '+' . $months . ' months', $timestamp ) < time();
	}
}
