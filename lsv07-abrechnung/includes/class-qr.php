<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * QR-Code — selbst gerechnet, ohne Fremdbibliothek.
 *
 * Gebraucht wird er für genau eine Sache: den **Girocode** (EPC069-12), den
 * jede deutsche Banking-App scannt. Die Kasse öffnet eine genehmigte
 * Rechnung, hält das Telefon davor und hat Empfänger, IBAN, Betrag und
 * Verwendungszweck in der Überweisungsmaske stehen — ohne eine Ziffer
 * abzutippen. Eine falsch abgetippte IBAN ist der häufigste Fehler bei
 * Vereinsüberweisungen, und er fällt erst auf, wenn das Geld weg ist.
 *
 * Warum selbst gerechnet und nicht mit einer Bibliothek:
 *
 *  - Der Inhalt eines Girocodes ist eine Bankverbindung mit Betrag. Sie
 *    darf das Haus nicht verlassen — kein fremder Dienst, der daraus ein
 *    Bild macht, und keine Abhängigkeit, die das irgendwann doch tut.
 *  - Ein Composer-Paket in einem WordPress-Plugin, das ein Verein per FTP
 *    hochlädt, ist eine Quelle für Ärger. Der Kodierer hier ist
 *    überschaubar und hat keine Abhängigkeiten.
 *
 * Umfang mit Absicht eng gehalten: **Byte-Modus, Fehlerkorrektur M,
 * Versionen 1 bis 13.** Genau das schreibt die EPC-Spezifikation vor, und
 * 331 Byte — ihre Obergrenze — passen exakt in Version 13-M. Mehr braucht
 * ein Girocode nicht, und was nicht gebraucht wird, wird auch nicht gebaut.
 */
class LSV07A_QR {

    /** Fehlerkorrektur M: je Version [ EC-Wörter je Block, Blöcke, Daten je Block ]. */
    const BLOeCKE = [
        1  => [ 10, [ [ 1, 16 ] ] ],
        2  => [ 16, [ [ 1, 28 ] ] ],
        3  => [ 26, [ [ 1, 44 ] ] ],
        4  => [ 18, [ [ 2, 32 ] ] ],
        5  => [ 24, [ [ 2, 43 ] ] ],
        6  => [ 16, [ [ 4, 27 ] ] ],
        7  => [ 18, [ [ 4, 31 ] ] ],
        8  => [ 22, [ [ 2, 38 ], [ 2, 39 ] ] ],
        9  => [ 22, [ [ 3, 36 ], [ 2, 37 ] ] ],
        10 => [ 26, [ [ 4, 43 ], [ 1, 44 ] ] ],
        11 => [ 30, [ [ 1, 50 ], [ 4, 51 ] ] ],
        12 => [ 22, [ [ 6, 36 ], [ 2, 37 ] ] ],
        13 => [ 22, [ [ 8, 37 ], [ 1, 38 ] ] ],
    ];

    /** Mittelpunkte der Ausrichtungsmuster je Version. */
    const AUSRICHTUNG = [
        1  => [],            2  => [ 6, 18 ],     3  => [ 6, 22 ],
        4  => [ 6, 26 ],     5  => [ 6, 30 ],     6  => [ 6, 34 ],
        7  => [ 6, 22, 38 ], 8  => [ 6, 24, 42 ], 9  => [ 6, 26, 46 ],
        10 => [ 6, 28, 50 ], 11 => [ 6, 30, 54 ], 12 => [ 6, 32, 58 ],
        13 => [ 6, 34, 62 ],
    ];

    private static $exp = null;
    private static $log = null;

    // ── Der Girocode ─────────────────────────────────────────────────────

