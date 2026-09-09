<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * LSV07I_Ajax_Trainingsplan
 * --------------------------
 * Trainingspläne für Schwimmen: ein Plan hat einen Titel und eine
 * geordnete Liste von Sessions (Anzahl, Strecke, Beschreibung,
 * Ausrüstung, Kommentar). Jeder Plan gehört seinem Ersteller — nur der
 * Ersteller (oder Admin) darf ihn bearbeiten, löschen oder freigeben.
 *
 * Sichtbarkeit:
 *   - "Meine Trainingspläne": alle eigenen Pläne, unabhängig vom
 *     Freigabe-Status.
 *   - "Freigegeben": Pläne ANDERER Trainer, die diese ausdrücklich per
 *     Freigeben-Knopf geteilt haben — reiner Lesezugriff (ansehen,
 *     PDF-Export), keine Bearbeitung fremder Pläne.
 *
 * Anzeige/PDF-Export liegen komplett clientseitig (jsPDF, siehe app.js) —
 * dieser Endpunkt liefert nur die Daten.
 *
 * Zusätzlich kann ein Plan PDFs tragen (eingescannte Pläne, Vorlagen des
 * Verbands). Die Dateien liegen geschützt ausserhalb des Web-Zugriffs, siehe
 * LSV07I_TP_Dateien; ausgeliefert werden sie ausschliesslich über
 * datei_download() — mit derselben Sichtbarkeitsregel wie der Plan selbst.
 */

class LSV07I_Ajax_Trainingsplan {

    public static function init() {
        add_action( 'wp_ajax_lsv07i_tp_liste',          [ __CLASS__, 'liste'          ] );
        add_action( 'wp_ajax_lsv07i_tp_get',            [ __CLASS__, 'get'            ] );
        add_action( 'wp_ajax_lsv07i_tp_save',           [ __CLASS__, 'save'           ] );
        add_action( 'wp_ajax_lsv07i_tp_freigeben',      [ __CLASS__, 'freigeben'      ] );
        add_action( 'wp_ajax_lsv07i_tp_loeschen',       [ __CLASS__, 'loeschen'       ] );
        add_action( 'wp_ajax_lsv07i_tp_datei_upload',   [ __CLASS__, 'datei_upload'   ] );
        add_action( 'wp_ajax_lsv07i_tp_datei_delete',   [ __CLASS__, 'datei_delete'   ] );
        add_action( 'wp_ajax_lsv07i_tp_datei_download', [ __CLASS__, 'datei_download' ] );
    }

