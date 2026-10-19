<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Mannschaften und wer dazugehört.
 *
 * Hier liegt die Zuordnung, aus der alle Zugriffsrechte folgen: Welche
 * Mannschaft ein Trainer betreut, und welche Schwimmer in einer Mannschaft
 * sind. Die Mitgliedschaft hat Start und Ende — wer die Mannschaft
 * verlässt, verliert seine Trainings- und Leistungshistorie nicht, er ist
 * nur nicht mehr aktuelles Mitglied.
 */
class LSV07P_Mannschaft {

    // ── Mannschaften ────────────────────────────────────────────────────

    public static function alle( $nur_aktive = false ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $sql = "SELECT * FROM {$p}lsv07p_team";
        if ( $nur_aktive ) $sql .= ' WHERE aktiv = 1';
        $sql .= ' ORDER BY sortierung ASC, name ASC';
        return $wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }

    public static function alle_ids( $nur_aktive = false ) {
        $out = [];
        foreach ( self::alle( $nur_aktive ) as $t ) $out[] = (int) $t['id'];
        return $out;
    }

    public static function holen( $id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_team WHERE id = %d", (int) $id ), ARRAY_A );
    }

    public static function anlegen( $name, $beschreibung = '', $sortierung = 0 ) {
        global $wpdb;
        $name = trim( sanitize_text_field( $name ) );
        if ( $name === '' ) return 0;

        $p = $wpdb->prefix;
        $wpdb->insert( $p . 'lsv07p_team', [
            'name'         => mb_substr( $name, 0, 150 ),
            'beschreibung' => mb_substr( sanitize_textarea_field( (string) $beschreibung ), 0, 2000 ),
            'aktiv'        => 1,
            'sortierung'   => max( 0, min( 9999, (int) $sortierung ) ),
            'angelegt_am'  => current_time( 'mysql' ),
        ], [ '%s', '%s', '%d', '%d', '%s' ] );

        $id = (int) $wpdb->insert_id;
        if ( $id ) LSV07P_DB::spur( 'team.neu', $id, $name );
        return $id;
    }

    public static function aendern( $id, array $felder ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! $id || ! self::holen( $id ) ) return false;

        $daten = [];
        $form  = [];
        if ( isset( $felder['name'] ) ) {
            $n = trim( sanitize_text_field( $felder['name'] ) );
            if ( $n === '' ) return false;
            $daten['name'] = mb_substr( $n, 0, 150 ); $form[] = '%s';
        }
        if ( isset( $felder['beschreibung'] ) ) {
            $daten['beschreibung'] = mb_substr(
                sanitize_textarea_field( (string) $felder['beschreibung'] ), 0, 2000 );
            $form[] = '%s';
        }
        if ( isset( $felder['aktiv'] ) ) {
            $daten['aktiv'] = $felder['aktiv'] ? 1 : 0; $form[] = '%d';
        }
        if ( isset( $felder['sortierung'] ) ) {
            $daten['sortierung'] = max( 0, min( 9999, (int) $felder['sortierung'] ) ); $form[] = '%d';
        }
        if ( ! $daten ) return true;

