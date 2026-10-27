<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Wer darf einen Vorgang löschen — und was passiert dann?
 *
 * Löschen ist der einzige Vorgang, der sich nicht zurücknehmen lässt. Darum
 * steht die Antwort darauf an genau EINER Stelle, und Prüfung, Kasse und
 * Verwaltung fragen sie alle. Zwei Listen von „wer darf" laufen
 * auseinander, und bei einer Löschung merkt man es daran, dass etwas weg
 * ist, das nicht weg sein sollte.
 *
 * **Was das mit der Aufbewahrungspflicht macht, muss offen dastehen.** Eine
 * eingereichte, genehmigte oder bezahlte Abrechnung ist ein Buchungsbeleg:
 * § 147 AO und § 257 HGB verlangen zehn Jahre Aufbewahrung. Dieses Plugin
 * macht das Löschen trotzdem möglich — es gibt echte Gründe dafür
 * (Doppelerfassung, Testeinträge, ein Vorgang am falschen Konto), und ohne
 * den Knopf bliebe nur der Weg über die Datenbank, der niemandem hilft und
 * nichts protokolliert. Die Verantwortung dafür liegt beim Verein, und
 * damit sie wahrgenommen werden kann:
 *
 *  * **Eine Begründung ist Pflicht.** Ohne sie geht es nicht weiter.
 *  * **Vorher wird protokolliert**, was gelöscht wird: Konto, Zeitraum oder
 *    Betreff, Betrag und Stand. Die Zeile verschwindet, die Spur bleibt.
 *  * **Die betroffene Person bekommt eine Mitteilung** — mit dem Grund. Wem
 *    etwas gelöscht wird, der soll es nicht daran merken, dass es fehlt.
 *  * **Die Oberfläche warnt**, sobald der Vorgang über „Entwurf" hinaus ist.
 *
 * Wer darf:
 *
 * | | Quartalsabrechnung | Rechnung |
 * |---|---|---|
 * | Administration | immer | immer |
 * | Wart | im eigenen Zuständigkeitsbereich | — |
 * | Abteilungsleitung | — | in der eigenen Abteilung |
 * | Kasse | ab genehmigt | ab genehmigt |
 * | die Person selbst | Entwurf | Entwurf und zurückgegeben |
 *
 * Die Kasse bekommt erst ab „genehmigt" etwas zu sehen; davor gibt es für
 * sie nichts zu löschen, weil es für sie nichts gibt.
 */
class LSV07A_Loeschung {

    /** Ab hier sieht die Kasse einen Vorgang überhaupt. */
    const KASSE_SICHT = [ 'genehmigt', 'bezahlt' ];

    /** Was die Person selbst noch löschen darf. */
    const SELBST = [ 'entwurf', 'zurueck' ];

    private static function t( $n ) {
        global $wpdb;
        return $wpdb->prefix . $n;
    }

    // ── Darf ich? ────────────────────────────────────────────────────────

    /**
     * Darf diese Person diese Quartalsabrechnung löschen?
     *
     * @return true|string true oder der Grund, warum nicht.
     */
    public static function darf_abrechnung( $abr, $uid = 0 ) {
        if ( ! is_array( $abr ) ) return 'Diese Abrechnung gibt es nicht.';
        $uid    = $uid ?: get_current_user_id();
        $status = (string) ( $abr['status'] ?? '' );

        if ( LSV07A_Rollen::ist_admin( $uid ) ) return true;

        if ( LSV07A_Rollen::ist_kasse( $uid )
             && in_array( $status, self::KASSE_SICHT, true ) ) {
            return true;
        }

        if ( LSV07A_Rollen::ist_wart( $uid )
             && LSV07A_Zustaendig::darf_pruefen( (int) $abr['id'], $uid ) ) {
            return true;
        }

        if ( (int) ( $abr['wp_user_id'] ?? 0 ) === (int) $uid
             && in_array( $status, self::SELBST, true ) ) {
            return true;
        }

        /* Die Kasse soll nicht erfahren, dass es etwas gibt, das sie nicht
           sehen darf — die Begründung nennt darum nur ihre eigene Grenze. */
        if ( LSV07A_Rollen::ist_kasse( $uid ) ) {
            return 'Die Kasse kann nur löschen, was genehmigt oder bezahlt ist.';
        }
        return 'Diese Abrechnung gehört nicht zu Ihrem Bereich.';
    }

