<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Statistik.
 *
 * Wer nur Trainer ist, sieht ausschließlich die eigenen Zahlen — das wird
 * hier erzwungen und nicht der Oberfläche überlassen. Wart, Kasse und
 * Administration sehen alle Trainer.
 */
class LSV07A_Ajax_Statistik {

    public static function init() {
        add_action( 'wp_ajax_lsv07a_stat_eigene', [ __CLASS__, 'eigene' ] );
        add_action( 'wp_ajax_lsv07a_stat_alle',   [ __CLASS__, 'alle' ] );
    }

    private static function tbl( $n ) { global $wpdb; return $wpdb->prefix . $n; }

    /** Darf diese Person die Zahlen aller Trainer sehen? */
    private static function sieht_alle() {
        return LSV07A_Rollen::ist_wart() || LSV07A_Rollen::ist_kasse() || LSV07A_Rollen::ist_admin();
    }

    /**
     * Was ein Jahr voraussichtlich kostet.
     *
     * Gerechnet wird nur mit VOLL VERGANGENEN Quartalen: Das laufende ist
     * halb leer und würde die Rechnung nach unten ziehen. Für ein
     * abgelaufenes Jahr gibt es nichts hochzurechnen — da steht die Zahl
     * schon fest, und eine Schätzung daneben wäre nur verwirrend.
     */
    private static function hochrechnung( $jahr, array $je_quartal ) {
        $heute      = current_time( 'Y-m-d' );
        $jetzt_jahr = (int) date( 'Y', strtotime( $heute ) );
        $aus        = [ 'moeglich' => false, 'grund' => '', 'bisher' => 0.0,
                        'quartale' => 0, 'erwartet' => 0.0 ];

        if ( $jahr < $jetzt_jahr ) {
            $aus['grund'] = 'Das Jahr ist abgeschlossen — die Zahl steht fest.';
            return $aus;
        }
        if ( $jahr > $jetzt_jahr ) {
            $aus['grund'] = 'Das Jahr hat noch nicht begonnen.';
            return $aus;
        }

        $q_jetzt = (int) ceil( (int) date( 'n', strtotime( $heute ) ) / 3 );
        $fertig  = $q_jetzt - 1;
        if ( $fertig < 1 ) {
            $aus['grund'] = 'Noch kein Quartal ist vorbei — dafür fehlt die Grundlage.';
            return $aus;
        }

        $bisher = 0.0;
        foreach ( [ 'Q1', 'Q2', 'Q3', 'Q4' ] as $i => $q ) {
            if ( $i >= $fertig ) break;
            $bisher += (float) ( $je_quartal[ $q ] ?? 0 );
        }
        if ( $bisher <= 0 ) {
            $aus['grund'] = 'In den vergangenen Quartalen wurde nichts abgerechnet.';
            return $aus;
        }
        return [
            'moeglich'  => true,
            'grund'     => '',
            'bisher'    => round( $bisher, 2 ),
            'quartale'  => $fertig,
            'erwartet'  => round( $bisher / $fertig * 4, 2 ),
        ];
    }

    /** Die Summe eines Jahres — für den Vergleich mit dem Vorjahr. */
    private static function jahressumme( $jahr, $uid = 0 ) {
        global $wpdb;
        $sql = "SELECT COALESCE(SUM(p.betrag),0)
                  FROM " . self::tbl( 'lsv07a_abrechnung' ) . " a
             LEFT JOIN " . self::tbl( 'lsv07a_posten' ) . " p ON p.abrechnung_id = a.id
                 WHERE a.jahr = %d";
        $werte = [ (int) $jahr ];
        if ( $uid ) { $sql .= " AND a.wp_user_id = %d"; $werte[] = (int) $uid; }
        return round( (float) $wpdb->get_var( $wpdb->prepare( $sql, $werte ) ), 2 );
    }

