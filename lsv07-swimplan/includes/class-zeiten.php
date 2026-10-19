<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Zeiten und Bestleistungen.
 *
 * Zwei Festlegungen, die beide aus dem Konzept kommen und beide wichtiger
 * sind, als sie aussehen:
 *
 *  1. **Eine Zeit ist eine ganze Zahl in Hundertstelsekunden.** 01:02,35
 *     steht als 6235 in der Datenbank. Mit Kommazahlen käme irgendwann eine
 *     Zeit heraus, die um ein Hundertstel danebenliegt — und eine Zeit, die
 *     um ein Hundertstel danebenliegt, ist bei einer Qualifikation eine
 *     falsche Zeit.
 *
 *  2. **Bestleistungen werden ERMITTELT, nicht gepflegt.** Es gibt keine
 *     Spalte „Bestzeit". Die Bestzeit ist das Minimum über die erfassten
 *     Zeiten, jedes Mal frisch. Eine gepflegte Bestzeit lässt sich von einer
 *     nachträglich eingetragenen älteren Zeit überschreiben, von einem
 *     gelöschten Eintrag verwaisen und von einem Tippfehler für immer
 *     verderben. Eine ermittelte nicht: Korrigiert man die Zeit, stimmt die
 *     Bestzeit im selben Moment wieder.
 *
 * Erfasst werden Zeiten von Trainern und Verwaltung. Schwimmer haben keinen
 * Zugang zu SwimPlan — sie bekommen ihre Bestzeiten auf Papier, als PDF oder
 * über das Schwesterplugin SwimView zu sehen.
 */
class LSV07P_Zeiten {

    /**
     * Die üblichen Strecken, in der Reihenfolge, in der sie in jedem
     * Protokoll stehen. Freitext ist nicht nötig — ein Rennen über 137 m
     * gibt es nicht.
     */
    const STRECKEN = [ 25, 50, 100, 200, 400, 800, 1500 ];

    public static function strecken() {
        return self::STRECKEN;
    }

    // ── Zeiten lesen und schreiben ──────────────────────────────────────

    /**
     * Eine eingegebene Zeit in Hundertstel.
     *
     * Erlaubt: „28,42"  „28.42"  „1:02,35"  „21:14,07"  „28"  „1:02"
     * Rückgabe -1, wenn daraus keine Zeit zu machen ist. 0 gibt es nicht —
     * eine Zeit von null Hundertsteln ist keine Zeit.
     */
    public static function parse( $roh ) {
        $s = trim( (string) $roh );
        if ( $s === '' ) return -1;

        /* Komma und Punkt sind dasselbe; getippt wird in Deutschland das
           Komma, auf dem Nummernblock oft der Punkt. */
        $s = str_replace( ',', '.', $s );
        $s = preg_replace( '/\s+/', '', $s );

        if ( ! preg_match( '/^(?:(\d{1,2}):)?(\d{1,2})(?:\.(\d{1,2}))?$/', $s, $m ) ) {
            return -1;
        }
        $min = isset( $m[1] ) && $m[1] !== '' ? (int) $m[1] : 0;
        $sek = (int) $m[2];
        if ( $sek > 59 ) return -1;

        $hun = 0;
        if ( isset( $m[3] ) && $m[3] !== '' ) {
            $hun = strlen( $m[3] ) === 1 ? (int) $m[3] * 10 : (int) $m[3];
        }

        $gesamt = $min * 6000 + $sek * 100 + $hun;
        return $gesamt > 0 ? $gesamt : -1;
    }

    /** 6235 → „1:02,35", 2842 → „28,42". Unter einer Minute ohne „0:". */
    public static function text( $hundertstel ) {
        $h = max( 0, (int) $hundertstel );
        $min = intdiv( $h, 6000 );
        $sek = intdiv( $h % 6000, 100 );
        $hun = $h % 100;
        $ende = sprintf( '%02d', $hun );
        if ( $min > 0 ) {
            return $min . ':' . sprintf( '%02d', $sek ) . ',' . $ende;
        }
        return $sek . ',' . $ende;
    }

    /** Immer mit Minute — für Tabellen, in denen alles untereinander steht. */
    public static function text_lang( $hundertstel ) {
        $h = max( 0, (int) $hundertstel );
        return intdiv( $h, 6000 ) . ':' . sprintf( '%02d', intdiv( $h % 6000, 100 ) )
             . ',' . sprintf( '%02d', $h % 100 );
    }

