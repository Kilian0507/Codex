<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LSV07I_Dsv7 — liest eine Ausschreibung im DSV-Standard „Format 7“
 * (Wettkampfdefinitionsliste, Stand 31.08.2022).
 *
 * Grundsatz: Nichts wird geraten. Eine Zeile, die nicht genau dem Standard
 * entspricht, wird nicht „irgendwie“ übernommen, sondern als Hinweis
 * gemeldet. Betrifft sie Wertungen oder Pflichtzeiten eines Wettkampfs,
 * prüft die Meldung dort keine Jahrgänge bzw. Pflichtzeiten — eine falsche
 * Sperre oder Freigabe wäre schlimmer als keine.
 *
 * Gelesen werden nur die Teile, die der interne Bereich braucht:
 * VERANSTALTUNG, MELDESCHLUSS, ABSCHNITT, WETTKAMPF, WERTUNG, PFLICHTZEIT.
 * Adressen, Bankverbindung und Meldegelder bleiben unbeachtet und werden
 * nirgends gespeichert. Die Datei selbst wird nicht abgelegt.
 */
class LSV07I_Dsv7 {

    const MAX_BYTES   = 1048576;   // 1 MB — echte Ausschreibungen sind wenige KB
    const MAX_ZEILEN  = 20000;
    const MAX_HINWEISE = 60;

    const TECHNIK  = [ 'F' => 'Freistil', 'R' => 'Rücken', 'B' => 'Brust', 'S' => 'Schmetterling', 'L' => 'Lagen', 'X' => 'Sonderform' ];
    const AUSUEBUNG = [ 'GL' => '', 'BE' => 'Beine', 'AR' => 'Arme', 'ST' => 'Start', 'WE' => 'Wende', 'GB' => 'Gleitübung', 'X' => 'Sonderform' ];
    const ARTEN    = [ 'V', 'Z', 'F', 'E' ];
    const MELDBAR  = [ 'V', 'E' ];   // die Vereinsmeldeliste kennt nur Vorlauf und Entscheidung

