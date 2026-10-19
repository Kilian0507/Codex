<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die PDF-Berichte.
 *
 * Gebaut wird aus denselben Daten, die auch die Ansicht füllt — über
 * LSV07P_Plan und LSV07P_Zeiten, also mit derselben Rechteprüfung. Ein
 * Bericht kann darum nie mehr enthalten als das, was auf dem Bildschirm
 * steht.
 *
 * Aufbau jedes Blattes: ein blaues Kopfband mit Titel und Mannschaft,
 * darunter Abschnitte und Tabellen, unten auf jeder Seite das Datum des
 * Ausdrucks und die Seitenzahl. Ein Trainingsplan auf Papier liegt am
 * Beckenrand und wird nass — darum grosse Zahlen, viel Weissraum und keine
 * Farbe, die etwas sagt, was nicht auch dasteht.
 */
class LSV07P_Bericht {

    /* Seitenmaße in Punkten. */
    const RAND_L = 42;
    const RAND_R = 42;
    const KOPF_H = 64;
    const FUSS_H = 34;

    /* Das Blau der Marke (#0b57d0) in Anteilen. */
    const BLAU = [ 0.043, 0.341, 0.816 ];

    private $pdf;
    private $y;          // laufende Höhe, von OBEN gezählt
    private $seite = 1;
    private $titel = '';
    private $person = '';
    private $unterzeile = '';

    public function __construct( $titel, $person = '', $unterzeile = '' ) {
        $this->pdf        = new LSV07P_PDF();
        $this->titel      = (string) $titel;
        $this->person     = (string) $person;
        $this->unterzeile = (string) $unterzeile;
        $this->kopf();
    }

    private function innen_breite() {
        return LSV07P_PDF::BREITE - self::RAND_L - self::RAND_R;
    }

    /** Von oben gezählte Höhe in die PDF-Koordinate (von unten) umrechnen. */
    private function oy( $abstand_von_oben ) {
        return LSV07P_PDF::HOEHE - $abstand_von_oben;
    }

    private function kopf() {
        $b = LSV07P_PDF::BREITE;
        $this->pdf->flaeche_farbe( 0, $this->oy( self::KOPF_H ), $b, self::KOPF_H,
            self::BLAU[0], self::BLAU[1], self::BLAU[2] );

        $this->pdf->text_farbe( self::RAND_L, $this->oy( 26 ), 'SwimPlan', 15, true, 1, 1, 1 );
        $this->pdf->text_farbe( self::RAND_L, $this->oy( 43 ), $this->titel, 11, false, 1, 1, 1 );

        if ( $this->person !== '' ) {
            $this->pdf->text_farbe(
                $b - self::RAND_R - LSV07P_PDF::breite( $this->person, 11, true ),
                $this->oy( 26 ), $this->person, 11, true, 1, 1, 1 );
        }
        if ( $this->unterzeile !== '' ) {
            $this->pdf->text_farbe(
                $b - self::RAND_R - LSV07P_PDF::breite( $this->unterzeile, 9, false ),
                $this->oy( 43 ), $this->unterzeile, 9, false, 1, 1, 1 );
        }

        $this->y = self::KOPF_H + 26;
    }

    private function fuss() {
        $b = LSV07P_PDF::BREITE;
        $y = $this->oy( LSV07P_PDF::HOEHE - 24 );
        $this->pdf->linie( self::RAND_L, $y + 12, $b - self::RAND_R, $y + 12, 0.5, 0.8 );
        $this->pdf->text( self::RAND_L, $y, 'Ausdruck vom ' . date_i18n( 'd.m.Y' ), 8, false, 0.45 );
        $this->pdf->text_rechts( $b - self::RAND_R, $y, 'Seite ' . $this->seite, 8, false, 0.45 );
    }

    /** Platz prüfen und notfalls umbrechen. */
    private function platz( $braucht ) {
        if ( $this->y + $braucht <= LSV07P_PDF::HOEHE - self::FUSS_H ) return;
        $this->fuss();
        $this->pdf->seite_neu();
        $this->seite++;
        $this->kopf();
    }

    // ── Bausteine ───────────────────────────────────────────────────────

