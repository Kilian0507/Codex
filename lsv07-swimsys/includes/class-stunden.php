<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Meine Stunden: was aus der Anwesenheit folgt.
 *
 * SwimSys baut **keine** Abrechnung. Die gibt es schon zweimal:
 *
 * 1. Im internen Bereich (`lsv07i_abrechnung` und `lsv07i_abr_*`), 31
 *    Endpunkte — die alte Fassung.
 * 2. Im eigenen Plugin **Übungsleiterabrechnung** (`lsv07a_*`), das
 *    genau diese Daten ersetzt und seine Posten direkt aus
 *    `lsv07i_anwesenheit`, `lsv07i_springer` und
 *    `lsv07i_wettkampf_anw_eintraege` ableitet — also aus derselben
 *    Quelle, die SwimSys führt.
 *
 * Eine dritte Fassung wäre genau der Fehler, gegen den dieses Plugin
 * gebaut ist: dieselbe Regel an zwei Stellen, die auseinanderlaufen.
 * Hier steht deshalb nur, **was gezählt wird** — Trainings, Stunden,
 * Wettkampfabschnitte. Kein Stundensatz, kein Betrag, kein Stand
 * „eingereicht": Sätze und Rechnungen gehören der Abrechnung.
 *
 * Der Nutzen bleibt: Ein Trainer sieht vor dem Quartalsende, was in
 * seiner Abrechnung stehen wird, und ob ein Training fehlt. Im internen
 * Bereich sieht er das erst, nachdem die Abrechnung erzeugt wurde.
 */
class LSV07S_Stunden {

    /** Das Trainerprofil des angemeldeten Kontos (oder 0). */
    public static function mein_trainer( $sparte = null, $uid = 0 ) {
        global $wpdb;
        $uid = (int) ( $uid ?: get_current_user_id() );
        if ( ! $uid ) return 0;
        $sparte = $sparte ?: LSV07S_Sparte::SCHWIMMEN;
        $t = LSV07S_Sparte::tabelle( $sparte, 't_trainer' );
        if ( ! $t ) return 0;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $t WHERE wp_user_id = %d ORDER BY id ASC LIMIT 1", $uid ) );
    }

