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
        foreach ( [ 'liste', 'detail', 'genehmigen', 'zurueckgeben', 'wieder_oeffnen' ] as $a ) {
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

        $quartal = sanitize_text_field( $_POST['quartal'] ?? LSV07A_Berechnung::quartal_von_datum( date( 'Y-m-d' ) ) );
        $jahr    = (int) ( $_POST['jahr'] ?? date( 'Y' ) );
        $status  = sanitize_text_field( $_POST['status'] ?? '' );
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
        if ( ! $abr ) wp_send_json_error( [ 'message' => 'Abrechnung nicht gefunden.' ] );
        if ( ! LSV07A_Zustaendig::darf_pruefen( $id ) ) {
            wp_send_json_error( [ 'message' => 'Diese Abrechnung gehört zu einer Mannschaft, für die '
                . 'Sie nicht zuständig sind.' ], 403 );
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
        if ( ! $abr ) wp_send_json_error( [ 'message' => 'Abrechnung nicht gefunden.' ] );
        if ( ! LSV07A_Zustaendig::darf_pruefen( $id ) ) {
            wp_send_json_error( [ 'message' => 'Diese Abrechnung gehört zu einer Mannschaft, für die '
                . 'Sie nicht zuständig sind.' ], 403 );
        }
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
        $geschrieben = $wpdb->update( self::tbl( 'lsv07a_abrechnung' ), [
            'status'        => 'genehmigt',
            'genehmigt_am'  => current_time( 'mysql' ),
            'genehmigt_von' => get_current_user_id(),
        ], [ 'id' => $id ], [ '%s', '%s', '%d' ], [ '%d' ] );
        if ( $geschrieben === false ) {
            wp_send_json_error( [ 'message' => 'Das Genehmigen ist fehlgeschlagen: '
                . ( $wpdb->last_error ?: 'unbekannter Datenbankfehler' ) ] );
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
        $grund = sanitize_textarea_field( $_POST['grund'] ?? '' );

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
        if ( ! $abr ) wp_send_json_error( [ 'message' => 'Abrechnung nicht gefunden.' ] );
        if ( ! LSV07A_Zustaendig::darf_pruefen( $id ) ) {
            wp_send_json_error( [ 'message' => 'Diese Abrechnung gehört zu einer Mannschaft, für die '
                . 'Sie nicht zuständig sind.' ], 403 );
        }
        if ( ! in_array( $abr['status'], [ 'eingereicht', 'genehmigt' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Zurückgeben lässt sich nur eine eingereichte oder bereits genehmigte Abrechnung.' ] );
        }
        if ( $abr['status'] === 'genehmigt' && ! LSV07A_Rollen::ist_admin() ) {
            // Eine genehmigte zurückholen ist ein Eingriff — das bleibt dem
            // Administrator vorbehalten, damit der Ablauf verlässlich bleibt.
            wp_send_json_error( [ 'message' => 'Diese Abrechnung ist bereits genehmigt. Nur die Administration kann sie wieder öffnen.' ] );
        }

        $wpdb->update( self::tbl( 'lsv07a_abrechnung' ), [
            'status'          => 'zurueck',
            'rueckgabe_grund' => $grund,
            'genehmigt_am'    => null,
            'genehmigt_von'   => 0,
        ], [ 'id' => $id ], [ '%s', '%s', '%s', '%d' ], [ '%d' ] );

        LSV07A_Log::schreibe( 'abrechnung.zurueckgegeben', [
            'ziel_typ' => 'abrechnung', 'ziel_id' => $id, 'details' => $grund ] );
        LSV07A_Nachricht::zurueckgegeben( $abr, $grund );
        wp_send_json_success( [ 'message' => 'Abrechnung zurückgegeben.' ] );
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
    public static function wieder_oeffnen() {
        LSV07A_Access::check( 'admin', true );
        global $wpdb;
        $id = absint( $_POST['abrechnung_id'] ?? 0 );
        $grund = sanitize_textarea_field( $_POST['grund'] ?? '' );
        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::tbl( 'lsv07a_abrechnung' ) . " WHERE id = %d", $id ), ARRAY_A );
        if ( ! $abr ) wp_send_json_error( [ 'message' => 'Abrechnung nicht gefunden.' ] );
        $wpdb->update( self::tbl( 'lsv07a_abrechnung' ), [
            'status'          => 'zurueck',
            'rueckgabe_grund' => $grund ?: 'Von der Administration wieder geöffnet.',
            'genehmigt_am'    => null, 'genehmigt_von' => 0,
            'bezahlt_am'      => null, 'bezahlt_von'   => 0,
        ], [ 'id' => $id ], [ '%s','%s','%s','%d','%s','%d' ], [ '%d' ] );
        LSV07A_Log::schreibe( 'abrechnung.wieder_geoeffnet', [
            'ziel_typ' => 'abrechnung', 'ziel_id' => $id, 'details' => $grund ] );
        LSV07A_Nachricht::wieder_offen( $abr );
        wp_send_json_success( [ 'message' => 'Abrechnung wieder geöffnet.' ] );
    }
}