    /** Der Abstand zweier Zeiten, mit Vorzeichen: „−0,43" oder „+1,08". */
    public static function abstand_text( $a, $b ) {
        $d = (int) $a - (int) $b;
        if ( $d === 0 ) return '±0,00';
        return ( $d < 0 ? '−' : '+' ) . self::text( abs( $d ) );
    }

    public static function holen( $id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_zeit WHERE id = %d", (int) $id ), ARRAY_A );
    }

    /**
     * Eine Zeit erfassen. Prüft Strecke, Lage und Datum; die Frage, wer das
     * für wen darf, stellt der Endpunkt über LSV07P_Schwimmer::darf().
     *
     * Gibt die neue Nummer zurück, oder einen Text mit dem Grund.
     */
    /**
     * Prüfen, ohne zu schreiben — dieselbe Prüfung wie in `erfassen()`.
     * Getrennt, damit die Vorschau des CSV-Imports mit genau diesen Gründen
     * arbeitet und nicht mit einer zweiten, eigenen Prüfung.
     */
    public static function pruefen( array $f ) {
        $schwimmer = (int) ( $f['schwimmer_id'] ?? 0 );
        if ( ! $schwimmer || ! LSV07P_Schwimmer::holen( $schwimmer ) ) {
            return 'Diesen Schwimmer gibt es nicht.';
        }
        if ( self::parse( $f['zeit'] ?? '' ) < 0 ) {
            return 'Die Zeit ist nicht zu lesen. Beispiel: 1:02,35';
        }
        if ( ! in_array( (int) ( $f['strecke_m'] ?? 0 ), self::STRECKEN, true ) ) {
            return 'Diese Strecke gibt es nicht.';
        }
        if ( ! isset( LSV07P_Rechnung::lagen_mit_zeit()[ (string) ( $f['lage'] ?? 'frei' ) ] ) ) {
            return 'Auf dieser Lage werden keine Zeiten geführt.';
        }
        $datum = LSV07P_Mannschaft::datum( $f['erreicht_am'] ?? '' );
        if ( $datum === '' ) return 'Das Datum fehlt.';
        if ( $datum > current_time( 'Y-m-d' ) ) {
            return 'Eine Zeit aus der Zukunft gibt es nicht.';
        }
        return true;
    }

    public static function erfassen( array $f ) {
        global $wpdb;

        $pruef = self::pruefen( $f );
        if ( $pruef !== true ) return $pruef;

        $schwimmer = (int) $f['schwimmer_id'];
        $hun       = self::parse( $f['zeit'] ?? '' );
        $strecke   = (int) $f['strecke_m'];
        $lage      = (string) ( $f['lage'] ?? 'frei' );
        $datum     = LSV07P_Mannschaft::datum( $f['erreicht_am'] ?? '' );

        $wpdb->insert( $wpdb->prefix . 'lsv07p_zeit', [
            'schwimmer_id'  => $schwimmer,
            'strecke_m'   => $strecke,
            'lage'        => $lage,
            'bahnlaenge'  => LSV07P_Plan::bahn( $f['bahnlaenge'] ?? 25 ),
            'hundertstel' => $hun,
            'erreicht_am' => $datum,
            'wettkampf'   => LSV07P_Plan::text( $f['wettkampf'] ?? '', 200 ),
            'offiziell'   => empty( $f['offiziell'] ) ? 0 : 1,
            'bemerkung'   => LSV07P_Plan::text( $f['bemerkung'] ?? '', 300 ),
            'erfasst_von' => LSV07P_Rollen::user_id(),
            'erfasst_am'  => current_time( 'mysql' ),
        ], [ '%d','%d','%s','%d','%d','%s','%s','%d','%s','%d','%s' ] );

        $id = (int) $wpdb->insert_id;
        if ( $id ) {
            LSV07P_DB::spur( 'zeit.neu', $schwimmer,
                $strecke . ' m ' . LSV07P_Rechnung::lage_name( $lage ) . ' ' . self::text( $hun ) );
        }
        return $id ?: 'Die Zeit liess sich nicht speichern.';
    }