    /**
     * Die Trainings, bei denen dieses Trainerprofil anwesend war.
     *
     * Die Stunden kommen aus der Trainingszeit, an der die Anwesenheit
     * hängt — über dieselbe Leiter wie überall (Saison des Datums, dann
     * die saisonlose Altzeile). Fehlt die Zeit, steht 0 und ein Vermerk;
     * geraten wird nicht.
     */
    public static function trainings( $sparte, $trainer_id, $von, $bis ) {
        global $wpdb;
        $trainer_id = (int) $trainer_id;
        if ( ! $trainer_id ) return [];

        $t_anw  = LSV07S_Sparte::tabelle( $sparte, 't_anw' );
        $t_ein  = LSV07S_Sparte::tabelle( $sparte, 't_anw_eintrag' );
        if ( ! $t_anw || ! $t_ein ) return [];

        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.training_datum, a.mannschaft_id, a.slot_id, a.ausgefallen"
            . " FROM $t_anw a JOIN $t_ein e ON e.anwesenheit_id = a.id"
            . " WHERE e.teilnehmer_typ = 'trainer' AND e.teilnehmer_id = %d"
            . " AND e.status = 'anwesend' AND a.ausgefallen = 0"
            . ' AND a.training_datum BETWEEN %s AND %s'
            . ' ORDER BY a.training_datum ASC, a.id ASC',
            $trainer_id, $von, $bis ), ARRAY_A );

        $raus = [];
        foreach ( $zeilen as $z ) {
            $datum = (string) $z['training_datum'];
            $mann  = (int) $z['mannschaft_id'];
            /* Ein Training, das später als ausgefallen vermerkt wurde,
               zählt nicht — auch wenn die Anwesenheit noch dasteht. */
            $aus = LSV07S_Anwesenheit::ausfall_stand( $sparte, $mann, $datum );
            if ( ! empty( $aus['ausgefallen'] ) ) continue;

            $stunden = LSV07S_Anwesenheit::trainer_stunden( $sparte, $mann, $datum );
            $m = LSV07S_Mannschaft::holen( $sparte, $mann );
            $raus[ 'a' . (int) $z['id'] ] = [
                'art'        => 'training',
                'datum'      => $datum,
                'datum_de'   => LSV07S_Datum::de( $datum ),
                'mannschaft' => $m ? $m['name'] : ( 'Nummer ' . $mann ),
                'stunden'    => $stunden,
                'herkunft'   => 'Anwesenheit',
                'hinweis'    => $stunden > 0 ? ''
                                : 'Für diesen Tag ist keine Trainingszeit hinterlegt.',
            ];
        }

        /* Springer-Schichten: Dort steht der Trainer am Slot und Datum,
           nicht in der Anwesenheitsliste. Wer beides hat, zählt einmal. */
        if ( $sparte === LSV07S_Sparte::SCHWIMMEN && LSV07S_Tabellen::da( 'springer' ) ) {
            $sp = (array) $wpdb->get_results( $wpdb->prepare(
                'SELECT sp.id, sp.training_datum, sp.slot_id, s.mannschaft_id,'
                . ' a.id AS anw_id, a.ausgefallen'
                . ' FROM ' . LSV07S_Tabellen::t( 'springer' ) . ' sp'
                . ' LEFT JOIN ' . LSV07S_Tabellen::t( 'slots' ) . ' s ON s.id = sp.slot_id'
                . ' LEFT JOIN ' . LSV07S_Tabellen::t( 'anwesenheit' ) . ' a'
                . '   ON a.slot_id = sp.slot_id AND a.training_datum = sp.training_datum'
                . ' WHERE sp.trainer_id = %d AND sp.training_datum BETWEEN %s AND %s'
                . ' ORDER BY sp.training_datum ASC',
                $trainer_id, $von, $bis ), ARRAY_A );
            foreach ( $sp as $z ) {
                if ( ! empty( $z['ausgefallen'] ) ) continue;
                $anw = (int) ( $z['anw_id'] ?? 0 );
                if ( $anw && isset( $raus[ 'a' . $anw ] ) ) continue;
                $datum = (string) $z['training_datum'];
                $mann  = (int) ( $z['mannschaft_id'] ?? 0 );
                $aus = LSV07S_Anwesenheit::ausfall_stand( $sparte, $mann, $datum );
                if ( ! empty( $aus['ausgefallen'] ) ) continue;
                $stunden = LSV07S_Anwesenheit::trainer_stunden( $sparte, $mann, $datum );
                $m = LSV07S_Mannschaft::holen( $sparte, $mann );
                $raus[ 's' . (int) $z['id'] ] = [
                    'art'        => 'training',
                    'datum'      => $datum,
                    'datum_de'   => LSV07S_Datum::de( $datum ),
                    'mannschaft' => $m ? $m['name'] : ( 'Nummer ' . $mann ),
                    'stunden'    => $stunden,
                    'herkunft'   => 'Springer',
                    'hinweis'    => $stunden > 0 ? ''
                                    : 'Für diesen Tag ist keine Trainingszeit hinterlegt.',
                ];
            }
        }

        $liste = array_values( $raus );
        usort( $liste, fn( $a, $b ) => strcmp( $a['datum'], $b['datum'] ) );
        return $liste;
    }

    /** Die Wettkampftage, an denen dieses Trainerprofil anwesend war. */
    public static function wettkaempfe( $trainer_id, $von, $bis ) {
        global $wpdb;
        $trainer_id = (int) $trainer_id;
        if ( ! $trainer_id ) return [];
        if ( ! LSV07S_Tabellen::da( 'wk_anw_eintr' ) ) return [];

        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT t.id AS tag_id, t.datum, t.abschnitte_plan, w.name, e.abschnitte'
            . ' FROM ' . LSV07S_Tabellen::t( 'wk_anw_eintr' ) . ' e'
            . ' JOIN ' . LSV07S_Tabellen::t( 'wk_anw' ) . ' a ON a.id = e.wk_anwesenheit_id'
            . ' JOIN ' . LSV07S_Tabellen::t( 'wk_tage' ) . ' t ON t.id = a.wettkampf_tag_id'
            . ' JOIN ' . LSV07S_Tabellen::t( 'wettkampf' ) . ' w ON w.id = t.wettkampf_id'
            . " WHERE e.teilnehmer_typ = 'trainer' AND e.teilnehmer_id = %d"
            . " AND e.status = 'anwesend' AND t.datum BETWEEN %s AND %s"
            . ' ORDER BY t.datum ASC',
            $trainer_id, $von, $bis ), ARRAY_A );

        /* Ein Trainer kann an einem Wettkampftag für zwei Mannschaften
           eingetragen sein — der Tag zählt einmal, mit der höheren
           Abschnittszahl. */
        $nach_tag = [];
        foreach ( $zeilen as $z ) {
            $tag = (int) $z['tag_id'];
            $abschnitte = (int) $z['abschnitte'] > 0
                ? (int) $z['abschnitte'] : (int) $z['abschnitte_plan'];
            if ( isset( $nach_tag[ $tag ] )
                 && $nach_tag[ $tag ]['abschnitte'] >= $abschnitte ) {
                continue;
            }
            $nach_tag[ $tag ] = [
                'art'        => 'wettkampf',
                'datum'      => (string) $z['datum'],
                'datum_de'   => LSV07S_Datum::de( $z['datum'] ),
                'name'       => (string) $z['name'],
                'abschnitte' => $abschnitte,
            ];
        }
        $liste = array_values( $nach_tag );
        usort( $liste, fn( $a, $b ) => strcmp( $a['datum'], $b['datum'] ) );
        return $liste;
    }

    /**
     * Meine Stunden in einem Zeitraum, nach Quartal zusammengefasst.
     *
     * Ohne Zeitraum das laufende Quartal — das ist der Zeitraum, über den
     * man beim Quartalsende redet.
     */
    public static function meine( $sparte = null, $von = '', $bis = '', $trainer_id = 0 ) {
        $sparte = $sparte ?: LSV07S_Sparte::SCHWIMMEN;
        if ( ! LSV07S_Sparte::gibt( $sparte ) || ! LSV07S_Sparte::da( $sparte ) ) {
            return 'Diese Sparte ist nicht eingerichtet.';
        }

        $eigen = LSV07S_Rechte::darf( LSV07S_Rechte::ABRECHNUNG_EIGEN_READ );
        $fremd = LSV07S_Rechte::darf_eines( [ LSV07S_Rechte::ABRECHNUNG_WART_READ,
                                              LSV07S_Rechte::ABRECHNUNG_KASSE_READ ] );
        if ( ! $eigen && ! $fremd ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }

        $ich = self::mein_trainer( $sparte );
        $trainer_id = (int) $trainer_id ?: $ich;
        if ( ! $trainer_id ) {
            return 'Für Ihr Konto ist kein Trainerprofil hinterlegt. Es gibt deshalb '
                 . 'keine Stunden zu zeigen.';
        }
        /* Die Stunden einer ANDEREN Person sieht nur, wer prüft oder die
           Kasse führt — nicht jeder Trainer. */
        if ( $trainer_id !== $ich && ! $fremd ) {
            return 'Sie können nur Ihre eigenen Stunden sehen.';
        }

        $a = LSV07S_Datum::rein( $von );
        $b = LSV07S_Datum::rein( $bis );
        if ( $a === null || $b === null ) {
            $q = LSV07S_Datum::quartal();
            $j = (int) LSV07S_Datum::jahr();
            $spanne = LSV07S_Datum::quartal_spanne( $q, $j );
            $a = $a ?? $spanne[0];
            $b = $b ?? $spanne[1];
        }
        if ( $b < $a ) [ $a, $b ] = [ $b, $a ];

        $trainings  = self::trainings( $sparte, $trainer_id, $a, $b );
        $wettkaempe = $sparte === LSV07S_Sparte::SCHWIMMEN
            ? self::wettkaempfe( $trainer_id, $a, $b ) : [];

        $stunden = 0.0;
        $ohne_zeit = 0;
        foreach ( $trainings as $t ) {
            $stunden += (float) $t['stunden'];
            if ( (float) $t['stunden'] <= 0 ) $ohne_zeit++;
        }
        $abschnitte = 0;
        foreach ( $wettkaempe as $w ) $abschnitte += (int) $w['abschnitte'];

        return [
            'sparte'      => $sparte,
            'trainer_id'  => $trainer_id,
            'name'        => self::trainer_name( $sparte, $trainer_id ),
            'eigene'      => $trainer_id === $ich,
            'von'         => $a,
            'bis'         => $b,
            'quartal'     => LSV07S_Datum::quartal_name( LSV07S_Datum::quartal( $a ),
                                                         (int) LSV07S_Datum::jahr( $a ) ),
            'trainings'   => $trainings,
            'wettkaempfe' => $wettkaempe,
            'summe'       => [
                'trainings'  => count( $trainings ),
                'stunden'    => round( $stunden, 2 ),
                'wettkampf_tage' => count( $wettkaempe ),
                'abschnitte' => $abschnitte,
                'ohne_zeit'  => $ohne_zeit,
            ],
            /* Kein Betrag: Der Stundensatz gehört der Abrechnung, und
               zwei Stellen, die rechnen, rechnen irgendwann verschieden. */
            'hinweis' => 'Gezählt wird, was in der Anwesenheit steht. Abgerechnet wird '
                       . 'in der Übungsleiterabrechnung — dort stehen Sätze, Pauschalen '
                       . 'und der Stand der Rechnung.',
        ];
    }

    private static function trainer_name( $sparte, $trainer_id ) {
        global $wpdb;
        $t = LSV07S_Sparte::tabelle( $sparte, 't_trainer' );
        if ( ! $t ) return '';
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM $t WHERE id = %d LIMIT 1", (int) $trainer_id ) );
    }

    /**
     * Die Stunden aller Trainer eines Quartals — für Prüfung und Kasse.
     *
     * Wieder ohne Geld: Die Liste sagt, wer wie viel erfasst hat, damit
     * ein fehlendes Training auffällt, bevor die Abrechnung daraus
     * entsteht.
     */
    public static function uebersicht( $sparte = null, $quartal = '', $jahr = 0 ) {
        global $wpdb;
        $sparte = $sparte ?: LSV07S_Sparte::SCHWIMMEN;
        if ( ! LSV07S_Rechte::darf_eines( [ LSV07S_Rechte::ABRECHNUNG_WART_READ,
                                            LSV07S_Rechte::ABRECHNUNG_KASSE_READ ] ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        $quartal = preg_match( '/^Q[1-4]$/', (string) $quartal )
            ? $quartal : LSV07S_Datum::quartal();
        $jahr = (int) $jahr ?: (int) LSV07S_Datum::jahr();
        [ $a, $b ] = LSV07S_Datum::quartal_spanne( $quartal, $jahr );

        $t = LSV07S_Sparte::tabelle( $sparte, 't_trainer' );
        if ( ! $t ) return [];
        $trainer = (array) $wpdb->get_results(
            "SELECT id, name FROM $t WHERE aktiv = 1 ORDER BY name ASC", ARRAY_A );

        $zeilen = [];
        foreach ( $trainer as $tr ) {
            $trainings = self::trainings( $sparte, (int) $tr['id'], $a, $b );
            $wk = $sparte === LSV07S_Sparte::SCHWIMMEN
                ? self::wettkaempfe( (int) $tr['id'], $a, $b ) : [];
            $stunden = 0.0;
            $ohne = 0;
            foreach ( $trainings as $x ) {
                $stunden += (float) $x['stunden'];
                if ( (float) $x['stunden'] <= 0 ) $ohne++;
            }
            $abschnitte = 0;
            foreach ( $wk as $x ) $abschnitte += (int) $x['abschnitte'];
            $zeilen[] = [
                'trainer_id' => (int) $tr['id'],
                'name'       => (string) $tr['name'],
                'trainings'  => count( $trainings ),
                'stunden'    => round( $stunden, 2 ),
                'wettkampf_tage' => count( $wk ),
                'abschnitte' => $abschnitte,
                'ohne_zeit'  => $ohne,
            ];
        }
        return [
            'quartal' => $quartal, 'jahr' => $jahr,
            'name' => LSV07S_Datum::quartal_name( $quartal, $jahr ),
            'von' => $a, 'bis' => $b,
            'zeilen' => $zeilen,
        ];
    }
}
