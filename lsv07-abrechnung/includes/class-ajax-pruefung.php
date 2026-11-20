<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Sicht des Warts: prüfen, genehmigen, zurückgeben.
 *
 * Der Wart sieht jede Abrechnung im Detail — auch die, die noch gar nicht
 * eingereicht wurde. Nur so kann er vorab nachsehen und nachfragen, bevor
 * das Quartal zu ist.
 */
class LSV07A_Ajax_Pruefung {

    public static function init() {
        foreach ( [ 'liste', 'detail', 'genehmigen', 'zurueckgeben', 'wieder_oeffnen',
                    'loeschen' ] as $a ) {
            add_action( 'wp_ajax_lsv07a_pruef_' . $a, [ __CLASS__, $a ] );
        }
    }

    private static function tbl( $n ) { global $wpdb; return $wpdb->prefix . $n; }

    /**
     * Alle Abrechnungen eines Quartals — auch die, die es noch gar nicht
     * gibt: Für jedes Konto mit der Rolle Trainer wird eine Zeile gezeigt,
     * damit sichtbar ist, wer noch nichts eingereicht hat.
     */
    public static function liste() {
        LSV07A_Access::check( 'wart' );
        global $wpdb;

        $quartal = sanitize_text_field( wp_unslash( $_POST['quartal'] ?? LSV07A_Berechnung::quartal_von_datum( date( 'Y-m-d' ) ) ) );
        $jahr    = (int) ( $_POST['jahr'] ?? date( 'Y' ) );
        $status  = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );
        if ( ! LSV07A_Berechnung::quartal_gueltig( $quartal ) || ! LSV07A_Berechnung::jahr_gueltig( $jahr ) ) {
            wp_send_json_error( [ 'message' => 'Quartal oder Jahr sind unmöglich.' ] );
        }

        $zeilen = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.*, COALESCE(SUM(p.betrag),0) AS gesamt, COUNT(p.id) AS posten
               FROM " . self::tbl( 'lsv07a_abrechnung' ) . " a
          LEFT JOIN " . self::tbl( 'lsv07a_posten' ) . " p ON p.abrechnung_id = a.id
              WHERE a.quartal = %s AND a.jahr = %d
           GROUP BY a.id", $quartal, $jahr ), ARRAY_A ) ?: [];

        /* Ist der Wart nur für bestimmte Mannschaften zuständig, bleibt
           alles andere unsichtbar — nicht bloß ausgegraut. Wer keinen
           Bereich hinterlegt hat, sieht wie bisher alles. */
        $erlaubt  = LSV07A_Zustaendig::abrechnungen_im_bereich( $quartal, $jahr );
        $personen = LSV07A_Zustaendig::personen_im_bereich();

        $nach_user = [];
        foreach ( $zeilen as $z ) {
            if ( $erlaubt !== null && ! in_array( (int) $z['id'], $erlaubt, true ) ) continue;
            $nach_user[ (int) $z['wp_user_id'] ] = $z;
        }

        // Jedes Trainer-Konto auflisten, auch ohne Abrechnung
        $trainer = $wpdb->get_col(
            "SELECT wp_user_id FROM " . self::tbl( 'lsv07a_rolle' ) . " WHERE rolle = 'trainer'" ) ?: [];
        foreach ( $trainer as $uid ) {
            $uid = (int) $uid;
            if ( isset( $nach_user[ $uid ] ) ) continue;
            /* Wer noch gar nichts erfasst hat, hat auch keinen Posten,
               über den sich eine Mannschaft bestimmen liesse. Massstab
               ist dann, wen dieser Wart früher schon geprüft hat. */
            if ( $personen !== null && ! in_array( $uid, $personen, true ) ) continue;
            $nach_user[ $uid ] = null;
        }

