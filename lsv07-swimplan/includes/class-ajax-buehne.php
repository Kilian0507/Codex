<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Bühne: Vollbild, Splitscreen, freie Größe.
 *
 * Sie ist nur eine ANSICHT auf Pläne, die es ohnehin gibt — der Trainer am
 * Beckenrand auf dem Tablet, im Stehen, mit nassen Händen. Jeder Plan, der
 * auf ihr landet, geht einzeln durch LSV07P_Plan::darf_sehen(). Dass drei
 * Pläne in einem Aufruf angefordert werden, macht aus zwei erlaubten und
 * einem fremden nicht drei erlaubte.
 *
 * Bis 1.2.0 lag hier auch das Schwimmerportal. Das ist weg: Schwimmer haben
 * kein Konto und kommen nicht in SwimPlan hinein. Ihren Plan bekommen sie
 * auf Papier, als PDF oder eben über die Bühne, wenn der Trainer sie
 * aufklappt.
 */
class LSV07P_Ajax_Buehne {

    /** Wie viele Pläne höchstens gleichzeitig auf die Bühne dürfen. */
    const BUEHNE_MAX = 6;

    public static function init() {
        $map = [
            'lsv07p_buehne' => 'buehne',
            'lsv07p_layout' => 'layout',
        ];
        foreach ( $map as $action => $methode ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, $methode ] );
        }
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
    }

    private static function feld( $name, $standard = '' ) {
        return isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : $standard;
    }

    /**
     * Mehrere Pläne auf einmal, für den Splitscreen.
     *
     * Jeder einzeln geprüft. Was nicht erlaubt ist, fällt still weg und
     * wird gezählt — die Bühne sagt dann „1 Plan nicht freigegeben" und
     * zeigt die übrigen. Eine Fehlermeldung mit Plannummer wäre hier ein
     * Auskunftsschalter darüber, welche Nummern es gibt.
     */
    public static function buehne() {
        self::wache();

        $roh = self::feld( 'ids', '' );
        $ids = is_array( $roh ) ? $roh : explode( ',', (string) $roh );
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
        $ids = array_slice( $ids, 0, self::BUEHNE_MAX );

        $plaene   = [];
        $verwehrt = 0;
        foreach ( $ids as $id ) {
            if ( ! LSV07P_Plan::darf_sehen( $id ) ) { $verwehrt++; continue; }
            $p = LSV07P_Plan::voll( $id );
            if ( $p ) $plaene[] = $p; else $verwehrt++;
        }

        wp_send_json_success( [
            'plaene'   => $plaene,
            'verwehrt' => $verwehrt,
            'max'      => self::BUEHNE_MAX,
        ] );
    }

    /**
     * Die Einstellung der Bühne — wie viele Spalten, wie groß die Schrift,
     * welche Pläne offen waren.
     *
     * Sie liegt am WordPress-Konto und nicht im Browser: Wer am Beckenrand
     * das Tablet des Vereins benutzt, soll seine Ansicht wiederfinden, und
     * wer sich abmeldet, soll sie dort nicht zurücklassen.
     */
    public static function layout() {
        self::wache();
        $ich = LSV07P_Rollen::user_id();

        if ( isset( $_POST['layout'] ) ) {
            $roh = json_decode( (string) self::feld( 'layout' ), true );
            if ( ! is_array( $roh ) ) {
                wp_send_json_error( [ 'message' => 'Die Einstellung ist nicht zu lesen.' ], 400 );
            }
            self::layout_schreiben( $ich, $roh );
        }
        wp_send_json_success( [ 'layout' => self::layout_lesen( $ich ) ] );
    }

    /**
     * Gelesen wird mit Grenzen, nicht mit Vertrauen: Was im Benutzerfeld
     * steht, kam irgendwann aus einem Browser. Zwanzig Spalten oder
     * Schriftgrösse 4000 wären kein Angriff, aber eine unbenutzbare Seite.
     */
    public static function layout_lesen( $wp_user_id ) {
        $roh = get_user_meta( (int) $wp_user_id, 'lsv07p_buehne', true );
        $d   = is_string( $roh ) ? json_decode( $roh, true ) : $roh;
        if ( ! is_array( $d ) ) $d = [];
        return self::layout_pruefen( $d );
    }

    private static function layout_schreiben( $wp_user_id, array $roh ) {
        update_user_meta( (int) $wp_user_id, 'lsv07p_buehne',
            wp_json_encode( self::layout_pruefen( $roh ) ) );
    }

    private static function layout_pruefen( array $d ) {
        $ids = [];
        foreach ( (array) ( $d['plaene'] ?? [] ) as $id ) {
            $id = (int) $id;
            if ( $id > 0 ) $ids[] = $id;
        }
        $ids = array_slice( array_values( array_unique( $ids ) ), 0, self::BUEHNE_MAX );

        $richtung = (string) ( $d['richtung'] ?? 'spalten' );
        if ( ! in_array( $richtung, [ 'spalten', 'zeilen' ], true ) ) $richtung = 'spalten';

        return [
            'spalten'  => max( 1, min( 4, (int) ( $d['spalten'] ?? 1 ) ) ),
            /* Schriftgrösse in Prozent. 60 ist das Kleinste, bei dem ein
               Plan noch lesbar ist, 220 das Grösste, bei dem auf einem
               Tablet noch ein Set auf den Schirm passt. */
            'groesse'  => max( 60, min( 220, (int) ( $d['groesse'] ?? 100 ) ) ),
            'richtung' => $richtung,
            'kompakt'  => ! empty( $d['kompakt'] ),
            'vollbild' => ! empty( $d['vollbild'] ),
            'plaene'   => $ids,
        ];
    }
}
