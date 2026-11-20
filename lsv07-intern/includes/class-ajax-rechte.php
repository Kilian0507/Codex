<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AJAX-Endpunkte für das granulare Rechte-System (Iter 2).
 *
 * Endpunkte:
 *   lsv07i_rechte_userlist   – Liste aller User mit Anzahl Rechte
 *   lsv07i_rechte_user_get   – Alle Rechte eines bestimmten Users
 *   lsv07i_rechte_user_save  – Rechte eines Users setzen (komplette Liste)
 *   lsv07i_rechte_apply_tpl  – Template auf User anwenden
 *   lsv07i_rechte_meta       – Liefert Bereiche/Gruppen/Templates für die UI
 */
class LSV07I_Ajax_Rechte {

    public static function init() {
        add_action( 'wp_ajax_lsv07i_rechte_userlist',   [ __CLASS__, 'userlist'   ] );
        add_action( 'wp_ajax_lsv07i_rechte_user_get',   [ __CLASS__, 'user_get'   ] );
        add_action( 'wp_ajax_lsv07i_rechte_user_save',  [ __CLASS__, 'user_save'  ] );
        add_action( 'wp_ajax_lsv07i_rechte_apply_tpl',  [ __CLASS__, 'apply_tpl'  ] );
        add_action( 'wp_ajax_lsv07i_rechte_meta',       [ __CLASS__, 'meta'       ] );
        add_action( 'wp_ajax_lsv07i_rechte_vorschau',   [ __CLASS__, 'vorschau'   ] );
        add_action( 'wp_ajax_lsv07i_rechte_tpl_save',   [ __CLASS__, 'tpl_save'   ] );
        add_action( 'wp_ajax_lsv07i_rechte_tpl_delete', [ __CLASS__, 'tpl_delete' ] );
    }

