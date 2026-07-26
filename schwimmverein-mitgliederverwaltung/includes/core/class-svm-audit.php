<?php
/**
 * Änderungsprotokoll.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Protokolliert Änderungen an Stamm-, Finanz- und Konfigurationsdaten.
 */
class SVM_Audit {

	/**
	 * Schreibt einen Protokolleintrag.
	 *
	 * @param string $entity_type Entitätstyp.
	 * @param int    $entity_id   Datensatz-ID.
	 * @param string $action      Aktion.
	 * @param string $field_key   Betroffenes Feld.
	 * @param mixed  $old_value   Alter Wert.
	 * @param mixed  $new_value   Neuer Wert.
	 * @return void
	 */
	public static function log( $entity_type, $entity_id, $action, $field_key = '', $old_value = null, $new_value = null ) {
		SVM_DB::insert(
			'audit_log',
			array(
				'user_id'     => get_current_user_id(),
				'entity_type' => $entity_type,
				'entity_id'   => (int) $entity_id,
				'action'      => $action,
				'field_key'   => (string) $field_key,
				'old_value'   => self::stringify( $old_value ),
				'new_value'   => self::stringify( $new_value ),
				'created_at'  => SVM_DB::now(),
			)
		);
	}

	/**
	 * Protokolliert den Zugriff auf ein als sensibel markiertes Feld.
	 *
	 * @param string $entity_type Entitätstyp.
	 * @param int    $entity_id   Datensatz-ID.
	 * @param string $field_key   Feldschlüssel.
	 * @return void
	 */
	public static function log_sensitive_access( $entity_type, $entity_id, $field_key ) {
		self::log( $entity_type, $entity_id, 'sensitive_view', $field_key );
	}

	/**
	 * Wandelt Werte in eine speicherbare Zeichenkette.
	 *
	 * @param mixed $value Wert.
	 * @return string|null
	 */
	private static function stringify( $value ) {
		if ( null === $value ) {
			return null;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return wp_json_encode( $value );
		}
		return (string) $value;
	}

	/**
	 * Liest Protokolleinträge.
	 *
	 * @param array $args entity_type, entity_id, limit, offset.
	 * @return array
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'entity_type' => '',
				'entity_id'   => 0,
				'limit'       => 100,
				'offset'      => 0,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['entity_type'] ) {
			$where[]  = 'entity_type = %s';
			$params[] = $args['entity_type'];
		}

		if ( $args['entity_id'] > 0 ) {
			$where[]  = 'entity_id = %d';
			$params[] = (int) $args['entity_id'];
		}

		$sql      = 'SELECT * FROM ' . SVM_DB::table( 'audit_log' ) . ' WHERE ' . implode( ' AND ', $where ) .
			' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d';
		$params[] = (int) $args['limit'];
		$params[] = (int) $args['offset'];

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}
}