    private static function tabellen_sicherstellen() {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$p}lsv07i_trainingsplan'" ) ) return;

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}lsv07i_trainingsplan (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            titel          VARCHAR(200) NOT NULL DEFAULT '',
            ersteller_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ersteller_name VARCHAR(160) NOT NULL DEFAULT '',
            freigegeben    TINYINT(1) NOT NULL DEFAULT 0,
            erstellt_am    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            geaendert_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ersteller (ersteller_id),
            KEY idx_freigegeben (freigegeben)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}lsv07i_trainingsplan_session (
            id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
            trainingsplan_id  INT UNSIGNED NOT NULL,
            sortierung        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            anzahl            VARCHAR(20) NOT NULL DEFAULT '',
            strecke           VARCHAR(60) NOT NULL DEFAULT '',
            beschreibung      TEXT,
            ausruestung       VARCHAR(200) NOT NULL DEFAULT '',
            kommentar         TEXT,
            PRIMARY KEY (id),
            KEY idx_plan (trainingsplan_id, sortierung)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
    }

    /** Lädt einen Plan und prüft, ob der aktuelle Nutzer sein Ersteller ist (oder Admin). Sonst null. */
    private static function eigener_plan( $id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $plan = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_trainingsplan WHERE id = %d", $id
        ), ARRAY_A );
        if ( ! $plan ) return null;
        if ( ! LSV07I_Access::is_admin() && (int) $plan['ersteller_id'] !== get_current_user_id() ) return null;
        return $plan;
    }

    /**
     * Lädt einen Plan, wenn der aktuelle Nutzer ihn ansehen darf: entweder als
     * Ersteller/Admin oder weil der Plan freigegeben ist. Sonst null.
     * Grundlage für den Datei-Download — eine PDF ist genau so weit sichtbar
     * wie der Plan, an dem sie hängt.
     */
    private static function lesbarer_plan( $id ) {
        global $wpdb;
        $p    = $wpdb->prefix;
        $plan = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_trainingsplan WHERE id = %d", $id
        ), ARRAY_A );
        if ( ! $plan ) return null;
        if ( LSV07I_Access::is_admin() ) return $plan;
        if ( (int) $plan['ersteller_id'] === get_current_user_id() ) return $plan;
        if ( (int) $plan['freigegeben'] === 1 ) return $plan;
        return null;
    }

    private static function sessions_laden( $plan_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT anzahl, strecke, beschreibung, ausruestung, kommentar
               FROM {$p}lsv07i_trainingsplan_session
              WHERE trainingsplan_id = %d
           ORDER BY sortierung ASC", $plan_id
        ), ARRAY_A );
    }

    public static function liste() {
        LSV07I_Access::check( 'sw_tp_read' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;
        $uid = get_current_user_id();

        $eigene = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.id, t.titel, t.freigegeben, t.geaendert_am,
                    ( SELECT COUNT(*) FROM {$p}lsv07i_trainingsplan_session s WHERE s.trainingsplan_id = t.id ) AS sessions_anzahl
               FROM {$p}lsv07i_trainingsplan t
              WHERE t.ersteller_id = %d
           ORDER BY t.geaendert_am DESC", $uid
        ), ARRAY_A );

        $freigegeben = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.id, t.titel, t.ersteller_name, t.geaendert_am,
                    ( SELECT COUNT(*) FROM {$p}lsv07i_trainingsplan_session s WHERE s.trainingsplan_id = t.id ) AS sessions_anzahl
               FROM {$p}lsv07i_trainingsplan t
              WHERE t.freigegeben = 1 AND t.ersteller_id != %d
           ORDER BY t.geaendert_am DESC", $uid
        ), ARRAY_A );

        wp_send_json_success( [ 'eigene' => $eigene ?: [], 'freigegeben' => $freigegeben ?: [] ] );
    }

    public static function get() {
        LSV07I_Access::check( 'sw_tp_read' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'Trainingsplan nicht gefunden.' ] );

        $plan = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_trainingsplan WHERE id = %d", $id
        ), ARRAY_A );
        if ( ! $plan ) wp_send_json_error( [ 'message' => 'Trainingsplan nicht gefunden.' ] );

        $ist_eigener = LSV07I_Access::is_admin() || (int) $plan['ersteller_id'] === get_current_user_id();
        if ( ! $ist_eigener && ! (int) $plan['freigegeben'] ) {
            wp_send_json_error( [ 'message' => 'Dieser Trainingsplan ist nicht freigegeben.' ], 403 );
        }

        wp_send_json_success( [
            'id'             => (int) $plan['id'],
            'titel'          => $plan['titel'],
            'ersteller_name' => $plan['ersteller_name'],
            'freigegeben'    => (int) $plan['freigegeben'],
            'geaendert_am'   => $plan['geaendert_am'],
            'kann_bearbeiten'=> $ist_eigener,
            'sessions'       => self::sessions_laden( $id ),
            'dateien'        => LSV07I_TP_Dateien::list_for( $id ),
        ] );
    }

    public static function save() {
        LSV07I_Access::check( 'sw_tp_read' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );

        $titel = sanitize_text_field( $_POST['titel'] ?? '' );
        if ( $titel === '' ) wp_send_json_error( [ 'message' => 'Bitte einen Titel angeben.' ] );

        $roh = json_decode( (string) wp_unslash( $_POST['sessions_json'] ?? '[]' ), true );
        if ( ! is_array( $roh ) ) wp_send_json_error( [ 'message' => 'Sessions nicht lesbar.' ] );
        if ( count( $roh ) > 100 ) wp_send_json_error( [ 'message' => 'Mehr als 100 Sessions sind nicht vorgesehen.' ] );

        $sessions = [];
        foreach ( $roh as $s ) {
            if ( ! is_array( $s ) ) continue;
            $anzahl       = sanitize_text_field( $s['anzahl'] ?? '' );
            $strecke      = sanitize_text_field( $s['strecke'] ?? '' );
            $beschreibung = sanitize_textarea_field( $s['beschreibung'] ?? '' );
            $ausruestung  = sanitize_text_field( $s['ausruestung'] ?? '' );
            $kommentar    = sanitize_textarea_field( $s['kommentar'] ?? '' );
            if ( $anzahl === '' && $strecke === '' && $beschreibung === '' && $ausruestung === '' && $kommentar === '' ) continue;
            $sessions[] = compact( 'anzahl', 'strecke', 'beschreibung', 'ausruestung', 'kommentar' );
        }
        // Ein Plan braucht Inhalt — entweder Sessions oder mindestens eine PDF.
        // Ein rein eingescannter Plan ist ein gültiger Trainingsplan, deshalb
        // zählen bereits hochgeladene Dateien (und beim Anlegen die im Editor
        // vorgemerkte, die direkt nach dem Speichern hochgeladen wird) mit.
        if ( empty( $sessions ) ) {
            $hat_pdf = ! empty( $_POST['pdf_folgt'] )
                || ( $id && LSV07I_TP_Dateien::list_for( $id ) );
            if ( ! $hat_pdf ) {
                wp_send_json_error( [ 'message' => 'Bitte mindestens eine Session anlegen oder eine PDF hochladen.' ] );
            }
        }

        if ( $id ) {
            $plan = self::eigener_plan( $id );
            if ( ! $plan ) wp_send_json_error( [ 'message' => 'Kein eigener Trainingsplan.' ], 403 );
            $wpdb->update( $p . 'lsv07i_trainingsplan',
                [ 'titel' => $titel, 'geaendert_am' => current_time( 'mysql' ) ],
                [ 'id' => $id ], [ '%s', '%s' ], [ '%d' ]
            );
        } else {
            $user = wp_get_current_user();
            $wpdb->insert( $p . 'lsv07i_trainingsplan', [
                'titel'          => $titel,
                'ersteller_id'   => get_current_user_id(),
                'ersteller_name' => $user ? $user->display_name : '',
                'freigegeben'    => 0,
            ] );
            $id = (int) $wpdb->insert_id;
        }

        $wpdb->delete( $p . 'lsv07i_trainingsplan_session', [ 'trainingsplan_id' => $id ], [ '%d' ] );
        foreach ( $sessions as $i => $s ) {
            $wpdb->insert( $p . 'lsv07i_trainingsplan_session', [
                'trainingsplan_id' => $id,
                'sortierung'       => $i,
                'anzahl'           => $s['anzahl'],
                'strecke'          => $s['strecke'],
                'beschreibung'     => $s['beschreibung'],
                'ausruestung'      => $s['ausruestung'],
                'kommentar'        => $s['kommentar'],
            ] );
        }

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( $id ? 'trainingsplan.update' : 'trainingsplan.create', [
                'bereich' => 'Schwimmen', 'ziel_typ' => 'trainingsplan', 'ziel_id' => $id, 'ziel_name' => $titel,
            ] );
        }

        wp_send_json_success( [ 'id' => $id ] );
    }

    public static function freigeben() {
        LSV07I_Access::check( 'sw_tp_read' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        $freigegeben = ! empty( $_POST['freigegeben'] ) ? 1 : 0;

        $plan = self::eigener_plan( $id );
        if ( ! $plan ) wp_send_json_error( [ 'message' => 'Kein eigener Trainingsplan.' ], 403 );

        $wpdb->update( $p . 'lsv07i_trainingsplan',
            [ 'freigegeben' => $freigegeben, 'geaendert_am' => current_time( 'mysql' ) ],
            [ 'id' => $id ], [ '%d', '%s' ], [ '%d' ]
        );

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( $freigegeben ? 'trainingsplan.freigeben' : 'trainingsplan.zurueckziehen', [
                'bereich' => 'Schwimmen', 'ziel_typ' => 'trainingsplan', 'ziel_id' => $id, 'ziel_name' => $plan['titel'],
            ] );
        }

        wp_send_json_success( [ 'freigegeben' => $freigegeben ] );
    }

    public static function loeschen() {
        LSV07I_Access::check( 'sw_tp_read' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );

        $plan = self::eigener_plan( $id );
        if ( ! $plan ) wp_send_json_error( [ 'message' => 'Kein eigener Trainingsplan.' ], 403 );

        LSV07I_TP_Dateien::delete_all_for( $id );
        $wpdb->delete( $p . 'lsv07i_trainingsplan_session', [ 'trainingsplan_id' => $id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_trainingsplan', [ 'id' => $id ], [ '%d' ] );

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'trainingsplan.delete', [
                'bereich' => 'Schwimmen', 'ziel_typ' => 'trainingsplan', 'ziel_id' => $id, 'ziel_name' => $plan['titel'],
            ] );
        }

        wp_send_json_success();
    }

    // ── PDFs am Plan ────────────────────────────────────────────────────────

    public static function datei_upload() {
        LSV07I_Access::check( 'sw_tp_read' );
        self::tabellen_sicherstellen();
        $plan_id = absint( $_POST['plan_id'] ?? 0 );

        $plan = $plan_id ? self::eigener_plan( $plan_id ) : null;
        if ( ! $plan ) wp_send_json_error( [ 'message' => 'Kein eigener Trainingsplan.' ], 403 );

        if ( empty( $_FILES['datei'] ) ) {
            wp_send_json_error( [ 'message' => 'Keine Datei übermittelt.' ] );
        }

        $datei = LSV07I_TP_Dateien::store_upload( $plan_id, $_FILES['datei'] );
        if ( is_wp_error( $datei ) ) {
            wp_send_json_error( [ 'message' => $datei->get_error_message() ] );
        }

        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'lsv07i_trainingsplan',
            [ 'geaendert_am' => current_time( 'mysql' ) ], [ 'id' => $plan_id ], [ '%s' ], [ '%d' ] );

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'trainingsplan.datei_upload', [
                'bereich' => 'Schwimmen', 'ziel_typ' => 'trainingsplan', 'ziel_id' => $plan_id,
                'ziel_name' => $plan['titel'] . ' · ' . $datei['dateiname'],
            ] );
        }

        wp_send_json_success( [
            'message' => 'PDF hochgeladen.',
            'datei'   => [
                'id'        => (int) $datei['id'],
                'plan_id'   => (int) $datei['plan_id'],
                'dateiname' => $datei['dateiname'],
                'groesse'   => (int) $datei['groesse'],
            ],
            'dateien' => LSV07I_TP_Dateien::list_for( $plan_id ),
        ] );
    }

    public static function datei_delete() {
        LSV07I_Access::check( 'sw_tp_read' );
        self::tabellen_sicherstellen();
        $id = absint( $_POST['id'] ?? 0 );

        $datei = $id ? LSV07I_TP_Dateien::get( $id ) : null;
        if ( ! $datei ) wp_send_json_error( [ 'message' => 'Datei nicht gefunden.' ] );

        $plan = self::eigener_plan( (int) $datei['plan_id'] );
        if ( ! $plan ) wp_send_json_error( [ 'message' => 'Kein eigener Trainingsplan.' ], 403 );

        LSV07I_TP_Dateien::delete( $id );

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'trainingsplan.datei_delete', [
                'bereich' => 'Schwimmen', 'ziel_typ' => 'trainingsplan', 'ziel_id' => (int) $datei['plan_id'],
                'ziel_name' => $plan['titel'] . ' · ' . $datei['dateiname'],
            ] );
        }

        wp_send_json_success( [ 'dateien' => LSV07I_TP_Dateien::list_for( (int) $datei['plan_id'] ) ] );
    }

    /**
     * Liefert die PDF-Bytes aus. Die Datei liegt ausserhalb des Web-Zugriffs,
     * das hier ist der einzige Weg an sie heran — deshalb wird die
     * Sichtbarkeit des Plans hier noch einmal vollständig geprüft.
     *
     * Standardmässig "inline": das Vollbild rendert die Seiten selbst mit
     * pdf.js und lädt die Datei dafür per XHR. Mit dl=1 kommt sie stattdessen
     * als Download heraus.
     */
    public static function datei_download() {
        if ( ! is_user_logged_in() ) { status_header( 403 ); echo 'Bitte anmelden.'; exit; }

        $nonce = $_REQUEST['nonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'lsv07i_nonce' ) ) { status_header( 403 ); echo 'Ungültige Anfrage.'; exit; }

        $perm = class_exists( 'LSV07I_Permissions' );
        $ok   = LSV07I_Access::is_intern()
             || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_TRAININGSPLAN_READ ) );
        if ( ! $ok ) { status_header( 403 ); echo 'Keine Berechtigung.'; exit; }

        self::tabellen_sicherstellen();
        $id    = absint( $_REQUEST['id'] ?? 0 );
        $datei = $id ? LSV07I_TP_Dateien::get( $id ) : null;
        if ( ! $datei || ! file_exists( $datei['path'] ) ) {
            status_header( 404 ); echo 'Datei nicht gefunden.'; exit;
        }

        if ( ! self::lesbarer_plan( (int) $datei['plan_id'] ) ) {
            status_header( 403 ); echo 'Dieser Trainingsplan ist nicht freigegeben.'; exit;
        }

        $als_download = ! empty( $_REQUEST['dl'] );

        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Length: ' . filesize( $datei['path'] ) );
        // Ascii-Fallback + RFC-6266-kodierter Original-Dateiname (Umlaute etc.)
        $ascii = preg_replace( '/[^\x20-\x7E]/', '_', $datei['dateiname'] );
        header( 'Content-Disposition: ' . ( $als_download ? 'attachment' : 'inline' )
              . '; filename="' . $ascii . '"'
              . "; filename*=UTF-8''" . rawurlencode( $datei['dateiname'] ) );
        header( 'X-Content-Type-Options: nosniff' );

        if ( ob_get_level() ) ob_end_clean();
        readfile( $datei['path'] );
        exit;
    }
}
