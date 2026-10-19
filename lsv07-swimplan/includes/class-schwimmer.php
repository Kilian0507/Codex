<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Schwimmerinnen und Schwimmer.
 *
 * **Sie haben kein WordPress-Konto und keinen Zugang zu SwimPlan.** Sie sind
 * Datensätze: ein Name, ein Jahrgang, eine Mannschaft, Zeiten. Angelegt
 * werden sie von Trainern und Verwaltung, und gesehen werden sie auch nur
 * dort — SwimPlan ist das Werkzeug der Trainer, nicht das Portal der Kinder.
 *
 * Das war bis 1.2.0 anders: Da war ein Schwimmer ein WordPress-Konto mit der
 * Rolle `swimmer` und konnte sich anmelden. Das hiess, für jedes Kind ein
 * Konto anzulegen — und ein Konto, mit dem sich jemand anmelden kann, ist
 * eine Angriffsfläche, die niemand braucht, wenn ohnehin niemand damit
 * hineinsoll. Seit 1.3.0 gibt es darum nur noch Konten für die, die wirklich
 * arbeiten: Verwaltung und Trainer.
 *
 * Die `dsv_id` ist freiwillig und dient nur dem Wiedererkennen: Unter dieser
 * Nummer steht das Kind in Meldeergebnissen und im internen Bereich. SwimPlan
 * selbst macht nichts damit — es liest keine fremde Tabelle.
 */
class LSV07P_Schwimmer {

    // ── Lesen ───────────────────────────────────────────────────────────

    public static function holen( $id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_schwimmer WHERE id = %d", (int) $id ), ARRAY_A );
    }

    /** Der Name, wie er in Listen steht: „Mia Musterkind". */
    public static function name( $id ) {
        $s = self::holen( $id );
        if ( ! $s ) return '(unbekannt)';
        return self::name_aus( $s );
    }

    public static function name_aus( array $s ) {
        $n = trim( (string) ( $s['vorname'] ?? '' ) . ' ' . (string) ( $s['nachname'] ?? '' ) );
        return $n !== '' ? $n : '(ohne Namen)';
    }

    /** „Musterkind, Mia" — für Listen, die nach Nachnamen sortiert sind. */
    public static function name_sortiert( array $s ) {
        $v = trim( (string) ( $s['vorname'] ?? '' ) );
        $n = trim( (string) ( $s['nachname'] ?? '' ) );
        if ( $n === '' ) return $v !== '' ? $v : '(ohne Namen)';
        return $v === '' ? $n : ( $n . ', ' . $v );
    }

    public static function zeile( $s ) {
        if ( ! $s ) return null;
        return [
            'id'        => (int) $s['id'],
            'vorname'   => (string) $s['vorname'],
            'nachname'  => (string) $s['nachname'],
            'name'      => self::name_aus( $s ),
            'sortiert'  => self::name_sortiert( $s ),
            'jahrgang'  => (int) $s['jahrgang'],
            'dsv_id'    => (string) $s['dsv_id'],
            'aktiv'     => (int) $s['aktiv'] === 1,
            'notiz'     => (string) $s['notiz'],
        ];
    }

    /**
     * Alle Schwimmer, nach Nachnamen. `$nur_aktive` lässt die weg, die den
     * Verein verlassen haben — ihre Zeiten bleiben trotzdem stehen.
     */
    public static function alle( $nur_aktive = false, $suche = '' ) {
        global $wpdb;
        $p    = $wpdb->prefix;
        $wo   = [];
        $args = [];

        if ( $nur_aktive ) $wo[] = 'aktiv = 1';
        if ( trim( (string) $suche ) !== '' ) {
            $wie    = '%' . $wpdb->esc_like( trim( (string) $suche ) ) . '%';
            $wo[]   = '(vorname LIKE %s OR nachname LIKE %s OR dsv_id LIKE %s)';
            $args[] = $wie; $args[] = $wie; $args[] = $wie;
        }

        $sql = "SELECT * FROM {$p}lsv07p_schwimmer";
        if ( $wo ) $sql .= ' WHERE ' . implode( ' AND ', $wo );
        $sql .= ' ORDER BY nachname ASC, vorname ASC';

        $rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A )
                      : $wpdb->get_results( $sql, ARRAY_A );

