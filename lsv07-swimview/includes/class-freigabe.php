<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Was eine Mannschaft sehen darf.
 *
 * Grundsatz: Nichts ist von sich aus sichtbar. Jeder Bereich steht auf
 * „aus", bis jemand für diese Mannschaft das Häkchen setzt. Ein Plugin, das
 * Daten über Kinder zeigt, darf nicht mit offenen Türen ausgeliefert werden.
 *
 * Gespeichert wird je MANNSCHAFT. Zwei Trainer derselben Mannschaft ändern
 * damit dieselbe Zeile — es gibt nichts abzugleichen, weil es nur eine
 * Einstellung gibt. Das war die Vorgabe („werden die Einstellungen
 * synchronisiert"), und sie ist hier nicht nachgebaut, sondern bauartbedingt
 * erfüllt.
 */
class LSV07S_Freigabe {

    /**
     * Die Bereiche, die freigegeben werden können — Reihenfolge wie im
     * Menü. Der Schlüssel steht in der Datenbank, der Name in beiden
     * Oberflächen.
     *
     * Zwei Texte, weil zwei verschiedene Leute lesen:
     *   `text`   steht im Trainerportal am Häkchen und sagt, WAS man
     *            freigibt — einschliesslich dessen, was gerade nicht
     *            freigegeben wird.
     *   `kachel` steht auf der Kachel des Kindes und sagt, was es dort
     *            findet. Sätze wie „Vermerke zu einzelnen Kindern werden
     *            NIE gezeigt" beantworten dort eine Frage, die niemand
     *            gestellt hat, und machen sie damit erst auf.
     */
    const BEREICHE = [
        'anwesenheit' => [
            'name'   => 'Anwesenheiten',
            'text'   => 'Die eigenen Anwesenheiten aus dem Mannschaftsbuch, mit Verspätungen.',
            'kachel' => 'Wann du da warst — und wann nicht.',
            'icon'   => 'haken',
        ],
        'vermerke' => [
            'name'   => 'Vermerke der Mannschaft',
            'text'   => 'Vermerke, die für die ganze Mannschaft gelten — etwa Trainingsziele. '
                      . 'Vermerke zu einzelnen Kindern werden NIE gezeigt.',
            'kachel' => 'Trainingsziele und Hinweise für die ganze Mannschaft.',
            'icon'   => 'notiz',
        ],
        'aufgaben' => [
            'name'   => 'Aufgaben',
            'text'   => 'Die eigenen Aufgaben und die der ganzen Mannschaft. '
                      . 'Aufgaben anderer Kinder werden nicht gezeigt.',
            'kachel' => 'Was du und was die Mannschaft noch zu tun habt.',
            'icon'   => 'liste',
        ],
        'dokumentation' => [
            'name'   => 'Dokumentation',
            'text'   => 'Was im Training gemacht wurde und was beim nächsten Mal dran ist.',
            'kachel' => 'Was im Training dran war und was als Nächstes kommt.',
            'icon'   => 'buch',
        ],
        'wettkaempfe' => [
            'name'   => 'Wettkämpfe',
            'text'   => 'Anstehende Wettkämpfe mit Ausschreibung, Meldeergebnis, Protokoll und Links. '
                      . 'Nur freigegebene Wettkämpfe, und nur bis zehn Tage nach dem letzten Tag.',
            'kachel' => 'Was ansteht, mit Ausschreibung, Meldeergebnis und Protokoll.',
            'icon'   => 'pokal',
        ],
        'bestzeiten' => [
            'name'   => 'Bestzeiten',
            'text'   => 'Die eigenen Bestzeiten.',
            'kachel' => 'Deine schnellsten Zeiten.',
            'icon'   => 'uhr',
        ],
        'reflexion' => [
            'name'   => 'Reflexionsbogen',
            'text'   => 'Den eigenen Reflexionsbogen der laufenden Saison ausfüllen und abgeben.',
            'kachel' => 'Deinen Bogen für diese Saison ausfüllen.',
            'icon'   => 'stift',
        ],
        'trainingsplan' => [
            'name'   => 'Trainingsplan',
            'text'   => 'Die Trainingspläne, die unten einzeln freigegeben sind.',
            'kachel' => 'Die Pläne, die dein Trainer für dich freigegeben hat.',
            'icon'   => 'plan',
        ],
        'nachrichten' => [
            'name'   => 'Nachrichten',
            'text'   => 'Nachrichten und Dateien, die hier an die Mannschaft oder einzeln geschickt werden.',
            'kachel' => 'Was dein Trainerteam dir schreibt, samt Dateien.',
            'icon'   => 'brief',
        ],
    ];

    public static function ist_bereich( $b ) {
        return isset( self::BEREICHE[ $b ] );
    }

    /**
     * Die Freigaben einer Mannschaft als bereich => bool. Bereiche ohne
     * Eintrag gelten als nicht freigegeben.
     */
    public static function fuer_mannschaft( $mannschaft_id ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $out = [];
        foreach ( array_keys( self::BEREICHE ) as $b ) $out[ $b ] = false;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT bereich, aktiv FROM {$p}lsv07s_freigabe WHERE mannschaft_id = %d",
            (int) $mannschaft_id ), ARRAY_A );
        foreach ( (array) $rows as $r ) {
            if ( isset( $out[ $r['bereich'] ] ) ) $out[ $r['bereich'] ] = ( (int) $r['aktiv'] === 1 );
        }
        return $out;
    }

    /**
     * Die Freigaben für eine Person: Sie ist womöglich in mehreren
     * Mannschaften. Ein Bereich gilt als frei, sobald ihn EINE ihrer
     * Mannschaften freigegeben hat — die Daten dieser Mannschaft darf sie
     * dann auch sehen, die der anderen deshalb nicht.
     *
     * Rückgabe: [ bereich => [ mannschaft_id, … ] ] — leer heisst gesperrt.
     */
    public static function fuer_schwimmer( $schwimmer_id ) {
        $mannschaften = LSV07S_Intern::mannschaften_von_schwimmer( $schwimmer_id );
        $out = [];
        foreach ( array_keys( self::BEREICHE ) as $b ) $out[ $b ] = [];
        foreach ( $mannschaften as $mid ) {
            foreach ( self::fuer_mannschaft( $mid ) as $b => $an ) {
                if ( $an ) $out[ $b ][] = (int) $mid;
            }
        }
        return $out;
    }

    /** Darf diese Person diesen Bereich sehen? */
    public static function darf( $schwimmer_id, $bereich ) {
        if ( ! self::ist_bereich( $bereich ) ) return false;
        $karte = self::fuer_schwimmer( $schwimmer_id );
        return ! empty( $karte[ $bereich ] );
    }

    /**
     * Die Mannschaften, für die dieser Bereich bei dieser Person freigegeben
     * ist. Jede Abfrage von Daten schränkt damit ein — sonst sähe jemand in
     * zwei Mannschaften auch das, was nur eine freigegeben hat.
     */
    public static function mannschaften_fuer( $schwimmer_id, $bereich ) {
        if ( ! self::ist_bereich( $bereich ) ) return [];
        $karte = self::fuer_schwimmer( $schwimmer_id );
        return isset( $karte[ $bereich ] ) ? $karte[ $bereich ] : [];
    }

    /** Setzt ein Häkchen. Die Prüfung, wer das darf, steht im Endpunkt. */
    public static function setzen( $mannschaft_id, $bereich, $aktiv, $user_id = 0 ) {
        global $wpdb;
        if ( ! self::ist_bereich( $bereich ) ) return false;
        $p = $wpdb->prefix;
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$p}lsv07s_freigabe (mannschaft_id, bereich, aktiv, geaendert_von, geaendert_am)
             VALUES (%d, %s, %d, %d, %s)
             ON DUPLICATE KEY UPDATE aktiv = VALUES(aktiv),
                                     geaendert_von = VALUES(geaendert_von),
                                     geaendert_am = VALUES(geaendert_am)",
            (int) $mannschaft_id, $bereich, $aktiv ? 1 : 0, (int) $user_id, current_time( 'mysql' )
        ) );
        return true;
    }

    // ── Trainingspläne ──────────────────────────────────────────────────

    /** Die Plan-IDs, die für diese Mannschaft freigegeben sind. */
    public static function plaene_der_mannschaft( $mannschaft_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT plan_id FROM {$p}lsv07s_plan_freigabe WHERE mannschaft_id = %d",
            (int) $mannschaft_id ) ) );
    }

    public static function plan_setzen( $mannschaft_id, $plan_id, $aktiv, $user_id = 0 ) {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( $aktiv ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$p}lsv07s_plan_freigabe (mannschaft_id, plan_id, freigegeben_von, freigegeben_am)
                 VALUES (%d, %d, %d, %s)
                 ON DUPLICATE KEY UPDATE freigegeben_von = VALUES(freigegeben_von),
                                         freigegeben_am = VALUES(freigegeben_am)",
                (int) $mannschaft_id, (int) $plan_id, (int) $user_id, current_time( 'mysql' ) ) );
        } else {
            $wpdb->delete( $p . 'lsv07s_plan_freigabe',
                [ 'mannschaft_id' => (int) $mannschaft_id, 'plan_id' => (int) $plan_id ],
                [ '%d', '%d' ] );
        }
        return true;
    }
}
