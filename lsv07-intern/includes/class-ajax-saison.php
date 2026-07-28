<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Ajax_Saison {

    public static function init() {
        add_action( 'wp_ajax_lsv07i_saison_list',     [ __CLASS__, 'list' ] );
        add_action( 'wp_ajax_lsv07i_saison_save',     [ __CLASS__, 'save' ] );
        add_action( 'wp_ajax_lsv07i_saison_activate', [ __CLASS__, 'activate' ] );
        add_action( 'wp_ajax_lsv07i_saison_delete',   [ __CLASS__, 'delete' ] );
    }

    private static function check( $manage = true ) {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Ungültige Anfrage.' ], 403 );
        }
        // Voll-Admins dürfen immer. Zusätzlich kann die Saison-Verwaltung per
        // Recht delegiert werden: 'manage' für schreibende Aktionen, sonst
        // reicht das Leserecht (das Verwalten-Recht schließt Lesen mit ein).
        if ( current_user_can( 'administrator' ) || LSV07I_Access::is_admin() ) return;
        $perm = class_exists( 'LSV07I_Permissions' );
        if ( $manage ) {
            if ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::ADMIN_SAISON_MANAGE ) ) return;
        } else {
            if ( $perm && ( LSV07I_Permissions::can_current( LSV07I_Permissions::ADMIN_SAISON_READ )
                         || LSV07I_Permissions::can_current( LSV07I_Permissions::ADMIN_SAISON_MANAGE ) ) ) return;
        }
        wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
    }

    public static function list() {
        self::check( false );
        wp_send_json_success( [
            'saisons'  => LSV07I_Saison::all(),
            'aktiv_id' => LSV07I_Saison::active_id(),
        ] );
    }

    public static function save() {
        self::check();
        $id    = absint( $_POST['id']    ?? 0 );
        $name  = sanitize_text_field( wp_unslash( $_POST['name']        ?? '' ) );
        $start = sanitize_text_field( $_POST['start_datum'] ?? '' );
        $ende  = sanitize_text_field( $_POST['ende_datum']  ?? '' );

        $result = LSV07I_Saison::save( $id, $name, $start, $ende );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        LSV07I_Log::write( $id ? 'saison.update' : 'saison.create', [
            'bereich'   => 'Admin',
            'ziel_typ'  => 'saison',
            'ziel_id'   => $result,
            'ziel_name' => $name,
        ] );
        wp_send_json_success( [
            'id'      => $result,
            'message' => $id ? 'Saison aktualisiert.' : 'Saison angelegt.',
        ] );
    }

    public static function activate() {
        self::check();
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'Fehlende ID.' ] );

        $ok = LSV07I_Saison::activate( $id );
        if ( ! $ok ) wp_send_json_error( [ 'message' => 'Aktivierung fehlgeschlagen.' ] );

        // Name fürs Log
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}lsv07i_saisons WHERE id = %d", $id ) );
        LSV07I_Log::write( 'saison.activate', [
            'bereich'   => 'Admin',
            'ziel_typ'  => 'saison',
            'ziel_id'   => $id,
            'ziel_name' => $name ?: ('ID ' . $id),
        ] );
        wp_send_json_success( [ 'message' => 'Saison "' . $name . '" ist jetzt aktiv.' ] );
    }

    public static function delete() {
        self::check();
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'Fehlende ID.' ] );

        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}lsv07i_saisons WHERE id = %d", $id ) );

        $result = LSV07I_Saison::delete( $id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        LSV07I_Log::write( 'saison.delete', [
            'bereich'   => 'Admin',
            'ziel_typ'  => 'saison',
            'ziel_id'   => $id,
            'ziel_name' => $name ?: ('ID ' . $id),
        ] );
        wp_send_json_success( [ 'message' => 'Saison gelöscht.' ] );
    }
}
