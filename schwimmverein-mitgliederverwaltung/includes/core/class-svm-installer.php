<?php
/**
 * Installation und Schema-Migration.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Legt Tabellen an und schreibt das Schema bei Updates fort.
 */
class SVM_Installer {

	const VERSION_OPTION = 'svm_db_version';
	const SEEDED_OPTION  = 'svm_seeded';

	/**
	 * Aktivierungshook.
	 *
	 * @return void
	 */
	public static function activate() {
		self::migrate();

		if ( ! get_option( self::SEEDED_OPTION ) ) {
			SVM_Default_Config::seed();
			update_option( self::SEEDED_OPTION, 1 );
		}

		SVM_Cron::schedule();
		flush_rewrite_rules();
	}

	/**
	 * Deaktivierungshook.
	 *
	 * @return void
	 */
	public static function deactivate() {
		SVM_Cron::unschedule();
		flush_rewrite_rules();
	}

	/**
	 * Führt die Migration aus, falls die Schemaversion veraltet ist.
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) < SVM_DB_VERSION ) {
			self::migrate();
		}
	}

	/**
	 * Legt alle Tabellen an bzw. aktualisiert sie.
	 *
	 * @return void
	 */
	public static function migrate() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( SVM_Schema::tables() as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::VERSION_OPTION, SVM_DB_VERSION );
	}
}
