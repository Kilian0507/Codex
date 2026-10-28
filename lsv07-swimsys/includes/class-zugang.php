<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ein Tor für alle Endpunkte.
 *
 * Im internen Bereich gibt es drei Wege ins Haus: 20 Klassen fragen
 * `LSV07I_Access::check`, 11 haben ihr eigenes Tor, und die Prüfung des
 * Einmal-Schlüssels steht 15× als `check_ajax_referer` und 7× als
 * `wp_verify_nonce` von Hand. Entsprechend gibt es für „keine
 * Berechtigung" 16 verschiedene Wortlaute und für „ungültige Anfrage"
 * vier, zwei davon ohne Umlaute.
 *
 * Hier geht jeder Endpunkt durch `tor()`. Dieselbe Reihenfolge, dieselben
 * Sätze, derselbe HTTP-Code — und beim Lesen des Codes sieht man an einer
 * Zeile, welches Recht ein Endpunkt verlangt.
 */
class LSV07S_Zugang {

    const NONCE = 'lsv07s_nonce';

    /**
     * Anmeldung, Einmal-Schlüssel, Zugang, Recht — in dieser Reihenfolge.
     *
     * Bricht ab, wenn etwas fehlt. Es gibt keinen Rückgabewert, auf den
     * man vergessen könnte zu hören.
     *
     * @param string|array $recht Das verlangte Recht, oder mehrere (eines
     *                            genügt), oder '' für „nur angemeldet und
     *                            mit Zugang".
     */
    public static function tor( $recht = '' ) {
        if ( ! is_user_logged_in() ) {
            self::nein( 'Bitte melden Sie sich an.', 401 );
        }
        /* Der Schlüssel wird in GET und POST gesucht, weil Downloads ihn
           in der Adresse mitbringen. `check_ajax_referer` mit false bricht
           nicht selbst ab — wir wollen unsere eigene Meldung. */
        if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
            self::nein( 'Die Sitzung ist abgelaufen. Bitte laden Sie die Seite neu.', 403 );
        }
        $uid = get_current_user_id();
        if ( LSV07S_Rechte::gesperrt( $uid ) ) {
            /* Eigene Meldung, weil „keine Berechtigung" hier in die Irre
               führt: Es fehlt kein Recht, das Konto ist gesperrt. */
            self::nein( 'Dieses Konto ist gesperrt. Bitte wenden Sie sich an die Administration.', 403 );
        }
        if ( ! LSV07S_Rechte::hat_zugang( $uid ) ) {
            self::nein( 'Für dieses Konto sind in SwimSys noch keine Rechte vergeben.', 403 );
        }
        if ( $recht !== '' && $recht !== [] && ! LSV07S_Rechte::darf_eines( (array) $recht, $uid ) ) {
            self::nein( 'Sie haben keine Berechtigung für diese Aktion.', 403 );
        }
    }

    /** Zusätzlich: nur in dieser Mannschaft. */
    public static function tor_mannschaft( $recht, $mannschaft_id ) {
        self::tor( $recht );
        if ( ! LSV07S_Rechte::darf_mannschaft( $mannschaft_id ) ) {
            self::nein( 'Für diese Mannschaft fehlt Ihnen die Berechtigung.', 403 );
        }
    }

    /** Ein Fehler, immer gleich gebaut: Meldung plus HTTP-Code. */
    public static function nein( $meldung, $code = 403 ) {
        wp_send_json_error( [ 'message' => (string) $meldung ], (int) $code );
    }

    /** Ein Erfolg, immer gleich gebaut. */
    public static function ja( array $daten = [] ) {
        wp_send_json_success( $daten );
    }

    /** Der Schlüssel für die Oberfläche. */
    public static function schluessel() {
        return wp_create_nonce( self::NONCE );
    }
}
