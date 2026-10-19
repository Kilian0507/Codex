<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Endpunkte des CSV-Imports.
 *
 * Zwei Wege, und beide gehen durch dieselbe Prüfung: `pruefen` läuft trocken
 * und liefert die Vorschau, `fahren` schreibt. Die Musterdatei liegt bei den
 * Downloads (LSV07P_Ajax_Download::muster), weil sie wie jede andere Datei
 * über GET und mit Nonce herausgeht.
 */
class LSV07P_Ajax_Import {

    public static function init() {
        /* Kein nopriv: Ohne WordPress-Anmeldung gibt es hier nichts. */
        add_action( 'wp_ajax_lsv07p_i_pruefen', [ __CLASS__, 'pruefen' ] );
        add_action( 'wp_ajax_lsv07p_i_fahren',  [ __CLASS__, 'fahren' ] );
    }

    private static function wache() {
        if ( ! check_ajax_referer( 'lsv07p_nonce', 'nonce', false ) ) {
            wp_send_json_error(
                [ 'message' => 'Die Sitzung ist abgelaufen. Bitte Seite neu laden.' ], 403 );
        }
        if ( ! LSV07P_Rollen::hat_zugang() ) {
            wp_send_json_error( [ 'message' =>
                'Dieses Konto ist für SwimPlan nicht eingetragen.' ], 403 );
        }
        if ( ! LSV07P_Rollen::darf_planen() ) {
            wp_send_json_error( [ 'message' => 'Das darf nur ein Trainer.' ], 403 );
        }
    }

    /**
     * Woher der Text kommt: aus einer hochgeladenen Datei oder aus dem Feld
     * zum Hineinkopieren. Beides ist derselbe Inhalt — wer eine Zeile aus
     * einer Tabelle kopiert, soll dafür keine Datei speichern müssen.
     */
    private static function text() {
        if ( isset( $_FILES['datei'] ) && is_array( $_FILES['datei'] )
          && (int) ( $_FILES['datei']['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_OK ) {

            $tmp = (string) ( $_FILES['datei']['tmp_name'] ?? '' );
            if ( $tmp === '' || ! is_uploaded_file( $tmp ) ) {
                wp_send_json_error( [ 'message' => 'Die Datei ist nicht angekommen.' ], 400 );
            }
            if ( (int) ( $_FILES['datei']['size'] ?? 0 ) > LSV07P_CSV::MAX_BYTES ) {
                wp_send_json_error( [ 'message' =>
                    'Die Datei ist größer als 2 MB. So viel ist kein Import mehr.' ], 400 );
            }
            $inhalt = (string) file_get_contents( $tmp );
            /* Geprüft wird am Inhalt und nicht an der Endung: Eine .csv mit
               einem PDF darin ist kein CSV, und eine .txt mit Spalten darin
               ist eines. Ein Nullbyte hat in einer Textdatei nichts zu
               suchen — das ist der verlässlichste Hinweis auf eine
               Binärdatei. */
            if ( strpos( $inhalt, "\0" ) !== false ) {
                wp_send_json_error( [ 'message' =>
                    'Das ist keine Textdatei. Erwartet wird eine CSV.' ], 400 );
            }
            return $inhalt;
        }

        $roh = isset( $_POST['text'] ) ? (string) wp_unslash( $_POST['text'] ) : '';
        if ( trim( $roh ) === '' ) {
            wp_send_json_error( [ 'message' =>
                'Wähle eine Datei aus oder füge die Zeilen in das Feld ein.' ], 400 );
        }
        return $roh;
    }

    private static function kategorie() {
        $k = isset( $_POST['kategorie'] ) ? (string) wp_unslash( $_POST['kategorie'] ) : '';
        if ( ! isset( LSV07P_Import::KATEGORIEN[ $k ] ) ) {
            wp_send_json_error( [ 'message' => 'Diese Kategorie gibt es nicht.' ], 400 );
        }
        return $k;
    }

    private static function ziel() {
        return (int) ( $_POST['ziel_id'] ?? 0 );
    }

    public static function pruefen() {
        self::wache();
        $erg = LSV07P_Import::lauf( self::kategorie(), self::text(), self::ziel(), true );
        if ( isset( $erg['fehler'] ) ) {
            wp_send_json_error( [ 'message' => $erg['fehler'] ], 400 );
        }
        wp_send_json_success( $erg );
    }

    public static function fahren() {
        self::wache();
        $erg = LSV07P_Import::lauf( self::kategorie(), self::text(), self::ziel(), false );
        if ( isset( $erg['fehler'] ) ) {
            wp_send_json_error( [ 'message' => $erg['fehler'] ], 400 );
        }
        wp_send_json_success( $erg );
    }
}
