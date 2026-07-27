<?php
/**
 * Manuell zugeordnete Beitragsarten je Mitglied.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ergänzt das Regelwerk: Ein Mitglied kann beliebig viele Beiträge zusätzlich
 * zugeordnet bekommen — oder einen einzelnen Beitrag ausgenommen bekommen,
 * obwohl die Regel greifen würde.
 */
class SVM_Member_Fees {

	/**
	 * Zuordnungsarten.
	 *
	 * @return array
	 */
	public static function modes() {
		return array(
			'include' => __( 'zusätzlich berechnen', 'svm' ),
			'exclude' => __( 'nicht berechnen', 'svm' ),
		);
	}

	/**
	 * Alle Zuordnungen eines Mitglieds.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return array
	 */
	public static function for_member( $member_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'member_fees' ) . ' WHERE member_id = %d ORDER BY id ASC',
				(int) $member_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Zuordnungen nach Art gruppiert.
	 *
	 * @param int    $member_id Mitglieds-ID.
	 * @param string $mode      include oder exclude.
	 * @return array fee_type_id => Zeile
	 */
	public static function by_mode( $member_id, $mode ) {
		$out = array();

		foreach ( self::for_member( $member_id ) as $row ) {
			if ( $row['mode'] === $mode ) {
				$out[ (int) $row['fee_type_id'] ] = $row;
			}
		}

		return $out;
	}

	/**
	 * Legt eine Zuordnung an oder aktualisiert sie.
	 *
	 * @param int    $member_id   Mitglieds-ID.
	 * @param int    $fee_type_id Beitragsart-ID.
	 * @param string $mode        include oder exclude.
	 * @param mixed  $amount      Abweichender Betrag oder null.
	 * @param string $note        Notiz.
	 * @return int
	 */
	public static function assign( $member_id, $fee_type_id, $mode = 'include', $amount = null, $note = '' ) {
		global $wpdb;

		$member_id   = (int) $member_id;
		$fee_type_id = (int) $fee_type_id;

		if ( $member_id <= 0 || $fee_type_id <= 0 ) {
			return 0;
		}

		$data = array(
			'member_id'       => $member_id,
			'fee_type_id'     => $fee_type_id,
			'mode'            => 'exclude' === $mode ? 'exclude' : 'include',
			'amount_override' => ( null === $amount || '' === $amount ) ? null : round( SVM_Fields::to_number( $amount ), 2 ),
			'note'            => $note,
		);

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . SVM_DB::table( 'member_fees' ) . ' WHERE member_id = %d AND fee_type_id = %d',
				$member_id,
				$fee_type_id
			)
		); // phpcs:ignore WordPress.DB

		if ( $existing ) {
			SVM_DB::update( 'member_fees', (int) $existing, $data );
			$id = (int) $existing;
		} else {
			$data['created_at'] = SVM_DB::now();
			$id                 = SVM_DB::insert( 'member_fees', $data );
		}

		$fee_type = SVM_Fee_Types::get( $fee_type_id );

		SVM_Audit::log(
			'member',
			$member_id,
			'fee_assigned',
			'fee_type',
			'',
			( $fee_type ? $fee_type['label'] : $fee_type_id ) . ' (' . $data['mode'] . ')'
		);

		return $id;
	}

	/**
	 * Entfernt eine Zuordnung.
	 *
	 * @param int $id Zuordnungs-ID.
	 * @return void
	 */
	public static function remove( $id ) {
		$row = SVM_DB::get( 'member_fees', $id );

		SVM_DB::delete( 'member_fees', $id );

		if ( $row ) {
			SVM_Audit::log( 'member', (int) $row['member_id'], 'fee_unassigned', 'fee_type', (string) $row['fee_type_id'] );
		}
	}

	/**
	 * Entfernt alle Zuordnungen eines Mitglieds.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return void
	 */
	public static function clear( $member_id ) {
		global $wpdb;

		$wpdb->delete( SVM_DB::table( 'member_fees' ), array( 'member_id' => (int) $member_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Zusammenfassung für die Mitgliedsansicht: alle Beitragsarten, die für
	 * dieses Mitglied greifen — aus Regeln und aus manueller Zuordnung.
	 *
	 * @param int    $member_id Mitglieds-ID.
	 * @param string $date      Stichtag.
	 * @return array Liste aus fee_type, source, amount.
	 */
	public static function effective( $member_id, $date = '' ) {
		$date    = '' !== $date ? $date : gmdate( 'Y-m-d' );
		$context = SVM_Members::context( $member_id, $date );

		if ( ! $context ) {
			return array();
		}

		$included = self::by_mode( $member_id, 'include' );
		$excluded = self::by_mode( $member_id, 'exclude' );
		$out      = array();

		foreach ( SVM_Fee_Types::valid_at( $date ) as $fee_type ) {
			$id = (int) $fee_type['id'];

			if ( isset( $excluded[ $id ] ) ) {
				continue;
			}

			$source = '';

			if ( isset( $included[ $id ] ) ) {
				$source = 'manual';
			} else {
				$rule = SVM_Fee_Types::condition_rule( $id );

				if ( ! $rule || SVM_Rule_Engine::matches( $rule, $context ) ) {
					$source = 'rule';
				}
			}

			if ( '' === $source ) {
				continue;
			}

			$amount = isset( $included[ $id ] ) && null !== $included[ $id ]['amount_override']
				? (float) $included[ $id ]['amount_override']
				: (float) $fee_type['amount'];

			$out[] = array(
				'fee_type' => $fee_type,
				'source'   => $source,
				'amount'   => $amount,
				'note'     => isset( $included[ $id ] ) ? $included[ $id ]['note'] : '',
			);
		}

		return $out;
	}
}