    /**
     * @return array{ok:bool, fehler:string[], hinweise:string[], daten:array}
     */
    public static function lesen( $roh ) {
        $fehler = []; $hinweise = [];
        $hinweis = function ( $t ) use ( &$hinweise ) { if ( count( $hinweise ) < self::MAX_HINWEISE ) $hinweise[] = $t; elseif ( count( $hinweise ) === self::MAX_HINWEISE ) $hinweise[] = 'Weitere Hinweise ausgelassen.'; };
        $raus = function ( $t ) use ( &$hinweise ) { return [ 'ok' => false, 'fehler' => [ $t ], 'hinweise' => $hinweise, 'daten' => [] ]; };

        $roh = (string) $roh;
        if ( $roh === '' ) return $raus( 'Die Datei ist leer.' );
        if ( strlen( $roh ) > self::MAX_BYTES ) return $raus( 'Die Datei ist zu gross (höchstens 1 MB).' );
        if ( strpos( $roh, "\0" ) !== false ) return $raus( 'Das ist keine Textdatei.' );
        if ( strncmp( $roh, "\xEF\xBB\xBF", 3 ) === 0 ) $roh = substr( $roh, 3 );
        if ( trim( $roh ) === '' ) return $raus( 'Die Datei ist leer.' );
        if ( ! self::utf8( $roh ) ) {
            $roh = self::aus_1252( $roh );
            $hinweis( 'Die Datei ist nicht in UTF-8 gespeichert (der Standard verlangt es). Sie wurde als Windows-Zeichensatz gelesen — bitte Umlaute in der Vorschau prüfen.' );
        }

        $zeilen = preg_split( '/\r\n|\n|\r/', $roh );
        if ( count( $zeilen ) > self::MAX_ZEILEN ) return $raus( 'Die Datei hat zu viele Zeilen.' );

        $el = [];   // [ [ name, attribute[], zeilennr ] ]
        foreach ( $zeilen as $i => $z ) {
            $nr = $i + 1;
            $z  = trim( $z );
            if ( $z === '' ) continue;
            if ( strncmp( $z, '(*', 2 ) === 0 ) {
                if ( substr( $z, -2 ) !== '*)' ) $hinweis( "Zeile $nr: Kommentar ohne Abschluss „*)“ — übersprungen." );
                continue;
            }
            if ( preg_match( '/^DATEIENDE\s*;?$/i', $z ) ) { $el[] = [ 'DATEIENDE', [], $nr ]; continue; }
            if ( ! preg_match( '/^([A-Za-z]+)\s*:(.*)$/s', $z, $m ) ) {
                $hinweis( "Zeile $nr ist kein Element des Standards — übersprungen: „" . self::kurz( $z, 40 ) . '“' );
                continue;
            }
            $rest = $m[2];
            if ( substr( rtrim( $rest ), -1 ) === ';' ) $rest = substr( rtrim( $rest ), 0, -1 );
            $attr = array_map( 'trim', explode( ';', $rest ) );
            $el[] = [ strtoupper( $m[1] ), $attr, $nr ];
        }

        if ( ! $el || $el[0][0] !== 'FORMAT' ) return $raus( 'Die Datei beginnt nicht mit FORMAT — das ist keine DSV-Datei.' );
        $fmt = $el[0][1];
        if ( strcasecmp( $fmt[0] ?? '', 'Wettkampfdefinitionsliste' ) !== 0 ) {
            return $raus( 'Das ist keine Ausschreibung (Wettkampfdefinitionsliste), sondern: „' . self::kurz( $fmt[0] ?? '', 40 ) . '“.' );
        }
        if ( ( $fmt[1] ?? '' ) !== '7' ) return $raus( 'Nur Format 7 wird gelesen, die Datei hat Format „' . self::kurz( $fmt[1] ?? '', 5 ) . '“.' );
        if ( end( $el )[0] !== 'DATEIENDE' ) return $raus( 'Die Datei endet nicht mit DATEIENDE — sie ist vermutlich unvollständig.' );

        $daten = [ 'veranstaltung' => null, 'meldeschluss' => null, 'abschnitte' => [], 'wettkaempfe' => [], 'nicht_meldbar' => 0 ];
        $wk_alle = [];      // "nr|art" => Wettkampf
        $wertung = [];      // "nr|art" => [ [von,bis,g], … ] oder false (unsicher)
        $pflicht = [];      // "nr|art" => [ [von,bis,hs,g], … ]
        $ak      = [];      // "nr|art" => true: Altersklassen-Wertung

        foreach ( $el as [ $name, $a, $nr ] ) {
            switch ( $name ) {
                case 'VERANSTALTUNG':
                    if ( count( $a ) < 4 || $a[0] === '' || $a[1] === '' ) { $hinweis( "Zeile $nr: VERANSTALTUNG unvollständig." ); break; }
                    $daten['veranstaltung'] = [ 'name' => self::text( $a[0], 200 ), 'ort' => self::text( $a[1], 200 ),
                                                'bahn' => self::text( $a[2], 5 ), 'zeitmessung' => self::text( $a[3], 20 ) ];
                    break;

                case 'MELDESCHLUSS':
                    $d = self::datum( $a[0] ?? '' ); $u = self::uhrzeit( $a[1] ?? '' );
                    if ( ! $d || ! $u ) { $hinweis( "Zeile $nr: MELDESCHLUSS nicht lesbar — nicht übernommen." ); break; }
                    $daten['meldeschluss'] = $d . ' ' . $u . ':00';
                    break;

                case 'ABSCHNITT':
                    $anr = $a[0] ?? ''; $d = self::datum( $a[1] ?? '' );
                    if ( ! preg_match( '/^\d{1,2}$/', $anr ) || ! $d ) { $hinweis( "Zeile $nr: ABSCHNITT nicht lesbar — übersprungen." ); break; }
                    if ( isset( $daten['abschnitte'][ (int) $anr ] ) ) { $hinweis( "Zeile $nr: Abschnitt $anr steht doppelt — der erste gilt." ); break; }
                    $relativ = strtoupper( $a[5] ?? '' ) === 'J';
                    $daten['abschnitte'][ (int) $anr ] = [ 'nr' => (int) $anr, 'datum' => $d,
                        'beginn' => $relativ ? '' : ( self::uhrzeit( $a[4] ?? '' ) ?: '' ) ];
                    break;

                case 'WETTKAMPF':
                    $w = self::wettkampf( $a );
                    if ( is_string( $w ) ) { $hinweis( "Zeile $nr: WETTKAMPF nicht lesbar ($w) — übersprungen." ); break; }
                    $key = $w['nr'] . '|' . $w['art'];
                    if ( isset( $wk_alle[ $key ] ) ) { $hinweis( "Zeile $nr: Wettkampf {$w['nr']} ({$w['art']}) steht doppelt — der erste gilt." ); break; }
                    $w['zeile'] = $nr;
                    $wk_alle[ $key ] = $w;
                    break;

                case 'WERTUNG':
                    $key = self::schluessel( $a );
                    if ( ! $key ) { $hinweis( "Zeile $nr: WERTUNG nicht lesbar — übersprungen." ); break; }
                    $typ = strtoupper( $a[3] ?? '' );
                    if ( $typ === 'AK' ) { $ak[ $key ] = true; break; }
                    $r = $typ === 'JG' ? self::bereich( $a[4] ?? '', $a[5] ?? '' ) : null;
                    $g = strtoupper( $a[6] ?? '' );
                    if ( ! $r || ! in_array( $g, [ '', 'M', 'W', 'X', 'D' ], true ) ) {
                        $wertung[ $key ] = false;
                        $hinweis( "Zeile $nr: WERTUNG für Wettkampf " . explode( '|', $key )[0] . ' nicht lesbar — dort werden keine Jahrgänge geprüft.' );
                        break;
                    }
                    if ( ( $wertung[ $key ] ?? null ) !== false ) $wertung[ $key ][] = [ $r[0], $r[1], $g ];
                    break;

                case 'PFLICHTZEIT':
                    $key = self::schluessel( $a );
                    $typ = strtoupper( $a[2] ?? '' );
                    $r   = $typ === 'JG' ? self::bereich( $a[3] ?? '', $a[4] ?? '' ) : null;
                    $hs  = self::zeit( $a[5] ?? '' );
                    $g   = strtoupper( $a[6] ?? '' );
                    if ( ! $key || ! $r || $hs === null || ! in_array( $g, [ '', 'M', 'W', 'D' ], true ) ) {
                        $hinweis( "Zeile $nr: PFLICHTZEIT nicht lesbar" . ( $typ === 'AK' ? ' (Altersklasse)' : '' ) . ' — dort wird keine Pflichtzeit geprüft.' );
                        if ( $key ) $pflicht[ $key ] = false;
                        break;
                    }
                    if ( ( $pflicht[ $key ] ?? null ) !== false ) $pflicht[ $key ][] = [ $r[0], $r[1], $hs, $g ];
                    break;
            }
        }

        if ( ! $daten['veranstaltung'] ) $fehler[] = 'VERANSTALTUNG fehlt oder ist unvollständig.';
        if ( ! $daten['abschnitte'] )    $fehler[] = 'Kein lesbarer ABSCHNITT — die Tage lassen sich nicht bestimmen.';
        if ( ! $wk_alle )                $fehler[] = 'Kein lesbarer WETTKAMPF.';

        // Meldbare Wettkämpfe in der Reihenfolge der Datei
        $nrs = [];
        foreach ( $wk_alle as $key => $w ) {
            if ( ! in_array( $w['art'], self::MELDBAR, true ) ) { $daten['nicht_meldbar']++; continue; }
            if ( isset( $nrs[ $w['nr'] ] ) ) { $hinweis( "Zeile {$w['zeile']}: Wettkampf {$w['nr']} ist zweimal meldbar ausgeschrieben — nur der erste kommt in die Liste." ); continue; }
            $nrs[ $w['nr'] ] = true;
            if ( ! isset( $daten['abschnitte'][ $w['abschnitt'] ] ) ) $hinweis( "Wettkampf {$w['nr']}: Abschnitt {$w['abschnitt']} ist nicht ausgeschrieben." );

            $wl = $wertung[ $key ] ?? null;
            $w['wertungen'] = null;
            $w['jg_von'] = 0; $w['jg_bis'] = 0;
            if ( ! empty( $ak[ $key ] ) ) {
                $hinweis( "Wettkampf {$w['nr']}: Wertung nach Altersklassen — Jahrgänge werden nicht geprüft." );
            } elseif ( $wl === false ) {
                // schon gemeldet
            } elseif ( $wl ) {
                $w['wertungen'] = $wl;
                [ $w['jg_von'], $w['jg_bis'] ] = self::huelle( $wl );
            } else {
                $hinweis( "Wettkampf {$w['nr']}: keine Wertung angegeben — Jahrgänge werden nicht geprüft." );
            }
            $pl = $pflicht[ $key ] ?? null;
            $w['pflicht'] = is_array( $pl ) ? $pl : [];
            unset( $w['zeile'] );
            $daten['wettkaempfe'][] = $w;
        }
        if ( $daten['nicht_meldbar'] ) $hinweis( $daten['nicht_meldbar'] . ( $daten['nicht_meldbar'] === 1 ? ' Wettkampf ist ein Finale oder Zwischenlauf' : ' Wettkämpfe sind Finals oder Zwischenläufe' ) . ' — dafür meldet man nicht, sie kommen nicht in die Liste.' );
        if ( $wk_alle && ! $daten['wettkaempfe'] ) $fehler[] = 'Kein meldbarer Wettkampf (Vorlauf oder Entscheidung).';
        if ( count( $daten['wettkaempfe'] ) > 200 ) $fehler[] = 'Mehr als 200 meldbare Wettkämpfe sind nicht vorgesehen.';

        // Tage und Abschnitte je Tag
        ksort( $daten['abschnitte'] );
        $tage = [];
        foreach ( $daten['abschnitte'] as $ab ) $tage[ $ab['datum'] ] = ( $tage[ $ab['datum'] ] ?? 0 ) + 1;
        ksort( $tage );
        $daten['tage'] = array_map( fn( $d, $n ) => [ 'datum' => $d, 'abschnitte_plan' => min( 9, $n ) ], array_keys( $tage ), $tage );
        $daten['datum_von'] = $tage ? array_key_first( $tage ) : '';
        $daten['datum_bis'] = $tage ? array_key_last( $tage ) : '';
        $daten['abschnitte'] = array_values( $daten['abschnitte'] );
        if ( $tage && ( strtotime( $daten['datum_bis'] ) - strtotime( $daten['datum_von'] ) ) > 14 * DAY_IN_SECONDS ) {
            $fehler[] = 'Die Abschnitte liegen mehr als 14 Tage auseinander — so lange darf ein Wettkampf nicht dauern.';
        }

        return [ 'ok' => ! $fehler, 'fehler' => $fehler, 'hinweise' => $hinweise, 'daten' => $daten ];
    }

