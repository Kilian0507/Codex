<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ein kleiner PDF-Schreiber — gerade so viel, wie ein Beleg braucht.
 *
 * Warum selbst gebaut: Ein PDF per Mail zu verschicken ginge auch mit
 * einer der grossen Programmbibliotheken. Die wiegt dann aber ein
 * Vielfaches dieses ganzen Plugins, muss mitgepflegt werden und bringt
 * hundert Fähigkeiten mit, von denen wir eine brauchen. Ein Beleg
 * besteht aus Linien, Flächen und links- oder rechtsbündigem Text in
 * zwei Schriftschnitten. Das sind die vier Dinge, die hier gehen.
 *
 * Es werden die beiden Standardschriften benutzt, die jedes
 * Anzeigeprogramm mitbringt (Helvetica und Helvetica fett). Dadurch
 * muss keine Schriftdatei eingebettet werden: Die Datei bleibt winzig,
 * und es wird nichts an eine fremde Schriftquelle ausgeliefert.
 *
 * Kodiert wird in WinAnsi (Windows-1252). Darin stecken Umlaute, ß und
 * das Eurozeichen — alles, was auf einem deutschen Beleg vorkommt.
 * Umgewandelt wird mit einer eigenen Tabelle statt mit mbstring oder
 * iconv: Beides ist auf fremdem Webspace nicht garantiert vorhanden,
 * und ein Beleg darf nicht daran scheitern.
 */
class LSV07A_PDF {

    /* A4 in Punkten (72 dpi), auf zwei Stellen gerundet. */
    const BREITE = 595.28;
    const HOEHE  = 841.89;

    /** @var array Seiten, jede ein Stück Inhaltsstrom. */
    private $seiten = [];
    private $strom  = '';

    public function __construct() { $this->seite_neu(); }

    public function seite_neu() {
        if ( $this->strom !== '' ) $this->seiten[] = $this->strom;
        $this->strom = '';
    }

    public function seitenzahl() { return count( $this->seiten ) + 1; }

    // ── Zeichnen ─────────────────────────────────────────────────────────

    /**
     * Text setzen. `$y` zählt wie im PDF von UNTEN — die aufrufende
     * Schicht rechnet das um, damit sie von oben nach unten denken kann.
     */
    public function text( $x, $y, $s, $groesse = 10, $fett = false, $grau = 0.0 ) {
        $roh = self::cp1252( $s );
        if ( $roh === '' ) return;
        $this->strom .= sprintf( "%s rg\nBT /F%d %s Tf %s %s Td (%s) Tj ET\n",
            self::z( $grau ) . ' ' . self::z( $grau ) . ' ' . self::z( $grau ),
            $fett ? 2 : 1, self::z( $groesse ), self::z( $x ), self::z( $y ),
            self::wort( $roh ) );
    }

    /** Rechtsbündig: Der Text endet bei `$x`. */
    public function text_rechts( $x, $y, $s, $groesse = 10, $fett = false, $grau = 0.0 ) {
        $this->text( $x - self::breite( $s, $groesse, $fett ), $y, $s, $groesse, $fett, $grau );
    }

    public function linie( $x1, $y1, $x2, $y2, $dicke = 0.6, $grau = 0.55 ) {
        $this->strom .= sprintf( "%s %s %s RG %s w\n%s %s m %s %s l S\n",
            self::z( $grau ), self::z( $grau ), self::z( $grau ), self::z( $dicke ),
            self::z( $x1 ), self::z( $y1 ), self::z( $x2 ), self::z( $y2 ) );
    }

    public function flaeche( $x, $y, $breite, $hoehe, $grau = 0.95 ) {
        $this->strom .= sprintf( "%s %s %s rg\n%s %s %s %s re f\n",
            self::z( $grau ), self::z( $grau ), self::z( $grau ),
            self::z( $x ), self::z( $y ), self::z( $breite ), self::z( $hoehe ) );
    }

    // ── Textmaß ──────────────────────────────────────────────────────────

