<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Der Shortcode [swimsys].
 *
 * Dieselbe Vollbild-Darstellung wie im internen Bereich und in der
 * Abrechnung: Theme-Kopf, Navigation und Fusszeile verschwinden, der
 * Inhalt füllt das Fenster. Wer zwischen den Plugins wechselt, soll
 * keinen Bruch sehen.
 */
class LSV07S_Shortcode {

    private static $ausgeben = false;
    private static $hat_sc   = null;

    public static function init() {
        add_shortcode( 'swimsys', [ __CLASS__, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
        add_action( 'wp_footer', [ __CLASS__, 'skripte' ], 99 );
        add_filter( 'body_class', [ __CLASS__, 'body_class' ] );
        add_filter( 'show_admin_bar', [ __CLASS__, 'adminleiste' ], 99 );
        add_action( 'wp_head', [ __CLASS__, 'kopf' ], 999 );
    }

    /**
     * Steht der Shortcode auf dieser Seite?
     *
     * Gemerkt wird nur ein verlässliches „ja": Manche Hooks laufen, bevor
     * $post steht, und ein dann gemerktes „nein" würde den Vollbildmodus
     * für den ganzen Aufruf zerstören. Seitenbaukästen legen ihren Aufbau
     * in Zusatzfeldern ab, deshalb werden auch die durchsucht.
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

        if ( has_shortcode( $post->post_content, 'swimsys' ) ) return self::$hat_sc = true;

        $felder = get_post_meta( $post->ID );
        if ( is_array( $felder ) ) {
            foreach ( $felder as $schluessel => $werte ) {
                if ( $schluessel !== '' && $schluessel[0] === '_'
                     && strpos( $schluessel, '_elementor' ) !== 0
                     && strpos( $schluessel, '_et_pb' ) !== 0
                     && strpos( $schluessel, '_vc_' ) !== 0
                     && strpos( $schluessel, '_fl_builder' ) !== 0 ) continue;
                foreach ( (array) $werte as $w ) {
                    if ( is_string( $w ) && strpos( $w, '[swimsys' ) !== false ) {
                        return self::$hat_sc = true;
                    }
                }
            }
        }
        return self::$hat_sc = false;
    }

    public static function assets() {
        if ( ! self::seite_hat_shortcode() || ! is_user_logged_in() ) return;
        wp_enqueue_style( 'lsv07s-style', LSV07S_URL . 'assets/css/style.css', [], LSV07S_VERSION );
        wp_enqueue_script( 'jquery' );
    }

    public static function body_class( $klassen ) {
        if ( self::seite_hat_shortcode() ) $klassen[] = 'lsv07s-vollbild';
        return $klassen;
    }

    public static function adminleiste( $zeigen ) {
        return self::seite_hat_shortcode() ? false : $zeigen;
    }

    public static function kopf() {
        if ( ! self::seite_hat_shortcode() ) return;
        echo '<style id="lsv07s-ohne-adminleiste">html{margin-top:0 !important}'
           . '* html body{margin-top:0 !important}'
           . '@media screen and (max-width:782px){html{margin-top:0 !important}'
           . '* html body{margin-top:0 !important}}</style>' . "\n";
        /* Dieselbe Farbe wie die Fläche der Anwendung (--g10), damit die
           Browserleisten auf dem Handy nicht als fremder Streifen
           darüberstehen. */
        echo '<meta name="theme-color" content="#f6f8fc">' . "\n";
        echo '<meta name="color-scheme" content="light">' . "\n";
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n";
        echo '<style id="lsv07s-grundfarbe">html{color-scheme:light}'
           . 'html,body{background:#f7f8fa !important}</style>' . "\n";
    }

    public static function render() {
        if ( ! is_user_logged_in() ) {
            return self::kasten( 'Bitte anmelden', 'Für SwimSys müssen Sie angemeldet sein.',
                /* Inline gestaltet: Dieser Kasten steht ausserhalb von
                   #s-root, und das Stilblatt greift nur innerhalb. */
                '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '"'
                . ' style="display:inline-flex;align-items:center;min-height:34px;padding:0 14px;'
                . 'border-radius:8px;background:#0b57d0;color:#fff;font-weight:600;'
                . 'text-decoration:none">Zur Anmeldung</a>' );
        }

        /* Fehlt das Schema, fehlt der interne Bereich. Das offen sagen ist
           besser, als mit Datenbankfehlern um sich zu werfen. */
        $fehlt = LSV07S_Tabellen::fehlt();
        if ( $fehlt ) {
            return self::kasten( 'Daten nicht gefunden',
                'SwimSys arbeitet auf den Daten des internen Bereichs. Diese Tabellen fehlen: '
                . implode( ', ', $fehlt ) . '. Bitte aktivieren Sie das Plugin LSV07 Intern.' );
        }

        if ( LSV07S_Rechte::gesperrt() ) {
            return self::kasten( 'Konto gesperrt',
                'Dieses Konto ist gesperrt. Bitte wenden Sie sich an die Administration.' );
        }

        if ( ! LSV07S_Rechte::hat_zugang() ) {
            return self::kasten( 'Noch keine Rechte',
                'Für dieses Konto sind in SwimSys noch keine Rechte vergeben. '
                . 'Die Administration kann sie im internen Bereich unter Admin → Rechte erteilen; '
                . 'SwimSys benutzt dieselben Rechte.' );
        }

        self::$ausgeben = true;
        ob_start();
        include LSV07S_DIR . 'templates/app.php';
        return ob_get_clean();
    }

    private static function kasten( $titel, $text, $extra = '' ) {
        return '<div style="font-family:\'Segoe UI\',system-ui,sans-serif;max-width:560px;'
             . 'margin:40px auto;padding:22px 24px;border:1px solid #e3e9f2;border-radius:14px;'
             . 'background:#fff;box-shadow:0 1px 2px rgba(16,32,56,.06)">'
             . '<div style="font-size:17px;font-weight:600;margin-bottom:6px;color:#16202e">'
             . esc_html( $titel ) . '</div>'
             . '<div style="color:#55637a;line-height:1.5;font-size:14px">' . esc_html( $text ) . '</div>'
             . ( $extra ? '<div style="margin-top:14px">' . $extra . '</div>' : '' )
             . '</div>';
    }

    public static function skripte() {
        if ( ! self::$ausgeben ) return;
        $daten = [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => LSV07S_Zugang::schluessel(),
            'karte'    => LSV07S_Rechte::karte(),
            'heute'    => LSV07S_Datum::heute(),
            'wer'      => LSV07S_Person::konto_name( get_current_user_id() ),
            'ist_admin'=> LSV07S_Rechte::ist_admin(),
            'zustaende'=> LSV07S_Anwesenheit::ZUSTAENDE,
            'sparten'  => array_map( fn( $s ) => [
                'kennung' => $s,
                'name'    => LSV07S_Sparte::feld( $s, 'name' ),
                'einer'   => LSV07S_Sparte::feld( $s, 'einer' ),
                'gruppe'  => LSV07S_Sparte::feld( $s, 'gruppe_name' ),
                'da'      => LSV07S_Sparte::da( $s ),
            ], LSV07S_Sparte::alle() ),
        ];
        echo '<script>window.LSV07S = ' . wp_json_encode( $daten ) . ';</script>' . "\n";
        /* Direkt ausgegeben statt per wp_enqueue_script: Dieser Hook läuft
           bei Priorität 99 und damit nach der Ausgabe der Fusszeilen-
           Skripte — ein Enqueue käme zu spät und das Skript fehlte ganz. */
        echo '<script src="' . esc_url( LSV07S_URL . 'assets/js/app.js?v=' . LSV07S_VERSION ) . '"></script>' . "\n";
    }
}
