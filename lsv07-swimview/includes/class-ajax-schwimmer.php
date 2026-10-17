<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Endpunkte für angemeldete Schwimmerinnen und Schwimmer.
 *
 * Alle hängen auch an `wp_ajax_nopriv_`, denn hier ist niemand bei
 * WordPress angemeldet — die Anmeldung läuft über das eigene Sitzungs-Cookie.
 * Damit fällt auch der WordPress-Nonce als Schutz aus: Für abgemeldete
 * Besucher liefert er für alle denselben Wert und schützt gegen gar nichts.
 * Stattdessen ein eigenes Token, doppelt eingereicht (Cookie + Feld).
 *
 * Jeder Endpunkt prüft zuerst die Anmeldung, dann das Token, dann holt er
 * die Daten über LSV07S_Daten — wo die Freigabe noch einmal geprüft wird.
 */
class LSV07S_Ajax_Schwimmer {

    public static function init() {
        $map = [
            'lsv07s_login'       => 'login',
            'lsv07s_logout'      => 'logout',
            'lsv07s_start'       => 'start',
            'lsv07s_anwesenheit' => 'anwesenheit',
            'lsv07s_vermerke'    => 'vermerke',
            'lsv07s_aufgaben'    => 'aufgaben',
            'lsv07s_doku'        => 'doku',
            'lsv07s_wettkaempfe' => 'wettkaempfe',
            'lsv07s_bestzeiten'  => 'bestzeiten',
            'lsv07s_reflexion'   => 'reflexion',
            'lsv07s_refl_save'   => 'reflexion_speichern',
            'lsv07s_plaene'      => 'plaene',
            'lsv07s_plan'        => 'plan',
            'lsv07s_nachrichten' => 'nachrichten',
            'lsv07s_gelesen'     => 'gelesen',
        ];
        foreach ( $map as $action => $methode ) {
            add_action( 'wp_ajax_' . $action,        [ __CLASS__, $methode ] );
            add_action( 'wp_ajax_nopriv_' . $action, [ __CLASS__, $methode ] );
        }
    }

    // ── Gemeinsames ─────────────────────────────────────────────────────

    /** Prüft das eigene Token. Ohne gültiges Token geht hier nichts. */
    private static function token_pruefen() {
        $feld = isset( $_POST['token'] ) ? (string) wp_unslash( $_POST['token'] ) : '';
        if ( ! LSV07S_Login::csrf_ok( $feld ) ) {
            wp_send_json_error( [ 'message' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.',
                                  'neu_laden' => true ], 403 );
        }
    }

    /**
     * Die angemeldete Person, oder Abbruch. Der Hinweis „abgemeldet" sagt
     * der Oberfläche, dass sie zur Anmeldung zurück soll — sonst stünde
     * jemand vor einer Fehlermeldung ohne Ausweg.
     */
    private static function ich() {
        self::token_pruefen();
        $s = LSV07S_Login::schwimmer();
        if ( ! $s ) {
            wp_send_json_error( [ 'message' => 'Du bist nicht mehr angemeldet.',
                                  'abgemeldet' => true ], 401 );
        }
        return $s;
    }

    private static function datum_feld( $name ) {
        $roh = isset( $_POST[ $name ] ) ? (string) wp_unslash( $_POST[ $name ] ) : '';
        $d   = LSV07S_Login::datum_norm( $roh );
        return $d;
    }

    // ── Anmelden und abmelden ───────────────────────────────────────────

    public static function login() {
        self::token_pruefen();

        $dsv   = isset( $_POST['dsv'] )   ? (string) wp_unslash( $_POST['dsv'] )   : '';
        $datum = isset( $_POST['datum'] ) ? (string) wp_unslash( $_POST['datum'] ) : '';

        $ergebnis = LSV07S_Login::anmelden( $dsv, $datum );
        if ( ! $ergebnis['ok'] ) {
            wp_send_json_error( [
                'message'  => $ergebnis['message'],
                'gesperrt' => ! empty( $ergebnis['gesperrt'] ),
            ], 401 );
        }

        wp_send_json_success( LSV07S_Daten::startdaten( $ergebnis['schwimmer'] ) );
    }

    public static function logout() {
        self::token_pruefen();
        LSV07S_Login::abmelden();
        wp_send_json_success( [ 'abgemeldet' => true ] );
    }

    /** Was die Oberfläche nach der Anmeldung braucht. */
    public static function start() {
        $s = self::ich();
        wp_send_json_success( LSV07S_Daten::startdaten( $s ) );
    }

    // ── Die Ansichten ───────────────────────────────────────────────────

    public static function anwesenheit() {
        $s = self::ich();
        if ( ! LSV07S_Freigabe::darf( (int) $s['id'], 'anwesenheit' ) ) self::gesperrt();
        wp_send_json_success( LSV07S_Daten::anwesenheit(
            (int) $s['id'], self::datum_feld( 'von' ), self::datum_feld( 'bis' ) ) );
    }

    public static function vermerke() {
        $s = self::ich();
        if ( ! LSV07S_Freigabe::darf( (int) $s['id'], 'vermerke' ) ) self::gesperrt();
        wp_send_json_success( LSV07S_Daten::vermerke(
            (int) $s['id'], self::datum_feld( 'von' ), self::datum_feld( 'bis' ) ) );
    }

