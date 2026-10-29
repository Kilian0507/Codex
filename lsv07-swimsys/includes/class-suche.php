<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Eine Suche über alles, was man sehen darf.
 *
 * Im internen Bereich gibt es 12 Suchfelder, jedes in genau einer Liste.
 * Wer einen Namen sucht und nicht weiss, in welcher Mannschaft die Person
 * steht, klickt sich durch die Mannschaften. Eine Suche über alles gibt es
 * nicht — 0 Treffer für „global", keine Befehlspalette, kein Tastenkürzel.
 *
 * Diese Suche fragt jede Quelle einzeln und — das ist der Punkt — prüft je
 * Quelle das Recht, das die zugehörige Ansicht verlangt. Wer Atteste nicht
 * sehen darf, findet über die Suche keine. Eine Suche, die alles findet,
 * ist ein Datenleck mit Eingabefeld.
 */
class LSV07S_Suche {

    /** Kürzer als zwei Zeichen sucht nichts — das wäre die halbe Datenbank. */
    const MINDESTENS = 2;

    /** Je Quelle höchstens so viele Treffer, damit eine Zeile nicht alles verdrängt. */
    const JE_QUELLE = 8;

    public static function suche( $wort, $uid = 0 ) {
        $wort = trim( (string) $wort );
        $uid  = (int) ( $uid ?: get_current_user_id() );
        if ( mb_strlen( $wort ) < self::MINDESTENS ) {
            return [ 'wort' => $wort, 'gruppen' => [], 'hinweis' =>
                'Bitte mindestens ' . self::MINDESTENS . ' Zeichen eingeben.' ];
        }

        $gruppen = [];
        foreach ( [ 'sportler', 'mannschaften', 'trainer', 'wettkaempfe', 'anfragen' ] as $quelle ) {
            $treffer = self::$quelle( $wort, $uid );
            if ( $treffer ) $gruppen[] = $treffer;
        }
        return [ 'wort' => $wort, 'gruppen' => $gruppen, 'hinweis' => '' ];
    }

    /** `%wort%` für LIKE, mit den Sonderzeichen von LIKE entschärft. */
    private static function wie( $wort ) {
        global $wpdb;
        return '%' . $wpdb->esc_like( $wort ) . '%';
    }

    private static function gruppe( $name, array $treffer ) {
        return $treffer === [] ? null : [ 'name' => $name, 'treffer' => $treffer ];
    }

    /**
     * Sportler aller drei Sparten — aber nur in Mannschaften, die man
     * sehen darf, und nur mit dem Leserecht der jeweiligen Sparte.
     */
    private static function sportler( $wort, $uid ) {
        global $wpdb;
        $treffer = [];
        foreach ( LSV07S_Sparte::alle() as $sparte ) {
            if ( ! LSV07S_Sparte::da( $sparte ) ) continue;
            /* Dieselbe Frage wie die Liste stellt — und zwar an derselben
               Stelle. Eine eigene Fassung hier hatte den Wart ausgesperrt,
               dem die Liste alle Mannschaften zeigt. */
            if ( ! LSV07S_Rechte::sieht_sportler( $sparte, $uid ) ) continue;

            $erlaubt = array_column( LSV07S_Mannschaft::erlaubte( $sparte, $uid ), 'id' );
            if ( $erlaubt === [] ) continue;

            $t_sp  = LSV07S_Sparte::tabelle( $sparte, 't_sportler' );
            $t_zu  = LSV07S_Sparte::tabelle( $sparte, 't_sp_gruppe' );
            $zu_sp = LSV07S_Sparte::feld( $sparte, 'sp_gruppe_sp' );
            $zu_gr = LSV07S_Sparte::feld( $sparte, 'sp_gruppe_gr' );
            $vn    = LSV07S_Sparte::feld( $sparte, 'vorname' );
            $nn    = LSV07S_Sparte::feld( $sparte, 'nachname' );
            $platz = implode( ',', array_fill( 0, count( $erlaubt ), '%d' ) );

            $werte  = array_merge( $erlaubt, [ self::wie( $wort ), self::wie( $wort ),
                                               self::wie( $wort ), self::JE_QUELLE ] );
            $zeilen = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT s.*, z.$zu_gr AS mann_id FROM $t_sp s
                   INNER JOIN $t_zu z ON z.$zu_sp = s.id
                  WHERE z.$zu_gr IN ($platz)
                    AND ( s.$vn LIKE %s OR s.$nn LIKE %s
                          OR CONCAT(s.$vn, ' ', s.$nn) LIKE %s )
               ORDER BY s.$nn ASC LIMIT %d", $werte ), ARRAY_A );

            foreach ( (array) $zeilen as $z ) {
                $mann = LSV07S_Mannschaft::holen( $sparte, $z['mann_id'] );
                $treffer[] = [
                    'name' => LSV07S_Person::aus_zeile( $z, $sparte ),
                    'wo'   => LSV07S_Sparte::feld( $sparte, 'name' )
                            . ( $mann ? ' · ' . $mann['name'] : '' ),
                    'ziel' => '#' . $sparte . '/mannschaft/' . (int) $z['mann_id']
                            . '/' . (int) $z['id'],
                ];
            }
        }
        return self::gruppe( 'Sportler', $treffer );
    }

