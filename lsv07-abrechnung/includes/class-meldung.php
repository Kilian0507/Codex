<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Fehlermeldungen, die an mehr als einer Stelle gebraucht werden.
 *
 * Eine Meldung soll drei Dinge sagen: WAS nicht ging, WARUM vermutlich,
 * und WAS JETZT zu tun ist. „Abrechnung nicht gefunden." sagt nur das
 * erste — und stand an elf Stellen, in drei Schreibweisen.
 *
 * Zwei Grenzen, beide Absicht:
 *
 * 1. Eine Meldung verrät nicht, OB es etwas gibt, das man nicht sehen
 *    darf. „Gibt es nicht mehr, oder gehört nicht zu Ihrem Bereich" ist
 *    für beide Fälle derselbe Satz — sonst liesse sich an der Meldung
 *    ablesen, welche Kennungen vergeben sind.
 *
 * 2. Der Text der Datenbank geht NICHT an den Browser. In ihm stehen
 *    Tabellen- und Spaltennamen, manchmal der abgelehnte Wert. Er steht im
 *    Fehlerprotokoll des Servers, und die Meldung trägt eine Kennung, mit
 *    der er dort zu finden ist.
 */
class LSV07A_Meldung {

    /** Naht für die Prüfungen: Wohin das Protokoll schreibt. */
    public static $protokoll = null;

    const FEHLT = [
        'abrechnung' => 'Diese Abrechnung gibt es nicht mehr, oder sie gehört nicht zu Ihrem Bereich. '
                      . 'Wahrscheinlich wurde sie inzwischen gelöscht oder zurückgezogen — '
                      . 'bitte die Liste neu laden.',
        'posten'     => 'Diesen Posten gibt es nicht mehr. Wahrscheinlich wurde er in einem anderen '
                      . 'Fenster gelöscht — bitte die Abrechnung neu laden.',
        'saison'     => 'Diese Saison gibt es nicht mehr. Wahrscheinlich wurde sie in einem anderen '
                      . 'Fenster gelöscht — bitte die Liste neu laden.',
    ];

    public static function fehlt( $was ) {
        return self::FEHLT[ $was ] ?? 'Dieser Eintrag ist nicht mehr vorhanden. Bitte die Seite neu laden.';
    }

    public static function jahr() {
        return 'Dieses Jahr liegt ausserhalb des möglichen Bereichs (2020 bis 2100). '
             . 'Bitte ein anderes Jahr wählen.';
    }

    public static function quartal() {
        return 'Dieses Quartal gibt es nicht — möglich sind das 1. bis 4. Quartal. '
             . 'Bitte das Quartal neu wählen.';
    }

    /**
     * Die Datenbank hat etwas abgelehnt.
     *
     * @param string $was  Ein ganzer Satz: was nicht ging.
     * @param string $rat  Ein Hinweis, wenn es einen naheliegenden Grund gibt.
     */
    public static function datenbank( $was, $rat = '' ) {
        global $wpdb;
        $kennung = strtoupper( bin2hex( random_bytes( 3 ) ) );
        $grund   = isset( $wpdb->last_error ) && $wpdb->last_error !== ''
                 ? $wpdb->last_error : 'kein Fehlertext von der Datenbank';
        self::protokolliere( 'lsv07-abrechnung [' . $kennung . '] ' . $was . ' — ' . $grund );

        return rtrim( $was ) . ' Die Datenbank hat den Eintrag nicht angenommen; gespeichert wurde nichts. '
             . ( $rat !== '' ? rtrim( $rat ) . ' ' : '' )
             . 'Bitte noch einmal versuchen. Bleibt es dabei, die Administration verständigen '
             . 'und diese Kennung nennen: ' . $kennung . '.';
    }

    private static function protokolliere( $zeile ) {
        if ( self::$protokoll !== null ) { call_user_func( self::$protokoll, $zeile ); return; }
        error_log( $zeile );
    }

