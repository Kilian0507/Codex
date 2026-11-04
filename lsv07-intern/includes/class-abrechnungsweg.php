<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * LSV07I_Abrechnungsweg
 * ---------------------
 * Wo wird abgerechnet — hier im internen Bereich, oder in einem eigenen
 * Plugin, zu dem nur ein Link führt?
 *
 * Bis 8.x rechnete der interne Bereich selbst ab: die Bereiche „Trainer"
 * (eigene Abrechnung, Sonderabrechnung, Stammdaten) und „Verwaltung"
 * (eingereichte Abrechnungen, Kassenwart). Mit 9.0.0 stand dort nur noch
 * ein Verweis nach draussen. Beides hat seinen Grund, und welcher gilt,
 * entscheidet der Verein — nicht dieses Plugin. Darum ist es eine
 * Einstellung der Administration (Admin → Verein → Konfiguration).
 *
 * Diese Klasse ist die EINZIGE Stelle, die weiss,
 *
 *   • welche Wege es gibt,
 *   • welcher gerade gilt,
 *   • und welche Bereiche der Navigation zu welchem Weg gehören.
 *
 * Alles andere fragt hier. Zwei Fassungen dieser Zuordnung — eine im
 * Template, eine in der Oberfläche — liefen beim ersten neuen Bereich
 * auseinander, und dann stünde eine Schaltfläche da, die auf eine
 * Ansicht zeigt, die es in diesem Weg nicht gibt.
 *
 * WICHTIG: Der Weg ist eine ANSICHT, kein Recht. Wer die Abrechnung
 * nicht sehen darf, sieht sie in keinem der beiden Wege — das
 * entscheiden weiter die Rechte (ABRECHNUNG_EIGEN_READ,
 * ABRECHNUNG_WART_READ, ABRECHNUNG_KASSE_READ). Ein Umschalten gibt
 * niemandem Zugriff und nimmt niemandem einen.
 */
class LSV07I_Abrechnungsweg {

    /** Der Schlüssel in der Konfigurationstabelle. */
    const SCHLUESSEL = 'abrechnung_modus';

    /** Abgerechnet wird hier, in den Bereichen Trainer und Verwaltung. */
    const INTERN = 'intern';

    /** Abgerechnet wird draussen; hier steht nur der Weg dorthin. */
    const LINK = 'link';

    /**
     * Ohne Einstellung gilt „intern".
     *
     * Das ist der Stand, den der Verein kennt: Die Abrechnungen liegen
     * seit Jahren hier, und eine Bestandsinstallation soll sie nach
     * einem Update nicht verloren haben. Wer nach draussen verweisen
     * will, sagt es einmal in der Konfiguration.
     */
    const STANDARD = self::INTERN;

    /** Wohin der Verweis führt, wenn „link" gilt. */
    const ZIEL = 'https://lsv07.info/lsv-abrechnung';

    /** Die Auswahl, wie sie in der Konfiguration steht. */
    const MODI = [
        self::INTERN => 'Im internen Bereich — im Bereich „Abrechnung"',
        self::LINK   => 'Über einen Link zum eigenen Abrechnungs-Plugin',
    ];

    /**
     * Welche Teile der Abrechnung gehören zu welchem Weg?
     *
     * Seit 9.5.0 ist „Abrechnung" EIN Bereich; der Weg entscheidet
     * nicht mehr, welcher Bereich angeboten wird, sondern was darin
     * steht. Geblieben ist die Frage für die Schnellzugriffe: Der
     * Schnellzugriff auf den Verweis gehört zum Weg „link", die auf die
     * Reiter zum Weg „intern".
     *
     * Ein Schlüssel, der hier nicht steht, hängt nicht am
     * Abrechnungsweg und wird von ihm auch nicht angefasst.
     */
    const BEREICHE = [
        'abr-link'   => self::LINK,
        'abr-intern' => self::INTERN,
    ];

    /**
     * Der geltende Weg.
     *
     * Was nicht zu den beiden bekannten Werten gehört, ist der Standard
     * — auch ein von Hand verbogener Eintrag in der Datenbank. Sonst
     * stünde bei einem Tippfehler weder das eine noch das andere da.
     */
    public static function modus() {
        $wert = class_exists( 'LSV07I_DB' )
            ? LSV07I_DB::get_config( self::SCHLUESSEL, self::STANDARD )
            : self::STANDARD;
        return isset( self::MODI[ $wert ] ) ? $wert : self::STANDARD;
    }

    /** Wird hier abgerechnet? */
    public static function ist_intern() {
        return self::modus() === self::INTERN;
    }

    /**
     * Bietet der geltende Weg diesen Teil an?
     *
     * Dieselbe Frage stellt die Oberfläche beim Umschalten —
     * beantwortet wird sie nur hier und in ihrer Entsprechung in
     * app.js, die sich auf dieselbe Tabelle stützt.
     */
    public static function bereich_offen( $sec ) {
        if ( ! isset( self::BEREICHE[ $sec ] ) ) return true;
        return self::BEREICHE[ $sec ] === self::modus();
    }

    /**
     * Den Weg setzen. Gibt den Wert zurück, der wirklich gilt.
     *
     * Geschrieben wird nur einer der beiden bekannten Werte. Was sonst
     * ankommt, wird nicht gespeichert: Die Auswahl kommt aus dem
     * Browser, und ein unbekannter Wert hiesse, dass hinterher keiner
     * der beiden Wege gilt.
     */
    public static function setzen( $wert ) {
        $wert = is_string( $wert ) ? trim( $wert ) : '';
        if ( isset( self::MODI[ $wert ] ) ) {
            LSV07I_DB::set_config( self::SCHLUESSEL, $wert );
        }
        return self::modus();
    }
}