    /**
     * Eine Überschrift. Über ihr steht Luft, sofern sie nicht gerade oben
     * auf einer Seite beginnt — ohne diesen Abstand klebte sie am Absatz
     * davor und man las beides als einen Block.
     */
    public function ueberschrift( $text ) {
        $oben = self::KOPF_H + 26;
        if ( $this->y > $oben ) $this->y += 12;
        $this->platz( 34 );
        $this->pdf->text( self::RAND_L, $this->oy( $this->y ), $text, 12, true );
        $this->y += 7;
        $this->pdf->linie( self::RAND_L, $this->oy( $this->y ),
            LSV07P_PDF::BREITE - self::RAND_R, $this->oy( $this->y ), 0.8, 0.75 );
        $this->y += 15;
    }

    public function absatz( $text, $groesse = 10, $grau = 0.0 ) {
        $zeilen = LSV07P_PDF::umbruch( $text, $this->innen_breite(), $groesse, false );
        foreach ( $zeilen as $z ) {
            $this->platz( $groesse + 4 );
            $this->pdf->text( self::RAND_L, $this->oy( $this->y ), $z, $groesse, false, $grau );
            $this->y += $groesse + 3.5;
        }
        $this->y += 4;
    }

    public function hinweis( $text ) {
        $this->absatz( $text, 9.5, 0.4 );
    }

    public function luft( $hoehe = 8 ) {
        $this->y += $hoehe;
    }

    /**
     * Eine Reihe von Kennzahlen, nebeneinander in grauen Feldern: Meter,
     * Dauer, Sets. Das ist das, was ein Trainer als erstes sucht, wenn er
     * einen Plan in die Hand nimmt.
     */
    public function kennzahlen( array $paare ) {
        if ( ! $paare ) return;
        $this->platz( 46 );

        $innen = $this->innen_breite();
        $n     = count( $paare );
        $luft  = 8;
        $w     = ( $innen - $luft * ( $n - 1 ) ) / $n;

        $this->pdf->flaeche( self::RAND_L, $this->oy( $this->y + 34 ), $innen, 42, 0.955 );

        $x = self::RAND_L;
        foreach ( $paare as $name => $wert ) {
            $this->pdf->text( $x + 8, $this->oy( $this->y + 8 ), (string) $name, 8, false, 0.42 );
            $this->pdf->text( $x + 8, $this->oy( $this->y + 24 ), (string) $wert, 14, true );
            $x += $w + $luft;
        }
        $this->y += 54;
    }

    /**
     * Eine Tabelle. $spalten: [ [ 'name' => …, 'breite' => Anteil,
     * 'rechts' => bool ], … ]; $zeilen: Liste von Listen.
     *
     * Umbrochen wird je Zelle; eine Zeile ist so hoch wie ihre höchste
     * Zelle. Reisst der Platz, bekommt die Fortsetzung auf der nächsten
     * Seite die Kopfzeile noch einmal — sonst stünde dort eine Tabelle ohne
     * Spaltennamen.
     */
    /** Ein leeres Quadrat zum Ankreuzen, aus vier Linien. */
    private function kaestchen( $x, $y, $schrift ) {
        $k = max( 6.0, $schrift - 1.5 );
        $u = $this->oy( $y ) - 0.5;            // Grundlinie der Zeile
        $o = $u + $k;
        $this->pdf->linie( $x, $u, $x + $k, $u, 0.6, 0.35 );
        $this->pdf->linie( $x, $o, $x + $k, $o, 0.6, 0.35 );
        $this->pdf->linie( $x, $u, $x, $o, 0.6, 0.35 );
        $this->pdf->linie( $x + $k, $u, $x + $k, $o, 0.6, 0.35 );
    }

