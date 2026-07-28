<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sonderabrechnung: eigener Abrechnungstyp unter "Trainer" für Arbeit, die
 * sich nicht in Mannschaftstraining/Wettkampf/Kilometergeld abbilden lässt.
 * Statt Mannschaftszuordnung gibt es nur Tag + Stunden (+ optionale
 * Beschreibung) pro Eintrag.
 *
 * Sichtbarkeit: ausschliesslich per Recht ABRECHNUNG_SONDER_EIGEN_READ
 * (siehe LSV07I_Access::check('sonder_eigen') und get_access_map()['tabs']
 * ['tr_sonder']) — NICHT an die normale Trainer-Rolle gekoppelt.
 *
 * Technisch ist eine Sonderabrechnung ein ganz normaler Datensatz in
 * lsv07i_abrechnung mit bereich='sonder' (die Tabelle hat ohnehin schon
 * einen Bereich-Diskriminator für schwimmen/triathlon/fitness). Dadurch
 * funktionieren Einreichen, Genehmigen, Zurückgeben, Bezahlt-Markieren und
 * Löschen automatisch über die BESTEHENDEN Endpunkte in
 * LSV07I_Ajax_Abrechnung (lsv07i_abr_einreichen usw.) — gleiche Genehmiger,
 * gleiche Kassenwart-Liste, ohne jeden zusätzlichen Code. Diese Klasse
 * deckt nur das Erstellen der Abrechnung und die Tag+Stunden-Einträge ab.
 */
class LSV07I_Ajax_Sonderabrechnung {

    public static function init() {
        foreach ( [ 'get_or_create', 'save_eintrag', 'delete_eintrag' ] as $a ) {
            add_action( 'wp_ajax_lsv07i_sonder_' . $a, [ __CLASS__, $a ] );
        }
    }

