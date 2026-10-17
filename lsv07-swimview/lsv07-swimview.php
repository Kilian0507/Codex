<?php
/**
 * Plugin Name: LSV07 SwimView
 * Description: Einblick für Schwimmerinnen und Schwimmer in ihre eigenen Daten aus dem internen Bereich — Anwesenheit, Vermerke, Aufgaben, Dokumentation, Wettkämpfe, Bestzeiten, Reflexion und Trainingsplan. Die Anmeldung läuft über DSV-ID und Geburtsdatum; sichtbar ist nur, was die Trainerin oder der Trainer für die Mannschaft freigegeben hat.
 * Version:     1.1.0
 * Author:      LSV07
 * Text Domain: lsv07-swimview
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LSV07S_VERSION', '1.1.0' );
define( 'LSV07S_DIR',     plugin_dir_path( __FILE__ ) );
define( 'LSV07S_URL',     plugin_dir_url( __FILE__ ) );

require_once LSV07S_DIR . 'includes/class-db.php';
require_once LSV07S_DIR . 'includes/class-intern.php';
require_once LSV07S_DIR . 'includes/class-freigabe.php';
require_once LSV07S_DIR . 'includes/class-code.php';
require_once LSV07S_DIR . 'includes/class-login.php';
require_once LSV07S_DIR . 'includes/class-trainer.php';
require_once LSV07S_DIR . 'includes/class-dateien.php';
require_once LSV07S_DIR . 'includes/class-pdf.php';
require_once LSV07S_DIR . 'includes/class-bericht.php';
require_once LSV07S_DIR . 'includes/class-daten.php';
require_once LSV07S_DIR . 'includes/class-ajax-schwimmer.php';
require_once LSV07S_DIR . 'includes/class-ajax-trainer.php';
require_once LSV07S_DIR . 'includes/class-ajax-download.php';
require_once LSV07S_DIR . 'includes/class-shortcode.php';

/* Beim Aktivieren die eigenen Tabellen anlegen. Zusätzlich bei jedem Laden
   nachsehen — so kommt eine neue Fassung auch ohne Neuaktivierung an ihre
   Tabellen, und ein fehlgeschlagenes CREATE beim Aktivieren heilt von
   selbst. Dasselbe Verfahren wie in der Abrechnung. */
register_activation_hook( __FILE__, [ 'LSV07S_DB', 'install' ] );

add_action( 'plugins_loaded', function () {
    if ( get_option( 'lsv07s_db_ver' ) !== LSV07S_VERSION ) {
        LSV07S_DB::install();
        update_option( 'lsv07s_db_ver', LSV07S_VERSION );
    }

    LSV07S_Ajax_Schwimmer::init();
    LSV07S_Ajax_Trainer::init();
    LSV07S_Ajax_Download::init();
    LSV07S_Shortcode::init();

    /* Einmal am Tag wegräumen, was seine Zeit hinter sich hat: abgelaufene
       Sitzungen, alte Sperren, alte Lesemarken, die Prüfspur jenseits ihrer
       Frist und Nachrichten jenseits der eingestellten Aufbewahrung.
       Datensparsamkeit heisst auch, Daten wieder loszuwerden. */
    add_action( 'lsv07s_taeglich', [ 'LSV07S_DB', 'aufraeumen' ] );
    if ( ! wp_next_scheduled( 'lsv07s_taeglich' ) ) {
        wp_schedule_event( time() + 3600, 'daily', 'lsv07s_taeglich' );
    }
} );

/* Beim Abschalten des Plugins den Termin wieder abbestellen. */
register_deactivation_hook( __FILE__, function () {
    $t = wp_next_scheduled( 'lsv07s_taeglich' );
    if ( $t ) wp_unschedule_event( $t, 'lsv07s_taeglich' );
} );