    /* ── Bausteine ─────────────────────────────────────────────────────── */

    /** WETTKAMPF: Nr;Art;Abschnitt;Anzahl;Strecke;Technik;Ausübung;Geschlecht;Zuordnung;QualNr;QualArt */
    private static function wettkampf( array $a ) {
        if ( count( $a ) < 9 ) return 'zu wenige Angaben';
        [ $nr, $art, $abs, $anz, $str, $tech, $aus, $g ] = array_map( 'strtoupper', array_slice( $a, 0, 8 ) );
        if ( ! preg_match( '/^\d{1,3}$/', $nr ) || (int) $nr < 1 ) return 'Nummer';
        if ( ! in_array( $art, self::ARTEN, true ) ) return 'Wettkampfart';
        if ( ! preg_match( '/^\d{1,2}$/', $abs ) ) return 'Abschnitt';
        if ( $anz !== '' && ( ! preg_match( '/^\d{1,2}$/', $anz ) || (int) $anz < 1 ) ) return 'Anzahl Starter';
        if ( ! preg_match( '/^\d{1,5}$/', $str ) || (int) $str > 25000 ) return 'Strecke';
        if ( ! isset( self::TECHNIK[ $tech ] ) ) return 'Technik';
        if ( ! array_key_exists( $aus, self::AUSUEBUNG ) ) return 'Ausübung';
        if ( ! in_array( $g, [ 'M', 'W', 'X' ], true ) ) return 'Geschlecht';
        $anz = $anz === '' ? 1 : (int) $anz;
        return [ 'nr' => (string) (int) $nr, 'art' => $art, 'abschnitt' => (int) $abs, 'ist_staffel' => $anz > 1 ? 1 : 0,
                 'strecke' => self::strecke( $anz, (int) $str, $tech, $aus ), 'geschlecht' => $g ];
    }

