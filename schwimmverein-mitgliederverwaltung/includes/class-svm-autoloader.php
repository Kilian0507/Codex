<?php
/**
 * Klassen-Autoloader.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lädt Klassen nach der Konvention SVM_Foo_Bar => class-svm-foo-bar.php.
 */
class SVM_Autoloader {

	/**
	 * Verzeichnisse, die nach Klassendateien durchsucht werden.
	 *
	 * @var string[]
	 */
	private static $paths = array(
		'includes/core/',
		'includes/models/',
		'includes/engine/',
		'includes/payments/',
		'includes/export/',
		'includes/frontend/',
		'includes/frontend/views/',
		'includes/admin/',
		'includes/',
	);

	/**
	 * Registriert den Autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Lädt eine Klasse.
	 *
	 * @param string $class Klassenname.
	 * @return void
	 */
	public static function load( $class ) {
		if ( 0 !== strpos( $class, 'SVM_' ) ) {
			return;
		}

		$file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

		foreach ( self::$paths as $path ) {
			$candidate = SVM_PLUGIN_DIR . $path . $file;
			if ( is_readable( $candidate ) ) {
				require_once $candidate;
				return;
			}
		}
	}
}