    private static function mannschaften( $wort, $uid ) {
        $treffer = [];
        foreach ( LSV07S_Sparte::alle() as $sparte ) {
            if ( ! LSV07S_Sparte::da( $sparte ) ) continue;
            foreach ( LSV07S_Mannschaft::erlaubte( $sparte, $uid ) as $m ) {
                if ( mb_stripos( $m['name'], $wort ) === false ) continue;
                $treffer[] = [
                    'name' => $m['name'],
                    'wo'   => LSV07S_Sparte::feld( $sparte, 'name' ),
                    'ziel' => '#' . $sparte . '/mannschaft/' . $m['id'],
                ];
                if ( count( $treffer ) >= self::JE_QUELLE ) break 2;
            }
        }
        return self::gruppe( 'Mannschaften und Gruppen', $treffer );
    }

    private static function trainer( $wort, $uid ) {
        global $wpdb;
        $treffer = [];
        foreach ( LSV07S_Sparte::alle() as $sparte ) {
            if ( ! LSV07S_Sparte::da( $sparte ) ) continue;
            if ( ! LSV07S_Schalter::an( LSV07S_Sparte::funktion( $sparte, 'trainer' ), $uid ) ) continue;
            $t = LSV07S_Sparte::tabelle( $sparte, 't_trainer' );
            $zeilen = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, name, email FROM $t WHERE name LIKE %s ORDER BY name ASC LIMIT %d",
                self::wie( $wort ), self::JE_QUELLE ), ARRAY_A );
            foreach ( (array) $zeilen as $z ) {
                $treffer[] = [
                    'name' => (string) $z['name'],
                    'wo'   => LSV07S_Sparte::feld( $sparte, 'name' ) . ' · Trainer',
                    'ziel' => '#' . $sparte . '/trainer/' . (int) $z['id'],
                ];
            }
        }
        return self::gruppe( 'Trainer', $treffer );
    }

    private static function wettkaempfe( $wort, $uid ) {
        global $wpdb;
        if ( ! LSV07S_Schalter::an( 'schwimmen/wettkampf', $uid ) ) return null;
        if ( ! LSV07S_Tabellen::da( 'wettkampf' ) ) return null;
        $t = LSV07S_Tabellen::t( 'wettkampf' );
        $zeilen = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, ort, datum_von FROM $t
              WHERE name LIKE %s OR ort LIKE %s
           ORDER BY datum_von DESC LIMIT %d",
            self::wie( $wort ), self::wie( $wort ), self::JE_QUELLE ), ARRAY_A );
        $treffer = [];
        foreach ( (array) $zeilen as $z ) {
            $treffer[] = [
                'name' => (string) $z['name'],
                'wo'   => trim( (string) ( $z['ort'] ?? '' ) . ' · '
                               . LSV07S_Datum::de( $z['datum_von'] ?? null ), ' ·' ),
                'ziel' => '#schwimmen/wettkampf/' . (int) $z['id'],
            ];
        }
        return self::gruppe( 'Wettkämpfe', $treffer );
    }

    private static function anfragen( $wort, $uid ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'tickets' ) ) return null;
        $t     = LSV07S_Tabellen::t( 'tickets' );
        $alle  = LSV07S_Schalter::an( 'reden/anfragen_alle', $uid );
        $wo    = $alle ? '' : $wpdb->prepare( ' AND ersteller_user_id = %d', $uid );
        $zeilen = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, nummer, titel, status FROM $t
              WHERE ( titel LIKE %s OR nummer LIKE %s ) $wo
           ORDER BY erstellt_am DESC LIMIT %d",
            self::wie( $wort ), self::wie( $wort ), self::JE_QUELLE ), ARRAY_A );
        $treffer = [];
        foreach ( (array) $zeilen as $z ) {
            $treffer[] = [
                'name' => (string) $z['titel'],
                'wo'   => trim( (string) $z['nummer'] . ' · ' . (string) $z['status'], ' ·' ),
                'ziel' => '#reden/tickets/' . (int) $z['id'],
            ];
        }
        return self::gruppe( 'Anfragen', $treffer );
    }
}
