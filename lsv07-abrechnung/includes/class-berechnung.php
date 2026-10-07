<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Was eine Abrechnung wert ist.
 *
 * Eine Stelle, an der gerechnet wird — damit Trainerin, Wart und Kasse
 * garantiert dieselbe Zahl sehen. Die Posten liegen fertig gerechnet in der
 * Datenbank (betrag je Zeile); hier entstehen sie und hier werden sie
 * zusammengezählt.
 */
class LSV07A_Berechnung {

    /** Quartal eines Datums und die Grenzen eines Quartals. */
    public static function quartal_von_datum( $datum ) {
        $m = (int) date( 'n', strtotime( $datum ) );
        return 'Q' . (int) ceil( $m / 3 );
    }

    public static function zeitraum( $quartal, $jahr ) {
        $q = max( 1, min( 4, (int) substr( (string) $quartal, 1 ) ) );
        $jahr = (int) $jahr;
        $von  = sprintf( '%04d-%02d-01', $jahr, ( $q - 1 ) * 3 + 1 );
        $bis  = date( 'Y-m-t', strtotime( sprintf( '%04d-%02d-01', $jahr, ( $q - 1 ) * 3 + 3 ) ) );
        return [ $von, $bis ];
    }

    public static function quartal_gueltig( $q ) { return in_array( $q, [ 'Q1','Q2','Q3','Q4' ], true ); }
    public static function jahr_gueltig( $j )    { return $j >= 2020 && $j <= 2100; }

    /** „1. Quartal (Jan–Mär)" — ausgeschrieben, wie in der Oberfläche. */
    public static function quartal_name( $q ) {
        return [
            'Q1' => '1. Quartal (Jan–Mär)', 'Q2' => '2. Quartal (Apr–Jun)',
            'Q3' => '3. Quartal (Jul–Sep)', 'Q4' => '4. Quartal (Okt–Dez)',
        ][ $q ] ?? (string) $q;
    }

    /** Das Quartal VOR dem eines Datums, mit dem zugehörigen Jahr. */
    public static function vorquartal( $datum ) {
        $zeit = strtotime( $datum );
        $q    = (int) ceil( (int) date( 'n', $zeit ) / 3 );
        $jahr = (int) date( 'Y', $zeit );
        if ( --$q < 1 ) { $q = 4; $jahr--; }
        return [ 'Q' . $q, $jahr ];
    }

    // ── Einzelne Posten rechnen ──────────────────────────────────────────

    /**
     * Ein Training. Welcher der drei Wege gilt, hängt am Konto:
     *   zeiten    — Stunden aus der Trainingszeit × Stundensatz
     *   pauschale — fester Betrag je Mannschaft, Stunden spielen keine Rolle
     *   manuell   — die Person trägt die Stunden selbst ein × Stundensatz
     * Die zuschaltbare Wartezeit kommt in allen drei Fällen als
     * Zeitaufschlag obendrauf (Minuten stellt der Administrator ein).
     */
    public static function training( $art, $stunden, $stundensatz, $wartezeit, $mannschaft_id, $pauschalen, $wartezeit_min ) {
        $stunden     = max( 0, (float) $stunden );
        $stundensatz = max( 0, (float) $stundensatz );
        $zuschlag_h  = $wartezeit ? round( max( 0, (int) $wartezeit_min ) / 60, 4 ) : 0.0;

        if ( $art === 'pauschale' ) {
            $grund  = isset( $pauschalen[ (int) $mannschaft_id ] ) ? (float) $pauschalen[ (int) $mannschaft_id ] : 0.0;
            $betrag = $grund + $zuschlag_h * $stundensatz;
            return [
                'menge'  => 1,
                'satz'   => round( $grund, 2 ),
                'betrag' => round( $betrag, 2 ),
            ];
        }

        $menge = round( $stunden + $zuschlag_h, 2 );
        return [
            'menge'  => $menge,
            'satz'   => round( $stundensatz, 2 ),
            'betrag' => round( $menge * $stundensatz, 2 ),
        ];
    }

    public static function wettkampf( $abschnitte, $satz ) {
        $abschnitte = max( 0, (int) $abschnitte );
        $satz       = max( 0, (float) $satz );
        return [ 'menge' => $abschnitte, 'satz' => round( $satz, 2 ),
                 'betrag' => round( $abschnitte * $satz, 2 ) ];
    }

    /**
     * Fahrtkosten. Erfasst wird die EINFACHE Strecke; abgerechnet wird erst
     * ab der eingestellten Mindeststrecke (Vorgabe: mehr als 20 km). Ob Hin-
     * und Rückfahrt zählen, stellt der Administrator ein (Vorgabe: ja).
     * Liegt die Strecke darunter, ist der Betrag 0 — die Zeile bleibt
     * sichtbar, damit erkennbar ist, warum nichts herauskommt.
     */
    public static function fahrt( $km_einfach, $tage, $satz, $mindest, $hin_rueck ) {
        $km     = max( 0, (float) $km_einfach );
        $tage   = max( 1, (int) $tage );
        $satz   = max( 0, (float) $satz );
        $mindest = max( 0, (float) $mindest );

        if ( $km <= $mindest ) {
            return [ 'menge' => $km, 'satz' => round( $satz, 2 ), 'betrag' => 0.00, 'unter_mindest' => true ];
        }
        $gefahren = $km * ( $hin_rueck ? 2 : 1 ) * $tage;
        return [ 'menge' => $km, 'satz' => round( $satz, 2 ),
                 'betrag' => round( $gefahren * $satz, 2 ), 'unter_mindest' => false,
                 'km_gesamt' => round( $gefahren, 2 ) ];
    }

