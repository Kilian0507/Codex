<?php
/**
 * Bootstrap des Plugins.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verdrahtet alle Module mit WordPress.
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

		if ( is_admin() ) {
			add_action( 'admin_menu', array( 'SVM_Admin_Menu', 'register' ) );
			add_action( 'admin_init', array( 'SVM_Admin_Router', 'handle_post' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );
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
	 * Frontend-Registrierungen.
	 *
	 * @return void
	 */
	public function on_init() {
		SVM_Portal::register();
	}

	/**
	 * Admin-Styles.
	 *
	 * @param string $hook Aktuelle Adminseite.
	 * @return void
	 */
	public function admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'svm' ) ) {
			return;
		}
		wp_enqueue_style( 'svm-admin', SVM_PLUGIN_URL . 'assets/admin.css', array(), SVM_VERSION );
	}

	/**
	 * Frontend-Styles für das Mitgliederportal.
	 *
	 * @return void
	 */
	public function frontend_assets() {
		wp_register_style( 'svm-portal', SVM_PLUGIN_URL . 'assets/portal.css', array(), SVM_VERSION );
	}
}
