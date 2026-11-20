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
 * | | Quartalsabrechnung | Rechnung | einzelner Beleg |
 * |---|---|---|---|
 * | Administration | fremde, nicht bezahlt | fremde, nicht bezahlt | fremde, auch bezahlt |
 * | Wart MIT Bereich | fremde im Bereich, eingereicht bis genehmigt | — | — |
 * | Wart ohne Bereich | nichts | — | — |
 * | Abteilungsleitung | — | fremde der Abteilung, eingereicht bis genehmigt | dto., auch bezahlt |
 * | Kasse | fremde, genehmigt | fremde, genehmigt | fremde, genehmigt und bezahlt |
 * | die Person selbst | Entwurf, zurückgegeben | Entwurf, zurückgegeben | Entwurf, zurückgegeben |
 *
 * Drei Regeln stecken darin:
 *
 *  1. **Bezahltes wird nie gelöscht.** Eine Zahlung steht im Kontoauszug;
 *     der Vorgang dazu muss bleiben. Ist etwas falsch, gibt es den Storno
 *     der Kasse bzw. das Wiederöffnen der Administration — beide mit Spur.
 *  2. **Der eigene Vorgang folgt nur der Personen-Zeile.** Eine Rolle gibt
 *     am EIGENEN Vorgang keine weiteren Rechte (Vier-Augen-Prinzip): Ein
 *     Wart löscht seine eingereichte Abrechnung nicht über die Wartrolle.
 *  3. **Fremde Entwürfe gehen niemanden etwas an** — weder Wart noch
 *     Leitung; was nie eingereicht wurde, ist Arbeit der Person.
 *
 * Ein Wart ohne hinterlegten Bereich sieht zwar alles (siehe
 * LSV07A_Zustaendig), LÖSCHEN darf er aber nichts: „Kein Eintrag" heisst
 * dort „nicht eingeschränkt worden", nicht „für das Löschen bestimmt".
 *
 * **Der einzelne Beleg folgt der Rechnung** — mit einer Ausnahme: Aus einer
 * BEZAHLTEN Rechnung darf ein falscher Beleg (fremder Kontoauszug,
 * Datenschutz) von Administration, Leitung und Kasse entfernt werden. Die
 * Buchung selbst bleibt dabei stehen; Begründung, Protokoll und Mitteilung
 * sind Pflicht. Beides beantwortet `darf_rechnung()` — eine Stelle.
 */
class LSV07A_Loeschung {

    /** Ab hier sieht die Kasse einen Vorgang überhaupt. */
    const KASSE_SICHT = [ 'genehmigt', 'bezahlt' ];

    /** Was die Person selbst noch löschen darf. */
    const SELBST = [ 'entwurf', 'zurueck' ];

    /** Was eine Rolle (Wart, Leitung) an FREMDEN Vorgängen löschen darf:
        eingereicht bis genehmigt — kein fremder Entwurf, nichts Bezahltes. */
    const ROLLE = [ 'eingereicht', 'zurueck', 'genehmigt' ];

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

        if ( $status === 'bezahlt' ) return self::bezahlt_bleibt();

        /* Der eigene Vorgang: nur die Personen-Zeile, keine Rolle. */
        if ( (int) ( $abr['wp_user_id'] ?? 0 ) === (int) $uid ) {
            return in_array( $status, self::SELBST, true ) ? true
                : 'Eine eingereichte Abrechnung lässt sich nicht selbst löschen. Holen Sie sie '
                . 'zurück, solange sie noch nicht entschieden ist.';
        }

        if ( LSV07A_Rollen::ist_admin( $uid ) ) return true;

        if ( LSV07A_Rollen::ist_kasse( $uid ) && $status === 'genehmigt' ) return true;

        if ( LSV07A_Rollen::ist_wart( $uid )
             && in_array( $status, self::ROLLE, true )
             && LSV07A_Zustaendig::bereiche( $uid )
             && LSV07A_Zustaendig::darf_pruefen( (int) $abr['id'], $uid ) ) {
            return true;
        }

