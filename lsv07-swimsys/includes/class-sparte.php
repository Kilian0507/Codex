<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Schwimmen, Triathlon und Fitness — der Unterschied als Daten.
 *
 * Im internen Bereich sind Triathlon und Fitness Kopien voneinander:
 * 569 gegen 572 Zeilen PHP, nach Umbenennung 13 abweichende Zeilen; im
 * Skript 421 gegen 414 Zeilen mit 59 Abweichungen. Das hat zwei Folgen,
 * die man im Betrieb merkt. Erstens fehlt beiden, was Schwimmen hat:
 * Statistik, Springer, Attest, Mahnung. Zweitens laufen die Kopien
 * auseinander — Triathlon und Fitness rechnen dem Trainer feste 2,0 h an,
 * Schwimmen die Dauer des Slots, und niemand hat das entschieden.
 *
 * Hier steht jede Sparte als Zeile in einer Tabelle: welche Tabellen, wie
 * die Spalten heissen, wie der Sportler heisst, welches Rechte-Präfix
 * gilt. Die Arbeit selbst macht EINE Fassung des Codes für alle drei. Eine
 * vierte Sparte wäre eine Zeile hier und keine vierte Kopie.
 */
class LSV07S_Sparte {

    const SCHWIMMEN = 'schwimmen';
    const TRIATHLON = 'triathlon';
    const FITNESS   = 'fitness';

    /**
     * Die Karte je Sparte.
     *
     * `gruppe_spalte`: so heisst die Gruppen-Fremdschlüsselspalte in den
     * Slot- und Anwesenheitstabellen (Schwimmen sagt mannschaft_id, die
     * anderen gruppe_id).
     * `teiln_spalte`: so heisst die Teilnehmerspalte in den Einträgen.
     * `teiln_typ_spalte`: nur Schwimmen führt zusätzlich eine Typspalte
     * (teilnehmer_typ), weil dort auch Trainer in derselben Tabelle stehen.
     */
    private static function karte() {
        return [
            self::SCHWIMMEN => [
                'name'             => 'Schwimmen',
                'einer'            => 'Schwimmer',
                'mehrere'          => 'Schwimmer',
                'gruppe_name'      => 'Mannschaft',
                'gruppe_mehrere'   => 'Mannschaften',
                'recht_praefix'    => 'schwimmen.',
                /* Die Rechtenamen heissen je Sparte anders:
                   schwimmen.mannschaft.* und schwimmen.schwimmer.* gegen
                   triathlon.gruppe.* und triathlon.sportler.*. Das steht
                   hier, damit keine Abfrage es selbst zusammensetzt. */
                'recht_gruppe'     => 'mannschaft',
                'recht_sportler'   => 'schwimmer',
                't_gruppen'        => 'mannschaften',
                't_sportler'       => 'schwimmer',
                't_sp_gruppe'      => 'schwimmer_mann',
                't_trainer'        => 'trainer',
                't_tr_gruppe'      => 'trainer_mann',
                't_slots'          => 'slots',
                't_anw'            => 'anwesenheit',
                't_anw_eintrag'    => 'anw_eintrag',
                'gruppe_spalte'    => 'mannschaft_id',
                'sp_gruppe_sp'     => 'swimmer_id',
                'sp_gruppe_gr'     => 'gruppe_id',
                'tr_gruppe_gr'     => 'mannschaft_id',
                'teiln_spalte'     => 'teilnehmer_id',
                'teiln_typ_spalte' => 'teilnehmer_typ',
                'vorname'          => 'first_name',
                'nachname'         => 'last_name',
                'geburt'           => 'birth_date',
                'aktiv'            => 'active',
                /* mv_swimmers.birth_date ist NOT NULL — ohne Geburtsdatum
                   lässt sich ein Schwimmer nicht anlegen. Bei Meldungen
                   zählt ohnehin der Jahrgang, nicht das Alter. */
                'geburt_pflicht'   => true,
                'hat_buch'         => true,   // nur Schwimmen hat das Mannschaftsbuch
                'hat_attest'       => true,
            ],
            self::TRIATHLON => [
                'name'             => 'Triathlon',
                'einer'            => 'Sportler',
                'mehrere'          => 'Sportler',
                'gruppe_name'      => 'Gruppe',
                'gruppe_mehrere'   => 'Gruppen',
                'recht_praefix'    => 'triathlon.',
                'recht_gruppe'     => 'gruppe',
                'recht_sportler'   => 'sportler',
                't_gruppen'        => 'tri_gruppen',
                't_sportler'       => 'tri_sportler',
                't_sp_gruppe'      => 'tri_sp_gruppe',
                't_trainer'        => 'tri_trainer',
                't_tr_gruppe'      => 'tri_tr_gruppe',
                't_slots'          => 'tri_slots',
                't_anw'            => 'tri_anw',
                't_anw_eintrag'    => 'tri_anw_eintr',
                'gruppe_spalte'    => 'gruppe_id',
                'sp_gruppe_sp'     => 'sportler_id',
                'sp_gruppe_gr'     => 'gruppe_id',
                'tr_gruppe_gr'     => 'gruppe_id',
                'teiln_spalte'     => 'sportler_id',
                'teiln_typ_spalte' => '',
                'vorname'          => 'first_name',
                'nachname'         => 'last_name',
                'geburt'           => 'birth_date',
                'aktiv'            => 'aktiv',
                /* Hier ist die Spalte nullbar, und ohne Meldungen braucht
                   es den Jahrgang nicht. */
                'geburt_pflicht'   => false,
                'hat_buch'         => false,
                'hat_attest'       => false,
            ],
            self::FITNESS => [
                'name'             => 'Fitness',
                'einer'            => 'Sportler',
                'mehrere'          => 'Sportler',
                'gruppe_name'      => 'Gruppe',
                'gruppe_mehrere'   => 'Gruppen',
                'recht_praefix'    => 'fitness.',
                'recht_gruppe'     => 'gruppe',
                'recht_sportler'   => 'sportler',
                't_gruppen'        => 'fit_gruppen',
                't_sportler'       => 'fit_sportler',
                't_sp_gruppe'      => 'fit_sp_gruppe',
                't_trainer'        => 'fit_trainer',
                't_tr_gruppe'      => 'fit_tr_gruppe',
                't_slots'          => 'fit_slots',
                't_anw'            => 'fit_anw',
                't_anw_eintrag'    => 'fit_anw_eintr',
                'gruppe_spalte'    => 'gruppe_id',
                'sp_gruppe_sp'     => 'sportler_id',
                'sp_gruppe_gr'     => 'gruppe_id',
                'tr_gruppe_gr'     => 'gruppe_id',
                'teiln_spalte'     => 'sportler_id',
                'teiln_typ_spalte' => '',
                'vorname'          => 'first_name',
                'nachname'         => 'last_name',
                'geburt'           => 'birth_date',
                'aktiv'            => 'aktiv',
                'geburt_pflicht'   => false,
                'hat_buch'         => false,
                'hat_attest'       => false,
            ],
        ];
    }