    private static function check_admin() {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) LSV07I_Access::schluessel_abgelehnt();
        // Echter WP-Administrator ODER User mit dem Recht zur Rechte-Verwaltung.
        // can_current() defensiv in try/catch, falls die Permissions-Tabelle aus
        // irgendeinem Grund nicht initialisiert ist (sonst würde ein Fatal Error
        // einen leeren 400-Response erzeugen, der schwer zu diagnostizieren ist).
        $hat_recht = false;
        if ( LSV07I_Access::is_admin() ) {
            $hat_recht = true;
        } else {
            try {
                $hat_recht = LSV07I_Permissions::can_current( LSV07I_Permissions::ADMIN_PERMISSIONS_UPDATE );
            } catch ( Throwable $e ) {
                // Wenn die Permissions-Klasse selbst nicht funktioniert, ist nur Admin erlaubt
                $hat_recht = false;
            }
        }
        if ( ! $hat_recht ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }
    }

    /**
     * Ein unerwarteter Fehler: Die Einzelheiten (Meldung, Datei, Zeile)
     * gehören ins Server-Log, nicht in die Antwort — sie verraten Pfade
     * und Aufbau des Systems an jeden, der den Fehler auslösen kann.
     */
    private static function serverfehler( $e, $text ) {
        error_log( 'LSV07I rechte: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
        wp_send_json_error( [ 'message' => $text ] );
    }

    /**
     * Niemand ändert seine EIGENEN Rechte — auch kein Administrator.
     *
     * Vorher konnte sich jeder mit dem Recht zur Rechteverwaltung über
     * „Vorlage anwenden → Admin (volle Rechte)" selbst alles geben. Für
     * Administratoren ändert es nichts (can() gibt ihnen ohnehin alles);
     * für alle anderen wäre es der Generalschlüssel.
     */
    private static function nicht_selbst( $uid ) {
        if ( (int) $uid === get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Die eigenen Rechte kann nur jemand anderes ändern.' ], 403 );
        }
    }

    /**
     * Die Rechte der Rechteverwaltung (admin.permissions.*) vergibt und
     * entzieht nur ein echter WP-Administrator. LSV07I_Access::is_admin()
     * meint hier bewusst die WordPress-Rolle (nicht gesperrt), kein Recht
     * aus dieser Verwaltung — sonst könnte ein Rechteverwalter weitere
     * Rechteverwalter ernennen und sich so über einen Zweiten selbst
     * befördern.
     *
     * Für alle anderen bleibt dieser Teil beim Ziel so, wie er ist: Was
     * es hatte, behält es; was es nicht hatte, bekommt es nicht. So lässt
     * sich der Rest eines Rechteverwalters weiter bearbeiten, ohne dass
     * die Oberfläche etwas anders schicken muss.
     */
    private static function geschuetzte_beibehalten( $uid, $rights ) {
        if ( LSV07I_Access::is_admin() ) return array_values( $rights );
        global $wpdb;
        $geschuetzt = array_values( array_filter( LSV07I_Permissions::all_codes(),
            fn( $c ) => strpos( $c, 'admin.permissions.' ) === 0 ) );
        $bisher = (array) $wpdb->get_col( $wpdb->prepare(
            'SELECT recht FROM ' . LSV07I_Permissions::table() . ' WHERE user_id = %d', (int) $uid ) );
        $rights = array_diff( $rights, $geschuetzt );
        return array_values( array_unique( array_merge( $rights, array_intersect( $bisher, $geschuetzt ) ) ) );
    }

    /**
     * Metadaten für die UI: Bereiche/Gruppen/Rechte und Templates.
     * Wird einmal beim Öffnen des Tabs geladen.
     */
    public static function meta() {
        self::check_admin();
        try {
            wp_send_json_success( [
                'bereiche'  => LSV07I_Permissions::all_rights(),
                /* Ab 9.0.0 führt die Oberfläche über die MODULE: je Modul
                   nichts / lesen / bearbeiten, darunter die feinen
                   Rechte. Die flache Liste aller Einzelrechte bleibt
                   daneben — sie ist die vollständige Wahrheit, und wer
                   genau hinsehen will, soll das können. */
                'module'    => LSV07I_Permissions::module(),
                'templates' => LSV07I_Permissions::all_templates(),
            ] );
        } catch ( Throwable $e ) {
            self::serverfehler( $e, 'Server-Fehler beim Laden der Rechte-Definitionen.' );
        }
    }

    /**
     * Übersetzt zwischen den beiden Ansichten — ohne etwas zu speichern.
     *
     * Die Oberfläche zeigt dieselben Rechte zweimal: als Module mit
     * Stufen und als vollständige Liste der Einzelrechte. Wer zwischen
     * den Ansichten wechselt, soll in der anderen das sehen, was er
     * gerade eingestellt hat.
     *
     * Umgerechnet wird HIER und nicht im Browser. Die Regel, welche
     * Rechte eine Stufe bedeutet, steht an einer Stelle; eine zweite
     * Fassung in JavaScript wäre eine, die irgendwann etwas anderes
     * sagt als das Speichern.
     */
    public static function vorschau() {
        self::check_admin();
        $stufen_raw = $_POST['stufen'] ?? null;
        if ( $stufen_raw !== null ) {
            $stufen = is_string( $stufen_raw ) ? json_decode( wp_unslash( $stufen_raw ), true ) : $stufen_raw;
            $fein   = $_POST['fein'] ?? '[]';
            $fein   = is_string( $fein ) ? json_decode( wp_unslash( $fein ), true ) : $fein;
            if ( ! is_array( $stufen ) ) $stufen = [];
            if ( ! is_array( $fein ) )   $fein   = [];
            $rights = LSV07I_Permissions::rechte_aus_stufen( $stufen, $fein );
        } else {
            $rights_raw = $_POST['rights'] ?? '[]';
            $rights = is_string( $rights_raw ) ? json_decode( wp_unslash( $rights_raw ), true ) : $rights_raw;
            if ( ! is_array( $rights ) ) $rights = [];
            $rights = array_values( array_unique(
                array_intersect( $rights, LSV07I_Permissions::all_codes() ) ) );
        }
        wp_send_json_success( [
            'rights' => $rights,
            'stufen' => LSV07I_Permissions::stufen_von( $rights ),
        ] );
    }

    /**
     * Liefert die User-Liste mit Anzahl zugewiesener Rechte und WP-Rollen.
     * Optional: Filter nach Suchbegriff (Name/Email).
     */
    public static function userlist() {
        self::check_admin();
        try {
            global $wpdb;
            $tbl = LSV07I_Permissions::table();

            $search = sanitize_text_field( $_POST['search'] ?? '' );
            $args   = [
                'number'  => 200,
                'orderby' => 'display_name',
                'order'   => 'ASC',
            ];
            if ( $search !== '' ) {
                $args['search'] = '*' . $search . '*';
                $args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
            }
            $users = get_users( $args );

            // Anzahl Rechte pro User in einer einzigen Abfrage
            $counts = [];
            $rows   = $wpdb->get_results( "SELECT user_id, COUNT(*) AS n FROM $tbl GROUP BY user_id" );
            if ( is_array( $rows ) ) {
                foreach ( $rows as $r ) {
                    $counts[ (int) $r->user_id ] = (int) $r->n;
                }
            }

            $out = [];
            foreach ( $users as $u ) {
                $uid    = (int) $u->ID;
                $roles  = isset( $u->roles ) ? array_values( (array) $u->roles ) : [];
                $name   = ! empty( $u->display_name ) ? $u->display_name : $u->user_login;
                $email  = isset( $u->user_email ) ? $u->user_email : '';
                $n      = isset( $counts[ $uid ] ) ? $counts[ $uid ] : 0;
                $out[]  = [
                    'id'           => $uid,
                    'name'         => $name,
                    'email'        => $email,
                    'roles'        => $roles,
                    'count'        => $n,
                    'has_explicit' => $n > 0,
                ];
            }
            wp_send_json_success( $out );
        } catch ( Throwable $e ) {
            self::serverfehler( $e, 'Server-Fehler. Bitte erneut versuchen.' );
        }
    }

    /**
     * Liefert die Rechte-Liste eines bestimmten Users.
     */
    public static function user_get() {
        self::check_admin();
        global $wpdb;
        $uid = (int) ( $_POST['user_id'] ?? 0 );
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'User-ID fehlt.' ] );

        $u = get_user_by( 'id', $uid );
        if ( ! $u ) wp_send_json_error( [ 'message' => 'User nicht gefunden.' ] );

        $tbl  = LSV07I_Permissions::table();
        $rows = $wpdb->get_col( $wpdb->prepare( "SELECT recht FROM $tbl WHERE user_id=%d", $uid ) );

        $rights = is_array( $rows ) ? $rows : [];
        wp_send_json_success( [
            'user_id'      => $uid,
            'name'         => $u->display_name ?: $u->user_login,
            'email'        => $u->user_email,
            'roles'        => array_values( (array) $u->roles ),
            'rights'       => $rights,
            /* Die Stufen werden hier gerechnet, nicht im Browser: Die
               Regel, welche Stufe eine Rechtelage ergibt, gehört an eine
               Stelle — sonst zeigt die Oberfläche etwas anderes an, als
               das Speichern daraus macht. */
            'stufen'       => LSV07I_Permissions::stufen_von( $rights ),
            'has_explicit' => count( $rights ) > 0,
        ] );
    }

    /**
     * Speichert die Rechte eines Users — komplette Liste, alles andere wird gelöscht.
     */
    public static function user_save() {
        self::check_admin();
        $uid = (int) ( $_POST['user_id'] ?? 0 );
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'User-ID fehlt.' ] );

        self::nicht_selbst( $uid );

        /* Zwei Wege führen hierher.
           • Die Modulansicht schickt Stufen und feine Rechte. Daraus
             wird die Rechteliste HIER gebaut — nicht im Browser. Was aus
             dem Browser kommt, ist ein Wunsch, keine Rechtelage.
           • Die Einzelrechte-Ansicht schickt die Liste direkt. */
        $stufen_raw = $_POST['stufen'] ?? null;
        if ( $stufen_raw !== null ) {
            $stufen = is_string( $stufen_raw ) ? json_decode( wp_unslash( $stufen_raw ), true ) : $stufen_raw;
            $fein   = $_POST['fein'] ?? '[]';
            $fein   = is_string( $fein ) ? json_decode( wp_unslash( $fein ), true ) : $fein;
            if ( ! is_array( $stufen ) ) $stufen = [];
            if ( ! is_array( $fein ) )   $fein   = [];
            $rights = LSV07I_Permissions::rechte_aus_stufen( $stufen, $fein );
        } else {
            $rights_raw = $_POST['rights'] ?? '[]';
            if ( is_string( $rights_raw ) ) {
                $rights = json_decode( wp_unslash( $rights_raw ), true );
            } else {
                $rights = $rights_raw;
            }
            if ( ! is_array( $rights ) ) $rights = [];
        }

        // Nur gültige Codes akzeptieren
        $valid  = LSV07I_Permissions::all_codes();
        $rights = array_values( array_unique( array_intersect( $rights, $valid ) ) );

        $rights = self::geschuetzte_beibehalten( $uid, $rights );

        LSV07I_Permissions::set_user_rights( $uid, $rights, get_current_user_id() );
        $target_user = get_user_by( 'id', $uid );
        LSV07I_Log::write( 'rechte.user.update', [
            'bereich'   => 'Rechte',
            'ziel_typ'  => 'user',
            'ziel_id'   => $uid,
            'ziel_name' => $target_user ? ( $target_user->display_name ?: $target_user->user_login ) : '?',
            'details'   => count( $rights ) . ' Rechte gesetzt',
        ] );
        wp_send_json_success( [
            'message' => 'Rechte gespeichert.',
            'count'   => count( $rights ),
            /* Zurückgegeben wird, was wirklich gespeichert wurde. Die
               Oberfläche zeigt danach den gespeicherten Stand und nicht
               den, den sie geschickt hat — die beiden können sich
               unterscheiden (Selbstschutz, verworfene Codes). */
            'rights'  => $rights,
            'stufen'  => LSV07I_Permissions::stufen_von( $rights ),
        ] );
    }

    /**
     * Wendet ein Template auf einen User an. Bestehende Rechte werden ersetzt.
     */
    public static function apply_tpl() {
        self::check_admin();
        $uid = (int) ( $_POST['user_id'] ?? 0 );
        $tpl = sanitize_text_field( $_POST['template'] ?? '' );
        if ( ! $uid || ! $tpl ) wp_send_json_error( [ 'message' => 'User oder Template fehlt.' ] );
        self::nicht_selbst( $uid );
        // „Admin (volle Rechte)" vergibt nur ein echter WP-Administrator
        // (Rolle, nicht Recht — siehe geschuetzte_beibehalten()).
        if ( $tpl === 'admin_full' && ! LSV07I_Access::is_admin() ) {
            wp_send_json_error( [ 'message' => 'Die Vorlage „Admin (volle Rechte)" vergibt nur ein Administrator.' ], 403 );
        }

        $tpls = LSV07I_Permissions::all_templates();
        if ( ! isset( $tpls[ $tpl ] ) ) {
            wp_send_json_error( [ 'message' => 'Unbekanntes Template.' ] );
        }

        // Auch eine eigene Vorlage könnte admin.permissions.* enthalten.
        $rights = self::geschuetzte_beibehalten( $uid, $tpls[ $tpl ]['rights'] );

        LSV07I_Permissions::set_user_rights( $uid, $rights, get_current_user_id() );
        $target_user = get_user_by( 'id', $uid );
        LSV07I_Log::write( 'rechte.template.apply', [
            'bereich'   => 'Rechte',
            'ziel_typ'  => 'user',
            'ziel_id'   => $uid,
            'ziel_name' => $target_user ? ( $target_user->display_name ?: $target_user->user_login ) : '?',
            'details'   => 'Template: ' . $tpls[ $tpl ]['name'],
        ] );
        wp_send_json_success( [
            'message'  => 'Vorlage „' . $tpls[ $tpl ]['name'] . '" angewendet.',
            'count'    => count( $rights ),
            'rights'   => $rights,
            'stufen'   => LSV07I_Permissions::stufen_von( $rights ),
        ] );
    }

    /**
     * Custom-Template anlegen oder aktualisieren.
     */
    public static function tpl_save() {
        self::check_admin();
        try {
            $id           = absint( $_POST['id'] ?? 0 );
            $name         = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
            $beschreibung = sanitize_textarea_field( wp_unslash( $_POST['beschreibung'] ?? '' ) );

            /* Eine Vorlage lässt sich auch aus der Modulansicht
               anlegen — „so wie alle Trainer". Dann kommen Stufen
               herein, und die Rechteliste wird hier daraus gebaut. */
            $stufen_raw = $_POST['stufen'] ?? null;
            if ( $stufen_raw !== null ) {
                $stufen = is_string( $stufen_raw ) ? json_decode( wp_unslash( $stufen_raw ), true ) : $stufen_raw;
                $fein   = $_POST['fein'] ?? '[]';
                $fein   = is_string( $fein ) ? json_decode( wp_unslash( $fein ), true ) : $fein;
                if ( ! is_array( $stufen ) ) $stufen = [];
                if ( ! is_array( $fein ) )   $fein   = [];
                $rights = LSV07I_Permissions::rechte_aus_stufen( $stufen, $fein );
            } else {
                $rights_raw = $_POST['rights'] ?? '[]';
                if ( is_string( $rights_raw ) ) {
                    $rights = json_decode( wp_unslash( $rights_raw ), true );
                } else {
                    $rights = $rights_raw;
                }
                if ( ! is_array( $rights ) ) $rights = [];
            }

            $result = LSV07I_Permissions::save_custom_template( $id, $name, $beschreibung, $rights );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => $result->get_error_message() ] );
            }

            LSV07I_Log::write( $id ? 'rechte.template.update' : 'rechte.template.create', [
                'bereich'   => 'Rechte',
                'ziel_typ'  => 'template',
                'ziel_id'   => $result,
                'ziel_name' => $name,
                'details'   => count( $rights ) . ' Rechte',
            ] );
            wp_send_json_success( [
                'id'      => $result,
                'message' => $id ? 'Template aktualisiert.' : 'Template angelegt.',
            ] );
        } catch ( Throwable $e ) {
            self::serverfehler( $e, 'Server-Fehler. Bitte erneut versuchen.' );
        }
    }

    /**
     * Template löschen.
     * - Custom-Templates (custom_<n>): werden aus der DB gelöscht.
     * - System-Templates: werden in einer Option als "ausgeblendet" markiert.
     *   Das eigentliche Template bleibt in der PHP-Klasse erhalten und kann
     *   technisch wiederhergestellt werden, ist aber aus der UI verschwunden.
     */
    public static function tpl_delete() {
        self::check_admin();
        $tid = sanitize_text_field( wp_unslash( $_POST['template'] ?? '' ) );
        if ( $tid === '' ) {
            wp_send_json_error( [ 'message' => 'Template-ID fehlt.' ] );
        }

        // Custom-Template?
        if ( strpos( $tid, 'custom_' ) === 0 ) {
            $db_id = (int) substr( $tid, 7 );
            if ( $db_id <= 0 ) {
                wp_send_json_error( [ 'message' => 'Ungültige Template-ID.' ] );
            }
            $ok = LSV07I_Permissions::delete_custom_template( $db_id );
            if ( ! $ok ) {
                wp_send_json_error( [ 'message' => 'Löschen fehlgeschlagen.' ] );
            }
            LSV07I_Log::write( 'rechte.template.delete', [
                'bereich'  => 'Rechte',
                'ziel_typ' => 'template',
                'ziel_id'  => $db_id,
                'details'  => 'Custom-Template gelöscht',
            ] );
            wp_send_json_success( [ 'message' => 'Template gelöscht.' ] );
        }

        // System-Template? Existiert es überhaupt?
        $built = LSV07I_Permissions::built_in_templates();
        if ( ! isset( $built[ $tid ] ) ) {
            wp_send_json_error( [ 'message' => 'Unbekanntes Template.' ] );
        }

        // Selbstschutz: 'admin_full' nicht löschen lassen, sonst kommt
        // niemand mehr an die Rechte-Verwaltung.
        if ( $tid === 'admin_full' ) {
            wp_send_json_error( [ 'message' => 'Das Admin-Template kann nicht gelöscht werden.' ] );
        }

        LSV07I_Permissions::hide_built_in( $tid );
        LSV07I_Log::write( 'rechte.template.hide_system', [
            'bereich'   => 'Rechte',
            'ziel_typ'  => 'template',
            'ziel_id'   => $tid,
            'ziel_name' => $built[ $tid ]['name'],
        ] );
        wp_send_json_success( [ 'message' => 'System-Template ausgeblendet.' ] );
    }
}