    /** Die Breite eines Textes in Punkten, bei gegebener Schriftgröße. */
    public static function breite( $s, $groesse = 10, $fett = false ) {
        $roh = self::cp1252( $s );
        $tab = self::breiten( $fett );
        $summe = 0;
        $len = strlen( $roh );
        for ( $i = 0; $i < $len; $i++ ) {
            $summe += $tab[ ord( $roh[ $i ] ) ] ?? 556;
        }
        return $summe * $groesse / 1000;
    }

    /**
     * Einen Text auf eine Breite umbrechen. Gibt die Zeilen zurück; ein
     * einzelnes Wort, das allein schon zu breit ist, bleibt zu breit
     * statt mitten im Wort zu zerfallen — lieber einmal zu lang als
     * unlesbar.
     */
    public static function umbruch( $s, $maxbreite, $groesse = 10, $fett = false ) {
        $worte = preg_split( '/\s+/u', trim( (string) $s ) ) ?: [];
        $zeilen = []; $zeile = '';
        foreach ( $worte as $w ) {
            if ( $w === '' ) continue;
            $probe = $zeile === '' ? $w : $zeile . ' ' . $w;
            if ( $zeile !== '' && self::breite( $probe, $groesse, $fett ) > $maxbreite ) {
                $zeilen[] = $zeile; $zeile = $w;
            } else {
                $zeile = $probe;
            }
        }
        if ( $zeile !== '' ) $zeilen[] = $zeile;
        return $zeilen ?: [ '' ];
    }

    // ── Datei ────────────────────────────────────────────────────────────

