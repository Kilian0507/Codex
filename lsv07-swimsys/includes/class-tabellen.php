<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Jeder Tabellenname genau einmal.
 *
 * Im internen Bereich steht `$wpdb->prefix . 'lsv07i_anwesenheit'` an
 * Dutzenden Stellen. Beim Umbenennen einer Tabelle findet man 19 davon und
 * übersieht die zwanzigste — und die wirft erst beim ersten Aufruf einen
 * Fehler, in der Produktion. Hier steht jeder Name einmal, und wer ihn
 * falsch schreibt, bekommt es sofort gesagt.
 *
 * SwimSys legt von diesen Tabellen keine an (siehe Kopfkommentar des
 * Plugins). Es prüft nur, ob sie da sind.
 */
class LSV07S_Tabellen {

    /** Die Tabellen des internen Bereichs, die SwimSys benutzt. */
    const GETEILT = [
        // Stammdaten
        'mannschaften'   => 'lsv07_gruppen',
        'schwimmer'      => 'mv_swimmers',
        'schwimmer_mann' => 'lsv07i_swimmer_gruppen',
        'kontakte'       => 'lsv07i_kontakte',
        'schwimmer_dateien' => 'lsv07i_schwimmer_dateien',
        'trainer'        => 'lsv07i_trainer',
        'trainer_mann'   => 'lsv07i_trainer_mannschaft',
        // Training und Anwesenheit (Schwimmen)
        'slots'          => 'lsv07i_training_slots',
        'ausfall'        => 'lsv07i_training_ausfall',
        'anwesenheit'    => 'lsv07i_anwesenheit',
        'anw_eintrag'    => 'lsv07i_anwesenheit_eintraege',
        'mb_tag'         => 'lsv07i_mb_tag',
        'mb_anwesenheit' => 'lsv07i_mb_anwesenheit',
        'mb_eintrag'     => 'lsv07i_mb_eintrag',
        'springer'       => 'lsv07i_springer',
        // Triathlon
        'tri_gruppen'    => 'lsv07i_tri_gruppen',
        'tri_sportler'   => 'lsv07i_tri_sportler',
        'tri_sp_gruppe'  => 'lsv07i_tri_sportler_gruppe',
        'tri_trainer'    => 'lsv07i_tri_trainer',
        'tri_tr_gruppe'  => 'lsv07i_tri_trainer_gruppe',
        'tri_slots'      => 'lsv07i_tri_slots',
        'tri_anw'        => 'lsv07i_tri_anwesenheit',
        'tri_anw_eintr'  => 'lsv07i_tri_anwesenheit_eintraege',
        // Fitness
        'fit_gruppen'    => 'lsv07i_fit_gruppen',
        'fit_sportler'   => 'lsv07i_fit_sportler',
        'fit_sp_gruppe'  => 'lsv07i_fit_sportler_gruppe',
        'fit_trainer'    => 'lsv07i_fit_trainer',
        'fit_tr_gruppe'  => 'lsv07i_fit_trainer_gruppe',
        'fit_slots'      => 'lsv07i_fit_slots',
        'fit_anw'        => 'lsv07i_fit_anwesenheit',
        'fit_anw_eintr'  => 'lsv07i_fit_anwesenheit_eintraege',
        // Wettkampf und Meldung
        'wettkampf'      => 'lsv07i_wettkampf',
        'wk_mann'        => 'lsv07i_wettkampf_mannschaft',
        'wk_tage'        => 'lsv07i_wettkampf_tage',
        'wk_anw'         => 'lsv07i_wettkampf_anwesenheit',
        'wk_anw_eintr'   => 'lsv07i_wettkampf_anw_eintraege',
        'meldung'        => 'lsv07i_meldung',
        'meldung_start'  => 'lsv07i_meldung_start',
        'bestzeiten'     => 'lsv07i_bestzeiten',
        // Abrechnung
        'abrechnung'     => 'lsv07i_abrechnung',
        'abr_tage'       => 'lsv07i_abr_trainingstage',
        'abr_wk'         => 'lsv07i_abr_wettkampf',
        'abr_km'         => 'lsv07i_abr_kilometer',
        'abr_stamm'      => 'lsv07i_trainer_stammdaten',
        // Reden und Aufgaben
        'tickets'        => 'lsv07i_tickets',
        'ticket_komm'    => 'lsv07i_ticket_kommentare',
        'konv'           => 'lsv07i_konv',
        'konv_teil'      => 'lsv07i_konv_teilnehmer',
        'konv_msg'       => 'lsv07i_konv_msg',
        // Querschnitt
        'saisons'        => 'lsv07i_saisons',
        'config'         => 'lsv07i_config',
        'log'            => 'lsv07i_log',
        'rechte'         => 'lsv07i_user_rechte',
        'rechte_vorlage' => 'lsv07i_rechte_templates',
        'reflexion'      => 'lsv07i_refl_bogen',
        'personen'       => 'lsv07i_personen',
    ];

    /**
     * Ohne diese Tabellen kann SwimSys nichts anzeigen. Fehlt eine, war der
     * interne Bereich nie aktiv — dann soll die Oberfläche das sagen und
     * nicht in Datenbankfehlern enden.
     */
    const PFLICHT = [ 'mannschaften', 'schwimmer', 'config' ];

    private static $da = [];

    /** Der volle Tabellenname. Ein unbekannter Schlüssel ist ein Programmfehler. */
    public static function t( $schluessel ) {
        global $wpdb;
        if ( ! isset( self::GETEILT[ $schluessel ] ) ) {
            /* Lieber laut hier als still falsch in einer Abfrage: Ein
               Tippfehler im Schlüssel würde sonst zu "prefix" + leer und
               damit zu einer Abfrage auf die Tabelle `wp_`. */
            _doing_it_wrong( __METHOD__, 'Unbekannte Tabelle: ' . esc_html( (string) $schluessel ), '0.1.0' );
            return '';
        }
        return $wpdb->prefix . self::GETEILT[ $schluessel ];
    }

    /** Gibt es diese Tabelle? Einmal gefragt, bleibt die Antwort im Aufruf. */
    public static function da( $schluessel ) {
        if ( isset( self::$da[ $schluessel ] ) ) return self::$da[ $schluessel ];
        global $wpdb;
        $name = self::t( $schluessel );
        if ( $name === '' ) return self::$da[ $schluessel ] = false;
        $gefunden = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
        return self::$da[ $schluessel ] = ( $gefunden === $name );
    }

    /** Fehlt etwas Unverzichtbares? Dann die Liste, sonst ein leeres Feld. */
    public static function fehlt() {
        $fehlt = [];
        foreach ( self::PFLICHT as $s ) {
            if ( ! self::da( $s ) ) $fehlt[] = self::GETEILT[ $s ];
        }
        return $fehlt;
    }

    /** Für Prüfungen: die gemerkten Antworten wegwerfen. */
    public static function vergessen() { self::$da = []; }

    /** Eine Einstellung aus lsv07i_config — dieselbe Quelle wie im internen Bereich. */
    public static function config( $schluessel, $standard = '' ) {
        global $wpdb;
        if ( ! self::da( 'config' ) ) return $standard;
        $wert = $wpdb->get_var( $wpdb->prepare(
            'SELECT cfg_value FROM ' . self::t( 'config' ) . ' WHERE cfg_key = %s LIMIT 1',
            $schluessel ) );
        return ( $wert !== null ) ? $wert : $standard;
    }
}
