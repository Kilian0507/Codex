<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Beanstandungen und Rückfragen am einzelnen Posten.
 *
 * Bisher gab es nur das grobe Werkzeug: Stimmt eine Zeile nicht, geht die
 * ganze Abrechnung zurück, und die Person sucht im Fließtext, welche
 * gemeint war. Hier geht es feiner.
 *
 *   **Beanstanden** heftet an genau eine Zeile einen Grund. Die übrigen
 *   Zeilen bleiben, wie sie sind. Solange etwas beanstandet ist, lässt
 *   sich die Abrechnung nicht genehmigen — das ist der Zweck der Übung.
 *
 *   **Rückfragen** ändern gar nichts. Wart und Person schreiben sich an
 *   der Zeile ein paar Sätze, der Stand der Abrechnung bleibt, wo er ist.
 *   Für das halbe Dutzend Fälle im Quartal, in denen eine Frage genügt
 *   und eine Rückgabe zu viel wäre.
 *
 * Wer was darf, entscheidet sich hier und nicht in der Oberfläche:
 * Die Person darf nur an der eigenen Abrechnung antworten, der Wart nur
 * an denen seines Bereichs (siehe LSV07A_Zustaendig).
 */
class LSV07A_Ajax_Notiz {

    public static function init() {
        foreach ( [ 'liste', 'anlegen', 'beanstanden', 'aufheben' ] as $a ) {
            add_action( 'wp_ajax_lsv07a_notiz_' . $a, [ __CLASS__, $a ] );
        }
    }

    private static function tbl( $n ) { global $wpdb; return $wpdb->prefix . $n; }

