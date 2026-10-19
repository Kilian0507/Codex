<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Meter und Dauer.
 *
 * Ein Trainingsplan besteht aus Sets, und ein Set steht in der
 * Schwimmsprache als „8 × 50 m Rücken auf 1:00". Daraus lassen sich zwei
 * Zahlen ableiten, die der Trainer sonst im Kopf rechnet:
 *
 *   Setmeter    = Wiederholungen × Strecke
 *   Gesamtmeter = Summe aller Setmeter
 *
 * Bei der Dauer gilt eine Festlegung, die hier einmal getroffen und überall
 * eingehalten wird — sonst kommt bei derselben Eingabe an zwei Stellen eine
 * andere Zahl heraus:
 *
 *   **Intervall ist der Abstand INNERHALB des Sets, Pause ist die Pause
 *   NACH dem Set.**
 *
 *   Setdauer = Wiederholungen × Zyklus + Pause
 *
 * „8 × 50 m auf 1:00, danach 2 Minuten Pause" sind also 8 × 60 + 120 = 600
 * Sekunden. Der Zyklus ist das eingetragene Intervall; steht keines da, tritt
 * ein Richtwert an seine Stelle (siehe unten). Darum heisst das Feld in der
 * Oberfläche ausdrücklich „Pause nach dem Set" und nicht nur „Pause".
 *
 * Zur Gesamtdauer kommt `zusatz_min` des Plans. Das ist die Zeit für
 * Erklärungen, Bahnwechsel und organisatorische Pausen — sie steht am Plan
 * und nicht an den Sets, weil sie sich nicht einem Set zuordnen lässt.
 *
 * Die Richtwerte sind Richtwerte und keine Wahrheit. Sie sagen dem Trainer,
 * ob der Inhalt ungefähr in die Wasserzeit passt. Wer es genau wissen will,
 * trägt Intervalle ein — dann rechnet SwimPlan nicht mehr, sondern zählt.
 */
class LSV07P_Rechnung {

    /**
     * Die Lagen. Sie stehen hier, weil die Dauerrechnung je Lage einen
     * anderen Richtwert braucht; alle anderen Klassen greifen darauf zu,
     * damit es nur eine Liste gibt.
     *
     * `faktor` ist der Richtwert im Verhältnis zu Freistil. `zeit` sagt, ob
     * auf dieser Lage überhaupt Zeiten geführt werden — auf „Technik" gibt
     * es keine Bestzeit.
     */
    const LAGEN = [
        'frei'     => [ 'name' => 'Freistil',       'faktor' => 1.00, 'zeit' => true ],
        'ruecken'  => [ 'name' => 'Rücken',         'faktor' => 1.12, 'zeit' => true ],
        'brust'    => [ 'name' => 'Brust',          'faktor' => 1.20, 'zeit' => true ],
        'delfin'   => [ 'name' => 'Schmetterling',  'faktor' => 1.08, 'zeit' => true ],
        'lagen'    => [ 'name' => 'Lagen',          'faktor' => 1.12, 'zeit' => true ],
        'beine'    => [ 'name' => 'Beine',          'faktor' => 1.45, 'zeit' => false ],
        'arme'     => [ 'name' => 'Arme',           'faktor' => 1.25, 'zeit' => false ],
        'technik'  => [ 'name' => 'Technik',        'faktor' => 1.35, 'zeit' => false ],
        'wechsel'  => [ 'name' => 'Lagenwechsel',   'faktor' => 1.15, 'zeit' => false ],
        'einschwimmen' => [ 'name' => 'Einschwimmen', 'faktor' => 1.25, 'zeit' => false ],
        'ausschwimmen' => [ 'name' => 'Ausschwimmen', 'faktor' => 1.30, 'zeit' => false ],
    ];