        $out = [];
        foreach ( $nach_user as $uid => $z ) {
            $u = get_userdata( $uid );
            $eintrag = [
                'wp_user_id'  => (int) $uid,
                'name'        => $u ? $u->display_name : ( 'Konto ' . $uid . ' (gelöscht)' ),
                'id'          => $z ? (int) $z['id'] : 0,
                'status'      => $z ? $z['status'] : 'offen',
                'status_name' => $z ? LSV07A_Berechnung::status_name( $z['status'] ) : 'Noch nichts erfasst',
                'gesamt'      => $z ? (float) $z['gesamt'] : 0.0,
                'posten'      => $z ? (int) $z['posten'] : 0,
                'eingereicht_am' => $z ? $z['eingereicht_am'] : null,
                'genehmigt_am'   => $z ? $z['genehmigt_am'] : null,
                'bezahlt_am'     => $z ? $z['bezahlt_am'] : null,
                'nachtrag_zu'    => $z ? (int) ( $z['nachtrag_zu'] ?? 0 ) : 0,
                'beanstandet'    => $z ? LSV07A_Ajax_Notiz::beanstandet_zahl( (int) $z['id'] ) : 0,
                'hinweise'       => $z ? count( LSV07A_Hinweise::fuer( $z ) ) : 0,
            ];
            if ( $status !== '' && $eintrag['status'] !== $status ) continue;
            $out[] = $eintrag;
        }
        usort( $out, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );

        $offen = 0;
        foreach ( $out as $o ) if ( $o['status'] === 'eingereicht' ) $offen++;

