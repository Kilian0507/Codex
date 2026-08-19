<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * LSV07I_Ajax_Benachrichtigung
 * -----------------------------
 * Systemweite Benachrichtigungen: der Admin erstellt sie über den
 * Admin-Bereich, jeder eingeloggte Nutzer mit Zugang zum internen
 * Bereich sieht sie (Glocken-Knopf auf der Startseite, roter Zähler für
 * ungelesene) und kann sie einzeln als gelesen markieren. Der Admin sieht
 * je Benachrichtigung eine Lesebestätigung (wer sie wann gelesen hat).
 *
 * Datenmodell:
 *   - lsv07i_benachrichtigung          Titel, Text, Ersteller, Datum
 *   - lsv07i_benachrichtigung_gelesen  Bridge: wer hat wann gelesen
 */

class LSV07I_Ajax_Benachrichtigung {

    public static function init() {
        add_action( 'wp_ajax_lsv07i_ben_unread_count', [ __CLASS__, 'unread_count' ] );
        add_action( 'wp_ajax_lsv07i_ben_liste',         [ __CLASS__, 'liste'         ] );
        add_action( 'wp_ajax_lsv07i_ben_als_gelesen',   [ __CLASS__, 'als_gelesen'   ] );
        add_action( 'wp_ajax_lsv07i_ben_admin_liste',   [ __CLASS__, 'admin_liste'   ] );
        add_action( 'wp_ajax_lsv07i_ben_admin_save',    [ __CLASS__, 'admin_save'    ] );
        add_action( 'wp_ajax_lsv07i_ben_admin_delete',  [ __CLASS__, 'admin_delete'  ] );
    }

