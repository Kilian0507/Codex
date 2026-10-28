<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Anwesenheit — eine Quelle, sechs Zustände, drei Sparten.
 *
 * Im internen Bereich gibt es zwei Anwesenheitswelten. Das
 * Mannschaftsbuch führt sechs Zustände, Verspätung mit Grund, Vermerke
 * und Aufgaben. Die ältere Anwesenheit führt drei Zustände. Akte, Profil,
 * Startseite und Saisonbericht lesen ausschliesslich die ältere — in
 * diesen vier Dateien kommt `mb_anwesenheit` nicht ein einziges Mal vor.
 * Alles, was im Buch erfasst wird, ist ausserhalb des Buches unsichtbar:
 * Krank, beurlaubt, zehn Minuten zu spät, die Aufgabe für nächste Woche.
 *
 * Hier gilt eine Antwort auf „war diese Person an diesem Tag da". Das
 * Buch ist die Wahrheit; wo es nichts sagt, wird der Altbestand gelesen
 * und auf die sechs Zustände hochgerechnet. Geschrieben wird in BEIDE
 * Tabellen — nicht aus Bequemlichkeit, sondern weil der interne Bereich
 * weiterläuft und seine Ansichten sonst leer blieben. Zwei Schreibwege
 * wären ein Fehler; einer, der zwei Tabellen führt, ist eine Brücke.
 *
 * Triathlon und Fitness bekommen dieselbe Fassung. Dass ihre Tabellen nur
 * drei Zustände speichern, ändert nichts an der Frage — nur an dem, was
 * beim Speichern ankommt, und das steht hier an einer Stelle.
 */
class LSV07S_Anwesenheit {

    /** Die sechs Zustände. Dieselben Schlüssel wie im Mannschaftsbuch. */
    const ZUSTAENDE = [
        'anwesend'       => 'Anwesend',
        'abwesend'       => 'Abwesend',
        'krank'          => 'Krank',
        'entschuldigt'   => 'Entschuldigt',
        'beurlaubt'      => 'Beurlaubt',
        'unentschuldigt' => 'Unentschuldigt',
    ];

    /** Sechs Zustände → die drei der älteren Tabelle. */
    const AUF_DREI = [
        'anwesend'       => 'anwesend',
        'abwesend'       => 'abwesend',
        'krank'          => 'entschuldigt',
        'entschuldigt'   => 'entschuldigt',
        'beurlaubt'      => 'entschuldigt',
        'unentschuldigt' => 'abwesend',
    ];

    /** Die drei → sechs. Mehr ist aus drei Zuständen nicht zu holen. */
    const AUF_SECHS = [
        'anwesend'     => 'anwesend',
        'abwesend'     => 'abwesend',
        'entschuldigt' => 'entschuldigt',
    ];

    /** Zählt dieser Zustand als „war da"? */
    public static function war_da( $zustand ) {
        return (string) $zustand === 'anwesend';
    }

    /** Zählt dieser Zustand als entschuldigt? */
    public static function entschuldigt( $zustand ) {
        return in_array( (string) $zustand, [ 'krank', 'entschuldigt', 'beurlaubt' ], true );
    }

    public static function zustand_name( $zustand ) {
        return self::ZUSTAENDE[ (string) $zustand ] ?? (string) $zustand;
    }

    public static function zustand_gueltig( $zustand ) {
        return isset( self::ZUSTAENDE[ (string) $zustand ] );
    }

    // ── Welcher Slot gilt an diesem Tag ──────────────────────────────────

