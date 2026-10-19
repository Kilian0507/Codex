<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Der Shortcode [swimplan] (und [lsv07_swimplan] als zweiter Name).
 *
 * Die Seite wird zur Anwendung: Sie wird mit einer eigenen Vorlage
 * ausgeliefert, das Theme steuert kein Markup bei — kein Kopf, keine
 * Navigation, keine Seitenleiste, keine Fusszeile. Sie werden nicht
 * versteckt, sondern gar nicht erst erzeugt. Das ist der Unterschied zu
 * einem Weg über eine Liste bekannter Klassennamen, der bei jedem
 * unbekannten Theme stillschweigend versagt.
 *
 * SwimPlan hat keine eigene Anmeldung: Wer hier etwas sehen will, ist bei
 * WordPress angemeldet. Die Seite wird darum für Nichtangemeldete
 * ausgeliefert, enthält dann aber nichts als den Hinweis und den Weg zur
 * Anmeldung — keine Namen, keine Mannschaften, keine Pläne.
 */
class LSV07P_Shortcode {

    private static $ausgeben = false;
    private static $hat_sc   = null;

    public static function init() {
        add_shortcode( 'swimplan',       [ __CLASS__, 'render' ] );
        add_shortcode( 'lsv07_swimplan', [ __CLASS__, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
        /* Spät, damit auch eingereiht ist, was Theme und Kern erst am Ende
           anmelden. */
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'fremde_stile_weg' ], 9999 );
        add_action( 'wp_footer',          [ __CLASS__, 'skripte' ], 99 );
        add_filter( 'body_class',         [ __CLASS__, 'body_class' ] );
        add_filter( 'show_admin_bar',     [ __CLASS__, 'adminbar' ], 99 );
        add_action( 'wp_head',            [ __CLASS__, 'kopf' ], 999 );
        /* Die Schutz-Kopfzeilen müssen VOR jeder Ausgabe stehen. `wp_head`
           wäre zu spät — dann sind die Kopfzeilen längst unterwegs. */
        add_action( 'template_redirect',  [ __CLASS__, 'schutz_kopfzeilen' ], 1 );
        /* Die eigene Seitenvorlage. Spät in der Kette, damit sie das letzte
           Wort hat — ein Theme oder ein Baukasten, der hier ebenfalls
           mitredet, soll das Vollbild nicht aushebeln. */
        add_filter( 'template_include',   [ __CLASS__, 'vorlage' ], 999 );
    }

    public static function vorlage( $vorlage ) {
        if ( ! self::seite_hat_shortcode() ) return $vorlage;
        if ( (string) LSV07P_DB::config( 'vollbild', '1' ) !== '1' ) return $vorlage;

        $eigene = LSV07P_DIR . 'templates/vollbild.php';
        return file_exists( $eigene ) ? $eigene : $vorlage;
    }

    /** Ist die Anwendung in diesem Aufruf schon ausgegeben worden? */
    public static function wurde_ausgegeben() {
        return self::$ausgeben;
    }

    /**
     * Die Kopfzeilen, mit denen diese Seite ausgeliefert wird.
     *
     * Hier stehen Trainingsdaten von Kindern und Jugendlichen.
     * Entsprechend:
     *
     *  - `no-store`: Die Seite landet in keinem Zwischenspeicher — weder im
     *    Browser noch in einem Proxy. Sonst stünde sie nach dem Abmelden
     *    noch im Zurück-Knopf des geteilten Vereinstablets.
     *  - `frame-ancestors 'none'` und `X-Frame-Options: DENY`: Niemand
     *    bettet diese Seite in seine eigene ein.
     *  - `Referrer-Policy: no-referrer`: Eine Plannummer steht in der
     *    Adresse; sie geht niemanden an, der von hier aus verlinkt wird.
     *  - `nosniff`: Der Browser rät keinen Inhaltstyp.
     *  - `noindex`: in keinen Suchindex, auch nicht über eine fremde
     *    Verlinkung.
     *  - `Permissions-Policy`: Diese Seite braucht weder Kamera noch
     *    Mikrofon noch Standort; dann soll sie auch nicht danach fragen
     *    können.
     */
    public static function schutz_kopfzeilen() {
        if ( ! self::seite_hat_shortcode() || headers_sent() ) return;

        nocache_headers();
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
        header( 'Pragma: no-cache' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Frame-Options: DENY' );
        header( 'Referrer-Policy: no-referrer' );
        header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
        header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), '
              . 'payment=(), usb=(), interest-cohort=()' );