    /** Der Posten mitsamt seiner Abrechnung — oder Abbruch. */
    private static function posten_holen( $posten_id ) {
        global $wpdb;
        $p = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::tbl( 'lsv07a_posten' ) . " WHERE id = %d",
            (int) $posten_id ), ARRAY_A );
        if ( ! $p ) wp_send_json_error( [ 'message' => 'Posten nicht gefunden.' ] );
        $a = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::tbl( 'lsv07a_abrechnung' ) . " WHERE id = %d",
            (int) $p['abrechnung_id'] ), ARRAY_A );
        if ( ! $a ) wp_send_json_error( [ 'message' => 'Abrechnung nicht gefunden.' ] );
        return [ $p, $a ];
    }

    /** Darf die angemeldete Person an dieser Abrechnung mitreden? */
    private static function darf( $abr, $schreibt = false ) {
        $uid = get_current_user_id();
        if ( LSV07A_Rollen::ist_admin() ) return 'wart';
        if ( LSV07A_Rollen::ist_wart() && LSV07A_Zustaendig::darf_pruefen( $abr['id'] ) ) return 'wart';
        if ( (int) $abr['wp_user_id'] === $uid ) return 'person';
        wp_send_json_error( [ 'message' => 'Diese Abrechnung gehört nicht zu Ihrem Bereich.' ], 403 );
    }

    /**
     * Alle Wortmeldungen einer Abrechnung, nach Posten gebündelt.
     * Die Oberfläche hängt sie an die jeweilige Zeile.
     */
    public static function liste() {
        /* Das Tor ist bewusst nur „Zugang": Hier sind BEIDE Seiten
           unterwegs — der Wart und die Person. Wer wirklich darf,
           entscheidet darunter self::darf() anhand der Abrechnung. */
        LSV07A_Access::check( 'zugang' );
        global $wpdb;
        $abr_id = absint( $_POST['abrechnung_id'] ?? 0 );
        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::tbl( 'lsv07a_abrechnung' ) . " WHERE id = %d", $abr_id ), ARRAY_A );
        if ( ! $abr ) wp_send_json_error( [ 'message' => 'Abrechnung nicht gefunden.' ] );
        self::darf( $abr );
        wp_send_json_success( self::paket( $abr_id ) );
    }

    /** Dieselben Daten, auch für andere Endpunkte verwendbar. */
    public static function paket( $abr_id ) {
        global $wpdb;
        $zeilen = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::tbl( 'lsv07a_posten_notiz' ) . "
              WHERE abrechnung_id = %d ORDER BY id ASC", (int) $abr_id ), ARRAY_A ) ?: [];

        $nach_posten = [];
        foreach ( $zeilen as $z ) {
            $u = get_userdata( (int) $z['wp_user_id'] );
            $nach_posten[ (int) $z['posten_id'] ][] = [
                'id'   => (int) $z['id'],
                'art'  => $z['art'],
                'text' => (string) $z['text'],
                'wer'  => $u ? $u->display_name : 'Unbekannt',
                'zeit' => $z['erstellt_am'],
            ];
        }

        $beanstandet = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM " . self::tbl( 'lsv07a_posten' ) . "
              WHERE abrechnung_id = %d AND beanstandet = 1", (int) $abr_id ) ) ?: [] );

        /* Der Schlüssel heisst bewusst NICHT „offen": Dieses Paket wird
           mit dem der Abrechnung zusammengeführt, und dort bedeutet
           „offen" bereits etwas anderes — nämlich ob sich die Abrechnung
           noch bearbeiten lässt. */
        return [ 'notizen' => $nach_posten, 'beanstandet' => $beanstandet,
                 'beanstandet_zahl' => count( $beanstandet ) ];
    }

    /**
     * Eine Rückfrage oder eine Antwort. Ändert am Stand der Abrechnung
     * nichts — das ist der ganze Punkt.
     */
    public static function anlegen() {
        LSV07A_Access::check( 'zugang', true );
        [ $posten, $abr ] = self::posten_holen( $_POST['posten_id'] ?? 0 );
        $wer  = self::darf( $abr, true );
        $text = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
        if ( trim( $text ) === '' ) {
            wp_send_json_error( [ 'message' => 'Ohne Text ist eine Rückfrage keine.' ] );
        }
        $art = $wer === 'wart' ? 'frage' : 'antwort';
        self::schreiben( $abr, $posten, $art, $text );

        if ( $art === 'frage' ) {
            LSV07A_Nachricht::posten_frage( $abr, $posten, $text );
            $antwort = 'Rückfrage gestellt. Die Person bekommt eine Mitteilung.';
        } else {
            LSV07A_Nachricht::posten_antwort( $abr, $posten, $text );
            $antwort = 'Antwort vermerkt. Der Wart bekommt eine Mitteilung.';
        }
        wp_send_json_success( array_merge( [ 'message' => $antwort ], self::paket( $abr['id'] ) ) );
    }

    /** Eine einzelne Zeile beanstanden — nur der Wart. */
    public static function beanstanden() {
        LSV07A_Access::check( 'wart', true );
        [ $posten, $abr ] = self::posten_holen( $_POST['posten_id'] ?? 0 );
        if ( self::darf( $abr, true ) !== 'wart' ) {
            wp_send_json_error( [ 'message' => 'Beanstanden kann nur der Wart.' ], 403 );
        }
        $text = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
        if ( trim( $text ) === '' ) {
            wp_send_json_error( [ 'message' => 'Bitte einen Grund angeben — sonst weiß niemand, was an '
                . 'dieser Zeile nicht stimmt.' ] );
        }
        global $wpdb;
        $wpdb->update( self::tbl( 'lsv07a_posten' ), [ 'beanstandet' => 1 ],
                       [ 'id' => (int) $posten['id'] ], [ '%d' ], [ '%d' ] );
        self::schreiben( $abr, $posten, 'beanstandung', $text );
        LSV07A_Log::schreibe( 'posten.beanstandet', [
            'ziel_typ' => 'posten', 'ziel_id' => (int) $posten['id'], 'details' => $text ] );
        LSV07A_Nachricht::posten_beanstandet( $abr, $posten, $text );
        wp_send_json_success( array_merge(
            [ 'message' => 'Zeile beanstandet. Die übrigen bleiben unberührt.' ],
            self::paket( $abr['id'] ) ) );
    }

    /** Beanstandung zurücknehmen. */
    public static function aufheben() {
        LSV07A_Access::check( 'wart', true );
        [ $posten, $abr ] = self::posten_holen( $_POST['posten_id'] ?? 0 );
        if ( self::darf( $abr, true ) !== 'wart' ) {
            wp_send_json_error( [ 'message' => 'Das kann nur der Wart.' ], 403 );
        }
        global $wpdb;
        $wpdb->update( self::tbl( 'lsv07a_posten' ), [ 'beanstandet' => 0 ],
                       [ 'id' => (int) $posten['id'] ], [ '%d' ], [ '%d' ] );
        self::schreiben( $abr, $posten, 'aufgehoben', 'Beanstandung zurückgenommen.' );
        LSV07A_Log::schreibe( 'posten.beanstandung_aufgehoben', [
            'ziel_typ' => 'posten', 'ziel_id' => (int) $posten['id'] ] );
        wp_send_json_success( array_merge(
            [ 'message' => 'Beanstandung aufgehoben.' ], self::paket( $abr['id'] ) ) );
    }

    private static function schreiben( $abr, $posten, $art, $text ) {
        global $wpdb;
        $wpdb->insert( self::tbl( 'lsv07a_posten_notiz' ), [
            'abrechnung_id' => (int) $abr['id'],
            'posten_id'     => (int) $posten['id'],
            'wp_user_id'    => get_current_user_id(),
            'art'           => $art,
            'text'          => substr( $text, 0, 1000 ),
        ], [ '%d', '%d', '%d', '%s', '%s' ] );
    }

    /** Wie viele Zeilen einer Abrechnung gerade beanstandet sind. */
    public static function beanstandet_zahl( $abr_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::tbl( 'lsv07a_posten' ) . "
              WHERE abrechnung_id = %d AND beanstandet = 1", (int) $abr_id ) );
    }

    /** Beim Einreichen fangen die Beanstandungen von vorne an. */
    public static function zuruecksetzen( $abr_id ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::tbl( 'lsv07a_posten' ) . " SET beanstandet = 0
              WHERE abrechnung_id = %d", (int) $abr_id ) );
    }
}
