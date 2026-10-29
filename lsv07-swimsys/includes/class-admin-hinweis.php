<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Was die Administration über SwimSys wissen muss — im Backend, nicht in
 * einer Anleitung.
 *
 * Zwei Dinge sind unbequem und gehören deshalb genannt, bevor jemand
 * stundenlang sucht:
 *
 * 1. SwimSys braucht die Tabellen des internen Bereichs. Fehlen sie,
 *    zeigt die Seite nichts, und der Grund steht hier.
 * 2. SwimSys fragt **keine Rechte**. Jede Funktion hat einen Schalter,
 *    global und je Konto (siehe LSV07S_Schalter). Ein Konto, für das
 *    nichts eingeschaltet ist, sieht nichts — auch wenn es im internen
 *    Bereich eine Rolle und Rechte hat. Das ist Absicht, aber es muss
 *    sichtbar sein, wen es betrifft.
 */
class LSV07S_Admin_Hinweis {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'menue' ] );
        add_action( 'admin_notices', [ __CLASS__, 'hinweis' ] );
    }

    public static function menue() {
        add_menu_page( 'SwimSys', 'SwimSys', 'manage_options', 'lsv07s',
            [ __CLASS__, 'seite' ], 'dashicons-chart-area', 58 );
    }

    public static function hinweis() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $fehlt = LSV07S_Tabellen::fehlt();
        if ( ! $fehlt ) return;
        echo '<div class="notice notice-warning"><p><strong>SwimSys:</strong> '
           . 'Diese Tabellen des internen Bereichs fehlen: '
           . esc_html( implode( ', ', $fehlt ) )
           . '. SwimSys zeigt erst Daten, wenn das Plugin LSV07 Intern aktiv war.</p></div>';
    }

    public static function seite() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $fehlt  = LSV07S_Tabellen::fehlt();
        $ohne   = self::konten_ohne_rechte();
        $sparten = [];
        foreach ( LSV07S_Sparte::alle() as $s ) {
            $sparten[ LSV07S_Sparte::feld( $s, 'name' ) ] = LSV07S_Sparte::da( $s );
        }

        echo '<div class="wrap"><h1>SwimSys ' . esc_html( LSV07S_VERSION ) . '</h1>';

        echo '<h2>Daten</h2><p>SwimSys arbeitet auf den Tabellen des internen Bereichs '
           . 'und legt davon keine an — das Schema gehört ihm, solange er aktiv ist. '
           . 'Eigene Tabellen hat SwimSys nur zwei, für die Schalter '
           . '(<code>lsv07s_schalter</code>, <code>lsv07s_konto_schalter</code>); '
           . 'die legt es immer selbst an.</p>';
        if ( $fehlt ) {
            echo '<p><strong>Fehlt:</strong> ' . esc_html( implode( ', ', $fehlt ) ) . '</p>';
        } else {
            echo '<p>Alle notwendigen Tabellen sind vorhanden.</p>';
        }

        echo '<h2>Sparten</h2><ul>';
        foreach ( $sparten as $name => $da ) {
            echo '<li>' . esc_html( $name ) . ': '
               . ( $da ? 'eingerichtet' : 'nicht eingerichtet' ) . '</li>';
        }
        echo '</ul>';

        echo '<h2>Funktionen und Schalter</h2>';
        echo '<p>Angemeldet wird über das WordPress-Konto; SwimSys hat keine eigene '
           . 'Anmeldung. Was ein Konto danach sieht, steht in den Schaltern: je '
           . 'Funktion einer global und einer je Konto. Global ist alles an, je Konto '
           . 'zunächst nichts. Gesetzt werden sie in SwimSys unter '
           . '<em>Verwaltung → Funktionen</em>, und nur von '
           . 'WordPress-Administratoren.</p>';
        $aus = LSV07S_Schalter::global_aus();
        echo '<p><strong>Global abgeschaltet:</strong> '
           . ( $aus === [] ? 'nichts — alle Funktionen sind an'
                           : '<code>' . esc_html( implode( ', ', array_keys( $aus ) ) )
                             . '</code>' ) . '</p>';

        echo '<h2>Konten ohne eingeschaltete Funktion</h2>';
        echo '<p>Diese Konten haben im internen Bereich eine Vereinsrolle, in SwimSys '
           . 'aber keine eingeschaltete Funktion — sie sehen hier nichts. Wer vorher '
           . 'mit Rechten gearbeitet hat, kann sie unter <em>Verwaltung → '
           . 'Funktionen</em> einmalig übernehmen lassen.</p>';
        if ( $ohne === [] ) {
            echo '<p>Keine. Für alle Konten mit Vereinsrolle ist etwas eingeschaltet.</p>';
        } else {
            echo '<ul>';
            foreach ( $ohne as $u ) {
                echo '<li>' . esc_html( $u['name'] ) . ' (' . esc_html( $u['rollen'] ) . ')</li>';
            }
            echo '</ul>';
        }

        echo '<h2>Sperrliste</h2>';
        $liste = (string) LSV07S_Tabellen::config( 'blocked_user_ids', '' );
        echo '<p>Gesperrte Konten (aus <code>lsv07i_config</code>, Schlüssel '
           . '<code>blocked_user_ids</code>): '
           . ( trim( $liste ) === '' ? 'keine' : '<code>' . esc_html( $liste ) . '</code>' ) . '</p>';
        echo '<p>In SwimSys gilt die Sperre für jedes Konto, auch für Administratoren. '
           . 'Sie ist eine Vereinsregel im Programm und kein Schutz gegen jemanden mit '
           . 'WordPress-Administratorrechten: wer das Backend hat, kann das Plugin abschalten.</p>';

        echo '<h2>Einbau</h2><p>Den Shortcode <code>[swimsys]</code> auf eine Seite setzen. '
           . 'Die Seite stellt sich im Vollbild dar, ohne Theme-Kopf und Navigation.</p>';
        echo '</div>';
    }

    /** Konten mit Vereinsrolle, für die in SwimSys nichts an ist. */
    private static function konten_ohne_rechte() {
        $rollen = [ 'intern', 'finanzwart', 'schwimmwart', 'triathlonwart',
                    'tri_intern', 'fitnesswart', 'fit_intern' ];
        $raus = [];
        foreach ( get_users( [ 'role__in' => $rollen, 'number' => 200 ] ) as $u ) {
            if ( LSV07S_Schalter::konto_liste( $u->ID ) !== [] ) continue;
            if ( user_can( $u->ID, 'administrator' ) ) continue;
            $raus[] = [
                'name'   => LSV07S_Person::konto_name( $u->ID ),
                'rollen' => implode( ', ', (array) $u->roles ),
            ];
        }
        return $raus;
    }
}
