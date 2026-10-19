<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CSV lesen und schreiben.
 *
 * Eine Vereins-CSV kommt aus Excel, aus LibreOffice, aus einer Vereinssoftware
 * oder aus dem Meldeprogramm des Verbands — und jede dieser Quellen macht es
 * anders. Darum wird hier nichts vorausgesetzt, sondern erkannt:
 *
 *  - **Trennzeichen**: Semikolon, Komma oder Tabulator. Deutsches Excel nimmt
 *    das Semikolon, englisches das Komma. Geraten wird an der Kopfzeile, und
 *    zwar ausserhalb von Anführungszeichen — sonst entschiede ein Komma in
 *    „Musterkind, Mia" über die ganze Datei.
 *  - **Zeichensatz**: UTF-8, oder, wenn das nicht aufgeht, Windows-1252. Das
 *    ist der Fall „Aus Excel gespeichert, ohne nachzudenken", und ohne diese
 *    Umschrift stünde dort „MÃ¼ller".
 *  - **BOM**: Excel schreibt eines. Bliebe es stehen, hiesse die erste Spalte
 *    nicht „Vorname", sondern „\xEF\xBB\xBFVorname" — und würde nicht erkannt.
 *  - **Zeilenenden**: CRLF, LF oder CR.
 *
 * Geschrieben wird dagegen bewusst eng: Semikolon, CRLF und ein BOM. Das ist
 * das Format, das ein Doppelklick unter Windows richtig öffnet — und eine
 * Musterdatei, die beim Öffnen zerfällt, ist keine Hilfe.
 */
class LSV07P_CSV {

    /** Höchstens so viele Zeilen je Datei. Darüber ist es kein Import mehr. */
    const MAX_ZEILEN = 2000;

    /** Höchstens so viele Bytes. Eine CSV mit 2000 Zeilen bleibt weit darunter. */
    const MAX_BYTES = 2097152;   // 2 MB

    /**
     * Eine CSV in Zeilen und Spalten zerlegen.
     *
     * Rückgabe: [ 'zeilen' => [ [ 'A', 'B' ], … ], 'trenner' => ';' ] oder
     * [ 'fehler' => '…' ].
     */
    public static function lesen( $roh ) {
        $roh = (string) $roh;

        if ( strlen( $roh ) > self::MAX_BYTES ) {
            return [ 'fehler' => 'Die Datei ist größer als 2 MB. So viel ist kein Import mehr.' ];
        }

        /* Das BOM zuerst — es steht vor dem ersten Spaltennamen. */
        if ( substr( $roh, 0, 3 ) === "\xEF\xBB\xBF" ) $roh = substr( $roh, 3 );

        /* Zeichensatz. `mb_check_encoding` ist hier die ganze Prüfung: Was
           gültiges UTF-8 ist, bleibt; alles andere kommt aus Windows. */
        if ( ! mb_check_encoding( $roh, 'UTF-8' ) ) {
            $roh = mb_convert_encoding( $roh, 'UTF-8', 'Windows-1252' );
        }

        $roh = str_replace( [ "\r\n", "\r" ], "\n", $roh );
        if ( trim( $roh ) === '' ) {
            return [ 'fehler' => 'Die Datei ist leer.' ];
        }

        $trenner = self::trenner_raten( $roh );
        $zeilen  = self::zerlegen( $roh, $trenner );

        /* Leere Zeilen am Ende und dazwischen fliegen raus — Excel hängt
           gern welche an, und eine leere Zeile ist kein Datensatz. */
        $aus = [];
        foreach ( $zeilen as $z ) {
            $leer = true;
            foreach ( $z as $f ) if ( trim( $f ) !== '' ) { $leer = false; break; }
            if ( ! $leer ) $aus[] = $z;
        }

        if ( ! $aus ) return [ 'fehler' => 'In der Datei steht keine einzige Zeile.' ];
        if ( count( $aus ) > self::MAX_ZEILEN + 1 ) {
            return [ 'fehler' => 'Die Datei hat mehr als ' . self::MAX_ZEILEN
                   . ' Zeilen. Teile sie bitte auf.' ];
        }

        return [ 'zeilen' => $aus, 'trenner' => $trenner ];
    }

    /**
     * Welches Trennzeichen es ist. Gezählt wird nur ausserhalb von
     * Anführungszeichen und nur in der ersten Zeile: Dort stehen die
     * Spaltennamen, und die enthalten selten Kommas.
     */
    private static function trenner_raten( $roh ) {
        $zeile = strtok( $roh, "\n" );
        if ( $zeile === false ) $zeile = $roh;

        $zahl = [ ';' => 0, ',' => 0, "\t" => 0 ];
        $in   = false;
        $len  = strlen( $zeile );
        for ( $i = 0; $i < $len; $i++ ) {
            $c = $zeile[ $i ];
            if ( $c === '"' ) { $in = ! $in; continue; }
            if ( $in ) continue;
            if ( isset( $zahl[ $c ] ) ) $zahl[ $c ]++;
        }

        arsort( $zahl );
        $beste = array_key_first( $zahl );
        /* Kommt keines vor, ist es eine einspaltige Datei. Das Semikolon ist
           dann so gut wie jedes andere. */
        return $zahl[ $beste ] > 0 ? $beste : ';';
    }

