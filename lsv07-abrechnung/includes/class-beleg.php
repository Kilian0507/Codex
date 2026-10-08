<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Der Beleg einer Abrechnung als fertiges Dokument.
 *
 * Bisher gab es ihn nur im Browser: Die Kasse öffnet ihn und druckt ihn
 * über den Druckdialog als PDF. Ist eine Abrechnung bezahlt, soll die
 * Person sie aber auch bekommen, ohne jemanden darum bitten zu müssen.
 * Darum wird das Dokument hier ein zweites Mal gebaut — auf dem Server,
 * in derselben Gliederung und mit denselben Grautönen wie im
 * Druckfenster.
 *
 * Zwei Dinge sind anders als beim Druckbeleg der Kasse, und zwar
 * absichtlich:
 *
 *   1. Alle Farben und Abstände stehen direkt an den Elementen. Mail-
 *      programme werfen <style> im Kopf gern weg; was inline steht,
 *      überlebt.
 *   2. Die Bankverbindung steht nur verkürzt darin. Der Beleg geht per
 *      Mail und liegt danach in einem Postfach, oft auf fremden Servern,
 *      und lässt sich nicht zurückholen. Die letzten vier Stellen der
 *      IBAN genügen, um die Zahlung dem eigenen Konto zuzuordnen; die
 *      ganze Nummer gehört nicht in die Post.
 */
class LSV07A_Beleg {

    /* Die Grautöne der Oberfläche, hier als feste Werte: In einer Mail
       gibt es keine CSS-Variablen. */
    const TINTE  = '#201f1e';
    const GRAU   = '#605e5c';
    const LINIE  = '#edebe9';
    const KANTE  = '#8a8886';
    const FLAECHE = '#f3f2f1';

    // ── Daten ────────────────────────────────────────────────────────────

