<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Den Beleg als PDF herunterladen.
 *
 * Bisher gab es das fertige PDF nur an zwei Stellen: im Druckfenster der
 * Kasse und als Anhang der Mail nach dem Bezahlen. Wer seine eigene
 * Abrechnung zwischendurch braucht — für die Unterlagen, für die
 * Steuererklärung, zum Weiterreichen —, musste danach fragen. Das ist
 * hier erledigt.
 *
 * Anders als jeder andere Endpunkt dieses Plugins antwortet dieser nicht
 * mit JSON, sondern mit einer Datei. Deshalb ein paar Besonderheiten:
 *
 *   - Aufgerufen wird er per GET, damit der Browser ihn als Download
 *     behandeln kann. Der Einmal-Schlüssel wandert dafür in die Adresse.
 *   - Vor dem ersten Byte wird jeder Puffer geleert. Eine einzige
 *     Leerzeile aus einem anderen Plugin würde sonst mitten im PDF
 *     landen und die Datei unbrauchbar machen.
 *   - Geprüft wird wie überall im Endpunkt und nicht in der Oberfläche:
 *     die eigene Abrechnung, oder Kasse und Administration.
 */
class LSV07A_Ajax_Beleg {

    public static function init() {
        add_action( 'wp_ajax_lsv07a_beleg_pdf', [ __CLASS__, 'pdf' ] );
    }

    /** Die Adresse, unter der eine Abrechnung als PDF liegt. */
    public static function adresse( $abrechnung_id ) {
        return add_query_arg( [
            'action'        => 'lsv07a_beleg_pdf',
            'abrechnung_id' => (int) $abrechnung_id,
            'nonce'         => wp_create_nonce( 'lsv07a_nonce' ),
        ], admin_url( 'admin-ajax.php' ) );
    }

    public static function pdf() {
        /* Dasselbe Tor wie überall sonst — Anmeldung, Einmal-Schlüssel,
           Zugang zum System. Dass der Schlüssel diesmal in der Adresse
           steht statt im Formular, ändert daran nichts: WordPress sucht
           ihn in GET und POST gleichermassen. */
        LSV07A_Access::check( 'zugang' );

        global $wpdb;
        $id  = absint( $_GET['abrechnung_id'] ?? 0 );
        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}lsv07a_abrechnung WHERE id = %d", $id ), ARRAY_A );
        if ( ! $abr ) {
            status_header( 404 );
            wp_die( 'Abrechnung nicht gefunden.', 'Nicht gefunden', [ 'response' => 404 ] );
        }

        /* Die eigene immer; fremde nur Kasse und Administration. Der Wart
           bekommt den Beleg hier bewusst NICHT: Auf ihm steht die
           Bankverbindung, und die geht ihn nichts an. */
        $eigene = (int) $abr['wp_user_id'] === get_current_user_id();
        if ( ! $eigene && ! LSV07A_Access::darf_zahlungsdaten() ) {
            status_header( 403 );
            wp_die( 'Diese Abrechnung gehört zu einem anderen Konto.', 'Kein Zugang',
                    [ 'response' => 403 ] );
        }

        $daten = LSV07A_Beleg::daten( $id );
        if ( ! $daten ) {
            status_header( 404 );
            wp_die( 'Beleg nicht erzeugbar.', 'Nicht gefunden', [ 'response' => 404 ] );
        }
        $pdf = LSV07A_Beleg::pdf( $daten );

        /* Alles weg, was vor uns geschrieben wurde — sonst steht es im
           PDF und der Leser findet keinen gültigen Kopf. */
        while ( ob_get_level() > 0 ) ob_end_clean();

        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="'
                . LSV07A_Beleg::dateiname( $daten ) . '"' );
        header( 'Content-Length: ' . strlen( $pdf ) );
        header( 'X-Content-Type-Options: nosniff' );
        echo $pdf;
        exit;
    }
}