    /** Das fertige PDF als Zeichenkette. */
    public function fertig() {
        $seiten = $this->seiten;
        if ( $this->strom !== '' ) $seiten[] = $this->strom;
        if ( ! $seiten ) $seiten[] = '';

        /* Feste Nummern: 1 Katalog, 2 Seitenbaum, 3+4 die Schriften.
           Danach je Seite zwei Objekte (Seite, Inhalt). */
        $erste = 5;
        $kinder = [];
        foreach ( $seiten as $i => $unused ) $kinder[] = ( $erste + $i * 2 ) . ' 0 R';

        $objekte = [];
        $objekte[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objekte[2] = "<< /Type /Pages /Kids [ " . implode( ' ', $kinder ) . " ] /Count "
                    . count( $seiten ) . " >>";
        $objekte[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica "
                    . "/Encoding /WinAnsiEncoding >>";
        $objekte[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold "
                    . "/Encoding /WinAnsiEncoding >>";

        foreach ( $seiten as $i => $inhalt ) {
            $nr = $erste + $i * 2;
            $objekte[ $nr ] = "<< /Type /Page /Parent 2 0 R /MediaBox [ 0 0 "
                . self::z( self::BREITE ) . ' ' . self::z( self::HOEHE ) . " ] "
                . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> "
                . "/Contents " . ( $nr + 1 ) . " 0 R >>";
            $objekte[ $nr + 1 ] = "<< /Length " . strlen( $inhalt ) . " >>\nstream\n"
                . $inhalt . "endstream";
        }

        ksort( $objekte );
        $aus = "%PDF-1.4\n";
        /* Die Kennzeichnung als Binärdatei: Ohne diese Bytes halten
           manche Übertragungswege das PDF für Text und übersetzen
           Zeilenenden um — danach ist die Datei kaputt. */
        $aus .= "%\xE2\xE3\xCF\xD3\n";
        $stellen = [];
        foreach ( $objekte as $nr => $inhalt ) {
            $stellen[ $nr ] = strlen( $aus );
            $aus .= $nr . " 0 obj\n" . $inhalt . "\nendobj\n";
        }
        $xref = strlen( $aus );
        $anzahl = max( array_keys( $objekte ) ) + 1;
        $aus .= "xref\n0 " . $anzahl . "\n";
        $aus .= "0000000000 65535 f \n";
        for ( $i = 1; $i < $anzahl; $i++ ) {
            $aus .= isset( $stellen[ $i ] )
                ? sprintf( "%010d 00000 n \n", $stellen[ $i ] )
                : "0000000000 65535 f \n";
        }
        $aus .= "trailer\n<< /Size " . $anzahl . " /Root 1 0 R >>\n"
              . "startxref\n" . $xref . "\n%%EOF\n";
        return $aus;
    }

    // ── Innereien ────────────────────────────────────────────────────────

    /** Zahlen kurz und ohne Exponent, mit Punkt als Trenner. */
    private static function z( $v ) {
        $s = number_format( (float) $v, 2, '.', '' );
        return rtrim( rtrim( $s, '0' ), '.' ) ?: '0';
    }

    /** Klammern und Rückstriche müssen in einer PDF-Zeichenkette maskiert sein. */
    private static function wort( $roh ) {
        return str_replace( [ '\\', '(', ')', "\r", "\n" ],
                            [ '\\\\', '\\(', '\\)', '', '' ], $roh );
    }

    /**
     * UTF-8 nach Windows-1252, mit eigener Tabelle für alles, was auf
     * einem deutschen Beleg vorkommt. Zeichen, die es dort nicht gibt,
     * werden auf etwas Ähnliches gebracht und nicht stillschweigend
     * weggelassen — ein fehlendes Zeichen fällt im Text nicht auf, ein
     * falsches Wort schon.
     */
    public static function cp1252( $s ) {
        /* Zeichen jenseits von Latin-1, die auf einem Beleg vorkommen —
           vor allem das Eurozeichen und die typografischen Striche und
           Anführungszeichen, die in den Texten des Plugins stehen.
           Latin-1 selbst (0xA0–0xFF) deckt sich mit Windows-1252 und
           braucht keinen Eintrag. */
        static $karte = [
            0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84,
            0x2026 => 0x85, 0x2020 => 0x86, 0x2021 => 0x87, 0x02C6 => 0x88,
            0x2030 => 0x89, 0x0160 => 0x8A, 0x2039 => 0x8B, 0x0152 => 0x8C,
            0x2018 => 0x91, 0x2019 => 0x92, 0x201C => 0x93, 0x201D => 0x94,
            0x2022 => 0x95, 0x2013 => 0x96, 0x2014 => 0x97, 0x02DC => 0x98,
            0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B, 0x0153 => 0x9C,
            0x0178 => 0x9F,
            /* Minuszeichen und geschütztes Leerzeichen auf ihre
               schlichten Verwandten, damit sie nicht zum Fragezeichen
               werden. */
            0x2212 => 0x2D, 0x2007 => 0x20, 0x2009 => 0x20, 0x202F => 0x20,
        ];

        $s   = (string) $s;
        $len = strlen( $s );
        $aus = '';
        $i   = 0;
        while ( $i < $len ) {
            $c = ord( $s[ $i ] );
            if ( $c < 0x80 ) { $aus .= $s[ $i ]; $i++; continue; }

            /* Länge der UTF-8-Folge am führenden Byte ablesen. */
            if    ( ( $c & 0xE0 ) === 0xC0 ) { $n = 2; $cp = $c & 0x1F; }
            elseif ( ( $c & 0xF0 ) === 0xE0 ) { $n = 3; $cp = $c & 0x0F; }
            elseif ( ( $c & 0xF8 ) === 0xF0 ) { $n = 4; $cp = $c & 0x07; }
            else { $aus .= '?'; $i++; continue; }

            if ( $i + $n > $len ) { $aus .= '?'; break; }
            $gut = true;
            for ( $k = 1; $k < $n; $k++ ) {
                $f = ord( $s[ $i + $k ] );
                if ( ( $f & 0xC0 ) !== 0x80 ) { $gut = false; break; }
                $cp = ( $cp << 6 ) | ( $f & 0x3F );
            }
            if ( ! $gut ) { $aus .= '?'; $i++; continue; }
            $i += $n;

            if ( $cp < 0x80 )                      $aus .= chr( $cp );
            elseif ( $cp >= 0xA0 && $cp <= 0xFF )  $aus .= chr( $cp );
            elseif ( isset( $karte[ $cp ] ) )      $aus .= chr( $karte[ $cp ] );
            /* Unbekannt: ein Fragezeichen ist sichtbar. Rohe Bytes
               stehen im PDF als Kauderwelsch und sehen nach einem
               Fehler in den Daten aus, statt nach einem fehlenden
               Zeichen. */
            else                                   $aus .= '?';
        }
        return $aus;
    }

    /**
     * Die Zeichenbreiten von Helvetica, in Tausendsteln der
     * Schriftgröße. Die Buchstaben mit Zeichen darüber sind genau so
     * breit wie die ohne — darum erben sie ihre Breite und müssen nicht
     * einzeln aufgeführt werden.
     */
    private static function breiten( $fett ) {
        static $zwischen = [];
        $k = $fett ? 'f' : 'n';
        if ( isset( $zwischen[ $k ] ) ) return $zwischen[ $k ];

        if ( $fett ) {
            $ascii = [ 278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,
                       556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,
                       975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,
                       667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,
                       333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,
                       611,611,389,556,333,611,556,778,556,556,500,389,280,389,584 ];
        } else {
            $ascii = [ 278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
                       556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
                       1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
                       667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
                       333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
                       556,556,333,500,278,556,500,722,500,500,500,334,260,334,584 ];
        }
        $tab = [];
        foreach ( $ascii as $i => $w ) $tab[ 32 + $i ] = $w;

        /* Oberer Bereich: erbt die Breite des Grundbuchstabens. */
        $erbe = [
            0xC0 => 'A', 0xC1 => 'A', 0xC2 => 'A', 0xC3 => 'A', 0xC4 => 'A', 0xC5 => 'A',
            0xC7 => 'C', 0xC8 => 'E', 0xC9 => 'E', 0xCA => 'E', 0xCB => 'E',
            0xCC => 'I', 0xCD => 'I', 0xCE => 'I', 0xCF => 'I', 0xD1 => 'N',
            0xD2 => 'O', 0xD3 => 'O', 0xD4 => 'O', 0xD5 => 'O', 0xD6 => 'O', 0xD8 => 'O',
            0xD9 => 'U', 0xDA => 'U', 0xDB => 'U', 0xDC => 'U', 0xDD => 'Y',
            0xE0 => 'a', 0xE1 => 'a', 0xE2 => 'a', 0xE3 => 'a', 0xE4 => 'a', 0xE5 => 'a',
            0xE7 => 'c', 0xE8 => 'e', 0xE9 => 'e', 0xEA => 'e', 0xEB => 'e',
            0xEC => 'i', 0xED => 'i', 0xEE => 'i', 0xEF => 'i', 0xF1 => 'n',
            0xF2 => 'o', 0xF3 => 'o', 0xF4 => 'o', 0xF5 => 'o', 0xF6 => 'o', 0xF8 => 'o',
            0xF9 => 'u', 0xFA => 'u', 0xFB => 'u', 0xFC => 'u', 0xFD => 'y', 0xFF => 'y',
        ];
        foreach ( $erbe as $code => $grund ) $tab[ $code ] = $tab[ ord( $grund ) ];

        /* Der Rest, soweit er auf einem Beleg vorkommt. */
        $einzeln = $fett
            ? [ 0x80 => 556, 0x82 => 278, 0x84 => 500, 0x85 => 1000, 0x91 => 278,
                0x92 => 278, 0x93 => 500, 0x94 => 500, 0x96 => 556, 0x97 => 1000,
                0xA0 => 278, 0xA3 => 556, 0xA7 => 556, 0xA9 => 737, 0xAB => 556,
                0xB0 => 400, 0xB1 => 584, 0xB5 => 611, 0xB7 => 278, 0xBB => 556,
                0xC6 => 1000, 0xD7 => 584, 0xDF => 611, 0xE6 => 889, 0xF7 => 584 ]
            : [ 0x80 => 556, 0x82 => 222, 0x84 => 333, 0x85 => 1000, 0x91 => 222,
                0x92 => 222, 0x93 => 333, 0x94 => 333, 0x96 => 556, 0x97 => 1000,
                0xA0 => 278, 0xA3 => 556, 0xA7 => 556, 0xA9 => 737, 0xAB => 556,
                0xB0 => 400, 0xB1 => 584, 0xB5 => 556, 0xB7 => 278, 0xBB => 556,
                0xC6 => 1000, 0xD7 => 584, 0xDF => 556, 0xE6 => 889, 0xF7 => 584 ];
        foreach ( $einzeln as $code => $w ) $tab[ $code ] = $w;

        $zwischen[ $k ] = $tab;
        return $tab;
    }
}