        wp_send_json_success( [ 'zeilen' => $out, 'offen' => $offen,
                                'quartal' => $quartal, 'jahr' => $jahr,
                                'bereich' => LSV07A_Zustaendig::beschriftung(),
                                'beschraenkt' => ! LSV07A_Zustaendig::unbeschraenkt() ] );
    }

    public static function detail() {
        LSV07A_Access::check( 'wart' );
        global $wpdb;
        $id = absint( $_POST['abrechnung_id'] ?? 0 );
        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::tbl( 'lsv07a_abrechnung' ) . " WHERE id = %d", $id ), ARRAY_A );
        if ( ! $abr ) wp_send_json_error( [ 'message' => LSV07A_Meldung::fehlt( 'abrechnung' ) ] );
        if ( ! LSV07A_Zustaendig::darf_pruefen( $id ) ) {
            /* Wie „gibt es nicht": An der Antwort soll nicht abzulesen sein,
               welche Abrechnungen ausserhalb des eigenen Bereichs existieren. */
            wp_send_json_error( [ 'message' => LSV07A_Meldung::fehlt( 'abrechnung' ) ], 404 );
        }
        $paket = LSV07A_Ajax_Abrechnung::paket( $abr );
        $paket['hinweise'] = LSV07A_Hinweise::fuer( $abr );
        wp_send_json_success( array_merge( $paket, LSV07A_Ajax_Notiz::paket( $id ) ) );
    }

    public static function genehmigen() {
        LSV07A_Access::check( 'wart', true );
        global $wpdb;
        $id  = absint( $_POST['abrechnung_id'] ?? 0 );
        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::tbl( 'lsv07a_abrechnung' ) . " WHERE id = %d", $id ), ARRAY_A );
        if ( ! $abr ) wp_send_json_error( [ 'message' => LSV07A_Meldung::fehlt( 'abrechnung' ) ] );
        if ( ! LSV07A_Zustaendig::darf_pruefen( $id ) ) {
            /* Wie „gibt es nicht": An der Antwort soll nicht abzulesen sein,
               welche Abrechnungen ausserhalb des eigenen Bereichs existieren. */
            wp_send_json_error( [ 'message' => LSV07A_Meldung::fehlt( 'abrechnung' ) ], 404 );
        }
        /* Vier Augen: die eigene Abrechnung genehmigt niemand selbst. */
        LSV07A_Access::vier_augen( $abr['wp_user_id'] );
        if ( $abr['status'] !== 'eingereicht' ) {
            wp_send_json_error( [ 'message' => 'Genehmigt werden kann nur, was eingereicht wurde. '
                . 'Diese Abrechnung steht auf „' . LSV07A_Berechnung::status_name( $abr['status'] ) . '".' ] );
        }
        /* Eine beanstandete Zeile genehmigen hiesse, die eigene
           Beanstandung zu übergehen. Entweder sie ist erledigt — dann
           aufheben — oder die Abrechnung geht zurück. */
        $offen = LSV07A_Ajax_Notiz::beanstandet_zahl( $id );
        if ( $offen > 0 ) {
            wp_send_json_error( [ 'message' => $offen === 1
                ? 'Eine Zeile ist noch beanstandet. Heben Sie die Beanstandung auf oder geben Sie die Abrechnung zurück.'
                : $offen . ' Zeilen sind noch beanstandet. Heben Sie die Beanstandungen auf oder geben Sie die Abrechnung zurück.' ] );
        }
        if ( ! LSV07A_DB::wechsel( 'lsv07a_abrechnung', $id, 'eingereicht', [
            'status'        => 'genehmigt',
            'genehmigt_am'  => current_time( 'mysql' ),
            'genehmigt_von' => get_current_user_id(),
        ] ) ) {
            wp_send_json_error( [ 'message' => self::schon_geaendert() ] );
        }
        $summe = LSV07A_Berechnung::summe( $id );
        LSV07A_Log::schreibe( 'abrechnung.genehmigt', [
            'ziel_typ' => 'abrechnung', 'ziel_id' => $id,
            'details'  => $abr['quartal'] . ' ' . $abr['jahr'] . ', ' . number_format( $summe['gesamt'], 2, ',', '.' ) . ' EUR' ] );
        LSV07A_Nachricht::genehmigt( $abr );
        wp_send_json_success( [ 'message' => 'Abrechnung genehmigt.' ] );
    }

    public static function zurueckgeben() {
        LSV07A_Access::check( 'wart', true );
        global $wpdb;
        $id    = absint( $_POST['abrechnung_id'] ?? 0 );
        $grund = mb_substr( sanitize_textarea_field( wp_unslash( $_POST['grund'] ?? '' ) ),
                            0, LSV07A_Berechnung::MAX_KOMMENTAR );

        /* Wer schon einzelne Zeilen beanstandet hat, hat den Grund
           bereits geschrieben — dort, wo er hingehört. Dann muss er ihn
           nicht ein zweites Mal als Fließtext wiederholen. */
        $beanstandet = self::beanstandete_zeilen( $id );
        if ( trim( $grund ) === '' && $beanstandet ) {
            $grund = count( $beanstandet ) === 1
                ? 'Eine Zeile ist beanstandet: ' . $beanstandet[0]
                : count( $beanstandet ) . ' Zeilen sind beanstandet: ' . implode( ' · ', $beanstandet );
        }
        if ( trim( $grund ) === '' ) {
            wp_send_json_error( [ 'message' => 'Bitte einen Grund angeben — sonst weiß niemand, was zu ändern ist. '
                . 'Alternativ einzelne Zeilen beanstanden.' ] );
        }
        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::tbl( 'lsv07a_abrechnung' ) . " WHERE id = %d", $id ), ARRAY_A );
        if ( ! $abr ) wp_send_json_error( [ 'message' => LSV07A_Meldung::fehlt( 'abrechnung' ) ] );
        if ( ! LSV07A_Zustaendig::darf_pruefen( $id ) ) {
            /* Wie „gibt es nicht": An der Antwort soll nicht abzulesen sein,
               welche Abrechnungen ausserhalb des eigenen Bereichs existieren. */
            wp_send_json_error( [ 'message' => LSV07A_Meldung::fehlt( 'abrechnung' ) ], 404 );
        }
        LSV07A_Access::vier_augen( $abr['wp_user_id'] );
        if ( ! in_array( $abr['status'], [ 'eingereicht', 'genehmigt' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Zurückgeben lässt sich nur eine eingereichte oder bereits genehmigte Abrechnung.' ] );
        }
        if ( $abr['status'] === 'genehmigt' && ! LSV07A_Rollen::ist_admin() ) {
            // Eine genehmigte zurückholen ist ein Eingriff — das bleibt dem
            // Administrator vorbehalten, damit der Ablauf verlässlich bleibt.
            wp_send_json_error( [ 'message' => 'Diese Abrechnung ist bereits genehmigt. Nur die Administration kann sie wieder öffnen.' ] );
        }

        if ( ! LSV07A_DB::wechsel( 'lsv07a_abrechnung', $id, $abr['status'], [
            'status'          => 'zurueck',
            'rueckgabe_grund' => $grund,
            'genehmigt_am'    => null,
            'genehmigt_von'   => 0,
        ] ) ) {
            wp_send_json_error( [ 'message' => self::schon_geaendert() ] );
        }

        LSV07A_Log::schreibe( 'abrechnung.zurueckgegeben', [
            'ziel_typ' => 'abrechnung', 'ziel_id' => $id, 'details' => $grund ] );
        LSV07A_Nachricht::zurueckgegeben( $abr, $grund );
        wp_send_json_success( [ 'message' => 'Abrechnung zurückgegeben.' ] );
    }

    /** Zwei gleichzeitige Entscheidungen: Die zweite kommt hier an. */
    private static function schon_geaendert() {
        return 'Über diese Abrechnung hat gerade jemand anderes entschieden. Bitte die Liste neu laden.';
    }

    /** Die beanstandeten Zeilen, kurz beschrieben — für den Rückgabegrund. */
    private static function beanstandete_zeilen( $abr_id ) {
        global $wpdb;
        $zeilen = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, datum, bezeichnung FROM " . self::tbl( 'lsv07a_posten' ) . "
              WHERE abrechnung_id = %d AND beanstandet = 1 ORDER BY datum ASC",
            (int) $abr_id ), ARRAY_A ) ?: [];
        $out = [];
        foreach ( $zeilen as $z ) {
            $d = strtotime( (string) $z['datum'] );
            $out[] = ( $d ? date( 'd.m.Y', $d ) . ' ' : '' )
                   . ( trim( (string) $z['bezeichnung'] ) ?: 'Posten' );
        }
        return $out;
    }

    /** Eine genehmigte oder bezahlte wieder öffnen — nur Administration. */
    /**
     * Eine Quartalsabrechnung löschen.
     *
     * Das Tor steht auf „wart" — WELCHE Abrechnung diese Person löschen
     * darf, entscheidet LSV07A_Loeschung. Dort steht die Antwort für alle
     * Bereiche; hier nur, wer überhaupt an diesen Endpunkt darf.
     */
    public static function loeschen() {
        LSV07A_Access::check( 'wart', true );
        $erg = LSV07A_Loeschung::abrechnung(
            absint( $_POST['abrechnung_id'] ?? 0 ),
            mb_substr( sanitize_textarea_field( wp_unslash( $_POST['grund'] ?? '' ) ), 0, LSV07A_Berechnung::MAX_KOMMENTAR ) );
        if ( $erg !== true ) wp_send_json_error( [ 'message' => (string) $erg ], 403 );
        wp_send_json_success( [ 'message' => 'Abrechnung gelöscht.' ] );
    }

    public static function wieder_oeffnen() {
        LSV07A_Access::check( 'admin', true );
        global $wpdb;
        $id = absint( $_POST['abrechnung_id'] ?? 0 );
        $grund = mb_substr( sanitize_textarea_field( wp_unslash( $_POST['grund'] ?? '' ) ),
                            0, LSV07A_Berechnung::MAX_KOMMENTAR );
        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::tbl( 'lsv07a_abrechnung' ) . " WHERE id = %d", $id ), ARRAY_A );
        if ( ! $abr ) wp_send_json_error( [ 'message' => LSV07A_Meldung::fehlt( 'abrechnung' ) ] );
        /* Die eigene bezahlte Abrechnung wieder öffnen hiesse, sie danach
           selbst ändern und neu durchlaufen lassen zu können. */
        LSV07A_Access::vier_augen( $abr['wp_user_id'] );
        if ( ! LSV07A_DB::wechsel( 'lsv07a_abrechnung', $id, $abr['status'], [
            'status'          => 'zurueck',
            'rueckgabe_grund' => $grund ?: 'Von der Administration wieder geöffnet.',
            'genehmigt_am'    => null, 'genehmigt_von' => 0,
            'bezahlt_am'      => null, 'bezahlt_von'   => 0,
        ] ) ) {
            wp_send_json_error( [ 'message' => self::schon_geaendert() ] );
        }
        /* Mit Spur: welcher Stand, und ob eine Zahlung damit aufgehoben wurde. */
        LSV07A_Log::schreibe( 'abrechnung.wieder_geoeffnet', [
            'ziel_typ' => 'abrechnung', 'ziel_id' => $id,
            'details'  => 'vorher „' . LSV07A_Berechnung::status_name( $abr['status'] ) . '"'
                        . ( ! empty( $abr['bezahlt_am'] ) ? ', bezahlt am ' . $abr['bezahlt_am'] . ' von Konto '
                            . (int) $abr['bezahlt_von'] : '' )
                        . ( $grund !== '' ? '. Grund: ' . $grund : '' ) ] );
        LSV07A_Nachricht::wieder_offen( $abr );
        wp_send_json_success( [ 'message' => 'Abrechnung wieder geöffnet.' ] );
    }
}
