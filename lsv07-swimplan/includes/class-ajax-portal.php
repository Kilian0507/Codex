<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Endpunkte des Schwimmerportals — und die der Bühne.
 *
 * Das Portal zeigt einem Schwimmer drei Dinge: welche Trainings für ihn
 * freigegeben sind, was darin steht, und seine eigenen Zeiten samt
 * Bestleistungen. Mehr nicht. Es gibt hier keinen Endpunkt, mit dem ein
 * Schwimmer etwas über einen anderen erfahren könnte, und keinen, mit dem
 * er etwas ändern könnte — auch nicht an seinen eigenen Zeiten.
 *
 * Die Bühne (Vollbild und Splitscreen) liegt ebenfalls hier, weil beide
 * Rollen sie benutzen: der Trainer am Beckenrand auf dem Tablet, der
 * Schwimmer auf dem Telefon. Sie ist nur eine ANSICHT — jeder Plan, der
 * auf ihr landet, geht einzeln durch LSV07P_Plan::darf_sehen(). Dass drei
 * Pläne in einem Aufruf angefordert werden, macht aus zwei erlaubten und
 * einem fremden nicht drei erlaubte.
 */
class LSV07P_Ajax_Portal {

    /** Wie viele Pläne höchstens gleichzeitig auf die Bühne dürfen. */
    const BUEHNE_MAX = 6;

    public static function init() {
        $map = [
            'lsv07p_s_basis'   => 'basis',
            'lsv07p_s_plaene'  => 'plaene',
            'lsv07p_s_plan'    => 'plan',
            'lsv07p_s_zeiten'  => 'zeiten',
            'lsv07p_s_verlauf' => 'verlauf',
            'lsv07p_s_buehne'  => 'buehne',
            'lsv07p_s_layout'  => 'layout',
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

    // ── Portal ──────────────────────────────────────────────────────────

    public static function basis() {
        self::wache();

        $ich   = LSV07P_Rollen::user_id();
        $heute = current_time( 'Y-m-d' );

        $teams = [];
        foreach ( LSV07P_Mannschaft::teams_von_schwimmer( $ich ) as $t ) {
            $team = LSV07P_Mannschaft::holen( $t );
            if ( ! $team ) continue;
            $trainer = [];
            foreach ( LSV07P_Mannschaft::trainer_von_team( $t ) as $u ) {
                $trainer[] = LSV07P_Rollen::name( $u );
            }
            $teams[] = [
                'id'      => (int) $t,
                'name'    => (string) $team['name'],
                'trainer' => $trainer,
            ];
        }

        /* Was als Nächstes ansteht — und was zuletzt war. Das sind die
           beiden Fragen, mit denen jemand das Portal öffnet. */
        $kommend = LSV07P_Plan::liste_schwimmer( $ich, $heute, '2999-12-31' );
        $kommend = array_reverse( $kommend );               // aufsteigend
        $vorbei  = LSV07P_Plan::liste_schwimmer( $ich, '1970-01-01',
            gmdate( 'Y-m-d', strtotime( $heute . ' -1 day' ) ) );

        wp_send_json_success( [
            'name'       => LSV07P_Rollen::name( $ich ),
            'vorname'    => self::vorname( LSV07P_Rollen::name( $ich ) ),
            'rolle'      => LSV07P_Rollen::rolle(),
            'darf_planen' => LSV07P_Rollen::darf_planen(),
            'teams'      => $teams,
            'kommend'    => array_slice( $kommend, 0, 10 ),
            'vorbei'     => array_slice( $vorbei, 0, 10 ),
            'bestzeiten' => LSV07P_Zeiten::bestzeiten( $ich ),
            'buehne_max' => self::BUEHNE_MAX,
            'layout'     => self::layout_lesen( $ich ),
        ] );
    }

    /** „Hallo Mia" — der Vorname genügt. */
    private static function vorname( $name ) {
        $teile = preg_split( '/\s+/u', trim( (string) $name ) ) ?: [];
        return $teile ? (string) $teile[0] : (string) $name;
    }

    public static function plaene() {
        self::wache();

        /* Ein Trainer, der das Portal öffnet, sieht hier seine eigene
           Sicht als Schwimmer — also die Pläne, die für IHN freigegeben
           sind. Seine Trainerpläne stehen im Trainerbereich. */
        wp_send_json_success( [
            'plaene' => LSV07P_Plan::liste_schwimmer( LSV07P_Rollen::user_id(),
                (string) self::feld( 'von' ), (string) self::feld( 'bis' ) ),
        ] );
    }

    public static function plan() {
        self::wache();

        $id = (int) self::feld( 'plan_id', 0 );
        if ( ! LSV07P_Plan::darf_sehen( $id ) ) {
            /* Dieselbe Antwort für „gibt es nicht" und „darf ich nicht":
               Sonst liesse sich durch Probieren herausfinden, welche
               Plannummern es gibt. */
            wp_send_json_error( [ 'message' => 'Dieser Plan ist für dich nicht freigegeben.' ], 403 );
        }

        $plan = LSV07P_Plan::voll( $id, ! LSV07P_Rollen::darf_planen() );
        if ( ! $plan ) {
            wp_send_json_error( [ 'message' => 'Dieser Plan ist für dich nicht freigegeben.' ], 403 );
        }
        wp_send_json_success( $plan );
    }

    public static function zeiten() {
        self::wache();
        $ich = LSV07P_Rollen::user_id();
        wp_send_json_success( [
            'zeiten'     => LSV07P_Zeiten::liste( $ich ),
            'bestzeiten' => LSV07P_Zeiten::bestzeiten( $ich ),
            'strecken'   => LSV07P_Zeiten::vorhandene_strecken( $ich ),
            'hinweis'    => 'Zeiten trägt die Trainerin oder der Trainer ein. '
                          . 'Stimmt eine Zeit nicht, sag es ihnen — sie können sie ändern.',
        ] );
    }

    public static function verlauf() {
        self::wache();
        $ich = LSV07P_Rollen::user_id();
        wp_send_json_success( [
            'verlauf' => LSV07P_Zeiten::verlauf( $ich,
                (int) self::feld( 'strecke_m', 0 ),
                (string) self::feld( 'lage' ),
                (int) self::feld( 'bahnlaenge', 25 ) ),
        ] );
    }

    // ── Die Bühne ───────────────────────────────────────────────────────

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

        $fuer_schwimmer = ! LSV07P_Rollen::darf_planen();
        $plaene  = [];
        $verwehrt = 0;
        foreach ( $ids as $id ) {
            if ( ! LSV07P_Plan::darf_sehen( $id ) ) { $verwehrt++; continue; }
            $p = LSV07P_Plan::voll( $id, $fuer_schwimmer );
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
