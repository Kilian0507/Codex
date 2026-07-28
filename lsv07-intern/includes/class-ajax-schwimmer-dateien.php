<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AJAX-Endpunkte für Schwimmer-Dateien.
 *
 *   lsv07i_sw_datei_list      – Liste der Dateien eines Schwimmers
 *   lsv07i_sw_datei_upload    – Datei hochladen
 *   lsv07i_sw_datei_download  – Datei herunterladen (mit Berechtigungs-Check)
 *   lsv07i_sw_datei_delete    – Datei löschen
 *
 * Alle Endpunkte prüfen die granularen Rechte aus LSV07I_Permissions.
 */
class LSV07I_Ajax_Schwimmer_Dateien {

    public static function init() {
        add_action( 'wp_ajax_lsv07i_sw_datei_list',     [ __CLASS__, 'list_files' ] );
        add_action( 'wp_ajax_lsv07i_sw_datei_upload',   [ __CLASS__, 'upload'     ] );
        add_action( 'wp_ajax_lsv07i_sw_datei_download', [ __CLASS__, 'download'   ] );
        add_action( 'wp_ajax_lsv07i_sw_datei_delete',   [ __CLASS__, 'delete'     ] );
    }

    private static function check_nonce() {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Ungültige Anfrage.' ], 403 );
        }
    }

    private static function require_permission( $right ) {
        // Admin darf alles, sonst granular prüfen
        if ( current_user_can( 'administrator' ) ) return;
        if ( LSV07I_Permissions::can_current( $right ) ) return;
        wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
    }

    /**
     * Liefert alle Dateien eines Schwimmers.
     */
    public static function list_files() {
        self::check_nonce();
        self::require_permission( LSV07I_Permissions::SCHWIMMEN_DATEI_READ );

        $sid = absint( $_POST['schwimmer_id'] ?? 0 );
        if ( ! $sid ) wp_send_json_error( [ 'message' => 'Schwimmer-ID fehlt.' ] );

        $files = LSV07I_Schwimmer_Dateien::list_for( $sid );

        // User-Namen mitliefern für die UI
        foreach ( $files as &$f ) {
            $u = get_user_by( 'id', (int) $f['hochgeladen_von'] );
            $f['hochgeladen_von_name'] = $u ? ( $u->display_name ?: $u->user_login ) : '–';
        }
        unset( $f );

        // Berechtigungen des aktuellen Users mitliefern, damit das Frontend
        // Upload-Form und Lösch-Buttons adaptiv ein-/ausblenden kann.
        $is_admin = current_user_can( 'administrator' );
        $can = [
            'upload' => $is_admin || LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_DATEI_UPLOAD ),
            'delete' => $is_admin || LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_DATEI_DELETE ),
        ];

        wp_send_json_success( [
            'files' => $files,
            'can'   => $can,
        ] );
    }

    /**
     * Verarbeitet einen Datei-Upload.
     */
    public static function upload() {
        self::check_nonce();
        self::require_permission( LSV07I_Permissions::SCHWIMMEN_DATEI_UPLOAD );

        $sid = absint( $_POST['schwimmer_id'] ?? 0 );
        if ( ! $sid ) wp_send_json_error( [ 'message' => 'Schwimmer-ID fehlt.' ] );

        if ( empty( $_FILES['datei'] ) ) {
            wp_send_json_error( [ 'message' => 'Keine Datei übermittelt.' ] );
        }

        $kommentar = sanitize_text_field( wp_unslash( $_POST['kommentar'] ?? '' ) );

        $result = LSV07I_Schwimmer_Dateien::store_upload( $sid, $_FILES['datei'], $kommentar );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        LSV07I_Log::write( 'datei.upload', [
            'bereich'   => 'Dateien',
            'ziel_typ'  => 'schwimmer',
            'ziel_id'   => $sid,
            'ziel_name' => sanitize_file_name( $_FILES['datei']['name'] ?? '?' ),
            'details'   => 'Datei-ID ' . $result . ', ' . round( ($_FILES['datei']['size'] ?? 0) / 1024 ) . ' kB',
        ] );
        wp_send_json_success( [
            'id'      => $result,
            'message' => 'Datei hochgeladen.',
        ] );
    }

    /**
     * Datei-Download mit Berechtigungs-Check und sauberen Headern.
     * Streamt die Datei, statt den Direktzugriff aufs Filesystem freizugeben.
     */
    public static function download() {
        // Download nutzt GET (für direkte Browser-Links), Nonce kommt aus Query-String
        $nonce = $_REQUEST['nonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'lsv07i_nonce' ) ) {
            status_header( 403 );
            echo 'Ungültige Anfrage.';
            exit;
        }
        if ( ! current_user_can( 'administrator' )
          && ! LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_DATEI_READ ) ) {
            status_header( 403 );
            echo 'Keine Berechtigung.';
            exit;
        }

        $id = absint( $_REQUEST['id'] ?? 0 );
        $row = LSV07I_Schwimmer_Dateien::get( $id );
        if ( ! $row || ! file_exists( $row['path'] ) ) {
            status_header( 404 );
            echo 'Datei nicht gefunden.';
            exit;
        }

        // Saubere Header — Datei wird gestreamt, nicht inline ausgeführt
        nocache_headers();
        header( 'Content-Type: ' . $row['mime_type'] );
        header( 'Content-Length: ' . filesize( $row['path'] ) );
        header( 'Content-Disposition: attachment; filename="' . rawurlencode( $row['dateiname'] ) . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        // Bei Bildern und PDF kann der Browser inline anzeigen, wenn der User möchte
        // (browserseitig per "Öffnen statt Speichern"). Wir setzen attachment als Standard.

        // Output-Buffer leeren, dann streamen
        if ( ob_get_level() ) ob_end_clean();
        readfile( $row['path'] );
        exit;
    }

    /**
     * Löscht eine Datei.
     */
    public static function delete() {
        self::check_nonce();
        self::require_permission( LSV07I_Permissions::SCHWIMMEN_DATEI_DELETE );

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'Datei-ID fehlt.' ] );

        // Vor dem Löschen die Metadaten holen, damit wir sie loggen können
        $meta = LSV07I_Schwimmer_Dateien::get( $id );

        $ok = LSV07I_Schwimmer_Dateien::delete( $id );
        if ( ! $ok ) wp_send_json_error( [ 'message' => 'Datei nicht gefunden.' ] );

        LSV07I_Log::write( 'datei.delete', [
            'bereich'   => 'Dateien',
            'ziel_typ'  => 'datei',
            'ziel_id'   => $id,
            'ziel_name' => $meta ? $meta['dateiname'] : '?',
            'details'   => $meta ? ( 'Schwimmer-ID: ' . $meta['schwimmer_id'] ) : '',
        ] );
        wp_send_json_success( [ 'message' => 'Datei gelöscht.' ] );
    }
}
