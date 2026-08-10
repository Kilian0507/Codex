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
 */

class LSV07I_Ajax_Trainingsplan {

    public static function init() {
        add_action( 'wp_ajax_lsv07i_tp_liste',      [ __CLASS__, 'liste'      ] );
        add_action( 'wp_ajax_lsv07i_tp_get',        [ __CLASS__, 'get'        ] );
        add_action( 'wp_ajax_lsv07i_tp_save',       [ __CLASS__, 'save'       ] );
        add_action( 'wp_ajax_lsv07i_tp_freigeben',  [ __CLASS__, 'freigeben'  ] );
        add_action( 'wp_ajax_lsv07i_tp_loeschen',   [ __CLASS__, 'loeschen'   ] );
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
        if ( empty( $sessions ) ) wp_send_json_error( [ 'message' => 'Bitte mindestens eine Session anlegen.' ] );

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

        $wpdb->delete( $p . 'lsv07i_trainingsplan_session', [ 'trainingsplan_id' => $id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_trainingsplan', [ 'id' => $id ], [ '%d' ] );

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'trainingsplan.delete', [
                'bereich' => 'Schwimmen', 'ziel_typ' => 'trainingsplan', 'ziel_id' => $id, 'ziel_name' => $plan['titel'],
            ] );
        }

        wp_send_json_success();
    }
}