    /**
     * Die Trainingszeit, die an diesem Datum gilt — oder null.
     *
     * Eine Leiter, von der genauesten Antwort zur gröbsten, und zwar für
     * alle drei Sparten dieselbe:
     *   1. ein Slot am richtigen Wochentag aus der Saison DIESES Datums,
     *   2. ein Slot am richtigen Wochentag ohne Saison (Altbestand),
     *   3. nichts.
     *
     * Die Saison des Datums, nicht die laufende Saison: Wer im Oktober
     * einen Trainingstag vom September nachträgt, soll die Zeiten von
     * September bekommen. Genau daran hing im internen Bereich der Fehler,
     * dass nach dem Saisonwechsel alte Stunden in der Abrechnung standen.
     */
    public static function slot_fuer( $sparte, $mannschaft_id, $datum ) {
        $d = LSV07S_Datum::rein( $datum );
        if ( $d === null ) return null;
        $wt = LSV07S_Datum::wochentag( $d );

        $saison    = LSV07S_Datum::saison_zu( $d );
        $saison_id = $saison ? (int) $saison['id'] : 0;

        $slots = LSV07S_Mannschaft::slots( $sparte, $mannschaft_id, 0 );   // 0 = ohne Saisonfilter
        $am_tag = array_values( array_filter( $slots, fn( $s ) => $s['wochentag'] === $wt ) );
        if ( $am_tag === [] ) return null;

        if ( $saison_id > 0 ) {
            foreach ( $am_tag as $s ) {
                if ( $s['saison_id'] === $saison_id ) return $s;
            }
        }
        foreach ( $am_tag as $s ) {
            if ( $s['saison_id'] === 0 ) return $s;   // Altbestand gilt in jeder Saison
        }
        return null;
    }

    /**
     * Die Stunden, die ein Trainer für dieses Training bekommt.
     *
     * Die Dauer des Slots — nicht pauschal 2,0 h. Triathlon und Fitness
     * rechneten feste 2,0 h an (`class-ajax-triathlon.php:504`,
     * `class-ajax-fitness.php:507`), Schwimmen die Slotdauer. Für ein
     * 90-Minuten-Training waren das 30 Minuten zu viel, bezahlt, jede
     * Woche. Ohne Slot gibt es 0 Stunden und keine Schätzung.
     */
    public static function trainer_stunden( $sparte, $mannschaft_id, $datum ) {
        $slot = self::slot_fuer( $sparte, $mannschaft_id, $datum );
        return $slot ? (float) $slot['stunden'] : 0.0;
    }

    // ── Ein Tag ──────────────────────────────────────────────────────────

    /**
     * Alles zu einem Trainingstag: Zustände, Verspätungen, Ausfall,
     * Vermerke und Aufgaben.
     */
    public static function tag( $sparte, $mannschaft_id, $datum ) {
        $d = LSV07S_Datum::rein( $datum );
        if ( $d === null ) return null;
        $mannschaft_id = (int) $mannschaft_id;
        $mann = LSV07S_Mannschaft::holen( $sparte, $mannschaft_id );
        if ( ! $mann ) return null;

        $slot     = self::slot_fuer( $sparte, $mannschaft_id, $d );
        $sportler = LSV07S_Mannschaft::sportler( $sparte, $mannschaft_id );
        $stand    = self::stand( $sparte, $mannschaft_id, $d );
        $ausfall  = self::ausfall_stand( $sparte, $mannschaft_id, $d, $slot );

        $liste = [];
        foreach ( $sportler as $s ) {
            $e = $stand[ $s['id'] ] ?? null;
            $liste[] = [
                'id'                => $s['id'],
                'name'              => $s['name'],
                'jahrgang'          => $s['jahrgang'],
                'status'            => $e['status'] ?? '',
                'status_name'       => isset( $e['status'] ) ? self::zustand_name( $e['status'] ) : '',
                'verspaetung'       => (int) ( $e['verspaetung'] ?? 0 ),
                'verspaetung_grund' => (string) ( $e['verspaetung_grund'] ?? '' ),
                'aus_altbestand'    => ! empty( $e['alt'] ),
            ];
        }

        return [
            'sparte'     => $sparte,
            'mannschaft' => $mann,
            'datum'      => $d,
            'datum_de'   => LSV07S_Datum::de( $d ),
            'wochentag'  => LSV07S_Datum::wochentag_name( LSV07S_Datum::wochentag( $d ) ),
            'slot'       => $slot,
            'ausgefallen'=> $ausfall['ausgefallen'],
            'ausfall_grund' => $ausfall['grund'],
            'teilnehmer' => $liste,
            'vermerke'   => self::eintraege( $sparte, $mannschaft_id, $d, 'vermerk' ),
            'aufgaben'   => self::eintraege( $sparte, $mannschaft_id, $d, 'aufgabe' ),
            'darf_aendern' => LSV07S_Rechte::darf_mannschaft( $sparte, $mannschaft_id )
                              && LSV07S_Rechte::darf_eines( [
                                   LSV07S_Rechte::MANNSCHAFTSBUCH_EDIT,
                                   LSV07S_Sparte::recht( $sparte, 'anw.update' ),
                                 ] ),
        ];
    }