    /** Dasselbe je Quartal — damit sich Quartal gegen Vorjahresquartal stellen lässt. */
    private static function quartalssummen( $jahr, $uid = 0 ) {
        global $wpdb;
        $sql = "SELECT a.quartal, COALESCE(SUM(p.betrag),0) AS betrag
                  FROM " . self::tbl( 'lsv07a_abrechnung' ) . " a
             LEFT JOIN " . self::tbl( 'lsv07a_posten' ) . " p ON p.abrechnung_id = a.id
                 WHERE a.jahr = %d";
        $werte = [ (int) $jahr ];
        if ( $uid ) { $sql .= " AND a.wp_user_id = %d"; $werte[] = (int) $uid; }
        $sql .= " GROUP BY a.quartal";
        $zeilen = $wpdb->get_results( $wpdb->prepare( $sql, $werte ), ARRAY_A ) ?: [];
        $out = array_fill_keys( [ 'Q1', 'Q2', 'Q3', 'Q4' ], 0.0 );
        foreach ( $zeilen as $z ) {
            if ( isset( $out[ $z['quartal'] ] ) ) $out[ $z['quartal'] ] = round( (float) $z['betrag'], 2 );
        }
        return $out;
    }

    /**
     * Was jede Mannschaft gekostet hat.
     *
     * Nur Trainingsposten tragen eine Mannschaft — Fahrten, Wettkämpfe
     * und Sonstiges nicht. Die landen deshalb in einer eigenen Zeile
     * „ohne Mannschaft", statt stillschweigend zu fehlen: Sonst wäre die
     * Summe der Mannschaften kleiner als das Jahresergebnis, und niemand
     * wüsste warum.
     */
    private static function nach_mannschaft( $jahr, $uid = 0 ) {
        global $wpdb;
        $sql = "SELECT p.mannschaft_id, COALESCE(SUM(p.betrag),0) AS betrag,
                       COALESCE(SUM(CASE WHEN p.typ IN ('training','vorbereitung')
                                         THEN p.menge ELSE 0 END),0) AS stunden,
                       COUNT(p.id) AS anzahl
                  FROM " . self::tbl( 'lsv07a_abrechnung' ) . " a
                  JOIN " . self::tbl( 'lsv07a_posten' ) . " p ON p.abrechnung_id = a.id
                 WHERE a.jahr = %d";
        $werte = [ (int) $jahr ];
        if ( $uid ) { $sql .= " AND a.wp_user_id = %d"; $werte[] = (int) $uid; }
        $sql .= " GROUP BY p.mannschaft_id";
        $zeilen = $wpdb->get_results( $wpdb->prepare( $sql, $werte ), ARRAY_A ) ?: [];

        $namen = [];
        foreach ( LSV07A_Intern::mannschaften() as $m ) $namen[ (int) $m['id'] ] = $m['name'];

        $out = [];
        foreach ( $zeilen as $z ) {
            $mid = (int) $z['mannschaft_id'];
            $out[] = [
                'mannschaft_id' => $mid,
                'name'          => $mid === 0
                    ? 'Ohne Mannschaft (Fahrten, Wettkämpfe, Sonstiges)'
                    : ( $namen[ $mid ] ?? 'Mannschaft ' . $mid . ' (gelöscht)' ),
                'betrag'        => round( (float) $z['betrag'], 2 ),
                'stunden'       => round( (float) $z['stunden'], 2 ),
                'anzahl'        => (int) $z['anzahl'],
            ];
        }
        usort( $out, fn( $a, $b ) => $b['betrag'] <=> $a['betrag'] );
        return $out;
    }

    private static function jahr_aus_post() {
        $jahr = (int) ( $_POST['jahr'] ?? date( 'Y' ) );
        if ( ! LSV07A_Berechnung::jahr_gueltig( $jahr ) ) {
            wp_send_json_error( [ 'message' => 'Unmögliches Jahr.' ] );
        }
        return $jahr;
    }

    /** Die eigenen Zahlen eines Jahres, nach Quartal und nach Art. */
    public static function eigene() {
        LSV07A_Access::check( 'trainer' );
        wp_send_json_success( self::fuer_person( get_current_user_id(), self::jahr_aus_post() ) );
    }

