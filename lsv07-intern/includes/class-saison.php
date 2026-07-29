<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LSV07I_Saison — Saison-Verwaltung
 *
 * Saisons gruppieren Trainingszeiten zeitlich. Pro Saison gibt es einen Satz
 * eigener Slots in jeder Sparte (Schwimmen/Triathlon/Fitness). Beim Wechsel
 * der aktiven Saison werden die alten Slots NICHT gelöscht — sie bleiben
 * historisch erhalten und Anwesenheit/Abrechnung greifen weiterhin darauf zu.
 *
 * Nur eine Saison ist zu jedem Zeitpunkt aktiv. Neu erstellte Slots werden
 * automatisch der aktiven Saison zugeordnet.
 */
class LSV07I_Saison {

    private static $cache_active = null;

    /**
     * Liefert alle Saisons, neueste zuerst.
     */
    public static function all() {
        global $wpdb;
        $tbl = $wpdb->prefix . 'lsv07i_saisons';
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$tbl'" ) ) return [];
        return $wpdb->get_results(
            "SELECT * FROM $tbl ORDER BY aktiv DESC, start_datum DESC, id DESC",
            ARRAY_A
        ) ?: [];
    }

    /**
     * Liefert die aktive Saison als Array oder null.
     */
    public static function active() {
        if ( self::$cache_active !== null ) return self::$cache_active;
        global $wpdb;
        $tbl = $wpdb->prefix . 'lsv07i_saisons';
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$tbl'" ) ) {
            self::$cache_active = null;
            return null;
        }
        $row = $wpdb->get_row(
            "SELECT * FROM $tbl WHERE aktiv = 1 ORDER BY start_datum DESC LIMIT 1",
            ARRAY_A
        );
        self::$cache_active = $row ?: null;
        return self::$cache_active;
    }

    /**
     * ID der aktiven Saison (oder 0 wenn keine).
     */
    public static function active_id() {
        $a = self::active();
        return $a ? (int) $a['id'] : 0;
    }

    /**
     * Saison anlegen oder aktualisieren.
     */
    public static function save( $id, $name, $start, $ende ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'lsv07i_saisons';

        $name  = trim( wp_strip_all_tags( (string) $name ) );
        if ( $name === '' ) return new WP_Error( 'invalid_name', 'Name darf nicht leer sein.' );

        // Datumsvalidierung
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ) {
            return new WP_Error( 'invalid_start', 'Start-Datum fehlt oder ungültig.' );
        }
        if ( $ende !== '' && $ende !== null && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ende ) ) {
            return new WP_Error( 'invalid_ende', 'Ende-Datum ungültig.' );
        }
        if ( $ende && $ende < $start ) {
            return new WP_Error( 'invalid_range', 'Ende-Datum darf nicht vor dem Start liegen.' );
        }

        // Name-Duplikat-Check
        $dup = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $tbl WHERE name = %s AND id != %d LIMIT 1",
            $name, (int) $id
        ) );
        if ( $dup ) return new WP_Error( 'name_conflict', 'Eine Saison mit diesem Namen existiert bereits.' );

        $data = [
            'name'        => $name,
            'start_datum' => $start,
            'ende_datum'  => $ende ?: null,
        ];
        $fmt = [ '%s', '%s', '%s' ];

        if ( $id ) {
            $result = $wpdb->update( $tbl, $data, [ 'id' => (int) $id ], $fmt, [ '%d' ] );
            // Klammern nicht vergessen: ohne sie lief das return immer und
            // jedes Speichern meldete einen Datenbankfehler.
            if ( $result === false ) {
                $fehler = $wpdb->last_error ?: 'Saison konnte nicht gespeichert werden.';
                do_action( 'lsv07i_db_error_log', $fehler );
                error_log( 'LSV07I DB: ' . $fehler );
                return new WP_Error( 'db_error', 'Die Saison konnte nicht gespeichert werden: ' . $fehler );
            }
            self::$cache_active = null;
            return (int) $id;
        }

        $data['erstellt_von'] = get_current_user_id();
        $fmt[] = '%d';
        $result = $wpdb->insert( $tbl, $data, $fmt );
        if ( $result === false ) {
            $fehler = $wpdb->last_error ?: 'Saison konnte nicht angelegt werden.';
            do_action( 'lsv07i_db_error_log', $fehler );
            error_log( 'LSV07I DB: ' . $fehler );
            return new WP_Error( 'db_error', 'Die Saison konnte nicht angelegt werden: ' . $fehler );
        }
        self::$cache_active = null;
        return (int) $wpdb->insert_id;
    }

    /**
     * Saison aktivieren. Setzt alle anderen Saisons inaktiv.
     */
    public static function activate( $id ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'lsv07i_saisons';
        $id  = (int) $id;
        if ( ! $id ) return false;

        // Existiert die Saison?
        $exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tbl WHERE id = %d", $id ) );
        if ( ! $exists ) return false;

        $wpdb->query( "UPDATE $tbl SET aktiv = 0" );
        $wpdb->update( $tbl, [ 'aktiv' => 1 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
        self::$cache_active = null;
        return true;
    }

    /**
     * Saison löschen. Die zugehörigen Slots werden NICHT gelöscht —
     * sie bleiben historisch erhalten, ihre saison_id zeigt aber dann
     * auf eine nicht-mehr-existente Saison. UI muss damit umgehen
     * (Anzeige als "Unbekannte Saison").
     *
     * Wir verbieten das Löschen der einzig verbleibenden Saison und
     * der aktiven Saison.
     */
    public static function delete( $id ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'lsv07i_saisons';
        $id  = (int) $id;

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tbl WHERE id = %d", $id ), ARRAY_A );
        if ( ! $row ) return new WP_Error( 'not_found', 'Saison nicht gefunden.' );
        if ( $row['aktiv'] ) return new WP_Error( 'is_active', 'Die aktive Saison kann nicht gelöscht werden. Erst eine andere aktivieren.' );

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tbl" );
        if ( $count <= 1 ) return new WP_Error( 'last_one', 'Die letzte verbleibende Saison kann nicht gelöscht werden.' );

        $wpdb->delete( $tbl, [ 'id' => $id ], [ '%d' ] );
        self::$cache_active = null;
        return true;
    }
}
