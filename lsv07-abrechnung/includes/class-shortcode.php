<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Der Shortcode [lsv07_abrechnung].
 *
 * Die Seite wird zur Anwendung: Theme-Kopf, -Fuß und -Seitenleiste
 * verschwinden, der Inhalt füllt das Fenster. Dasselbe Verfahren wie im
 * internen Bereich, damit sich beide gleich anfühlen.
 */
class LSV07A_Shortcode {

    private static $ausgeben = false;
    private static $hat_sc   = null;

    public static function init() {
        add_shortcode( 'lsv07_abrechnung', [ __CLASS__, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
        add_action( 'wp_footer', [ __CLASS__, 'skripte' ], 99 );
        add_filter( 'body_class', [ __CLASS__, 'body_class' ] );
        add_filter( 'show_admin_bar', [ __CLASS__, 'adminbar' ], 99 );
        add_action( 'wp_head', [ __CLASS__, 'adminbar_css' ], 999 );
    }

    /**
     * Steht der Shortcode auf dieser Seite? Mehrere Hooks fragen das pro
     * Aufruf — gemerkt wird aber nur ein verlässliches Ergebnis: Manche
     * Hooks feuern, bevor $post steht, und ein dann gemerktes "nein" würde
     * den Vollbildmodus für den ganzen Aufruf zerstören.
     */
    private static function seite_hat_shortcode() {
        if ( self::$hat_sc !== null ) return self::$hat_sc;
        if ( self::$ausgeben ) return true;   // wird gerade ausgegeben — sicherer geht es nicht
        if ( ! is_singular() ) return false;
        $post = ! empty( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
        if ( ! $post ) {
            $qid = get_queried_object_id();
            if ( $qid ) $post = get_post( $qid );
        }
        if ( ! $post ) return false;

        if ( has_shortcode( $post->post_content, 'lsv07_abrechnung' ) ) {
            return self::$hat_sc = true;
        }

        /* has_shortcode() sieht nur den Inhalt der Seite. Seitenbaukästen
           (Elementor, Divi, WPBakery, Beaver) legen ihren Aufbau in
           Zusatzfeldern ab — dort steht der Shortcode dann, und ohne diese
           Suche bliebe die Seite eingebettet: mit Theme-Kopf, Navigation,
           Fusszeile und Adminleiste. Die Zusatzfelder sind zu diesem
           Zeitpunkt ohnehin schon geladen, das kostet keine Abfrage. */
        $felder = get_post_meta( $post->ID );
        if ( is_array( $felder ) ) {
            foreach ( $felder as $schluessel => $werte ) {
                if ( $schluessel !== '' && $schluessel[0] === '_'
                     && strpos( $schluessel, '_elementor' ) !== 0
                     && strpos( $schluessel, '_et_pb' ) !== 0
                     && strpos( $schluessel, '_vc_' ) !== 0
                     && strpos( $schluessel, '_fl_builder' ) !== 0 ) continue;
                foreach ( (array) $werte as $w ) {
                    if ( is_string( $w ) && strpos( $w, '[lsv07_abrechnung' ) !== false ) {
                        return self::$hat_sc = true;
                    }
                }
            }
        }
        return self::$hat_sc = false;
    }

    public static function assets() {
        if ( ! self::seite_hat_shortcode() || ! is_user_logged_in() ) return;
        wp_enqueue_style( 'lsv07a-style', LSV07A_URL . 'assets/css/style.css', [], LSV07A_VERSION );
        wp_enqueue_script( 'jquery' );
    }

    public static function body_class( $klassen ) {
        if ( self::seite_hat_shortcode() ) {
            $klassen[] = 'lsv07a-fullscreen';
        }
        return $klassen;
    }

    public static function adminbar( $zeigen ) {
        return self::seite_hat_shortcode() ? false : $zeigen;
    }

    /** WordPress reserviert per CSS 32px oben für die Adminleiste. */
    public static function adminbar_css() {
        if ( ! self::seite_hat_shortcode() ) return;
        echo '<style id="lsv07a-ohne-adminleiste">html{margin-top:0 !important}'
           . '* html body{margin-top:0 !important}'
           . '@media screen and (max-width:782px){html{margin-top:0 !important}'
           . '* html body{margin-top:0 !important}}</style>' . "\n";

        /* Damit das Fenster bis an die Geräteränder gehört und nicht nur
           bis zum Theme: Die Browserleisten bekommen dieselbe Farbe wie die
           Anwendung, die Seite reicht in die Safe-Areas, und html/body sind
           vom ersten Bild an hell — sonst blitzt Weiss auf und Safari macht
           daraus die Leistenfarbe. Serverseitig im <head>, weil Safari
           nachträglich per JS gesetzte Angaben ignoriert. */
        echo '<meta name="theme-color" content="#faf9f8">' . "\n";
        echo '<meta name="color-scheme" content="light">' . "\n";
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n";
        echo '<style id="lsv07a-grundfarbe">html{color-scheme:light}'
           . 'html,body{background:#faf9f8 !important}</style>' . "\n";
    }

    public static function render() {
        if ( ! is_user_logged_in() ) {
            return self::hinweis_kasten( 'Bitte anmelden',
                'Für die Abrechnung müssen Sie angemeldet sein.',
                '<a class="a-btn a-btn-p" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Zur Anmeldung</a>' );
        }
        if ( ! LSV07A_Rollen::hat_zugang() ) {
            return self::hinweis_kasten( 'Kein Zugang',
                'Ihrem Konto ist in der Abrechnung noch keine Rolle zugewiesen. '
                . 'Die Administration kann das unter Verwaltung → Konten erledigen.' );
        }

        wp_enqueue_style( 'lsv07a-style', LSV07A_URL . 'assets/css/style.css', [], LSV07A_VERSION );
        wp_enqueue_script( 'jquery' );
        self::$ausgeben = true;

        ob_start();
        include LSV07A_DIR . 'templates/app.php';
        return ob_get_clean();
    }

    private static function hinweis_kasten( $titel, $text, $extra = '' ) {
        return '<div style="font-family:system-ui,sans-serif;max-width:560px;margin:40px auto;padding:22px 24px;'
             . 'border:1px solid #e3e5ee;border-radius:14px;background:#fff">'
             . '<div style="font-size:17px;font-weight:650;margin-bottom:6px">' . esc_html( $titel ) . '</div>'
             . '<div style="color:#5b5e74;line-height:1.55">' . esc_html( $text ) . '</div>'
             . ( $extra ? '<div style="margin-top:14px">' . $extra . '</div>' : '' )
             . '</div>';
    }

    public static function skripte() {
        if ( ! self::$ausgeben ) return;
        $daten = [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'lsv07a_nonce' ),
            'zugang'   => LSV07A_Access::karte(),
            'config'   => LSV07A_DB::config_alle(),
            'jahr'     => (int) date( 'Y' ),
            'quartal'  => LSV07A_Berechnung::quartal_von_datum( date( 'Y-m-d' ) ),
            'arten'    => array_map( fn( $a ) => [ 'wert' => $a, 'name' => LSV07A_Berechnung::art_name( $a ) ],
                                     LSV07A_DB::ARTEN ),
            'typen'    => array_map( fn( $t ) => [ 'wert' => $t, 'name' => LSV07A_Berechnung::typ_name( $t ) ],
                                     LSV07A_DB::POSTEN_TYPEN ),
        ];
        echo '<script>window.LSV07A = ' . wp_json_encode( $daten ) . ';</script>' . "\n";
        /* Direkt ausgegeben statt per wp_enqueue_script: Dieser Hook läuft
           bei Priorität 99 und damit NACH der Ausgabe der Fußzeilen-Skripte
           — ein Enqueue käme hier zu spät und das Skript fehlte ganz. */
        echo '<script src="' . esc_url( LSV07A_URL . 'assets/js/app.js?v=' . LSV07A_VERSION ) . '"></script>' . "\n";
    }
}
