<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SwimTiming_Activator {

	public static function activate() {
		self::create_tables();
		self::backfill_team_positions();
		self::maybe_add_role_capability();

		if ( false === get_option( 'swimtiming_title' ) ) {
			add_option( 'swimtiming_title', __( 'Schwimmen Zeitnahme', 'swim-timing' ) );
		}
		if ( false === get_option( 'swimtiming_admin_role' ) ) {
			add_option( 'swimtiming_admin_role', 'administrator' );
		}

		update_option( 'swimtiming_db_version', SWIMTIMING_DB_VERSION );
	}

	public static function deactivate() {
		// Intentionally left blank: data is preserved on deactivation.
	}

	public static function maybe_upgrade() {
		if ( get_option( 'swimtiming_db_version' ) !== SWIMTIMING_DB_VERSION ) {
			self::create_tables();
			self::backfill_team_positions();
			update_option( 'swimtiming_db_version', SWIMTIMING_DB_VERSION );
		}
	}

	/**
	 * Startpersonen, die einer Staffel zugeordnet sind aber noch keine
	 * Position haben (team_position = 0 - z. B. weil sie vor der Einführung
	 * dieser Spalte angelegt wurden), bekommen hier nachträglich fortlaufend
	 * eine Position zugewiesen. Ohne das findet die Start-Kaskade bei
	 * bestehenden Startpersonen nie einen "Nächsten", weil alle auf 0
	 * stehen (0 ist nie größer als 0).
	 */
	public static function backfill_team_positions() {
		global $wpdb;
		$table = $wpdb->prefix . 'swimtiming_starters';

		foreach ( array( 'rot', 'gelb' ) as $team ) {
			$next = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(team_position) FROM {$table} WHERE team = %s", $team ) );
			$next++;

			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE team = %s AND team_position = 0 ORDER BY id ASC", $team ) );
			foreach ( $ids as $id ) {
				$wpdb->update( $table, array( 'team_position' => $next ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
				$next++;
			}
		}
	}

	private static function maybe_add_role_capability() {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( 'manage_swim_timing' ) ) {
			$role->add_cap( 'manage_swim_timing' );
		}
	}

	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$starters_table = $wpdb->prefix . 'swimtiming_starters';
		$splits_table   = $wpdb->prefix . 'swimtiming_splits';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql_starters = "CREATE TABLE {$starters_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			first_name VARCHAR(191) NOT NULL,
			last_name VARCHAR(191) NOT NULL,
			team VARCHAR(10) NOT NULL DEFAULT '',
			team_position INT UNSIGNED NOT NULL DEFAULT 0,
			report_time VARCHAR(20) DEFAULT NULL,
			start_time VARCHAR(20) DEFAULT NULL,
			end_time VARCHAR(20) DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY name_start (first_name, last_name, start_time)
		) {$charset_collate};";

		$sql_splits = "CREATE TABLE {$splits_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			starter_id BIGINT UNSIGNED NOT NULL,
			split_number INT UNSIGNED NOT NULL,
			split_time VARCHAR(20) DEFAULT NULL,
			comment TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY starter_id (starter_id)
		) {$charset_collate};";

		dbDelta( $sql_starters );
		dbDelta( $sql_splits );
	}
}
