<?php
/**
 * Plugin Name: LSV07 SwimSys
 * Plugin URI:  https://lsv07.de
 * Description: Der interne Bereich, neu gebaut: dieselben Daten, ein Weg zu jeder Regel, eine Adresse für jede Ansicht. Läuft parallel zum alten internen Bereich.
 * Version:     0.12.1
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
 * Was SwimSys eigenes anlegt, trägt den Präfix lsv07s_ — das sind genau
 * zwei Tabellen, für die Schalter: lsv07s_schalter (global) und
 * lsv07s_konto_schalter (je Konto). SwimSys fragt keine Rechte; was ein
 * Konto sieht und darf, steht in den Schaltern. Angemeldet wird über das
 * WordPress-Konto.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LSV07S_VERSION', '0.12.1' );
define( 'LSV07S_DIR', plugin_dir_path( __FILE__ ) );
define( 'LSV07S_URL', plugin_dir_url( __FILE__ ) );

/* Die Reihenfolge ist Absicht: Tabellen und Sparten wissen nichts von
   Rechten, Rechte nichts von Zugang, Zugang nichts von den Endpunkten. Wer
   weiter unten steht, darf nach oben greifen — nie umgekehrt. */
require_once LSV07S_DIR . 'includes/class-tabellen.php';
require_once LSV07S_DIR . 'includes/class-schema.php';
require_once LSV07S_DIR . 'includes/class-install.php';
require_once LSV07S_DIR . 'includes/class-sparte.php';
require_once LSV07S_DIR . 'includes/class-datum.php';
require_once LSV07S_DIR . 'includes/class-rechte.php';
require_once LSV07S_DIR . 'includes/class-schalter.php';
require_once LSV07S_DIR . 'includes/class-zugang.php';
require_once LSV07S_DIR . 'includes/class-person.php';
require_once LSV07S_DIR . 'includes/class-mannschaft.php';
require_once LSV07S_DIR . 'includes/class-stamm.php';
require_once LSV07S_DIR . 'includes/class-log.php';
require_once LSV07S_DIR . 'includes/class-anwesenheit.php';
require_once LSV07S_DIR . 'includes/class-buch.php';
require_once LSV07S_DIR . 'includes/class-mail.php';
require_once LSV07S_DIR . 'includes/class-attest.php';
require_once LSV07S_DIR . 'includes/class-datei.php';
require_once LSV07S_DIR . 'includes/class-benachrichtigung.php';
require_once LSV07S_DIR . 'includes/class-profil.php';
require_once LSV07S_DIR . 'includes/class-akte.php';
require_once LSV07S_DIR . 'includes/class-bestzeit.php';
require_once LSV07S_DIR . 'includes/class-wettkampf.php';
require_once LSV07S_DIR . 'includes/class-meldung.php';
require_once LSV07S_DIR . 'includes/class-stunden.php';
require_once LSV07S_DIR . 'includes/class-reden.php';
require_once LSV07S_DIR . 'includes/class-anfragen.php';
require_once LSV07S_DIR . 'includes/class-reflexion.php';
require_once LSV07S_DIR . 'includes/class-reflexion-public.php';
require_once LSV07S_DIR . 'includes/class-trainingsplan.php';
require_once LSV07S_DIR . 'includes/class-eigene.php';
require_once LSV07S_DIR . 'includes/class-aufgaben.php';
require_once LSV07S_DIR . 'includes/class-suche.php';
require_once LSV07S_DIR . 'includes/class-ajax.php';
require_once LSV07S_DIR . 'includes/class-shortcode.php';
require_once LSV07S_DIR . 'includes/class-admin-hinweis.php';

add_action( 'plugins_loaded', function () {
    LSV07S_Ajax::init();
    LSV07S_Shortcode::init();
    LSV07S_Admin_Hinweis::init();
    /* Die Seite, auf der ein Schwimmer seinen Reflexionsbogen ausfüllt.
       Sie hört auf ihren eigenen Schalter in der Adresse
       (`?swimsys_reflexion=1`); die des internen Bereichs
       (`?lsv07i_reflexion=`) kann daneben weiterlaufen. */
    LSV07S_Reflexion_Public::init();
    /* Prüft, ob das Schema übernommen werden muss — und tut es nur, wenn
       der interne Bereich nicht aktiv ist (siehe LSV07S_Install). */
    LSV07S_Install::init();
}, 20 );   // 20: nach dem internen Bereich, dessen Tabellen wir brauchen

/* Bei der Aktivierung derselbe Weg. Die Prüfung in plugins_loaded heilt
   den Fall, in dem das hier einmal nicht durchlief. */
register_activation_hook( __FILE__, function () {
    /* Die eigenen Tabellen immer — ohne sie sieht niemand ausser der
       Administration etwas. Die Tabellen des internen Bereichs nur, wenn
       er nicht läuft. */
    LSV07S_Install::eigene_anlegen();
    if ( ! LSV07S_Install::intern_aktiv() ) LSV07S_Install::anlegen();
} );