    private static function fuer_person( $uid, $jahr ) {
        global $wpdb;
        $zeilen = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.quartal, a.status, p.typ, COALESCE(SUM(p.betrag),0) AS betrag,
                    COALESCE(SUM(p.menge),0) AS menge, COUNT(p.id) AS anzahl
               FROM " . self::tbl( 'lsv07a_abrechnung' ) . " a
          LEFT JOIN " . self::tbl( 'lsv07a_posten' ) . " p ON p.abrechnung_id = a.id
              WHERE a.wp_user_id = %d AND a.jahr = %d
           GROUP BY a.quartal, a.status, p.typ", $uid, $jahr ), ARRAY_A ) ?: [];

        $quartale = [];
        foreach ( [ 'Q1','Q2','Q3','Q4' ] as $q ) {
            $quartale[ $q ] = [ 'quartal' => $q, 'status' => 'offen', 'status_name' => 'Noch nichts erfasst',
                                'gesamt' => 0.0, 'nach_typ' => array_fill_keys( LSV07A_DB::POSTEN_TYPEN, 0.0 ) ];
        }
        $nach_typ = array_fill_keys( LSV07A_DB::POSTEN_TYPEN, 0.0 );
        $stunden  = 0.0; $gesamt = 0.0;

        foreach ( $zeilen as $z ) {
            $q = $z['quartal'];
            if ( ! isset( $quartale[ $q ] ) ) continue;
            $quartale[ $q ]['status']      = $z['status'];
            $quartale[ $q ]['status_name'] = LSV07A_Berechnung::status_name( $z['status'] );
            if ( ! $z['typ'] ) continue;
            $betrag = (float) $z['betrag'];
            $quartale[ $q ]['gesamt'] += $betrag;
            if ( isset( $quartale[ $q ]['nach_typ'][ $z['typ'] ] ) ) {
                $quartale[ $q ]['nach_typ'][ $z['typ'] ] += $betrag;
                $nach_typ[ $z['typ'] ] += $betrag;
            }
            if ( in_array( $z['typ'], [ 'training', 'vorbereitung' ], true ) ) $stunden += (float) $z['menge'];
            $gesamt += $betrag;
        }
        foreach ( $quartale as &$q ) {
            $q['gesamt'] = round( $q['gesamt'], 2 );
            foreach ( $q['nach_typ'] as $t => $v ) $q['nach_typ'][ $t ] = round( $v, 2 );
        }
        /* Ohne dieses unset bleibt $q eine Referenz auf das letzte
           Quartal. Wer die Variable danach weiterbenutzt — und sei es
           nur als Schleifenvariable — überschreibt damit still den
           letzten Eintrag. */
        unset( $q );
        foreach ( $nach_typ as $t => $v ) $nach_typ[ $t ] = round( $v, 2 );

        $u = get_userdata( $uid );
        $je_quartal = [];
        foreach ( $quartale as $name => $daten ) $je_quartal[ $name ] = $daten['gesamt'];

        return [
            'jahr'     => $jahr,
            'name'     => $u ? $u->display_name : ( 'Konto ' . $uid ),
            'quartale' => array_values( $quartale ),
            'nach_typ' => $nach_typ,
            'gesamt'   => round( $gesamt, 2 ),
            'stunden'  => round( $stunden, 2 ),
            'vorjahr'  => [
                'jahr'     => $jahr - 1,
                'gesamt'   => self::jahressumme( $jahr - 1, $uid ),
                'quartale' => self::quartalssummen( $jahr - 1, $uid ),
            ],
            'hochrechnung'    => self::hochrechnung( $jahr, $je_quartal ),
            'nach_mannschaft' => self::nach_mannschaft( $jahr, $uid ),
            'typ_namen'=> array_map( [ 'LSV07A_Berechnung', 'typ_name' ], array_combine(
                            LSV07A_DB::POSTEN_TYPEN, LSV07A_DB::POSTEN_TYPEN ) ),
        ];
    }

