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
 * 2. SwimSys folgt ausschliesslich den ausdrücklich vergebenen Rechten.
 *    Der interne Bereich hat zusätzlich einen Rückfall auf die alten
 *    WordPress-Rollen für Konten, die nie Rechte bekommen haben. Diese
 *    Konten sehen im internen Bereich etwas und in SwimSys nichts. Das
 *    ist Absicht — geraten wird nicht —, aber es muss sichtbar sein, wen
 *    es betrifft.
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

        echo '<h2>Daten</h2><p>SwimSys arbeitet auf den Tabellen des internen Bereichs und legt '
           . 'selbst keine an. Das Schema gehört dem internen Bereich.</p>';
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

        echo '<h2>Konten ohne vergebene Rechte</h2>';
        echo '<p>SwimSys folgt nur ausdrücklich vergebenen Rechten und rät nicht aus alten '
           . 'WordPress-Rollen. Diese Konten haben im internen Bereich eine Rolle, aber in '
           . '<code>lsv07i_user_rechte</code> keinen Eintrag — sie sehen in SwimSys nichts, '
           . 'bis ihnen im internen Bereich unter Admin → Rechte etwas zugewiesen wird.</p>';
        if ( $ohne === [] ) {
            echo '<p>Keine. Alle Konten mit Vereinsrolle haben vergebene Rechte.</p>';
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

    /** Konten mit Vereinsrolle, aber ohne Eintrag in der Rechtetabelle. */
    private static function konten_ohne_rechte() {
        if ( ! LSV07S_Tabellen::da( 'rechte' ) ) return [];
        $rollen = [ 'intern', 'finanzwart', 'schwimmwart', 'triathlonwart',
                    'tri_intern', 'fitnesswart', 'fit_intern' ];
        $raus = [];
        foreach ( get_users( [ 'role__in' => $rollen, 'number' => 200 ] ) as $u ) {
            if ( LSV07S_Rechte::rechte_von( $u->ID ) !== [] ) continue;
            if ( user_can( $u->ID, 'administrator' ) ) continue;
            $raus[] = [
                'name'   => LSV07S_Person::konto_name( $u->ID ),
                'rollen' => implode( ', ', (array) $u->roles ),
            ];
        }
        return $raus;
    }
}
