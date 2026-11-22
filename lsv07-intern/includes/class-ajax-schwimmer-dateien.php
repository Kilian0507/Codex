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
 * Zwei Prüfungen, beide in jedem Endpunkt:
 *   1. das Recht (LSV07I_Permissions) — darf die Person überhaupt Dateien
 *      sehen/hochladen/löschen?
 *   2. die Zuständigkeit (LSV07I_Access::darf_schwimmer) — gehört DIESER
 *      Schwimmer zu ihren Mannschaften? Vorher fehlte sie: Wer das Recht
 *      hatte, kam über die Datei-ID an die Dateien aller Kinder des Vereins.
 *
 * Atteste liegen in derselben Tabelle, sind hier aber unsichtbar
 * (LSV07I_Schwimmer_Dateien::ist_attest) — sie haben eigene Rechte und
 * eigene Endpunkte in der Attestverwaltung.
 */
class LSV07I_Ajax_Schwimmer_Dateien {

    /** Eine Antwort für „gibt es nicht", „fremd" und „Attest" — sonst verriete
        der Unterschied, welche Datei-IDs es bei anderen Mannschaften gibt. */
    const NICHT_GEFUNDEN = 'Datei nicht gefunden.';

    public static function init() {
        add_action( 'wp_ajax_lsv07i_sw_datei_list',     [ __CLASS__, 'list_files' ] );
        add_action( 'wp_ajax_lsv07i_sw_datei_upload',   [ __CLASS__, 'upload'     ] );
        add_action( 'wp_ajax_lsv07i_sw_datei_download', [ __CLASS__, 'download'   ] );
        add_action( 'wp_ajax_lsv07i_sw_datei_delete',   [ __CLASS__, 'delete'     ] );
    }