    public function tabelle( array $spalten, array $zeilen, $schrift = 9.5 ) {
        $innen = $this->innen_breite();
        $summe = 0;
        foreach ( $spalten as $s ) $summe += (float) $s['breite'];
        if ( $summe <= 0 ) $summe = 1;

        $x = [];
        $w = [];
        $lauf = self::RAND_L;
        foreach ( $spalten as $i => $s ) {
            $w[ $i ] = $innen * ( (float) $s['breite'] / $summe );
            $x[ $i ] = $lauf;
            $lauf   += $w[ $i ];
        }

        $zeilenhoehe = $schrift + 3.5;

        $kopf = function () use ( $spalten, $x, $w ) {
            $this->platz( 22 );
            $this->pdf->flaeche( self::RAND_L, $this->oy( $this->y + 4 ),
                $this->innen_breite(), 16, 0.93 );
            foreach ( $spalten as $i => $s ) {
                $t = (string) $s['name'];
                if ( ! empty( $s['rechts'] ) ) {
                    $this->pdf->text_rechts( $x[ $i ] + $w[ $i ] - 4, $this->oy( $this->y ),
                        $t, 9, true, 0.25 );
                } else {
                    $this->pdf->text( $x[ $i ] + 4, $this->oy( $this->y ), $t, 9, true, 0.25 );
                }
            }
            $this->y += 18;
        };

        $kopf();

        foreach ( $zeilen as $zeile ) {
            $teile = [];
            $hoch  = 1;
            foreach ( $spalten as $i => $s ) {
                $txt = isset( $zeile[ $i ] ) ? (string) $zeile[ $i ] : '';
                $fett = ! empty( $s['fett'] );
                $teile[ $i ] = LSV07P_PDF::umbruch( $txt, $w[ $i ] - 8, $schrift, $fett );
                $hoch = max( $hoch, count( $teile[ $i ] ) );
            }
            $hoehe = $hoch * $zeilenhoehe + 4;

            $vorher = $this->seite;
            $this->platz( $hoehe );
            if ( $this->seite !== $vorher ) $kopf();   // Fortsetzung bekommt den Kopf

            foreach ( $spalten as $i => $s ) {
                $yy   = $this->y;
                $fett = ! empty( $s['fett'] );
                /* Ein Kästchen zum Abhaken wird GEZEICHNET und nicht
                   geschrieben: Das Zeichen □ gibt es in Helvetica mit
                   cp1252 nicht, und im PDF stand dort ein Fragezeichen.
                   Im Abbild war das zu sehen, in keiner Prüfung. */
                if ( ! empty( $s['kaestchen'] ) ) {
                    if ( ( $zeile[ $i ] ?? '' ) !== '' ) {
                        $this->kaestchen( $x[ $i ] + 4, $yy, $schrift );
                    }
                    continue;
                }
                foreach ( $teile[ $i ] as $z ) {
                    if ( ! empty( $s['rechts'] ) ) {
                        $this->pdf->text_rechts( $x[ $i ] + $w[ $i ] - 4, $this->oy( $yy ),
                            $z, $schrift, $fett );
                    } else {
                        $this->pdf->text( $x[ $i ] + 4, $this->oy( $yy ), $z, $schrift, $fett );
                    }
                    $yy += $zeilenhoehe;
                }
            }

            /* Die Trennlinie gehört in die Lücke ZWISCHEN zwei Zeilen: unter
               die Unterlängen der eigenen und über die Oberlängen der
               nächsten. Vom Fuss der Zeile aus gerechnet lag sie im
               Schwesterplugin einmal mitten im Text der Zeile darunter — im
               PDF selbst war das zu sehen, in den Daten nicht. */
            $linie = $this->y + ( $hoch - 1 ) * $zeilenhoehe + 6;
            $this->pdf->linie( self::RAND_L, $this->oy( $linie ),
                LSV07P_PDF::BREITE - self::RAND_R, $this->oy( $linie ), 0.4, 0.86 );
            $this->y += $hoehe;
        }

        if ( ! $zeilen ) {
            $this->pdf->text( self::RAND_L + 4, $this->oy( $this->y ),
                'Nichts eingetragen.', $schrift, false, 0.45 );
            $this->y += 18;
        }
        $this->y += 8;
    }

    /** Das fertige PDF. */
    public function fertig() {
        $this->fuss();
        return $this->pdf->fertig();
    }

    // ── Die Berichte ────────────────────────────────────────────────────

