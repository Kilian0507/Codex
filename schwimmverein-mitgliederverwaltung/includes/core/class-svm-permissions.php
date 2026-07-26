<?php
/**
 * Rechte-Registry und Zugriffsprüfung.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Der Katalog der möglichen Rechte ist fest, die Zuordnung zu Rollen ist frei.
 */
class SVM_Permissions {

	/**
	 * Rechtekatalog, gruppiert.
	 *
	 * @var array|null
	 */
	private static $catalog = null;

	/**
	 * Zwischenspeicher der Rechte je Benutzer.
	 *
	 * @var array
	 */
	private static $user_cache = array();

	/**
	 * Baut den Rechtekatalog auf.
	 *
	 * @return void
	 */
	public static function register_catalog() {
		self::$catalog = null;
		self::catalog();
	}

	/**
	 * Liefert den Rechtekatalog.
	 *
	 * @return array Gruppenlabel => array( key => label ).
	 */
	public static function catalog() {
		if ( null !== self::$catalog ) {
			return self::$catalog;
		}

		$catalog = array(
			__( 'Mitglieder', 'svm' )      => array(
				'members_view'      => __( 'Mitglieder anzeigen', 'svm' ),
				'members_edit'      => __( 'Mitglieder bearbeiten', 'svm' ),
				'members_create'    => __( 'Mitglieder anlegen', 'svm' ),
				'members_delete'    => __( 'Mitglieder löschen', 'svm' ),
				'members_status'    => __( 'Mitgliedsstatus ändern', 'svm' ),
				'bank_data_view'    => __( 'Bankdaten anzeigen', 'svm' ),
				'change_requests'   => __( 'Änderungsanträge freigeben', 'svm' ),
			),
			__( 'Struktur', 'svm' )        => array(
				'units_view'   => __( 'Sparten/Gruppen anzeigen', 'svm' ),
				'units_manage' => __( 'Sparten/Gruppen verwalten', 'svm' ),
			),
			__( 'Beiträge', 'svm' )        => array(
				'fees_view'     => __( 'Beitragsarten anzeigen', 'svm' ),
				'fees_manage'   => __( 'Beitragsarten verwalten', 'svm' ),
				'fee_run'       => __( 'Beitragslauf ausführen', 'svm' ),
				'invoices_view' => __( 'Forderungen anzeigen', 'svm' ),
				'invoices_edit' => __( 'Forderungen bearbeiten/stornieren', 'svm' ),
			),
			__( 'Zahlungen', 'svm' )       => array(
				'payments_view'    => __( 'Zahlungen anzeigen', 'svm' ),
				'payments_record'  => __( 'Zahlung erfassen', 'svm' ),
				'mandates_manage'  => __( 'SEPA-Mandate verwalten', 'svm' ),
				'payment_run'      => __( 'Zahldatei erzeugen', 'svm' ),
				'dunning_manage'   => __( 'Mahnwesen verwalten', 'svm' ),
			),
			__( 'Nachrichten', 'svm' )     => array(
				'messages_view'   => __( 'Nachrichten anzeigen', 'svm' ),
				'messages_manage' => __( 'Nachrichten verfassen', 'svm' ),
			),
			__( 'Auswertungen', 'svm' )    => array(
				'export_run'    => __( 'Export ausführen', 'svm' ),
				'export_manage' => __( 'Export-Profile verwalten', 'svm' ),
				'import_run'    => __( 'Daten importieren', 'svm' ),
				'audit_view'    => __( 'Änderungsprotokoll einsehen', 'svm' ),
			),
			__( 'Konfiguration', 'svm' )   => array(
				'fields_manage'   => __( 'Felder verwalten', 'svm' ),
				'roles_manage'    => __( 'Rollen und Rechte verwalten', 'svm' ),
				'settings_manage' => __( 'Grundeinstellungen verwalten', 'svm' ),
			),
			__( 'Mitgliederportal', 'svm' ) => array(
				'portal_access'      => __( 'Portal nutzen', 'svm' ),
				'own_profile_edit'   => __( 'Eigene Stammdaten ändern', 'svm' ),
				'own_finance_view'   => __( 'Eigene Beiträge und Zahlungen sehen', 'svm' ),
			),
		);

		/**
		 * Erlaubt Erweiterungen, eigene Rechte zu registrieren.
		 *
		 * @param array $catalog Rechtekatalog.
		 */
		self::$catalog = apply_filters( 'svm_permission_catalog', $catalog );

		return self::$catalog;
	}