    /**
     * Der Nutzinhalt eines Girocodes nach EPC069-12.
     *
     * Die Reihenfolge der Zeilen ist vorgeschrieben und darf sich nicht
     * ändern — die Banking-Apps lesen stur nach Zeilennummer. Darum steht
     * sie hier auch als Liste und nicht als assoziatives Feld: Eine
     * vertauschte Zeile wäre ein stiller Fehler, und der fällt erst in der
     * Überweisungsmaske auf.
     *
     * @return string|array Der Text, oder [ 'fehler' => '…' ].
     */
    public static function epc( array $f ) {
        $name = self::text( $f['name'] ?? '', 70 );
        $iban = self::iban( $f['iban'] ?? '' );
        $bic  = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $f['bic'] ?? '' ) ) );
        $betrag = round( (float) ( $f['betrag'] ?? 0 ), 2 );
        $zweck  = self::text( $f['zweck'] ?? '', 140 );

        if ( $name === '' ) return [ 'fehler' => 'Ohne Empfänger kein QR-Code.' ];
        if ( $iban === '' ) return [ 'fehler' => 'Die IBAN ist nicht gültig.' ];
        if ( $betrag < 0.01 ) return [ 'fehler' => 'Ohne Betrag kein QR-Code.' ];
        /* Die Obergrenze steht in der Spezifikation. Darüber nimmt keine App
           den Code an — besser hier sagen als dort scheitern. */
        if ( $betrag > 999999999.99 ) return [ 'fehler' => 'Der Betrag ist zu groß für einen Girocode.' ];
        if ( $bic !== '' && ! preg_match( '/^[A-Z0-9]{8}([A-Z0-9]{3})?$/', $bic ) ) {
            return [ 'fehler' => 'Die BIC ist nicht gültig. Sie darf auch leer bleiben.' ];
        }

        $zeilen = [
            'BCD',                              //  1 Dienstkennung
            '002',                              //  2 Version (002: BIC darf fehlen)
            '1',                                //  3 Zeichensatz 1 = UTF-8
            'SCT',                              //  4 SEPA Credit Transfer
            $bic,                               //  5 BIC (bei 002 freiwillig)
            $name,                              //  6 Empfänger
            $iban,                              //  7 IBAN
            'EUR' . number_format( $betrag, 2, '.', '' ),   //  8 Betrag
            '',                                 //  9 Zweck-Code (nicht benutzt)
            '',                                 // 10 Strukturierte Referenz
            $zweck,                             // 11 Verwendungszweck
            '',                                 // 12 Hinweis an den Zahler
        ];

        /* Nachlaufende Leerzeilen dürfen weg und sollen es auch: Jede
           gesparte Zeile ist Platz für den Verwendungszweck. */
        while ( $zeilen && end( $zeilen ) === '' ) array_pop( $zeilen );

        $text = implode( "\n", $zeilen );
        if ( strlen( $text ) > 331 ) {
            return [ 'fehler' => 'Empfänger und Verwendungszweck sind zusammen zu lang für '
                   . 'einen Girocode. Kürze den Verwendungszweck.' ];
        }
        return $text;
    }

    /**
     * Eine IBAN prüfen — Länge je Land und die Prüfziffer nach ISO 7064
     * (Modulo 97-10).
     *
     * Die Prüfziffer ist der Grund, warum das hier steht: Ein Zahlendreher
     * in der IBAN ergibt fast immer eine ungültige IBAN, und das hier merkt
     * es. Ohne diese Prüfung entstünde ein QR-Code, den die App klaglos
     * annimmt und den die Bank zwei Tage später zurückweist.
     *
     * @return string Die IBAN ohne Leerzeichen, oder '' wenn sie nicht gilt.
     */
    public static function iban( $roh ) {
        $i = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $roh ) );
        if ( strlen( $i ) < 15 || strlen( $i ) > 34 ) return '';
        if ( ! preg_match( '/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $i ) ) return '';

        $laenge = self::IBAN_LAENGE[ substr( $i, 0, 2 ) ] ?? 0;
        if ( $laenge && strlen( $i ) !== $laenge ) return '';

        /* Die ersten vier Zeichen nach hinten, Buchstaben zu Zahlen
           (A = 10 … Z = 35), dann Rest bei Division durch 97. Gerechnet
           wird stückweise, weil die Zahl sonst grösser wird als ein
           Integer. */
        $um   = substr( $i, 4 ) . substr( $i, 0, 4 );
        $rest = 0;
        $len  = strlen( $um );
        for ( $k = 0; $k < $len; $k++ ) {
            $c = $um[ $k ];
            $wert = ctype_digit( $c ) ? $c : (string) ( ord( $c ) - 55 );
            foreach ( str_split( $wert ) as $ziffer ) {
                $rest = ( $rest * 10 + (int) $ziffer ) % 97;
            }
        }
        return $rest === 1 ? $i : '';
    }

    /** Die vorgeschriebene IBAN-Länge je Land, soweit für den SEPA-Raum nötig. */
    const IBAN_LAENGE = [
        'AD' => 24, 'AT' => 20, 'BE' => 16, 'BG' => 22, 'CH' => 21, 'CY' => 28,
        'CZ' => 24, 'DE' => 22, 'DK' => 18, 'EE' => 20, 'ES' => 24, 'FI' => 18,
        'FR' => 27, 'GB' => 22, 'GI' => 23, 'GR' => 27, 'HR' => 21, 'HU' => 28,
        'IE' => 22, 'IS' => 26, 'IT' => 27, 'LI' => 21, 'LT' => 20, 'LU' => 20,
        'LV' => 21, 'MC' => 27, 'MT' => 31, 'NL' => 18, 'NO' => 15, 'PL' => 28,
        'PT' => 25, 'RO' => 24, 'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27,
        'VA' => 22,
    ];

    /**
     * Text für den Girocode: Zeilenumbrüche heraus (sie trennen die Felder!)
     * und auf die erlaubte Länge gekürzt.
     */
    private static function text( $s, $max ) {
        $s = preg_replace( '/[\r\n\t]+/', ' ', (string) $s );
        $s = trim( preg_replace( '/\s{2,}/', ' ', $s ) );
        return mb_substr( $s, 0, $max );
    }

    // ── Der Kodierer ─────────────────────────────────────────────────────

    /**
     * Aus Text eine Matrix machen.
     *
     * @return array|null [ 'groesse' => n, 'module' => [ [0|1, …], … ], 'version' => v ]
     */
    public static function matrix( $text ) {
        $text = (string) $text;
        $len  = strlen( $text );
        if ( $len === 0 ) return null;

        $version = self::version_fuer( $len );
        if ( ! $version ) return null;

        $bits = self::bitstrom( $text, $version );
        if ( $bits === null ) return null;

        $woerter = self::bloecke_verweben( $bits, $version );
        $groesse = 17 + 4 * $version;

        /* Acht Masken ausprobieren und die mit der geringsten Strafe nehmen.
           Das schreibt die Norm so vor, und es ist keine Feinheit: Eine
           schlechte Maske erzeugt Flächen, an denen ein Lesegerät die
           Orientierung verliert. */
        $beste = null; $beste_strafe = PHP_INT_MAX;
        for ( $maske = 0; $maske < 8; $maske++ ) {
            $m = self::setzen( $woerter, $version, $groesse, $maske );
            $strafe = self::strafe( $m, $groesse );
            if ( $strafe < $beste_strafe ) { $beste_strafe = $strafe; $beste = $m; }
        }

        return [ 'groesse' => $groesse, 'module' => $beste, 'version' => $version ];
    }

    private static function version_fuer( $bytes ) {
        foreach ( self::BLOeCKE as $v => $spez ) {
            $daten = 0;
            foreach ( $spez[1] as $gr ) $daten += $gr[0] * $gr[1];
            /* Kopf: 4 Bit Modus + Längenfeld (8 Bit bis Version 9, sonst 16). */
            $kopf = 4 + ( $v <= 9 ? 8 : 16 );
            if ( $bytes * 8 + $kopf <= $daten * 8 ) return $v;
        }
        return 0;
    }

    /** Modus, Länge, Daten, Abschluss und Auffüllbytes. */
    private static function bitstrom( $text, $version ) {
        $daten = 0;
        foreach ( self::BLOeCKE[ $version ][1] as $gr ) $daten += $gr[0] * $gr[1];

        $bits = '0100';                                   // Byte-Modus
        $bits .= str_pad( decbin( strlen( $text ) ), $version <= 9 ? 8 : 16, '0', STR_PAD_LEFT );
        for ( $i = 0, $n = strlen( $text ); $i < $n; $i++ ) {
            $bits .= str_pad( decbin( ord( $text[ $i ] ) ), 8, '0', STR_PAD_LEFT );
        }
        if ( strlen( $bits ) > $daten * 8 ) return null;

        /* Bis zu vier Nullbits als Abschluss, dann auf volle Bytes. */
        $bits .= str_repeat( '0', min( 4, $daten * 8 - strlen( $bits ) ) );
        if ( strlen( $bits ) % 8 ) $bits .= str_repeat( '0', 8 - strlen( $bits ) % 8 );

        /* Auffüllen mit 236 und 17 im Wechsel — so steht es in der Norm. */
        $fuell = [ '11101100', '00010001' ];
        $k = 0;
        while ( strlen( $bits ) < $daten * 8 ) { $bits .= $fuell[ $k % 2 ]; $k++; }

        $woerter = [];
        foreach ( str_split( $bits, 8 ) as $byte ) $woerter[] = bindec( $byte );
        return $woerter;
    }

    /**
     * Blöcke bilden, je Block die Fehlerkorrektur rechnen und beides
     * verweben.
     *
     * Das Verweben ist der Grund, warum ein Kratzer über den Code nicht den
     * ganzen Inhalt zerstört: Benachbarte Module gehören zu verschiedenen
     * Blöcken, und jeder Block kann für sich repariert werden.
     */
    private static function bloecke_verweben( array $woerter, $version ) {
        list( $ec_je_block, $gruppen ) = self::BLOeCKE[ $version ];

        $daten_bloecke = [];
        $ec_bloecke    = [];
        $pos = 0;
        foreach ( $gruppen as $gr ) {
            for ( $b = 0; $b < $gr[0]; $b++ ) {
                $block = array_slice( $woerter, $pos, $gr[1] );
                $pos  += $gr[1];
                $daten_bloecke[] = $block;
                $ec_bloecke[]    = self::rs( $block, $ec_je_block );
            }
        }

        $aus = [];
        $max = 0;
        foreach ( $daten_bloecke as $b ) $max = max( $max, count( $b ) );
        for ( $i = 0; $i < $max; $i++ ) {
            foreach ( $daten_bloecke as $b ) if ( isset( $b[ $i ] ) ) $aus[] = $b[ $i ];
        }
        for ( $i = 0; $i < $ec_je_block; $i++ ) {
            foreach ( $ec_bloecke as $b ) if ( isset( $b[ $i ] ) ) $aus[] = $b[ $i ];
        }
        return $aus;
    }

    // ── Reed-Solomon über GF(256) ────────────────────────────────────────

    private static function gf_init() {
        if ( self::$exp !== null ) return;
        self::$exp = array_fill( 0, 512, 0 );
        self::$log = array_fill( 0, 256, 0 );
        $x = 1;
        for ( $i = 0; $i < 255; $i++ ) {
            self::$exp[ $i ] = $x;
            self::$log[ $x ] = $i;
            $x <<= 1;
            if ( $x & 0x100 ) $x ^= 0x11D;   // das Generatorpolynom der Norm
        }
        for ( $i = 255; $i < 512; $i++ ) self::$exp[ $i ] = self::$exp[ $i - 255 ];
    }

    private static function gf_mul( $a, $b ) {
        if ( $a === 0 || $b === 0 ) return 0;
        return self::$exp[ self::$log[ $a ] + self::$log[ $b ] ];
    }

    /**
     * Das Generatorpolynom für n Fehlerkorrekturwörter:
     * (x − α⁰)(x − α¹) … (x − αⁿ⁻¹).
     *
     * Die Reihenfolge der Koeffizienten ist hier nicht Geschmackssache:
     * `$g[0]` ist der Koeffizient des HÖCHSTEN Grades und muss 1 sein —
     * genau darauf baut die Division in rs() auf. Andersherum aufgebaut
     * entsteht ein Code, der in sich stimmig aussieht und dessen
     * Fehlerkorrektur trotzdem falsch ist; kein Lesegerät nimmt ihn an.
     */
    private static function generator( $n ) {
        self::gf_init();
        $g = [ 1 ];
        for ( $i = 0; $i < $n; $i++ ) {
            $neu = array_fill( 0, count( $g ) + 1, 0 );
            foreach ( $g as $k => $wert ) {
                $neu[ $k ]     ^= $wert;                                  // ×x
                $neu[ $k + 1 ] ^= self::gf_mul( $wert, self::$exp[ $i ] ); // ×αⁱ
            }
            $g = $neu;
        }
        return $g;
    }

    /** Die Fehlerkorrekturwörter eines Blocks. */
    private static function rs( array $daten, $n ) {
        self::gf_init();
        $g    = self::generator( $n );
        $rest = array_merge( $daten, array_fill( 0, $n, 0 ) );

        for ( $i = 0; $i < count( $daten ); $i++ ) {
            $faktor = $rest[ $i ];
            if ( $faktor === 0 ) continue;
            foreach ( $g as $k => $wert ) {
                $rest[ $i + $k ] ^= self::gf_mul( $wert, $faktor );
            }
        }
        return array_slice( $rest, count( $daten ), $n );
    }

    // ── Die Matrix ───────────────────────────────────────────────────────

    /**
     * Funktionsmuster setzen, Daten im Zickzack einfüllen, maskieren und
     * die Format- und Versionsinformation eintragen.
     */
    private static function setzen( array $woerter, $version, $groesse, $maske ) {
        $m   = array_fill( 0, $groesse, array_fill( 0, $groesse, null ) );
        $fix = array_fill( 0, $groesse, array_fill( 0, $groesse, false ) );

        $setze = function ( $x, $y, $wert, $ist_fix ) use ( &$m, &$fix, $groesse ) {
            if ( $x < 0 || $y < 0 || $x >= $groesse || $y >= $groesse ) return;
            $m[ $y ][ $x ]   = $wert ? 1 : 0;
            $fix[ $y ][ $x ] = $ist_fix;
        };

        // Die drei Suchmuster samt Trennlinie
        foreach ( [ [ 0, 0 ], [ $groesse - 7, 0 ], [ 0, $groesse - 7 ] ] as $ecke ) {
            for ( $dy = -1; $dy <= 7; $dy++ ) {
                for ( $dx = -1; $dx <= 7; $dx++ ) {
                    $x = $ecke[0] + $dx; $y = $ecke[1] + $dy;
                    if ( $x < 0 || $y < 0 || $x >= $groesse || $y >= $groesse ) continue;
                    $rand = ( $dx === -1 || $dx === 7 || $dy === -1 || $dy === 7 );
                    $an = ! $rand && (
                        $dx === 0 || $dx === 6 || $dy === 0 || $dy === 6
                        || ( $dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4 ) );
                    $setze( $x, $y, $an, true );
                }
            }
        }

        // Die beiden Taktlinien
        for ( $i = 8; $i < $groesse - 8; $i++ ) {
            $setze( $i, 6, $i % 2 === 0, true );
            $setze( 6, $i, $i % 2 === 0, true );
        }

        // Die Ausrichtungsmuster — nie über einem Suchmuster
        $punkte = self::AUSRICHTUNG[ $version ];
        foreach ( $punkte as $cy ) {
            foreach ( $punkte as $cx ) {
                if ( ( $cx === 6 && $cy === 6 )
                  || ( $cx === 6 && $cy === $groesse - 7 )
                  || ( $cx === $groesse - 7 && $cy === 6 ) ) continue;
                for ( $dy = -2; $dy <= 2; $dy++ ) {
                    for ( $dx = -2; $dx <= 2; $dx++ ) {
                        $an = ( abs( $dx ) === 2 || abs( $dy ) === 2 || ( $dx === 0 && $dy === 0 ) );
                        $setze( $cx + $dx, $cy + $dy, $an, true );
                    }
                }
            }
        }

        // Das eine Modul, das immer dunkel ist
        $setze( 8, $groesse - 8, true, true );

        // Plätze für Format und Version freihalten
        foreach ( self::format_plaetze( $groesse ) as $pl ) $fix[ $pl[1] ][ $pl[0] ] = true;
        if ( $version >= 7 ) {
            foreach ( self::version_plaetze( $groesse ) as $pl ) $fix[ $pl[1] ][ $pl[0] ] = true;
        }

        /* Die Daten im Zickzack von unten rechts nach oben links. Spalte 6
           ist die senkrechte Taktlinie und wird übersprungen. */
        $bit = 0;
        $gesamt = count( $woerter ) * 8;
        $x = $groesse - 1;
        while ( $x > 0 ) {
            if ( $x === 6 ) $x--;
            for ( $k = 0; $k < $groesse; $k++ ) {
                $aufwaerts = ( intdiv( $groesse - 1 - $x, 2 ) % 2 ) === 0;
                $y = $aufwaerts ? $groesse - 1 - $k : $k;
                foreach ( [ $x, $x - 1 ] as $sx ) {
                    if ( $fix[ $y ][ $sx ] ) continue;
                    $wert = 0;
                    if ( $bit < $gesamt ) {
                        $wort = $woerter[ intdiv( $bit, 8 ) ];
                        $wert = ( $wort >> ( 7 - ( $bit % 8 ) ) ) & 1;
                    }
                    $bit++;
                    if ( self::maskiert( $maske, $sx, $y ) ) $wert ^= 1;
                    $m[ $y ][ $sx ] = $wert;
                }
            }
            $x -= 2;
        }

        self::format_eintragen( $m, $groesse, $maske );
        if ( $version >= 7 ) self::version_eintragen( $m, $groesse, $version );

        /* Was nie beschrieben wurde, ist hell. Ohne das bliebe dort null
           stehen, und das Bild hätte Löcher. */
        for ( $y = 0; $y < $groesse; $y++ ) {
            for ( $x2 = 0; $x2 < $groesse; $x2++ ) {
                if ( $m[ $y ][ $x2 ] === null ) $m[ $y ][ $x2 ] = 0;
            }
        }
        return $m;
    }

    private static function maskiert( $maske, $x, $y ) {
        switch ( $maske ) {
            case 0: return ( $y + $x ) % 2 === 0;
            case 1: return $y % 2 === 0;
            case 2: return $x % 3 === 0;
            case 3: return ( $y + $x ) % 3 === 0;
            case 4: return ( intdiv( $y, 2 ) + intdiv( $x, 3 ) ) % 2 === 0;
            case 5: return ( ( $y * $x ) % 2 + ( $y * $x ) % 3 ) === 0;
            case 6: return ( ( ( $y * $x ) % 2 + ( $y * $x ) % 3 ) % 2 ) === 0;
            case 7: return ( ( ( $y + $x ) % 2 + ( $y * $x ) % 3 ) % 2 ) === 0;
        }
        return false;
    }

    /** Die 15 Plätze der Formatinformation — zweimal, damit sie überlebt. */
    private static function format_plaetze( $groesse ) {
        $pl = [];
        for ( $i = 0; $i <= 5; $i++ )  $pl[] = [ 8, $i ];
        $pl[] = [ 8, 7 ]; $pl[] = [ 8, 8 ]; $pl[] = [ 7, 8 ];
        for ( $i = 9; $i <= 14; $i++ ) $pl[] = [ 14 - $i, 8 ];
        for ( $i = 0; $i <= 7; $i++ )  $pl[] = [ $groesse - 1 - $i, 8 ];
        for ( $i = 8; $i <= 14; $i++ ) $pl[] = [ 8, $groesse - 15 + $i ];
        return $pl;
    }

    private static function format_eintragen( array &$m, $groesse, $maske ) {
        /* Fehlerkorrektur M ist 00. Dahinter die Maske, dann BCH(15,5) und
           zum Schluss das vorgeschriebene XOR — ohne das wäre eine Maske 0
           ohne Daten ein Feld aus lauter Nullen und damit nicht zu finden. */
        $wert = ( 0b00 << 3 ) | $maske;
        $rest = $wert << 10;
        for ( $i = 14; $i >= 10; $i-- ) {
            if ( ( $rest >> $i ) & 1 ) $rest ^= 0x537 << ( $i - 10 );
        }
        $bits = ( ( $wert << 10 ) | $rest ) ^ 0x5412;

        /* Die erste Hälfte um das linke obere Suchmuster, die zweite unten
           und rechts — jedes Bit steht zweimal im Code. */
        $erste = [];
        for ( $i = 0; $i <= 5; $i++ )  $erste[] = [ 8, $i ];
        $erste[] = [ 8, 7 ]; $erste[] = [ 8, 8 ]; $erste[] = [ 7, 8 ];
        for ( $i = 9; $i <= 14; $i++ ) $erste[] = [ 14 - $i, 8 ];

        $zweite = [];
        for ( $i = 0; $i <= 7; $i++ )  $zweite[] = [ $groesse - 1 - $i, 8 ];
        for ( $i = 8; $i <= 14; $i++ ) $zweite[] = [ 8, $groesse - 15 + $i ];

        for ( $i = 0; $i < 15; $i++ ) {
            $b = ( $bits >> $i ) & 1;
            $m[ $erste[ $i ][1] ][ $erste[ $i ][0] ] = $b;
            $m[ $zweite[ $i ][1] ][ $zweite[ $i ][0] ] = $b;
        }
    }

    private static function version_plaetze( $groesse ) {
        $pl = [];
        for ( $i = 0; $i < 18; $i++ ) {
            $a = intdiv( $i, 3 );
            $b = $i % 3;
            $pl[] = [ $a, $groesse - 11 + $b ];
            $pl[] = [ $groesse - 11 + $b, $a ];
        }
        return $pl;
    }

    private static function version_eintragen( array &$m, $groesse, $version ) {
        $rest = $version << 12;
        for ( $i = 17; $i >= 12; $i-- ) {
            if ( ( $rest >> $i ) & 1 ) $rest ^= 0x1F25 << ( $i - 12 );
        }
        $bits = ( $version << 12 ) | $rest;
        for ( $i = 0; $i < 18; $i++ ) {
            $b = ( $bits >> $i ) & 1;
            $a = intdiv( $i, 3 );
            $c = $i % 3;
            $m[ $groesse - 11 + $c ][ $a ] = $b;
            $m[ $a ][ $groesse - 11 + $c ] = $b;
        }
    }

    // ── Die Bewertung der Masken ─────────────────────────────────────────

    private static function strafe( array $m, $n ) {
        $s = 0;

        // Regel 1: fünf und mehr gleiche Module in einer Reihe
        for ( $i = 0; $i < $n; $i++ ) {
            foreach ( [ 'reihe', 'spalte' ] as $richtung ) {
                $lauf = 1;
                for ( $k = 1; $k < $n; $k++ ) {
                    $a = $richtung === 'reihe' ? $m[ $i ][ $k - 1 ] : $m[ $k - 1 ][ $i ];
                    $b = $richtung === 'reihe' ? $m[ $i ][ $k ]     : $m[ $k ][ $i ];
                    if ( $a === $b ) { $lauf++; continue; }
                    if ( $lauf >= 5 ) $s += 3 + ( $lauf - 5 );
                    $lauf = 1;
                }
                if ( $lauf >= 5 ) $s += 3 + ( $lauf - 5 );
            }
        }

        // Regel 2: Blöcke aus 2 × 2 gleichen Modulen
        for ( $y = 0; $y < $n - 1; $y++ ) {
            for ( $x = 0; $x < $n - 1; $x++ ) {
                $v = $m[ $y ][ $x ];
                if ( $v === $m[ $y ][ $x + 1 ] && $v === $m[ $y + 1 ][ $x ]
                  && $v === $m[ $y + 1 ][ $x + 1 ] ) $s += 3;
            }
        }

        // Regel 3: das Muster 1:1:3:1:1 mit vier hellen Modulen daneben
        $muster1 = [ 1,0,1,1,1,0,1,0,0,0,0 ];
        $muster2 = [ 0,0,0,0,1,0,1,1,1,0,1 ];
        for ( $y = 0; $y < $n; $y++ ) {
            for ( $x = 0; $x <= $n - 11; $x++ ) {
                $reihe = array_slice( $m[ $y ], $x, 11 );
                if ( $reihe === $muster1 || $reihe === $muster2 ) $s += 40;
            }
        }
        for ( $x = 0; $x < $n; $x++ ) {
            for ( $y = 0; $y <= $n - 11; $y++ ) {
                $spalte = [];
                for ( $k = 0; $k < 11; $k++ ) $spalte[] = $m[ $y + $k ][ $x ];
                if ( $spalte === $muster1 || $spalte === $muster2 ) $s += 40;
            }
        }

        // Regel 4: das Verhältnis hell zu dunkel
        $dunkel = 0;
        foreach ( $m as $reihe ) $dunkel += array_sum( $reihe );
        $anteil = (int) floor( $dunkel * 100 / ( $n * $n ) );
        $s += 10 * min( abs( $anteil - 50 ), abs( 100 - $anteil - 50 ) ) / 5;

        return (int) $s;
    }

    // ── Ausgabe ──────────────────────────────────────────────────────────

    /**
     * Die Matrix als SVG.
     *
     * SVG und kein PNG: Es braucht keine Bildbibliothek, bleibt bei jeder
     * Größe scharf, ist ein paar Kilobyte gross und lässt sich in die Seite
     * schreiben, ohne dass eine zweite Anfrage an den Server geht. Gezeichnet
     * wird ein einziger Pfad — ein Rechteck je Modul wären bei Version 13
     * über 5000 Elemente.
     */
    public static function svg( $text, $kante = 320, $rand = 4 ) {
        $qr = self::matrix( $text );
        if ( ! $qr ) return '';

        $n     = $qr['groesse'];
        $ganz  = $n + 2 * $rand;
        $pfad  = '';
        for ( $y = 0; $y < $n; $y++ ) {
            for ( $x = 0; $x < $n; $x++ ) {
                if ( $qr['module'][ $y ][ $x ] ) {
                    $pfad .= 'M' . ( $x + $rand ) . ' ' . ( $y + $rand ) . 'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int) $kante
             . '" height="' . (int) $kante . '" viewBox="0 0 ' . $ganz . ' ' . $ganz . '"'
             . ' shape-rendering="crispEdges" role="img"'
             . ' aria-label="QR-Code für die Überweisung">'
             . '<rect width="' . $ganz . '" height="' . $ganz . '" fill="#fff"/>'
             . '<path d="' . $pfad . '" fill="#000"/></svg>';
    }

    /** Dieselbe Matrix als Liste von Zeilen aus 0 und 1 — für das PDF. */
    public static function reihen( $text ) {
        $qr = self::matrix( $text );
        return $qr ? $qr['module'] : [];
    }
}
