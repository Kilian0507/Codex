<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Mannschaften, Gruppen, Sportler, Trainer — eine Fassung für drei Sparten.
 *
 * Was hier steht, stand im internen Bereich dreimal: einmal für
 * Schwimmen, und zweimal als Kopie für Triathlon und Fitness. Die Kopien
 * sind auseinandergelaufen (feste 2,0 h statt Slotdauer, keine
 * Saisongrenze, keine Statistik). Hier arbeitet eine Fassung mit der
 * Spartenkarte aus LSV07S_Sparte.
 */
class LSV07S_Mannschaft {

    /** Alle Mannschaften oder Gruppen einer Sparte. */
    public static function alle( $sparte ) {
        global $wpdb;
        if ( ! LSV07S_Sparte::da( $sparte ) ) return [];
        $t = LSV07S_Sparte::tabelle( $sparte, 't_gruppen' );
        $zeilen = $wpdb->get_results(
            "SELECT id, name, sort_order FROM $t ORDER BY sort_order ASC, name ASC", ARRAY_A );
        return array_map( fn( $z ) => [
            'id'     => (int) $z['id'],
            'name'   => (string) $z['name'],
            'sparte' => $sparte,
        ], (array) $zeilen );
    }

    /**
     * Die Mannschaften, die dieses Konto sehen darf.
     *
     * Wer alles sieht, bekommt alle. Wer nicht, bekommt die eigenen —
     * und zwar auch dann, wenn er das Leserecht für Mannschaften hat:
     * Ein Leserecht macht keine fremde Mannschaft zur eigenen.
     */
    public static function erlaubte( $sparte, $uid = 0 ) {
        $alle = self::alle( $sparte );
        if ( LSV07S_Rechte::sieht_alles( $sparte, $uid ) ) return $alle;
        if ( LSV07S_Rechte::darf( LSV07S_Rechte::SCHWIMMEN_MANNSCHAFT_READ_ALL, $uid )
             && $sparte === LSV07S_Sparte::SCHWIMMEN ) {
            /* Dieses Recht heisst ausdrücklich „alle Mannschaften EINSEHEN".
               Es öffnet die Liste, nicht die Arbeit darin — das entscheidet
               weiterhin LSV07S_Rechte::darf_mannschaft(). */
            return $alle;
        }
        $eigene = self::eigene_ids( $sparte, $uid );
        return array_values( array_filter( $alle,
            fn( $m ) => in_array( $m['id'], $eigene, true ) ) );
    }

    /** Die Nummern der Mannschaften, in denen dieses Konto Trainer ist. */
    public static function eigene_ids( $sparte, $uid = 0 ) {
        global $wpdb;
        $uid = (int) ( $uid ?: get_current_user_id() );
        if ( ! $uid || ! LSV07S_Sparte::da( $sparte ) ) return [];
        $t_tr  = LSV07S_Sparte::tabelle( $sparte, 't_trainer' );
        $t_tg  = LSV07S_Sparte::tabelle( $sparte, 't_tr_gruppe' );
        $sp_gr = LSV07S_Sparte::feld( $sparte, 'tr_gruppe_gr' );
        if ( ! $t_tr || ! $t_tg ) return [];
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT tg.$sp_gr FROM $t_tg tg INNER JOIN $t_tr t ON t.id = tg.trainer_id
              WHERE t.wp_user_id = %d", $uid ) );
        return array_values( array_unique( array_map( 'absint', (array) $ids ) ) );
    }

    /**
     * Die Sportler einer Mannschaft.
     *
     * Schwimmen kennt die Mehrfachzuordnung (ein Schwimmer kann in zwei
     * Mannschaften stehen) über lsv07i_swimmer_gruppen; Triathlon und
     * Fitness über ihre eigene Zuordnungstabelle. Dieselbe Abfrage, nur
     * andere Spaltennamen — deshalb eine Fassung.
     */
    public static function sportler( $sparte, $mannschaft_id, $nur_aktive = true ) {
        global $wpdb;
        if ( ! LSV07S_Sparte::da( $sparte ) ) return [];
        $t_sp   = LSV07S_Sparte::tabelle( $sparte, 't_sportler' );
        $t_zu   = LSV07S_Sparte::tabelle( $sparte, 't_sp_gruppe' );
        $zu_sp  = LSV07S_Sparte::feld( $sparte, 'sp_gruppe_sp' );
        $zu_gr  = LSV07S_Sparte::feld( $sparte, 'sp_gruppe_gr' );
        $aktiv  = LSV07S_Sparte::feld( $sparte, 'aktiv' );
        $vn     = LSV07S_Sparte::feld( $sparte, 'vorname' );
        $nn     = LSV07S_Sparte::feld( $sparte, 'nachname' );
        $gb     = LSV07S_Sparte::feld( $sparte, 'geburt' );

        $wo = $nur_aktive ? "AND s.$aktiv = 1" : '';
        $zeilen = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.* FROM $t_sp s
               INNER JOIN $t_zu z ON z.$zu_sp = s.id
              WHERE z.$zu_gr = %d $wo
           ORDER BY s.$nn ASC, s.$vn ASC", (int) $mannschaft_id ), ARRAY_A );

        $raus = [];
        foreach ( (array) $zeilen as $z ) {
            $raus[] = [
                'id'        => (int) $z['id'],
                'name'      => LSV07S_Person::aus_zeile( $z, $sparte ),
                'jahrgang'  => LSV07S_Person::jahrgang( $z[ $gb ] ?? null ),
                'attest_bis'=> LSV07S_Sparte::feld( $sparte, 'hat_attest' )
                                 ? ( $z['attest_expires'] ?? null ) : null,
                'dsv_id'    => $z['dsv_id'] ?? '',
            ];
        }
        return $raus;
    }

