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
        foreach ( $nach_typ as $t => $v ) $nach_typ[ $t ] = round( $v, 2 );

        $u = get_userdata( $uid );
        return [
            'jahr'     => $jahr,
            'name'     => $u ? $u->display_name : ( 'Konto ' . $uid ),
            'quartale' => array_values( $quartale ),
            'nach_typ' => $nach_typ,
            'gesamt'   => round( $gesamt, 2 ),
            'stunden'  => round( $stunden, 2 ),
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

        wp_send_json_success( [
            'jahr'        => $jahr,
            'personen'    => $liste,
            'nach_typ'    => $nach_typ,
            'nach_status' => $nach_status,
            'gesamt'      => round( $gesamt, 2 ),
            'typ_namen'   => array_map( [ 'LSV07A_Berechnung', 'typ_name' ], array_combine(
                               LSV07A_DB::POSTEN_TYPEN, LSV07A_DB::POSTEN_TYPEN ) ),
            'status_namen'=> array_map( [ 'LSV07A_Berechnung', 'status_name' ], array_combine(
                               LSV07A_DB::STATUS, LSV07A_DB::STATUS ) ),
        ] );
    }
}