    private static function tabellen_sicherstellen() {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$p}lsv07i_benachrichtigung'" ) ) return;

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}lsv07i_benachrichtigung (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            titel          VARCHAR(200) NOT NULL DEFAULT '',
            text           TEXT,
            ersteller_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ersteller_name VARCHAR(160) NOT NULL DEFAULT '',
            erstellt_am    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}lsv07i_benachrichtigung_gelesen (
            id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
            benachrichtigung_id   INT UNSIGNED NOT NULL,
            user_id               BIGINT UNSIGNED NOT NULL,
            user_name             VARCHAR(160) NOT NULL DEFAULT '',
            gelesen_am            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ben_user (benachrichtigung_id, user_id),
            KEY idx_ben (benachrichtigung_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
    }

    private static function require_access() {
        if ( ! current_user_can( 'administrator' ) && ! LSV07I_Access::has_any_access() ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }
    }

    private static function require_admin() {
        if ( ! LSV07I_Access::is_admin() ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }
    }

    /** Anzahl der für den aktuellen Nutzer noch ungelesenen Benachrichtigungen — für den roten Zähler. */
    public static function unread_count() {
        self::require_access();
        self::tabellen_sicherstellen();
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = get_current_user_id();

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$p}lsv07i_benachrichtigung b
              WHERE NOT EXISTS (
                  SELECT 1 FROM {$p}lsv07i_benachrichtigung_gelesen g
                   WHERE g.benachrichtigung_id = b.id AND g.user_id = %d
              )",
            $uid
        ) );

        wp_send_json_success( [ 'count' => $count ] );
    }

    /** Alle Benachrichtigungen für den aktuellen Nutzer, neueste zuerst, mit eigenem Gelesen-Status. */
    public static function liste() {
        self::require_access();
        self::tabellen_sicherstellen();
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = get_current_user_id();

        $rows = $wpdb->get_results(
            "SELECT id, titel, text, ersteller_name, erstellt_am
               FROM {$p}lsv07i_benachrichtigung
           ORDER BY erstellt_am DESC",
            ARRAY_A
        );
        if ( empty( $rows ) ) wp_send_json_success( [ 'benachrichtigungen' => [] ] );

        $ids = array_map( fn( $r ) => (int) $r['id'], $rows );
        $ids_in = implode( ',', $ids );
        $gelesen_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT benachrichtigung_id, gelesen_am FROM {$p}lsv07i_benachrichtigung_gelesen
              WHERE user_id = %d AND benachrichtigung_id IN ($ids_in)",
            $uid
        ), ARRAY_A );
        $gelesen_am_by_id = [];
        foreach ( $gelesen_rows as $g ) $gelesen_am_by_id[ (int) $g['benachrichtigung_id'] ] = $g['gelesen_am'];

        foreach ( $rows as &$r ) {
            $r['id'] = (int) $r['id'];
            $r['gelesen_am'] = $gelesen_am_by_id[ $r['id'] ] ?? null;
        }
        unset( $r );

        wp_send_json_success( [ 'benachrichtigungen' => $rows ] );
    }

    /** Markiert eine Benachrichtigung als gelesen für den aktuellen Nutzer (idempotent). */
    public static function als_gelesen() {
        self::require_access();
        self::tabellen_sicherstellen();
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'Fehlende ID.' ] );

        $existiert = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_benachrichtigung WHERE id = %d", $id
        ) );
        if ( ! $existiert ) wp_send_json_error( [ 'message' => 'Benachrichtigung nicht gefunden.' ] );

        $uid  = get_current_user_id();
        $user = wp_get_current_user();

        $schon = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_benachrichtigung_gelesen WHERE benachrichtigung_id = %d AND user_id = %d",
            $id, $uid
        ) );
        if ( ! $schon ) {
            $wpdb->insert( $p . 'lsv07i_benachrichtigung_gelesen', [
                'benachrichtigung_id' => $id,
                'user_id'             => $uid,
                'user_name'           => $user ? $user->display_name : '',
            ] );
        }

        wp_send_json_success();
    }

    /** Admin: alle Benachrichtigungen mit Lesebestätigung (wer hat wann gelesen). */
    public static function admin_liste() {
        self::require_admin();
        self::tabellen_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results(
            "SELECT id, titel, text, ersteller_name, erstellt_am
               FROM {$p}lsv07i_benachrichtigung
           ORDER BY erstellt_am DESC",
            ARRAY_A
        );
        if ( empty( $rows ) ) wp_send_json_success( [ 'benachrichtigungen' => [] ] );

        $ids = array_map( fn( $r ) => (int) $r['id'], $rows );
        $ids_in = implode( ',', $ids );
        $gelesen_rows = $wpdb->get_results(
            "SELECT benachrichtigung_id, user_name, gelesen_am
               FROM {$p}lsv07i_benachrichtigung_gelesen
              WHERE benachrichtigung_id IN ($ids_in)
           ORDER BY gelesen_am ASC",
            ARRAY_A
        );
        $leser_by_id = [];
        foreach ( $gelesen_rows as $g ) {
            $leser_by_id[ (int) $g['benachrichtigung_id'] ][] = [
                'name'       => $g['user_name'],
                'gelesen_am' => $g['gelesen_am'],
            ];
        }

        foreach ( $rows as &$r ) {
            $r['id'] = (int) $r['id'];
            $r['leser'] = $leser_by_id[ $r['id'] ] ?? [];
        }
        unset( $r );

        wp_send_json_success( [ 'benachrichtigungen' => $rows ] );
    }

    /** Admin: neue Benachrichtigung erstellen. */
    public static function admin_save() {
        self::require_admin();
        self::tabellen_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;

        $titel = sanitize_text_field( $_POST['titel'] ?? '' );
        $text  = sanitize_textarea_field( $_POST['text'] ?? '' );
        if ( $titel === '' ) wp_send_json_error( [ 'message' => 'Bitte einen Titel angeben.' ] );
        if ( $text === '' )  wp_send_json_error( [ 'message' => 'Bitte einen Text angeben.' ] );

        $user = wp_get_current_user();
        $wpdb->insert( $p . 'lsv07i_benachrichtigung', [
            'titel'          => $titel,
            'text'           => $text,
            'ersteller_id'   => get_current_user_id(),
            'ersteller_name' => $user ? $user->display_name : '',
        ] );
        $id = (int) $wpdb->insert_id;

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'benachrichtigung.create', [
                'bereich' => 'Benachrichtigungen', 'ziel_typ' => 'benachrichtigung', 'ziel_id' => $id, 'ziel_name' => $titel,
            ] );
        }

        wp_send_json_success( [ 'id' => $id ] );
    }

    /** Admin: Benachrichtigung löschen (inkl. Lesebestätigungen). */
    public static function admin_delete() {
        self::require_admin();
        self::tabellen_sicherstellen();
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'Fehlende ID.' ] );

        $titel = $wpdb->get_var( $wpdb->prepare( "SELECT titel FROM {$p}lsv07i_benachrichtigung WHERE id = %d", $id ) );
        if ( ! $titel ) wp_send_json_error( [ 'message' => 'Benachrichtigung nicht gefunden.' ] );

        $wpdb->delete( $p . 'lsv07i_benachrichtigung_gelesen', [ 'benachrichtigung_id' => $id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_benachrichtigung', [ 'id' => $id ], [ '%d' ] );

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'benachrichtigung.delete', [
                'bereich' => 'Benachrichtigungen', 'ziel_typ' => 'benachrichtigung', 'ziel_id' => $id, 'ziel_name' => $titel,
            ] );
        }

        wp_send_json_success();
    }
}
