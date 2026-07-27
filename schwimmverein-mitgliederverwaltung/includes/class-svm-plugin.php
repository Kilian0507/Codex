<?php
/**
 * Bootstrap des Plugins.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verdrahtet alle Module mit WordPress.
 *
 * Die gesamte Verwaltung läuft im Frontend auf der Seite mit dem Shortcode
 * [svm_app]; im WordPress-Adminbereich steht nur noch ein Verweis darauf.
 */
class SVM_Plugin {

	/**
	 * Singleton-Instanz.
	 *
	 * @var SVM_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Liefert die Instanz.
	 *
	 * @return SVM_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registriert die Hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
		add_action( 'init', array( $this, 'on_init' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( 'SVM_Admin_Menu', 'register' ) );
		}

		add_action( SVM_Cron::HOOK_DAILY, array( 'SVM_Cron', 'run_daily' ) );
		add_action( SVM_Cron::HOOK_MAIL, array( 'SVM_Cron', 'run_mail_queue' ) );
	}

	/**
	 * Textdomain, Migration und Rechte-Katalog.
	 *
	 * @return void
	 */
	public function on_plugins_loaded() {
		load_plugin_textdomain( 'svm', false, dirname( plugin_basename( SVM_PLUGIN_FILE ) ) . '/languages' );
		SVM_Installer::maybe_migrate();
		SVM_Permissions::register_catalog();
	}

	/**
	 * Shortcode und Formularverarbeitung.
	 *
	 * @return void
	 */
	public function on_init() {
		SVM_App::register();
		SVM_Router::register();
	}

	/**
	 * Stylesheet der Anwendung bereitstellen.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style( 'svm-app', SVM_PLUGIN_URL . 'assets/app.css', array(), SVM_VERSION );
	}
}