    /**
     * Richtwert für 100 m Freistil im Training, in Sekunden. 1:50 ist für
     * eine Jugendmannschaft ein brauchbarer Mittelwert; schnellere oder
     * langsamere Mannschaften stellen ihn um (Verwaltung → Richtwert).
     */
    const RICHTWERT_100 = 110;

    public static function ist_lage( $l ) {
        return isset( self::LAGEN[ $l ] );
    }

    public static function lage_name( $l ) {
        return self::LAGEN[ $l ]['name'] ?? (string) $l;
    }

    /** Nur die Lagen, auf denen Zeiten geführt werden. */
    public static function lagen_mit_zeit() {
        $out = [];
        foreach ( self::LAGEN as $k => $v ) {
            if ( $v['zeit'] ) $out[ $k ] = $v['name'];
        }
        return $out;
    }

    public static function richtwert_100() {
        $w = (int) LSV07P_DB::config( 'richtwert_100', self::RICHTWERT_100 );
        /* Unter 40 s auf 100 m schwimmt niemand, über 5 Minuten rechnet
           sich nichts Sinnvolles mehr. */
        return max( 40, min( 300, $w ) );
    }

    /** Geschätzte Schwimmzeit für eine Strecke in einer Lage, in Sekunden. */
    public static function schwimmzeit( $strecke_m, $lage ) {
        $strecke_m = max( 0, (int) $strecke_m );
        if ( ! $strecke_m ) return 0;
        $faktor = self::LAGEN[ $lage ]['faktor'] ?? 1.0;
        return (int) round( $strecke_m / 100 * self::richtwert_100() * $faktor );
    }

    /**
     * Ein einzelnes Set rechnen.
     *
     * Gibt immer alle Felder zurück, auch bei leerem Set — die Oberfläche
     * soll nicht prüfen müssen, ob ein Schlüssel da ist.
     */
    public static function set( array $s, $bahnlaenge = 25 ) {
        $wdh     = max( 1, (int) ( $s['wiederholungen'] ?? 1 ) );
        $strecke = max( 0, (int) ( $s['strecke_m'] ?? 0 ) );
        $lage    = (string) ( $s['lage'] ?? 'frei' );
        $interv  = max( 0, (int) ( $s['intervall_sek'] ?? 0 ) );
        $pause   = max( 0, (int) ( $s['pause_sek'] ?? 0 ) );

        $meter  = $wdh * $strecke;
        $gesch  = $interv === 0;
        $zyklus = $gesch ? self::schwimmzeit( $strecke, $lage ) : $interv;
        $dauer  = $wdh * $zyklus + $pause;

        /* Ein Intervall, das kürzer ist als die geschätzte Schwimmzeit, ist
           kein Fehler — es ist entweder ein schnelles Set oder ein
           Tippfehler. Gesagt wird es, entschieden nicht. */
        $knapp = ( ! $gesch && $strecke > 0 && $interv > 0
                   && $interv < self::schwimmzeit( $strecke, $lage ) );

        $bahn   = max( 1, (int) $bahnlaenge );
        $bahnen = $strecke > 0 ? $strecke / $bahn : 0;

        return [
            'meter'      => $meter,
            'dauer_sek'  => $dauer,
            'zyklus_sek' => $zyklus,
            'geschaetzt' => $gesch,
            'knapp'      => $knapp,
            'bahnen'     => $bahnen,
            'halbe_bahn' => $strecke > 0 && abs( $bahnen - round( $bahnen ) ) > 0.001,
            'text'       => self::set_text( $wdh, $strecke, $lage, $interv ),
        ];
    }

    /** „8 × 50 m Rücken auf 1:00" — wie es ein Trainer aufschreibt. */
    public static function set_text( $wdh, $strecke, $lage, $intervall = 0 ) {
        $wdh     = max( 1, (int) $wdh );
        $strecke = max( 0, (int) $strecke );
        $t = ( $wdh > 1 ? $wdh . ' × ' : '' ) . $strecke . ' m';
        $n = self::lage_name( $lage );
        if ( $n !== '' ) $t .= ' ' . $n;
        if ( (int) $intervall > 0 ) $t .= ' auf ' . self::mmss( (int) $intervall );
        return $t;
    }

