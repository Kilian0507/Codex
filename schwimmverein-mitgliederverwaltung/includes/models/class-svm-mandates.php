<?php
/**
 * SEPA-Mandate.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Je Mitglied beliebig viele Mandate — die Historie bleibt erhalten.
 */
class SVM_Mandates {

	/**
	 * Ein Mandat.
	 *
	 * @param int $id Mandats-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		return SVM_DB::get( 'mandates', $id );
	}

	/**
	 * Mandate eines Mitglieds.
	 *
	 * @param int  $member_id   Mitglieds-ID.
	 * @param bool $only_active Nur aktive.
	 * @return array
	 */
	public static function for_member( $member_id, $only_active = false ) {
		global $wpdb;

		$sql = 'SELECT * FROM ' . SVM_DB::table( 'mandates' ) . ' WHERE member_id = %d';
		if ( $only_active ) {
			$sql .= " AND status = 'active'";
		}
		$sql .= ' ORDER BY signed_at DESC, id DESC';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, (int) $member_id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Das aktive Mandat eines Mitglieds.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return array|null
	 */
	public static function active_for_member( $member_id ) {
		$mandates = self::for_member( $member_id, true );

		return empty( $mandates ) ? null : $mandates[0];
	}

	/**
	 * Speichert ein Mandat.
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
				'member_id'      => 0,
				'mandate_ref'    => '',
				'iban'           => '',
				'bic'            => '',
				'account_holder' => '',
				'signed_at'      => null,
				'sequence_type'  => 'FRST',
				'status'         => 'active',
			)
		);

		$data['iban'] = SVM_IBAN::normalize( $data['iban'] );

		if ( '' !== $data['iban'] && ! SVM_IBAN::is_valid( $data['iban'] ) ) {
			return new WP_Error( 'svm_mandate_iban', __( 'Die IBAN ist ungültig (Prüfziffer stimmt nicht).', 'svm' ) );
		}

		if ( '' === $data['mandate_ref'] ) {
			$data['mandate_ref'] = SVM_Number_Range::next( 'mandate' );
		}

		if ( '' === $data['account_holder'] && $data['member_id'] ) {
			$data['account_holder'] = SVM_Members::display_name( (int) $data['member_id'] );
		}

		if ( $id > 0 ) {
			SVM_DB::update( 'mandates', $id, $data );
		} else {
			$data['created_at'] = SVM_DB::now();
			$id                 = SVM_DB::insert( 'mandates', $data );
		}

		SVM_Audit::log( 'mandate', $id, 'saved', 'iban', '', SVM_IBAN::mask( $data['iban'] ) );

		return $id;
	}

	/**
	 * Setzt ein Mandat auf widerrufen.
	 *
	 * @param int $id Mandats-ID.
	 * @return void
	 */
	public static function revoke( $id ) {
		SVM_DB::update( 'mandates', $id, array( 'status' => 'revoked' ) );
		SVM_Audit::log( 'mandate', $id, 'revoked' );
	}

	/**
	 * Widerruft alle Mandate eines Mitglieds.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return void
	 */
	public static function revoke_for_member( $member_id ) {
		foreach ( self::for_member( $member_id, true ) as $mandate ) {
			self::revoke( (int) $mandate['id'] );
		}
	}

	/**
	 * Markiert ein Mandat als verwendet und schaltet FRST auf RCUR.
	 *
	 * @param int    $id   Mandats-ID.
	 * @param string $date Verwendungsdatum.
	 * @return void
	 */
	public static function mark_used( $id, $date ) {
		$mandate = self::get( $id );
		if ( ! $mandate ) {
			return;
		}

		$update = array( 'last_used_at' => $date );

		if ( 'FRST' === $mandate['sequence_type'] ) {
			$update['sequence_type'] = 'RCUR';
		}

		if ( 'FNAL' === $mandate['sequence_type'] || 'OOFF' === $mandate['sequence_type'] ) {
			$update['status'] = 'expired';
		}

		SVM_DB::update( 'mandates', $id, $update );
	}

	/**
	 * Setzt Mandate ohne Nutzung nach 36 Monaten auf abgelaufen.
	 *
	 * @return int Anzahl betroffener Mandate.
	 */
	public static function expire_stale() {
		global $wpdb;

		$limit = gmdate( 'Y-m-d', strtotime( '-36 months' ) );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . SVM_DB::table( 'mandates' ) . "
				WHERE status = 'active'
				AND ( (last_used_at IS NOT NULL AND last_used_at < %s)
					OR (last_used_at IS NULL AND signed_at IS NOT NULL AND signed_at < %s) )",
				$limit,
				$limit
			)
		); // phpcs:ignore WordPress.DB

		foreach ( (array) $ids as $id ) {
			SVM_DB::update( 'mandates', (int) $id, array( 'status' => 'expired' ) );
			SVM_Audit::log( 'mandate', (int) $id, 'expired' );
		}

		return count( (array) $ids );
	}

	/**
	 * Sequenztypen.
	 *
	 * @return array
	 */
	public static function sequence_types() {
		return array(
			'FRST' => __( 'FRST — Erstlastschrift', 'svm' ),
			'RCUR' => __( 'RCUR — Folgelastschrift', 'svm' ),
			'OOFF' => __( 'OOFF — einmalige Lastschrift', 'svm' ),
			'FNAL' => __( 'FNAL — letzte Lastschrift', 'svm' ),
		);
	}

	/**
	 * Statuswerte.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'active'  => __( 'aktiv', 'svm' ),
			'revoked' => __( 'widerrufen', 'svm' ),
			'expired' => __( 'abgelaufen', 'svm' ),
		);
	}
}
