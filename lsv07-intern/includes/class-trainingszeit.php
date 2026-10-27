<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Welche Trainingszeit galt an einem bestimmten Tag?
 *
 * **Warum es diese Klasse gibt.** Die Frage wurde an vier Stellen
 * beantwortet, jede für sich und jede etwas anders: beim Haken „anwesend"
 * an einem Trainer, beim Knopf „Abrechnen" im Mannschaftsbuch, beim
 * Übernehmen in die Abrechnung und noch einmal beim Nachtragen. Drei
 * verschiedene Notzahlen (1,5 / 2,0 / mindestens 0,5) waren das kleinere
 * Problem. Das grössere: **keine davon kannte die Saison.**
 *
 * Der Slot wurde über `mannschaft_id + wochentag` gesucht,
 * `ORDER BY zeit_von ASC LIMIT 1`. Nach einem Saisonwechsel gibt es
 * dieselbe Mannschaft am selben Wochentag aber **zweimal** — einmal mit
 * der alten Zeit, einmal mit der neuen. Beginnen beide zur selben Stunde,
 * entscheidet die Reihenfolge der Datenbank, und das ist in aller Regel
 * die kleinere Nummer: der Slot der ALTEN Saison. Die Anwesenheit hing
 * daran, und die Abrechnung rechnete die alten Stunden — nach der
 * Umstellung genauso wie davor.
 *
 * Deshalb gibt es hier **eine** Antwort, und alle fragen sie. Zwei Wege zu
 * derselben Zahl laufen auseinander, und der, den keine Prüfung begeht,
 * zuerst.
 *
 * Massgeblich ist die Zeit, die an diesem Datum **galt**:
 *
 *   1. Der Slot der Mannschaft in der **Saison des Datums**, am Wochentag
 *      des Datums — das ist die Trainingszeit laut Plan.
 *   2. Sonst der Slot, an dem die Anwesenheit hängt. Ein verlegtes
 *      Training (Ferien, Hallenschliessung) steht dort richtig, auch wenn
 *      der Wochentag nicht passt.
 *   3. Sonst irgendein Slot am passenden Wochentag, ohne Saisonbezug —
 *      für Anlagen, in denen nie Saisons gepflegt wurden.
 *   4. Sonst **gar keine**. Dann sagt die Oberfläche das und die Stunden
 *      werden von Hand eingetragen. Eine erfundene Zahl wäre schlimmer als
 *      eine offene Frage: Sie sähe aus wie ein Ergebnis.
 */
class LSV07I_Trainingszeit {

    private static $slots   = null;
    private static $saisons = null;

    /** Gemerkte Zeiten verwerfen — nötig, sobald ein Slot gespeichert wurde. */
    public static function vergessen() {
        self::$slots   = null;
        self::$saisons = null;
    }

    private static function t( $name ) {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    private static function tabelle_da( $name ) {
        global $wpdb;
        static $bekannt = [];
        if ( isset( $bekannt[ $name ] ) ) return $bekannt[ $name ];
        $voll = self::t( $name );
        return $bekannt[ $name ] =
            (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $voll ) ) === $voll;
    }

    private static function spalte_da( $tabelle, $spalte ) {
        global $wpdb;
        static $bekannt = [];
        $k = $tabelle . '.' . $spalte;
        if ( isset( $bekannt[ $k ] ) ) return $bekannt[ $k ];
        if ( ! self::tabelle_da( $tabelle ) ) return $bekannt[ $k ] = false;
        $sp = $wpdb->get_results( 'SHOW COLUMNS FROM ' . self::t( $tabelle )
            . $wpdb->prepare( ' LIKE %s', $spalte ) );
        return $bekannt[ $k ] = ! empty( $sp );
    }

    // ── Saisons ──────────────────────────────────────────────────────────

    private static function saisons() {
        if ( self::$saisons !== null ) return self::$saisons;
        if ( ! self::tabelle_da( 'lsv07i_saisons' ) ) return self::$saisons = [];
        global $wpdb;
        $zeilen = $wpdb->get_results(
            'SELECT id, name, start_datum, ende_datum, aktiv FROM ' . self::t( 'lsv07i_saisons' )
            . ' ORDER BY start_datum DESC, id DESC', ARRAY_A );
        return self::$saisons = (array) $zeilen;
    }

    /**
     * Die Saison, in die dieses Datum fällt. Null, wenn keine passt.
     *
     * Eine Saison ohne Enddatum läuft weiter — das ist die übliche Form der
     * laufenden Saison, und sie darf nicht durchfallen, nur weil niemand
     * ein Ende eingetragen hat.
     */
    public static function saison_zu_datum( $datum ) {
        $datum = (string) $datum;
        if ( $datum === '' ) return null;
        foreach ( self::saisons() as $s ) {
            if ( empty( $s['start_datum'] ) ) continue;
            if ( $datum < $s['start_datum'] ) continue;
            if ( ! empty( $s['ende_datum'] ) && $datum > $s['ende_datum'] ) continue;
            return $s;
        }
        return null;
    }