        /* Die Kasse soll nicht erfahren, dass es etwas gibt, das sie nicht
           sehen darf — die Begründung nennt darum nur ihre eigene Grenze. */
        if ( LSV07A_Rollen::ist_kasse( $uid ) ) {
            return 'Die Kasse kann nur löschen, was genehmigt und noch nicht bezahlt ist.';
        }
        return 'Diese Abrechnung gehört nicht zu Ihrem Bereich.';
    }

    /**
     * Darf diese Person diese Rechnung löschen?
     *
     * @return true|string
     */
    public static function darf_rechnung( $r, $uid = 0, $nur_beleg = false ) {
        if ( ! is_array( $r ) ) return 'Diese Rechnung gibt es nicht.';
        $uid    = $uid ?: get_current_user_id();
        $status = (string) ( $r['status'] ?? '' );

        /* Bezahlt: die Buchung nie; nur einen falschen Beleg daraus (siehe
           Klassenkopf), und das nur durch eine Rolle, nie durch die Person. */
        if ( $status === 'bezahlt' && ! $nur_beleg ) return self::bezahlt_bleibt();

        if ( (int) ( $r['wp_user_id'] ?? 0 ) === (int) $uid ) {
            return in_array( $status, self::SELBST, true ) ? true
                : 'Eine eingereichte Rechnung lässt sich nicht selbst löschen. Holen Sie sie '
                . 'zurück, solange sie noch nicht entschieden ist.';
        }

        if ( LSV07A_Rollen::ist_admin( $uid ) ) return true;

        $kasse_darf = $nur_beleg ? self::KASSE_SICHT : [ 'genehmigt' ];
        if ( LSV07A_Rollen::ist_kasse( $uid ) && in_array( $status, $kasse_darf, true ) ) {
            return true;
        }

        $leitung_darf = $nur_beleg ? array_merge( self::ROLLE, [ 'bezahlt' ] ) : self::ROLLE;
        if ( in_array( $status, $leitung_darf, true )
             && LSV07A_Rechnung::darf_genehmigen( $r, $uid ) ) return true;

        if ( LSV07A_Rollen::ist_kasse( $uid ) ) {
            return $nur_beleg ? 'Die Kasse kann nur löschen, was genehmigt oder bezahlt ist.'
                              : 'Die Kasse kann nur löschen, was genehmigt und noch nicht bezahlt ist.';
        }
        return 'Diese Rechnung gehört nicht zu Ihrer Abteilung.';
    }

    /** Darf diese Person EINEN Beleg dieser Rechnung löschen? */
    public static function darf_beleg( $r, $uid = 0 ) {
        return self::darf_rechnung( $r, $uid, true );
    }

    private static function bezahlt_bleibt() {
        return 'Bezahlte Vorgänge werden nicht gelöscht — die Zahlung steht im Kontoauszug. '
             . 'Ist etwas falsch, kann die Kasse den Zahlungsvermerk stornieren (mit Begründung).';
    }

    /**
     * Muss vor dem Löschen gewarnt werden?
     *
     * Ein Entwurf ist kein Beleg — den löscht man ohne Umstände. Alles ab
     * „eingereicht" ist einer, und dann soll vor dem Klick dastehen, was das
     * bedeutet.
     */
    public static function warnung( $status, $was = 'vorgang' ) {
        if ( in_array( (string) $status, [ 'entwurf' ], true ) ) return '';
        if ( $was === 'beleg' ) {
            return 'Diese Rechnung wurde bereits eingereicht. Der Beleg ist damit der '
                 . 'Nachweis zu einer gebuchten Zahlung; § 147 AO und § 257 HGB verlangen '
                 . 'zehn Jahre Aufbewahrung. Die Rechnung bleibt stehen — ihr Nachweis ist '
                 . 'danach weg, auch aus dem Jahrespaket. Das Löschen wird protokolliert '
                 . 'und der betroffenen Person mitgeteilt.';
        }
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

        /* Atomar: gelöscht wird nur, wenn die Zeile noch im geprüften Stand
           steht. Hat die Kasse gerade bezahlt, scheitert das Löschen, statt
           eine eben bezahlte Abrechnung mitzunehmen. Erst die Zeile, dann
           alles, was daran hängt. */
        $summe = LSV07A_Berechnung::summe( $id );
        $n = $wpdb->delete( self::t( 'lsv07a_abrechnung' ),
            [ 'id' => $id, 'status' => (string) $abr['status'] ], [ '%d', '%s' ] );
        if ( $n === false ) return LSV07A_Meldung::datenbank( 'Das Löschen ist fehlgeschlagen.' );
        if ( (int) $n !== 1 ) {
            return 'Diese Abrechnung wurde gerade von jemand anderem geändert. Bitte neu laden.';
        }

        /* Protokolliert wird mit den Angaben von VOR dem Löschen. */
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
        /* Atomar wie bei der Abrechnung: nur im geprüften Stand. */
        $n = $wpdb->delete( self::t( 'lsv07a_rechnung' ),
            [ 'id' => $id, 'status' => (string) $r['status'] ], [ '%d', '%s' ] );
        if ( $n === false ) return LSV07A_Meldung::datenbank( 'Das Löschen ist fehlgeschlagen.' );
        if ( (int) $n !== 1 ) {
            return 'Diese Rechnung wurde gerade von jemand anderem geändert. Bitte neu laden.';
        }
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

        LSV07A_Nachricht::rechnung_geloescht( $r, $grund );
        return true;
    }

    /**
     * EINEN Beleg löschen — unwiderruflich, auch aus dem Archiv.
     *
     * Vorher ging das nur, solange die Rechnung noch ein Entwurf war
     * (`LSV07A_Rechnung::datei_loeschen()`). Für eine eingereichte,
     * bezahlte oder archivierte Rechnung blieb nur das Löschen der ganzen
     * Rechnung — oder der Weg über die Datenbank, der nichts protokolliert.
     * Es gibt aber echte Gründe für genau diesen Schnitt: ein Beleg am
     * falschen Vorgang, ein versehentlich hochgeladener Kontoauszug, eine
     * Datei mit Daten, die dort nichts zu suchen haben.
     *
     * Die Rechnung selbst bleibt dabei stehen. Nur ihr Nachweis ist weg —
     * und weil das nicht nachträglich zu bemerken sein darf, gilt
     * dasselbe wie beim Löschen der Rechnung:
     *
     *  * **Begründung ist Pflicht**, sobald die Rechnung über „Entwurf"
     *    hinaus ist oder jemand anderes löscht.
     *  * **Vorher wird protokolliert**: Dateiname, Grösse, Rechnung, Stand,
     *    wie viele Belege danach übrig sind, und der Grund.
     *  * **Die betroffene Person bekommt eine Mitteilung** mit dem Grund.
     *  * **Die Datei wird überschrieben, dann gelöscht**
     *    (`LSV07A_Rechnung::datei_vernichten()`), und der Eintrag in der
     *    Datenbank verschwindet — damit ist auch der Download-Endpunkt zu.
     *
     * @return true|string
     */
    public static function beleg( $datei_id, $grund, $uid = 0 ) {
        global $wpdb;
        $datei_id = (int) $datei_id;
        $uid      = $uid ?: get_current_user_id();
        $grund    = trim( (string) $grund );

        $d = LSV07A_Rechnung::datei_holen( $datei_id );
        if ( ! $d ) return 'Diesen Beleg gibt es nicht.';

        $r = LSV07A_Rechnung::holen( (int) $d['rechnung_id'] );
        if ( ! $r ) return 'Die Rechnung zu diesem Beleg gibt es nicht mehr.';

        /* Dieselbe Frage wie bei der ganzen Rechnung — siehe Klassenkopf. */
        $darf = self::darf_beleg( $r, $uid );
        if ( $darf !== true ) return $darf;

        if ( $grund === '' && self::grund_noetig( $r, $uid ) ) {
            return 'Bitte schreiben Sie dazu, warum der Beleg gelöscht wird. '
                 . 'Löschen lässt sich nicht zurücknehmen.';
        }

        /* Erst protokollieren, dann löschen: Nach dem DELETE ist nicht mehr
           zu ermitteln, was dort lag. */
        $uebrig = max( 0, count( LSV07A_Rechnung::dateien( (int) $r['id'] ) ) - 1 );
        LSV07A_Log::schreibe( 'rechnung.beleg_geloescht', [
            'ziel_typ' => 'rechnung',
            'ziel_id'  => (int) $r['id'],
            'details'  => sprintf(
                'Beleg „%s" (%s) aus Rechnung „%s" von %s, Stand „%s". '
                . 'Danach %d Beleg(e) übrig. Grund: %s',
                (string) $d['dateiname'],
                LSV07A_Rechnung::groesse_text( (int) $d['groesse'] ),
                (string) $r['titel'],
                LSV07A_Nachricht::name( $r['wp_user_id'] ),
                LSV07A_Berechnung::status_name( $r['status'] ),
                $uebrig,
                $grund !== '' ? $grund : 'eigener Entwurf, keine Begründung nötig' ),
        ] );

        LSV07A_Rechnung::datei_vernichten( (string) $d['pfad'] );
        if ( $wpdb->delete( self::t( 'lsv07a_rechnung_datei' ),
                            [ 'id' => $datei_id ], [ '%d' ] ) === false ) {
            return LSV07A_Meldung::datenbank( 'Das Löschen ist fehlgeschlagen.' );
        }

        LSV07A_Nachricht::beleg_geloescht( $r, $d, $grund );
        return true;
    }
}