    public static function aendern( $id, array $f ) {
        global $wpdb;
        $alt = self::holen( $id );
        if ( ! $alt ) return 'Diese Zeit ist nicht da.';

        $daten = [];
        $form  = [];

        if ( isset( $f['zeit'] ) ) {
            $hun = self::parse( $f['zeit'] );
            if ( $hun < 0 ) return 'Die Zeit ist nicht zu lesen. Beispiel: 1:02,35';
            $daten['hundertstel'] = $hun; $form[] = '%d';
        }
        if ( isset( $f['strecke_m'] ) ) {
            $s = (int) $f['strecke_m'];
            if ( ! in_array( $s, self::STRECKEN, true ) ) return 'Diese Strecke gibt es nicht.';
            $daten['strecke_m'] = $s; $form[] = '%d';
        }
        if ( isset( $f['lage'] ) ) {
            $l = (string) $f['lage'];
            if ( ! isset( LSV07P_Rechnung::lagen_mit_zeit()[ $l ] ) ) {
                return 'Auf dieser Lage werden keine Zeiten geführt.';
            }
            $daten['lage'] = $l; $form[] = '%s';
        }
        if ( isset( $f['bahnlaenge'] ) ) {
            $daten['bahnlaenge'] = LSV07P_Plan::bahn( $f['bahnlaenge'] ); $form[] = '%d';
        }
        if ( isset( $f['erreicht_am'] ) ) {
            $d = LSV07P_Mannschaft::datum( $f['erreicht_am'] );
            if ( $d === '' ) return 'Das Datum fehlt.';
            if ( $d > current_time( 'Y-m-d' ) ) return 'Eine Zeit aus der Zukunft gibt es nicht.';
            $daten['erreicht_am'] = $d; $form[] = '%s';
        }
        if ( isset( $f['wettkampf'] ) ) {
            $daten['wettkampf'] = LSV07P_Plan::text( $f['wettkampf'], 200 ); $form[] = '%s';
        }
        if ( isset( $f['bemerkung'] ) ) {
            $daten['bemerkung'] = LSV07P_Plan::text( $f['bemerkung'], 300 ); $form[] = '%s';
        }
        if ( isset( $f['offiziell'] ) ) {
            $daten['offiziell'] = $f['offiziell'] ? 1 : 0; $form[] = '%d';
        }
        if ( ! $daten ) return true;

        $wpdb->update( $wpdb->prefix . 'lsv07p_zeit', $daten,
            [ 'id' => (int) $id ], $form, [ '%d' ] );
        LSV07P_DB::spur( 'zeit.aendern', (int) $alt['schwimmer_id'], 'Zeit ' . (int) $id );
        return true;
    }

    public static function loeschen( $id ) {
        global $wpdb;
        $alt = self::holen( $id );
        if ( ! $alt ) return false;
        $wpdb->delete( $wpdb->prefix . 'lsv07p_zeit', [ 'id' => (int) $id ], [ '%d' ] );
        LSV07P_DB::spur( 'zeit.weg', (int) $alt['schwimmer_id'], 'Zeit ' . (int) $id );
        return true;
    }

    // ── Listen ──────────────────────────────────────────────────────────