	/**
	 * Flache Liste aller Rechteschlüssel.
	 *
	 * @return string[]
	 */
	public static function all_keys() {
		$keys = array();
		foreach ( self::catalog() as $group ) {
			$keys = array_merge( $keys, array_keys( $group ) );
		}
		return $keys;
	}

	/**
	 * Lesbarer Name eines Rechts.
	 *
	 * @param string $key Rechteschlüssel.
	 * @return string
	 */
	public static function label( $key ) {
		foreach ( self::catalog() as $group ) {
			if ( isset( $group[ $key ] ) ) {
				return $group[ $key ];
			}
		}
		return $key;
	}

	/**
	 * Prüft ein Recht für den aktuellen Benutzer.
	 *
	 * @param string $permission Rechteschlüssel.
	 * @return bool
	 */
	public static function current_user_can( $permission ) {
		return self::user_can( get_current_user_id(), $permission );
	}

	/**
	 * Prüft ein Recht für einen Benutzer.
	 *
	 * @param int    $user_id    Benutzer-ID.
	 * @param string $permission Rechteschlüssel.
	 * @return bool
	 */
	public static function user_can( $user_id, $permission ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		$permissions = self::user_permissions( $user_id );

		return in_array( $permission, $permissions, true );
	}

	/**
	 * Alle Rechte eines Benutzers aus seinen Rollen.
	 *
	 * @param int $user_id Benutzer-ID.
	 * @return string[]
	 */
	public static function user_permissions( $user_id ) {
		$user_id = (int) $user_id;

		if ( isset( self::$user_cache[ $user_id ] ) ) {
			return self::$user_cache[ $user_id ];
		}

		global $wpdb;
		$sql = 'SELECT rp.permission_key FROM ' . SVM_DB::table( 'role_permissions' ) . ' rp
			INNER JOIN ' . SVM_DB::table( 'user_roles' ) . ' ur ON ur.role_id = rp.role_id
			WHERE ur.user_id = %d';

		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $user_id ) ); // phpcs:ignore WordPress.DB

		self::$user_cache[ $user_id ] = $rows ? array_values( array_unique( $rows ) ) : array();

		return self::$user_cache[ $user_id ];
	}

	/**
	 * Leert den Rechte-Zwischenspeicher.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$user_cache = array();
	}

	/**
	 * Bricht mit Fehlermeldung ab, wenn das Recht fehlt.
	 *
	 * @param string $permission Rechteschlüssel.
	 * @return void
	 */
	public static function require_permission( $permission ) {
		if ( ! self::current_user_can( $permission ) ) {
			wp_die( esc_html__( 'Für diese Aktion fehlt die Berechtigung.', 'svm' ), 403 );
		}
	}

	/* ---------------------------------------------------------------------
	 * Geltungsbereich (Scope)
	 * ------------------------------------------------------------------ */

	/**
	 * Weitester Geltungsbereich eines Benutzers über alle seine Rollen.
	 *
	 * @param int $user_id Benutzer-ID.
	 * @return string all|units|group|self|none
	 */
	public static function user_scope( $user_id ) {
		$user_id = (int) $user_id;

		if ( user_can( $user_id, 'manage_options' ) ) {
			return 'all';
		}

		global $wpdb;
		$sql = 'SELECT r.scope FROM ' . SVM_DB::table( 'roles' ) . ' r
			INNER JOIN ' . SVM_DB::table( 'user_roles' ) . ' ur ON ur.role_id = r.id
			WHERE ur.user_id = %d';

		$scopes = $wpdb->get_col( $wpdb->prepare( $sql, $user_id ) ); // phpcs:ignore WordPress.DB
		if ( empty( $scopes ) ) {
			return 'none';
		}

		$ranking = array( 'none' => 0, 'self' => 1, 'group' => 2, 'units' => 3, 'all' => 4 );
		$best    = 'none';

		foreach ( $scopes as $scope ) {
			if ( isset( $ranking[ $scope ] ) && $ranking[ $scope ] > $ranking[ $best ] ) {
				$best = $scope;
			}
		}

		return $best;
	}

	/**
	 * Einheiten, auf die ein Benutzer Zugriff hat (inkl. Untereinheiten, falls konfiguriert).
	 *
	 * @param int $user_id Benutzer-ID.
	 * @return int[]
	 */
	public static function accessible_unit_ids( $user_id ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$sql     = 'SELECT unit_id FROM ' . SVM_DB::table( 'user_units' ) . ' WHERE user_id = %d';
		$units   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $user_id ) ) ); // phpcs:ignore WordPress.DB

		if ( empty( $units ) ) {
			return array();
		}

		$include_sub = (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(r.include_subunits) FROM ' . SVM_DB::table( 'roles' ) . ' r
				INNER JOIN ' . SVM_DB::table( 'user_roles' ) . ' ur ON ur.role_id = r.id
				WHERE ur.user_id = %d',
				$user_id
			)
		); // phpcs:ignore WordPress.DB

		if ( $include_sub ) {
			$units = SVM_Units::with_descendants( $units );
		}

		return $units;
	}

	/**
	 * Prüft, ob der aktuelle Benutzer auf einen Mitgliedsdatensatz zugreifen darf.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return bool
	 */
	public static function can_access_member( $member_id ) {
		$user_id = get_current_user_id();
		$scope   = self::user_scope( $user_id );

		if ( 'all' === $scope ) {
			return true;
		}

		if ( 'none' === $scope ) {
			return false;
		}

		$member = SVM_Members::get( $member_id );
		if ( ! $member ) {
			return false;
		}

		if ( (int) $member['wp_user_id'] === (int) $user_id ) {
			return true;
		}

		if ( 'self' === $scope ) {
			return false;
		}

		$units = self::accessible_unit_ids( $user_id );
		if ( empty( $units ) ) {
			return false;
		}

		global $wpdb;
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . SVM_DB::table( 'member_units' ) . '
				WHERE member_id = %d AND is_active = 1 AND unit_id IN (' . SVM_DB::id_list( $units ) . ')',
				(int) $member_id
			)
		); // phpcs:ignore WordPress.DB

		return $found > 0;
	}

	/**
	 * SQL-Bedingung, die eine Mitgliederabfrage auf den Geltungsbereich einschränkt.
	 *
	 * @param string $alias Tabellenalias der Mitgliedertabelle.
	 * @return string
	 */
	public static function member_scope_sql( $alias = 'm' ) {
		$user_id = get_current_user_id();
		$scope   = self::user_scope( $user_id );

		if ( 'all' === $scope ) {
			return '1=1';
		}

		if ( 'none' === $scope ) {
			return '1=0';
		}

		if ( 'self' === $scope ) {
			return $alias . '.wp_user_id = ' . (int) $user_id;
		}

		$units = self::accessible_unit_ids( $user_id );
		if ( empty( $units ) ) {
			return $alias . '.wp_user_id = ' . (int) $user_id;
		}

		return '(' . $alias . '.wp_user_id = ' . (int) $user_id . ' OR ' . $alias . '.id IN (
			SELECT mu.member_id FROM ' . SVM_DB::table( 'member_units' ) . ' mu
			WHERE mu.is_active = 1 AND mu.unit_id IN (' . SVM_DB::id_list( $units ) . ')
		))';
	}
}