    /** Der eigentliche Zerleger — nach RFC 4180, mit "" als Anführungszeichen. */
    private static function zerlegen( $roh, $trenner ) {
        $zeilen = [];
        $feld   = '';
        $zeile  = [];
        $in     = false;
        $len    = strlen( $roh );

        for ( $i = 0; $i < $len; $i++ ) {
            $c = $roh[ $i ];

            if ( $in ) {
                if ( $c === '"' ) {
                    /* Zwei Anführungszeichen sind eines im Text. */
                    if ( $i + 1 < $len && $roh[ $i + 1 ] === '"' ) { $feld .= '"'; $i++; }
                    else $in = false;
                } else {
                    $feld .= $c;
                }
                continue;
            }

            if ( $c === '"' && $feld === '' ) { $in = true; continue; }
            if ( $c === $trenner ) { $zeile[] = $feld; $feld = ''; continue; }
            if ( $c === "\n" )     { $zeile[] = $feld; $zeilen[] = $zeile;
                                     $zeile = []; $feld = ''; continue; }
            $feld .= $c;
        }
        if ( $feld !== '' || $zeile ) { $zeile[] = $feld; $zeilen[] = $zeile; }

        foreach ( $zeilen as $n => $z ) {
            foreach ( $z as $m => $f ) $zeilen[ $n ][ $m ] = trim( $f );
        }
        return $zeilen;
    }

    /**
     * Einen Spaltennamen auf seine Kurzform bringen: klein, ohne Umlaute,
     * ohne Leerzeichen und ohne Satzzeichen. „DSV-ID", „dsv id" und „DSV_Id"
     * sind damit dasselbe — und niemand muss raten, wie die Spalte zu
     * schreiben ist.
     */
    public static function kurz( $name ) {
        $n = mb_strtolower( trim( (string) $name ), 'UTF-8' );
        /* Deutsche Umschrift: ä → ae. So heisst „Bahnlänge" dasselbe wie
           „Bahnlaenge", und das ist die Schreibweise, die ein Verein ohne
           Umlaut-Tastatur wählt. */
        $n = strtr( $n, [ 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
                          'á' => 'a', 'à' => 'a', 'é' => 'e', 'è' => 'e' ] );
        return (string) preg_replace( '/[^a-z0-9]/', '', $n );
    }

    /**
     * Beide Schreibweisen eines Namens: mit „ue" und mit „u".
     *
     * „Rücken", „Ruecken" und „Rucken" sind dasselbe Wort — nur schreibt es
     * jeder anders, je nachdem, ob die Tastatur Umlaute kann und ob jemand
     * die Umschrift kennt. Verglichen wird darum gegen beide Formen; sonst
     * fiele genau eine der drei Schreibweisen durch, und zwar schweigend.
     */
    public static function kurz_beide( $name ) {
        $a = self::kurz( $name );
        $b = strtr( $a, [ 'ae' => 'a', 'oe' => 'o', 'ue' => 'u' ] );
        return $a === $b ? [ $a ] : [ $a, $b ];
    }

    /**
     * Die Kopfzeile auf die Felder abbilden.
     *
     * `$felder` ist [ feldname => [ erlaubte Schreibweisen ] ]. Zurück kommt
     * [ feldname => Spaltennummer ] für alles, was gefunden wurde.
     */
    public static function kopf_zuordnen( array $kopf, array $felder ) {
        $spalten = [];
        foreach ( $kopf as $i => $name ) {
            foreach ( self::kurz_beide( $name ) as $k ) {
                if ( $k !== '' && ! isset( $spalten[ $k ] ) ) $spalten[ $k ] = $i;
            }
        }

        $aus = [];
        foreach ( $felder as $feld => $namen ) {
            foreach ( (array) $namen as $n ) {
                foreach ( self::kurz_beide( $n ) as $k ) {
                    if ( isset( $spalten[ $k ] ) ) { $aus[ $feld ] = $spalten[ $k ]; break 2; }
                }
            }
        }
        return $aus;
    }

    /** Ein Wert aus einer Zeile, nach der Zuordnung. */
    public static function wert( array $zeile, array $zuordnung, $feld, $standard = '' ) {
        if ( ! isset( $zuordnung[ $feld ] ) ) return $standard;
        $i = (int) $zuordnung[ $feld ];
        return isset( $zeile[ $i ] ) ? trim( (string) $zeile[ $i ] ) : $standard;
    }

    /**
     * Ein Ja/Nein-Feld. Deutsche und englische Schreibweisen, dazu 1/0 — was
     * ein Verein eben in eine Tabelle schreibt.
     */
    public static function ja( $wert ) {
        $w = self::kurz( $wert );
        return in_array( $w, [ '1', 'ja', 'j', 'x', 'yes', 'y', 'true', 'wahr' ], true );
    }

    /**
     * Zeilen als CSV schreiben: Semikolon, CRLF, BOM.
     *
     * Das BOM ist der Grund, warum die Musterdatei in Excel sofort richtig
     * aussieht statt als „MÃ¼ller" — und das Semikolon der Grund, warum sie
     * nicht in einer einzigen Spalte landet.
     */
    public static function schreiben( array $zeilen ) {
        $aus = "\xEF\xBB\xBF";
        foreach ( $zeilen as $z ) {
            $teile = [];
            foreach ( (array) $z as $f ) {
                $f = (string) $f;
                /* Nur was es braucht, wird eingefasst — eine Datei voller
                   Anführungszeichen liest sich im Editor schlecht. */
                if ( preg_match( '/[";\r\n]/', $f ) ) {
                    $f = '"' . str_replace( '"', '""', $f ) . '"';
                }
                $teile[] = $f;
            }
            $aus .= implode( ';', $teile ) . "\r\n";
        }
        return $aus;
    }
}
