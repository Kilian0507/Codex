<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * „Was ist für mich zu tun" — die Startseite, die etwas sagt.
 *
 * Die Startseite des internen Bereichs zeigt die letzten fünf Trainings,
 * die fünf schnellsten Schwimmer und drei Infokacheln. Nichts davon
 * verlangt eine Handlung. Was eine verlangt — eine Abrechnung, die
 * eingereicht werden muss, ein Attest, das abläuft, ein Training ohne
 * Anwesenheit, eine offene Anfrage — steht nirgends, und man findet es nur,
 * wenn man danach sucht.
 *
 * Hier zählt jede Aufgabe genau einmal, mit dem Weg dorthin. Jede Zeile
 * prüft dabei das Recht, das die zugehörige Ansicht verlangt: Wer die
 * Atteste nicht sehen darf, bekommt auch nicht gesagt, wie viele ablaufen.
 */
class LSV07S_Aufgaben {

    /** Wie viele Tage im Voraus ein ablaufendes Attest gemeldet wird. */
    private static function attest_tage() {
        $t = (int) LSV07S_Tabellen::config( 'attest_warnung_tage', 30 );
        return $t > 0 ? $t : 30;
    }

    public static function alle( $uid = 0 ) {
        $uid  = (int) ( $uid ?: get_current_user_id() );
        $raus = [];
        foreach ( [ 'eigene_abrechnung', 'abrechnung_pruefen', 'abrechnung_zahlen',
                    'atteste', 'anwesenheit_fehlt', 'buch_aufgaben',
                    'anfragen', 'nachrichten' ] as $was ) {
            $e = self::$was( $uid );
            if ( $e && $e['zahl'] > 0 ) $raus[] = $e;
        }
        /* Dringendes zuerst, dann nach Menge. Wer zehn Dinge zu tun hat,
           soll nicht raten müssen, welches zuerst. */
        usort( $raus, function ( $a, $b ) {
            $r = [ 'hoch' => 0, 'mittel' => 1, 'normal' => 2 ];
            $x = $r[ $a['dringend'] ] ?? 2;
            $y = $r[ $b['dringend'] ] ?? 2;
            if ( $x !== $y ) return $x - $y;
            return $b['zahl'] - $a['zahl'];
        } );
        return $raus;
    }

    private static function eintrag( $zahl, $titel, $text, $ziel, $dringend = 'normal' ) {
        return [ 'zahl' => (int) $zahl, 'titel' => $titel, 'text' => $text,
                 'ziel' => $ziel, 'dringend' => $dringend ];
    }

