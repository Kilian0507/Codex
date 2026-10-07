<?php
/**
 * Plugin Name: LSV07 Abrechnung
 * Description: Quartalsabrechnung für Trainerinnen und Trainer — Training, Wettkämpfe, Fahrtkosten, Vorbereitung und Sonstiges. Greift lesend auf die Daten des internen Bereichs zu (Anwesenheit, Wettkämpfe, Mannschaften) und läuft parallel dazu.
 * Version:     1.1.3
 * Author:      LSV07
 * Text Domain: lsv07-abrechnung
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LSV07A_VERSION', '1.1.3' );
define( 'LSV07A_DIR',     plugin_dir_path( __FILE__ ) );
define( 'LSV07A_URL',     plugin_dir_url( __FILE__ ) );

require_once LSV07A_DIR . 'includes/class-db.php';
require_once LSV07A_DIR . 'includes/class-log.php';
require_once LSV07A_DIR . 'includes/class-rollen.php';
require_once LSV07A_DIR . 'includes/class-access.php';
require_once LSV07A_DIR . 'includes/class-intern.php';
require_once LSV07A_DIR . 'includes/class-berechnung.php';
require_once LSV07A_DIR . 'includes/class-ajax-abrechnung.php';
require_once LSV07A_DIR . 'includes/class-ajax-pruefung.php';
require_once LSV07A_DIR . 'includes/class-ajax-kasse.php';
require_once LSV07A_DIR . 'includes/class-ajax-admin.php';
require_once LSV07A_DIR . 'includes/class-ajax-statistik.php';
require_once LSV07A_DIR . 'includes/class-shortcode.php';

/* Beim Aktivieren die eigenen Tabellen anlegen. Zusätzlich bei jedem Laden
   nachsehen — so kommt eine neue Fassung auch ohne Neuaktivierung an ihre
   Tabellen, und ein fehlgeschlagenes CREATE beim Aktivieren heilt von
   selbst. Das Prüfen kostet eine Abfrage und läuft nur bei Versionswechsel
   wirklich los. */
register_activation_hook( __FILE__, [ 'LSV07A_DB', 'install' ] );

add_action( 'plugins_loaded', function () {
    if ( get_option( 'lsv07a_db_ver' ) !== LSV07A_VERSION ) {
        LSV07A_DB::install();
        update_option( 'lsv07a_db_ver', LSV07A_VERSION );
    }

    LSV07A_Ajax_Abrechnung::init();
    LSV07A_Ajax_Pruefung::init();
    LSV07A_Ajax_Kasse::init();
    LSV07A_Ajax_Admin::init();
    LSV07A_Ajax_Statistik::init();
    LSV07A_Shortcode::init();
} );
