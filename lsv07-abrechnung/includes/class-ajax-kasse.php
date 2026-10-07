<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Sicht der Kasse.
 *
 * Die Kasse sieht ausschließlich genehmigte und bereits bezahlte
 * Abrechnungen — alles davor geht sie nichts an und wird gar nicht erst
 * geliefert. Sie kann als bezahlt markieren und für die Buchhaltung ein
 * PDF erzeugen (die Daten dafür kommen von hier, gesetzt wird es im
 * Browser).
 */
class LSV07A_Ajax_Kasse {

    public static function init() {
        foreach ( [ 'liste', 'detail', 'bezahlt', 'storno' ] as $a ) {
            add_action( 'wp_ajax_lsv07a_kasse_' . $a, [ __CLASS__, $a ] );
        }
    }

    private static function tbl( $n ) { global $wpdb; return $wpdb->prefix . $n; }

    /** Nur genehmigt und bezahlt — davor existiert für die Kasse nichts. */
    private static function sichtbar() { return [ 'genehmigt', 'bezahlt' ]; }

    public static function liste() {
        LSV07A_Access::check( 'kasse' );
        global $wpdb;

        $jahr    = (int) ( $_POST['jahr'] ?? date( 'Y' ) );
        $quartal = sanitize_text_field( $_POST['quartal'] ?? '' );
        $status  = sanitize_text_field( $_POST['status'] ?? '' );
        if ( ! LSV07A_Berechnung::jahr_gueltig( $jahr ) ) {
            wp_send_json_error( [ 'message' => 'Unmögliches Jahr.' ] );
        }

        $erlaubt = self::sichtbar();
        if ( $status !== '' && in_array( $status, $erlaubt, true ) ) $erlaubt = [ $status ];
        $in = "'" . implode( "','", array_map( 'esc_sql', $erlaubt ) ) . "'";

        $wo = $wpdb->prepare( 'a.jahr = %d', $jahr );
        if ( LSV07A_Berechnung::quartal_gueltig( $quartal ) ) {
            $wo .= $wpdb->prepare( ' AND a.quartal = %s', $quartal );
        }

        $zeilen = $wpdb->get_results(
            "SELECT a.*, COALESCE(SUM(p.betrag),0) AS gesamt, COUNT(p.id) AS posten
               FROM " . self::tbl( 'lsv07a_abrechnung' ) . " a
          LEFT JOIN " . self::tbl( 'lsv07a_posten' ) . " p ON p.abrechnung_id = a.id
              WHERE $wo AND a.status IN ($in)
           GROUP BY a.id
           ORDER BY a.jahr DESC, a.quartal DESC, a.genehmigt_am ASC", ARRAY_A ) ?: [];

        $out = []; $summe_offen = 0.0; $summe_bezahlt = 0.0;
        foreach ( $zeilen as $z ) {
            $u = get_userdata( $z['wp_user_id'] );
            $pers = LSV07A_Person::holen( $z['wp_user_id'] );
            $betrag = (float) $z['gesamt'];
            if ( $z['status'] === 'bezahlt' ) $summe_bezahlt += $betrag; else $summe_offen += $betrag;
            $out[] = [
                'id'           => (int) $z['id'],
                'wp_user_id'   => (int) $z['wp_user_id'],
                'name'         => $u ? $u->display_name : ( 'Konto ' . $z['wp_user_id'] ),
                'quartal'      => $z['quartal'],
                'jahr'         => (int) $z['jahr'],
                'status'       => $z['status'],
                'status_name'  => LSV07A_Berechnung::status_name( $z['status'] ),
                'gesamt'       => $betrag,
                'posten'       => (int) $z['posten'],
                'genehmigt_am' => $z['genehmigt_am'],
                'bezahlt_am'   => $z['bezahlt_am'],
                'iban'         => $pers['iban'],
                'kontoinhaber' => $pers['kontoinhaber'],
            ];
        }

        wp_send_json_success( [
            'zeilen'        => $out,
            'summe_offen'   => round( $summe_offen, 2 ),
            'summe_bezahlt' => round( $summe_bezahlt, 2 ),
        ] );
    }

    /** Alles, was für das PDF gebraucht wird. */
    public static function detail() {
        LSV07A_Access::check( 'kasse' );
        global $wpdb;
        $id  = absint( $_POST['abrechnung_id'] ?? 0 );
        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::tbl( 'lsv07a_abrechnung' ) . " WHERE id = %d", $id ), ARRAY_A );
        if ( ! $abr ) wp_send_json_error( [ 'message' => 'Abrechnung nicht gefunden.' ] );
        if ( ! in_array( $abr['status'], self::sichtbar(), true ) ) {
            wp_send_json_error( [ 'message' => 'Diese Abrechnung ist noch nicht genehmigt und für die Kasse deshalb nicht einsehbar.' ], 403 );
        }
        $paket = LSV07A_Ajax_Abrechnung::paket( $abr );
        $paket['verein'] = LSV07A_DB::config( 'verein', '' );
        wp_send_json_success( $paket );
    }

    public static function bezahlt() {
        LSV07A_Access::check( 'kasse', true );
        global $wpdb;
        $id  = absint( $_POST['abrechnung_id'] ?? 0 );
        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::tbl( 'lsv07a_abrechnung' ) . " WHERE id = %d", $id ), ARRAY_A );
        if ( ! $abr ) wp_send_json_error( [ 'message' => 'Abrechnung nicht gefunden.' ] );

        /* Der Kern der Kassenregel: bezahlt wird erst, was genehmigt ist. */
        if ( $abr['status'] !== 'genehmigt' ) {
            $warum = $abr['status'] === 'bezahlt'
                ? 'Diese Abrechnung ist bereits als bezahlt vermerkt.'
                : 'Diese Abrechnung ist noch nicht genehmigt (Stand: „'
                  . LSV07A_Berechnung::status_name( $abr['status'] ) . '"). '
                  . 'Erst nach der Genehmigung durch den Wart kann sie ausgezahlt werden.';
            wp_send_json_error( [ 'message' => $warum ] );
        }

        $geschrieben = $wpdb->update( self::tbl( 'lsv07a_abrechnung' ), [
            'status'      => 'bezahlt',
            'bezahlt_am'  => current_time( 'mysql' ),
            'bezahlt_von' => get_current_user_id(),
        ], [ 'id' => $id ], [ '%s', '%s', '%d' ], [ '%d' ] );
        if ( $geschrieben === false ) {
            wp_send_json_error( [ 'message' => 'Das Markieren ist fehlgeschlagen: '
                . ( $wpdb->last_error ?: 'unbekannter Datenbankfehler' ) ] );
        }

        $summe = LSV07A_Berechnung::summe( $id );
        LSV07A_Log::schreibe( 'abrechnung.bezahlt', [
            'ziel_typ' => 'abrechnung', 'ziel_id' => $id,
            'details'  => $abr['quartal'] . ' ' . $abr['jahr'] . ', ' . number_format( $summe['gesamt'], 2, ',', '.' ) . ' EUR' ] );
        LSV07A_Nachricht::bezahlt( $abr );
        wp_send_json_success( [ 'message' => 'Als bezahlt vermerkt.' ] );
    }

    /** Versehentlich bezahlt gesetzt — zurück auf genehmigt. */
    public static function storno() {
        LSV07A_Access::check( 'kasse', true );
        global $wpdb;
        $id  = absint( $_POST['abrechnung_id'] ?? 0 );
        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::tbl( 'lsv07a_abrechnung' ) . " WHERE id = %d", $id ), ARRAY_A );
        if ( ! $abr ) wp_send_json_error( [ 'message' => 'Abrechnung nicht gefunden.' ] );
        if ( $abr['status'] !== 'bezahlt' ) {
            wp_send_json_error( [ 'message' => 'Diese Abrechnung steht gar nicht auf bezahlt.' ] );
        }
        $wpdb->update( self::tbl( 'lsv07a_abrechnung' ),
            [ 'status' => 'genehmigt', 'bezahlt_am' => null, 'bezahlt_von' => 0 ],
            [ 'id' => $id ], [ '%s', '%s', '%d' ], [ '%d' ] );
        LSV07A_Log::schreibe( 'abrechnung.bezahlt_zurueck', [ 'ziel_typ' => 'abrechnung', 'ziel_id' => $id ] );
        wp_send_json_success( [ 'message' => 'Zahlungsvermerk entfernt.' ] );
    }
}
