<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Datum und Zeit an einer Stelle.
 *
 * Im internen Bereich wird „heute" auf vier Arten bestimmt: 28× per
 * `date('Y-m-d')` (das ist UTC, weil WordPress die Zeitzone von PHP auf
 * UTC stellt), 3× per `current_time()` (Vereinszeit), 8× per SQL
 * `CURDATE()` (Zeitzone des Datenbankservers) und im Browser per
 * Gerätedatum. Zwischen 00:00 und 02:00 Vereinszeit liefern die vier
 * verschiedene Tage. Ein Training, das dann eingetragen wird, landet am
 * Vortag; eine Abrechnung rutscht ins falsche Quartal.
 *
 * Hier gilt eine Antwort: die Vereinszeit, also die in WordPress
 * eingestellte Zeitzone. Das ist die Zeit, in der der Verein trainiert,
 * und damit die einzige, die jemand nachvollziehen kann.
 */
class LSV07S_Datum {

    /** Für Prüfungen: ein festes „heute" setzen. */
    public static $heute_fest = null;

    public static function heute() {
        if ( self::$heute_fest !== null ) return self::$heute_fest;
        return current_time( 'Y-m-d' );
    }

    public static function jetzt() {
        if ( self::$heute_fest !== null ) return self::$heute_fest . ' 12:00:00';
        return current_time( 'Y-m-d H:i:s' );
    }

