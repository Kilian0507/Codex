<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SwimTiming_Activator {

	public static function activate() {
		self::create_tables();
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
			update_option( 'swimtiming_db_version', SWIMTIMING_DB_VERSION );
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
