<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Brücke zum internen Bereich.
 *
 * Hier und NUR hier wird auf dessen Tabellen zugegriffen, und zwar
 * ausschließlich lesend. Der interne Bereich bleibt die Quelle für
 * Mannschaften, Schwimmer, Mannschaftsbuch, Wettkämpfe, Bestzeiten,
 * Reflexion und Trainingspläne; SwimView zeigt davon das, was freigegeben
 * ist.
 *
 * Ausnahme mit Ansage: der Reflexionsbogen. Ihn FÜLLT der Schwimmer hier
 * aus, also wird dort geschrieben — in genau dieselben Zeilen, die der
 * interne Bereich auch über seinen eigenen Weg füllt. Das steht in
 * LSV07S_Daten, nicht hier, und ist der einzige schreibende Zugriff.
 *
 * Fehlt der interne Bereich oder eine seiner Tabellen, liefert jede Methode
 * eine leere Liste und fehlt() sagt, was fehlt — statt dass SwimView mit
 * einem Datenbankfehler stehen bleibt.
 */
class LSV07S_Intern {

    private static $vorhanden = [];
    private static $fehlend   = [];
    private static $spalten   = [];

    public static function tabelle( $name ) {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    /** Gibt es diese Tabelle? Einmal je Aufruf nachsehen genügt. */
    public static function da( $name ) {
        if ( isset( self::$vorhanden[ $name ] ) ) return self::$vorhanden[ $name ];
        global $wpdb;
        $voll    = self::tabelle( $name );
        $treffer = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $voll ) );
        self::$vorhanden[ $name ] = ( $treffer === $voll );
        if ( ! self::$vorhanden[ $name ] ) self::$fehlend[ $name ] = true;
        return self::$vorhanden[ $name ];
    }

    public static function alle_da( array $namen ) {
        foreach ( $namen as $n ) if ( ! self::da( $n ) ) return false;
        return true;
    }

    /**
     * Gibt es diese Spalte? Der interne Bereich rüstet Spalten nach — wer
     * eine abfragt, die es in einer älteren Fassung noch nicht gibt, bekommt
     * einen Datenbankfehler und eine stumm leere Ansicht.
     */
    public static function spalte_da( $tabelle, $spalte ) {
        $key = $tabelle . '.' . $spalte;
        if ( isset( self::$spalten[ $key ] ) ) return self::$spalten[ $key ];
        if ( ! self::da( $tabelle ) ) return self::$spalten[ $key ] = false;
        global $wpdb;
        $treffer = $wpdb->get_var( $wpdb->prepare(
            'SHOW COLUMNS FROM ' . self::tabelle( $tabelle ) . ' LIKE %s', $spalte ) );
        return self::$spalten[ $key ] = ( $treffer !== null && $treffer !== '' );
    }

    /** Was beim letzten Zugriff gefehlt hat — für eine verständliche Meldung. */
    public static function fehlt() {
        return array_keys( self::$fehlend );
    }

    public static function hinweis() {
        $f = self::fehlt();
        if ( ! $f ) return '';
        return 'Der interne Bereich ist nicht vollständig erreichbar — diese Tabellen fehlen: '
             . implode( ', ', $f ) . '.';
    }

    // ── Schwimmer ───────────────────────────────────────────────────────

    /**
     * Eine Person anhand ihrer DSV-ID. Gesucht wird ohne Rücksicht auf
     * Leerzeichen und Bindestriche, denn auf dem Ausweis steht sie mal so,
     * mal so. Nur aktive Personen.
     */
    public static function schwimmer_by_dsv( $dsv_norm ) {
        global $wpdb;
        if ( ! self::da( 'mv_swimmers' ) ) return null;
        $p = $wpdb->prefix;
        $treffer = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name, birth_date, team_id, dsv_id, email
               FROM {$p}mv_swimmers
              WHERE active = 1
                AND REPLACE(REPLACE(REPLACE(dsv_id, ' ', ''), '-', ''), '.', '') = %s
           ORDER BY id ASC",
            $dsv_norm
        ), ARRAY_A );
        if ( ! $treffer ) return null;
        /* Mehr als ein Treffer heisst: dieselbe DSV-ID steht an zwei Personen.
           Dann ist nicht entscheidbar, wer sich da anmeldet — und raten wäre
           die falsche Antwort auf eine Verwechslungsgefahr. */
        if ( count( $treffer ) > 1 ) return null;
        return $treffer[0];
    }

    public static function schwimmer( $id ) {
        global $wpdb;
        if ( ! self::da( 'mv_swimmers' ) ) return null;
        $p = $wpdb->prefix;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, first_name, last_name, birth_date, team_id, dsv_id, email, active
               FROM {$p}mv_swimmers WHERE id = %d",
            (int) $id
        ), ARRAY_A );
    }

    /**
     * Die Mannschaften einer Person: team_id und zusätzlich die M:N-Tabelle,
     * weil der interne Bereich beides führt.
     */
    public static function mannschaften_von_schwimmer( $schwimmer_id ) {
        global $wpdb;
        if ( ! self::da( 'mv_swimmers' ) ) return [];
        $p   = $wpdb->prefix;
        $ids = [];

        $team = $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$p}mv_swimmers WHERE id = %d", (int) $schwimmer_id ) );
        if ( $team ) $ids[] = (int) $team;

        if ( self::da( 'lsv07i_swimmer_gruppen' ) ) {
            $weitere = $wpdb->get_col( $wpdb->prepare(
                "SELECT gruppe_id FROM {$p}lsv07i_swimmer_gruppen WHERE swimmer_id = %d",
                (int) $schwimmer_id ) );
            foreach ( (array) $weitere as $g ) $ids[] = (int) $g;
        }

        $ids = array_values( array_unique( array_filter( $ids ) ) );
        return $ids;
    }

    /** Namen der Mannschaften zu einer Liste von IDs, als id => name. */
    public static function mannschaft_namen( array $ids ) {
        global $wpdb;
        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
        if ( ! $ids || ! self::da( 'lsv07_gruppen' ) ) return [];
        $p    = $wpdb->prefix;
        $platz = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name FROM {$p}lsv07_gruppen WHERE id IN ($platz) ORDER BY sort_order ASC, name ASC",
            ...$ids
        ), ARRAY_A );
        $out = [];
        foreach ( (array) $rows as $r ) $out[ (int) $r['id'] ] = $r['name'];
        return $out;
    }

    /** Alle aktiven Schwimmer einer Mannschaft — fürs Trainerportal. */
    public static function schwimmer_der_mannschaft( $mannschaft_id ) {
        global $wpdb;
        if ( ! self::da( 'mv_swimmers' ) ) return [];
        $p  = $wpdb->prefix;
        $mn = (int) $mannschaft_id;
        $mn_tbl = self::da( 'lsv07i_swimmer_gruppen' );

        $sql = "SELECT DISTINCT s.id, s.first_name, s.last_name, s.birth_date, s.dsv_id
                  FROM {$p}mv_swimmers s
                 WHERE s.active = 1 AND ( s.team_id = %d";
        if ( $mn_tbl ) {
            $sql .= " OR EXISTS (SELECT 1 FROM {$p}lsv07i_swimmer_gruppen sg
                                  WHERE sg.swimmer_id = s.id AND sg.gruppe_id = %d)";
        }
        $sql .= " ) ORDER BY s.last_name ASC, s.first_name ASC";

        $args = $mn_tbl ? [ $mn, $mn ] : [ $mn ];
        return $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A ) ?: [];
    }

    // ── Trainer ─────────────────────────────────────────────────────────

    /** Die Trainer-ID zu einem WordPress-Konto, oder 0. */
    public static function trainer_id( $user_id ) {
        global $wpdb;
        if ( ! self::da( 'lsv07i_trainer' ) ) return 0;
        $p = $wpdb->prefix;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_trainer WHERE wp_user_id = %d AND aktiv = 1 ORDER BY id ASC LIMIT 1",
            (int) $user_id ) );
        return $id ? (int) $id : 0;
    }

    /** Die Mannschaften eines Trainers. */
    public static function trainer_mannschaften( $trainer_id ) {
        global $wpdb;
        if ( ! self::da( 'lsv07i_trainer_mannschaft' ) ) return [];
        $p = $wpdb->prefix;
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT mannschaft_id FROM {$p}lsv07i_trainer_mannschaft WHERE trainer_id = %d",
            (int) $trainer_id ) ) );
    }

    /** Alle Mannschaften — für Admin und Schwimmwart. */
    public static function alle_mannschaften() {
        global $wpdb;
        if ( ! self::da( 'lsv07_gruppen' ) ) return [];
        $p = $wpdb->prefix;
        return $wpdb->get_results(
            "SELECT id, name FROM {$p}lsv07_gruppen ORDER BY sort_order ASC, name ASC",
            ARRAY_A ) ?: [];
    }

    /** Der Anzeigename eines Trainers, mit Rückfall auf das WordPress-Konto. */
    public static function trainer_name( $user_id ) {
        global $wpdb;
        if ( self::da( 'lsv07i_trainer' ) ) {
            $p    = $wpdb->prefix;
            $name = $wpdb->get_var( $wpdb->prepare(
                "SELECT name FROM {$p}lsv07i_trainer WHERE wp_user_id = %d AND aktiv = 1 ORDER BY id ASC LIMIT 1",
                (int) $user_id ) );
            if ( $name ) return $name;
        }
        $u = get_userdata( (int) $user_id );
        return $u ? $u->display_name : '';
    }

    // ── Saison ──────────────────────────────────────────────────────────

    /** Die aktive Saison als Zeile, oder null. */
    public static function saison_aktiv() {
        global $wpdb;
        if ( ! self::da( 'lsv07i_saisons' ) ) return null;
        $p = $wpdb->prefix;
        return $wpdb->get_row(
            "SELECT * FROM {$p}lsv07i_saisons WHERE aktiv = 1 ORDER BY id DESC LIMIT 1",
            ARRAY_A );
    }
}