    /**
     * Der Stand je Sportler an einem Tag, nach Nummer.
     *
     * Reihenfolge: das Buch, dann der Altbestand. Eine Zeile aus dem
     * Altbestand wird als solche gekennzeichnet — die Oberfläche kann
     * dann sagen „aus der alten Erfassung", statt eine Genauigkeit
     * vorzutäuschen, die die drei Zustände nicht haben.
     */
    public static function stand( $sparte, $mannschaft_id, $datum ) {
        global $wpdb;
        $d    = LSV07S_Datum::rein( $datum );
        $raus = [];
        if ( $d === null ) return $raus;

        if ( LSV07S_Sparte::feld( $sparte, 'hat_buch' ) && LSV07S_Tabellen::da( 'mb_tag' ) ) {
            $tag_id = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT id FROM ' . LSV07S_Tabellen::t( 'mb_tag' )
                . ' WHERE mannschaft_id = %d AND datum = %s LIMIT 1', (int) $mannschaft_id, $d ) );
            if ( $tag_id ) {
                $zeilen = $wpdb->get_results( $wpdb->prepare(
                    'SELECT schwimmer_id, status, verspaetung, verspaetung_grund FROM '
                    . LSV07S_Tabellen::t( 'mb_anwesenheit' ) . ' WHERE tag_id = %d', $tag_id ), ARRAY_A );
                foreach ( (array) $zeilen as $z ) {
                    $raus[ (int) $z['schwimmer_id'] ] = [
                        'status'            => (string) $z['status'],
                        'verspaetung'       => (int) $z['verspaetung'],
                        'verspaetung_grund' => (string) $z['verspaetung_grund'],
                        'alt'               => false,
                    ];
                }
            }
        }

        foreach ( self::stand_alt( $sparte, $mannschaft_id, $d ) as $id => $e ) {
            if ( ! isset( $raus[ $id ] ) ) $raus[ $id ] = $e;
        }
        return $raus;
    }

    /** Der Stand aus der älteren Tabelle, auf sechs Zustände hochgerechnet. */
    private static function stand_alt( $sparte, $mannschaft_id, $datum ) {
        global $wpdb;
        $raus = [];
        $t_anw = LSV07S_Sparte::feld( $sparte, 't_anw' );
        $t_ein = LSV07S_Sparte::feld( $sparte, 't_anw_eintrag' );
        if ( ! LSV07S_Tabellen::da( $t_anw ) || ! LSV07S_Tabellen::da( $t_ein ) ) return $raus;

        $anw  = LSV07S_Tabellen::t( $t_anw );
        $ein  = LSV07S_Tabellen::t( $t_ein );
        $gsp  = LSV07S_Sparte::feld( $sparte, 'gruppe_spalte' );
        $tsp  = LSV07S_Sparte::feld( $sparte, 'teiln_spalte' );
        $ttyp = LSV07S_Sparte::feld( $sparte, 'teiln_typ_spalte' );

        $wo = $ttyp !== '' ? "AND e.$ttyp = 'schwimmer'" : '';
        $zeilen = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.$tsp AS pid, e.status FROM $ein e
               INNER JOIN $anw a ON a.id = e.anwesenheit_id
              WHERE a.$gsp = %d AND a.training_datum = %s $wo",
            (int) $mannschaft_id, $datum ), ARRAY_A );

        foreach ( (array) $zeilen as $z ) {
            $alt = (string) $z['status'];
            $raus[ (int) $z['pid'] ] = [
                'status'            => self::AUF_SECHS[ $alt ] ?? $alt,
                'verspaetung'       => 0,
                'verspaetung_grund' => '',
                'alt'               => true,
            ];
        }
        return $raus;
    }

    // ── Setzen ───────────────────────────────────────────────────────────

    /**
     * Den Zustand einer Person an einem Tag setzen.
     *
     * Schreibt ins Buch und führt den Altbestand mit, damit der interne
     * Bereich weiter stimmt. Beides in einem Aufruf — wer nur eines von
     * beiden schreibt, erzeugt genau die Abweichung, die wir gerade
     * beseitigen.
     *
     * @return true|string true, oder der Satz, der dem Nutzer gesagt wird.
     */
    public static function setzen( $sparte, $mannschaft_id, $datum, $person_id, $zustand,
                                   $verspaetung = 0, $verspaetung_grund = '' ) {
        global $wpdb;
        $mannschaft_id = (int) $mannschaft_id;
        $person_id     = (int) $person_id;
        $d             = LSV07S_Datum::rein( $datum );

        if ( $d === null )  return 'Dieses Datum gibt es nicht.';
        if ( ! LSV07S_Sparte::da( $sparte ) ) return 'Diese Sparte ist nicht eingerichtet.';
        if ( ! self::zustand_gueltig( $zustand ) ) return 'Diesen Zustand gibt es nicht.';
        if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, $mannschaft_id ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }
        /* Ein Training in der Zukunft hat noch nicht stattgefunden. Im
           internen Bereich liess sich das eintragen — und stand dann als
           Anwesenheit in der Statistik, bevor jemand im Wasser war. */
        if ( $d > LSV07S_Datum::heute() ) {
            return 'Dieser Tag liegt in der Zukunft.';
        }
        $grenze = LSV07S_Datum::grenze();
        if ( $grenze !== null && $d < $grenze ) {
            return 'Dieser Tag liegt vor dem Beginn der laufenden Saison.';
        }
        if ( ! self::gehoert_dazu( $sparte, $mannschaft_id, $person_id ) ) {
            return 'Diese Person gehört nicht zu dieser Mannschaft.';
        }

        $verspaetung = max( 0, min( 600, (int) $verspaetung ) );
        if ( ! self::war_da( $zustand ) ) {
            /* Wer nicht da war, kann nicht zu spät gekommen sein. Sonst
               bleibt eine Verspätung stehen, nachdem der Zustand auf
               „krank" geändert wurde. */
            $verspaetung       = 0;
            $verspaetung_grund = '';
        }
        $verspaetung_grund = substr( sanitize_text_field( (string) $verspaetung_grund ), 0, 200 );

        if ( LSV07S_Sparte::feld( $sparte, 'hat_buch' ) ) {
            $tag_id = self::tag_id( $mannschaft_id, $d, true );
            if ( ! $tag_id ) return 'Der Trainingstag konnte nicht angelegt werden.';
            $wpdb->query( $wpdb->prepare(
                'INSERT INTO ' . LSV07S_Tabellen::t( 'mb_anwesenheit' )
                . ' (tag_id, schwimmer_id, status, verspaetung, verspaetung_grund, geaendert_von, geaendert_am)'
                . ' VALUES (%d, %d, %s, %d, %s, %d, %s)'
                . ' ON DUPLICATE KEY UPDATE status = VALUES(status), verspaetung = VALUES(verspaetung),'
                . ' verspaetung_grund = VALUES(verspaetung_grund), geaendert_von = VALUES(geaendert_von),'
                . ' geaendert_am = VALUES(geaendert_am)',
                $tag_id, $person_id, $zustand, $verspaetung, $verspaetung_grund,
                get_current_user_id(), LSV07S_Datum::jetzt() ) );
        }

        self::alt_mitschreiben( $sparte, $mannschaft_id, $d, $person_id, $zustand );

        LSV07S_Log::schreibe( 'anwesenheit.gesetzt', [
            'bereich'   => $sparte,
            'ziel_typ'  => 'person',
            'ziel_id'   => $person_id,
            'ziel_name' => self::person_name( $sparte, $person_id ),
            'details'   => $d . ': ' . self::zustand_name( $zustand )
                         . ( $verspaetung ? ', ' . $verspaetung . ' Min. zu spät' : '' ),
        ] );
        return true;
    }

    /** Gehört diese Person zu dieser Mannschaft? */
    public static function gehoert_dazu( $sparte, $mannschaft_id, $person_id ) {
        foreach ( LSV07S_Mannschaft::sportler( $sparte, $mannschaft_id, false ) as $s ) {
            if ( $s['id'] === (int) $person_id ) return true;
        }
        return false;
    }

    private static function person_name( $sparte, $person_id ) {
        global $wpdb;
        $t  = LSV07S_Sparte::tabelle( $sparte, 't_sportler' );
        $z  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            (int) $person_id ), ARRAY_A );
        return $z ? LSV07S_Person::aus_zeile( $z, $sparte ) : ( 'Nr. ' . (int) $person_id );
    }

    /** Die Nummer des Trainingstags im Buch — notfalls angelegt. */
    private static function tag_id( $mannschaft_id, $datum, $anlegen = false ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'mb_tag' ) ) return 0;
        $t  = LSV07S_Tabellen::t( 'mb_tag' );
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $t WHERE mannschaft_id = %d AND datum = %s LIMIT 1",
            (int) $mannschaft_id, $datum ) );
        if ( $id || ! $anlegen ) return $id;
        $wpdb->insert( $t, [
            'mannschaft_id' => (int) $mannschaft_id,
            'datum'         => $datum,
            'angelegt_von'  => get_current_user_id(),
            'angelegt_am'   => LSV07S_Datum::jetzt(),
            'geaendert_am'  => LSV07S_Datum::jetzt(),
        ], [ '%d','%s','%d','%s','%s' ] );
        return (int) $wpdb->insert_id;
    }

    /** Den Altbestand mitführen, damit der interne Bereich weiter stimmt. */
    private static function alt_mitschreiben( $sparte, $mannschaft_id, $datum, $person_id, $zustand ) {
        global $wpdb;
        $t_anw = LSV07S_Sparte::feld( $sparte, 't_anw' );
        $t_ein = LSV07S_Sparte::feld( $sparte, 't_anw_eintrag' );
        if ( ! LSV07S_Tabellen::da( $t_anw ) || ! LSV07S_Tabellen::da( $t_ein ) ) return;

        $anw  = LSV07S_Tabellen::t( $t_anw );
        $ein  = LSV07S_Tabellen::t( $t_ein );
        $gsp  = LSV07S_Sparte::feld( $sparte, 'gruppe_spalte' );
        $tsp  = LSV07S_Sparte::feld( $sparte, 'teiln_spalte' );
        $ttyp = LSV07S_Sparte::feld( $sparte, 'teiln_typ_spalte' );
        $slot = self::slot_fuer( $sparte, $mannschaft_id, $datum );

        $sitzung = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $anw WHERE $gsp = %d AND training_datum = %s LIMIT 1",
            (int) $mannschaft_id, $datum ) );
        if ( ! $sitzung ) {
            $daten  = [ $gsp => (int) $mannschaft_id, 'training_datum' => $datum,
                        'erstellt_von' => get_current_user_id() ];
            $formate = [ '%d', '%s', '%d' ];
            if ( LSV07S_Mannschaft::spalte_da( $anw, 'slot_id' ) ) {
                $daten['slot_id'] = $slot ? (int) $slot['id'] : 0;
                $formate[] = '%d';
            }
            $wpdb->insert( $anw, $daten, $formate );
            $sitzung = (int) $wpdb->insert_id;
        }
        if ( ! $sitzung ) return;

        $alt_status = self::AUF_DREI[ $zustand ] ?? 'abwesend';
        $spalten    = "(anwesenheit_id, $tsp, status" . ( $ttyp !== '' ? ", $ttyp" : '' ) . ')';
        $werte      = $ttyp !== ''
            ? $wpdb->prepare( 'VALUES (%d, %d, %s, %s)', $sitzung, (int) $person_id, $alt_status, 'schwimmer' )
            : $wpdb->prepare( 'VALUES (%d, %d, %s)', $sitzung, (int) $person_id, $alt_status );
        $wpdb->query( "INSERT INTO $ein $spalten $werte
                       ON DUPLICATE KEY UPDATE status = VALUES(status)" );
    }

    // ── Ausfall ──────────────────────────────────────────────────────────

    /** Ist dieses Training ausgefallen, und warum? */
    public static function ausfall_stand( $sparte, $mannschaft_id, $datum, $slot = null ) {
        global $wpdb;
        $leer = [ 'ausgefallen' => false, 'grund' => '' ];
        $t_anw = LSV07S_Sparte::feld( $sparte, 't_anw' );
        if ( ! LSV07S_Tabellen::da( $t_anw ) ) return $leer;
        $anw = LSV07S_Tabellen::t( $t_anw );
        $gsp = LSV07S_Sparte::feld( $sparte, 'gruppe_spalte' );

        $zeile = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $anw WHERE $gsp = %d AND training_datum = %s LIMIT 1",
            (int) $mannschaft_id, $datum ), ARRAY_A );
        $aus   = $zeile && (int) ( $zeile['ausgefallen'] ?? 0 ) === 1;
        /* Nur Triathlon und Fitness führen den Grund in der Sitzungszeile;
           lsv07i_anwesenheit hat diese Spalte nicht. */
        $grund = (string) ( $zeile['ausfall_grund'] ?? '' );

        /* Schwimmen führt den Ausfall zusätzlich je Slot und Datum in
           lsv07i_training_ausfall — und DORT steht der Grund. Beide Orte
           gelten; gefragt werden deshalb beide, und der Grund kommt von
           dort, wo einer steht. Wer nur die Sitzungszeile liest, bekommt
           bei Schwimmen „ausgefallen, ohne Grund" — und niemand weiss
           mehr, warum. */
        if ( LSV07S_Tabellen::da( 'ausfall' ) && $slot ) {
            $z = $wpdb->get_row( $wpdb->prepare(
                'SELECT grund FROM ' . LSV07S_Tabellen::t( 'ausfall' )
                . ' WHERE slot_id = %d AND training_datum = %s LIMIT 1',
                (int) $slot['id'], $datum ), ARRAY_A );
            if ( $z ) {
                $aus = true;
                if ( $grund === '' ) $grund = (string) $z['grund'];
            }
        }
        return $aus ? [ 'ausgefallen' => true, 'grund' => $grund ] : $leer;
    }

    /**
     * Ein Training absagen oder die Absage zurücknehmen — für einen Tag
     * oder für einen Zeitraum.
     *
     * Der Zeitraum ist neu. Im internen Bereich geht nur ein Datum, und
     * Ferien sind zwei Wochen: zehn Klicks, von denen einer vergessen
     * wird. Begründet wird einmal, und sie steht an jedem Tag.
     */
    public static function ausfall( $sparte, $mannschaft_id, $von, $bis = '', $grund = '', $an = true ) {
        global $wpdb;
        $mannschaft_id = (int) $mannschaft_id;
        $a = LSV07S_Datum::rein( $von );
        $b = LSV07S_Datum::rein( $bis ?: $von );
        if ( $a === null || $b === null ) return 'Dieses Datum gibt es nicht.';
        if ( $b < $a ) [ $a, $b ] = [ $b, $a ];
        if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, $mannschaft_id ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }
        if ( ! LSV07S_Rechte::darf_eines( [ LSV07S_Sparte::recht( $sparte, 'anw.ausfall' ),
                                            LSV07S_Rechte::MANNSCHAFTSBUCH_EDIT ] ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        /* Ein Zeitraum über ein Jahr ist keine Absage mehr, sondern ein
           Versehen — und würde 365 Zeilen schreiben. */
        if ( (int) ( ( strtotime( $b ) - strtotime( $a ) ) / DAY_IN_SECONDS ) > 366 ) {
            return 'Bitte höchstens ein Jahr auf einmal.';
        }
        $grund = substr( sanitize_text_field( (string) $grund ), 0, 255 );
        if ( $an && $grund === '' ) return 'Bitte geben Sie einen Grund an.';

        $t_anw = LSV07S_Sparte::feld( $sparte, 't_anw' );
        if ( ! LSV07S_Tabellen::da( $t_anw ) ) return 'Diese Sparte ist nicht eingerichtet.';
        $anw   = LSV07S_Tabellen::t( $t_anw );
        $gsp   = LSV07S_Sparte::feld( $sparte, 'gruppe_spalte' );
        $hat_grund = LSV07S_Mannschaft::spalte_da( $anw, 'ausfall_grund' );

        $tage = 0;
        for ( $d = $a; $d <= $b; $d = date( 'Y-m-d', strtotime( $d . ' +1 day' ) ) ) {
            $slot = self::slot_fuer( $sparte, $mannschaft_id, $d );
            if ( ! $slot ) continue;   // an diesem Wochentag ist ohnehin kein Training
            $tage++;

            $sitzung = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $anw WHERE $gsp = %d AND training_datum = %s LIMIT 1",
                $mannschaft_id, $d ) );
            if ( ! $sitzung ) {
                $daten   = [ $gsp => $mannschaft_id, 'training_datum' => $d,
                             'erstellt_von' => get_current_user_id() ];
                $formate = [ '%d', '%s', '%d' ];
                if ( LSV07S_Mannschaft::spalte_da( $anw, 'slot_id' ) ) {
                    $daten['slot_id'] = (int) $slot['id'];
                    $formate[] = '%d';
                }
                $wpdb->insert( $anw, $daten, $formate );
                $sitzung = (int) $wpdb->insert_id;
            }
            $setze = [ 'ausgefallen' => $an ? 1 : 0 ];
            $form  = [ '%d' ];
            if ( $hat_grund ) { $setze['ausfall_grund'] = $an ? $grund : ''; $form[] = '%s'; }
            $wpdb->update( $anw, $setze, [ 'id' => $sitzung ], $form, [ '%d' ] );

            if ( LSV07S_Tabellen::da( 'ausfall' ) && LSV07S_Sparte::feld( $sparte, 'hat_buch' ) ) {
                $at = LSV07S_Tabellen::t( 'ausfall' );
                if ( $an ) {
                    $wpdb->query( $wpdb->prepare(
                        "INSERT INTO $at (slot_id, training_datum, grund) VALUES (%d, %s, %s)
                         ON DUPLICATE KEY UPDATE grund = VALUES(grund)",
                        (int) $slot['id'], $d, $grund ) );
                } else {
                    $wpdb->delete( $at, [ 'slot_id' => (int) $slot['id'], 'training_datum' => $d ],
                        [ '%d', '%s' ] );
                }
            }
        }

        if ( $tage === 0 ) return 'In diesem Zeitraum liegt kein Training dieser Mannschaft.';

        LSV07S_Log::schreibe( $an ? 'training.abgesagt' : 'training.absage_zurueck', [
            'bereich'   => $sparte,
            'ziel_typ'  => 'mannschaft',
            'ziel_id'   => $mannschaft_id,
            'ziel_name' => LSV07S_Mannschaft::holen( $sparte, $mannschaft_id )['name'] ?? '',
            'details'   => $a . ( $b !== $a ? ' bis ' . $b : '' ) . ', ' . $tage . ' Training(e)'
                         . ( $an && $grund !== '' ? ': ' . $grund : '' ),
        ] );
        return true;
    }

    // ── Vermerke und Aufgaben ────────────────────────────────────────────

    /** Vermerke oder Aufgaben eines Tages. */
    public static function eintraege( $sparte, $mannschaft_id, $datum, $art = 'vermerk' ) {
        global $wpdb;
        if ( ! LSV07S_Sparte::feld( $sparte, 'hat_buch' ) || ! LSV07S_Tabellen::da( 'mb_eintrag' ) ) {
            return [];
        }
        $tag_id = self::tag_id( (int) $mannschaft_id, $datum, false );
        if ( ! $tag_id ) return [];
        $zeilen = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'mb_eintrag' )
            . ' WHERE tag_id = %d AND art = %s ORDER BY id ASC', $tag_id, $art ), ARRAY_A );
        return array_map( fn( $z ) => [
            'id'          => (int) $z['id'],
            'person_id'   => (int) $z['schwimmer_id'],
            'kategorie'   => (string) $z['kategorie'],
            'text'        => (string) $z['text'],
            'erledigt'    => (int) $z['erledigt'] === 1,
            'von'         => $z['angelegt_von'] ? LSV07S_Person::konto_name( $z['angelegt_von'] ) : '',
            'am'          => LSV07S_Datum::de_zeit( $z['angelegt_am'] ),
        ], (array) $zeilen );
    }

    // ── Auswertung ───────────────────────────────────────────────────────

    /**
     * Die Quote je Sportler in einem Zeitraum — für alle drei Sparten.
     *
     * Triathlon und Fitness hatten keine Auswertung; Schwimmen hatte eine,
     * die nur Summen je Zeitraum liefert. Hier kommt zu den Summen der
     * Verlauf je Monat, weil die eigentliche Frage selten „wie viel
     * insgesamt" ist, sondern „wird es besser oder schlechter".
     *
     * Ausgefallene Trainings zählen nicht als Abwesenheit. Das ist nicht
     * selbstverständlich — im internen Bereich zählt der Saisonbericht
     * sie mit, wenn die Sitzung Einträge hat.
     */
    public static function quote( $sparte, $mannschaft_id, $von, $bis ) {
        $a = LSV07S_Datum::rein( $von );
        $b = LSV07S_Datum::rein( $bis );
        if ( $a === null || $b === null ) return [];
        if ( $b < $a ) [ $a, $b ] = [ $b, $a ];

        $sportler = LSV07S_Mannschaft::sportler( $sparte, $mannschaft_id, false );
        $zahlen   = [];
        foreach ( $sportler as $s ) {
            $zahlen[ $s['id'] ] = [
                'id' => $s['id'], 'name' => $s['name'],
                'anwesend' => 0, 'entschuldigt' => 0, 'unentschuldigt' => 0,
                'verspaetungen' => 0, 'minuten_spaet' => 0,
                'gesamt' => 0, 'quote' => 0.0, 'monate' => [],
            ];
        }

        for ( $d = $a; $d <= $b; $d = date( 'Y-m-d', strtotime( $d . ' +1 day' ) ) ) {
            $slot = self::slot_fuer( $sparte, $mannschaft_id, $d );
            if ( ! $slot ) continue;
            if ( self::ausfall_stand( $sparte, $mannschaft_id, $d, $slot )['ausgefallen'] ) continue;
            $stand = self::stand( $sparte, $mannschaft_id, $d );
            $monat = substr( $d, 0, 7 );

            foreach ( $zahlen as $id => &$z ) {
                /* Die EINE Regel: Gezählt wird nur, wen dieser Tag nennt.
                   Ein nie erfasstes Training wird damit niemandem
                   angerechnet, und wer erst im Oktober dazukam, hat nicht
                   rückwirkend im September gefehlt. */
                if ( ! isset( $stand[ $id ] ) ) continue;
                $st = $stand[ $id ]['status'];
                $z['gesamt']++;
                if ( ! isset( $z['monate'][ $monat ] ) ) {
                    $z['monate'][ $monat ] = [ 'anwesend' => 0, 'gesamt' => 0 ];
                }
                $z['monate'][ $monat ]['gesamt']++;
                if ( self::war_da( $st ) ) {
                    $z['anwesend']++;
                    $z['monate'][ $monat ]['anwesend']++;
                    if ( (int) $stand[ $id ]['verspaetung'] > 0 ) {
                        $z['verspaetungen']++;
                        $z['minuten_spaet'] += (int) $stand[ $id ]['verspaetung'];
                    }
                } elseif ( self::entschuldigt( $st ) ) {
                    $z['entschuldigt']++;
                } else {
                    $z['unentschuldigt']++;
                }
            }
            unset( $z );
        }

        foreach ( $zahlen as &$z ) {
            $z['quote'] = $z['gesamt'] > 0 ? round( $z['anwesend'] / $z['gesamt'] * 100, 1 ) : 0.0;
            $monate = [];
            foreach ( $z['monate'] as $m => $w ) {
                $monate[] = [
                    'monat' => $m,
                    'quote' => $w['gesamt'] > 0 ? round( $w['anwesend'] / $w['gesamt'] * 100, 1 ) : 0.0,
                    'gesamt'=> $w['gesamt'],
                ];
            }
            usort( $monate, fn( $x, $y ) => strcmp( $x['monat'], $y['monat'] ) );
            $z['monate'] = $monate;
        }
        unset( $z );

        $raus = array_values( $zahlen );
        usort( $raus, fn( $x, $y ) => strcmp( $x['name'], $y['name'] ) );
        return $raus;
    }
}