    /** Alle Kennungen in der Reihenfolge, in der sie in der Oberfläche stehen. */
    public static function alle() {
        return array_keys( self::karte() );
    }

    /** Gibt es diese Sparte? */
    public static function gibt( $sparte ) {
        return isset( self::karte()[ (string) $sparte ] );
    }

    /**
     * Ein Feld der Sparte. Eine unbekannte Sparte oder ein unbekanntes Feld
     * ist ein Programmfehler und wird nicht still zu einer leeren Abfrage.
     */
    public static function feld( $sparte, $feld ) {
        $k = self::karte();
        if ( ! isset( $k[ $sparte ] ) ) {
            _doing_it_wrong( __METHOD__, 'Unbekannte Sparte: ' . esc_html( (string) $sparte ), '0.1.0' );
            return '';
        }
        if ( ! array_key_exists( $feld, $k[ $sparte ] ) ) {
            _doing_it_wrong( __METHOD__, 'Unbekanntes Feld: ' . esc_html( (string) $feld ), '0.1.0' );
            return '';
        }
        return $k[ $sparte ][ $feld ];
    }

    /** Die ganze Zeile — für Code, der mehrere Felder braucht. */
    public static function daten( $sparte ) {
        $k = self::karte();
        return $k[ $sparte ] ?? null;
    }

    /** Der volle Tabellenname hinter einem Tabellenfeld der Sparte. */
    public static function tabelle( $sparte, $feld ) {
        $schluessel = self::feld( $sparte, $feld );
        return $schluessel === '' ? '' : LSV07S_Tabellen::t( $schluessel );
    }

    /** Sind die Tabellen dieser Sparte überhaupt angelegt? */
    public static function da( $sparte ) {
        if ( ! self::gibt( $sparte ) ) return false;
        foreach ( [ 't_gruppen', 't_sportler', 't_anw' ] as $f ) {
            if ( ! LSV07S_Tabellen::da( self::feld( $sparte, $f ) ) ) return false;
        }
        return true;
    }

    /** Das Recht, das in dieser Sparte gilt: 'anw.read' → 'schwimmen.anw.read'. */
    public static function recht( $sparte, $kurz ) {
        return self::feld( $sparte, 'recht_praefix' ) . $kurz;
    }

    /**
     * Die Funktion einer Sparte: 'anwesenheit' → 'schwimmen/anwesenheit'.
     *
     * Das ist derselbe Schlüssel, der in der Adresse steht und unter dem
     * der Schalter liegt (siehe LSV07S_Schalter). Eine Stelle, drei
     * Sparten.
     */
    public static function funktion( $sparte, $teil ) {
        return $sparte . '/' . $teil;
    }

    /**
     * Die Funktion an der Gruppe — sie heisst je Sparte anders:
     * 'schwimmen/mannschaft', 'triathlon/gruppe', 'fitness/gruppe'.
     */
    public static function funktion_gruppe( $sparte ) {
        return $sparte . '/' . self::feld( $sparte, 'recht_gruppe' );
    }

    /**
     * Das Recht an der Gruppe: 'create' → 'schwimmen.mannschaft.create'
     * bzw. 'triathlon.gruppe.create'.
     */
    public static function recht_gruppe( $sparte, $was ) {
        return self::recht( $sparte, self::feld( $sparte, 'recht_gruppe' ) . '.' . $was );
    }

    /**
     * Das Recht am Sportler: 'update' → 'schwimmen.schwimmer.update'
     * bzw. 'triathlon.sportler.update'.
     */
    public static function recht_sportler( $sparte, $was ) {
        return self::recht( $sparte, self::feld( $sparte, 'recht_sportler' ) . '.' . $was );
    }

    /** „Schwimmer" oder „Sportler" — damit die Oberfläche je Sparte richtig redet. */
    public static function wort( $sparte, $was = 'einer' ) {
        return self::feld( $sparte, $was );
    }
}
