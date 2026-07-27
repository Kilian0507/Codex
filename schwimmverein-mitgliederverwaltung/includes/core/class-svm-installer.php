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
	const MISSING_OPTION = 'svm_missing_tables';

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
	 * Die Anweisungen werden als Array übergeben. Eine Zeichenkette würde
	 * dbDelta() an jedem Semikolon zerlegen — auch an einem, das in einem
	 * Spaltenstandardwert steht.
	 *
	 * @return void
	 */
	public static function migrate() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( SVM_Schema::tables() );

		update_option( self::VERSION_OPTION, SVM_DB_VERSION );

		// Ergebnis festhalten, damit ein stiller Fehlschlag sichtbar wird.
		update_option( self::MISSING_OPTION, self::missing_tables() );

		// Bei Bestandsinstallationen nachziehen, was zuvor nicht angelegt werden konnte.
		if ( get_option( self::SEEDED_OPTION ) ) {
			SVM_Default_Config::repair();
		}
	}

	/**
	 * Tabellen, die beim letzten Lauf gefehlt haben.
	 *
	 * @return string[]
	 */
	public static function known_missing() {
		$missing = get_option( self::MISSING_OPTION, array() );

		return is_array( $missing ) ? $missing : array();
	}

	/**
	 * Prüft, ob eine Tabelle des Plugins vorhanden ist.
	 *
	 * @param string $table Logischer Tabellenname.
	 * @return bool
	 */
	public static function table_exists( $table ) {
		global $wpdb;

		$name  = SVM_DB::table( $table );
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ); // phpcs:ignore WordPress.DB

		return $name === $found;
	}

	/**
	 * Listet Tabellen des Plugins, die fehlen.
	 *
	 * @return string[]
	 */
	public static function missing_tables() {
		$missing = array();

		foreach ( SVM_Schema::table_names() as $table ) {
			if ( ! self::table_exists( $table ) ) {
				$missing[] = $table;
			}
		}

		return $missing;
	}
}