    /** Alle Zeiten einer Person, die neueste zuerst. */
    public static function liste( $schwimmer_id, $strecke = 0, $lage = '', $bahn = 0 ) {
        global $wpdb;
        $p    = $wpdb->prefix;
        $wo   = [ 'schwimmer_id = %d' ];
        $args = [ (int) $schwimmer_id ];

        if ( (int) $strecke ) { $wo[] = 'strecke_m = %d'; $args[] = (int) $strecke; }
        if ( $lage !== '' )   { $wo[] = 'lage = %s';      $args[] = (string) $lage; }
        if ( (int) $bahn )    { $wo[] = 'bahnlaenge = %d'; $args[] = LSV07P_Plan::bahn( $bahn ); }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_zeit WHERE " . implode( ' AND ', $wo )
          . ' ORDER BY erreicht_am DESC, id DESC', ...$args ), ARRAY_A ) ?: [];

        /* Welche Zeile ist die Bestzeit? Das sagt dieselbe Ermittlung, die
           auch die Bestzeitenliste benutzt — nicht ein zweites Verfahren,
           das irgendwann anders rechnet. */
        $beste = [];
        foreach ( self::bestzeiten( $schwimmer_id ) as $b ) $beste[ $b['zeit_id'] ] = true;

        $out = [];
        foreach ( $rows as $r ) {
            $out[] = self::zeile( $r, isset( $beste[ (int) $r['id'] ] ) );
        }
        return $out;
    }

    private static function zeile( array $r, $ist_best = false ) {
        return [
            'id'        => (int) $r['id'],
            'schwimmer_id' => (int) $r['schwimmer_id'],
            'strecke_m' => (int) $r['strecke_m'],
            'lage'      => (string) $r['lage'],
            'lage_name' => LSV07P_Rechnung::lage_name( $r['lage'] ),
            'bahnlaenge' => (int) $r['bahnlaenge'],
            'bahn_text' => ( (int) $r['bahnlaenge'] ) . ' m',
            'hundertstel' => (int) $r['hundertstel'],
            'zeit'      => self::text( (int) $r['hundertstel'] ),
            'datum'     => (string) $r['erreicht_am'],
            'wettkampf' => (string) $r['wettkampf'],
            'offiziell' => (int) $r['offiziell'] === 1,
            'bemerkung' => (string) $r['bemerkung'],
            'strecke_text' => (int) $r['strecke_m'] . ' m '
                            . LSV07P_Rechnung::lage_name( $r['lage'] ),
            'bestzeit'  => (bool) $ist_best,
        ];
    }

    /**
     * Die Bestleistungen einer Person — ermittelt, nicht gespeichert.
     *
     * Gruppiert wird nach Strecke, Lage und Bahnlänge: Eine Zeit auf der
     * 25-m-Bahn ist mit einer auf der 50-m-Bahn nicht zu vergleichen, und
     * sie als eine Bestzeit zu führen wäre schlicht falsch.
     *
     * `$nur_offiziell` lässt die Trainingszeiten weg — dann steht da, was
     * auch in einer Meldung stehen darf.
     */
    public static function bestzeiten( $schwimmer_id, $nur_offiziell = false ) {
        global $wpdb;
        $p    = $wpdb->prefix;
        $wo   = [ 'schwimmer_id = %d' ];
        $args = [ (int) $schwimmer_id ];
        if ( $nur_offiziell ) $wo[] = 'offiziell = 1';

        /* Sortiert geholt und in PHP gruppiert: Die erste Zeile je Gruppe
           IST die Bestzeit, und sie bringt Datum und Wettkampf gleich mit.
           Mit MIN() im SQL käme nur die Zahl, nicht die Zeile dazu. */
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_zeit WHERE " . implode( ' AND ', $wo )
          . ' ORDER BY hundertstel ASC, erreicht_am DESC, id DESC', ...$args ), ARRAY_A ) ?: [];

        $best = [];
        $alle = [];
        foreach ( $rows as $r ) {
            $k = (int) $r['strecke_m'] . '|' . $r['lage'] . '|' . (int) $r['bahnlaenge'];
            if ( ! isset( $best[ $k ] ) ) $best[ $k ] = $r;
            if ( ! isset( $alle[ $k ] ) ) $alle[ $k ] = 0;
            $alle[ $k ]++;
        }

        $out = [];
        foreach ( $best as $k => $r ) {
            $z = self::zeile( $r, true );
            $z['zeit_id'] = (int) $r['id'];
            $z['anzahl']  = $alle[ $k ];
            $out[] = $z;
        }
        return self::sortieren( $out );
    }

    /**
     * Kanonische Reihenfolge: nach Lage wie in LSV07P_Rechnung::LAGEN,
     * darin nach Strecke aufsteigend, darin 25 m vor 50 m.
     *
     * Alphabetisch wäre falsch: Da stünde 100 m vor 50 m und 1500 m ganz
     * oben. Genau dieser Fehler ist im Schwesterplugin schon einmal
     * passiert.
     */
    public static function sortieren( array $liste ) {
        $rang = array_keys( LSV07P_Rechnung::LAGEN );
        usort( $liste, function ( $a, $b ) use ( $rang ) {
            $ra = array_search( $a['lage'], $rang, true );
            $rb = array_search( $b['lage'], $rang, true );
            if ( $ra === false ) $ra = 99;
            if ( $rb === false ) $rb = 99;
            if ( $ra !== $rb ) return $ra <=> $rb;
            if ( $a['strecke_m'] !== $b['strecke_m'] ) return $a['strecke_m'] <=> $b['strecke_m'];
            return $a['bahnlaenge'] <=> $b['bahnlaenge'];
        } );
        return $liste;
    }

    /**
     * Der Verlauf auf einer Strecke, vom ältesten zum neuesten — das ist
     * die Kurve, die ein Schwimmer sehen will. `verbesserung` steht an
     * jeder Zeile: um wie viel sie besser war als die bis dahin beste.
     */
    public static function verlauf( $schwimmer_id, $strecke, $lage, $bahn = 25 ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_zeit
              WHERE schwimmer_id = %d AND strecke_m = %d AND lage = %s AND bahnlaenge = %d
           ORDER BY erreicht_am ASC, id ASC",
            (int) $schwimmer_id, (int) $strecke, (string) $lage,
            LSV07P_Plan::bahn( $bahn ) ), ARRAY_A ) ?: [];

        $out  = [];
        $best = 0;
        foreach ( $rows as $r ) {
            $h = (int) $r['hundertstel'];
            $z = self::zeile( $r );
            $z['neue_bestzeit'] = ( $best === 0 || $h < $best );
            $z['verbesserung']  = ( $best > 0 && $h < $best ) ? $best - $h : 0;
            $z['verbesserung_text'] = $z['verbesserung']
                ? '−' . self::text( $z['verbesserung'] ) : '';
            if ( $best === 0 || $h < $best ) $best = $h;
            $out[] = $z;
        }
        return $out;
    }

    /**
     * Die Bestzeiten einer ganzen Mannschaft auf einer Strecke, als
     * Rangliste. Nur aktuelle Mitglieder — eine Rangliste mit Ehemaligen
     * sagt nichts über die Mannschaft von heute.
     */
    public static function rangliste( $team_id, $strecke, $lage, $bahn = 25 ) {
        $bahn = LSV07P_Plan::bahn( $bahn );
        $out  = [];

        foreach ( LSV07P_Mannschaft::mitglieder( $team_id ) as $m ) {
            if ( ! $m['aktiv'] ) continue;
            foreach ( self::bestzeiten( $m['schwimmer_id'] ) as $b ) {
                if ( (int) $b['strecke_m'] !== (int) $strecke ) continue;
                if ( (string) $b['lage'] !== (string) $lage ) continue;
                if ( (int) $b['bahnlaenge'] !== $bahn ) continue;
                $out[] = [
                    'schwimmer_id' => $m['schwimmer_id'],
                    'name'        => $m['name'],
                    'hundertstel' => $b['hundertstel'],
                    'zeit'        => $b['zeit'],
                    'datum'       => $b['datum'],
                    'wettkampf'   => $b['wettkampf'],
                    'offiziell'   => $b['offiziell'],
                ];
            }
        }

        usort( $out, function ( $a, $b ) { return $a['hundertstel'] <=> $b['hundertstel']; } );

        $platz = 0;
        $vor   = -1;
        foreach ( $out as $i => &$z ) {
            /* Gleiche Zeit, gleicher Platz — wie im Protokoll. */
            if ( $z['hundertstel'] !== $vor ) {
                $platz = $i + 1;
                $vor   = $z['hundertstel'];
            }
            $z['platz']   = $platz;
            $z['abstand'] = $i === 0 ? ''
                : self::abstand_text( $z['hundertstel'], $out[0]['hundertstel'] );
        }
        unset( $z );
        return $out;
    }

    /**
     * Welche Strecken hat diese Person überhaupt geschwommen — für die
     * Auswahlliste im Verlauf. Sonst stünden dort 150 Kombinationen, von
     * denen 145 leer sind.
     */
    public static function vorhandene_strecken( $schwimmer_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT strecke_m, lage, bahnlaenge FROM {$p}lsv07p_zeit
              WHERE schwimmer_id = %d", (int) $schwimmer_id ), ARRAY_A ) ?: [];

        $out = [];
        foreach ( $rows as $r ) {
            $out[] = [
                'strecke_m'  => (int) $r['strecke_m'],
                'lage'       => (string) $r['lage'],
                'bahnlaenge' => (int) $r['bahnlaenge'],
                'text'       => (int) $r['strecke_m'] . ' m '
                              . LSV07P_Rechnung::lage_name( $r['lage'] )
                              . ' (' . (int) $r['bahnlaenge'] . '-m-Bahn)',
            ];
        }
        return self::sortieren( $out );
    }
}