    /** Ist das ein Datum in der Form JJJJ-MM-TT, und gibt es den Tag? */
    public static function gueltig( $datum ) {
        $d = (string) $datum;
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) ) return false;
        return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
    }

    /**
     * Ein eingegebenes Datum säubern: JJJJ-MM-TT oder TT.MM.JJJJ herein,
     * JJJJ-MM-TT oder null heraus. Ein unmögliches Datum wird zu null und
     * nicht zu „0000-00-00" — eine Null, die wie ein Datum aussieht, wird
     * später irgendwo als 30.11.-0001 angezeigt.
     */
    public static function rein( $datum ) {
        /* Es gibt nur eine Prüfung, und die steht am Ende: Was gueltig()
           nicht als Datum erkennt, wird null. Eigene Abfragen für den
           leeren Text und für „0000-00-00" wären zweite Regeln für
           denselben Fall — der leere Text scheitert am Muster,
           „0000-00-00" an checkdate( 0, 0, 0 ). Beide standen hier, bis
           die Gegenprobe zeigte, dass sie nichts entscheiden. */
        $d = trim( (string) $datum );
        if ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $d, $m ) ) {
            $d = sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] );
        }
        return self::gueltig( $d ) ? $d : null;
    }

    /** „2026-04-02" → „02.04.2026". Ein leeres oder unmögliches Datum wird zu „–". */
    public static function de( $datum, $leer = '–' ) {
        $d = self::rein( $datum );
        if ( $d === null ) return $leer;
        return date_i18n( 'd.m.Y', strtotime( $d . ' 12:00:00' ) );
    }

    /** „2026-04-02 09:15:00" → „02.04.2026, 09:15". */
    public static function de_zeit( $zeitpunkt, $leer = '–' ) {
        $z = trim( (string) $zeitpunkt );
        if ( $z === '' || strpos( $z, '0000-00-00' ) === 0 ) return $leer;
        $t = strtotime( $z );
        if ( ! $t ) return $leer;
        return date_i18n( 'd.m.Y, H:i', $t );
    }

    /** „19:30:00" → „19:30". */
    public static function uhr( $zeit ) {
        return substr( (string) $zeit, 0, 5 );
    }

    /** „19:30:00"–„21:00:00" → „19:30–21:00" (Gedankenstrich, überall derselbe). */
    public static function spanne( $von, $bis ) {
        return self::uhr( $von ) . '–' . self::uhr( $bis );
    }

    /** Die Dauer in Stunden, kaufmännisch auf zwei Stellen. Über Mitternacht zählt als nächster Tag. */
    public static function dauer_stunden( $von, $bis ) {
        $a = strtotime( '2000-01-01 ' . $von );
        $b = strtotime( '2000-01-01 ' . $bis );
        if ( ! $a || ! $b ) return 0.0;
        if ( $b <= $a ) $b += DAY_IN_SECONDS;
        return round( ( $b - $a ) / 3600, 2 );
    }

    /** Wochentag als Zahl, Montag = 1 bis Sonntag = 7 — wie in den Slot-Tabellen. */
    public static function wochentag( $datum ) {
        $d = self::rein( $datum );
        if ( $d === null ) return 0;
        return (int) date( 'N', strtotime( $d . ' 12:00:00' ) );
    }

    const WOCHENTAGE = [
        1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag',
        5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag',
    ];

    public static function wochentag_name( $n, $kurz = false ) {
        $name = self::WOCHENTAGE[ (int) $n ] ?? '';
        return $kurz ? substr( $name, 0, 2 ) : $name;
    }

    // ── Quartal ──────────────────────────────────────────────────────────

    /** „2026-04-02" → „Q2". */
    public static function quartal( $datum = null ) {
        $d = self::rein( $datum ?? self::heute() ) ?? self::heute();
        return 'Q' . (int) ceil( (int) date( 'n', strtotime( $d . ' 12:00:00' ) ) / 3 );
    }

    public static function jahr( $datum = null ) {
        $d = self::rein( $datum ?? self::heute() ) ?? self::heute();
        return (int) date( 'Y', strtotime( $d . ' 12:00:00' ) );
    }

    /** „Q2", 2026 → [ '2026-04-01', '2026-06-30' ]. */
    public static function quartal_spanne( $quartal, $jahr ) {
        $n     = max( 1, min( 4, (int) ltrim( (string) $quartal, 'Qq' ) ) );
        $jahr  = (int) $jahr;
        $start = sprintf( '%04d-%02d-01', $jahr, ( $n - 1 ) * 3 + 1 );
        $ende  = date( 'Y-m-t', strtotime( sprintf( '%04d-%02d-01', $jahr, $n * 3 ) ) );
        return [ $start, $ende ];
    }

    public static function quartal_name( $quartal, $jahr = 0 ) {
        $monate = [ 1 => 'Jan–Mär', 2 => 'Apr–Jun', 3 => 'Jul–Sep', 4 => 'Okt–Dez' ];
        $n = max( 1, min( 4, (int) ltrim( (string) $quartal, 'Qq' ) ) );
        return $n . '. Quartal' . ( $jahr ? ' ' . (int) $jahr : '' ) . ' (' . $monate[ $n ] . ')';
    }

    // ── Saison ───────────────────────────────────────────────────────────

    /**
     * Die laufende Saison, oder null.
     *
     * Im internen Bereich steht „Saisonbeginn ist die untere Grenze" an
     * sechs Stellen, an vier davon ist die Administration ausgenommen und
     * an zwei nicht — dasselbe Konto sieht also je nach Ansicht andere
     * Trainings. Hier wird die Saison geliefert und NICHT entschieden, wer
     * darüber hinausschauen darf; das entscheidet eine Stelle (siehe
     * `grenze()`).
     */
    public static function saison_aktiv() {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'saisons' ) ) return null;
        $z = $wpdb->get_row( 'SELECT * FROM ' . LSV07S_Tabellen::t( 'saisons' )
            . ' WHERE aktiv = 1 ORDER BY start_datum DESC LIMIT 1', ARRAY_A );
        return $z ?: null;
    }

    /** Die Saison, in der dieses Datum liegt — unabhängig davon, welche gerade läuft. */
    public static function saison_zu( $datum ) {
        global $wpdb;
        $d = self::rein( $datum );
        if ( $d === null || ! LSV07S_Tabellen::da( 'saisons' ) ) return null;
        $z = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'saisons' )
            . ' WHERE start_datum <= %s AND ( ende_datum IS NULL OR ende_datum >= %s )'
            . ' ORDER BY start_datum DESC LIMIT 1', $d, $d ), ARRAY_A );
        return $z ?: null;
    }

    /**
     * Ab welchem Datum darf diese Person Trainings sehen?
     *
     * Eine Stelle, eine Regel: Wer über die Saison hinausschauen darf
     * (Administration und Wart), bekommt null — also keine Grenze. Alle
     * anderen sehen ab Saisonbeginn. Vorher stand diese Regel sechsmal im
     * Code, mit zwei verschiedenen Antworten.
     */
    public static function grenze( $uid = 0 ) {
        if ( LSV07S_Rechte::sieht_alles( $uid ) ) return null;
        $s = self::saison_aktiv();
        return $s ? $s['start_datum'] : null;
    }
}