    /**
     * Ein Trainingsplan.
     *
     * `$fuer_aushang` lässt die internen Notizen weg — das ist dieselbe
     * Unterscheidung, die LSV07P_Plan::voll() trifft, und sie wird hier
     * nicht noch einmal anders getroffen. Der Aushang ist der Zettel, der
     * an die Mannschaft geht; Schwimmer sehen SwimPlan selbst nie.
     */
    public static function plan( $plan_id, $fuer_aushang = false ) {
        $p = LSV07P_Plan::voll( $plan_id, $fuer_aushang );
        if ( ! $p ) return '';

        $unten = $p['datum_text'];
        if ( $p['startzeit'] !== '' ) $unten .= ', ' . $p['startzeit'] . ' Uhr';

        $b = new self(
            $p['titel'] !== '' ? $p['titel'] : 'Trainingsplan',
            self::plan_empfaenger( $p ),
            $unten );

        $b->kennzahlen( [
            'Umfang'     => $p['summe']['meter_text'],
            'Dauer'      => $p['summe']['dauer_text'] . ( $p['summe']['geschaetzt'] ? ' *' : '' ),
            'Bahnlänge'  => $p['bahnlaenge'] . ' m',
            'Sets'       => (string) count( $p['sets'] ),
        ] );

        if ( $p['ziel'] !== '' ) {
            $b->ueberschrift( 'Ziel der Einheit' );
            $b->absatz( $p['ziel'] );
        }

        $b->ueberschrift( 'Ablauf' );
        $spalten = [
            [ 'name' => '',          'breite' => 0.35 ],
            /* Nicht fett: In diese Spalte kommen auch Material und Hinweis,
               und ein dreizeiliger fetter Hinweis erschlägt die Zeile. Wofür
               das Auge die Struktur braucht, sorgen die Nummer links und die
               Zahlenspalten rechts. */
            [ 'name' => 'Set',       'breite' => 2.60 ],
            [ 'name' => 'Umfang',    'breite' => 1.05 ],
            [ 'name' => 'Lage',      'breite' => 1.05 ],
            [ 'name' => 'Intervall', 'breite' => 0.80, 'rechts' => true ],
            [ 'name' => 'Pause',     'breite' => 0.70, 'rechts' => true ],
            [ 'name' => 'Meter',     'breite' => 0.75, 'rechts' => true ],
        ];

        $zeilen = [];
        $nr = 0;
        foreach ( $p['sets'] as $s ) {
            $nr++;
            $inhalt = $s['titel'] !== '' ? $s['titel'] : $s['kurz'];
            $zusatz = [];
            if ( $s['material'] !== '' ) $zusatz[] = 'Material: ' . $s['material'];
            if ( $s['hinweis'] !== '' )  $zusatz[] = $s['hinweis'];
            if ( $zusatz ) $inhalt .= ' — ' . implode( ' · ', $zusatz );

            /* Die Übungen je Wiederholung, jede auf einer eigenen Zeile und
               durchnummeriert. Am Beckenrand ist genau das die Zeile, auf
               die der Trainer zeigt — sie gehört darum untereinander und
               nicht in einen Fliesstext. */
            foreach ( (array) ( $s['uebungen'] ?? [] ) as $i => $u ) {
                $inhalt .= "\n" . ( $i + 1 ) . '. ' . $u;
            }

            $umfang = ( $s['wiederholungen'] > 1 ? $s['wiederholungen'] . ' × ' : '' )
                    . $s['strecke_m'] . ' m';

            $zeilen[] = [
                $nr . '.',
                $inhalt,
                $umfang,
                $s['lage_name'],
                $s['intervall_text'],
                $s['pause_text'],
                number_format_i18n( $s['meter'] ),
            ];
        }
        $b->tabelle( $spalten, $zeilen );

        if ( $p['summe']['lagen'] ) {
            $b->ueberschrift( 'Verteilung' );
            $vz = [];
            foreach ( $p['summe']['lagen'] as $l ) {
                $vz[] = [ $l['name'], number_format_i18n( $l['meter'] ) . ' m',
                          $l['prozent'] . ' %' ];
            }
            $b->tabelle( [
                [ 'name' => 'Lage',    'breite' => 2.0 ],
                [ 'name' => 'Meter',   'breite' => 1.0, 'rechts' => true ],
                [ 'name' => 'Anteil',  'breite' => 1.0, 'rechts' => true ],
            ], $vz );
        }

        /* Für wen die Einheit ist. Auf dem Zettel am Beckenrand ist das die
           Anwesenheitsliste: Namen zum Abhaken, in zwei Spalten, damit auch
           eine grosse Mannschaft auf eine Seite passt. */
        $teilnehmer = $p['teilnehmer'] ?? [];
        if ( $teilnehmer ) {
            $b->ueberschrift( 'Dabei (' . count( $teilnehmer ) . ')' );
            $zeilen = [];
            $halb   = (int) ceil( count( $teilnehmer ) / 2 );
            for ( $i = 0; $i < $halb; $i++ ) {
                $links  = $teilnehmer[ $i ];
                $rechts = $teilnehmer[ $i + $halb ] ?? null;
                $zeilen[] = [
                    'x', $links['name'], $links['jahrgang'] ? (string) $links['jahrgang'] : '',
                    $rechts ? 'x' : '', $rechts ? $rechts['name'] : '',
                    $rechts && $rechts['jahrgang'] ? (string) $rechts['jahrgang'] : '',
                ];
            }
            $b->tabelle( [
                [ 'name' => '',         'breite' => 0.22, 'kaestchen' => true ],
                [ 'name' => 'Name',     'breite' => 1.60 ],
                [ 'name' => 'Jg.',      'breite' => 0.40 ],
                [ 'name' => '',         'breite' => 0.22, 'kaestchen' => true ],
                [ 'name' => 'Name',     'breite' => 1.60 ],
                [ 'name' => 'Jg.',      'breite' => 0.40 ],
            ], $zeilen, 9 );
        }

        if ( ! $fuer_aushang && ! empty( $p['notizen'] ) ) {
            $b->ueberschrift( 'Notizen des Trainers' );
            $b->absatz( $p['notizen'] );
        }

        $b->luft( 4 );
        $fuss = 'Stand: Fassung ' . $p['version'] . ' · ' . $p['status_name']
              . ' · Trainer: ' . $p['trainer'];
        $b->hinweis( $fuss );
        if ( $p['summe']['geschaetzt'] ) {
            $b->hinweis( '* Bei Sets ohne Intervall ist die Dauer geschätzt '
                . '(Richtwert ' . LSV07P_Rechnung::mmss( LSV07P_Rechnung::richtwert_100() )
                . ' auf 100 m Freistil).' );
        }

        return $b->fertig();
    }

