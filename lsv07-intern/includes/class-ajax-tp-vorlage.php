<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * LSV07I_Ajax_TP_Vorlage
 * -----------------------
 * Session-Vorlagen für den Trainingsplan: eine einzelne Session (Anzahl,
 * Strecke, Beschreibung, Ausrüstung, Kommentar) lässt sich aus dem
 * Trainingsplan-Editor heraus als Vorlage speichern und später in jeden
 * beliebigen Plan wieder einfügen — genau wie bei Trainingsplänen selbst
 * ist eine Vorlage zunächst privat und kann für andere Trainer
 * freigegeben werden (dann erscheint sie bei ihnen unter "Freigegeben").
 * Nutzt dasselbe Recht wie Trainingspläne (sw_tp_read), da eng gekoppelt.
 */

class LSV07I_Ajax_TP_Vorlage {

    public static function init() {
        add_action( 'wp_ajax_lsv07i_tpv_liste',     [ __CLASS__, 'liste'     ] );
        add_action( 'wp_ajax_lsv07i_tpv_save',      [ __CLASS__, 'save'      ] );
        add_action( 'wp_ajax_lsv07i_tpv_freigeben', [ __CLASS__, 'freigeben' ] );
        add_action( 'wp_ajax_lsv07i_tpv_loeschen',  [ __CLASS__, 'loeschen'  ] );
    }

    private static function tabellen_sicherstellen() {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$p}lsv07i_trainingsplan_vorlage'" ) ) return;

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}lsv07i_trainingsplan_vorlage (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            anzahl         VARCHAR(20) NOT NULL DEFAULT '',
            strecke        VARCHAR(60) NOT NULL DEFAULT '',
            beschreibung   TEXT,
            ausruestung    VARCHAR(200) NOT NULL DEFAULT '',
            kommentar      TEXT,
            ersteller_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ersteller_name VARCHAR(160) NOT NULL DEFAULT '',
            freigegeben    TINYINT(1) NOT NULL DEFAULT 0,
            erstellt_am    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ersteller (ersteller_id),
            KEY idx_freigegeben (freigegeben)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
    }

    /** Lädt eine Vorlage und prüft, ob der aktuelle Nutzer ihr Ersteller ist (oder Admin). Sonst null. */
    private static function eigene_vorlage( $id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_trainingsplan_vorlage WHERE id = %d", $id
        ), ARRAY_A );
        if ( ! $row ) return null;
        if ( ! LSV07I_Access::is_admin() && (int) $row['ersteller_id'] !== get_current_user_id() ) return null;
        return $row;
    }

    public static function liste() {
        LSV07I_Access::check( 'sw_tp_read' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = get_current_user_id();

        $eigene = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, anzahl, strecke, beschreibung, ausruestung, kommentar, freigegeben
               FROM {$p}lsv07i_trainingsplan_vorlage
              WHERE ersteller_id = %d
           ORDER BY erstellt_am DESC",
            $uid
        ), ARRAY_A );

        $freigegeben = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, anzahl, strecke, beschreibung, ausruestung, kommentar, ersteller_name
               FROM {$p}lsv07i_trainingsplan_vorlage
              WHERE freigegeben = 1 AND ersteller_id != %d
           ORDER BY erstellt_am DESC",
            $uid
        ), ARRAY_A );

        wp_send_json_success( [ 'eigene' => $eigene ?: [], 'freigegeben' => $freigegeben ?: [] ] );
    }

    public static function save() {
        LSV07I_Access::check( 'sw_tp_read' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;

        $anzahl       = sanitize_text_field( $_POST['anzahl'] ?? '' );
        $strecke      = sanitize_text_field( $_POST['strecke'] ?? '' );
        $beschreibung = sanitize_textarea_field( $_POST['beschreibung'] ?? '' );
        $ausruestung  = sanitize_text_field( $_POST['ausruestung'] ?? '' );
        $kommentar    = sanitize_textarea_field( $_POST['kommentar'] ?? '' );

        if ( $anzahl === '' && $strecke === '' && $beschreibung === '' && $ausruestung === '' && $kommentar === '' ) {
            wp_send_json_error( [ 'message' => 'Diese Session ist leer — es gibt nichts zu speichern.' ] );
        }

        $user = wp_get_current_user();
        $wpdb->insert( $p . 'lsv07i_trainingsplan_vorlage', [
            'anzahl'         => $anzahl,
            'strecke'        => $strecke,
            'beschreibung'   => $beschreibung,
            'ausruestung'    => $ausruestung,
            'kommentar'      => $kommentar,
            'ersteller_id'   => get_current_user_id(),
            'ersteller_name' => $user ? $user->display_name : '',
            'freigegeben'    => 0,
        ] );
        $id = (int) $wpdb->insert_id;

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'tp_vorlage.create', [
                'bereich' => 'Schwimmen', 'ziel_typ' => 'tp_vorlage', 'ziel_id' => $id,
                'ziel_name' => trim( $anzahl . ' ' . $strecke ) ?: 'Vorlage',
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

        $vorlage = self::eigene_vorlage( $id );
        if ( ! $vorlage ) wp_send_json_error( [ 'message' => 'Keine eigene Vorlage.' ], 403 );

        $wpdb->update( $p . 'lsv07i_trainingsplan_vorlage',
            [ 'freigegeben' => $freigegeben ], [ 'id' => $id ], [ '%d' ], [ '%d' ]
        );

        wp_send_json_success( [ 'freigegeben' => $freigegeben ] );
    }

    public static function loeschen() {
        LSV07I_Access::check( 'sw_tp_read' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );

        $vorlage = self::eigene_vorlage( $id );
        if ( ! $vorlage ) wp_send_json_error( [ 'message' => 'Keine eigene Vorlage.' ], 403 );

        $wpdb->delete( $p . 'lsv07i_trainingsplan_vorlage', [ 'id' => $id ], [ '%d' ] );

        wp_send_json_success();
    }
}