    /** Eine Mannschaft am Namen — oder null. */
    public static function holen( $sparte, $mannschaft_id ) {
        global $wpdb;
        if ( ! LSV07S_Sparte::da( $sparte ) ) return null;
        $t = LSV07S_Sparte::tabelle( $sparte, 't_gruppen' );
        $z = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name FROM $t WHERE id = %d LIMIT 1", (int) $mannschaft_id ), ARRAY_A );
        if ( ! $z ) return null;
        return [ 'id' => (int) $z['id'], 'name' => (string) $z['name'], 'sparte' => $sparte ];
    }

    /**
     * Die Trainingszeiten einer Mannschaft.
     *
     * Die Saison wird berücksichtigt: Slots einer anderen Saison gehören
     * nicht in die laufende Woche. Slots mit saison_id = 0 sind Altbestand
     * von vor der Saisonumstellung und zählen weiterhin — sie wegzulassen
     * würde bestehende Mannschaften ohne Trainingszeiten dastehen lassen.
     */
    public static function slots( $sparte, $mannschaft_id, $saison_id = null ) {
        global $wpdb;
        if ( ! LSV07S_Sparte::da( $sparte ) ) return [];
        $t   = LSV07S_Sparte::tabelle( $sparte, 't_slots' );
        $gsp = LSV07S_Sparte::feld( $sparte, 'gruppe_spalte' );
        if ( ! LSV07S_Tabellen::da( LSV07S_Sparte::feld( $sparte, 't_slots' ) ) ) return [];

        if ( $saison_id === null ) {
            $s = LSV07S_Datum::saison_aktiv();
            $saison_id = $s ? (int) $s['id'] : 0;
        }
        $zusatz = '';
        if ( $saison_id > 0 && self::spalte_da( $t, 'saison_id' ) ) {
            $zusatz = $wpdb->prepare( ' AND ( saison_id = %d OR saison_id = 0 )', $saison_id );
        }
        $zeilen = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $t WHERE $gsp = %d $zusatz ORDER BY wochentag ASC, zeit_von ASC",
            (int) $mannschaft_id ), ARRAY_A );

        return array_map( fn( $z ) => [
            'id'        => (int) $z['id'],
            'wochentag' => (int) $z['wochentag'],
            'tag_name'  => LSV07S_Datum::wochentag_name( $z['wochentag'] ),
            'von'       => LSV07S_Datum::uhr( $z['zeit_von'] ),
            'bis'       => LSV07S_Datum::uhr( $z['zeit_bis'] ),
            'spanne'    => LSV07S_Datum::spanne( $z['zeit_von'], $z['zeit_bis'] ),
            'stunden'   => LSV07S_Datum::dauer_stunden( $z['zeit_von'], $z['zeit_bis'] ),
            'saison_id' => isset( $z['saison_id'] ) ? (int) $z['saison_id'] : 0,
        ], (array) $zeilen );
    }

    /**
     * Gibt es diese Spalte?
     *
     * saison_id entstand erst nachträglich per ALTER TABLE. Eine Anlage,
     * die den internen Bereich noch nicht nachgezogen hat, hat sie nicht —
     * und eine Abfrage darauf würde die ganze Ansicht leer lassen. Besser
     * ohne Saisonfilter anzeigen als mit Datenbankfehler verschwinden.
     */
    private static $spalten = [];

    public static function spalte_da( $tabelle, $spalte ) {
        $k = $tabelle . '.' . $spalte;
        if ( isset( self::$spalten[ $k ] ) ) return self::$spalten[ $k ];
        global $wpdb;
        $gefunden = $wpdb->get_results( $wpdb->prepare(
            "SHOW COLUMNS FROM $tabelle LIKE %s", $spalte ) );
        return self::$spalten[ $k ] = ! empty( $gefunden );
    }

    /** Für Prüfungen. */
    public static function vergessen() { self::$spalten = []; }
}