        $out = [];
        foreach ( (array) $rows as $r ) $out[] = self::zeile( $r );
        return $out;
    }

    /**
     * Die Schwimmer, die das angemeldete Konto sehen darf.
     *
     * Verwaltung: alle. Trainer: die in seinen Mannschaften — die laufenden
     * und die früheren, denn zu einer alten Zeit gehört auch ein Name.
     */
    public static function meine() {
        if ( LSV07P_Rollen::ist_admin() ) return self::alle();

        $ids = [];
        foreach ( LSV07P_Rollen::eigene_teams() as $t ) {
            foreach ( LSV07P_Mannschaft::mitglieder( $t, true ) as $m ) {
                $ids[ (int) $m['schwimmer_id'] ] = true;
            }
        }
        if ( ! $ids ) return [];

        global $wpdb;
        $p     = $wpdb->prefix;
        $platz = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_schwimmer WHERE id IN ($platz)
           ORDER BY nachname ASC, vorname ASC",
            ...array_map( 'intval', array_keys( $ids ) ) ), ARRAY_A ) ?: [];

        $out = [];
        foreach ( $rows as $r ) $out[] = self::zeile( $r );
        return $out;
    }

    // ── Rechte ──────────────────────────────────────────────────────────

    /**
     * Darf das angemeldete Konto die Daten DIESES Schwimmers sehen?
     *
     * Verwaltung: jeden. Trainer: jeden, der in einer seiner Mannschaften
     * ist oder war. Diese eine Frage entscheidet über Zeiten, Bestleistungen
     * und Zuordnungen — darum steht sie hier und nicht verstreut in den
     * Endpunkten.
     */
    public static function darf( $schwimmer_id ) {
        $schwimmer_id = (int) $schwimmer_id;
        if ( ! $schwimmer_id || ! LSV07P_Rollen::hat_zugang() ) return false;
        if ( ! self::holen( $schwimmer_id ) ) return false;
        if ( LSV07P_Rollen::ist_admin() ) return true;
        if ( ! LSV07P_Rollen::ist_trainer() ) return false;

        $meine = LSV07P_Rollen::eigene_teams();
        if ( ! $meine ) return false;
        foreach ( LSV07P_Mannschaft::teams_von_schwimmer( $schwimmer_id, true ) as $t ) {
            if ( in_array( (int) $t, $meine, true ) ) return true;
        }
        return false;
    }

    // ── Schreiben ───────────────────────────────────────────────────────

    /**
     * Anlegen. Gibt die neue Nummer zurück, oder einen Text mit dem Grund.
     * Ein Nachname genügt — manche Vereine führen nur den.
     */
    /**
     * Prüfen, ohne zu schreiben.
     *
     * Dieselbe Prüfung, die auch `anlegen()` fährt — getrennt, damit der
     * CSV-Import seine Vorschau mit genau diesen Gründen bauen kann. Zwei
     * getrennte Prüfungen würden auseinanderlaufen, und dann meldete die
     * Vorschau „neu" und der Import danach einen Fehler.
     */
    public static function pruefen( array $f, $ausser = 0 ) {
        $vorname  = LSV07P_Plan::text( $f['vorname'] ?? '', 80 );
        $nachname = LSV07P_Plan::text( $f['nachname'] ?? '', 80 );
        if ( $vorname === '' && $nachname === '' ) {
            return 'Ohne Namen geht es nicht.';
        }
        if ( self::jahrgang( $f['jahrgang'] ?? 0 ) === -1 ) {
            return 'Der Jahrgang muss eine Jahreszahl sein, etwa 2012.';
        }
        $dsv = self::dsv( $f['dsv_id'] ?? '' );
        if ( $dsv !== '' && self::dsv_vergeben( $dsv, (int) $ausser ) ) {
            return 'Diese DSV-ID ist schon bei einem anderen Schwimmer eingetragen.';
        }
        return true;
    }

    public static function anlegen( array $f ) {
        global $wpdb;

        $pruef = self::pruefen( $f );
        if ( $pruef !== true ) return $pruef;

        $vorname  = LSV07P_Plan::text( $f['vorname'] ?? '', 80 );
        $nachname = LSV07P_Plan::text( $f['nachname'] ?? '', 80 );
        $jahrgang = self::jahrgang( $f['jahrgang'] ?? 0 );
        $dsv      = self::dsv( $f['dsv_id'] ?? '' );

        $wpdb->insert( $wpdb->prefix . 'lsv07p_schwimmer', [
            'vorname'      => $vorname,
            'nachname'     => $nachname,
            'jahrgang'     => $jahrgang,
            'dsv_id'       => $dsv,
            'aktiv'        => 1,
            'notiz'        => LSV07P_Plan::text( $f['notiz'] ?? '', 200 ),
            'angelegt_von' => (int) get_current_user_id(),
            'angelegt_am'  => current_time( 'mysql' ),
        ], [ '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s' ] );

        $id = (int) $wpdb->insert_id;
        if ( ! $id ) return 'Der Schwimmer liess sich nicht anlegen.';
        LSV07P_DB::spur( 'schwimmer.neu', $id, trim( $vorname . ' ' . $nachname ) );
        return $id;
    }

    public static function aendern( $id, array $f ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! self::holen( $id ) ) return 'Diesen Schwimmer gibt es nicht.';

        $daten = [];
        $form  = [];

        if ( isset( $f['vorname'] ) || isset( $f['nachname'] ) ) {
            $alt = self::holen( $id );
            $v = isset( $f['vorname'] )
                ? LSV07P_Plan::text( $f['vorname'], 80 ) : (string) $alt['vorname'];
            $n = isset( $f['nachname'] )
                ? LSV07P_Plan::text( $f['nachname'], 80 ) : (string) $alt['nachname'];
            if ( $v === '' && $n === '' ) return 'Ohne Namen geht es nicht.';
            $daten['vorname'] = $v;  $form[] = '%s';
            $daten['nachname'] = $n; $form[] = '%s';
        }
        if ( isset( $f['jahrgang'] ) ) {
            $j = self::jahrgang( $f['jahrgang'] );
            if ( $j === -1 ) return 'Der Jahrgang muss eine Jahreszahl sein, etwa 2012.';
            $daten['jahrgang'] = $j; $form[] = '%d';
        }
        if ( isset( $f['dsv_id'] ) ) {
            $d = self::dsv( $f['dsv_id'] );
            if ( $d !== '' && self::dsv_vergeben( $d, $id ) ) {
                return 'Diese DSV-ID ist schon bei einem anderen Schwimmer eingetragen.';
            }
            $daten['dsv_id'] = $d; $form[] = '%s';
        }
        if ( isset( $f['notiz'] ) ) {
            $daten['notiz'] = LSV07P_Plan::text( $f['notiz'], 200 ); $form[] = '%s';
        }
        if ( isset( $f['aktiv'] ) ) {
            $daten['aktiv'] = $f['aktiv'] ? 1 : 0; $form[] = '%d';
        }
        if ( ! $daten ) return true;

        $wpdb->update( $wpdb->prefix . 'lsv07p_schwimmer', $daten,
            [ 'id' => $id ], $form, [ '%d' ] );
        LSV07P_DB::spur( 'schwimmer.aendern', $id, implode( ', ', array_keys( $daten ) ) );
        return true;
    }

    /**
     * Austragen heisst abschalten, nicht löschen: Zeiten, Mitgliedschaften
     * und Zuordnungen bleiben stehen. Ein gelöschter Schwimmer liesse sie
     * verwaisen, und in einer alten Rangliste stünde dann niemand mehr.
     */
    public static function abschalten( $id ) {
        return self::aendern( $id, [ 'aktiv' => 0 ] );
    }

    public static function einschalten( $id ) {
        return self::aendern( $id, [ 'aktiv' => 1 ] );
    }

    /**
     * Wirklich löschen — mit allem, was daran hängt. Nur für den Fall, dass
     * jemand versehentlich angelegt wurde; sonst abschalten.
     */
    public static function loeschen( $id ) {
        global $wpdb;
        $id = (int) $id;
        $s  = self::holen( $id );
        if ( ! $s ) return false;

        $p = $wpdb->prefix;
        $wpdb->delete( $p . 'lsv07p_zeit',          [ 'schwimmer_id' => $id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07p_team_mitglied', [ 'schwimmer_id' => $id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07p_freigabe',      [ 'schwimmer_id' => $id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07p_schwimmer',     [ 'id' => $id ],           [ '%d' ] );
        LSV07P_DB::spur( 'schwimmer.weg', $id, self::name_aus( $s ) );
        return true;
    }

    // ── Kleinkram ───────────────────────────────────────────────────────

    /** Jahreszahl oder 0; -1 heisst „das ist kein Jahrgang". */
    public static function jahrgang( $roh ) {
        $s = trim( (string) $roh );
        if ( $s === '' || $s === '0' ) return 0;
        if ( ! preg_match( '/^\d{4}$/', $s ) ) return -1;
        $j = (int) $s;
        /* Vor 1900 schwimmt niemand mehr, und ein Jahrgang in der Zukunft
           ist ein Tippfehler. */
        if ( $j < 1900 || $j > (int) current_time( 'Y' ) ) return -1;
        return $j;
    }

    /** Ziffern, sonst nichts. Leer bleibt leer. */
    public static function dsv( $roh ) {
        $s = preg_replace( '/\D+/', '', (string) $roh );
        return mb_substr( (string) $s, 0, 20 );
    }

    private static function dsv_vergeben( $dsv, $ausser_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}lsv07p_schwimmer WHERE dsv_id = %s AND id <> %d",
            (string) $dsv, (int) $ausser_id ) ) > 0;
    }
}
