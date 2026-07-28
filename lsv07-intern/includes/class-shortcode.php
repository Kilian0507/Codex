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
        // Fehlgeschlagene Anmeldung: statt der Standard-wp-login.php-
        // Fehlerseite zurück auf unser eigenes, dunkel gestaltetes
        // Anmeldeformular leiten (siehe render_login_form()).
        add_action( 'wp_login_failed', [ __CLASS__, 'on_login_failed' ] );
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
     * Eigenes, dunkel gestaltetes Anmeldeformular direkt auf der internen
     * Seite statt eines Buttons, der zur separaten WordPress-Login-Seite
     * (wp-login.php) verlässt. Nutzt bewusst die WordPress-eigene
     * wp_login_form()-Funktion für die eigentliche Anmeldung — die
     * übernimmt Sicherheit (Nonce, Passwort-Hashing, Filter/Hooks anderer
     * Plugins wie Zwei-Faktor-Auth) unverändert; wir stylen hier nur.
     *
     * redirect zeigt auf die AKTUELLE URL: Da man ja schon auf der
     * richtigen (internen) Seite steht, ist eine erfolgreiche Anmeldung
     * für den Nutzer nichts weiter als ein Neuladen derselben Seite —
     * kein Sprung auf eine andere URL.
     */
    private static function render_login_form() {
        $current_url = home_url( add_query_arg( null, null ) );

        $error_html = '';
        if ( isset( $_GET['login_error'] ) ) {
            $error_html = '<div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.4);color:#fca5a5;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:18px">'
                . 'Benutzername oder Passwort ist leider falsch. Bitte erneut versuchen.</div>';
        }

        ob_start();
        ?>
        <div style="max-width:380px;margin:60px auto;padding:32px 28px;background:#ffffff;border:1px solid rgba(30,58,95,0.10);border-radius:14px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1c1d2e;box-shadow:0 8px 32px rgba(30,58,95,0.12)">
            <h2 style="margin:0 0 4px;font-size:20px;color:#1e3a5f">LSV07 Intern</h2>
            <p style="margin:0 0 22px;font-size:13px;color:#6b6e85">Bitte melde dich an, um fortzufahren.</p>
            <?php echo $error_html; ?>
            <?php
            wp_login_form( [
                'redirect'       => $current_url,
                'form_id'        => 'lsv07i-loginform',
                'label_username' => 'Benutzername oder E-Mail',
                'label_password' => 'Passwort',
                'label_remember' => 'Angemeldet bleiben',
                'label_log_in'   => 'Anmelden',
                'remember'       => true,
            ] );
            ?>
            <style>
                #lsv07i-loginform p{margin:0 0 14px}
                #lsv07i-loginform label{display:block;font-size:12px;font-weight:600;color:#454864;margin-bottom:6px}
                #lsv07i-loginform input[type=text],#lsv07i-loginform input[type=password]{
                    width:100%;box-sizing:border-box;padding:10px 12px;border-radius:8px;
                    border:1px solid rgba(30,58,95,0.15);background:#f7f9fc;color:#1c1d2e;font-size:15px;
                }
                #lsv07i-loginform input[type=text]:focus,#lsv07i-loginform input[type=password]:focus{
                    outline:none;border-color:#3c7efc;background:#ffffff;
                }
                #lsv07i-loginform input[type=submit]{
                    width:100%;padding:11px;border:none;border-radius:8px;background:#3c7efc;color:#fff;
                    font-size:15px;font-weight:600;cursor:pointer;margin-top:6px;
                }
                #lsv07i-loginform input[type=submit]:hover{background:#2a5fd0}
                #lsv07i-loginform p.forgetmenot{display:flex;align-items:center;gap:8px;font-size:13px;color:#6b6e85}
                #lsv07i-loginform p.forgetmenot label{margin:0;font-weight:400;color:inherit}
                #lsv07i-loginform p.forgetmenot input{width:auto}
            </style>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Bei falschem Benutzernamen/Passwort NICHT auf die Standard-
     * wp-login.php-Fehlerseite lassen, sondern zurück auf unsere eigene
     * Anmeldeseite leiten (mit Hinweis). Nur wenn das Formular tatsächlich
     * von unserer Seite kam (redirect_to zeigt auf eine eigene URL) — bei
     * Anmeldeversuchen über die normale WP-Login-Seite bleibt das
     * Standardverhalten unangetastet.
     */
    public static function on_login_failed( $username ) {
        if ( empty( $_POST['redirect_to'] ) ) return;
        $redirect_to = wp_unslash( $_POST['redirect_to'] );
        if ( strpos( $redirect_to, home_url() ) !== 0 ) return;
        wp_safe_redirect( add_query_arg( 'login_error', '1', $redirect_to ) );
        exit;
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
            return self::render_login_form();
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
            // SheetJS liegt lokal im Plugin und wird erst bei Bedarf nachgeladen
            // (Excel-Import/-Export) — keine Anfrage an ein fremdes CDN.
            'xlsx_url'         => LSV07I_URL . 'assets/js/vendor/xlsx.full.min.js?v=' . LSV07I_VERSION,
        ];

        // Daten + Script ganz am Ende des Footers ausgeben
        // jQuery ist zu diesem Zeitpunkt garantiert geladen
        echo "\n<script>var LSV07I=" . wp_json_encode( $data ) . ";</script>\n";
        // jsPDF (lokal) für direkten PDF-Download der Abrechnungen
        echo '<script src="' . esc_url( LSV07I_URL . 'assets/js/vendor/jspdf.umd.min.js' ) . '?v=' . LSV07I_VERSION . '"></script>' . "\n";
        echo '<script src="' . esc_url( LSV07I_URL . 'assets/js/app.js' ) . '?v=' . LSV07I_VERSION . '"></script>' . "\n";
    }
}