    /** Alle Trainer eines Jahres — für Wart, Kasse und Administration. */
    public static function alle() {
        LSV07A_Access::check( 'statistik' );
        if ( ! self::sieht_alle() ) {
            wp_send_json_error( [ 'message' => 'Die Zahlen aller Trainer sehen nur Wart, Kasse und Administration. '
                . 'Ihre eigenen finden Sie unter „Meine Statistik".' ], 403 );
        }
        global $wpdb;
        $jahr = self::jahr_aus_post();

        $zeilen = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.wp_user_id, a.quartal, a.status, p.typ,
                    COALESCE(SUM(p.betrag),0) AS betrag, COALESCE(SUM(p.menge),0) AS menge
               FROM " . self::tbl( 'lsv07a_abrechnung' ) . " a
          LEFT JOIN " . self::tbl( 'lsv07a_posten' ) . " p ON p.abrechnung_id = a.id
              WHERE a.jahr = %d
           GROUP BY a.wp_user_id, a.quartal, a.status, p.typ", $jahr ), ARRAY_A ) ?: [];

        $personen = [];
        $nach_typ = array_fill_keys( LSV07A_DB::POSTEN_TYPEN, 0.0 );
        $nach_status = array_fill_keys( LSV07A_DB::STATUS, 0.0 );
        $gesamt = 0.0;

        foreach ( $zeilen as $z ) {
            $uid = (int) $z['wp_user_id'];
            if ( ! isset( $personen[ $uid ] ) ) {
                $u = get_userdata( $uid );
                $personen[ $uid ] = [
                    'wp_user_id' => $uid,
                    'name'       => $u ? $u->display_name : ( 'Konto ' . $uid ),
                    'gesamt'     => 0.0, 'stunden' => 0.0,
                    'nach_typ'   => array_fill_keys( LSV07A_DB::POSTEN_TYPEN, 0.0 ),
                    'quartale'   => [],
                ];
            }
            $personen[ $uid ]['quartale'][ $z['quartal'] ] = LSV07A_Berechnung::status_name( $z['status'] );
            if ( ! $z['typ'] ) continue;
            $betrag = (float) $z['betrag'];
            $personen[ $uid ]['gesamt'] += $betrag;
            if ( isset( $personen[ $uid ]['nach_typ'][ $z['typ'] ] ) ) {
                $personen[ $uid ]['nach_typ'][ $z['typ'] ] += $betrag;
                $nach_typ[ $z['typ'] ] += $betrag;
            }
            if ( in_array( $z['typ'], [ 'training', 'vorbereitung' ], true ) ) {
                $personen[ $uid ]['stunden'] += (float) $z['menge'];
            }
            if ( isset( $nach_status[ $z['status'] ] ) ) $nach_status[ $z['status'] ] += $betrag;
            $gesamt += $betrag;
        }

        foreach ( $personen as &$p ) {
            $p['gesamt']  = round( $p['gesamt'], 2 );
            $p['stunden'] = round( $p['stunden'], 2 );
            foreach ( $p['nach_typ'] as $t => $v ) $p['nach_typ'][ $t ] = round( $v, 2 );
        }
        unset( $p );
        $liste = array_values( $personen );
        usort( $liste, fn( $a, $b ) => $b['gesamt'] <=> $a['gesamt'] );
        foreach ( $nach_typ as $t => $v ) $nach_typ[ $t ] = round( $v, 2 );
        foreach ( $nach_status as $s => $v ) $nach_status[ $s ] = round( $v, 2 );

        $je_quartal = self::quartalssummen( $jahr );

        wp_send_json_success( [
            'jahr'        => $jahr,
            'personen'    => $liste,
            'nach_typ'    => $nach_typ,
            'nach_status' => $nach_status,
            'gesamt'      => round( $gesamt, 2 ),
            'quartale'    => $je_quartal,
            'vorjahr'     => [
                'jahr'     => $jahr - 1,
                'gesamt'   => self::jahressumme( $jahr - 1 ),
                'quartale' => self::quartalssummen( $jahr - 1 ),
            ],
            'hochrechnung'    => self::hochrechnung( $jahr, $je_quartal ),
            'nach_mannschaft' => self::nach_mannschaft( $jahr ),
            'typ_namen'   => array_map( [ 'LSV07A_Berechnung', 'typ_name' ], array_combine(
                               LSV07A_DB::POSTEN_TYPEN, LSV07A_DB::POSTEN_TYPEN ) ),
            'status_namen'=> array_map( [ 'LSV07A_Berechnung', 'status_name' ], array_combine(
                               LSV07A_DB::STATUS, LSV07A_DB::STATUS ) ),
        ] );
    }
}