    /** Abrechnung des laufenden Trainers für Quartal/Jahr holen oder anlegen. */
    public static function get_or_create() {
        LSV07I_Access::check( 'sonder_eigen' );
        global $wpdb;
        $p = $wpdb->prefix;

        $trainer_id = LSV07I_Ajax_Abrechnung::get_or_create_trainer_id();
        if ( ! $trainer_id ) {
            // get_or_create_trainer_id() legt ein fehlendes Profil nur für
            // Admins automatisch an. Wer NUR das Sonderabrechnung-Recht hat
            // (typischerweise kein "Trainer" im klassischen Sinn — genau
            // dafür ist dieses Feature gedacht), hat entsprechend noch
            // keines. Da LSV07I_Access::check('sonder_eigen') oben bereits
            // die Berechtigung geprüft hat, legen wir es hier direkt an,
            // statt den Nutzer zum Admin-Bereich zu schicken, wo er dieses
            // Recht ohnehin nicht selbst vergeben kann.
            $trainer_id = LSV07I_Ajax_Abrechnung::create_trainer_profile_for_current_user();
        }
        if ( ! $trainer_id ) {
            wp_send_json_error( [ 'message' => 'Trainer-Profil konnte nicht angelegt werden. Bitte an einen Administrator wenden.' ] );
        }

        $quartal = sanitize_text_field( $_POST['quartal'] ?? 'Q' . ceil( date( 'n' ) / 3 ) );
        if ( ! in_array( $quartal, [ 'Q1', 'Q2', 'Q3', 'Q4' ], true ) ) $quartal = 'Q1';
        $jahr = absint( $_POST['jahr'] ?? date( 'Y' ) );

        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_abrechnung
              WHERE trainer_id = %d AND bereich = 'sonder' AND quartal = %s AND jahr = %d LIMIT 1",
            $trainer_id, $quartal, $jahr
        ) );

        if ( ! $id ) {
            // Bewusst KEIN prefill_trainingstage/prefill_wettkampf wie bei
            // der regulären Abrechnung — Sonderabrechnungen haben keine
            // automatische Quelle, jeder Eintrag wird manuell angelegt.
            $wpdb->insert( $p . 'lsv07i_abrechnung', [
                'trainer_id' => $trainer_id, 'bereich' => 'sonder',
                'quartal' => $quartal, 'jahr' => $jahr, 'abr_status' => 'entwurf',
            ], [ '%d', '%s', '%s', '%d', '%s' ] );
            $id = $wpdb->insert_id;
        }

        wp_send_json_success( LSV07I_Ajax_Abrechnung::load_abrechnung( $id ) );
    }

    /** Eintrag (Tag + Stunden) anlegen oder bearbeiten. */
    public static function save_eintrag() {
        LSV07I_Access::check( 'sonder_eigen' );
        global $wpdb;
        $p = $wpdb->prefix;

        $abr_id       = absint( $_POST['abrechnung_id'] ?? 0 );
        $id           = absint( $_POST['id'] ?? 0 );
        $datum        = sanitize_text_field( $_POST['datum'] ?? '' );
        $stunden      = round( (float) ( $_POST['stunden'] ?? 0 ), 2 );
        $beschreibung = sanitize_text_field( $_POST['beschreibung'] ?? '' );

        if ( ! $abr_id || ! $datum || $stunden <= 0 ) {
            wp_send_json_error( [ 'message' => 'Bitte Datum und Stunden angeben.' ] );
        }
        self::assert_bereich_sonder( $abr_id );
        LSV07I_Ajax_Abrechnung::assert_owns( $abr_id );
        self::assert_bearbeitbar( $abr_id );

        if ( $id ) {
            $wpdb->update( $p . 'lsv07i_abr_sonder',
                [ 'datum' => $datum, 'stunden' => $stunden, 'beschreibung' => $beschreibung ],
                [ 'id' => $id ], [ '%s', '%f', '%s' ], [ '%d' ]
            );
        } else {
            $wpdb->insert( $p . 'lsv07i_abr_sonder', [
                'abrechnung_id' => $abr_id, 'datum' => $datum,
                'stunden' => $stunden, 'beschreibung' => $beschreibung,
            ], [ '%d', '%s', '%f', '%s' ] );
        }

        wp_send_json_success( LSV07I_Ajax_Abrechnung::load_abrechnung( $abr_id ) );
    }

    /** Eintrag löschen. */
    public static function delete_eintrag() {
        LSV07I_Access::check( 'sonder_eigen' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT abrechnung_id FROM {$p}lsv07i_abr_sonder WHERE id = %d LIMIT 1", $id
        ), ARRAY_A );
        if ( ! $row ) wp_send_json_error( [ 'message' => 'Eintrag nicht gefunden.' ] );

        $abr_id = (int) $row['abrechnung_id'];
        self::assert_bereich_sonder( $abr_id );
        LSV07I_Ajax_Abrechnung::assert_owns( $abr_id );
        self::assert_bearbeitbar( $abr_id );

        // Keine Prefill-Quelle bei Sonderabrechnungen (immer 'manuell') —
        // echtes Löschen genügt, anders als bei Trainingstagen aus
        // Anwesenheit gibt es hier nichts, das wieder eingefügt werden könnte.
        $wpdb->delete( $p . 'lsv07i_abr_sonder', [ 'id' => $id ], [ '%d' ] );

        wp_send_json_success( LSV07I_Ajax_Abrechnung::load_abrechnung( $abr_id ) );
    }

    /** Absicherung: Diese Klasse darf ausschließlich bereich='sonder' anfassen. */
    private static function assert_bereich_sonder( $abr_id ) {
        global $wpdb;
        $bereich = $wpdb->get_var( $wpdb->prepare(
            "SELECT bereich FROM {$wpdb->prefix}lsv07i_abrechnung WHERE id = %d LIMIT 1", $abr_id
        ) );
        if ( $bereich !== 'sonder' ) {
            wp_send_json_error( [ 'message' => 'Ungültige Abrechnung.' ], 403 );
        }
    }

    /** Nur im Entwurf/zurückgegeben darf der Trainer selbst noch ändern. */
    private static function assert_bearbeitbar( $abr_id ) {
        if ( LSV07I_Access::is_admin() ) return;
        global $wpdb;
        $status = $wpdb->get_var( $wpdb->prepare(
            "SELECT abr_status FROM {$wpdb->prefix}lsv07i_abrechnung WHERE id = %d LIMIT 1", $abr_id
        ) );
        if ( ! in_array( $status, [ 'entwurf', 'zurueck' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Diese Abrechnung ist bereits eingereicht und kann nicht mehr bearbeitet werden.' ] );
        }
    }
}