    /** Strecke im Format der Meldung: Kürzel, wo es eins gibt (50F), sonst Klartext. */
    public static function strecke( $anz, $meter, $tech, $aus ) {
        $bekannt = class_exists( 'LSV07I_Ajax_Bestzeiten' ) ? LSV07I_Ajax_Bestzeiten::STRECKEN : [];
        if ( $anz === 1 && $aus === 'GL' && $tech !== 'X' && isset( $bekannt[ $meter . $tech ] ) ) return $meter . $tech;
        $t = $meter === 0 ? 'Sonstige' : ( $anz > 1 ? $anz . 'x' . $meter : $meter . 'm' ) . ' ' . self::TECHNIK[ $tech ];
        if ( self::AUSUEBUNG[ $aus ] !== '' && ! ( $aus === 'X' && $tech === 'X' ) ) $t .= ' ' . self::AUSUEBUNG[ $aus ];
        return $t;
    }

    /** "Nr|Art" aus WERTUNG/PFLICHTZEIT — nur, wenn beides sauber ist. */
    private static function schluessel( array $a ) {
        $nr = $a[0] ?? ''; $art = strtoupper( $a[1] ?? '' );
        if ( ! preg_match( '/^\d{1,3}$/', $nr ) || ! in_array( $art, self::ARTEN, true ) ) return null;
        return (string) (int) $nr . '|' . $art;
    }

