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
        foreach ( [ 'liste', 'detail', 'bezahlt', 'storno', 'loeschen' ] as $a ) {
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
        $quartal = sanitize_text_field( wp_unslash( $_POST['quartal'] ?? '' ) );
        $status  = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );
        if ( ! LSV07A_Berechnung::jahr_gueltig( $jahr ) ) {
            wp_send_json_error( [ 'message' => LSV07A_Meldung::jahr() ] );
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
        if ( ! $abr ) wp_send_json_error( [ 'message' => LSV07A_Meldung::fehlt( 'abrechnung' ) ] );
        if ( ! in_array( $abr['status'], self::sichtbar(), true ) ) {
            /* Für die Kasse gibt es sie (noch) nicht — dieselbe Antwort. */
            wp_send_json_error( [ 'message' => LSV07A_Meldung::fehlt( 'abrechnung' ) ], 404 );
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
        if ( ! $abr ) wp_send_json_error( [ 'message' => LSV07A_Meldung::fehlt( 'abrechnung' ) ] );

        /* Vier Augen: Die Kasse zahlt sich nicht selbst aus. */
        LSV07A_Access::vier_augen( $abr['wp_user_id'] );

        /* Der Kern der Kassenregel: bezahlt wird erst, was genehmigt ist. */
        if ( $abr['status'] !== 'genehmigt' ) {
            $warum = $abr['status'] === 'bezahlt'
                ? 'Diese Abrechnung ist bereits als bezahlt vermerkt.'
                : 'Diese Abrechnung ist noch nicht genehmigt (Stand: „'
                  . LSV07A_Berechnung::status_name( $abr['status'] ) . '"). '
                  . 'Erst nach der Genehmigung durch den Wart kann sie ausgezahlt werden.';
            wp_send_json_error( [ 'message' => $warum ] );
        }

        /* Atomar: Zwei Kassenleute, die gleichzeitig „bezahlt" klicken,
           erzeugen nicht zwei Vermerke und zwei Belegmails. */
        if ( ! LSV07A_DB::wechsel( 'lsv07a_abrechnung', $id, 'genehmigt', [
            'status'      => 'bezahlt',
            'bezahlt_am'  => current_time( 'mysql' ),
            'bezahlt_von' => get_current_user_id(),
        ] ) ) {
            wp_send_json_error( [ 'message' => 'Diese Abrechnung wurde gerade von jemand anderem '
                . 'geändert (vielleicht schon bezahlt). Bitte die Liste neu laden.' ] );
        }

        $summe = LSV07A_Berechnung::summe( $id );
        LSV07A_Log::schreibe( 'abrechnung.bezahlt', [
            'ziel_typ' => 'abrechnung', 'ziel_id' => $id,
            'details'  => $abr['quartal'] . ' ' . $abr['jahr'] . ', ' . number_format( $summe['gesamt'], 2, ',', '.' ) . ' EUR' ] );
        LSV07A_Nachricht::bezahlt( $abr );
        wp_send_json_success( [ 'message' => 'Als bezahlt vermerkt.' ] );
    }

    /** Versehentlich bezahlt gesetzt — zurück auf genehmigt. */
    /**
     * Eine Quartalsabrechnung löschen. Für die Kasse heisst das: ab
     * genehmigt — davor gibt es für sie nichts, also auch nichts zu löschen.
     * Die Grenze zieht LSV07A_Loeschung, nicht dieser Endpunkt.
     */
    public static function loeschen() {
        LSV07A_Access::check( 'kasse', true );
        $erg = LSV07A_Loeschung::abrechnung(
            absint( $_POST['abrechnung_id'] ?? 0 ),
            mb_substr( sanitize_textarea_field( wp_unslash( $_POST['grund'] ?? '' ) ), 0, LSV07A_Berechnung::MAX_KOMMENTAR ) );
        if ( $erg !== true ) wp_send_json_error( [ 'message' => (string) $erg ], 403 );
        wp_send_json_success( [ 'message' => 'Abrechnung gelöscht.' ] );
    }

    /**
     * Den Zahlungsvermerk zurücknehmen (Storno).
     *
     * Bisher ohne Spur: Der Vermerk verschwand, im Protokoll stand nur
     * „bezahlt_zurueck" — wann und von wem bezahlt worden war, war danach
     * nirgends mehr zu finden. Jetzt gilt dasselbe wie beim Löschen:
     * Begründung ist Pflicht, das Protokoll hält fest, was aufgehoben wurde
     * (Betrag, ursprüngliches Zahlungsdatum, wer gezahlt hatte), und die
     * betroffene Person erfährt davon. Die eigene Abrechnung storniert
     * niemand selbst (Vier-Augen-Prinzip).
     */
    public static function storno() {
        LSV07A_Access::check( 'kasse', true );
        global $wpdb;
        $id    = absint( $_POST['abrechnung_id'] ?? 0 );
        $grund = trim( mb_substr( sanitize_textarea_field( wp_unslash( $_POST['grund'] ?? '' ) ),
                                  0, LSV07A_Berechnung::MAX_KOMMENTAR ) );
        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::tbl( 'lsv07a_abrechnung' ) . " WHERE id = %d", $id ), ARRAY_A );
        if ( ! $abr ) wp_send_json_error( [ 'message' => LSV07A_Meldung::fehlt( 'abrechnung' ) ] );
        LSV07A_Access::vier_augen( $abr['wp_user_id'] );
        if ( $abr['status'] !== 'bezahlt' ) {
            wp_send_json_error( [ 'message' => 'Diese Abrechnung steht gar nicht auf bezahlt.' ] );
        }
        if ( $grund === '' ) {
            wp_send_json_error( [ 'message' => 'Bitte schreiben Sie dazu, warum der Zahlungsvermerk '
                . 'zurückgenommen wird — die Zahlung steht womöglich schon im Kontoauszug.',
                'code' => 'grund_fehlt' ] );
        }
        if ( ! LSV07A_DB::wechsel( 'lsv07a_abrechnung', $id, 'bezahlt',
                [ 'status' => 'genehmigt', 'bezahlt_am' => null, 'bezahlt_von' => 0 ] ) ) {
            wp_send_json_error( [ 'message' => 'Diese Abrechnung wurde gerade von jemand anderem '
                . 'geändert. Bitte die Liste neu laden.' ] );
        }
        $summe = LSV07A_Berechnung::summe( $id );
        LSV07A_Log::schreibe( 'abrechnung.bezahlt_zurueck', [
            'ziel_typ' => 'abrechnung', 'ziel_id' => $id,
            'details'  => sprintf( '%s %s, %s EUR, bezahlt am %s von Konto %d. Grund: %s',
                $abr['quartal'], $abr['jahr'], number_format( (float) $summe['gesamt'], 2, ',', '.' ),
                (string) $abr['bezahlt_am'], (int) $abr['bezahlt_von'], $grund ) ] );
        LSV07A_Nachricht::an( (int) $abr['wp_user_id'], 'storno',
            'Zahlungsvermerk zurückgenommen: ' . LSV07A_Berechnung::quartal_name( $abr['quartal'] )
                . ' ' . (int) $abr['jahr'],
            'Die Kasse hat den Vermerk „bezahlt" zurückgenommen. Grund: ' . $grund,
            'eigene', $id );
        wp_send_json_success( [ 'message' => 'Zahlungsvermerk entfernt.' ] );
    }
}
