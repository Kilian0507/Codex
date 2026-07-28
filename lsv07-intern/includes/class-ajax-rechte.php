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
        add_action( 'wp_ajax_lsv07i_rechte_tpl_save',   [ __CLASS__, 'tpl_save'   ] );
        add_action( 'wp_ajax_lsv07i_rechte_tpl_delete', [ __CLASS__, 'tpl_delete' ] );
    }

    private static function check_admin() {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Ungültige Anfrage.' ], 403 );
        }
        // Echter WP-Administrator ODER User mit dem Recht zur Rechte-Verwaltung.
        // can_current() defensiv in try/catch, falls die Permissions-Tabelle aus
        // irgendeinem Grund nicht initialisiert ist (sonst würde ein Fatal Error
        // einen leeren 400-Response erzeugen, der schwer zu diagnostizieren ist).
        $hat_recht = false;
        if ( current_user_can( 'administrator' ) ) {
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
     * Metadaten für die UI: Bereiche/Gruppen/Rechte und Templates.
     * Wird einmal beim Öffnen des Tabs geladen.
     */
    public static function meta() {
        self::check_admin();
        try {
            wp_send_json_success( [
                'bereiche'  => LSV07I_Permissions::all_rights(),
                'templates' => LSV07I_Permissions::all_templates(),
            ] );
        } catch ( Throwable $e ) {
            wp_send_json_error( [
                'message' => 'Server-Fehler beim Laden der Rechte-Definitionen: ' . $e->getMessage(),
                'file'    => basename( $e->getFile() ),
                'line'    => $e->getLine(),
            ] );
        }
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
            wp_send_json_error( [
                'message' => 'Server-Fehler: ' . $e->getMessage(),
                'file'    => basename( $e->getFile() ),
                'line'    => $e->getLine(),
            ] );
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

        wp_send_json_success( [
            'user_id'      => $uid,
            'name'         => $u->display_name ?: $u->user_login,
            'email'        => $u->user_email,
            'roles'        => array_values( (array) $u->roles ),
            'rights'       => is_array( $rows ) ? $rows : [],
            'has_explicit' => is_array( $rows ) && count( $rows ) > 0,
        ] );
    }

    /**
     * Speichert die Rechte eines Users — komplette Liste, alles andere wird gelöscht.
     */
    public static function user_save() {
        self::check_admin();
        $uid = (int) ( $_POST['user_id'] ?? 0 );
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'User-ID fehlt.' ] );

        // Sicherheit: User darf eigene Rechte nicht reduzieren (sich selbst aussperren)
        $is_self = ( $uid === get_current_user_id() );

        $rights_raw = $_POST['rights'] ?? '[]';
        if ( is_string( $rights_raw ) ) {
            $rights = json_decode( wp_unslash( $rights_raw ), true );
        } else {
            $rights = $rights_raw;
        }
        if ( ! is_array( $rights ) ) $rights = [];

        // Nur gültige Codes akzeptieren
        $valid  = LSV07I_Permissions::all_codes();
        $rights = array_values( array_unique( array_intersect( $rights, $valid ) ) );

        // Selbstschutz: man darf sich nicht selbst die Rechte-Verwaltung entziehen,
        // wenn man kein WP-Administrator ist.
        if ( $is_self && ! current_user_can( 'administrator' ) ) {
            if ( ! in_array( LSV07I_Permissions::ADMIN_PERMISSIONS_UPDATE, $rights, true ) ) {
                $rights[] = LSV07I_Permissions::ADMIN_PERMISSIONS_UPDATE;
            }
        }

        LSV07I_Permissions::set_user_rights( $uid, $rights, get_current_user_id() );
        $target_user = get_user_by( 'id', $uid );
        LSV07I_Log::write( 'rechte.user.update', [
            'bereich'   => 'Rechte',
            'ziel_typ'  => 'user',
            'ziel_id'   => $uid,
            'ziel_name' => $target_user ? ( $target_user->display_name ?: $target_user->user_login ) : '?',
            'details'   => count( $rights ) . ' Rechte gesetzt',
        ] );
        wp_send_json_success( [ 'message' => 'Rechte gespeichert.', 'count' => count( $rights ) ] );
    }

    /**
     * Wendet ein Template auf einen User an. Bestehende Rechte werden ersetzt.
     */
    public static function apply_tpl() {
        self::check_admin();
        $uid = (int) ( $_POST['user_id'] ?? 0 );
        $tpl = sanitize_text_field( $_POST['template'] ?? '' );
        if ( ! $uid || ! $tpl ) wp_send_json_error( [ 'message' => 'User oder Template fehlt.' ] );

        $tpls = LSV07I_Permissions::all_templates();
        if ( ! isset( $tpls[ $tpl ] ) ) {
            wp_send_json_error( [ 'message' => 'Unbekanntes Template.' ] );
        }

        // Selbstschutz wie oben
        $rights = $tpls[ $tpl ]['rights'];
        if ( $uid === get_current_user_id() && ! current_user_can( 'administrator' ) ) {
            if ( ! in_array( LSV07I_Permissions::ADMIN_PERMISSIONS_UPDATE, $rights, true ) ) {
                $rights[] = LSV07I_Permissions::ADMIN_PERMISSIONS_UPDATE;
            }
        }

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
            'message'  => 'Template "' . $tpls[ $tpl ]['name'] . '" angewendet.',
            'count'    => count( $rights ),
            'rights'   => $rights,
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

            $rights_raw = $_POST['rights'] ?? '[]';
            if ( is_string( $rights_raw ) ) {
                $rights = json_decode( wp_unslash( $rights_raw ), true );
            } else {
                $rights = $rights_raw;
            }
            if ( ! is_array( $rights ) ) $rights = [];

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
            wp_send_json_error( [
                'message' => 'Server-Fehler: ' . $e->getMessage(),
                'file'    => basename( $e->getFile() ),
                'line'    => $e->getLine(),
            ] );
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
