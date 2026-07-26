<?php
/**
 * Rollenverwaltung.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rollen sind Datensätze, keine Programmkonstanten.
 */
class SVM_Roles {

	/**
	 * Alle Rollen.
	 *
	 * @return array
	 */
	public static function all() {
		return SVM_DB::all( 'roles', '', 'sort_order ASC, label ASC' );
	}

	/**
	 * Eine Rolle.
	 *
	 * @param int $id Rollen-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		return SVM_DB::get( 'roles', $id );
	}

	/**
	 * Rolle anhand ihres Schlüssels.
	 *
	 * @param string $key Rollenschlüssel.
	 * @return array|null
	 */
	public static function get_by_key( $key ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . SVM_DB::table( 'roles' ) . ' WHERE role_key = %s', $key ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB
		return $row ? $row : null;
	}

	/**
	 * Legt eine Rolle an oder aktualisiert sie.
	 *
	 * @param array $data        Rollendaten.
	 * @param array $permissions Rechteschlüssel.
	 * @return int Rollen-ID.
	 */
	public static function save( array $data, array $permissions = array() ) {
		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		unset( $data['id'] );

		$data = wp_parse_args(
			$data,
			array(
				'role_key'         => '',
				'label'            => '',
				'description'      => '',
				'scope'            => 'self',
				'include_subunits' => 1,
				'is_member_role'   => 0,
				'sort_order'       => 0,
			)
		);

		if ( '' === $data['role_key'] ) {
			$data['role_key'] = sanitize_key( $data['label'] );
		}

		if ( $id > 0 ) {
			SVM_DB::update( 'roles', $id, $data );
		} else {
			$id = SVM_DB::insert( 'roles', $data );
		}

		self::set_permissions( $id, $permissions );
		SVM_Permissions::flush_cache();

		return $id;
	}

	/**
	 * Löscht eine Rolle samt Zuordnungen.
	 *
	 * @param int $id Rollen-ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id = (int) $id;

		// Es muss immer eine Rolle geben, die Rollen verwalten darf.
		$guardians = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT role_id) FROM ' . SVM_DB::table( 'role_permissions' ) . '
				WHERE permission_key = %s AND role_id <> %d',
				'roles_manage',
				$id
			)
		); // phpcs:ignore WordPress.DB

		if ( 0 === $guardians && self::has_permission( $id, 'roles_manage' ) ) {
			return new WP_Error(
				'svm_last_admin_role',
				__( 'Die letzte Rolle mit dem Recht „Rollen und Rechte verwalten“ kann nicht gelöscht werden.', 'svm' )
			);
		}

		$wpdb->delete( SVM_DB::table( 'role_permissions' ), array( 'role_id' => $id ) ); // phpcs:ignore WordPress.DB
		$wpdb->delete( SVM_DB::table( 'user_roles' ), array( 'role_id' => $id ) ); // phpcs:ignore WordPress.DB
		$wpdb->delete( SVM_DB::table( 'field_visibility' ), array( 'role_id' => $id ) ); // phpcs:ignore WordPress.DB
		SVM_DB::delete( 'roles', $id );

		SVM_Permissions::flush_cache();

		return true;
	}

	/**
	 * Setzt die Rechte einer Rolle neu.
	 *
	 * @param int   $role_id     Rollen-ID.
	 * @param array $permissions Rechteschlüssel.
	 * @return void
	 */
	public static function set_permissions( $role_id, array $permissions ) {
		global $wpdb;

		$role_id = (int) $role_id;
		$valid   = SVM_Permissions::all_keys();

		$wpdb->delete( SVM_DB::table( 'role_permissions' ), array( 'role_id' => $role_id ) ); // phpcs:ignore WordPress.DB

		foreach ( array_unique( $permissions ) as $permission ) {
			if ( ! in_array( $permission, $valid, true ) ) {
				continue;
			}
			SVM_DB::insert(
				'role_permissions',
				array(
					'role_id'        => $role_id,
					'permission_key' => $permission,
				)
			);
		}
	}

	/**
	 * Rechte einer Rolle.
	 *
	 * @param int $role_id Rollen-ID.
	 * @return string[]
	 */
	public static function permissions( $role_id ) {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT permission_key FROM ' . SVM_DB::table( 'role_permissions' ) . ' WHERE role_id = %d',
				(int) $role_id
			)
		); // phpcs:ignore WordPress.DB
		return $rows ? $rows : array();
	}

	/**
	 * Prüft, ob eine Rolle ein Recht besitzt.
	 *
	 * @param int    $role_id    Rollen-ID.
	 * @param string $permission Rechteschlüssel.
	 * @return bool
	 */
	public static function has_permission( $role_id, $permission ) {
		return in_array( $permission, self::permissions( $role_id ), true );
	}

	/**
	 * Rollen eines Benutzers.
	 *
	 * @param int $user_id Benutzer-ID.
	 * @return int[]
	 */
	public static function user_role_ids( $user_id ) {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT role_id FROM ' . SVM_DB::table( 'user_roles' ) . ' WHERE user_id = %d',
				(int) $user_id
			)
		); // phpcs:ignore WordPress.DB
		return $rows ? array_map( 'intval', $rows ) : array();
	}

	/**
	 * Weist einem Benutzer Rollen zu.
	 *
	 * @param int   $user_id  Benutzer-ID.
	 * @param array $role_ids Rollen-IDs.
	 * @return void
	 */
	public static function assign_user( $user_id, array $role_ids ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$wpdb->delete( SVM_DB::table( 'user_roles' ), array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB

		foreach ( array_unique( array_map( 'intval', $role_ids ) ) as $role_id ) {
			if ( $role_id > 0 ) {
				SVM_DB::insert( 'user_roles', array( 'user_id' => $user_id, 'role_id' => $role_id ) );
			}
		}

		SVM_Permissions::flush_cache();
	}

	/**
	 * Setzt die Einheiten, auf die sich die Rollen eines Benutzers beziehen.
	 *
	 * @param int   $user_id  Benutzer-ID.
	 * @param array $unit_ids Einheiten-IDs.
	 * @return void
	 */
	public static function assign_user_units( $user_id, array $unit_ids ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$wpdb->delete( SVM_DB::table( 'user_units' ), array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB

		foreach ( array_unique( array_map( 'intval', $unit_ids ) ) as $unit_id ) {
			if ( $unit_id > 0 ) {
				SVM_DB::insert( 'user_units', array( 'user_id' => $user_id, 'unit_id' => $unit_id ) );
			}
		}
	}

	/**
	 * Einheiten eines Benutzers (ohne Vererbung).
	 *
	 * @param int $user_id Benutzer-ID.
	 * @return int[]
	 */
	public static function user_unit_ids( $user_id ) {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT unit_id FROM ' . SVM_DB::table( 'user_units' ) . ' WHERE user_id = %d',
				(int) $user_id
			)
		); // phpcs:ignore WordPress.DB
		return $rows ? array_map( 'intval', $rows ) : array();
	}

	/**
	 * Verfügbare Geltungsbereiche.
	 *
	 * @return array
	 */
	public static function scopes() {
		return array(
			'all'   => __( 'Alle Mitglieder (vereinsweit)', 'svm' ),
			'units' => __( 'Nur zugeordnete Sparten/Gruppen', 'svm' ),
			'group' => __( 'Nur eigene Gruppe', 'svm' ),
			'self'  => __( 'Nur eigener Datensatz', 'svm' ),
		);
	}
}
