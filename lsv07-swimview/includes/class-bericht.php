<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die PDF-Berichte: zu jeder Ansicht einer.
 *
 * Gebaut wird aus denselben Daten, die auch die Ansicht füllt — über
 * LSV07S_Daten, also mit derselben Freigabeprüfung. Ein Bericht kann darum
 * nie mehr enthalten als das, was auf dem Bildschirm steht.
 *
 * Aufbau jedes Blattes: ein blaues Kopfband mit dem Namen der Person und dem
 * Zeitraum, darunter eine Tabelle oder Abschnitte, unten auf jeder Seite das
 * Datum des Ausdrucks und die Seitenzahl. Mehr nicht — es ist ein Auszug,
 * keine Urkunde.
 */
class LSV07S_Bericht {

    /* Seitenmaße in Punkten. */
    const RAND_L  = 42;
    const RAND_R  = 42;
    const KOPF_H  = 64;
    const FUSS_H  = 34;

    /* Das Blau der Marke (#0b57d0) in Anteilen. */
    const BLAU = [ 0.043, 0.341, 0.816 ];

    private $pdf;
    private $y;          // laufende Höhe, von OBEN gezählt
    private $seite = 1;
    private $titel = '';
    private $person = '';
    private $unterzeile = '';

    public function __construct( $titel, $person, $unterzeile = '' ) {
        $this->pdf        = new LSV07S_PDF();
        $this->titel      = (string) $titel;
        $this->person     = (string) $person;
        $this->unterzeile = (string) $unterzeile;
        $this->kopf();
    }

    private function innen_breite() {
        return LSV07S_PDF::BREITE - self::RAND_L - self::RAND_R;
    }

    /** Von oben gezählte Höhe in die PDF-Koordinate (von unten) umrechnen. */
    private function oy( $abstand_von_oben ) {
        return LSV07S_PDF::HOEHE - $abstand_von_oben;
    }

    private function kopf() {
        $b = LSV07S_PDF::BREITE;
        $this->pdf->flaeche_farbe( 0, $this->oy( self::KOPF_H ), $b, self::KOPF_H,
            self::BLAU[0], self::BLAU[1], self::BLAU[2] );

        $this->pdf->text_farbe( self::RAND_L, $this->oy( 26 ), 'SwimView', 15, true, 1, 1, 1 );
        $this->pdf->text_farbe( self::RAND_L, $this->oy( 43 ), $this->titel, 11, false, 1, 1, 1 );

        if ( $this->person !== '' ) {
            $this->pdf->text_farbe(
                $b - self::RAND_R - LSV07S_PDF::breite( $this->person, 11, true ),
                $this->oy( 26 ), $this->person, 11, true, 1, 1, 1 );
        }
        if ( $this->unterzeile !== '' ) {
            $this->pdf->text_farbe(
                $b - self::RAND_R - LSV07S_PDF::breite( $this->unterzeile, 9, false ),
                $this->oy( 43 ), $this->unterzeile, 9, false, 1, 1, 1 );
        }

        $this->y = self::KOPF_H + 26;
    }

    private function fuss() {
        $b = LSV07S_PDF::BREITE;
        $y = $this->oy( LSV07S_PDF::HOEHE - 24 );
        $this->pdf->linie( self::RAND_L, $y + 12, $b - self::RAND_R, $y + 12, 0.5, 0.8 );
        $this->pdf->text( self::RAND_L, $y, 'Ausdruck vom ' . date_i18n( 'd.m.Y' ), 8, false, 0.45 );
        $this->pdf->text_rechts( $b - self::RAND_R, $y, 'Seite ' . $this->seite, 8, false, 0.45 );
    }