    /**
     * Jahrgangsbereich [von, bis], 0 = offen. Mindestjahrgang 0 = offene
     * Klasse; Höchstjahrgang leer = wie der Mindestjahrgang, 0 = „und
     * jünger“, 9999 = offen.
     */
    private static function bereich( $min, $max ) {
        if ( ! preg_match( '/^\d{1,4}$/', $min ) ) return null;
        $min = (int) $min;
        if ( $max === '' ) $max = $min;
        elseif ( ! preg_match( '/^\d{1,4}$/', $max ) ) return null;
        else $max = (int) $max;
        if ( $min !== 0 && ( $min < 1900 || $min > 2100 ) ) return null;
        if ( $max !== 0 && $max !== 9999 && ( $max < 1900 || $max > 2100 ) ) return null;
        $bis = ( $max === 0 || $max === 9999 ) ? 0 : $max;
        if ( $min && $bis && $min > $bis ) return null;
        return [ $min, $bis ];
    }

    /** Kleinster und grösster Jahrgang über alle Wertungen (0 = offen). */
    private static function huelle( array $wl ) {
        $von = PHP_INT_MAX; $bis = 0; $offen_v = false; $offen_b = false;
        foreach ( $wl as [ $v, $b ] ) {
            if ( ! $v ) $offen_v = true; else $von = min( $von, $v );
            if ( ! $b ) $offen_b = true; else $bis = max( $bis, $b );
        }
        return [ $offen_v ? 0 : $von, $offen_b ? 0 : $bis ];
    }

    /** Zeit HH:MM:SS,hh in Hundertstel; null, wenn nicht genau so. */
    public static function zeit( $t ) {
        if ( ! preg_match( '/^(\d{2}):([0-5]\d):([0-5]\d),(\d{2})$/', trim( (string) $t ), $m ) ) return null;
        $hs = ( ( (int) $m[1] * 60 + (int) $m[2] ) * 60 + (int) $m[3] ) * 100 + (int) $m[4];
        return $hs > 0 ? $hs : null;
    }

    /** Datum TT.MM.JJJJ → JJJJ-MM-TT; null, wenn es den Tag nicht gibt. */
    private static function datum( $t ) {
        if ( ! preg_match( '/^(\d{2})\.(\d{2})\.(\d{4})$/', trim( (string) $t ), $m ) ) return null;
        if ( ! checkdate( (int) $m[2], (int) $m[1], (int) $m[3] ) || (int) $m[3] < 2000 || (int) $m[3] > 2100 ) return null;
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    private static function uhrzeit( $t ) {
        return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', trim( (string) $t ) ) ? trim( (string) $t ) : null;
    }

    private static function text( $t, $max ) {
        $t = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $t ) ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $t, 0, $max ) : substr( $t, 0, $max );
    }

    private static function kurz( $t, $max ) {
        $t = self::text( $t, $max + 1 );
        return ( function_exists( 'mb_strlen' ) ? mb_strlen( $t ) : strlen( $t ) ) > $max ? self::text( $t, $max ) . '…' : $t;
    }

    private static function utf8( $s ) {
        return function_exists( 'mb_check_encoding' ) ? mb_check_encoding( $s, 'UTF-8' ) : (bool) preg_match( '//u', $s );
    }

    private static function aus_1252( $s ) {
        if ( function_exists( 'mb_convert_encoding' ) ) return mb_convert_encoding( $s, 'UTF-8', 'Windows-1252' );
        return function_exists( 'utf8_encode' ) ? utf8_encode( $s ) : '';
    }
}