    /**
     * Darf diese Person diese Rechnung löschen?
     *
     * @return true|string
     */
    public static function darf_rechnung( $r, $uid = 0 ) {
        if ( ! is_array( $r ) ) return 'Diese Rechnung gibt es nicht.';
        $uid    = $uid ?: get_current_user_id();
        $status = (string) ( $r['status'] ?? '' );

        if ( LSV07A_Rollen::ist_admin( $uid ) ) return true;

        if ( LSV07A_Rollen::ist_kasse( $uid )
             && in_array( $status, self::KASSE_SICHT, true ) ) {
            return true;
        }

        if ( LSV07A_Rechnung::darf_genehmigen( $r, $uid ) ) return true;

        if ( (int) ( $r['wp_user_id'] ?? 0 ) === (int) $uid
             && in_array( $status, self::SELBST, true ) ) {
            return true;
        }

        if ( LSV07A_Rollen::ist_kasse( $uid ) ) {
            return 'Die Kasse kann nur löschen, was genehmigt oder bezahlt ist.';
        }
        return 'Diese Rechnung gehört nicht zu Ihrer Abteilung.';
    }

    /**
     * Muss vor dem Löschen gewarnt werden?
     *
     * Ein Entwurf ist kein Beleg — den löscht man ohne Umstände. Alles ab
     * „eingereicht" ist einer, und dann soll vor dem Klick dastehen, was das
     * bedeutet.
     */
    public static function warnung( $status ) {
        if ( in_array( (string) $status, [ 'entwurf' ], true ) ) return '';
        return 'Dieser Vorgang wurde bereits eingereicht und ist damit ein Buchungsbeleg. '
             . '§ 147 AO und § 257 HGB verlangen zehn Jahre Aufbewahrung. Das Löschen wird '
             . 'protokolliert und der betroffenen Person mitgeteilt.';
    }

    /**
     * Braucht es für diesen Vorgang eine Begründung?
     *
     * Nein, wenn jemand seinen EIGENEN Entwurf wegwirft: Er war nie
     * eingereicht, es gibt nichts zu verantworten, und eine Pflichtabfrage
     * wäre nur im Weg. Ja, sobald der Vorgang eingereicht war oder jemand
     * anderes löscht — dann steht eine fremde oder eine gebuchte Sache auf
     * dem Spiel.
     */
    public static function grund_noetig( $vorgang, $uid = 0 ) {
        $uid = $uid ?: get_current_user_id();
        if ( (int) ( $vorgang['wp_user_id'] ?? 0 ) !== (int) $uid ) return true;
        return (string) ( $vorgang['status'] ?? '' ) !== 'entwurf';
    }

    // ── Löschen ──────────────────────────────────────────────────────────