    public static function vorbereitung( $stunden, $stundensatz ) {
        $stunden = max( 0, (float) $stunden );
        $satz    = max( 0, (float) $stundensatz );
        return [ 'menge' => round( $stunden, 2 ), 'satz' => round( $satz, 2 ),
                 'betrag' => round( $stunden * $satz, 2 ) ];
    }

    public static function sonstiges( $betrag ) {
        return [ 'menge' => 1, 'satz' => 0, 'betrag' => round( (float) $betrag, 2 ) ];
    }

    // ── Eine ganze Abrechnung ────────────────────────────────────────────

    public static function pauschalen() {
        global $wpdb;
        $zeilen = $wpdb->get_results(
            "SELECT mannschaft_id, betrag FROM {$wpdb->prefix}lsv07a_pauschale", ARRAY_A ) ?: [];
        $out = [];
        foreach ( $zeilen as $z ) $out[ (int) $z['mannschaft_id'] ] = (float) $z['betrag'];
        return $out;
    }

    /** Alle Posten einer Abrechnung, nach Art gebündelt und aufsummiert. */
    public static function summe( $abrechnung_id ) {
        global $wpdb;
        $posten = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}lsv07a_posten
              WHERE abrechnung_id = %d ORDER BY typ ASC, datum ASC, id ASC",
            (int) $abrechnung_id ), ARRAY_A ) ?: [];

        $gruppen = [];
        $summen  = [];
        $gesamt  = 0.0;
        foreach ( LSV07A_DB::POSTEN_TYPEN as $t ) { $gruppen[ $t ] = []; $summen[ $t ] = 0.0; }

        foreach ( $posten as $p ) {
            $typ = isset( $gruppen[ $p['typ'] ] ) ? $p['typ'] : 'sonstiges';
            $p['menge']  = (float) $p['menge'];
            $p['satz']   = (float) $p['satz'];
            $p['betrag'] = (float) $p['betrag'];
            $p['id']     = (int) $p['id'];
            $p['wartezeit'] = (int) $p['wartezeit'];
            $p['tage']   = (int) $p['tage'];
            $p['mannschaft_id'] = (int) ( $p['mannschaft_id'] ?? 0 );
            $gruppen[ $typ ][] = $p;
            $summen[ $typ ] += $p['betrag'];
            $gesamt += $p['betrag'];
        }
        foreach ( $summen as $t => $s ) $summen[ $t ] = round( $s, 2 );

        return [ 'posten' => $gruppen, 'summen' => $summen, 'gesamt' => round( $gesamt, 2 ),
                 'anzahl' => count( $posten ) ];
    }

    /** Lesbare Beschriftungen — einmal zentral, damit sie überall gleich sind. */
    public static function typ_name( $typ ) {
        return [
            'training'     => 'Training',
            'wettkampf'    => 'Wettkämpfe',
            'fahrt'        => 'Fahrtkosten',
            'vorbereitung' => 'Vorbereitung',
            'sonstiges'    => 'Sonstiges',
        ][ $typ ] ?? $typ;
    }

    public static function status_name( $status ) {
        return [
            'entwurf'     => 'Entwurf',
            'eingereicht' => 'Eingereicht',
            'zurueck'     => 'Zurückgegeben',
            'genehmigt'   => 'Genehmigt',
            'bezahlt'     => 'Bezahlt',
        ][ $status ] ?? $status;
    }

    public static function art_name( $art ) {
        return [
            'zeiten'    => 'Trainingszeiten',
            'pauschale' => 'Pauschalbeträge',
            'manuell'   => 'Manuelle Stundeneingabe',
        ][ $art ] ?? $art;
    }

    /**
     * Eine Zahl für den Fließtext: 20 bleibt "20", 0,50 wird "0,5",
     * 1,25 bleibt "1,25".
     * Achtung — ein naives rtrim('0') macht aus "20" eine "2", weil es
     * ohne Komma die Stelle der Zehner abschneidet. Deshalb wird erst auf
     * Nachkommastellen gebracht und dann gekürzt.
     */
    public static function zahl_kurz( $wert ) {
        $s = number_format( (float) $wert, 2, ',', '' );
        $s = rtrim( $s, '0' );
        return rtrim( $s, ',' );
    }

    /** Darf an dieser Abrechnung noch etwas geändert werden? */
    public static function offen( $status ) {
        return in_array( $status, [ 'entwurf', 'zurueck' ], true );
    }
}