    /**
     * Den ganzen Plan rechnen.
     *
     * `$plan` sind die Spalten der Planzeile (bahnlaenge, dauer_min,
     * zusatz_min), `$sets` die Sets in ihrer Reihenfolge.
     */
    public static function plan( array $plan, array $sets ) {
        $bahn   = max( 1, (int) ( $plan['bahnlaenge'] ?? 25 ) );
        $zusatz = max( 0, (int) ( $plan['zusatz_min'] ?? 0 ) );

        $meter = 0;
        $dauer = 0;
        $zeile = [];
        $je_lage = [];
        $geschaetzt = false;

        foreach ( $sets as $s ) {
            $r      = self::set( $s, $bahn );
            $meter += $r['meter'];
            $dauer += $r['dauer_sek'];
            if ( $r['geschaetzt'] && $r['meter'] > 0 ) $geschaetzt = true;

            $lage = (string) ( $s['lage'] ?? 'frei' );
            if ( ! isset( $je_lage[ $lage ] ) ) $je_lage[ $lage ] = 0;
            $je_lage[ $lage ] += $r['meter'];

            $zeile[] = $r;
        }

        $gesamt = $dauer + $zusatz * 60;

        /* Anteile je Lage, nach Metern absteigend — das ist die Frage, die
           ein Trainer beim Durchsehen stellt: „Wie viel Rücken ist da
           eigentlich drin?" */
        arsort( $je_lage );
        $lagen = [];
        foreach ( $je_lage as $k => $m ) {
            if ( ! $m ) continue;
            $lagen[] = [
                'lage'    => $k,
                'name'    => self::lage_name( $k ),
                'meter'   => $m,
                'prozent' => $meter > 0 ? (int) round( $m / $meter * 100 ) : 0,
            ];
        }

        $soll = max( 0, (int) ( $plan['dauer_min'] ?? 0 ) );
        $ist  = (int) ceil( $gesamt / 60 );

        return [
            'meter'       => $meter,
            'bahnen'      => (int) round( $meter / $bahn ),
            'bahnlaenge'  => $bahn,
            'sets'        => count( $sets ),
            'dauer_sek'   => $gesamt,
            'dauer_min'   => $ist,
            'dauer_text'  => self::hmm( $gesamt ),
            'zusatz_min'  => $zusatz,
            'netto_sek'   => $dauer,
            'geschaetzt'  => $geschaetzt,
            'soll_min'    => $soll,
            'abweichung_min' => $soll > 0 ? $ist - $soll : 0,
            'passt'       => $soll === 0 ? true : $ist <= $soll,
            'lagen'       => $lagen,
            'zeilen'      => $zeile,
        ];
    }

    // ── Zahlen in Text ──────────────────────────────────────────────────

    /** Sekunden als 1:00 oder 0:45. */
    public static function mmss( $sek ) {
        $sek = max( 0, (int) $sek );
        return intdiv( $sek, 60 ) . ':' . sprintf( '%02d', $sek % 60 );
    }

    /** Sekunden als „1 h 35 min" oder „45 min". */
    public static function hmm( $sek ) {
        $min = (int) ceil( max( 0, (int) $sek ) / 60 );
        if ( $min < 60 ) return $min . ' min';
        $h = intdiv( $min, 60 );
        $m = $min % 60;
        return $m ? $h . ' h ' . $m . ' min' : $h . ' h';
    }

    /** 2400 → „2.400 m", 10350 → „10,35 km". */
    public static function meter_text( $m ) {
        $m = max( 0, (int) $m );
        if ( $m < 10000 ) return number_format_i18n( $m ) . ' m';
        return number_format_i18n( $m / 1000, 2 ) . ' km';
    }
}