    /** Platz prüfen und notfalls umbrechen. */
    private function platz( $braucht ) {
        if ( $this->y + $braucht <= LSV07S_PDF::HOEHE - self::FUSS_H ) return;
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
            LSV07S_PDF::BREITE - self::RAND_R, $this->oy( $this->y ), 0.8, 0.75 );
        $this->y += 15;
    }

    public function absatz( $text, $groesse = 10, $grau = 0.0 ) {
        $zeilen = LSV07S_PDF::umbruch( $text, $this->innen_breite(), $groesse, false );
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
     * Eine Tabelle. $spalten: [ [ 'name' => …, 'breite' => Anteil,
     * 'rechts' => bool ], … ]; $zeilen: Liste von Listen.
     *
     * Umbrochen wird je Zelle; eine Zeile ist so hoch wie ihre höchste
     * Zelle. Reisst der Platz, bekommt die Fortsetzung auf der nächsten
     * Seite die Kopfzeile noch einmal — sonst stünde dort eine Tabelle ohne
     * Spaltennamen.
     */
    public function tabelle( array $spalten, array $zeilen ) {
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

        $kopf = function () use ( $spalten, $x, $w ) {
            $this->platz( 22 );
            $this->pdf->flaeche( self::RAND_L, $this->oy( $this->y + 4 ),
                $this->innen_breite(), 16, 0.93 );
            foreach ( $spalten as $i => $s ) {
                $t = (string) $s['name'];
                if ( ! empty( $s['rechts'] ) ) {
                    $this->pdf->text_rechts( $x[ $i ] + $w[ $i ] - 4, $this->oy( $this->y ), $t, 9, true, 0.25 );
                } else {
                    $this->pdf->text( $x[ $i ] + 4, $this->oy( $this->y ), $t, 9, true, 0.25 );
                }
            }
            $this->y += 18;
        };

        $kopf();
        $erste_seite = $this->seite;

        foreach ( $zeilen as $zeile ) {
            /* Höhe dieser Zeile ermitteln. */
            $teile = [];
            $hoch  = 1;
            foreach ( $spalten as $i => $s ) {
                $txt = isset( $zeile[ $i ] ) ? (string) $zeile[ $i ] : '';
                $teile[ $i ] = LSV07S_PDF::umbruch( $txt, $w[ $i ] - 8, 9.5, false );
                $hoch = max( $hoch, count( $teile[ $i ] ) );
            }
            $hoehe = $hoch * 13 + 4;

            $vorher = $this->seite;
            $this->platz( $hoehe );
            if ( $this->seite !== $vorher ) $kopf();   // Fortsetzung bekommt den Kopf

            foreach ( $spalten as $i => $s ) {
                $yy = $this->y;
                foreach ( $teile[ $i ] as $z ) {
                    if ( ! empty( $s['rechts'] ) ) {
                        $this->pdf->text_rechts( $x[ $i ] + $w[ $i ] - 4, $this->oy( $yy ), $z, 9.5 );
                    } else {
                        $this->pdf->text( $x[ $i ] + 4, $this->oy( $yy ), $z, 9.5 );
                    }
                    $yy += 13;
                }
            }

            /* Die Trennlinie gehört in die Lücke ZWISCHEN zwei Zeilen: unter
               die Unterlängen der eigenen (rund 2 pt unter der Grundlinie)
               und über die Oberlängen der nächsten (rund 7,5 pt über deren
               Grundlinie). Gemessen vom Fuss der Zeile aus gerechnet lag sie
               vorher 12 pt tiefer — und damit mitten im Text der Zeile
               darunter. Im PDF selbst war das zu sehen, in den Daten nicht. */
            $linie = $this->y + ( $hoch - 1 ) * 13 + 6;
            $this->pdf->linie( self::RAND_L, $this->oy( $linie ),
                LSV07S_PDF::BREITE - self::RAND_R, $this->oy( $linie ), 0.4, 0.86 );
            $this->y += $hoehe;
        }

        if ( ! $zeilen ) {
            $this->pdf->text( self::RAND_L + 4, $this->oy( $this->y ), 'Nichts eingetragen.', 9.5, false, 0.45 );
            $this->y += 18;
        }
        unset( $erste_seite );
        $this->y += 8;
    }

    /** Das fertige PDF. */
    public function fertig() {
        $this->fuss();
        return $this->pdf->fertig();
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

    /** Dateiname ohne Überraschungen. */
    public static function dateiname( $teil, $person ) {
        $roh = $teil . '-' . $person;
        $roh = strtr( $roh, [ 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae',
                              'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss' ] );
        $roh = preg_replace( '/[^A-Za-z0-9._-]+/', '-', $roh );
        $roh = trim( (string) $roh, '-' );
        return ( $roh !== '' ? $roh : 'swimview' ) . '-' . gmdate( 'Y-m-d' ) . '.pdf';
    }
}
