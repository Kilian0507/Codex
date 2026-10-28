<?php
/**
 * Plugin Name: LSV07 SwimSys
 * Plugin URI:  https://lsv07.de
 * Description: Der interne Bereich, neu gebaut: dieselben Daten, ein Weg zu jeder Regel, eine Adresse für jede Ansicht. Läuft parallel zum alten internen Bereich.
 * Version:     0.1.0
 * Author:      LSV 07
 * Text Domain: lsv07-swimsys
 *
 * SwimSys arbeitet auf den Tabellen des internen Bereichs (lsv07i_*,
 * lsv07_gruppen, mv_swimmers) und legt davon KEINE an. Zwei Plugins, die
 * dieselbe Tabelle anlegen und nachbessern, bessern irgendwann
 * gegeneinander nach — und wer dann welche Spalte gesetzt hat, ist nicht
 * mehr zu ermitteln. Das Schema gehört dem internen Bereich; SwimSys liest
 * und schreibt Zeilen, nie Struktur.
 *
 * Was SwimSys eigenes anlegt, trägt den Präfix lsv07s_.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LSV07S_VERSION', '0.1.0' );
define( 'LSV07S_DIR', plugin_dir_path( __FILE__ ) );
define( 'LSV07S_URL', plugin_dir_url( __FILE__ ) );

/* Die Reihenfolge ist Absicht: Tabellen und Sparten wissen nichts von
   Rechten, Rechte nichts von Zugang, Zugang nichts von den Endpunkten. Wer
   weiter unten steht, darf nach oben greifen — nie umgekehrt. */
require_once LSV07S_DIR . 'includes/class-tabellen.php';
require_once LSV07S_DIR . 'includes/class-sparte.php';
require_once LSV07S_DIR . 'includes/class-datum.php';
require_once LSV07S_DIR . 'includes/class-rechte.php';
require_once LSV07S_DIR . 'includes/class-zugang.php';
require_once LSV07S_DIR . 'includes/class-person.php';
require_once LSV07S_DIR . 'includes/class-mannschaft.php';
require_once LSV07S_DIR . 'includes/class-log.php';
require_once LSV07S_DIR . 'includes/class-anwesenheit.php';
require_once LSV07S_DIR . 'includes/class-aufgaben.php';
require_once LSV07S_DIR . 'includes/class-suche.php';
require_once LSV07S_DIR . 'includes/class-ajax.php';
require_once LSV07S_DIR . 'includes/class-shortcode.php';
require_once LSV07S_DIR . 'includes/class-admin-hinweis.php';

add_action( 'plugins_loaded', function () {
    LSV07S_Ajax::init();
    LSV07S_Shortcode::init();
    LSV07S_Admin_Hinweis::init();
}, 20 );   // 20: nach dem internen Bereich, dessen Tabellen wir brauchen
