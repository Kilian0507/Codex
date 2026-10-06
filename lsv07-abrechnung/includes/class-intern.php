<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Brücke zum internen Bereich.
 *
 * Hier und NUR hier wird auf dessen Tabellen zugegriffen, und zwar
 * ausschließlich lesend. Beide Plugins laufen parallel; der interne Bereich
 * bleibt die Quelle für Anwesenheit, Wettkämpfe, Mannschaften, Saisons und
 * Trainingszeiten, die Abrechnung rechnet damit.
 *
 * Fehlt der interne Bereich oder eine seiner Tabellen, liefert jede Methode
 * eine leere Liste und fehlt() sagt, was fehlt — statt dass die Abrechnung
 * mit einem Datenbankfehler stehen bleibt.
 */
class LSV07A_Intern {

    private static $vorhanden = [];
    private static $fehlend   = [];

    public static function tabelle( $name ) {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    /** Gibt es diese Tabelle? Einmal je Aufruf nachsehen genügt. */
    public static function da( $name ) {
        if ( isset( self::$vorhanden[ $name ] ) ) return self::$vorhanden[ $name ];
        global $wpdb;
        $voll = self::tabelle( $name );
        $treffer = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $voll ) );
        self::$vorhanden[ $name ] = ( $treffer === $voll );
        if ( ! self::$vorhanden[ $name ] ) self::$fehlend[ $name ] = true;
        return self::$vorhanden[ $name ];
    }

    private static function alle_da( array $namen ) {
        foreach ( $namen as $n ) if ( ! self::da( $n ) ) return false;
        return true;
    }

    /** Was beim letzten Zugriff gefehlt hat — für eine verständliche Meldung. */
    public static function fehlt() {
        return array_keys( self::$fehlend );
    }

    public static function hinweis() {
        $f = self::fehlt();
        if ( ! $f ) return '';
        return 'Der interne Bereich ist nicht erreichbar — diese Tabellen fehlen: '
             . implode( ', ', $f ) . '. Ohne sie können Trainings und Wettkämpfe '
             . 'nicht übernommen werden; von Hand erfasste Posten funktionieren weiterhin.';
    }

    // ── Personenzuordnung ────────────────────────────────────────────────
    /**
     * Das Trainer-Profil des internen Bereichs zu einem WordPress-Konto.
     * Dort hängt die Anwesenheit dran. Mehrere Profile am selben Konto sind
     * möglich (Altbestand) — es gewinnt stabil das älteste aktive.
     */
    public static function trainer_id( $wp_user_id ) {
        if ( ! self::da( 'lsv07i_trainer' ) ) return 0;
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM " . self::tabelle( 'lsv07i_trainer' ) . "
              WHERE wp_user_id = %d AND aktiv = 1 ORDER BY id ASC LIMIT 1", (int) $wp_user_id ) );
        return (int) $id;
    }

    public static function mannschaften() {
        if ( ! self::da( 'lsv07_gruppen' ) ) return [];
        global $wpdb;
        $zeilen = $wpdb->get_results(
            "SELECT id, name FROM " . self::tabelle( 'lsv07_gruppen' ) . " ORDER BY name ASC", ARRAY_A );
        if ( $wpdb->last_error ) return [];
        return array_map( fn( $z ) => [ 'id' => (int) $z['id'], 'name' => $z['name'] ], (array) $zeilen );
    }

    // ── Saisons und Trainingszeiten ──────────────────────────────────────
    public static function saisons() {
        if ( ! self::da( 'lsv07i_saisons' ) ) return [];
        global $wpdb;
        $zeilen = $wpdb->get_results(
            "SELECT id, name, start_datum, ende_datum, aktiv
               FROM " . self::tabelle( 'lsv07i_saisons' ) . "
           ORDER BY start_datum DESC, id DESC", ARRAY_A );
        return (array) $zeilen;
    }

    /** Die Saison, in die ein Datum fällt. Null, wenn keine passt. */
    public static function saison_zu_datum( $datum ) {
        foreach ( self::saisons() as $s ) {
            if ( $datum < $s['start_datum'] ) continue;
            if ( ! empty( $s['ende_datum'] ) && $datum > $s['ende_datum'] ) continue;
            return $s;
        }
        return null;
    }

    public static function slots( $saison_id = null ) {
        if ( ! self::alle_da( [ 'lsv07i_training_slots', 'lsv07_gruppen' ] ) ) return [];
        global $wpdb;
        $wo = $saison_id === null ? '' : $wpdb->prepare( 'WHERE s.saison_id = %d', (int) $saison_id );
        $zeilen = $wpdb->get_results(
            "SELECT s.id, s.saison_id, s.mannschaft_id, s.wochentag, s.zeit_von, s.zeit_bis,
                    g.name AS mannschaft_name
               FROM " . self::tabelle( 'lsv07i_training_slots' ) . " s
          LEFT JOIN " . self::tabelle( 'lsv07_gruppen' ) . " g ON g.id = s.mannschaft_id
              $wo
           ORDER BY s.wochentag ASC, s.zeit_von ASC", ARRAY_A );
        if ( $wpdb->last_error ) return [];
        return (array) $zeilen;
    }

    // ── Trainings, in denen die Person anwesend war ──────────────────────
    /**
     * Alle Trainings im Zeitraum, bei denen dieses Trainer-Profil als
     * anwesend eingetragen ist — einschließlich der Springer-Schichten.
     *
     * Die Stunden kommen aus der Trainingszeit des Slots, an dem die
     * Anwesenheit hängt. Das ist zugleich die Absicherung gegen spätere
     * Änderungen: Eine neue Saison bekommt neue Slot-Zeilen, die alte
     * Anwesenheit zeigt weiterhin auf die alten. Fehlt der Slot, kommt
     * stunden = 0 und "zeit_fehlt" — dann kann von Hand nachgetragen werden.
     */
    public static function trainings( $trainer_id, $von, $bis ) {
        $trainer_id = (int) $trainer_id;
        if ( ! $trainer_id ) return [];
        if ( ! self::alle_da( [ 'lsv07i_anwesenheit', 'lsv07i_anwesenheit_eintraege',
                                'lsv07i_training_slots', 'lsv07_gruppen' ] ) ) return [];

        global $wpdb;
        $t_anw   = self::tabelle( 'lsv07i_anwesenheit' );
        $t_eint  = self::tabelle( 'lsv07i_anwesenheit_eintraege' );
        $t_slot  = self::tabelle( 'lsv07i_training_slots' );
        $t_grp   = self::tabelle( 'lsv07_gruppen' );

        $zeilen = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.training_datum, a.mannschaft_id, a.slot_id, a.ausgefallen,
                    g.name AS mannschaft_name,
                    s.zeit_von, s.zeit_bis, s.saison_id
               FROM $t_anw a
               JOIN $t_eint e ON e.anwesenheit_id = a.id
                    AND e.teilnehmer_typ = 'trainer' AND e.teilnehmer_id = %d
                    AND e.status = 'anwesend'
          LEFT JOIN $t_slot s ON s.id = a.slot_id
          LEFT JOIN $t_grp  g ON g.id = a.mannschaft_id
              WHERE a.training_datum BETWEEN %s AND %s AND a.ausgefallen = 0
           ORDER BY a.training_datum ASC, a.id ASC",
            $trainer_id, $von, $bis ), ARRAY_A );
        if ( $wpdb->last_error ) return [];

        $out = [];
        foreach ( (array) $zeilen as $z ) $out[ (int) $z['id'] ] = self::training_aufbereiten( $z, 'anwesenheit' );

        // Springer-Schichten: dort steht der Trainer direkt am Slot und Datum.
        if ( self::da( 'lsv07i_springer' ) ) {
            $t_spr = self::tabelle( 'lsv07i_springer' );
            $spr = $wpdb->get_results( $wpdb->prepare(
                "SELECT sp.id AS springer_id, sp.training_datum, sp.slot_id,
                        s.mannschaft_id, s.zeit_von, s.zeit_bis, s.saison_id,
                        g.name AS mannschaft_name,
                        a.id AS anwesenheit_id, a.ausgefallen
                   FROM $t_spr sp
              LEFT JOIN $t_slot s ON s.id = sp.slot_id
              LEFT JOIN $t_grp  g ON g.id = s.mannschaft_id
              LEFT JOIN $t_anw  a ON a.slot_id = sp.slot_id AND a.training_datum = sp.training_datum
                  WHERE sp.trainer_id = %d AND sp.training_datum BETWEEN %s AND %s
               ORDER BY sp.training_datum ASC",
                $trainer_id, $von, $bis ), ARRAY_A );
            foreach ( (array) $spr as $z ) {
                if ( ! empty( $z['ausgefallen'] ) ) continue;
                $anw_id = (int) ( $z['anwesenheit_id'] ?? 0 );
                if ( $anw_id && isset( $out[ $anw_id ] ) ) continue;   // schon über die Anwesenheit drin
                $z['id'] = $anw_id ?: 0;
                $eintrag = self::training_aufbereiten( $z, 'springer' );
                $eintrag['ref_typ'] = 'springer';
                $eintrag['ref_id']  = (int) $z['springer_id'];
                $out[ 'sp' . $z['springer_id'] ] = $eintrag;
            }
        }

        $liste = array_values( $out );
        usort( $liste, fn( $a, $b ) => strcmp( $a['datum'], $b['datum'] ) );
        return $liste;
    }

    private static function training_aufbereiten( $z, $herkunft ) {
        $stunden = 0.0;
        $zeit_fehlt = true;
        if ( ! empty( $z['zeit_von'] ) && ! empty( $z['zeit_bis'] ) ) {
            $von = self::minuten( $z['zeit_von'] );
            $bis = self::minuten( $z['zeit_bis'] );
            if ( $bis > $von ) {
                $stunden = round( ( $bis - $von ) / 60, 2 );
                $zeit_fehlt = false;
            }
        }
        return [
            'ref_typ'          => 'anwesenheit',
            'ref_id'           => (int) ( $z['id'] ?? 0 ),
            'datum'            => $z['training_datum'],
            'mannschaft_id'    => (int) ( $z['mannschaft_id'] ?? 0 ),
            'mannschaft_name'  => (string) ( $z['mannschaft_name'] ?? '' ),
            'zeit_von'         => (string) ( $z['zeit_von'] ?? '' ),
            'zeit_bis'         => (string) ( $z['zeit_bis'] ?? '' ),
            'saison_id'        => (int) ( $z['saison_id'] ?? 0 ),
            'stunden'          => $stunden,
            'zeit_fehlt'       => $zeit_fehlt,
            'herkunft'         => $herkunft,
        ];
    }

    private static function minuten( $zeit ) {
        $t = explode( ':', (string) $zeit );
        return ( (int) ( $t[0] ?? 0 ) ) * 60 + ( (int) ( $t[1] ?? 0 ) );
    }

    // ── Wettkämpfe ───────────────────────────────────────────────────────
    /**
     * Wettkämpfe im Zeitraum, mit den geplanten Abschnitten je Tag und —
     * falls im internen Bereich erfasst — den Abschnitten, die für dieses
     * Trainer-Profil an dem Tag eingetragen sind. Letztere dienen als
     * Vorschlag; abgerechnet wird, was in der Abrechnung steht.
     */
    public static function wettkaempfe( $trainer_id, $von, $bis ) {
        if ( ! self::alle_da( [ 'lsv07i_wettkampf', 'lsv07i_wettkampf_tage' ] ) ) return [];
        global $wpdb;
        $t_wk  = self::tabelle( 'lsv07i_wettkampf' );
        $t_tag = self::tabelle( 'lsv07i_wettkampf_tage' );

        $tage = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.id AS tag_id, t.wettkampf_id, t.datum, t.abschnitte_plan,
                    w.name, w.ort
               FROM $t_tag t
               JOIN $t_wk w ON w.id = t.wettkampf_id
              WHERE t.datum BETWEEN %s AND %s
           ORDER BY t.datum ASC", $von, $bis ), ARRAY_A );
        if ( $wpdb->last_error ) return [];
        if ( ! $tage ) return [];

        // Vorschlag aus der Wettkampf-Anwesenheit des internen Bereichs
        $eigene = [];
        if ( $trainer_id && self::alle_da( [ 'lsv07i_wettkampf_anwesenheit', 'lsv07i_wettkampf_anw_eintraege' ] ) ) {
            $t_wanw = self::tabelle( 'lsv07i_wettkampf_anwesenheit' );
            $t_wein = self::tabelle( 'lsv07i_wettkampf_anw_eintraege' );
            $roh = $wpdb->get_results( $wpdb->prepare(
                "SELECT wa.wettkampf_tag_id, MAX(we.abschnitte) AS abschnitte
                   FROM $t_wanw wa
                   JOIN $t_wein we ON we.wk_anwesenheit_id = wa.id
                        AND we.teilnehmer_typ = 'trainer' AND we.teilnehmer_id = %d
                        AND we.status = 'anwesend'
               GROUP BY wa.wettkampf_tag_id", (int) $trainer_id ), ARRAY_A );
            foreach ( (array) $roh as $r ) $eigene[ (int) $r['wettkampf_tag_id'] ] = (int) $r['abschnitte'];
        }

        $out = [];
        foreach ( $tage as $t ) {
            $tag_id = (int) $t['tag_id'];
            $out[] = [
                'ref_typ'          => 'wettkampf_tag',
                'ref_id'           => $tag_id,
                'wettkampf_id'     => (int) $t['wettkampf_id'],
                'datum'            => $t['datum'],
                'name'             => $t['name'],
                'ort'              => $t['ort'],
                'abschnitte_plan'  => (int) $t['abschnitte_plan'],
                'abschnitte_eigen' => $eigene[ $tag_id ] ?? 0,
                'war_dabei'        => isset( $eigene[ $tag_id ] ),
            ];
        }
        return $out;
    }
}