    /** Für das Kopfband: für wen die Einheit ist. */
    private static function plan_empfaenger( array $p ) {
        if ( empty( $p['zuordnung'] ) ) return '';
        $namen = [];
        foreach ( $p['zuordnung'] as $z ) $namen[] = $z['name'];
        if ( count( $namen ) > 2 ) {
            return $namen[0] . ' und ' . ( count( $namen ) - 1 ) . ' weitere';
        }
        return implode( ', ', $namen );
    }

    /** Mehrere Pläne hintereinander in einer Datei — der Splitscreen auf Papier. */
    public static function plaene( array $plan_ids, $fuer_aushang = false ) {
        $teile = [];
        foreach ( $plan_ids as $id ) {
            $p = LSV07P_Plan::voll( $id, $fuer_aushang );
            if ( $p ) $teile[] = $p;
        }
        if ( ! $teile ) return '';
        if ( count( $teile ) === 1 ) return self::plan( $teile[0]['id'], $fuer_aushang );

        $von = $teile[0]['datum'];
        $bis = $teile[ count( $teile ) - 1 ]['datum'];
        $b = new self( 'Trainingspläne', '',
            self::de( min( $von, $bis ) ) . ' – ' . self::de( max( $von, $bis ) ) );

        $gesamt = 0;
        foreach ( $teile as $p ) $gesamt += (int) $p['summe']['meter'];

        $b->kennzahlen( [
            'Einheiten' => (string) count( $teile ),
            'Umfang'    => LSV07P_Rechnung::meter_text( $gesamt ),
        ] );

        foreach ( $teile as $p ) {
            $kopf = $p['datum_text'] . ( $p['titel'] !== '' ? ' — ' . $p['titel'] : '' );
            $b->ueberschrift( $kopf );
            $b->hinweis( $p['summe']['meter_text'] . ' · ' . $p['summe']['dauer_text']
                . ' · ' . $p['bahnlaenge'] . '-m-Bahn' );

            $zeilen = [];
            $nr = 0;
            foreach ( $p['sets'] as $s ) {
                $nr++;
                $zeilen[] = [
                    $nr . '.',
                    $s['titel'] !== '' ? $s['titel'] : $s['kurz'],
                    ( $s['wiederholungen'] > 1 ? $s['wiederholungen'] . ' × ' : '' )
                        . $s['strecke_m'] . ' m',
                    $s['lage_name'],
                    $s['intervall_text'],
                    number_format_i18n( $s['meter'] ),
                ];
            }
            $b->tabelle( [
                [ 'name' => '',          'breite' => 0.35 ],
                [ 'name' => 'Set',       'breite' => 2.80 ],
                [ 'name' => 'Umfang',    'breite' => 1.00 ],
                [ 'name' => 'Lage',      'breite' => 1.00 ],
                [ 'name' => 'Intervall', 'breite' => 0.80, 'rechts' => true ],
                [ 'name' => 'Meter',     'breite' => 0.75, 'rechts' => true ],
            ], $zeilen, 9 );
        }

        return $b->fertig();
    }

