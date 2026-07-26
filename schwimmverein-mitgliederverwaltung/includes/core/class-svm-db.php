<?php
/**
 * Datenbank-Hilfsfunktionen.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Kapselt Tabellennamen und wiederkehrende Abfragemuster.
 */
class SVM_DB {

	/**
	 * Liefert den vollständigen Tabellennamen.
	 *
	 * @param string $name Logischer Tabellenname ohne Präfix.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'svm_' . $name;
	}

	/**
	 * Fügt einen Datensatz ein und liefert die neue ID.
	 *
	 * @param string $table Logischer Tabellenname.
	 * @param array  $data  Spaltenwerte.
	 * @return int
	 */
	public static function insert( $table, array $data ) {
		global $wpdb;
		$wpdb->insert( self::table( $table ), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Aktualisiert einen Datensatz anhand seiner ID.
	 *
	 * @param string $table Logischer Tabellenname.
	 * @param int    $id    Datensatz-ID.
	 * @param array  $data  Spaltenwerte.
	 * @return int Anzahl geänderter Zeilen.
	 */
	public static function update( $table, $id, array $data ) {
		global $wpdb;
		return (int) $wpdb->update( self::table( $table ), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Löscht einen Datensatz anhand seiner ID.
	 *
	 * @param string $table Logischer Tabellenname.
	 * @param int    $id    Datensatz-ID.
	 * @return int
	 */
	public static function delete( $table, $id ) {
		global $wpdb;
		return (int) $wpdb->delete( self::table( $table ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Liefert einen einzelnen Datensatz anhand seiner ID.
	 *
	 * @param string $table Logischer Tabellenname.
	 * @param int    $id    Datensatz-ID.
	 * @return array|null
	 */
	public static function get( $table, $id ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . self::table( $table ) . ' WHERE id = %d';
		$row = $wpdb->get_row( $wpdb->prepare( $sql, (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? $row : null;
	}

	/**
	 * Liefert alle Datensätze einer Tabelle.
	 *
	 * @param string $table   Logischer Tabellenname.
	 * @param string $where   Optionale WHERE-Bedingung (bereits sicher zusammengesetzt).
	 * @param string $order_by Sortierung.
	 * @return array
	 */
	public static function all( $table, $where = '', $order_by = 'id ASC' ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . self::table( $table );
		if ( '' !== $where ) {
			$sql .= ' WHERE ' . $where;
		}
		$sql .= ' ORDER BY ' . $order_by;
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
		return $rows ? $rows : array();
	}

	/**
	 * Baut eine IN-Klausel mit Platzhaltern.
	 *
	 * @param array $ids Zahlenwerte.
	 * @return string
	 */
	public static function id_list( array $ids ) {
		$ids = array_map( 'intval', $ids );
		$ids = array_filter(
			$ids,
			static function ( $id ) {
				return $id > 0;
			}
		);

		return empty( $ids ) ? '0' : implode( ',', $ids );
	}

	/**
	 * Aktuelle Zeit im MySQL-Format.
	 *
	 * @return string
	 */
	public static function now() {
		return current_time( 'mysql' );
	}
}