    /**
     * Alles, was auf dem Beleg steht, aus der Datenbank geholt.
     *
     * Ohne Rechteprüfung: Diese Methode entscheidet nicht, wer etwas
     * sehen darf, sie stellt nur zusammen. Aufgerufen wird sie allein
     * dort, wo die Person selbst der Empfänger ist — beim Vermerk
     * „bezahlt". Wer sie woanders benutzt, muss vorher prüfen.
     */
    public static function daten( $abrechnung_id ) {
        global $wpdb;
        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}lsv07a_abrechnung WHERE id = %d",
            (int) $abrechnung_id ), ARRAY_A );
        if ( ! $abr ) return null;

        $rechnung = LSV07A_Berechnung::summe( (int) $abr['id'] );
        $person   = LSV07A_Person::holen( $abr['wp_user_id'] );
        $u        = get_userdata( $abr['wp_user_id'] );

        return [
            'name'        => $u ? $u->display_name : ( 'Konto ' . (int) $abr['wp_user_id'] ),
            'verein'      => LSV07A_DB::config( 'verein', '' ),
            'zeitraum'    => LSV07A_Berechnung::quartal_name( $abr['quartal'] ) . ' ' . (int) $abr['jahr'],
            'quartal'     => (string) $abr['quartal'],
            'jahr'        => (int) $abr['jahr'],
            'status_name' => LSV07A_Berechnung::status_name( $abr['status'] ),
            'art_name'    => LSV07A_Berechnung::art_name( $abr['abrechnungsart'] ),
            'stundensatz' => (float) $abr['stundensatz'],
            'genehmigt_am' => $abr['genehmigt_am'],
            'bezahlt_am'   => $abr['bezahlt_am'],
            'posten'      => $rechnung['posten'],
            'summen'      => $rechnung['summen'],
            'gesamt'      => $rechnung['gesamt'],
            'kontoinhaber' => (string) ( $person['kontoinhaber'] ?? '' ),
            'iban_kurz'    => self::iban_kurz( $person['iban'] ?? '' ),
        ];
    }

    /**
     * Ein Beispielbeleg für die Probemail. Dieselben Zahlen wie in der
     * Beispielmail, damit sich beim Einrichten beurteilen lässt, wie das
     * Ergebnis aussieht — ohne eine echte Abrechnung anzufassen.
     */
    public static function probe_daten() {
        $jahr = (int) date( 'Y' );
        return [
            'name'        => 'Beispiel Trainerin',
            'verein'      => LSV07A_DB::config( 'verein', '' ),
            'zeitraum'    => '1. Quartal (Jan–Mär) ' . $jahr,
            'quartal'     => 'Q1',
            'jahr'        => $jahr,
            'status_name' => 'Bezahlt',
            'art_name'    => 'Stundensatz',
            'stundensatz' => 12.0,
            'genehmigt_am' => $jahr . '-04-08 10:00:00',
            'bezahlt_am'   => $jahr . '-04-11 09:30:00',
            'posten'      => [
                'training' => [
                    [ 'datum' => $jahr . '-01-14', 'bezeichnung' => 'Training Jugend A',
                      'notiz' => 'mit Wartezeit', 'menge' => 2.25, 'satz' => 12.0,
                      'betrag' => 27.0, 'tage' => 1 ],
                    [ 'datum' => $jahr . '-01-21', 'bezeichnung' => 'Training Jugend A',
                      'notiz' => '', 'menge' => 2.0, 'satz' => 12.0,
                      'betrag' => 24.0, 'tage' => 1 ],
                ],
                'fahrt' => [
                    [ 'datum' => $jahr . '-02-03', 'bezeichnung' => 'Fahrt zum Wettkampf',
                      'notiz' => '', 'menge' => 48.0, 'satz' => 0.5,
                      'betrag' => 48.0, 'tage' => 2 ],
                ],
            ],
            'summen'      => [ 'training' => 51.0, 'wettkampf' => 0.0, 'fahrt' => 48.0,
                               'vorbereitung' => 0.0, 'sonstiges' => 0.0 ],
            'gesamt'      => 99.0,
            'kontoinhaber' => 'Beispiel Trainerin',
            'iban_kurz'    => 'DE** **** **** **** **12 34',
        ];
    }

    // ── Bausteine ────────────────────────────────────────────────────────

    /**
     * Die IBAN auf Land und die letzten vier Stellen gekürzt. Nicht
     * hinterlegt heisst: nichts anzeigen, nicht „—" mit Sternen.
     */
    public static function iban_kurz( $iban ) {
        $roh = strtoupper( preg_replace( '/\s+/', '', (string) $iban ) );
        if ( strlen( $roh ) < 8 ) return '';
        $land  = substr( $roh, 0, 2 );
        $ende  = substr( $roh, -4 );
        return $land . '** **** **** **** **' . substr( $ende, 0, 2 ) . ' ' . substr( $ende, 2 );
    }

    private static function eur( $v ) {
        return number_format( (float) $v, 2, ',', '.' ) . ' €';
    }

    private static function zahl( $v, $dez = 2 ) {
        return rtrim( rtrim( number_format( (float) $v, $dez, ',', '.' ), '0' ), ',' );
    }

    private static function datum( $d ) {
        $z = strtotime( (string) $d );
        return $z ? date( 'd.m.Y', $z ) : '';
    }

    private static function h( $s ) {
        return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
    }

    /** Die mittlere Spalte: woraus der Betrag entstanden ist. */
    private static function rechenweg( $typ, $p ) {
        $menge = (float) ( $p['menge'] ?? 0 );
        $satz  = (float) ( $p['satz'] ?? 0 );
        $tage  = (int) ( $p['tage'] ?? 1 );
        if ( $typ === 'wettkampf' ) {
            return self::zahl( $menge, 0 ) . ' × ' . self::eur( $satz );
        }
        if ( $typ === 'fahrt' ) {
            return self::zahl( $menge, 1 ) . ' km × ' . self::eur( $satz )
                 . ( $tage > 1 ? ' × ' . $tage . ' Tage' : '' );
        }
        if ( $typ === 'sonstiges' ) return '';
        return self::zahl( $menge ) . ' Std × ' . self::eur( $satz );
    }

    // ── Darstellung ──────────────────────────────────────────────────────

    /**
     * Der Beleg als HTML-Baustein, zum Einsetzen in eine Mail. Enthält
     * bewusst kein <html> und kein <style> — nur Elemente, die sich
     * überall einbetten lassen.
     */
    public static function abschnitt( array $d ) {
        /* `table-layout:fixed` mit festen Spaltenanteilen. Ohne das
           richtet sich die Tabelle nach ihrem längsten Wort: Ein Posten
           namens „Kreismeisterschaft" schiebt sie dann auf einem Telefon
           über den Rand, und im Postfach muss man seitwärts wischen. So
           bleibt sie immer genau so breit wie der Platz, den sie hat —
           unabhängig davon, wie lang die Bezeichnungen ausfallen. */
        $td   = 'padding:6px 3px;border-bottom:1px solid ' . self::LINIE
              . ';vertical-align:top;word-wrap:break-word;overflow-wrap:break-word';
        $tdr  = $td . ';text-align:right;white-space:nowrap';
        $klein = 'font-size:12px;color:' . self::GRAU;

        $zeilen = '';
        foreach ( LSV07A_DB::POSTEN_TYPEN as $typ ) {
            $liste = $d['posten'][ $typ ] ?? [];
            if ( ! $liste ) continue;
            $name = LSV07A_Berechnung::typ_name( $typ );
            $zeilen .= '<tr><td colspan="3" style="padding:15px 3px 5px;font-weight:600;'
                     . 'border-bottom:1px solid ' . self::KANTE . '">' . self::h( $name ) . '</td></tr>';
            foreach ( $liste as $p ) {
                $notiz = trim( (string) ( $p['notiz'] ?? '' ) );
                /* Der Rechenweg steht unter der Bezeichnung und nicht in
                   einer eigenen Spalte: Vier Spalten passen auf keinen
                   Telefonbildschirm, ohne dass Datum und Wörter mitten
                   entzweigehen. Auf dem PDF ist der Platz da, dort hat er
                   seine Spalte. */
                $unten = [];
                $weg   = self::rechenweg( $typ, $p );
                if ( $weg !== '' )   $unten[] = str_replace( ' €', '&nbsp;€', self::h( $weg ) );
                if ( $notiz !== '' ) $unten[] = self::h( $notiz );

                $zeilen .= '<tr>'
                    . '<td style="' . $td . ';white-space:nowrap;padding-right:10px">'
                    . self::h( self::datum( $p['datum'] ?? '' ) ) . '</td>'
                    . '<td style="' . $td . '">' . self::h( $p['bezeichnung'] ?? '' )
                    . ( $unten ? '<br><span style="' . $klein . '">'
                               . implode( ' · ', $unten ) . '</span>' : '' )
                    . '</td>'
                    . '<td style="' . $tdr . '">' . self::h( self::eur( $p['betrag'] ?? 0 ) ) . '</td>'
                    . '</tr>';
            }
            $zeilen .= '<tr>'
                . '<td colspan="2" style="' . $td . ';font-weight:600;background:' . self::FLAECHE . '">'
                . 'Summe ' . self::h( $name ) . '</td>'
                . '<td style="' . $tdr . ';font-weight:600;background:' . self::FLAECHE . '">'
                . self::h( self::eur( $d['summen'][ $typ ] ?? 0 ) ) . '</td>'
                . '</tr>';
        }
        if ( $zeilen === '' ) {
            $zeilen = '<tr><td colspan="3" style="' . $td . ';color:' . self::GRAU . '">'
                    . 'Keine Posten.</td></tr>';
        }

        $th = 'text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:'
            . self::GRAU . ';font-weight:600;border-bottom:1px solid ' . self::KANTE . ';padding:5px 3px';

        $kopf = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
              . 'style="border-collapse:collapse;border-bottom:2px solid ' . self::TINTE . ';'
              . 'padding-bottom:10px;margin-bottom:14px"><tr>'
              . '<td style="vertical-align:top">'
              . '<div style="font-size:21px;font-weight:600;line-height:1.2">Abrechnung</div>'
              . '<div style="padding-top:3px">' . self::h( $d['name'] ) . '</div></td>'
              . '<td style="vertical-align:top;text-align:right;font-size:13px;color:' . self::GRAU . '">'
              . ( $d['verein'] !== '' ? self::h( $d['verein'] ) . '<br>' : '' )
              . self::h( $d['zeitraum'] ) . '<br>'
              . self::h( ! empty( $d['bezahlt_am'] )
                  ? 'Bezahlt am ' . self::datum( $d['bezahlt_am'] )
                  : $d['status_name'] )
              . '</td></tr></table>';

        $fuss = '<div style="margin-top:18px;' . $klein . ';line-height:1.6">'
              . 'Abgerechnet nach ' . self::h( $d['art_name'] )
              . ' mit einem Stundensatz von ' . self::h( self::eur( $d['stundensatz'] ) ) . '.';
        if ( trim( (string) $d['kontoinhaber'] ) !== '' || $d['iban_kurz'] !== '' ) {
            $fuss .= '<br>Überwiesen auf das hinterlegte Konto'
                   . ( trim( (string) $d['kontoinhaber'] ) !== ''
                       ? ' von ' . self::h( $d['kontoinhaber'] ) : '' )
                   . ( $d['iban_kurz'] !== '' ? ' (' . self::h( $d['iban_kurz'] ) . ')' : '' ) . '.'
                   /* Die verkürzte IBAN kann irritieren, wenn man sie für
                      einen Übertragungsfehler hält — darum steht dabei,
                      dass die Kürzung Absicht ist. */
                   . '<br>Die Bankverbindung steht hier nur verkürzt; vollständig ist sie '
                   . 'in der Abrechnung hinterlegt.';
        }
        $fuss .= '</div>';

        return $kopf
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            . 'style="border-collapse:collapse;table-layout:fixed;width:100%">'
            /* Kurze Spaltenköpfe: „Bezeichnung" und „Berechnung" sind
               lange Wörter. Auf dem PDF ist der Platz da, dort stehen
               die langen; hier nicht. */
            . '<thead><tr>'
            . '<th style="' . $th . ';width:24%">Datum</th>'
            . '<th style="' . $th . ';width:54%">Posten</th>'
            . '<th style="' . $th . ';width:22%;text-align:right">Betrag</th></tr></thead>'
            . '<tbody>' . $zeilen . '</tbody></table>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            . 'style="border-collapse:collapse;margin-top:14px;border-top:2px solid ' . self::TINTE . '">'
            . '<tr><td style="padding-top:8px;font-size:17px;font-weight:700">Gesamtbetrag</td>'
            . '<td style="padding-top:8px;font-size:17px;font-weight:700;text-align:right;'
            . 'white-space:nowrap">' . self::h( self::eur( $d['gesamt'] ) ) . '</td></tr></table>'
            . $fuss;
    }

    /**
     * Ein ganzes Dokument: der vorangestellte Mitteilungstext, darunter
     * der Beleg. Der Text kommt aus den Vorlagen der Verwaltung und ist
     * reiner Text — er wird maskiert und seine Zeilenumbrüche bleiben
     * erhalten, damit angepasste Texte nicht zu einer Zeile verkleben.
     */
    public static function mail_html( $text, array $d ) {
        $rumpf = 'font-family:\'Segoe UI\',system-ui,-apple-system,Helvetica,Arial,sans-serif;'
               . 'color:' . self::TINTE . ';font-size:14px;line-height:1.55;'
               . 'margin:0;padding:14px;background:#ffffff';
        return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
             . '<meta name="viewport" content="width=device-width,initial-scale=1">'
             . '<title>' . self::h( 'Abrechnung ' . $d['zeitraum'] ) . '</title></head>'
             . '<body style="' . $rumpf . '">'
             . '<div style="max-width:640px;margin:0 auto">'
             . '<div style="margin-bottom:22px">' . nl2br( self::h( $text ) ) . '</div>'
             . self::abschnitt( $d )
             . '</div></body></html>';
    }

    /**
     * Derselbe Beleg als reiner Text. Für Mailprogramme, die kein HTML
     * anzeigen — und als lesbare Fassung im Verlauf.
     */
    public static function nur_text( array $d ) {
        $z = [];
        $z[] = 'Abrechnung — ' . $d['name'];
        if ( $d['verein'] !== '' ) $z[] = $d['verein'];
        $z[] = $d['zeitraum'] . ' · ' . $d['status_name']
             . ( ! empty( $d['bezahlt_am'] ) ? ' · bezahlt am ' . self::datum( $d['bezahlt_am'] ) : '' );
        $z[] = '';

        foreach ( LSV07A_DB::POSTEN_TYPEN as $typ ) {
            $liste = $d['posten'][ $typ ] ?? [];
            if ( ! $liste ) continue;
            $name = LSV07A_Berechnung::typ_name( $typ );
            $z[]  = $name;
            foreach ( $liste as $p ) {
                $weg  = self::rechenweg( $typ, $p );
                $z[]  = '  ' . self::datum( $p['datum'] ?? '' ) . '  ' . ( $p['bezeichnung'] ?? '' )
                      . ( $weg !== '' ? '  (' . $weg . ')' : '' )
                      . '  ' . self::eur( $p['betrag'] ?? 0 );
            }
            $z[] = '  Summe ' . $name . ': ' . self::eur( $d['summen'][ $typ ] ?? 0 );
            $z[] = '';
        }
        $z[] = 'Gesamtbetrag: ' . self::eur( $d['gesamt'] );
        $z[] = '';
        $z[] = 'Abgerechnet nach ' . $d['art_name'] . ' mit einem Stundensatz von '
             . self::eur( $d['stundensatz'] ) . '.';
        if ( $d['iban_kurz'] !== '' ) {
            $z[] = 'Überwiesen auf das hinterlegte Konto (' . $d['iban_kurz'] . ').';
        }
        return implode( "\n", $z );
    }

    // ── Als PDF ──────────────────────────────────────────────────────────

    /* Das Papierformat, von oben nach unten gedacht. Im PDF selbst zählt
       y von unten; umgerechnet wird an einer Stelle (self::von_oben), und
       die ganze Gestaltung darüber darf von oben nach unten lesen. */
    const RAND_L  = 56.0;
    const RAND_R  = 539.28;   // 595.28 − 56
    const FUSS    = 92.0;     // ab hier beginnt die nächste Seite

    private static function von_oben( $y ) { return LSV07A_PDF::HOEHE - $y; }

    /**
     * Der Beleg als PDF, als Zeichenkette zurückgegeben.
     *
     * Gestaltet wie der Druckbeleg: keine Farbe, Gliederung durch
     * Linien, Flächen nur für die Zwischensummen. Was nicht auf eine
     * Seite passt, läuft auf die nächste — mit wiederholter
     * Tabellenüberschrift, damit die Spalten dort nicht geraten werden
     * müssen.
     */
    public static function pdf( array $d ) {
        $p = new LSV07A_PDF();

        /* Spalten. Die Beträge enden rechts an der Satzkante, damit die
           Nachkommastellen untereinander stehen. */
        $x_datum = self::RAND_L;
        $x_bez   = self::RAND_L + 68;
        $x_rech  = self::RAND_L + 250;
        $b_bez   = 178.0;
        $b_rech  = 112.0;

        $seite = 1;
        $y     = self::kopf( $p, $d, true );

        $zeile_h  = 13.5;
        $gr       = 9.5;     // Schriftgröße der Zeilen
        $gr_klein = 8.0;

        $umbruch_seite = function () use ( $p, $d, &$seite, &$y ) {
            self::seitenfuss( $p, $d, $seite );
            $p->seite_neu();
            $seite++;
            $y = self::kopf( $p, $d, false );
        };

        foreach ( LSV07A_DB::POSTEN_TYPEN as $typ ) {
            $liste = $d['posten'][ $typ ] ?? [];
            if ( ! $liste ) continue;
            $name = LSV07A_Berechnung::typ_name( $typ );

            /* Eine Gruppenüberschrift allein am Seitenende wäre
               sinnlos — sie wandert mit, wenn nicht auch noch eine
               Zeile darunter Platz hat. */
            if ( $y + 20 + $zeile_h > LSV07A_PDF::HOEHE - self::FUSS ) $umbruch_seite();

            $y += 14;
            $p->text( $x_datum, self::von_oben( $y ), $name, 10.5, true );
            $y += 5;
            $p->linie( self::RAND_L, self::von_oben( $y ), self::RAND_R, self::von_oben( $y ), 0.6, 0.54 );
            $y += 4;

            foreach ( $liste as $po ) {
                $bez    = (string) ( $po['bezeichnung'] ?? '' );
                $notiz  = trim( (string) ( $po['notiz'] ?? '' ) );
                $z_bez  = LSV07A_PDF::umbruch( $bez, $b_bez, $gr );
                $z_not  = $notiz !== '' ? LSV07A_PDF::umbruch( $notiz, $b_bez, $gr_klein ) : [];
                $z_rech = LSV07A_PDF::umbruch( self::rechenweg( $typ, $po ), $b_rech, $gr );
                $hoehe  = max( count( $z_bez ) + count( $z_not ), count( $z_rech ), 1 ) * $zeile_h;

                if ( $y + $hoehe > LSV07A_PDF::HOEHE - self::FUSS ) {
                    $umbruch_seite();
                    /* Nach dem Umbruch steht die Gruppe neu im Kopf,
                       sonst beginnt die Seite mit Zahlen ohne Bezug. */
                    $y += 12;
                    $p->text( $x_datum, self::von_oben( $y ), $name . ' (Fortsetzung)', 10.5, true );
                    $y += 5;
                    $p->linie( self::RAND_L, self::von_oben( $y ), self::RAND_R, self::von_oben( $y ), 0.6, 0.54 );
                    $y += 4;
                }

                $y += 11;
                $p->text( $x_datum, self::von_oben( $y ), self::datum( $po['datum'] ?? '' ), $gr );
                $yz = $y;
                foreach ( $z_bez as $zl ) {
                    $p->text( $x_bez, self::von_oben( $yz ), $zl, $gr );
                    $yz += $zeile_h;
                }
                foreach ( $z_not as $zl ) {
                    $p->text( $x_bez, self::von_oben( $yz ), $zl, $gr_klein, false, 0.38 );
                    $yz += $zeile_h;
                }
                $yr = $y;
                foreach ( $z_rech as $zl ) {
                    $p->text( $x_rech, self::von_oben( $yr ), $zl, $gr, false, 0.38 );
                    $yr += $zeile_h;
                }
                $p->text_rechts( self::RAND_R, self::von_oben( $y ), self::eur( $po['betrag'] ?? 0 ), $gr );

                $y = $y - 11 + $hoehe;
                $p->linie( self::RAND_L, self::von_oben( $y ), self::RAND_R, self::von_oben( $y ), 0.5, 0.88 );
            }

            /* Zwischensumme auf hellem Grund. */
            if ( $y + 22 > LSV07A_PDF::HOEHE - self::FUSS ) $umbruch_seite();
            $p->flaeche( self::RAND_L, self::von_oben( $y + 18 ), self::RAND_R - self::RAND_L, 18, 0.95 );
            $y += 13;
            $p->text( $x_datum, self::von_oben( $y ), 'Summe ' . $name, $gr, true );
            $p->text_rechts( self::RAND_R, self::von_oben( $y ), self::eur( $d['summen'][ $typ ] ?? 0 ), $gr, true );
            $y += 5;
            $p->linie( self::RAND_L, self::von_oben( $y ), self::RAND_R, self::von_oben( $y ), 0.6, 0.78 );
        }

        if ( ! self::hat_posten( $d ) ) {
            $y += 20;
            $p->text( $x_datum, self::von_oben( $y ), 'Keine Posten.', $gr, false, 0.38 );
        }

        // Gesamtbetrag
        if ( $y + 46 > LSV07A_PDF::HOEHE - self::FUSS ) $umbruch_seite();
        $y += 20;
        $p->linie( self::RAND_L, self::von_oben( $y ), self::RAND_R, self::von_oben( $y ), 1.6, 0.1 );
        $y += 17;
        $p->text( $x_datum, self::von_oben( $y ), 'Gesamtbetrag', 13, true );
        $p->text_rechts( self::RAND_R, self::von_oben( $y ), self::eur( $d['gesamt'] ), 13, true );

        // Fussnoten
        $y += 26;
        foreach ( self::fussnoten( $d ) as $satz ) {
            $zeilen = LSV07A_PDF::umbruch( $satz, self::RAND_R - self::RAND_L, 8.5 );
            /* Ein Satz wird nicht über den Seitenrand hinweg zerschnitten:
               Passt er nicht mehr ganz, geht er vollständig mit. */
            if ( $y + count( $zeilen ) * 11.5 > LSV07A_PDF::HOEHE - self::FUSS ) {
                $umbruch_seite();
                $y += 14;
            }
            foreach ( $zeilen as $zl ) {
                $p->text( self::RAND_L, self::von_oben( $y ), $zl, 8.5, false, 0.38 );
                $y += 11.5;
            }
        }

        self::seitenfuss( $p, $d, $seite );
        return $p->fertig();
    }

    private static function hat_posten( array $d ) {
        foreach ( LSV07A_DB::POSTEN_TYPEN as $typ ) {
            if ( ! empty( $d['posten'][ $typ ] ) ) return true;
        }
        return false;
    }

    /** Die Sätze unter dem Betrag — einmal für PDF und Text gleich. */
    private static function fussnoten( array $d ) {
        $s = [ 'Abgerechnet nach ' . $d['art_name'] . ' mit einem Stundensatz von '
               . self::eur( $d['stundensatz'] ) . '.' ];
        $inhaber = trim( (string) $d['kontoinhaber'] );
        if ( $inhaber !== '' || $d['iban_kurz'] !== '' ) {
            $s[] = 'Überwiesen auf das hinterlegte Konto'
                 . ( $inhaber !== '' ? ' von ' . $inhaber : '' )
                 . ( $d['iban_kurz'] !== '' ? ' (' . $d['iban_kurz'] . ')' : '' ) . '.'
                 . ' Die Bankverbindung steht hier nur verkürzt; vollständig ist sie in der'
                 . ' Abrechnung hinterlegt.';
        }
        return $s;
    }

    /**
     * Der Seitenkopf. Auf der ersten Seite mit Namen und Zeitraum, auf
     * den folgenden knapp — dort ist schon klar, worum es geht, und der
     * Platz gehört den Posten.
     */
    private static function kopf( LSV07A_PDF $p, array $d, $erste ) {
        if ( ! $erste ) {
            $p->text( self::RAND_L, self::von_oben( 50 ),
                'Abrechnung · ' . $d['name'] . ' · ' . $d['zeitraum'], 9, false, 0.38 );
            $p->linie( self::RAND_L, self::von_oben( 58 ), self::RAND_R, self::von_oben( 58 ), 0.6, 0.54 );
            return self::tabellenkopf( $p, 76 );
        }

        $p->text( self::RAND_L, self::von_oben( 72 ), 'Abrechnung', 20, true );
        $p->text( self::RAND_L, self::von_oben( 90 ), $d['name'], 11 );

        $y = 60;
        $rechts = [ $d['verein'], $d['zeitraum'] ];
        /* Entweder das Datum oder der blosse Stand — „Bezahlt" und
           „Bezahlt am 11.04." untereinander sagt zweimal dasselbe. */
        if ( ! empty( $d['bezahlt_am'] ) ) {
            $rechts[] = 'Bezahlt am ' . self::datum( $d['bezahlt_am'] );
        } elseif ( ! empty( $d['genehmigt_am'] ) ) {
            $rechts[] = 'Genehmigt am ' . self::datum( $d['genehmigt_am'] );
        } else {
            $rechts[] = $d['status_name'];
        }
        foreach ( $rechts as $zl ) {
            if ( trim( (string) $zl ) === '' ) continue;
            $p->text_rechts( self::RAND_R, self::von_oben( $y ), $zl, 9.5, false, 0.38 );
            $y += 12.5;
        }

        $p->linie( self::RAND_L, self::von_oben( 104 ), self::RAND_R, self::von_oben( 104 ), 1.6, 0.1 );
        return self::tabellenkopf( $p, 124 );
    }

    /** Die Spaltenüberschriften; gibt die Höhe zurück, ab der es weitergeht. */
    private static function tabellenkopf( LSV07A_PDF $p, $y ) {
        $p->text( self::RAND_L,       self::von_oben( $y ), 'DATUM', 7.5, true, 0.38 );
        $p->text( self::RAND_L + 68,  self::von_oben( $y ), 'BEZEICHNUNG', 7.5, true, 0.38 );
        $p->text( self::RAND_L + 250, self::von_oben( $y ), 'BERECHNUNG', 7.5, true, 0.38 );
        $p->text_rechts( self::RAND_R, self::von_oben( $y ), 'BETRAG', 7.5, true, 0.38 );
        $p->linie( self::RAND_L, self::von_oben( $y + 5 ), self::RAND_R, self::von_oben( $y + 5 ), 0.6, 0.54 );
        return $y + 5;
    }

    private static function seitenfuss( LSV07A_PDF $p, array $d, $seite ) {
        /* Hier wird ausnahmsweise von UNTEN gerechnet, denn darauf
           bezieht sich der Fuss: 36 Punkt über der Blattkante. */
        $y = 36.0;
        $p->linie( self::RAND_L, $y + 14, self::RAND_R, $y + 14, 0.5, 0.88 );
        $links = trim( (string) $d['verein'] ) !== '' ? $d['verein'] : 'Abrechnung';
        $p->text( self::RAND_L, $y, $links . ' · ' . $d['zeitraum'], 8, false, 0.5 );
        $p->text_rechts( self::RAND_R, $y, 'Seite ' . (int) $seite, 8, false, 0.5 );
    }

    // ── Datei für den Mailanhang ─────────────────────────────────────────

    /**
     * Ein Dateiname, den man im Postfach wiederfindet: Zeitraum vor
     * Namen, damit mehrere Belege nebeneinander nach Quartal sortieren.
     */
    public static function dateiname( array $d ) {
        $name = preg_replace( '/[^A-Za-z0-9]+/', '-',
            strtr( $d['name'], [ 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae',
                                 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss' ] ) );
        $name = trim( (string) $name, '-' );
        return 'Abrechnung-' . (int) $d['jahr'] . '-' . $d['quartal']
             . ( $name !== '' ? '-' . $name : '' ) . '.pdf';
    }

    /**
     * Das PDF als Datei auf der Platte, für den Mailanhang.
     *
     * Jeder Beleg bekommt ein eigenes, zufällig benanntes Verzeichnis.
     * Zwei Gründe: Der Anhang soll den lesbaren Namen tragen (den nimmt
     * der Mailversand aus dem Dateinamen), und zwei Belege, die
     * gleichzeitig verschickt werden, dürfen sich nicht überschreiben.
     *
     * Gibt den Pfad zurück oder einen leeren Text, wenn nichts
     * geschrieben werden konnte — dann geht die Mail ohne Anhang raus,
     * statt gar nicht.
     */
    public static function pdf_datei( array $d ) {
        $basis = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
        $basis = rtrim( (string) $basis, '/\\' );
        if ( $basis === '' || ! is_dir( $basis ) || ! is_writable( $basis ) ) return '';

        $ordner = $basis . DIRECTORY_SEPARATOR . 'lsv07a-beleg-' . bin2hex( random_bytes( 8 ) );
        if ( ! @mkdir( $ordner, 0700 ) ) return '';

        $pfad = $ordner . DIRECTORY_SEPARATOR . self::dateiname( $d );
        if ( @file_put_contents( $pfad, self::pdf( $d ) ) === false ) {
            @rmdir( $ordner );
            return '';
        }
        return $pfad;
    }

    /** Den Anhang wieder wegräumen, sobald die Mail übergeben ist. */
    public static function aufraeumen( $pfad ) {
        $pfad = (string) $pfad;
        if ( $pfad === '' || ! is_file( $pfad ) ) return;
        $ordner = dirname( $pfad );
        @unlink( $pfad );
        /* Nur das eigene Verzeichnis, und nur wenn es leer ist. */
        if ( strpos( basename( $ordner ), 'lsv07a-beleg-' ) === 0 ) @rmdir( $ordner );
    }
}