    /** Die Bestleistungen einer Person. */
    public static function bestzeiten( $schwimmer_id, $nur_offiziell = false ) {
        $name  = LSV07P_Schwimmer::name( $schwimmer_id );
        $liste = LSV07P_Zeiten::bestzeiten( $schwimmer_id, $nur_offiziell );

        $b = new self( 'Bestleistungen', $name,
            $nur_offiziell ? 'nur offizielle Zeiten' : 'alle erfassten Zeiten' );

        $b->hinweis( 'Die Bestzeit ist jeweils die schnellste erfasste Zeit auf dieser '
            . 'Strecke, Lage und Bahnlänge. Sie wird bei jedem Aufruf neu ermittelt — '
            . 'es gibt keine gespeicherte Bestzeit, die veralten könnte.' );

        $zeilen = [];
        foreach ( $liste as $z ) {
            $zeilen[] = [
                $z['strecke_m'] . ' m',
                $z['lage_name'],
                $z['bahnlaenge'] . ' m',
                $z['zeit'],
                self::de( $z['datum'] ),
                $z['wettkampf'] !== '' ? $z['wettkampf']
                    : ( $z['offiziell'] ? '' : 'Training' ),
            ];
        }
        $b->tabelle( [
            [ 'name' => 'Strecke', 'breite' => 0.8 ],
            [ 'name' => 'Lage',    'breite' => 1.3 ],
            [ 'name' => 'Bahn',    'breite' => 0.6 ],
            [ 'name' => 'Zeit',    'breite' => 0.9, 'rechts' => true, 'fett' => true ],
            [ 'name' => 'Datum',   'breite' => 0.9 ],
            [ 'name' => 'Anlass',  'breite' => 2.0 ],
        ], $zeilen );

        return $b->fertig();
    }

    /** Der Verlauf auf einer Strecke. */
    public static function verlauf( $schwimmer_id, $strecke, $lage, $bahn = 25 ) {
        $name  = LSV07P_Schwimmer::name( $schwimmer_id );
        $liste = LSV07P_Zeiten::verlauf( $schwimmer_id, $strecke, $lage, $bahn );
        $was   = (int) $strecke . ' m ' . LSV07P_Rechnung::lage_name( $lage );

        $b = new self( 'Verlauf ' . $was, $name,
            LSV07P_Plan::bahn( $bahn ) . '-m-Bahn' );

        $zeilen = [];
        foreach ( array_reverse( $liste ) as $z ) {
            $zeilen[] = [
                self::de( $z['datum'] ),
                $z['zeit'],
                $z['verbesserung_text'],
                $z['wettkampf'] !== '' ? $z['wettkampf'] : ( $z['offiziell'] ? '' : 'Training' ),
                $z['neue_bestzeit'] ? 'neue Bestzeit' : '',
            ];
        }
        $b->tabelle( [
            [ 'name' => 'Datum',  'breite' => 1.0 ],
            [ 'name' => 'Zeit',   'breite' => 0.9, 'rechts' => true, 'fett' => true ],
            [ 'name' => 'Gewinn', 'breite' => 0.9, 'rechts' => true ],
            [ 'name' => 'Anlass', 'breite' => 2.2 ],
            [ 'name' => '',       'breite' => 1.1 ],
        ], $zeilen );

        return $b->fertig();
    }

