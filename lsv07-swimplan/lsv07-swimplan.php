<?php
/**
 * Plugin Name: SwimPlan
 * Description: Trainingsplanung und Leistungsverwaltung für Schwimmvereine. Trainer erstellen Trainingseinheiten aus Sets, ordnen sie Mannschaften oder einzelnen Schwimmern zu und erfassen Zeiten. Pläne lassen sich auch als PDF anhängen, im Vollbild und mehrere nebeneinander anzeigen. Zugang nur für Verwaltung und Trainer über ihr WordPress-Konto; Schwimmer haben kein Konto.
 * Version:     1.3.0
 * Author:      LSV07
 * Text Domain: swimplan
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LSV07P_VERSION', '1.3.0' );
define( 'LSV07P_DIR',     plugin_dir_path( __FILE__ ) );
define( 'LSV07P_URL',     plugin_dir_url( __FILE__ ) );

require_once LSV07P_DIR . 'includes/class-db.php';
require_once LSV07P_DIR . 'includes/class-rollen.php';
require_once LSV07P_DIR . 'includes/class-schwimmer.php';
require_once LSV07P_DIR . 'includes/class-mannschaft.php';
require_once LSV07P_DIR . 'includes/class-rechnung.php';
require_once LSV07P_DIR . 'includes/class-plan.php';
require_once LSV07P_DIR . 'includes/class-vorlage.php';
require_once LSV07P_DIR . 'includes/class-zeiten.php';
require_once LSV07P_DIR . 'includes/class-dateien.php';
require_once LSV07P_DIR . 'includes/class-pdf.php';
require_once LSV07P_DIR . 'includes/class-bericht.php';
require_once LSV07P_DIR . 'includes/class-ajax-verwaltung.php';
require_once LSV07P_DIR . 'includes/class-ajax-plan.php';
require_once LSV07P_DIR . 'includes/class-ajax-buehne.php';
require_once LSV07P_DIR . 'includes/class-ajax-download.php';
require_once LSV07P_DIR . 'includes/class-shortcode.php';

/* Beim Aktivieren die Tabellen anlegen. Zusätzlich bei jedem Laden
   nachsehen — so kommt eine neue Fassung auch ohne Neuaktivierung an ihre
   Tabellen, und ein fehlgeschlagenes CREATE beim Aktivieren heilt von
   selbst. Das Prüfen kostet eine Abfrage und läuft nur bei
   Versionswechsel wirklich los. */
register_activation_hook( __FILE__, [ 'LSV07P_DB', 'install' ] );

add_action( 'plugins_loaded', function () {
    if ( get_option( 'lsv07p_db_ver' ) !== LSV07P_VERSION ) {
        LSV07P_DB::install();
        update_option( 'lsv07p_db_ver', LSV07P_VERSION );
    }

    LSV07P_Ajax_Verwaltung::init();
    LSV07P_Ajax_Plan::init();
    LSV07P_Ajax_Buehne::init();
    LSV07P_Ajax_Download::init();
    LSV07P_Shortcode::init();

    /* Einmal am Tag aufräumen: Prüfspur jenseits ihrer Frist und
       Planversionen, die niemand mehr braucht. */
    add_action( 'lsv07p_taeglich', [ 'LSV07P_DB', 'aufraeumen' ] );
    if ( ! wp_next_scheduled( 'lsv07p_taeglich' ) ) {
        wp_schedule_event( time() + 3600, 'daily', 'lsv07p_taeglich' );
    }
} );

register_deactivation_hook( __FILE__, function () {
    $t = wp_next_scheduled( 'lsv07p_taeglich' );
    if ( $t ) wp_unschedule_event( $t, 'lsv07p_taeglich' );
} );
