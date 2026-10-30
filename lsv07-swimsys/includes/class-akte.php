<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Akte: alles zu einer Person an einem Ort.
 *
 * Im internen Bereich liest die Akte ausschliesslich die ältere
 * Anwesenheitstabelle mit drei Zuständen. Was im Mannschaftsbuch steht —
 * krank, beurlaubt, zehn Minuten zu spät, Vermerke, Aufgaben — kommt
 * darin nicht vor. Ebenso fehlen die Wettkampfteilnahme aus der
 * Wettkampf-Anwesenheit und die Reflexionsbögen. Es gibt sie ausserdem
 * nur für Schwimmer; Triathlon, Fitness und Trainer haben keine.
 *
 * Hier kommt die Akte aus derselben Quelle wie die Anwesenheitsansicht
 * (LSV07S_Anwesenheit) und zeigt für alle drei Sparten dasselbe.
 *
 * Eine Akte ist eine Zusammenstellung über eine Person. Sie hat deshalb
 * ihren eigenen Schalter, und jeder Abruf steht im Protokoll —
 * wer eine Akte öffnet, hat danach alles über diese Person gesehen.
 */
class LSV07S_Akte {

    /**
     * Die Akte einer Person in einem Zeitraum.
     *
     * @return array|string Die Akte, oder der Satz für den Nutzer.
     */
    public static function akte( $sparte, $person_id, $von = '', $bis = '' ) {
        global $wpdb;
        $person_id = (int) $person_id;

        if ( ! LSV07S_Sparte::gibt( $sparte ) || ! LSV07S_Sparte::da( $sparte ) ) {
            return 'Diese Sparte ist nicht eingerichtet.';
        }
        if ( ! LSV07S_Schalter::an( 'schwimmen/akte' ) ) {
            return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        }
        if ( ! LSV07S_Stamm::sportler_in_reichweite( $sparte, $person_id ) ) {
            return 'Diese Person gehört nicht zu Ihrem Bereich.';
        }

        $t = LSV07S_Sparte::tabelle( $sparte, 't_sportler' );
        $zeile = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $person_id ), ARRAY_A );
        if ( ! $zeile ) return 'Diese Person gibt es nicht.';

        /* Ohne Zeitraum die laufende Saison — das ist der Zeitraum, über
           den man beim Elterngespräch redet. */
        $a = LSV07S_Datum::rein( $von );
        $b = LSV07S_Datum::rein( $bis );
        if ( $a === null ) {
            $s = LSV07S_Datum::saison_aktiv();
            $a = $s ? $s['start_datum'] : date( 'Y-01-01', strtotime( LSV07S_Datum::heute() ) );
        }
        if ( $b === null ) $b = LSV07S_Datum::heute();
        if ( $b < $a ) [ $a, $b ] = [ $b, $a ];

        $gb = LSV07S_Sparte::feld( $sparte, 'geburt' );
        $mannschaften = [];
        foreach ( LSV07S_Stamm::zuordnung( $sparte, $person_id ) as $m ) {
            $mann = LSV07S_Mannschaft::holen( $sparte, $m );
            if ( $mann ) $mannschaften[] = $mann;
        }

        $akte = [
            'sparte'   => $sparte,
            'person'   => [
                'id'       => $person_id,
                'name'     => LSV07S_Person::aus_zeile( $zeile, $sparte ),
                'jahrgang' => LSV07S_Person::jahrgang( $zeile[ $gb ] ?? null ),
                'geburt_de'=> LSV07S_Datum::de( $zeile[ $gb ] ?? null ),
                'dsv_id'   => (string) ( $zeile['dsv_id'] ?? '' ),
                'aktiv'    => (int) ( $zeile[ LSV07S_Sparte::feld( $sparte, 'aktiv' ) ] ?? 0 ) === 1,
            ],
            'von'      => $a,
            'bis'      => $b,
            'mannschaften' => $mannschaften,
        ];

        /* Das Attest ist ein Gesundheitsdatum: Es steht nur in der Akte,
           wenn das Konto es auch in der Attestliste sehen dürfte. */
        if ( LSV07S_Sparte::feld( $sparte, 'hat_attest' )
             && LSV07S_Schalter::an( 'schwimmen/atteste' ) ) {
            $akte['attest'] = [
                'bis'        => $zeile['attest_expires'] ?? null,
                'bis_de'     => LSV07S_Datum::de( $zeile['attest_expires'] ?? null ),
                'stand'      => LSV07S_Attest::stand( $zeile['attest_expires'] ?? null ),
                'stand_name' => LSV07S_Attest::stand_name(
                                  LSV07S_Attest::stand( $zeile['attest_expires'] ?? null ) ),
            ];
        }

        if ( LSV07S_Sparte::feld( $sparte, 'hat_attest' ) ) {
            $akte['kontakte'] = LSV07S_Stamm::kontakte( $person_id );
        }

        $akte['anwesenheit'] = self::anwesenheit( $sparte, $person_id, $mannschaften, $a, $b );
        $akte['buch']        = self::buch( $sparte, $person_id, $mannschaften, $a, $b );
        $akte['wettkampf']   = self::wettkampf( $sparte, $person_id, $a, $b );
        $akte['bestzeiten']  = self::bestzeiten( $sparte, $person_id );
        $akte['reflexion']   = self::reflexion( $sparte, $person_id );
        /* Die Papiere der Person. `LSV07S_Datei::liste()` prüft den
           eigenen Schalter selbst und filtert die Atteste heraus — die
           Akte darf nicht die Stelle sein, an der der Datei-Schalter
           plötzlich mehr zeigt als die Dateiliste. */
        $akte['dateien']     = $sparte === LSV07S_Sparte::SCHWIMMEN
                                 ? LSV07S_Datei::liste( $person_id ) : [];
        $akte['notiz']       = (string) ( $zeile['notes'] ?? '' );

        LSV07S_Log::schreibe( 'akte.geoeffnet', [
            'bereich' => $sparte, 'ziel_typ' => 'person', 'ziel_id' => $person_id,
            'ziel_name' => $akte['person']['name'],
            'details' => $a . ' bis ' . $b,
        ] );

        return $akte;
    }

    /**
     * Die Anwesenheit — aus derselben Quelle wie die Anwesenheitsansicht.
     *
     * Damit stehen hier auch „krank", „beurlaubt" und die Verspätungen.
     * Im internen Bereich zählt die Akte nur anwesend, abwesend und
     * entschuldigt, weil sie die ältere Tabelle liest.
     */
    private static function anwesenheit( $sparte, $person_id, array $mannschaften, $von, $bis ) {
        $raus = [];
        foreach ( $mannschaften as $m ) {
            foreach ( LSV07S_Anwesenheit::quote( $sparte, $m['id'], $von, $bis ) as $z ) {
                if ( $z['id'] !== (int) $person_id ) continue;
                $raus[] = [
                    'mannschaft'     => $m['name'],
                    'anwesend'       => $z['anwesend'],
                    'entschuldigt'   => $z['entschuldigt'],
                    'unentschuldigt' => $z['unentschuldigt'],
                    'gesamt'         => $z['gesamt'],
                    'quote'          => $z['quote'],
                    'verspaetungen'  => $z['verspaetungen'],
                    'minuten_spaet'  => $z['minuten_spaet'],
                    'monate'         => $z['monate'],
                ];
            }
        }
        return $raus;
    }

    /** Vermerke und Aufgaben aus dem Mannschaftsbuch — im internen Bereich fehlen sie. */
    private static function buch( $sparte, $person_id, array $mannschaften, $von, $bis ) {
        global $wpdb;
        if ( ! LSV07S_Sparte::feld( $sparte, 'hat_buch' )
             || ! LSV07S_Tabellen::da( 'mb_eintrag' ) ) return [];
        if ( $mannschaften === [] ) return [];

        $ids   = array_column( $mannschaften, 'id' );
        $platz = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $werte = array_merge( $ids, [ (int) $person_id, $von, $bis ] );

        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT e.*, t.datum FROM ' . LSV07S_Tabellen::t( 'mb_eintrag' ) . ' e'
            . ' INNER JOIN ' . LSV07S_Tabellen::t( 'mb_tag' ) . ' t ON t.id = e.tag_id'
            . " WHERE e.mannschaft_id IN ($platz) AND e.schwimmer_id = %d"
            . ' AND t.datum >= %s AND t.datum <= %s'
            . ' ORDER BY t.datum DESC, e.id DESC', $werte ), ARRAY_A );

        return array_map( fn( $z ) => [
            'datum'    => (string) $z['datum'],
            'datum_de' => LSV07S_Datum::de( $z['datum'] ),
            'art'      => (string) $z['art'],
            'art_name' => LSV07S_Buch::ARTEN[ $z['art'] ] ?? (string) $z['art'],
            'kategorie'=> (string) $z['kategorie'],
            'text'     => (string) $z['text'],
            'erledigt' => (int) $z['erledigt'] === 1,
        ], $zeilen );
    }

    /**
     * Wettkampfteilnahmen.
     *
     * Aus der Wettkampf-Anwesenheit UND aus den Meldungen: Die Meldung
     * sagt, wofür jemand gemeldet war, die Anwesenheit, ob er dort war.
     * Die Akte des internen Bereichs zählt nur Meldungen und nennt sie
     * „abgegebene Meldungen" — ein Status „abgegeben" gibt es in der
     * Tabelle aber nicht, also zählt sie alle.
     */
    private static function wettkampf( $sparte, $person_id, $von, $bis ) {
        global $wpdb;
        if ( $sparte !== LSV07S_Sparte::SCHWIMMEN ) return [];
        if ( ! LSV07S_Schalter::an( 'schwimmen/wettkampf' )
             && ! LSV07S_Schalter::an( 'schwimmen/meldung' ) ) {
            return [];
        }
        if ( ! LSV07S_Tabellen::da( 'wettkampf' ) ) return [];

        $raus = [];

        if ( LSV07S_Tabellen::da( 'meldung' ) && LSV07S_Tabellen::da( 'meldung_start' ) ) {
            $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
                'SELECT w.name, w.ort, w.datum_von, s.strecke, s.meldezeit'
                . ' FROM ' . LSV07S_Tabellen::t( 'meldung_start' ) . ' s'
                . ' INNER JOIN ' . LSV07S_Tabellen::t( 'meldung' ) . ' m ON m.id = s.meldung_id'
                . ' INNER JOIN ' . LSV07S_Tabellen::t( 'wettkampf' ) . ' w ON w.id = m.wettkampf_id'
                . ' WHERE s.schwimmer_id = %d AND w.datum_von >= %s AND w.datum_von <= %s'
                . ' ORDER BY w.datum_von DESC', (int) $person_id, $von, $bis ), ARRAY_A );
            foreach ( $zeilen as $z ) {
                $raus[] = [
                    'wettkampf' => (string) $z['name'],
                    'ort'       => (string) $z['ort'],
                    'datum'     => (string) $z['datum_von'],
                    'datum_de'  => LSV07S_Datum::de( $z['datum_von'] ),
                    'strecke'   => (string) $z['strecke'],
                    'meldezeit' => (string) $z['meldezeit'],
                ];
            }
        }
        return $raus;
    }

    /** Die Bestzeiten — der aktuelle Stand. */
    private static function bestzeiten( $sparte, $person_id ) {
        global $wpdb;
        if ( $sparte !== LSV07S_Sparte::SCHWIMMEN ) return [];
        if ( ! LSV07S_Schalter::an( 'schwimmen/bestzeit' ) ) return [];
        if ( ! LSV07S_Tabellen::da( 'bestzeiten' ) ) return [];
        $t = LSV07S_Tabellen::t( 'bestzeiten' );
        if ( ! LSV07S_Mannschaft::spalte_da( $t, 'swimmer_id' ) ) return [];

        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT strecke, zeit_raw FROM $t WHERE swimmer_id = %d ORDER BY strecke ASC",
            (int) $person_id ), ARRAY_A );
        return array_map( fn( $z ) => [
            'strecke' => (string) $z['strecke'],
            'zeit'    => (string) $z['zeit_raw'],
        ], $zeilen );
    }

    /** Die Reflexionsbögen — im internen Bereich fehlen sie in der Akte. */
    private static function reflexion( $sparte, $person_id ) {
        global $wpdb;
        if ( $sparte !== LSV07S_Sparte::SCHWIMMEN ) return [];
        if ( ! LSV07S_Schalter::an( 'schwimmen/reflexion' ) ) return [];
        if ( ! LSV07S_Tabellen::da( 'reflexion' ) ) return [];

        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT id, saison_id, status, abgegeben_am FROM ' . LSV07S_Tabellen::t( 'reflexion' )
            . ' WHERE swimmer_id = %d ORDER BY id DESC', (int) $person_id ), ARRAY_A );
        return array_map( fn( $z ) => [
            'id'        => (int) $z['id'],
            'saison_id' => (int) $z['saison_id'],
            'status'    => (string) $z['status'],
            'am'        => LSV07S_Datum::de_zeit( $z['abgegeben_am'] ?? null, '–' ),
        ], $zeilen );
    }

    /**
     * Die Akten einer ganzen Mannschaft — für das Elterngespräch oder die
     * Saisonbesprechung.
     *
     * Jede Akte geht einzeln durch die Rechteprüfung; die Liste ist nur
     * eine Schleife darüber und kein zweiter Weg an den Daten vorbei.
     */
    public static function mannschaft( $sparte, $mannschaft_id, $von = '', $bis = '' ) {
        $raus = [];
        foreach ( LSV07S_Mannschaft::sportler( $sparte, $mannschaft_id ) as $s ) {
            $akte = self::akte( $sparte, $s['id'], $von, $bis );
            if ( is_array( $akte ) ) $raus[] = $akte;
        }
        return $raus;
    }
}
