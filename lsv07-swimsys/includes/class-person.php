<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ein Name, eine Schreibweise.
 *
 * Im internen Bereich wird „Nachname, Vorname" an 12 PHP-Stellen und 3×
 * in SQL gebaut, „Vorname Nachname" an 20 weiteren, im Skript 18× so und
 * 10× anders. Der Anzeigename mit Jahrgang entsteht an 6 Stellen, zweimal
 * mit und viermal ohne Jahr. Dieselbe Person heisst dadurch auf der
 * Startseite anders als in der Liste und im PDF anders als in der Mail.
 *
 * Hier gilt: Listen und Tabellen zeigen „Nachname, Vorname" (danach wird
 * sortiert), Anreden und Sätze zeigen „Vorname Nachname". Zwei Formen,
 * eine Stelle, kein Spielraum.
 */
class LSV07S_Person {

    /** Für Listen: „Mustermann, Erika". */
    public static function listen_name( $vorname, $nachname ) {
        $v = trim( (string) $vorname );
        $n = trim( (string) $nachname );
        if ( $n === '' ) return $v !== '' ? $v : 'Ohne Namen';
        return $v === '' ? $n : $n . ', ' . $v;
    }

    /** Für Sätze: „Erika Mustermann". */
    public static function satz_name( $vorname, $nachname ) {
        $v = trim( (string) $vorname );
        $n = trim( (string) $nachname );
        $z = trim( $v . ' ' . $n );
        return $z !== '' ? $z : 'Ohne Namen';
    }

    /**
     * Aus einer Datenbankzeile, egal welcher Sparte.
     *
     * Die Spalten heissen in allen drei Sparten first_name/last_name; die
     * Sparte sagt es trotzdem selbst, damit eine vierte Sparte mit anderen
     * Spaltennamen hier nichts bricht.
     */
    public static function aus_zeile( $zeile, $sparte = LSV07S_Sparte::SCHWIMMEN, $form = 'liste' ) {
        if ( ! is_array( $zeile ) ) return 'Ohne Namen';
        $v = $zeile[ LSV07S_Sparte::feld( $sparte, 'vorname' ) ] ?? '';
        $n = $zeile[ LSV07S_Sparte::feld( $sparte, 'nachname' ) ] ?? '';
        return $form === 'satz' ? self::satz_name( $v, $n ) : self::listen_name( $v, $n );
    }

    /** Der Jahrgang aus einem Geburtsdatum, oder 0. */
    public static function jahrgang( $geburtsdatum ) {
        $d = LSV07S_Datum::rein( $geburtsdatum );
        return $d === null ? 0 : (int) substr( $d, 0, 4 );
    }

    /**
     * Das Alter am Stichtag — in ganzen Jahren.
     *
     * Für Meldungen zählt im Schwimmsport der Jahrgang, nicht das Alter;
     * deshalb steht hier nur, was auch wirklich Alter heisst, und die
     * Oberfläche zeigt bei Wettkämpfen den Jahrgang.
     */
    public static function alter( $geburtsdatum, $stichtag = null ) {
        $d = LSV07S_Datum::rein( $geburtsdatum );
        if ( $d === null ) return 0;
        $s = LSV07S_Datum::rein( $stichtag ?? LSV07S_Datum::heute() ) ?? LSV07S_Datum::heute();
        $g = new DateTime( $d );
        $t = new DateTime( $s );
        return (int) $g->diff( $t )->y;
    }

    /** Der Name eines WordPress-Kontos — für Protokoll und Mitteilungen. */
    public static function konto_name( $uid ) {
        $u = get_user_by( 'id', (int) $uid );
        if ( ! $u ) return 'Konto ' . (int) $uid;
        $v = get_user_meta( $u->ID, 'first_name', true );
        $n = get_user_meta( $u->ID, 'last_name', true );
        if ( $v || $n ) return self::satz_name( $v, $n );
        return $u->display_name ?: $u->user_login;
    }
}