    /** „8M" → Bytes. Eigene Rechnung, damit die Klasse ohne WordPress prüfbar bleibt. */
    public static function bytes( $angabe ) {
        $angabe = trim( (string) $angabe );
        if ( $angabe === '' ) return 0;
        $zahl = (float) $angabe;
        switch ( strtolower( substr( $angabe, -1 ) ) ) {
            case 'g': $zahl *= 1024;
            case 'm': $zahl *= 1024;
            case 'k': $zahl *= 1024;
        }
        return (int) $zahl;
    }

    private static function mb( $bytes ) {
        return str_replace( '.', ',', (string) round( $bytes / 1048576, 1 ) ) . ' MB';
    }

    /**
     * Was dieser Server je Datei höchstens annimmt: die kleinste der drei
     * Grenzen (eigene, upload_max_filesize, post_max_size). 0 heisst bei
     * PHP „keine Grenze" und zählt darum nicht mit.
     */
    public static function upload_grenze( $eigene, $datei = null, $anfrage = null ) {
        $datei   = self::bytes( $datei   === null ? ini_get( 'upload_max_filesize' ) : $datei );
        $anfrage = self::bytes( $anfrage === null ? ini_get( 'post_max_size' )       : $anfrage );
        $grenze  = (int) $eigene;
        foreach ( [ $datei, $anfrage ] as $g ) if ( $g > 0 && $g < $grenze ) $grenze = $g;
        return $grenze;
    }

    /**
     * Ist die ganze Anfrage zu gross gewesen?
     *
     * Dann wirft PHP sie WEG — Formularfelder und Datei, alles. Übrig
     * bleibt eine leere Anfrage ohne Einmal-Schlüssel, und die bekäme
     * „Ungültige Anfrage, Seite neu laden" zu hören: falsch und nutzlos,
     * denn nach dem Neuladen passiert dasselbe. Der wahre Grund ist die
     * Grösse, und nur der hilft weiter.
     *
     * @return string Die Meldung, oder '' wenn es daran nicht lag.
     */
    public static function anfrage_zu_gross( $laenge = null, $grenze = null, $leer = null ) {
        $laenge = $laenge === null ? (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) : (int) $laenge;
        $grenze = self::bytes( $grenze === null ? ini_get( 'post_max_size' ) : $grenze );
        $leer   = $leer === null ? ( empty( $_POST ) && empty( $_FILES ) ) : (bool) $leer;
        if ( ! $leer || $grenze <= 0 || $laenge <= $grenze ) return '';
        return 'Die Datei ist zu gross für diesen Server: Angekommen wären ' . self::mb( $laenge )
             . ', angenommen werden höchstens ' . self::mb( $grenze ) . ' je Anfrage. '
             . 'Bitte den Beleg mit weniger Auflösung fotografieren oder als kleineres PDF '
             . 'hochladen. Gespeichert wurde nichts.';
    }

    /** Die Fehlernummern, die PHP zu einem Upload meldet — übersetzt. */
    public static function upload( $code, $eigene_grenze ) {
        switch ( (int) $code ) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'Die Datei ist zu gross: Dieser Server nimmt höchstens '
                     . self::mb( self::upload_grenze( $eigene_grenze ) ) . ' je Datei an. '
                     . 'Bitte den Beleg mit weniger Auflösung fotografieren oder als kleineres PDF hochladen.';
            case UPLOAD_ERR_PARTIAL:
                return 'Die Datei kam nur zum Teil an — meist reisst dabei die Verbindung ab. '
                     . 'Bitte noch einmal hochladen, am besten mit stabilem Netz.';
            case UPLOAD_ERR_NO_FILE:
                return 'Es wurde keine Datei ausgewählt. Bitte einen Beleg wählen und noch einmal hochladen.';
            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE:
                return 'Der Server konnte die Datei nicht zwischenspeichern — sein Zwischenspeicher '
                     . 'fehlt, ist voll oder nicht beschreibbar. Das kann nur die Administration '
                     . 'beheben; an der Datei liegt es nicht.';
            case UPLOAD_ERR_EXTENSION:
                return 'Der Server hat den Upload abgewiesen, bevor er hier ankam (eine '
                     . 'Sicherheitsregel des Servers). Bitte die Administration verständigen.';
        }
        return 'Der Upload ist fehlgeschlagen, ohne dass der Server einen Grund nennt. '
             . 'Bitte noch einmal versuchen.';
    }
}