    /** Die eigene Abrechnung des zuletzt abgelaufenen Quartals. */
    private static function eigene_abrechnung( $uid ) {
        global $wpdb;
        if ( ! LSV07S_Rechte::darf( LSV07S_Rechte::ABRECHNUNG_EIGEN_READ, $uid ) ) return null;
        if ( ! LSV07S_Tabellen::da( 'abrechnung' ) || ! LSV07S_Tabellen::da( 'trainer' ) ) return null;

        $trainer_id = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM ' . LSV07S_Tabellen::t( 'trainer' ) . ' WHERE wp_user_id = %d LIMIT 1', $uid ) );
        if ( ! $trainer_id ) return null;

        /* Das Quartal, das gerade zu Ende ist — nicht das laufende. Für das
           laufende ist es noch nicht an der Zeit. */
        $vorher  = date( 'Y-m-d', strtotime( LSV07S_Datum::heute() . ' -1 month' ) );
        $quartal = LSV07S_Datum::quartal( $vorher );
        $jahr    = LSV07S_Datum::jahr( $vorher );
        if ( LSV07S_Datum::quartal() === $quartal ) {
            /* Wir stehen noch im selben Quartal wie vor einem Monat; dann
               ist das Quartal davor gemeint. */
            [ $start ] = LSV07S_Datum::quartal_spanne( $quartal, $jahr );
            $vorher  = date( 'Y-m-d', strtotime( $start . ' -1 day' ) );
            $quartal = LSV07S_Datum::quartal( $vorher );
            $jahr    = LSV07S_Datum::jahr( $vorher );
        }

        $zeile = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'abrechnung' )
            . ' WHERE trainer_id = %d AND quartal = %s AND jahr = %d LIMIT 1',
            $trainer_id, $quartal, $jahr ), ARRAY_A );

        $stand = $zeile ? (string) $zeile['abr_status'] : 'entwurf';
        if ( ! in_array( $stand, [ 'entwurf', 'zurueck', 'zurueckgegeben' ], true ) ) return null;

        $zurueck = $stand !== 'entwurf';
        return self::eintrag( 1,
            $zurueck ? 'Abrechnung zurückgegeben' : 'Abrechnung noch nicht eingereicht',
            LSV07S_Datum::quartal_name( $quartal, $jahr )
            . ( $zurueck ? ' — bitte ansehen und erneut einreichen.' : ' — bitte prüfen und einreichen.' ),
            '#abrechnung/eigene',
            $zurueck ? 'hoch' : 'mittel' );
    }

    /** Eingereichte Abrechnungen, die auf eine Entscheidung warten. */
    private static function abrechnung_pruefen( $uid ) {
        global $wpdb;
        if ( ! LSV07S_Rechte::darf( LSV07S_Rechte::ABRECHNUNG_WART_READ, $uid ) ) return null;
        if ( ! LSV07S_Tabellen::da( 'abrechnung' ) ) return null;
        $n = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . LSV07S_Tabellen::t( 'abrechnung' )
            . " WHERE abr_status = 'eingereicht'" );
        return self::eintrag( $n, 'Abrechnungen warten auf Prüfung',
            $n === 1 ? 'Eine Abrechnung ist eingereicht.' : $n . ' Abrechnungen sind eingereicht.',
            '#abrechnung/pruefen', 'mittel' );
    }

    /** Genehmigte Abrechnungen, die noch nicht bezahlt sind. */
    private static function abrechnung_zahlen( $uid ) {
        global $wpdb;
        if ( ! LSV07S_Rechte::darf( LSV07S_Rechte::ABRECHNUNG_KASSE_READ, $uid ) ) return null;
        if ( ! LSV07S_Tabellen::da( 'abrechnung' ) ) return null;
        $n = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . LSV07S_Tabellen::t( 'abrechnung' )
            . " WHERE abr_status = 'genehmigt' AND bezahlt = 0" );
        return self::eintrag( $n, 'Abrechnungen warten auf Zahlung',
            $n === 1 ? 'Eine genehmigte Abrechnung ist offen.'
                     : $n . ' genehmigte Abrechnungen sind offen.',
            '#abrechnung/kasse', 'mittel' );
    }

    /**
     * Atteste, die abgelaufen sind oder ablaufen.
     *
     * Zwei Zahlen in einer Zeile, weil „abgelaufen" dringend ist und
     * „läuft ab" nicht. Im internen Bereich gilt ein Attest am Ablauftag
     * schon als abgelaufen (`strtotime(Datum) < time()`); hier gilt es an
     * diesem Tag noch — es steht ein Datum darauf, und an dem Tag ist es
     * gültig.
     */
    private static function atteste( $uid ) {
        global $wpdb;
        if ( ! LSV07S_Rechte::darf_eines( [ LSV07S_Rechte::ATTEST_READ,
                                            LSV07S_Rechte::ATTEST_EDIT ], $uid ) ) return null;
        if ( ! LSV07S_Tabellen::da( 'schwimmer' ) ) return null;

        $mannschaften = LSV07S_Mannschaft::erlaubte( LSV07S_Sparte::SCHWIMMEN, $uid );
        if ( $mannschaften === [] ) return null;
        $ids = array_column( $mannschaften, 'id' );
        $platz = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $heute = LSV07S_Datum::heute();
        $frist = date( 'Y-m-d', strtotime( $heute . ' +' . self::attest_tage() . ' days' ) );
        $sql = 'SELECT s.attest_expires FROM ' . LSV07S_Tabellen::t( 'schwimmer' ) . ' s'
             . ' INNER JOIN ' . LSV07S_Tabellen::t( 'schwimmer_mann' ) . ' z ON z.swimmer_id = s.id'
             . " WHERE s.active = 1 AND z.gruppe_id IN ($platz)"
             . ' AND s.attest_expires IS NOT NULL AND s.attest_expires <= %s'
             . ' GROUP BY s.id, s.attest_expires';
        $zeilen = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( $ids, [ $frist ] ) ) );

        $ab = 0; $bald = 0;
        foreach ( (array) $zeilen as $datum ) {
            if ( (string) $datum < $heute ) $ab++; else $bald++;
        }
        $n = $ab + $bald;
        if ( $n === 0 ) return null;

        $text = [];
        if ( $ab )   $text[] = $ab . ' abgelaufen';
        if ( $bald ) $text[] = $bald . ' läuft in den nächsten ' . self::attest_tage() . ' Tagen ab';
        return self::eintrag( $n, 'Atteste', implode( ', ', $text ) . '.',
            '#schwimmen/atteste', $ab > 0 ? 'hoch' : 'normal' );
    }

    /**
     * Trainings der letzten zwei Wochen ohne erfasste Anwesenheit.
     *
     * Über alle drei Sparten, in denen das Konto Trainer ist. Der interne
     * Bereich mahnt das per Mail, aber nur für Schwimmen und nur per Cron
     * — wer die Mail löscht, erfährt es nicht mehr.
     */
    private static function anwesenheit_fehlt( $uid ) {
        $offen = 0;
        $erster = '';
        foreach ( LSV07S_Sparte::alle() as $sparte ) {
            if ( ! LSV07S_Sparte::da( $sparte ) ) continue;
            if ( ! LSV07S_Rechte::darf_eines( [ LSV07S_Sparte::recht( $sparte, 'anw.update' ),
                                                LSV07S_Rechte::MANNSCHAFTSBUCH_EDIT ], $uid ) ) continue;
            foreach ( LSV07S_Mannschaft::erlaubte( $sparte, $uid ) as $mann ) {
                if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, $mann['id'], $uid ) ) continue;
                for ( $i = 1; $i <= 14; $i++ ) {
                    $d = date( 'Y-m-d', strtotime( LSV07S_Datum::heute() . ' -' . $i . ' days' ) );
                    $grenze = LSV07S_Datum::grenze( $uid );
                    if ( $grenze !== null && $d < $grenze ) continue;
                    $slot = LSV07S_Anwesenheit::slot_fuer( $sparte, $mann['id'], $d );
                    if ( ! $slot ) continue;
                    if ( LSV07S_Anwesenheit::ausfall_stand( $sparte, $mann['id'], $d, $slot )['ausgefallen'] ) {
                        continue;
                    }
                    if ( LSV07S_Anwesenheit::stand( $sparte, $mann['id'], $d ) !== [] ) continue;
                    $offen++;
                    if ( $erster === '' ) {
                        $erster = '#' . $sparte . '/anwesenheit/' . $mann['id'] . '/' . $d;
                    }
                }
            }
        }
        if ( $offen === 0 ) return null;
        return self::eintrag( $offen, 'Trainings ohne Anwesenheit',
            $offen === 1 ? 'Ein Training der letzten zwei Wochen ist nicht erfasst.'
                         : $offen . ' Trainings der letzten zwei Wochen sind nicht erfasst.',
            $erster, 'mittel' );
    }

    /** Offene Aufgaben aus dem Mannschaftsbuch. */
    private static function buch_aufgaben( $uid ) {
        global $wpdb;
        if ( ! LSV07S_Rechte::darf_eines( [ LSV07S_Rechte::MANNSCHAFTSBUCH_READ,
                                            LSV07S_Rechte::MANNSCHAFTSBUCH_EDIT ], $uid ) ) return null;
        if ( ! LSV07S_Tabellen::da( 'mb_eintrag' ) ) return null;
        $mannschaften = LSV07S_Mannschaft::erlaubte( LSV07S_Sparte::SCHWIMMEN, $uid );
        if ( $mannschaften === [] ) return null;
        $ids   = array_column( $mannschaften, 'id' );
        $platz = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $n = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . LSV07S_Tabellen::t( 'mb_eintrag' )
            . " WHERE art = 'aufgabe' AND erledigt = 0 AND mannschaft_id IN ($platz)", $ids ) );
        return self::eintrag( $n, 'Offene Aufgaben im Mannschaftsbuch',
            $n === 1 ? 'Eine Aufgabe ist noch offen.' : $n . ' Aufgaben sind noch offen.',
            '#schwimmen/anwesenheit', 'normal' );
    }

    /** Offene Anfragen: eigene und, mit Recht, fremde. */
    private static function anfragen( $uid ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'tickets' ) ) return null;
        $t = LSV07S_Tabellen::t( 'tickets' );
        if ( LSV07S_Rechte::darf( LSV07S_Rechte::TICKETS_READ_ALL, $uid ) ) {
            $n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status = 'offen'" );
            $titel = 'Offene Anfragen';
        } else {
            $n = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $t WHERE status = 'offen' AND ersteller_user_id = %d", $uid ) );
            $titel = 'Meine offenen Anfragen';
        }
        return self::eintrag( $n, $titel,
            $n === 1 ? 'Eine Anfrage ist offen.' : $n . ' Anfragen sind offen.',
            '#reden/tickets', 'normal' );
    }

    /** Ungelesene Nachrichten. */
    private static function nachrichten( $uid ) {
        global $wpdb;
        if ( ! LSV07S_Rechte::darf( LSV07S_Rechte::NACHRICHTEN_READ, $uid ) ) return null;
        if ( ! LSV07S_Tabellen::da( 'konv_teil' ) || ! LSV07S_Tabellen::da( 'konv_msg' ) ) return null;
        $teil = LSV07S_Tabellen::t( 'konv_teil' );
        $msg  = LSV07S_Tabellen::t( 'konv_msg' );
        /* `gelesen_bis` ist die Nummer der letzten gelesenen Nachricht,
           kein Zeitpunkt — verglichen wird deshalb die Nummer. Eigene
           Nachrichten zählen nicht, gelöschte auch nicht, und eine
           stummgeschaltete oder verlassene Unterhaltung bleibt aussen vor:
           wer sie stumm geschaltet hat, will nicht erinnert werden. */
        $stumm = LSV07S_Mannschaft::spalte_da( $teil, 'stumm' ) ? ' AND t.stumm = 0' : '';
        $n = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $msg m
               INNER JOIN $teil t ON t.konv_id = m.konv_id
              WHERE t.teilnehmer_typ = 'user' AND t.teilnehmer_id = %d $stumm
                AND t.verlassen_am IS NULL
                AND m.id > t.gelesen_bis
                AND m.geloescht_am IS NULL
                AND NOT ( m.von_typ = 'user' AND m.von_id = %d )", $uid, $uid ) );
        return self::eintrag( $n, 'Ungelesene Nachrichten',
            $n === 1 ? 'Eine Nachricht ist ungelesen.' : $n . ' Nachrichten sind ungelesen.',
            '#reden/nachrichten', 'normal' );
    }
}