    // ── Slots ────────────────────────────────────────────────────────────

    private static function slots() {
        if ( self::$slots !== null ) return self::$slots;
        if ( ! self::tabelle_da( 'lsv07i_training_slots' ) ) return self::$slots = [];
        global $wpdb;
        $hat_saison = self::spalte_da( 'lsv07i_training_slots', 'saison_id' );
        $sp = $hat_saison ? 'saison_id' : '0 AS saison_id';
        $zeilen = $wpdb->get_results(
            "SELECT id, $sp, mannschaft_id, wochentag, zeit_von, zeit_bis
               FROM " . self::t( 'lsv07i_training_slots' ) . "
           ORDER BY wochentag ASC, zeit_von ASC, id ASC", ARRAY_A );
        $nach_id = [];
        foreach ( (array) $zeilen as $z ) $nach_id[ (int) $z['id'] ] = $z;
        return self::$slots = $nach_id;
    }

    private static function minuten( $zeit ) {
        $t = explode( ':', (string) $zeit );
        return ( (int) ( $t[0] ?? 0 ) ) * 60 + ( (int) ( $t[1] ?? 0 ) );
    }

    /** Trägt dieser Slot eine echte Zeitspanne — oder ist er ein Platzhalter? */
    private static function echte_zeit( $s ) {
        if ( empty( $s['zeit_von'] ) || empty( $s['zeit_bis'] ) ) return false;
        return self::minuten( $s['zeit_bis'] ) > self::minuten( $s['zeit_von'] );
    }

    /**
     * An welchem Slot soll eine Anwesenheit an diesem Tag hängen?
     *
     * Wird beim Anlegen gebraucht, wenn nur Mannschaft und Datum bekannt
     * sind. Gibt 0 zurück, wenn es nichts Passendes gibt — dann legt der
     * Aufrufer einen Platzhalter an und weiss, dass er einen anlegt.
     */
    public static function slot_fuer( $mannschaft_id, $datum ) {
        $mannschaft_id = (int) $mannschaft_id;
        if ( ! $mannschaft_id || $datum === '' ) return 0;

        $saison    = self::saison_zu_datum( $datum );
        $saison_id = $saison ? (int) $saison['id'] : 0;
        $wochentag = (int) date( 'N', strtotime( $datum ) );

        $der_mannschaft = [];
        foreach ( self::slots() as $s ) {
            if ( (int) $s['mannschaft_id'] === $mannschaft_id ) $der_mannschaft[] = $s;
        }
        if ( ! $der_mannschaft ) return 0;

        $am_tag = array_values( array_filter( $der_mannschaft,
            fn( $s ) => (int) $s['wochentag'] === $wochentag ) );

        /* Der Wochentag passt: dieselbe Reihenfolge wie bei den Stunden. */
        $wahl = self::waehlen( $am_tag, $saison_id, null );
        if ( $wahl ) return (int) $wahl[0]['id'];

        /* Ein abweichender Tag (Verlegung): irgendein Slot der Mannschaft,
           bevorzugt aus der Saison des Datums. Hier geht es nur noch darum,
           WO die Anwesenheit hängt — die Stunden rechnet `fuer()` ohnehin
           noch einmal nach. */
        $wahl = self::waehlen( $der_mannschaft, $saison_id, null );
        return $wahl ? (int) $wahl[0]['id'] : 0;
    }

    /** Die früheste Zeit, bei Gleichstand die kleinere Nummer. */
    private static function frueheste( array $liste ) {
        usort( $liste, function ( $a, $b ) {
            $c = strcmp( (string) $a['zeit_von'], (string) $b['zeit_von'] );
            return $c !== 0 ? $c : ( (int) $a['id'] <=> (int) $b['id'] );
        } );
        return $liste[0];
    }

    /**
     * Aus mehreren Slots desselben Wochentags den massgeblichen wählen.
     *
     * Die Reihenfolge steht NUR hier, und `slot_fuer()` wie `fuer()` fragen
     * sie beide. Stünde sie zweimal da, liefe sie auseinander — und dann
     * hinge eine Anwesenheit an dem einen Slot, während die Stunden aus dem
     * anderen kämen. Das wäre derselbe Fehler noch einmal, nur schwerer zu
     * finden.
     *
     * @return array|null [ Slot, Quelle, mehrdeutig ]
     */
    private static function waehlen( array $am_tag, $saison_id, $eigen ) {
        if ( ! $am_tag ) return null;

        /* 1. Die Saison des Datums. */
        if ( $saison_id ) {
            $in_saison = array_values( array_filter( $am_tag,
                fn( $s ) => (int) $s['saison_id'] === $saison_id ) );
            if ( $in_saison ) {
                /* Zwei Zeiten am selben Tag (zwei Gruppen nacheinander):
                   die der Anwesenheit hat Vorrang, sonst die frühere. */
                foreach ( $in_saison as $s ) {
                    if ( $eigen && (int) $s['id'] === (int) $eigen['id'] ) {
                        return [ $s, 'plan', count( $in_saison ) > 1 ];
                    }
                }
                return [ self::frueheste( $in_saison ), 'plan', count( $in_saison ) > 1 ];
            }
        }

        /* 2. Ein Slot ohne Saisonbezug. Von dem weiss man wenigstens, dass
              er schon vor den Saisons galt; der einer FREMDEN Saison sagt
              über diesen Tag gar nichts. */
        $ohne_saison = array_values( array_filter( $am_tag,
            fn( $s ) => (int) $s['saison_id'] === 0 ) );
        if ( $ohne_saison ) {
            return [ self::frueheste( $ohne_saison ), 'wochentag', count( $ohne_saison ) > 1 ];
        }

        /* 3. Irgendeiner am passenden Wochentag. Lieber die Zeit einer
              anderen Saison als gar keine. */
        return [ self::frueheste( $am_tag ), 'wochentag', count( $am_tag ) > 1 ];
    }

    /**
     * Die Saison, der ein neu angelegter Slot für dieses Datum gehört.
     *
     * Ohne das bekäme ein Platzhalter `saison_id = 0` und würde später von
     * der Reparatur in `lsv07-intern.php` der ÄLTESTEN Saison zugeschlagen
     * — wieder eine alte Zeit an einem neuen Training.
     */
    public static function saison_id_fuer( $datum ) {
        $s = self::saison_zu_datum( $datum );
        if ( $s ) return (int) $s['id'];
        return class_exists( 'LSV07I_Saison' ) ? (int) LSV07I_Saison::active_id() : 0;
    }

    // ── Die Zeit eines Tages ─────────────────────────────────────────────

    /**
     * Was galt an diesem Tag?
     *
     * @param int    $mannschaft_id
     * @param string $datum    Y-m-d
     * @param int    $slot_id  Der Slot, an dem die Anwesenheit hängt (0 = keiner).
     * @return array zeit_von, zeit_bis, saison_id, stunden, zeit_fehlt,
     *               zeit_quelle ('plan' | 'anwesenheit' | 'wochentag' | 'keine'),
     *               zeit_mehrdeutig
     */
    public static function fuer( $mannschaft_id, $datum, $slot_id = 0 ) {
        $alle  = self::slots();
        $eigen = $alle[ (int) $slot_id ] ?? null;

        $mannschaft_id = (int) $mannschaft_id;
        if ( ! $mannschaft_id && $eigen ) $mannschaft_id = (int) $eigen['mannschaft_id'];

        $saison    = self::saison_zu_datum( $datum );
        $saison_id = $saison ? (int) $saison['id'] : 0;
        $wochentag = $datum !== '' ? (int) date( 'N', strtotime( $datum ) ) : 0;

        $passend = [];
        foreach ( $alle as $s ) {
            if ( (int) $s['mannschaft_id'] !== $mannschaft_id ) continue;
            if ( ! self::echte_zeit( $s ) ) continue;
            $passend[] = $s;
        }
        $am_tag = array_values( array_filter( $passend,
            fn( $s ) => (int) $s['wochentag'] === $wochentag ) );

        /* 1. Der Wochentag passt: dieselbe Reihenfolge wie bei der
              Slot-Wahl — Saison des Datums, dann ohne Saisonbezug, dann
              irgendeiner. Nur der Plan-Treffer zählt hier schon; alles
              andere tritt erst hinter dem Slot der Anwesenheit an, denn
              ein verlegtes Training weiss es besser als der Wochentag. */
        $wahl = self::waehlen( $am_tag, $saison_id, $eigen );
        if ( $wahl && $wahl[1] === 'plan' ) {
            return self::paket( $wahl[0], 'plan', $wahl[2] );
        }

        /* 2. Der Slot der Anwesenheit — ein verlegtes Training hängt dort
              richtig, auch wenn der Wochentag nicht passt. */
        if ( $eigen && self::echte_zeit( $eigen ) ) {
            return self::paket( $eigen, 'anwesenheit', false );
        }

        /* 3. Was die Wahl sonst hergab. */
        if ( $wahl ) {
            return self::paket( $wahl[0], $wahl[1], $wahl[2] );
        }

        /* 4. Keine Zeit — ehrlich melden statt raten. */
        return [
            'zeit_von' => '', 'zeit_bis' => '', 'saison_id' => $saison_id,
            'stunden' => 0.0, 'zeit_fehlt' => true,
            'zeit_quelle' => 'keine', 'zeit_mehrdeutig' => false,
        ];
    }

    private static function paket( $s, $quelle, $mehrdeutig ) {
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
     * Nur die Stunden — für die Stellen, die sonst nichts brauchen.
     *
     * Fehlt die Zeit, kommt **0.0** zurück und nicht 1,5 oder 2,0. Die
     * alten Notzahlen waren der Grund, warum eine fehlende Trainingszeit
     * nie auffiel: Es stand immer irgendetwas Plausibles da. Wer 0,0 sieht,
     * trägt nach.
     */
    public static function stunden( $mannschaft_id, $datum, $slot_id = 0 ) {
        $z = self::fuer( $mannschaft_id, $datum, $slot_id );
        return (float) $z['stunden'];
    }
}
