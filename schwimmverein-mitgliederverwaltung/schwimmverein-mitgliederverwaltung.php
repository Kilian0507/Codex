<?php
/**
 * Plugin Name:       Schwimmverein Mitgliederverwaltung
 * Plugin URI:        https://github.com/Kilian0507/Codex
 * Description:       Vollständig konfigurierbare Mitgliederverwaltung für Vereine: frei definierbare Sparten, Stammdatenfelder, Rollen, Beitragsregeln, SEPA-Zahlläufe, manuelle Zahlungserfassung, Nachrichten und Exporte.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Verein
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       svm
 * Domain Path:       /languages
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

define( 'SVM_VERSION', '0.1.0' );
define( 'SVM_DB_VERSION', 4 );
define( 'SVM_PLUGIN_FILE', __FILE__ );
define( 'SVM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SVM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SVM_PLUGIN_DIR . 'includes/class-svm-autoloader.php';
SVM_Autoloader::register();

register_activation_hook( __FILE__, array( 'SVM_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SVM_Installer', 'deactivate' ) );

/**
 * Zentraler Einstiegspunkt.
 *
 * @return SVM_Plugin
 */
function svm() {
	return SVM_Plugin::instance();
}

svm()->boot();