    /**
     * Eine Quartalsabrechnung löschen, mit allem, was daran hängt.
     *
     * @return true|string
     */
    public static function abrechnung( $id, $grund, $uid = 0 ) {
        global $wpdb;
        $id    = (int) $id;
        $uid   = $uid ?: get_current_user_id();
        $grund = trim( (string) $grund );

        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'lsv07a_abrechnung' ) . " WHERE id = %d", $id ), ARRAY_A );
        if ( ! $abr ) return 'Diese Abrechnung gibt es nicht.';

        $darf = self::darf_abrechnung( $abr, $uid );
        if ( $darf !== true ) return $darf;

        if ( $grund === '' && self::grund_noetig( $abr, $uid ) ) {
            return 'Bitte schreiben Sie dazu, warum gelöscht wird. '
                 . 'Löschen lässt sich nicht zurücknehmen.';
        }

        /* Erst protokollieren, dann löschen: Nach dem DELETE ist nicht mehr
           zu ermitteln, was dort stand. */
        $summe = LSV07A_Berechnung::summe( $id );
        LSV07A_Log::schreibe( 'abrechnung.geloescht', [
            'ziel_typ' => 'abrechnung',
            'ziel_id'  => $id,
            'details'  => sprintf(
                '%s %s von %s, %s EUR, Stand „%s". Grund: %s',
                LSV07A_Berechnung::quartal_name( $abr['quartal'] ), $abr['jahr'],
                LSV07A_Nachricht::name( $abr['wp_user_id'] ),
                number_format( (float) $summe['gesamt'], 2, ',', '.' ),
                LSV07A_Berechnung::status_name( $abr['status'] ),
                $grund !== '' ? $grund : 'eigener Entwurf, keine Begründung nötig' ),
        ] );

        $wpdb->delete( self::t( 'lsv07a_posten' ),       [ 'abrechnung_id' => $id ], [ '%d' ] );
        $wpdb->delete( self::t( 'lsv07a_posten_notiz' ), [ 'abrechnung_id' => $id ], [ '%d' ] );
        $wpdb->delete( self::t( 'lsv07a_nicht_auto' ),   [ 'abrechnung_id' => $id ], [ '%d' ] );
        if ( $wpdb->delete( self::t( 'lsv07a_abrechnung' ), [ 'id' => $id ], [ '%d' ] ) === false ) {
            return 'Das Löschen ist fehlgeschlagen: '
                 . ( $wpdb->last_error ?: 'unbekannter Datenbankfehler' );
        }

        LSV07A_Nachricht::abrechnung_geloescht( $abr, $grund );
        return true;
    }

    /**
     * Eine Rechnung löschen, samt Belegen auf der Platte.
     *
     * @return true|string
     */
    public static function rechnung( $id, $grund, $uid = 0 ) {
        global $wpdb;
        $id    = (int) $id;
        $uid   = $uid ?: get_current_user_id();
        $grund = trim( (string) $grund );

        $r = LSV07A_Rechnung::holen( $id );
        if ( ! $r ) return 'Diese Rechnung gibt es nicht.';

        $darf = self::darf_rechnung( $r, $uid );
        if ( $darf !== true ) return $darf;

        if ( $grund === '' && self::grund_noetig( $r, $uid ) ) {
            return 'Bitte schreiben Sie dazu, warum gelöscht wird. '
                 . 'Löschen lässt sich nicht zurücknehmen.';
        }

        $dateien = LSV07A_Rechnung::dateien( $id );
        LSV07A_Log::schreibe( 'rechnung.geloescht', [
            'ziel_typ' => 'rechnung',
            'ziel_id'  => $id,
            'details'  => sprintf(
                '„%s" von %s, %s EUR, Stand „%s", %d Beleg(e). Grund: %s',
                (string) $r['titel'], LSV07A_Nachricht::name( $r['wp_user_id'] ),
                number_format( (float) $r['betrag'], 2, ',', '.' ),
                LSV07A_Berechnung::status_name( $r['status'] ),
                count( $dateien ),
                $grund !== '' ? $grund : 'eigener Entwurf, keine Begründung nötig' ),
        ] );

        LSV07A_Rechnung::dateien_weg( $id );
        if ( $wpdb->delete( self::t( 'lsv07a_rechnung' ), [ 'id' => $id ], [ '%d' ] ) === false ) {
            return 'Das Löschen ist fehlgeschlagen: '
                 . ( $wpdb->last_error ?: 'unbekannter Datenbankfehler' );
        }

        LSV07A_Nachricht::rechnung_geloescht( $r, $grund );
        return true;
    }
}