    private static function check_nonce() {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) LSV07I_Access::schluessel_abgelehnt();
    }

    private static function hat_recht( $right ) {
        // is_admin() statt current_user_can(): berücksichtigt die Sperrliste.
        return LSV07I_Access::is_admin() || LSV07I_Permissions::can_current( $right );
    }

    private static function require_permission( $right ) {
        if ( self::hat_recht( $right ) ) return;
        wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
    }

    /**
     * Darf die Person die Dateien dieses Schwimmers sehen? Die Dateien hängen
     * im Reiter „Mannschaften" — deshalb gilt dort auch das Recht „alle
     * Mannschaften sehen" (zweiter Parameter).
     */
    private static function darf_schwimmer( $schwimmer_id ) {
        return $schwimmer_id > 0 && LSV07I_Access::darf_schwimmer( (int) $schwimmer_id, true );
    }

    /**
     * Der Datensatz, wenn die Person ihn über diese Endpunkte erreichen darf,
     * sonst null. Der Schwimmer kommt aus dem DATENSATZ, nicht aus der
     * Anfrage — sonst gäbe man eine eigene Schwimmer-ID an und eine fremde
     * Datei-ID dazu.
     */
    private static function erreichbare_datei( $id ) {
        $row = $id ? LSV07I_Schwimmer_Dateien::get( $id ) : null;
        if ( ! $row ) return null;
        if ( LSV07I_Schwimmer_Dateien::ist_attest( $row ) ) return null;
        if ( ! self::darf_schwimmer( (int) $row['schwimmer_id'] ) ) return null;
        return $row;
    }

    /**
     * Liefert alle Dateien eines Schwimmers.
     */
    public static function list_files() {
        self::check_nonce();
        self::require_permission( LSV07I_Permissions::SCHWIMMEN_DATEI_READ );

        $sid = absint( $_POST['schwimmer_id'] ?? 0 );
        if ( ! $sid ) wp_send_json_error( [ 'message' => 'Schwimmer-ID fehlt.' ] );
        if ( ! self::darf_schwimmer( $sid ) ) {
            wp_send_json_error( [ 'message' => 'Schwimmer nicht gefunden.' ], 404 );
        }

        $files = LSV07I_Schwimmer_Dateien::list_for( $sid );

        // User-Namen mitliefern für die UI
        foreach ( $files as &$f ) {
            $u = get_user_by( 'id', (int) $f['hochgeladen_von'] );
            $f['hochgeladen_von_name'] = $u ? ( $u->display_name ?: $u->user_login ) : '–';
        }
        unset( $f );

        // Berechtigungen des aktuellen Users mitliefern, damit das Frontend
        // Upload-Form und Lösch-Buttons adaptiv ein-/ausblenden kann.
        $can = [
            'upload' => self::hat_recht( LSV07I_Permissions::SCHWIMMEN_DATEI_UPLOAD ),
            'delete' => self::hat_recht( LSV07I_Permissions::SCHWIMMEN_DATEI_DELETE ),
        ];

        wp_send_json_success( [
            'files' => $files,
            'can'   => $can,
        ] );
    }

    /**
     * Verarbeitet einen Datei-Upload.
     *
     * Ein Attest lässt sich hierüber nicht anlegen: store_upload() setzt
     * keine `art`, der Datensatz bekommt also den Standard '' — zum Attest
     * macht ihn allein der Upload der Attestverwaltung.
     */
    public static function upload() {
        self::check_nonce();
        self::require_permission( LSV07I_Permissions::SCHWIMMEN_DATEI_UPLOAD );

        $sid = absint( $_POST['schwimmer_id'] ?? 0 );
        if ( ! $sid ) wp_send_json_error( [ 'message' => 'Schwimmer-ID fehlt.' ] );
        if ( ! self::darf_schwimmer( $sid ) ) {
            wp_send_json_error( [ 'message' => 'Schwimmer nicht gefunden.' ], 404 );
        }

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
        if ( ! self::hat_recht( LSV07I_Permissions::SCHWIMMEN_DATEI_READ ) ) {
            status_header( 403 );
            echo 'Keine Berechtigung.';
            exit;
        }

        $row = self::erreichbare_datei( absint( $_REQUEST['id'] ?? 0 ) );
        if ( ! $row || ! file_exists( $row['path'] ) ) {
            status_header( 404 );
            echo self::NICHT_GEFUNDEN;
            exit;
        }

        // Immer als Download und ohne Typ-Raten des Browsers: Eine Datei,
        // die er selbst als HTML deutete, liefe sonst mit der Sitzung der
        // Person im Vereinsbereich.
        nocache_headers();
        header( 'Content-Type: ' . $row['mime_type'] );
        /* Über den Tresor: Die Datei liegt verschlüsselt (siehe LSV07I_Tresor). */
        $klartext_datei = LSV07I_Tresor::datei_inhalt( $row['path'] );
        if ( $klartext_datei === null ) { status_header( 404 ); echo 'Datei nicht gefunden.'; exit; }
        header( 'Content-Disposition: attachment; filename="' . rawurlencode( $row['dateiname'] ) . '"' );
        header( 'X-Content-Type-Options: nosniff' );

        // Output-Buffer leeren, dann streamen
        while ( ob_get_level() > 0 ) ob_end_clean();
        LSV07I_Tresor::ausgeben( $klartext_datei );
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

        // Metadaten zuerst: für die Prüfung UND fürs Protokoll.
        $meta = self::erreichbare_datei( $id );
        if ( ! $meta ) wp_send_json_error( [ 'message' => self::NICHT_GEFUNDEN ], 404 );

        $ok = LSV07I_Schwimmer_Dateien::delete( $id );
        if ( ! $ok ) wp_send_json_error( [ 'message' => self::NICHT_GEFUNDEN ], 404 );

        LSV07I_Log::write( 'datei.delete', [
            'bereich'   => 'Dateien',
            'ziel_typ'  => 'datei',
            'ziel_id'   => $id,
            'ziel_name' => $meta['dateiname'],
            'details'   => 'Schwimmer-ID: ' . $meta['schwimmer_id'],
        ] );
        wp_send_json_success( [ 'message' => 'Datei gelöscht.' ] );
    }
}
