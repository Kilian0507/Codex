<?php
/**
 * Mitgliedsstatus und Lebenszyklus.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Statuswerte und erlaubte Übergänge sind vollständig konfigurierbar.
 */
class SVM_Statuses {

	/**
	 * Alle Statuswerte.
	 *
	 * @return array
	 */
	public static function all() {
		return SVM_DB::all( 'statuses', '', 'sort_order ASC, id ASC' );
	}

	/**
	 * Ein Statuswert.
	 *
	 * @param int $id Status-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		return SVM_DB::get( 'statuses', $id );
	}

	/**
	 * Status anhand des Schlüssels.
	 *
	 * @param string $key Statusschlüssel.
	 * @return array|null
	 */
	public static function get_by_key( $key ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . SVM_DB::table( 'statuses' ) . ' WHERE status_key = %s', $key ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB
		return $row ? $row : null;
	}

	/**
	 * Standardstatus für neue Mitglieder.
	 *
	 * @return array|null
	 */
	public static function get_default() {
		global $wpdb;
		$row = $wpdb->get_row(
			'SELECT * FROM ' . SVM_DB::table( 'statuses' ) . ' WHERE is_default = 1 ORDER BY sort_order ASC LIMIT 1',
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		if ( $row ) {
			return $row;
		}

		$all = self::all();
		return empty( $all ) ? null : $all[0];
	}

	/**
	 * Auswahlliste.
	 *
	 * @return array
	 */
	public static function options() {
		$out = array();
		foreach ( self::all() as $status ) {
			$out[ (int) $status['id'] ] = $status['label'];
		}
		return $out;
	}

	/**
	 * Speichert einen Status.
	 *
	 * @param array $data Daten.
	 * @return int
	 */
	public static function save( array $data ) {
		global $wpdb;

		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		unset( $data['id'] );

		$data = wp_parse_args(
			$data,
			array(
				'status_key'       => '',
				'label'            => '',
				'color'            => '',
				'counts_as_active' => 1,
				'is_billable'      => 1,
				'allows_login'     => 1,
				'is_default'       => 0,
				'sort_order'       => 0,
			)
		);

		if ( '' === $data['status_key'] ) {
			$data['status_key'] = sanitize_key( $data['label'] );
		}

		if ( $id > 0 ) {
			SVM_DB::update( 'statuses', $id, $data );
		} else {
			$id = SVM_DB::insert( 'statuses', $data );
		}

		// Es darf nur einen Standardstatus geben.
		if ( ! empty( $data['is_default'] ) ) {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . SVM_DB::table( 'statuses' ) . ' SET is_default = 0 WHERE id <> %d',
					$id
				)
			); // phpcs:ignore WordPress.DB
		}

		return $id;
	}

	/**
	 * Löscht einen Status, sofern er nicht in Verwendung ist.
	 *
	 * @param int $id Status-ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id    = (int) $id;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . SVM_DB::table( 'members' ) . ' WHERE status_id = %d', $id )
		); // phpcs:ignore WordPress.DB

		if ( $count > 0 ) {
			return new WP_Error(
				'svm_status_in_use',
				sprintf(
					/* translators: %d: Anzahl Mitglieder. */
					__( 'Dieser Status ist noch %d Mitgliedern zugeordnet und kann nicht gelöscht werden.', 'svm' ),
					$count
				)
			);
		}

		$wpdb->delete( SVM_DB::table( 'status_transitions' ), array( 'from_status_id' => $id ) ); // phpcs:ignore WordPress.DB
		$wpdb->delete( SVM_DB::table( 'status_transitions' ), array( 'to_status_id' => $id ) ); // phpcs:ignore WordPress.DB
		SVM_DB::delete( 'statuses', $id );

		return true;
	}

	/**
	 * Erlaubte Folgestatus.
	 *
	 * @param int $from_status_id Ausgangsstatus.
	 * @return array
	 */
	public static function transitions( $from_status_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'status_transitions' ) . ' WHERE from_status_id = %d',
				(int) $from_status_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Prüft, ob ein Statuswechsel erlaubt ist.
	 *
	 * Sind keine Übergänge definiert, ist jeder Wechsel erlaubt.
	 *
	 * @param int $from Ausgangsstatus.
	 * @param int $to   Zielstatus.
	 * @return bool
	 */
	public static function transition_allowed( $from, $to ) {
		global $wpdb;

		$from = (int) $from;
		$to   = (int) $to;

		if ( $from === $to || 0 === $from ) {
			return true;
		}

		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SVM_DB::table( 'status_transitions' ) ); // phpcs:ignore WordPress.DB
		if ( 0 === $total ) {
			return true;
		}

		$found = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . SVM_DB::table( 'status_transitions' ) . '
				WHERE from_status_id = %d AND to_status_id = %d',
				$from,
				$to
			)
		); // phpcs:ignore WordPress.DB

		return $found > 0;
	}

	/**
	 * Speichert einen Übergang.
	 *
	 * @param array $data Daten.
	 * @return int
	 */
	public static function save_transition( array $data ) {
		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		unset( $data['id'] );

		$data = wp_parse_args(
			$data,
			array(
				'from_status_id'  => 0,
				'to_status_id'    => 0,
				'requires_reason' => 0,
				'requires_date'   => 0,
				'on_transition'   => '',
			)
		);

		if ( $id > 0 ) {
			SVM_DB::update( 'status_transitions', $id, $data );
			return $id;
		}

		return SVM_DB::insert( 'status_transitions', $data );
	}

	/**
	 * Löscht einen Statusübergang.
	 *
	 * @param int $id Übergangs-ID.
	 * @return void
	 */
	public static function delete_transition( $id ) {
		SVM_DB::delete( 'status_transitions', $id );
		SVM_Audit::log( 'status_transition', (int) $id, 'deleted' );
	}

	/**
	 * Aktionen, die bei einem Statuswechsel ausgelöst werden können.
	 *
	 * @return array
	 */
	public static function transition_actions() {
		return apply_filters(
			'svm_status_transition_actions',
			array(
				''                 => __( '— keine —', 'svm' ),
				'cancel_invoices'  => __( 'Offene Forderungen stornieren', 'svm' ),
				'revoke_mandates'  => __( 'SEPA-Mandate deaktivieren', 'svm' ),
				'disable_login'    => __( 'Portalzugang sperren', 'svm' ),
			)
		);
	}

	/**
	 * Führt die konfigurierte Aktion eines Übergangs aus.
	 *
	 * @param string $action    Aktionsschlüssel.
	 * @param int    $member_id Mitglieds-ID.
	 * @return void
	 */
	public static function run_transition_action( $action, $member_id ) {
		switch ( $action ) {
			case 'cancel_invoices':
				SVM_Invoices::cancel_open_for_member( $member_id );
				break;

			case 'revoke_mandates':
				SVM_Mandates::revoke_for_member( $member_id );
				break;

			case 'disable_login':
				$member = SVM_Members::get( $member_id );
				if ( $member && $member['wp_user_id'] ) {
					delete_user_meta( (int) $member['wp_user_id'], 'svm_portal_enabled' );
				}
				break;
		}

		do_action( 'svm_status_transition_action', $action, $member_id );
	}
}