    /** Eine Mannschaft: wer dazugehört, und wer sie betreut. */
    public static function mannschaft( $team_id ) {
        $team = LSV07P_Mannschaft::holen( $team_id );
        if ( ! $team ) return '';

        $b = new self( 'Mannschaft', (string) $team['name'] );

        $trainer = [];
        foreach ( LSV07P_Mannschaft::trainer_von_team( $team_id ) as $t ) {
            $trainer[] = LSV07P_Rollen::name( $t );
        }
        $b->ueberschrift( 'Betreuung' );
        $b->absatz( $trainer ? implode( ', ', $trainer ) : 'Keine Trainerin, kein Trainer zugeteilt.' );

        $b->ueberschrift( 'Schwimmerinnen und Schwimmer' );
        $zeilen = [];
        foreach ( LSV07P_Mannschaft::mitglieder( $team_id ) as $m ) {
            $zeilen[] = [
                $m['name'],
                $m['jahrgang'] ? (string) $m['jahrgang'] : '',
                self::de( $m['von'] ),
                $m['aktiv'] ? '' : 'ausgetragen',
            ];
        }
        $b->tabelle( [
            [ 'name' => 'Name',       'breite' => 2.2 ],
            [ 'name' => 'Jahrgang',   'breite' => 0.8 ],
            [ 'name' => 'dabei seit', 'breite' => 1.0 ],
            [ 'name' => '',           'breite' => 0.9 ],
        ], $zeilen );

        return $b->fertig();
    }

    /** Eine Rangliste innerhalb der Mannschaft. */
    public static function rangliste( $team_id, $strecke, $lage, $bahn = 25 ) {
        $team = LSV07P_Mannschaft::holen( $team_id );
        if ( ! $team ) return '';

        $was = (int) $strecke . ' m ' . LSV07P_Rechnung::lage_name( $lage );
        $b   = new self( 'Rangliste ' . $was, (string) $team['name'],
            LSV07P_Plan::bahn( $bahn ) . '-m-Bahn' );

        $zeilen = [];
        foreach ( LSV07P_Zeiten::rangliste( $team_id, $strecke, $lage, $bahn ) as $z ) {
            $zeilen[] = [
                $z['platz'] . '.',
                $z['name'],
                $z['zeit'],
                $z['abstand'],
                self::de( $z['datum'] ),
            ];
        }
        $b->tabelle( [
            [ 'name' => '',        'breite' => 0.4 ],
            [ 'name' => 'Name',    'breite' => 2.2, 'fett' => true ],
            [ 'name' => 'Zeit',    'breite' => 0.9, 'rechts' => true ],
            [ 'name' => 'Abstand', 'breite' => 0.9, 'rechts' => true ],
            [ 'name' => 'Datum',   'breite' => 1.0 ],
        ], $zeilen );

        $b->hinweis( 'Gezählt werden die aktuellen Mitglieder der Mannschaft. '
            . 'Gleiche Zeit bedeutet gleicher Platz.' );

        return $b->fertig();
    }

    // ── Hilfen ──────────────────────────────────────────────────────────

    public static function de( $datum ) {
        $d = substr( (string) $datum, 0, 10 );
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) ) return (string) $datum;
        return $m[3] . '.' . $m[2] . '.' . $m[1];
    }

    public static function de_zeit( $wert ) {
        $s = (string) $wert;
        $d = self::de( $s );
        return strlen( $s ) > 10 ? $d . ', ' . substr( $s, 11, 5 ) . ' Uhr' : $d;
    }

    /**
     * Dateiname. Das Konzept nennt dafür ein Beispiel:
     * `C-Mannschaft_2026-10-19.pdf` — erst, um was es geht, dann das Datum,
     * mit Unterstrich dazwischen. Daran hält sich das hier.
     */
    public static function dateiname( $teil, $datum = '' ) {
        $roh = strtr( (string) $teil, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae',
            'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
        ] );
        $roh = preg_replace( '/[^A-Za-z0-9.-]+/', '-', $roh );
        $roh = trim( (string) $roh, '-' );
        if ( $roh === '' ) $roh = 'SwimPlan';

        $d = LSV07P_Mannschaft::datum( $datum );
        if ( $d === '' ) $d = current_time( 'Y-m-d' );
        return $roh . '_' . $d . '.pdf';
    }
}
