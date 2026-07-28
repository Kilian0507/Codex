<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Ajax_Permissions {

    public static function init() {
        add_action( 'wp_ajax_lsv07i_perm_get_users', [ __CLASS__, 'get_users' ] );
        add_action( 'wp_ajax_lsv07i_perm_save',      [ __CLASS__, 'save'      ] );
        add_action( 'wp_ajax_lsv07i_perm_reset',     [ __CLASS__, 'reset'     ] );
    }

    // Alle Benutzer mit ihren aktuellen Permissions laden
    public static function get_users() {
        // Nonce prüfen
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Ungueltige Anfrage.' ], 403 );
        }
        // Echter WP-Administrator (unabhängig von Plugin-Permissions)
        if ( ! current_user_can( 'administrator' ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }
        global $wpdb;
        $p = $wpdb->prefix;

        $rolle_filter = sanitize_text_field( $_POST['rolle'] ?? '' );

        // Vollständige WP_User Objekte laden (nicht nur bestimmte Felder)
        // damit $u->roles zuverlässig verfügbar ist
        $query_args = [];
        if ( $rolle_filter ) {
            $query_args['role'] = $rolle_filter;
        }

        $users       = get_users( $query_args );
        $current_uid = get_current_user_id();

        // Alle Permissions-Einträge in EINEM Schwung holen (statt N Queries)
        $perm_map = [];
        if ( ! empty( $users ) ) {
            $uids = array_filter( array_map( fn( $u ) => (int) $u->ID, $users ), fn( $i ) => $i > 0 );
            if ( ! empty( $uids ) ) {
                $uids_in = implode( ',', $uids );
                $perm_rows = $wpdb->get_results(
                    "SELECT * FROM {$p}lsv07i_permissions WHERE user_id IN ($uids_in)", ARRAY_A );
                foreach ( $perm_rows as $pr ) $perm_map[ (int) $pr['user_id'] ] = $pr;
            }
        }

        // Rollenbezeichnungen für Anzeige
        $rollen_labels = [
            'administrator'    => 'Administrator',
            'lsv07_intern'     => 'Intern',
            'lsv07_schwimmwart'=> 'Schwimmwart',
            'lsv07_finanzwart' => 'Finanzwart',
        ];

        $result = [];
        foreach ( $users as $u ) {
            $uid_loop = (int) $u->ID;
            $perm = $perm_map[ $uid_loop ] ?? null;

            $roles    = (array) $u->roles;
            $is_admin = in_array( 'administrator', $roles, true );

            // Lesbare Rollenliste für Anzeige
            $rollen_anzeige = [];
            foreach ( $roles as $r ) {
                $rollen_anzeige[] = $rollen_labels[ $r ] ?? $r;
            }

            $gesetzt_von  = $perm ? (int) $perm['gesetzt_von'] : 0;
            $kann_aendern = ( $uid_loop !== $current_uid )
                && ( ! $perm || $gesetzt_von === 0 || $gesetzt_von === $current_uid );

            $setzer_name = '';
            if ( $gesetzt_von ) {
                $setzer = get_user_by( 'ID', $gesetzt_von );
                $setzer_name = $setzer ? $setzer->display_name : '';
            }

            $result[] = [
                'user_id'          => $uid_loop,
                'name'             => $u->display_name,
                'email'            => $u->user_email,
                'rollen'           => implode( ', ', $rollen_anzeige ),
                'is_admin'         => $is_admin,
                'is_self'          => $uid_loop === $current_uid,
                'allow_schwimmen'  => $perm ? (bool) $perm['allow_schwimmen']  : true,
                'allow_trainer'    => $perm ? (bool) $perm['allow_trainer']    : true,
                'allow_verwaltung' => $perm ? (bool) $perm['allow_verwaltung'] : true,
                'allow_admin'      => $perm ? (bool) $perm['allow_admin']      : true,
                'allow_triathlon'  => $perm ? (bool) ( $perm['allow_triathlon'] ?? 1 ) : true,
                'hat_einschraenkung' => $perm !== null,
                'gesetzt_von'      => $gesetzt_von,
                'gesetzt_von_name' => $setzer_name,
                'kann_aendern'     => $kann_aendern,
            ];
        }

        wp_send_json_success( $result );
    }

    // Permissions speichern
    public static function save() {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Ungueltige Anfrage.' ], 403 );
        }
        if ( ! current_user_can( 'administrator' ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }
        global $wpdb;
        $p = $wpdb->prefix;

        $target_uid  = absint( $_POST['user_id'] ?? 0 );
        $current_uid = get_current_user_id();

        if ( ! $target_uid ) {
            wp_send_json_error( [ 'message' => 'Fehlende Benutzer-ID.' ] );
        }
        if ( $target_uid === $current_uid ) {
            wp_send_json_error( [ 'message' => 'Sie können Ihre eigenen Rechte nicht einschränken.' ] );
        }

        // Prüfen ob ein anderer Admin bereits eine Einschränkung gesetzt hat
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT gesetzt_von FROM {$p}lsv07i_permissions WHERE user_id = %d LIMIT 1",
            $target_uid
        ), ARRAY_A );

        if ( $existing && (int) $existing['gesetzt_von'] !== 0 && (int) $existing['gesetzt_von'] !== $current_uid ) {
            $setzer = get_user_by( 'ID', $existing['gesetzt_von'] );
            $name   = $setzer ? $setzer->display_name : 'ein anderer Administrator';
            wp_send_json_error( [ 'message' => "Diese Einschränkung wurde von $name gesetzt und kann nur von dieser Person geändert werden." ] );
        }

        $allow_schwimmen  = (int) (bool) ( $_POST['allow_schwimmen']  ?? 1 );
        $allow_trainer    = (int) (bool) ( $_POST['allow_trainer']    ?? 1 );
        $allow_verwaltung = (int) (bool) ( $_POST['allow_verwaltung'] ?? 1 );
        $allow_admin      = (int) (bool) ( $_POST['allow_admin']      ?? 1 );
        $allow_triathlon  = (int) (bool) ( $_POST['allow_triathlon']  ?? 1 );

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$p}lsv07i_permissions
                (user_id, allow_schwimmen, allow_trainer, allow_verwaltung, allow_admin, allow_triathlon, gesetzt_von)
             VALUES (%d, %d, %d, %d, %d, %d, %d)
             ON DUPLICATE KEY UPDATE
                allow_schwimmen  = VALUES(allow_schwimmen),
                allow_trainer    = VALUES(allow_trainer),
                allow_verwaltung = VALUES(allow_verwaltung),
                allow_admin      = VALUES(allow_admin),
                allow_triathlon  = VALUES(allow_triathlon),
                gesetzt_von      = VALUES(gesetzt_von)",
            $target_uid, $allow_schwimmen, $allow_trainer, $allow_verwaltung, $allow_admin, $allow_triathlon, $current_uid
        ) );

        wp_send_json_success();
    }

    // Permissions zurücksetzen
    public static function reset() {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Ungueltige Anfrage.' ], 403 );
        }
        if ( ! current_user_can( 'administrator' ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }
        global $wpdb;
        $p = $wpdb->prefix;

        $target_uid  = absint( $_POST['user_id'] ?? 0 );
        $current_uid = get_current_user_id();

        if ( $target_uid === $current_uid ) {
            wp_send_json_error( [ 'message' => 'Sie können Ihre eigenen Rechte nicht ändern.' ] );
        }

        // Nur der ursprüngliche Setzer darf zurücksetzen
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT gesetzt_von FROM {$p}lsv07i_permissions WHERE user_id = %d LIMIT 1",
            $target_uid
        ), ARRAY_A );

        if ( $existing && (int) $existing['gesetzt_von'] !== 0 && (int) $existing['gesetzt_von'] !== $current_uid ) {
            $setzer = get_user_by( 'ID', $existing['gesetzt_von'] );
            $name   = $setzer ? $setzer->display_name : 'ein anderer Administrator';
            wp_send_json_error( [ 'message' => "Diese Einschränkung wurde von $name gesetzt und kann nur von dieser Person aufgehoben werden." ] );
        }

        $wpdb->delete(
            $p . 'lsv07i_permissions',
            [ 'user_id' => $target_uid ],
            [ '%d' ]
        );

        wp_send_json_success();
    }
}
