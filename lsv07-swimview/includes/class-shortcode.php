<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Der Shortcode [swimview] (und [lsv07_swimview] als zweiter Name).
 *
 * Die Seite wird zur Anwendung: Theme-Kopf, -Fuß und -Seitenleiste
 * verschwinden, der Inhalt füllt das Fenster — dasselbe Verfahren wie im
 * internen Bereich und in der Abrechnung, damit sich alle drei gleich
 * anfühlen.
 *
 * Anders als dort ist hier NICHT jeder angemeldet: Schwimmer melden sich mit
 * DSV-ID und Geburtsdatum an, Trainer über ihr WordPress-Konto. Die Seite
 * muss also auch für Nichtangemeldete ausgeliefert werden — und darf dann
 * nichts enthalten als das Anmeldefeld.
 */
class LSV07S_Shortcode {

    private static $ausgeben = false;
    private static $hat_sc   = null;

    public static function init() {
        add_shortcode( 'swimview',        [ __CLASS__, 'render' ] );
        add_shortcode( 'lsv07_swimview',  [ __CLASS__, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
        add_action( 'wp_footer',          [ __CLASS__, 'skripte' ], 99 );
        add_filter( 'body_class',         [ __CLASS__, 'body_class' ] );
        add_filter( 'show_admin_bar',     [ __CLASS__, 'adminbar' ], 99 );
        add_action( 'wp_head',            [ __CLASS__, 'kopf' ], 999 );
    }

    /**
     * Steht der Shortcode auf dieser Seite? Mehrere Hooks fragen das pro
     * Aufruf — gemerkt wird aber nur ein verlässliches Ergebnis: Manche
     * Hooks feuern, bevor $post steht, und ein dann gemerktes „nein" würde
     * den Vollbildmodus für den ganzen Aufruf zerstören.
     */
    private static function seite_hat_shortcode() {
        if ( self::$hat_sc !== null ) return self::$hat_sc;
        if ( self::$ausgeben ) return true;
        if ( ! is_singular() ) return false;

        $post = ! empty( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
        if ( ! $post ) {
            $qid = get_queried_object_id();
            if ( $qid ) $post = get_post( $qid );
        }
        if ( ! $post ) return false;

        if ( has_shortcode( $post->post_content, 'swimview' )
          || has_shortcode( $post->post_content, 'lsv07_swimview' ) ) {
            return self::$hat_sc = true;
        }

        /* Seitenbaukästen (Elementor, Divi, WPBakery, Beaver) legen ihren
           Aufbau in Zusatzfeldern ab — dort steht der Shortcode dann, und
           ohne diese Suche bliebe die Seite eingebettet: mit Theme-Kopf,
           Navigation, Fusszeile und Adminleiste. */
        $felder = get_post_meta( $post->ID );
        if ( is_array( $felder ) ) {
            foreach ( $felder as $schluessel => $werte ) {
                if ( $schluessel !== '' && $schluessel[0] === '_'
                     && strpos( $schluessel, '_elementor' ) !== 0
                     && strpos( $schluessel, '_et_pb' ) !== 0
                     && strpos( $schluessel, '_vc_' ) !== 0
                     && strpos( $schluessel, '_fl_builder' ) !== 0 ) continue;
                foreach ( (array) $werte as $w ) {
                    if ( is_string( $w )
                      && ( strpos( $w, '[swimview' ) !== false
                        || strpos( $w, '[lsv07_swimview' ) !== false ) ) {
                        return self::$hat_sc = true;
                    }
                }
            }
        }
        return self::$hat_sc = false;
    }

    public static function assets() {
        if ( ! self::seite_hat_shortcode() ) return;
        wp_enqueue_style( 'lsv07s-style', LSV07S_URL . 'assets/css/style.css', [], LSV07S_VERSION );
        wp_enqueue_script( 'jquery' );
    }

    public static function body_class( $klassen ) {
        if ( self::seite_hat_shortcode() ) $klassen[] = 'lsv07s-vollbild';
        return $klassen;
    }

    public static function adminbar( $zeigen ) {
        return self::seite_hat_shortcode() ? false : $zeigen;
    }

    public static function kopf() {
        if ( ! self::seite_hat_shortcode() ) return;

        /* WordPress reserviert per CSS 32 px oben für die Adminleiste. */
        echo '<style id="lsv07s-ohne-adminleiste">html{margin-top:0 !important}'
           . '* html body{margin-top:0 !important}'
           . '@media screen and (max-width:782px){html{margin-top:0 !important}'
           . '* html body{margin-top:0 !important}}</style>' . "\n";

        /* Damit das Fenster bis an die Geräteränder gehört: Die
           Browserleisten bekommen dieselbe Farbe wie die Anwendung, die
           Seite reicht in die Safe-Areas, und html/body sind vom ersten
           Bild an hell — sonst blitzt Weiss auf und Safari macht daraus die
           Leistenfarbe. Serverseitig im <head>, weil Safari nachträglich
           per JS gesetzte Angaben ignoriert. */
        echo '<meta name="theme-color" content="#0a2e63">' . "\n";
        echo '<meta name="color-scheme" content="light">' . "\n";
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n";
        echo '<style id="lsv07s-grundfarbe">html{color-scheme:light}'
           . 'html,body{background:#f6f8fc !important}</style>' . "\n";

        /* Eine Seite mit Daten über Kinder gehört in keinen Suchindex. */
        echo '<meta name="robots" content="noindex, nofollow">' . "\n";
    }

    public static function render() {
        wp_enqueue_style( 'lsv07s-style', LSV07S_URL . 'assets/css/style.css', [], LSV07S_VERSION );
        wp_enqueue_script( 'jquery' );
        self::$ausgeben = true;

        /* Das Token wird schon beim Ausliefern der Seite gesetzt — sonst
           hätte das erste Anmeldeformular noch keines. */
        LSV07S_Login::csrf_token();

        ob_start();
        include LSV07S_DIR . 'templates/app.php';
        return ob_get_clean();
    }

    public static function skripte() {
        if ( ! self::$ausgeben ) return;

        $s = LSV07S_Login::schwimmer();

        $daten = [
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'token'      => LSV07S_Login::csrf_token(),
            'nonce'      => wp_create_nonce( 'lsv07s_nonce' ),
            'logo'       => LSV07S_URL . 'assets/img/swimview-logo.png',
            'marke'      => LSV07S_URL . 'assets/img/swimview-marke.png',
            'angemeldet' => (bool) $s,
            'trainer'    => LSV07S_Trainer::hat_zugang(),
            'zustaende'  => LSV07S_Daten::ZUSTAENDE,
            'gesperrt'   => LSV07S_Login::ist_gesperrt(),
        ];

        if ( $s ) {
            $daten['start'] = LSV07S_Daten::startdaten( $s );
        }

        echo '<script>window.LSV07S = ' . wp_json_encode( $daten ) . ';</script>' . "\n";
        /* Direkt ausgegeben statt per wp_enqueue_script: Dieser Hook läuft
           bei Priorität 99 und damit NACH der Ausgabe der Fußzeilen-Skripte
           — ein Enqueue käme hier zu spät und das Skript fehlte ganz. */
        echo '<script src="' . esc_url( LSV07S_URL . 'assets/js/app.js?v=' . LSV07S_VERSION ) . '"></script>' . "\n";
    }

}
