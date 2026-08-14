<?php
/**
 * Plugin Name: Swim Timing
 * Description: Frontend-Zeitnahme-Plugin für Schwimmveranstaltungen. Ein Shortcode zeigt je nach Berechtigung einen Adminbereich (Startpersonen &amp; Zwischenzeiten verwalten) oder einen öffentlichen Abfragebereich (eigene Zeiten ansehen &amp; als PDF herunterladen).
 * Version: 1.7.0
 * Author: Swim Timing
 * Text Domain: swim-timing
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SWIMTIMING_VERSION', '1.7.0' );
define( 'SWIMTIMING_FILE', __FILE__ );
define( 'SWIMTIMING_PATH', plugin_dir_path( __FILE__ ) );
define( 'SWIMTIMING_URL', plugin_dir_url( __FILE__ ) );
define( 'SWIMTIMING_DB_VERSION', '1.3' );

require_once SWIMTIMING_PATH . 'includes/class-swimtiming-activator.php';
require_once SWIMTIMING_PATH . 'includes/class-swimtiming-settings.php';
require_once SWIMTIMING_PATH . 'includes/class-swimtiming-db.php';
require_once SWIMTIMING_PATH . 'includes/class-swimtiming-csv.php';
require_once SWIMTIMING_PATH . 'includes/class-swimtiming-pdf.php';
require_once SWIMTIMING_PATH . 'includes/class-swimtiming-ajax.php';
require_once SWIMTIMING_PATH . 'includes/class-swimtiming-shortcode.php';

register_activation_hook( __FILE__, array( 'SwimTiming_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SwimTiming_Activator', 'deactivate' ) );

add_action( 'plugins_loaded', 'swimtiming_init' );

function swimtiming_init() {
	load_plugin_textdomain( 'swim-timing', false, dirname( plugin_basename( SWIMTIMING_FILE ) ) . '/languages' );

	SwimTiming_Activator::maybe_upgrade();

	new SwimTiming_Settings();
	new SwimTiming_Ajax();
	new SwimTiming_Shortcode();
}