        /* Die Inhaltsrichtlinie in zwei Stufen.
           Die milde Stufe sagt nur, was diese Seite sicher NICHT braucht —
           sie kann kein Theme zerstören und ist darum die Voreinstellung.
           Die strenge Stufe sperrt zusätzlich fremde Skriptquellen aus; das
           ist deutlich wirksamer, kann aber mit Themes und Plugins
           kollidieren, die ihre Skripte von fremden Servern holen. Darum
           ein Schalter und kein stiller Zwang. */
        $csp = "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; "
             . "object-src 'none'";

        if ( (string) LSV07P_DB::config( 'csp_streng', '0' ) === '1' ) {
            $csp = "default-src 'self'; "
                 . "script-src 'self' 'unsafe-inline'; "
                 . "style-src 'self' 'unsafe-inline'; "
                 . "img-src 'self' data:; "
                 . "font-src 'self'; "
                 . "connect-src 'self'; "
                 . "frame-src 'none'; media-src 'none'; worker-src 'none'; "
                 . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; "
                 . "object-src 'none'";
        }
        header( 'Content-Security-Policy: ' . $csp );

        /* HSTS — nur auf ausdrücklichen Wunsch.
           Es gilt für die GANZE Domain, nicht für diese Seite, und ist nicht
           zurückzunehmen. Läuft irgendwann ein Zertifikat ab — das häufigste
           Missgeschick überhaupt —, kommt niemand mehr auf die Vereinsseite,
           auch nicht mit Wegklicken. Diese Entscheidung gehört dem, der den
           Server betreibt, nicht einem Plugin für eine Unterseite. */
        if ( is_ssl() && (string) LSV07P_DB::config( 'hsts', '0' ) === '1' ) {
            header( 'Strict-Transport-Security: max-age=31536000' );
        }
    }

    /**
     * Steht der Shortcode auf dieser Seite? Mehrere Hooks fragen das pro
     * Aufruf — gemerkt wird aber nur ein verlässliches Ergebnis: Manche
     * Hooks feuern, bevor $post steht, und ein dann gemerktes „nein" würde
     * das Vollbild für den ganzen Aufruf zerstören.
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

        if ( has_shortcode( $post->post_content, 'swimplan' )
          || has_shortcode( $post->post_content, 'lsv07_swimplan' ) ) {
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
                      && ( strpos( $w, '[swimplan' ) !== false
                        || strpos( $w, '[lsv07_swimplan' ) !== false ) ) {
                        return self::$hat_sc = true;
                    }
                }
            }
        }
        return self::$hat_sc = false;
    }

    public static function assets() {
        if ( ! self::seite_hat_shortcode() ) return;
        wp_enqueue_style( 'lsv07p-style', LSV07P_URL . 'assets/css/style.css', [], LSV07P_VERSION );
        wp_enqueue_script( 'jquery' );
    }

    /**
     * Die Stilvorlagen des Themes abräumen.
     *
     * Die eigene Seitenvorlage sorgt dafür, dass es kein Theme-MARKUP gibt.
     * Das Theme-CSS kam bisher trotzdem mit, und das reicht für einen
     * sichtbaren Rahmen: Viele Themes setzen Polster auf `body`, Blockthemen
     * schicken über `global-styles` Randabstände und eine Inhaltsbreite mit,
     * und beides legt sich um eine Anwendung, die die ganze Fläche haben
     * soll.
     *
     * Entfernt wird darum gezielt, was zum Theme oder zur Blockdarstellung
     * gehört — nicht alles: Die Stilvorlagen anderer Plugins bleiben, damit
     * ein Cookie-Hinweis oder ein Chat-Fenster nicht unformatiert im Raum
     * steht. Abschaltbar über `lsv07p_vollbild_rein = 0`.
     */
    public static function fremde_stile_weg() {
        if ( ! self::seite_hat_shortcode() ) return;
        if ( (string) LSV07P_DB::config( 'vollbild', '1' ) !== '1' ) return;
        if ( (string) LSV07P_DB::config( 'vollbild_rein', '1' ) !== '1' ) return;
        if ( ! function_exists( 'wp_styles' ) ) return;

        $stile = wp_styles();
        if ( ! $stile || empty( $stile->queue ) ) return;

        /* Was der Kern für Blockinhalte mitschickt. Auf dieser Seite gibt es
           keine Blöcke — nur die Anwendung. */
        $kern = [ 'wp-block-library', 'wp-block-library-theme', 'global-styles',
                  'classic-theme-styles', 'wp-webfonts', 'wp-emoji-styles' ];

        /* Und alles, was aus dem Verzeichnis des Themes kommt. Daran lässt
           sich ein Theme sicherer erkennen als am Namen seines Handles. */
        $orte = [];
        if ( function_exists( 'get_template_directory_uri' ) ) {
            $orte[] = trailingslashit( get_template_directory_uri() );
        }
        if ( function_exists( 'get_stylesheet_directory_uri' ) ) {
            $orte[] = trailingslashit( get_stylesheet_directory_uri() );
        }

        /* Erst sammeln, dann entfernen: Wer die Warteschlange beim Gehen
           verändert, überspringt Einträge. */
        $weg = [];
        foreach ( (array) $stile->queue as $handle ) {
            if ( in_array( $handle, $kern, true ) ) { $weg[] = $handle; continue; }

            $quelle = isset( $stile->registered[ $handle ] )
                ? (string) $stile->registered[ $handle ]->src : '';
            if ( $quelle === '' ) continue;
            foreach ( $orte as $ort ) {
                if ( $ort !== '' && strpos( $quelle, $ort ) === 0 ) { $weg[] = $handle; break; }
            }
        }

        /* Die eigenen bleiben auf jeden Fall — auch dann, wenn jemand das
           Plugin ins Theme-Verzeichnis gelegt hat. */
        foreach ( $weg as $handle ) {
            if ( strpos( (string) $handle, 'lsv07p' ) === 0 ) continue;
            wp_dequeue_style( $handle );
        }

        /* Das eigene CSS des Themes aus dem Customizer kommt nicht über die
           Warteschlange, sondern direkt im Kopf. */
        remove_action( 'wp_head', 'wp_custom_css_cb', 101 );
    }

    public static function body_class( $klassen ) {
        if ( self::seite_hat_shortcode() ) $klassen[] = 'lsv07p-vollbild';
        return $klassen;
    }

    public static function adminbar( $zeigen ) {
        return self::seite_hat_shortcode() ? false : $zeigen;
    }

    public static function kopf() {
        if ( ! self::seite_hat_shortcode() ) return;

        /* WordPress reserviert per CSS 32 px oben für die Adminleiste. */
        echo '<style id="lsv07p-ohne-adminleiste">html{margin-top:0 !important}'
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
        /* Der harte Rücksetzer. Er steht hier und nicht nur in der
           Stilvorlage, weil er dann ganz am Ende des Kopfes landet und
           damit auch das überschreibt, was ein Theme oder ein Plugin erst
           spät dazuschreibt. Er spricht `html` und `body` ohne Klasse an:
           Die Klasse hängt am <body>, das Polster aber oft am <html>.

           Nur im Vollbildmodus. Wer die Anwendung ausdrücklich im Rahmen
           seines Themes haben will, bekommt dessen Abstände auch. */
        $hart = '';
        if ( (string) LSV07P_DB::config( 'vollbild', '1' ) === '1' ) {
            $hart = 'html,body{margin:0 !important;padding:0 !important;'
                  . 'border:0 !important;max-width:none !important;'
                  . 'width:auto !important}';
        }
        echo '<style id="lsv07p-grundfarbe">html{color-scheme:light}'
           . 'html,body{background:#f6f8fc !important}'
           . $hart . '</style>' . "\n";

        /* Eine Seite mit Trainingsdaten gehört in keinen Suchindex. */
        echo '<meta name="robots" content="noindex, nofollow">' . "\n";
    }

    public static function render() {
        wp_enqueue_style( 'lsv07p-style', LSV07P_URL . 'assets/css/style.css', [], LSV07P_VERSION );
        wp_enqueue_script( 'jquery' );
        self::$ausgeben = true;

        ob_start();
        include LSV07P_DIR . 'templates/app.php';
        return ob_get_clean();
    }

    /**
     * Die Startdaten. Für ein Konto ohne Eintrag steht hier nichts als
     * `zugang: false` — kein Name, keine Mannschaft, keine Rolle.
     */
    public static function skripte() {
        if ( ! self::$ausgeben ) return;

        $zugang = LSV07P_Rollen::hat_zugang();

        $daten = [
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'lsv07p_nonce' ),
            'logo'       => LSV07P_URL . 'assets/img/swimplan-logo.svg',
            'marke'      => LSV07P_URL . 'assets/img/swimplan-marke.svg',
            'angemeldet' => is_user_logged_in(),
            'zugang'     => $zugang,
            'anmelden'   => wp_login_url( self::hier() ),
            'abmelden'   => wp_logout_url( self::hier() ),
        ];

        if ( $zugang ) {
            $daten['rolle']       = LSV07P_Rollen::rolle();
            $daten['rolle_name']  = LSV07P_Rollen::ROLLEN[ LSV07P_Rollen::rolle() ] ?? '';
            $daten['name']        = LSV07P_Rollen::name( LSV07P_Rollen::user_id() );
            $daten['user_id']     = LSV07P_Rollen::user_id();
            $daten['darf_planen'] = LSV07P_Rollen::darf_planen();
            $daten['ist_admin']   = LSV07P_Rollen::ist_admin();
            $daten['lagen']       = LSV07P_Rechnung::LAGEN;
            $daten['strecken']    = LSV07P_Zeiten::STRECKEN;
            $daten['status']      = LSV07P_Plan::STATUS;
            $daten['wege']        = LSV07P_Plan::WEGE;
            $daten['kategorien']  = LSV07P_Vorlage::KATEGORIEN;
            $daten['buehne_max']  = LSV07P_Ajax_Buehne::BUEHNE_MAX;
            $daten['layout']      = LSV07P_Ajax_Buehne::layout_lesen( LSV07P_Rollen::user_id() );
            $daten['richtwert']   = LSV07P_Rechnung::richtwert_100();
        }

        echo '<script>window.LSV07P = ' . wp_json_encode( $daten ) . ';</script>' . "\n";
        /* Direkt ausgegeben statt per wp_enqueue_script: Dieser Hook läuft
           bei Priorität 99 und damit NACH der Ausgabe der Fußzeilen-Skripte
           — ein Enqueue käme hier zu spät und das Skript fehlte ganz. */
        echo '<script src="' . esc_url( LSV07P_URL . 'assets/js/app.js?v=' . LSV07P_VERSION )
           . '"></script>' . "\n";
    }

    /** Die Adresse dieser Seite — für Anmelden und Abmelden zurück hierher. */
    private static function hier() {
        $id = get_queried_object_id();
        return $id ? get_permalink( $id ) : home_url( '/' );
    }
}