        $wpdb->update( $wpdb->prefix . 'lsv07p_team', $daten, [ 'id' => $id ], $form, [ '%d' ] );
        LSV07P_DB::spur( 'team.aendern', $id, implode( ', ', array_keys( $daten ) ) );
        return true;
    }

    // ── Trainer ─────────────────────────────────────────────────────────

    public static function trainer_von_team( $team_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT wp_user_id FROM {$p}lsv07p_team_trainer WHERE team_id = %d",
            (int) $team_id ) ) );
    }

    public static function teams_von_trainer( $wp_user_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT t.id FROM {$p}lsv07p_team t
               JOIN {$p}lsv07p_team_trainer tt ON tt.team_id = t.id
              WHERE tt.wp_user_id = %d
           ORDER BY t.sortierung ASC, t.name ASC", (int) $wp_user_id ) ) );
    }

    public static function trainer_zuteilen( $team_id, $wp_user_id ) {
        global $wpdb;
        $team_id    = (int) $team_id;
        $wp_user_id = (int) $wp_user_id;
        if ( ! self::holen( $team_id ) ) return false;

        /* Nur wer als Trainer oder Verwaltung geführt wird. Ein Schwimmer
           als Trainer einer Mannschaft wäre genau die Rechteverwechslung,
           die das Konzept ausschliesst. */
        $person = LSV07P_Rollen::person( $wp_user_id );
        if ( ! $person || ! in_array( $person['rolle'],
                [ LSV07P_Rollen::TRAINER, LSV07P_Rollen::ADMIN ], true ) ) {
            return false;
        }

        $p = $wpdb->prefix;
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$p}lsv07p_team_trainer (team_id, wp_user_id, zugeteilt_am)
             VALUES (%d, %d, %s)", $team_id, $wp_user_id, current_time( 'mysql' ) ) );
        LSV07P_DB::spur( 'team.trainer.an', $team_id, 'Konto ' . $wp_user_id );
        return true;
    }

    public static function trainer_entziehen( $team_id, $wp_user_id ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'lsv07p_team_trainer',
            [ 'team_id' => (int) $team_id, 'wp_user_id' => (int) $wp_user_id ], [ '%d', '%d' ] );
        LSV07P_DB::spur( 'team.trainer.aus', (int) $team_id, 'Konto ' . (int) $wp_user_id );
        return true;
    }

    // ── Schwimmer ───────────────────────────────────────────────────────

    /**
     * Die Mitglieder einer Mannschaft.
     * `$auch_ehemalige` nimmt die mit beendeter Mitgliedschaft dazu.
     */
    public static function mitglieder( $team_id, $auch_ehemalige = false ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $sql = "SELECT * FROM {$p}lsv07p_team_mitglied WHERE team_id = %d";
        if ( ! $auch_ehemalige ) {
            $sql .= " AND (ende_datum IS NULL OR ende_datum >= %s)";
        }
        $sql .= ' ORDER BY start_datum ASC, id ASC';

        $args = $auch_ehemalige ? [ (int) $team_id ] : [ (int) $team_id, current_time( 'Y-m-d' ) ];
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A ) ?: [];

        $out = [];
        foreach ( $rows as $r ) {
            $person = LSV07P_Rollen::person_roh( (int) $r['wp_user_id'] );
            $out[] = [
                'id'       => (int) $r['id'],
                'user_id'  => (int) $r['wp_user_id'],
                'name'     => LSV07P_Rollen::name( (int) $r['wp_user_id'] ),
                'von'      => (string) $r['start_datum'],
                'bis'      => $r['ende_datum'] ? (string) $r['ende_datum'] : '',
                'laeuft'   => empty( $r['ende_datum'] ),
                'aktiv'    => $person ? ( (int) $person['aktiv'] === 1 ) : false,
            ];
        }
        return $out;
    }

    /** Die Mannschaften, in denen eine Person ist (oder war). */
    public static function teams_von_schwimmer( $wp_user_id, $auch_ehemalige = false ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $sql = "SELECT DISTINCT team_id FROM {$p}lsv07p_team_mitglied WHERE wp_user_id = %d";
        if ( ! $auch_ehemalige ) {
            $sql .= " AND (ende_datum IS NULL OR ende_datum >= %s)";
        }
        $args = $auch_ehemalige ? [ (int) $wp_user_id ]
                                : [ (int) $wp_user_id, current_time( 'Y-m-d' ) ];
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) ) );
    }

    /**
     * Nimmt einen Schwimmer in eine Mannschaft auf.
     *
     * Eine laufende Mitgliedschaft darf es je Mannschaft nur einmal geben —
     * sonst stünde dieselbe Person zweimal in der Liste und bekäme jeden
     * Plan doppelt.
     */
    public static function aufnehmen( $team_id, $wp_user_id, $start = '' ) {
        global $wpdb;
        $team_id    = (int) $team_id;
        $wp_user_id = (int) $wp_user_id;
        if ( ! self::holen( $team_id ) ) return false;

        $person = LSV07P_Rollen::person( $wp_user_id );
        if ( ! $person ) return false;

        $p = $wpdb->prefix;
        $laeuft = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}lsv07p_team_mitglied
              WHERE team_id = %d AND wp_user_id = %d
                AND (ende_datum IS NULL OR ende_datum >= %s)",
            $team_id, $wp_user_id, current_time( 'Y-m-d' ) ) );
        if ( $laeuft > 0 ) return false;

        $start = self::datum( $start ) ?: current_time( 'Y-m-d' );
        $wpdb->insert( $p . 'lsv07p_team_mitglied', [
            'team_id'     => $team_id,
            'wp_user_id'  => $wp_user_id,
            'start_datum' => $start,
            'ende_datum'  => null,
        ], [ '%d', '%d', '%s', '%s' ] );

        /* Die Nummer VOR der Prüfspur merken: Deren Eintrag ist selbst ein
           INSERT und setzt `insert_id` neu. Danach gelesen käme hier die
           Nummer der Protokollzeile heraus — und das Beenden dieser
           Mitgliedschaft fände nichts. */
        $id = (int) $wpdb->insert_id;
        LSV07P_DB::spur( 'team.mitglied.an', $team_id, 'Konto ' . $wp_user_id );
        return $id;
    }

    /** Beendet eine Mitgliedschaft — ohne die Historie anzurühren. */
    public static function beenden( $mitglied_id, $ende = '' ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_team_mitglied WHERE id = %d", (int) $mitglied_id ), ARRAY_A );
        if ( ! $row ) return false;

        $ende = self::datum( $ende ) ?: current_time( 'Y-m-d' );
        $wpdb->update( $p . 'lsv07p_team_mitglied', [ 'ende_datum' => $ende ],
            [ 'id' => (int) $mitglied_id ], [ '%s' ], [ '%d' ] );
        LSV07P_DB::spur( 'team.mitglied.aus', (int) $row['team_id'],
            'Konto ' . (int) $row['wp_user_id'] );
        return true;
    }

    /** Ist diese Person zu diesem Datum Mitglied dieser Mannschaft? */
    public static function war_mitglied( $team_id, $wp_user_id, $datum ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $d = self::datum( $datum ) ?: current_time( 'Y-m-d' );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}lsv07p_team_mitglied
              WHERE team_id = %d AND wp_user_id = %d
                AND start_datum <= %s
                AND (ende_datum IS NULL OR ende_datum >= %s)",
            (int) $team_id, (int) $wp_user_id, $d, $d ) ) > 0;
    }

    /** Y-m-d oder leer. */
    public static function datum( $roh ) {
        $s = trim( (string) $roh );
        if ( $s === '' ) return '';
        if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m ) ) {
            if ( checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) return $s;
        }
        if ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $m ) ) {
            if ( checkdate( (int) $m[2], (int) $m[1], (int) $m[3] ) ) {
                return sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] );
            }
        }
        return '';
    }
}
