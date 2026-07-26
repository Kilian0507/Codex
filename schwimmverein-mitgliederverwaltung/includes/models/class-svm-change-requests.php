<?php
/**
 * Freigabe-Workflow für Stammdatenänderungen.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ob ein Feld direkt geändert werden darf oder eine Freigabe braucht, steht in der Feldkonfiguration.
 */
class SVM_Change_Requests {

	/**
	 * Ein Antrag.
	 *
	 * @param int $id Antrags-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		return SVM_DB::get( 'change_requests', $id );
	}

	/**
	 * Legt einen Änderungsantrag an.
	 *
	 * @param int    $member_id Mitglieds-ID.
	 * @param int    $field_id  Feld-ID.
	 * @param string $new_value Neuer Wert.
	 * @return int
	 */
	public static function create( $member_id, $field_id, $new_value ) {
		$old = SVM_Fields::get_value_by_id( 'member', $member_id, $field_id );

		$id = SVM_DB::insert(
			'change_requests',
			array(
				'member_id'    => (int) $member_id,
				'field_id'     => (int) $field_id,
				'old_value'    => $old,
				'new_value'    => is_array( $new_value ) ? wp_json_encode( $new_value ) : $new_value,
				'status'       => 'pending',
				'requested_by' => get_current_user_id(),
				'requested_at' => SVM_DB::now(),
			)
		);

		SVM_Audit::log( 'member', $member_id, 'change_requested', (string) $field_id, $old, $new_value );

		do_action( 'svm_change_request_created', $id );

		return $id;
	}

	/**
	 * Offene Anträge.
	 *
	 * @param int $member_id Optional auf ein Mitglied begrenzen.
	 * @return array
	 */
	public static function pending( $member_id = 0 ) {
		global $wpdb;

		$sql    = 'SELECT * FROM ' . SVM_DB::table( 'change_requests' ) . ' WHERE status = %s';
		$params = array( 'pending' );

		if ( $member_id > 0 ) {
			$sql     .= ' AND member_id = %d';
			$params[] = (int) $member_id;
		}

		$sql .= ' ORDER BY requested_at ASC';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Anzahl offener Anträge.
	 *
	 * @return int
	 */
	public static function pending_count() {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . SVM_DB::table( 'change_requests' ) . ' WHERE status = %s',
				'pending'
			)
		); // phpcs:ignore WordPress.DB
	}

	/**
	 * Genehmigt einen Antrag und übernimmt den Wert.
	 *
	 * @param int    $id   Antrags-ID.
	 * @param string $note Notiz.
	 * @return void
	 */
	public static function approve( $id, $note = '' ) {
		$request = self::get( $id );

		if ( ! $request || 'pending' !== $request['status'] ) {
			return;
		}

		SVM_Fields::set_value( 'member', (int) $request['member_id'], (int) $request['field_id'], $request['new_value'] );

		SVM_DB::update(
			'change_requests',
			$id,
			array(
				'status'     => 'approved',
				'decided_by' => get_current_user_id(),
				'decided_at' => SVM_DB::now(),
				'note'       => $note,
			)
		);

		$def = SVM_Fields::get_def( (int) $request['field_id'] );

		SVM_Audit::log(
			'member',
			(int) $request['member_id'],
			'change_approved',
			$def ? $def['field_key'] : '',
			$request['old_value'],
			$request['new_value']
		);
	}

	/**
	 * Lehnt einen Antrag ab.
	 *
	 * @param int    $id   Antrags-ID.
	 * @param string $note Begründung.
	 * @return void
	 */
	public static function reject( $id, $note = '' ) {
		$request = self::get( $id );

		if ( ! $request || 'pending' !== $request['status'] ) {
			return;
		}

		SVM_DB::update(
			'change_requests',
			$id,
			array(
				'status'     => 'rejected',
				'decided_by' => get_current_user_id(),
				'decided_at' => SVM_DB::now(),
				'note'       => $note,
			)
		);

		SVM_Audit::log( 'member', (int) $request['member_id'], 'change_rejected', (string) $request['field_id'] );
	}
}
