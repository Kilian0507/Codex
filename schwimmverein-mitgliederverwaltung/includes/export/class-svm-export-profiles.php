<?php
/**
 * Export-Profile.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ein Profil beschreibt Datenbasis, Spalten, Filter, Format und Berechtigung.
 */
class SVM_Export_Profiles {

	/**
	 * Alle Profile.
	 *
	 * @param bool $only_active Nur aktive.
	 * @return array
	 */
	public static function all( $only_active = false ) {
		$where = $only_active ? 'is_active = 1' : '';
		return SVM_DB::all( 'export_profiles', $where, 'label ASC' );
	}

	/**
	 * Ein Profil.
	 *
	 * @param int $id Profil-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		return SVM_DB::get( 'export_profiles', $id );
	}

	/**
	 * Profile, die der aktuelle Benutzer ausführen darf.
	 *
	 * @return array
	 */
	public static function available() {
		$out      = array();
		$user_id  = get_current_user_id();
		$role_ids = SVM_Roles::user_role_ids( $user_id );
		$is_admin = user_can( $user_id, 'manage_options' );

		foreach ( self::all( true ) as $profile ) {
			$allowed = json_decode( (string) $profile['allowed_roles'], true );

			if ( $is_admin || empty( $allowed ) || ! is_array( $allowed ) ) {
				$out[] = $profile;
				continue;
			}

			if ( array_intersect( array_map( 'intval', $allowed ), $role_ids ) ) {
				$out[] = $profile;
			}
		}

		return $out;
	}

	/**
	 * Prüft, ob der aktuelle Benutzer ein Profil ausführen darf.
	 *
	 * @param array $profile Profil.
	 * @return bool
	 */
	public static function can_run( array $profile ) {
		if ( ! SVM_Permissions::current_user_can( 'export_run' ) ) {
			return false;
		}

		if ( user_can( get_current_user_id(), 'manage_options' ) ) {
			return true;
		}

		$allowed = json_decode( (string) $profile['allowed_roles'], true );

		if ( empty( $allowed ) || ! is_array( $allowed ) ) {
			return true;
		}

		return (bool) array_intersect(
			array_map( 'intval', $allowed ),
			SVM_Roles::user_role_ids( get_current_user_id() )
		);
	}

	/**
	 * Speichert ein Profil.
	 *
	 * @param array $data Daten.
	 * @return int
	 */
	public static function save( array $data ) {
		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		unset( $data['id'] );

		$data = wp_parse_args(
			$data,
			array(
				'label'          => '',
				'entity_type'    => 'member',
				'columns_json'   => '',
				'filter_rule_id' => 0,
				'format'         => 'csv',
				'delimiter'      => ';',
				'charset'        => 'UTF-8',
				'allowed_roles'  => '',
				'sort_column'    => '',
				'sort_direction' => 'ASC',
				'is_active'      => 1,
			)
		);

		if ( is_array( $data['columns_json'] ) ) {
			$data['columns_json'] = wp_json_encode( array_values( $data['columns_json'] ) );
		}

		if ( is_array( $data['allowed_roles'] ) ) {
			$data['allowed_roles'] = wp_json_encode( array_map( 'intval', $data['allowed_roles'] ) );
		}

		if ( $id > 0 ) {
			SVM_DB::update( 'export_profiles', $id, $data );
			return $id;
		}

		return SVM_DB::insert( 'export_profiles', $data );
	}

	/**
	 * Löscht ein Profil.
	 *
	 * @param int $id Profil-ID.
	 * @return void
	 */
	public static function delete( $id ) {
		SVM_DB::delete( 'export_profiles', $id );
	}

	/**
	 * Verfügbare Datenbasen.
	 *
	 * @return array
	 */
	public static function entity_types() {
		return array(
			'member'  => __( 'Mitglieder', 'svm' ),
			'invoice' => __( 'Forderungen', 'svm' ),
			'payment' => __( 'Zahlungen', 'svm' ),
			'mandate' => __( 'SEPA-Mandate', 'svm' ),
		);
	}

	/**
	 * Verfügbare Ausgabeformate.
	 *
	 * @return array
	 */
	public static function formats() {
		return array(
			'csv'  => __( 'CSV', 'svm' ),
			'xlsx' => __( 'Excel-kompatibles CSV (UTF-8 mit BOM)', 'svm' ),
			'json' => __( 'JSON', 'svm' ),
			'xml'  => __( 'XML', 'svm' ),
		);
	}

	/**
	 * Spalten eines Profils.
	 *
	 * @param array $profile Profil.
	 * @return array
	 */
	public static function columns( array $profile ) {
		$columns = json_decode( (string) $profile['columns_json'], true );

		if ( ! is_array( $columns ) || empty( $columns ) ) {
			return array_keys( SVM_Exporter::available_columns( $profile['entity_type'] ) );
		}

		return $columns;
	}
}