    public static function aufgaben() {
        $s = self::ich();
        if ( ! LSV07S_Freigabe::darf( (int) $s['id'], 'aufgaben' ) ) self::gesperrt();
        wp_send_json_success( LSV07S_Daten::aufgaben(
            (int) $s['id'], self::datum_feld( 'von' ), self::datum_feld( 'bis' ) ) );
    }

    public static function doku() {
        $s = self::ich();
        if ( ! LSV07S_Freigabe::darf( (int) $s['id'], 'dokumentation' ) ) self::gesperrt();
        wp_send_json_success( LSV07S_Daten::dokumentation(
            (int) $s['id'], self::datum_feld( 'von' ), self::datum_feld( 'bis' ) ) );
    }

    public static function wettkaempfe() {
        $s = self::ich();
        if ( ! LSV07S_Freigabe::darf( (int) $s['id'], 'wettkaempfe' ) ) self::gesperrt();
        wp_send_json_success( LSV07S_Daten::wettkaempfe( (int) $s['id'] ) );
    }

    public static function bestzeiten() {
        $s = self::ich();
        if ( ! LSV07S_Freigabe::darf( (int) $s['id'], 'bestzeiten' ) ) self::gesperrt();
        wp_send_json_success( LSV07S_Daten::bestzeiten( (int) $s['id'] ) );
    }

    public static function reflexion() {
        $s = self::ich();
        if ( ! LSV07S_Freigabe::darf( (int) $s['id'], 'reflexion' ) ) self::gesperrt();
        wp_send_json_success( LSV07S_Daten::reflexion( (int) $s['id'] ) );
    }

    public static function reflexion_speichern() {
        $s = self::ich();
        if ( ! LSV07S_Freigabe::darf( (int) $s['id'], 'reflexion' ) ) self::gesperrt();

        $antworten = [];
        if ( isset( $_POST['frage'] ) && is_array( $_POST['frage'] ) ) {
            foreach ( wp_unslash( $_POST['frage'] ) as $fid => $text ) {
                $antworten[ (int) $fid ] = is_string( $text ) ? $text : '';
            }
        }
        $wunsch = [];
        if ( isset( $_POST['wunsch'] ) && is_array( $_POST['wunsch'] ) ) {
            foreach ( wp_unslash( $_POST['wunsch'] ) as $strecke => $zeit ) {
                $wunsch[ (string) $strecke ] = is_string( $zeit ) ? $zeit : '';
            }
        }
        $abgeben = ! empty( $_POST['abgeben'] );

        $r = LSV07S_Daten::reflexion_speichern( (int) $s['id'], $antworten, $wunsch, $abgeben );
        if ( empty( $r['ok'] ) ) {
            wp_send_json_error( [ 'message' => $r['message'] ?? 'Das hat nicht geklappt.' ], 400 );
        }
        wp_send_json_success( [
            'abgegeben' => ! empty( $r['abgegeben'] ),
            'message'   => ! empty( $r['abgegeben'] )
                           ? 'Dein Bogen ist abgegeben. Danke!'
                           : 'Gespeichert. Du kannst später weitermachen.',
        ] );
    }

    public static function plaene() {
        $s = self::ich();
        if ( ! LSV07S_Freigabe::darf( (int) $s['id'], 'trainingsplan' ) ) self::gesperrt();
        wp_send_json_success( LSV07S_Daten::trainingsplaene( (int) $s['id'] ) );
    }

    public static function plan() {
        $s = self::ich();
        if ( ! LSV07S_Freigabe::darf( (int) $s['id'], 'trainingsplan' ) ) self::gesperrt();
        $plan = LSV07S_Daten::trainingsplan( (int) $s['id'], (int) ( $_POST['plan_id'] ?? 0 ) );
        if ( ! $plan ) {
            wp_send_json_error( [ 'message' => 'Dieser Trainingsplan ist für dich nicht freigegeben.' ], 403 );
        }
        wp_send_json_success( [ 'plan' => $plan ] );
    }

    public static function nachrichten() {
        $s = self::ich();
        if ( ! LSV07S_Freigabe::darf( (int) $s['id'], 'nachrichten' ) ) self::gesperrt();
        wp_send_json_success( LSV07S_Daten::nachrichten( (int) $s['id'] ) );
    }

    public static function gelesen() {
        $s  = self::ich();
        $id = (int) ( $_POST['id'] ?? 0 );
        if ( ! LSV07S_Daten::nachricht_gelesen( (int) $s['id'], $id ) ) {
            wp_send_json_error( [ 'message' => 'Diese Nachricht gibt es für dich nicht.' ], 403 );
        }
        $n = LSV07S_Daten::nachrichten( (int) $s['id'] );
        wp_send_json_success( [ 'ungelesen' => (int) $n['ungelesen'] ] );
    }

    private static function gesperrt() {
        wp_send_json_error( [
            'message'   => 'Dieser Bereich ist für deine Mannschaft nicht freigegeben.',
            'gesperrt'  => true,
        ], 403 );
    }
}
