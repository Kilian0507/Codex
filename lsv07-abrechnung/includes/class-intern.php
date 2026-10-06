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
    private static $spalten   = [];
    private static $slots_alle = null;

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

    /**
     * Gibt es diese Spalte? Nötig, weil der interne Bereich seine
     * Trainingszeiten ursprünglich OHNE saison_id angelegt hat — die Spalte
     * kam erst später dazu. Ein SELECT auf eine fehlende Spalte wäre ein
     * Datenbankfehler und hätte die ganze Übernahme stumm leer gelassen.
     */
    public static function spalte_da( $tabelle, $spalte ) {
        $key = $tabelle . '.' . $spalte;
        if ( isset( self::$spalten[ $key ] ) ) return self::$spalten[ $key ];
        if ( ! self::da( $tabelle ) ) return self::$spalten[ $key ] = false;
        global $wpdb;
        $treffer = $wpdb->get_var( $wpdb->prepare(
            'SHOW COLUMNS FROM ' . self::tabelle( $tabelle ) . ' LIKE %s', $spalte ) );
        return self::$spalten[ $key ] = ( $treffer !== null && $treffer !== '' );
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
        $hat_saison = self::spalte_da( 'lsv07i_training_slots', 'saison_id' );
        $sp = $hat_saison ? 's.saison_id' : '0 AS saison_id';
        $wo = ( $saison_id === null || ! $hat_saison )
            ? '' : $wpdb->prepare( 'WHERE s.saison_id = %d', (int) $saison_id );
        $zeilen = $wpdb->get_results(
            "SELECT s.id, $sp, s.mannschaft_id, s.wochentag, s.zeit_von, s.zeit_bis,
                    g.name AS mannschaft_name
               FROM " . self::tabelle( 'lsv07i_training_slots' ) . " s
          LEFT JOIN " . self::tabelle( 'lsv07_gruppen' ) . " g ON g.id = s.mannschaft_id
              $wo
           ORDER BY s.wochentag ASC, s.zeit_von ASC", ARRAY_A );
        if ( $wpdb->last_error ) return [];
        return (array) $zeilen;
    }

    /**
     * Gemerkte Trainingszeiten verwerfen. Nötig, sobald in derselben
     * Anfrage ein Slot gespeichert oder gelöscht wurde.
     */
    public static function cache_leeren() {
        self::$slots_alle = null;
        self::$spalten    = [];
    }

    /** Alle Trainingszeiten, nach Slot-ID. Einmal je Aufruf geladen. */
    private static function slots_alle() {
        if ( self::$slots_alle !== null ) return self::$slots_alle;
        self::$slots_alle = [];
        foreach ( self::slots() as $s ) self::$slots_alle[ (int) $s['id'] ] = $s;
        return self::$slots_alle;
    }

    /** Ein Platzhalter-Slot (00:00–00:00) ist keine Trainingszeit. */
    private static function echte_zeit( $s ) {
        if ( empty( $s['zeit_von'] ) || empty( $s['zeit_bis'] ) ) return false;
        return self::minuten( $s['zeit_bis'] ) > self::minuten( $s['zeit_von'] );
    }

    /**
     * Welche Trainingszeit galt für diese Mannschaft an diesem Tag?
     *
     * Die Slot-ID an der Anwesenheit allein reicht dafür NICHT. Der interne
     * Bereich sucht beim Anlegen einer Anwesenheit den Slot über
     * `mannschaft_id + wochentag` OHNE die Saison zu beachten — nach einem
     * Saisonwechsel gibt es dieselbe Mannschaft am selben Wochentag aber
     * zweimal, und es gewinnt die frühere Uhrzeit, oft die der alten Saison.
     * Findet er gar nichts, nimmt er "irgendeinen Slot" der Mannschaft (auch
     * von einem anderen Wochentag) oder legt einen Platzhalter 00:00–00:00 an.
     * Genau daher kamen die mal zu kurzen, mal zu langen Trainings.
     *
     * Maßgeblich ist deshalb die Zeit, die an diesem Datum GALT:
     *   1. Slot der Mannschaft in der Saison des Datums, am Wochentag des
     *      Datums — das ist die Trainingszeit laut Plan.
     *   2. Sonst der Slot, an dem die Anwesenheit hängt (verlegte Trainings).
     *   3. Sonst ein Slot am passenden Wochentag ohne Saisonbezug
     *      (Anlagen ohne gepflegte Saisons).
     *   4. Sonst gar keine — dann sagt die Oberfläche das und die Stunden
     *      werden von Hand eingetragen, statt still eine Zahl zu erfinden.
     */
    private static function zeit_fuer( $mannschaft_id, $datum, $slot_id ) {
        $alle  = self::slots_alle();
        $eigen = $alle[ (int) $slot_id ] ?? null;
        $mannschaft_id = (int) $mannschaft_id;
        if ( ! $mannschaft_id && $eigen ) $mannschaft_id = (int) $eigen['mannschaft_id'];

        $saison    = self::saison_zu_datum( $datum );
        $saison_id = $saison ? (int) $saison['id'] : 0;
        $wochentag = (int) date( 'N', strtotime( $datum ) );

        $passend = [];
        foreach ( $alle as $s ) {
            if ( (int) $s['mannschaft_id'] !== $mannschaft_id ) continue;
            if ( ! self::echte_zeit( $s ) ) continue;
            $passend[] = $s;
        }

        $nach_tag = array_values( array_filter( $passend,
            fn( $s ) => (int) $s['wochentag'] === $wochentag ) );

        // 1. Saison des Datums und Wochentag des Datums
        if ( $saison_id ) {
            $in_saison = array_values( array_filter( $nach_tag,
                fn( $s ) => (int) $s['saison_id'] === $saison_id ) );
            if ( $in_saison ) {
                // Mehrere Zeiten am selben Tag (zwei Gruppen): die der
                // Anwesenheit hat Vorrang, sonst die frühere.
                foreach ( $in_saison as $s ) {
                    if ( $eigen && (int) $s['id'] === (int) $eigen['id'] ) {
                        return self::zeit_paket( $s, 'plan', count( $in_saison ) > 1 );
                    }
                }
                usort( $in_saison, fn( $a, $b ) => strcmp( $a['zeit_von'], $b['zeit_von'] ) );
                return self::zeit_paket( $in_saison[0], 'plan', count( $in_saison ) > 1 );
            }
        }

        // 2. Der Slot der Anwesenheit — verlegte Trainings hängen dort richtig
        if ( $eigen && self::echte_zeit( $eigen ) ) {
            return self::zeit_paket( $eigen, 'anwesenheit', false );
        }

        // 3. Irgendein Slot am passenden Wochentag (Anlagen ohne Saisons)
        if ( $nach_tag ) {
            usort( $nach_tag, fn( $a, $b ) => strcmp( $a['zeit_von'], $b['zeit_von'] ) );
            return self::zeit_paket( $nach_tag[0], 'wochentag', count( $nach_tag ) > 1 );
        }

        // 4. Keine Zeit — ehrlich melden statt raten
        return [
            'zeit_von' => '', 'zeit_bis' => '', 'saison_id' => $saison_id,
            'stunden' => 0.0, 'zeit_fehlt' => true,
            'zeit_quelle' => 'keine', 'zeit_mehrdeutig' => false,
        ];
    }

    private static function zeit_paket( $s, $quelle, $mehrdeutig ) {
        $minuten = self::minuten( $s['zeit_bis'] ) - self::minuten( $s['zeit_von'] );
        return [
            'zeit_von'        => (string) $s['zeit_von'],
            'zeit_bis'        => (string) $s['zeit_bis'],
            'saison_id'       => (int) $s['saison_id'],
            'stunden'         => round( $minuten / 60, 2 ),
            'zeit_fehlt'      => false,
            'zeit_quelle'     => $quelle,
            'zeit_mehrdeutig' => (bool) $mehrdeutig,
        ];
    }

    /**
     * Abgesagte Trainings. Der interne Bereich führt sie in einer eigenen
     * Tabelle; das Feld `ausgefallen` an der Anwesenheit wird nur
     * mitgezogen, WENN es die Anwesenheitszeile zu dem Zeitpunkt schon gab.
     * Wird erst danach eine angelegt, steht dort 0, obwohl das Training
     * ausfiel — und eine Springer-Schicht an einem abgesagten Tag hat
     * überhaupt keine Anwesenheitszeile. Beides wurde bisher mitgerechnet.
     */
    private static function ausfaelle( $von, $bis ) {
        if ( ! self::da( 'lsv07i_training_ausfall' ) ) return [];
        global $wpdb;
        $zeilen = $wpdb->get_results( $wpdb->prepare(
            "SELECT slot_id, training_datum FROM " . self::tabelle( 'lsv07i_training_ausfall' ) . "
              WHERE training_datum BETWEEN %s AND %s", $von, $bis ), ARRAY_A );
        if ( $wpdb->last_error ) return [];
        $raus = [];
        foreach ( (array) $zeilen as $z ) {
            $raus[ (int) $z['slot_id'] . '|' . $z['training_datum'] ] = true;
        }
        return $raus;
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

        $abgesagt = self::ausfaelle( $von, $bis );

        $zeilen = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.training_datum, a.mannschaft_id, a.slot_id, a.ausgefallen,
                    g.name AS mannschaft_name
               FROM $t_anw a
               JOIN $t_eint e ON e.anwesenheit_id = a.id
                    AND e.teilnehmer_typ = 'trainer' AND e.teilnehmer_id = %d
                    AND e.status = 'anwesend'
          LEFT JOIN $t_grp  g ON g.id = a.mannschaft_id
              WHERE a.training_datum BETWEEN %s AND %s AND a.ausgefallen = 0
           ORDER BY a.training_datum ASC, a.id ASC",
            $trainer_id, $von, $bis ), ARRAY_A );
        if ( $wpdb->last_error ) return [];

        $out = [];
        foreach ( (array) $zeilen as $z ) {
            if ( isset( $abgesagt[ (int) $z['slot_id'] . '|' . $z['training_datum'] ] ) ) continue;
            $out[ (int) $z['id'] ] = self::training_aufbereiten( $z, 'anwesenheit' );
        }

        // Springer-Schichten: dort steht der Trainer direkt am Slot und Datum.
        if ( self::da( 'lsv07i_springer' ) ) {
            $t_spr = self::tabelle( 'lsv07i_springer' );
            $spr = $wpdb->get_results( $wpdb->prepare(
                "SELECT sp.id AS springer_id, sp.training_datum, sp.slot_id,
                        s.mannschaft_id,
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
                if ( isset( $abgesagt[ (int) $z['slot_id'] . '|' . $z['training_datum'] ] ) ) continue;
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
        $zeit = self::zeit_fuer( $z['mannschaft_id'] ?? 0, $z['training_datum'], $z['slot_id'] ?? 0 );
        return array_merge( [
            'ref_typ'          => 'anwesenheit',
            'ref_id'           => (int) ( $z['id'] ?? 0 ),
            'datum'            => $z['training_datum'],
            'mannschaft_id'    => (int) ( $z['mannschaft_id'] ?? 0 ),
            'mannschaft_name'  => (string) ( $z['mannschaft_name'] ?? '' ),
            'herkunft'         => $herkunft,
        ], $zeit );
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
