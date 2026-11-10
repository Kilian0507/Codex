<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Shortcode {

    public static function init() {
        add_shortcode( 'lsv07_intern', [ __CLASS__, 'render' ] );
        // Styles früh registrieren UND (falls Shortcode auf der Seite) enqueuen,
        // damit sie korrekt im <head> landen statt verspätet im Footer.
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        // Script + Daten im Footer ausgeben – nach jQuery
        add_action( 'wp_footer', [ __CLASS__, 'print_scripts' ], 99 );
        // Body-Klasse für Vollbild-Modus
        add_filter( 'body_class', [ __CLASS__, 'body_class' ] );
        // Adminbar verstecken wenn der Shortcode auf der Seite ist
        add_filter( 'show_admin_bar', [ __CLASS__, 'hide_admin_bar' ], 99 );
        // Adminbar-CSS-Margin (28/32px Top) auch wegnehmen
        add_action( 'wp_head', [ __CLASS__, 'override_admin_bar_css' ], 999 );
        /* Wer nicht angemeldet ist, wird zur Anmeldung von WordPress
           geschickt — und zwar so früh, dass noch nichts ausgegeben ist.
           Priorität 1, damit kein anderes Plugin vorher schreibt. */
        add_action( 'template_redirect', [ __CLASS__, 'zur_anmeldung' ], 1 );
    }

    /**
     * Lädt CSS im <head>, wenn die aktuelle Seite den Shortcode enthält.
     * Läuft bei wp_enqueue_scripts (vor wp_head) — der korrekte Zeitpunkt.
     * Ohne das landet das Enqueue aus render() zu spät und das Theme-CSS
     * wird teilweise gar nicht angezeigt.
     */
    public static function enqueue_assets() {
        if ( ! self::page_has_shortcode() ) return;
        if ( ! is_user_logged_in() ) return;

        wp_enqueue_style( 'lsv07i-style', LSV07I_URL . 'assets/css/style.css', [], LSV07I_VERSION );
        wp_enqueue_style( 'lsv07i-glass', LSV07I_URL . 'assets/css/glass.css', [ 'lsv07i-style' ], LSV07I_VERSION );
        wp_enqueue_script( 'jquery' );
    }

    /**
     * Prüft (mit Request-Cache), ob die aktuelle Singular-Seite den Shortcode
     * enthält. has_shortcode parst den kompletten Post-Content, daher cachen
     * wir das Ergebnis — es wird von mehreren Hooks pro Request abgefragt.
     * WICHTIG: Wir cachen nur ein VERLÄSSLICHES Ergebnis. Manche Hooks
     * (z. B. show_admin_bar) feuern, bevor das globale $post gesetzt ist —
     * ein dann gecachtes "false" würde den Vollbild-Modus für den ganzen
     * Request kaputt machen. Daher nutzen wir die Queried-Object-ID und
     * cachen erst, wenn ein Post-Objekt wirklich vorliegt.
     */
    private static $has_sc_cache = null;
    private static function page_has_shortcode() {
        if ( self::$has_sc_cache !== null ) return self::$has_sc_cache;
        if ( ! is_singular() ) return false; // noch nicht cachen — kann sich ändern

        // Post zuverlässig holen: erst global $post, sonst über Queried Object
        $post = null;
        if ( ! empty( $GLOBALS['post'] ) ) {
            $post = $GLOBALS['post'];
        } else {
            $qid = get_queried_object_id();
            if ( $qid ) $post = get_post( $qid );
        }
        if ( ! $post ) return false; // $post noch nicht da → NICHT cachen, später erneut prüfen

        $result = has_shortcode( $post->post_content, 'lsv07_intern' );
        self::$has_sc_cache = $result;
        return $result;
    }

    /**
     * Adminbar verstecken wenn der Shortcode auf der Seite ist. Auch für
     * eingeloggte Admins — der interne Bereich soll wie eine eigenständige
     * Web-App wirken, nicht wie eine WordPress-Seite.
     */
    public static function hide_admin_bar( $show ) {
        return self::page_has_shortcode() ? false : $show;
    }

    /**
     * WordPress reserviert per CSS am html-Element 28/32px Top-Padding
     * für die Adminbar. Wenn wir sie ausblenden, das Padding auch.
     */
    public static function override_admin_bar_css() {
        if ( ! self::page_has_shortcode() ) return;
        echo '<style id="lsv07i-no-adminbar">html{margin-top:0 !important}* html body{margin-top:0 !important}@media screen and (max-width:782px){html{margin-top:0 !important}* html body{margin-top:0 !important}}</style>' . "\n";

        /* Browser-Leisten dunkel (weisser Rand oben/unten auf dem Handy):
           1) theme-color SERVERSEITIG im <head>: Safari wertet die Angabe
              beim ersten Parsen sicher aus (dynamisch per JS eingefuegte
              wurde ignoriert) und faerbt Adress-/Statusleiste ein.
           2) viewport-fit=cover: zieht die Seite HINTER die Leisten bzw.
              in die Safe-Areas (Notch oben, Home-Indicator unten) — unser
              dunkler Hintergrund malt dann selbst bis an die Bildschirm-
              kanten, unabhaengig von jeder Safari-Einstellung. Dieses
              viewport-Meta laeuft mit Prioritaet 999 NACH dem des Themes;
              bei mehreren viewport-Metas gilt das letzte.
           3) Sofort-Hintergrund fuer html/body: dunkel ab dem allerersten
              Paint (die CSS-Klassen kommen erst spaeter per JS), sonst
              blitzt Weiss und Safari uebernimmt Weiss als Leistenfarbe.  */
        echo '<meta name="theme-color" content="#eef3fb">' . "\n";
        echo '<meta name="color-scheme" content="light">' . "\n";
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n";
        /* color-scheme:light ist der entscheidende Schalter fuer Safari:
           erst damit rendert der Browser seine EIGENEN Flaechen (Leisten-
           Hintergrund, Canvas hinter der Seite, Formulare, Scrollbars)
           sicher im Hell-Stil, auch wenn das Geraet im dunklen System-
           Modus laeuft — theme-color allein reicht dafuer nicht.         */
        echo '<style id="lsv07i-early-bg">html{color-scheme:light}html,body{background:#eef3fb !important}</style>' . "\n";
    }

    private static $should_render = false;

    /**
     * Die Adresse der Anmeldung — EINE Stelle.
     *
     * `wp_login_url()` und nicht `/wp-login.php` von Hand: Verschiebt ein
     * Plugin die Anmeldeseite (das tun Sicherheits-Plugins regelmässig),
     * zeigt der Weg weiterhin dorthin, wo die Anmeldung wirklich liegt.
     * Ohne solches Plugin ist das Ergebnis genau `/wp-login.php`.
     *
     * Das Ziel nach der Anmeldung ist die AKTUELLE Seite: Man steht ja
     * schon auf der richtigen (internen) Seite, also ist die erfolgreiche
     * Anmeldung nichts weiter als ein Neuladen derselben Adresse.
     */
    public static function anmelde_url() {
        return wp_login_url( home_url( add_query_arg( null, null ) ) );
    }

    /**
     * Nicht angemeldet → zur Anmeldung von WordPress.
     *
     * Hier und nicht im Shortcode: Wenn der Shortcode läuft, ist der
     * Seitenkopf längst geschrieben und eine Weiterleitung über Kopfzeilen
     * nicht mehr möglich. `template_redirect` läuft davor.
     *
     * Ein eigenes Anmeldeformular gibt es bewusst nicht mehr. Es war eine
     * zweite Stelle, an der Benutzername und Passwort eingegeben werden —
     * mit eigenem Aussehen, eigener Fehlerbehandlung und der Pflicht, bei
     * jeder Änderung an WordPress mitzuhalten. Die Anmeldung von WordPress
     * kann all das besser: Sperren nach Fehlversuchen,
     * Zwei-Faktor-Plugins, Passwort vergessen, Sprache, Barrierefreiheit.
     */
    public static function zur_anmeldung() {
        if ( is_user_logged_in() ) return;
        if ( ! self::page_has_shortcode() ) return;
        /* Ein abgeschicktes Formular wird nicht weitergeleitet — die
           eingegebenen Daten wären sonst weg. Dasselbe gilt für
           AJAX-Aufrufe und für Feeds. */
        if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' ) return;
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) return;
        if ( is_feed() || is_robots() ) return;
        /* Steht schon Ausgabe, geht keine Weiterleitung mehr. Dann
           übernimmt die Seite selbst (render_anmeldung()). */
        if ( headers_sent() ) return;

        /* Zeigt die Anmeldung auf diese Seite selbst, wird nicht
           weitergeleitet. Das kann passieren, wenn ein Sicherheits-Plugin
           `wp_login_url()` umbiegt — und wäre eine endlose Schleife. Dann
           bleibt der sichtbare Verweis, und der zeigt wenigstens
           irgendwohin. */
        $ziel  = self::anmelde_url();
        $hier  = home_url( add_query_arg( null, null ) );
        if ( strtok( $ziel, '?' ) === strtok( $hier, '?' ) ) return;

        /* Keine Zwischenspeicherung: Eine gespeicherte Weiterleitung
           würde auch angemeldete Besucher hinausschicken. */
        nocache_headers();
        wp_safe_redirect( $ziel, 302 );
        exit;
    }

    /**
     * Was auf der Seite steht, wenn die Weiterleitung NICHT greifen
     * konnte — weil ein anderes Plugin schon geschrieben hat, weil ein
     * Seiten-Zwischenspeicher die Seite ausliefert oder weil der Shortcode
     * in einem Zusammenhang läuft, in dem `template_redirect` nicht feuert.
     *
     * Drei Wege, absteigend nach Verlässlichkeit:
     *  1. Die Weiterleitung über Kopfzeilen (zur_anmeldung(), oben).
     *  2. Ein kleines Skript, das dieselbe Adresse aufruft.
     *  3. Ein sichtbarer Verweis, der immer da ist — auch ohne Skripte.
     *
     * Der Verweis ist bewusst keine Notlösung im Kleingedruckten, sondern
     * der Knopf in der Mitte: Wer hier landet, soll einen Klick vom Ziel
     * entfernt sein und nicht rätseln.
     */
    private static function render_anmeldung() {
        $url = self::anmelde_url();
        ob_start();
        ?>
        <div style="max-width:420px;margin:60px auto;padding:32px 28px;background:#ffffff;border:1px solid rgba(30,58,95,0.10);border-radius:14px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1c1d2e;box-shadow:0 8px 32px rgba(30,58,95,0.12);text-align:center">
            <h2 style="margin:0 0 6px;font-size:20px;color:#1e3a5f">LSV07 Intern</h2>
            <p style="margin:0 0 20px;font-size:13.5px;color:#6b6e85;line-height:1.5">
                Für den internen Bereich ist eine Anmeldung nötig.
                Du wirst gleich dorthin weitergeleitet.
            </p>
            <a id="lsv07i-anmelden" href="<?php echo esc_url( $url ); ?>"
               style="display:block;padding:12px 16px;border-radius:8px;background:#3c7efc;color:#fff;font-size:15px;font-weight:600;text-decoration:none">
                Zur Anmeldung
            </a>
            <p style="margin:16px 0 0;font-size:12px;color:#8b8fa3;line-height:1.5">
                Passiert nichts? Dann bitte auf <strong>„Zur Anmeldung"</strong> klicken.
            </p>
        </div>
        <script>
        /* Zweiter Weg, falls die Weiterleitung über die Kopfzeilen nicht
           möglich war. replace() statt href: Die Anmeldeseite soll nicht
           als eigener Schritt im Verlauf liegen, sonst landet man mit
           „Zurück" wieder hier. Der Verweis darüber bleibt der Weg, der
           ohne Skripte funktioniert. */
        (function () {
            var ziel = document.getElementById('lsv07i-anmelden');
            if (!ziel) return;
            setTimeout(function () { window.location.replace(ziel.href); }, 150);
        })();
        </script>
        <?php
        return ob_get_clean();
    }


    /**
     * Body-Klasse 'lsv07i-fullscreen' setzen, wenn der Shortcode auf der
     * aktuellen Seite verwendet wird. Das CSS nutzt die Klasse, um den
     * Theme-Container/Sidebar/Footer auszuhebeln.
     */
    public static function body_class( $classes ) {
        if ( self::page_has_shortcode() ) {
            $classes[] = 'lsv07i-fullscreen';
            $classes[] = 'lsv07i-active';
        }
        return $classes;
    }

    public static function render() {
        if ( ! is_user_logged_in() ) {
            return self::render_anmeldung();
        }

        if ( ! LSV07I_Access::has_any_access() ) {
            return '<div style="background:#fee2e2;border-left:4px solid #dc2626;padding:14px 18px;border-radius:6px;font-family:sans-serif;margin:16px 0">'
                . '<strong>Kein Zugriff.</strong> Ihr Konto hat keine Berechtigung fuer den internen Bereich. '
                . 'Bitte wenden Sie sich an den Administrator.</div>';
        }

        // CSS laden
        wp_enqueue_style( 'lsv07i-style', LSV07I_URL . 'assets/css/style.css', [], LSV07I_VERSION );
        // Glasmorphism-Theme (dunkles Grau, weiße Schrift, Blur) — Layer über style.css
        wp_enqueue_style( 'lsv07i-glass', LSV07I_URL . 'assets/css/glass.css', [ 'lsv07i-style' ], LSV07I_VERSION );

        // jQuery sicherstellen
        wp_enqueue_script( 'jquery' );

        // Footer-Hook aktivieren
        self::$should_render = true;

        ob_start();
        include LSV07I_DIR . 'templates/main.php';
        return ob_get_clean();
    }

    public static function print_scripts() {
        if ( ! self::$should_render ) return;

        $data = [
            'ajax_url'         => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'lsv07i_nonce' ),
            'user_id'          => get_current_user_id(),
            'user_name'        => wp_get_current_user()->display_name,
            'access'           => LSV07I_Access::get_access_map(),
            'is_trainer'       => LSV07I_Access::is_trainer(),
            'trainer_id'       => LSV07I_Access::get_trainer_id(),
            'sample_trainer_url'  => add_query_arg( [
                'lsv07i_sample' => 'trainer',
                '_wpnonce'      => wp_create_nonce( 'lsv07i_sample' ),
            ], home_url( '/' ) ),
            'sample_schwimmer_url' => add_query_arg( [
                'lsv07i_sample' => 'schwimmer',
                '_wpnonce'      => wp_create_nonce( 'lsv07i_sample' ),
            ], home_url( '/' ) ),
            'strecken'         => class_exists( 'LSV07I_Ajax_Bestzeiten' ) ? LSV07I_Ajax_Bestzeiten::STRECKEN : [],
            /* Wo abgerechnet wird. Die Oberfläche braucht den Weg, um
               beim Umschalten und beim Wiederherstellen des letzten
               Ortes zu wissen, welche Bereiche es gerade gibt. */
            'abrechnung_modus' => class_exists( 'LSV07I_Abrechnungsweg' )
                                    ? LSV07I_Abrechnungsweg::modus() : 'intern',
            // Die selbst gewählte Standardmannschaft. Liegt im Bootstrap,
            // damit jeder Bereich sie ohne eigene Anfrage vorauswählen kann.
            'standard_mannschaft' => class_exists( 'LSV07I_Ajax_Mannschaftsbuch' )
                                       ? LSV07I_Ajax_Mannschaftsbuch::standard_mannschaft() : 0,
            // SheetJS liegt lokal im Plugin und wird erst bei Bedarf nachgeladen
            // (Excel-Import/-Export) — keine Anfrage an ein fremdes CDN.
            'xlsx_url'         => LSV07I_URL . 'assets/js/vendor/xlsx.full.min.js?v=' . LSV07I_VERSION,
            // pdf.js (ebenfalls lokal, erst bei Bedarf nachgeladen). Wird
            // gebraucht, um die PDFs eines Trainingsplans im Vollbild selbst
            // zu zeichnen — der eingebaute PDF-Betrachter des Browsers würde
            // seine eigene Werkzeugleiste mitbringen, am Becken soll aber nur
            // die Seite zu sehen sein.
            'pdfjs_url'        => LSV07I_URL . 'assets/js/vendor/pdf.min.js?v=' . LSV07I_VERSION,
            'pdfjs_worker_url' => LSV07I_URL . 'assets/js/vendor/pdf.worker.min.js?v=' . LSV07I_VERSION,
        ];

        // Daten + Script ganz am Ende des Footers ausgeben
        // jQuery ist zu diesem Zeitpunkt garantiert geladen
        echo "\n<script>var LSV07I=" . wp_json_encode( $data ) . ";</script>\n";
        // jsPDF (lokal) für direkten PDF-Download der Abrechnungen
        echo '<script src="' . esc_url( LSV07I_URL . 'assets/js/vendor/jspdf.umd.min.js' ) . '?v=' . LSV07I_VERSION . '"></script>' . "\n";
        echo '<script src="' . esc_url( LSV07I_URL . 'assets/js/app.js' ) . '?v=' . LSV07I_VERSION . '"></script>' . "\n";
    }
}
