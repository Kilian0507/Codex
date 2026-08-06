<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * LSV07I_Ajax_Trainer_Sportler
 * -----------------------------
 * Trainer-Bereich: eigene Sportler anlegen und in eine andere Mannschaft
 * verschieben. Nutzt dieselbe mv_swimmers-Datenbasis wie die Admin-
 * Sportlerverwaltung (class-ajax-admin.php), aber serverseitig auf die
 * Mannschaften des jeweiligen Trainers beschränkt:
 *   - Anlegen: neue Sportler dürfen nur den eigenen Mannschaften zugeordnet
 *     werden — nicht dem Client vertraut, sondern gegen die tatsächliche
 *     Trainer-Mannschaft-Zuordnung geprüft.
 *   - Verschieben: nur Sportler, die zu einer eigenen Mannschaft gehören,
 *     dürfen bewegt werden. Das Ziel darf jede beliebige Mannschaft sein
 *     (kein Fremdvertrauen nötig, da "verschieben" den Sportler ohnehin nur
 *     an einen anderen Trainer übergibt).
 *   - Bearbeiten bestehender Sportler (Name, Attest, Kontakte, …) ist hier
 *     bewusst NICHT möglich — dafür bleibt der Admin-Bereich zuständig.
 * Admin/Schwimmwart sehen und verschieben wie gewohnt uneingeschränkt alle
 * Mannschaften (Sicherheitsventil, siehe erlaubte_mannschaft_ids()).
 */
class LSV07I_Ajax_Trainer_Sportler {

    public static function init() {
        add_action( 'wp_ajax_lsv07i_tr_sportler_liste',       [ __CLASS__, 'liste' ] );
        add_action( 'wp_ajax_lsv07i_tr_sportler_save',        [ __CLASS__, 'save' ] );
        add_action( 'wp_ajax_lsv07i_tr_sportler_verschieben', [ __CLASS__, 'verschieben' ] );
    }

    /** Mannschaft-IDs, die der aktuelle Nutzer bearbeiten darf. */
    private static function erlaubte_mannschaft_ids() {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( LSV07I_Access::is_admin() || LSV07I_Access::is_schwimmwart() ) {
            return array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$p}lsv07_gruppen" ) );
        }
        $trainer_id = LSV07I_Access::get_trainer_id();
        if ( ! $trainer_id ) return [];
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT mannschaft_id FROM {$p}lsv07i_trainer_mannschaft WHERE trainer_id = %d",
            $trainer_id
        ) ) );
    }

    /** Aktive Sportler, die zu (mindestens) einer der erlaubten Mannschaften gehören. */
    private static function sportler_in( array $mannschaft_ids ) {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( empty( $mannschaft_ids ) ) return [];
        $in = implode( ',', $mannschaft_ids );
        return $wpdb->get_results(
            "SELECT DISTINCT s.id, s.first_name, s.last_name, s.birth_date, s.team_id, g.name AS mannschaft_name
               FROM {$p}mv_swimmers s
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = s.team_id
              WHERE s.active = 1
                AND (
                    s.team_id IN ($in)
                    OR EXISTS (
                        SELECT 1 FROM {$p}lsv07i_swimmer_gruppen sg
                         WHERE sg.swimmer_id = s.id AND sg.gruppe_id IN ($in)
                    )
                )
           ORDER BY s.last_name ASC, s.first_name ASC",
            ARRAY_A
        );
    }

    public static function liste() {
        LSV07I_Access::check( 'trainer' );
        global $wpdb;
        $p = $wpdb->prefix;

        $erlaubt = self::erlaubte_mannschaft_ids();
        $alle_mannschaften = $wpdb->get_results( "SELECT id, name FROM {$p}lsv07_gruppen ORDER BY name ASC", ARRAY_A );
        $eigene_mannschaften = empty( $erlaubt ) ? [] : array_values( array_filter(
            $alle_mannschaften, fn( $m ) => in_array( (int) $m['id'], $erlaubt, true )
        ) );

        wp_send_json_success( [
            'sportler'          => self::sportler_in( $erlaubt ),
            'mannschaften'      => $eigene_mannschaften,
            'alle_mannschaften' => $alle_mannschaften,
        ] );
    }

    /** Neuen Sportler anlegen — nur in eigenen Mannschaften, keine Bearbeitung bestehender. */
    public static function save() {
        LSV07I_Access::check( 'trainer' );
        global $wpdb;
        $p = $wpdb->prefix;

        if ( ! empty( $_POST['id'] ) ) {
            wp_send_json_error( [ 'message' => 'Bestehende Sportler können hier nicht bearbeitet werden.' ] );
        }

        $erlaubt = self::erlaubte_mannschaft_ids();
        if ( empty( $erlaubt ) ) {
            wp_send_json_error( [ 'message' => 'Dir ist keine Mannschaft zugeordnet.' ] );
        }

        $last_name    = sanitize_text_field( $_POST['last_name']  ?? '' );
        $first_name   = sanitize_text_field( $_POST['first_name'] ?? '' );
        $birth_date   = sanitize_text_field( $_POST['birth_date'] ?? '' );
        $attest       = sanitize_text_field( $_POST['attest_expires'] ?? '' );
        $dsv_id       = sanitize_text_field( $_POST['dsv_id']     ?? '' );
        $datenschutz  = absint( $_POST['datenschutz']  ?? 0 );
        $notes        = sanitize_textarea_field( $_POST['notes']  ?? '' );
        $gruppe_ids   = array_filter( array_map( 'absint', (array) ( $_POST['gruppe_ids'] ?? [] ) ) );
        $kontakte_raw = sanitize_text_field( $_POST['kontakte_json'] ?? '[]' );
        $kontakte     = json_decode( stripslashes( $kontakte_raw ), true );
        if ( ! is_array( $kontakte ) ) $kontakte = [];

        if ( ! $last_name || ! $first_name || ! $birth_date ) {
            wp_send_json_error( [ 'message' => 'Nachname, Vorname und Geburtsdatum sind Pflichtfelder.' ] );
        }
        if ( empty( $gruppe_ids ) ) {
            wp_send_json_error( [ 'message' => 'Bitte mindestens eine Mannschaft wählen.' ] );
        }
        // Serverseitig durchsetzen: nur eigene Mannschaften, unabhängig davon,
        // was die Oberfläche anbietet oder ein manipulierter Request schickt.
        if ( array_diff( $gruppe_ids, $erlaubt ) ) {
            wp_send_json_error( [ 'message' => 'Du kannst Sportler nur deinen eigenen Mannschaften zuordnen.' ] );
        }

        $gruppe_ids = array_values( array_unique( $gruppe_ids ) );
        $team_id    = $gruppe_ids[0];
        $jahr       = $birth_date ? date( 'Y', strtotime( $birth_date ) ) : '';

        $data = [
            'team_id'        => $team_id,
            'last_name'      => $last_name,
            'first_name'     => $first_name,
            'birth_date'     => $birth_date,
            'attest_expires' => $attest ?: null,
            'dsv_id'         => $dsv_id,
            'datenschutz'    => $datenschutz,
            'display_name'   => $last_name . ', ' . $first_name . ( $jahr ? ' (' . $jahr . ')' : '' ),
            'notes'          => $notes,
            'active'         => 1,
        ];
        $wpdb->insert( $p . 'mv_swimmers', $data, [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d' ] );
        $id = $wpdb->insert_id;

        foreach ( $gruppe_ids as $gid ) {
            $wpdb->insert( $p . 'lsv07i_swimmer_gruppen', [ 'swimmer_id' => $id, 'gruppe_id' => $gid ], [ '%d', '%d' ] );
        }
        LSV07I_DB::save_kontakte( $id, $kontakte );

        LSV07I_Log::write( 'schwimmer.create', [
            'bereich'   => 'Trainer',
            'ziel_typ'  => 'schwimmer',
            'ziel_id'   => $id,
            'ziel_name' => trim( $first_name . ' ' . $last_name ),
        ] );

        wp_send_json_success( [ 'id' => $id, 'sportler' => self::sportler_in( $erlaubt ) ] );
    }

    /** Sportler in eine andere Mannschaft verschieben. Quelle muss eigene Mannschaft sein, Ziel beliebig. */
    public static function verschieben() {
        LSV07I_Access::check( 'trainer' );
        global $wpdb;
        $p = $wpdb->prefix;

        $id   = absint( $_POST['id'] ?? 0 );
        $ziel = absint( $_POST['ziel_mannschaft_id'] ?? 0 );
        if ( ! $id || ! $ziel ) wp_send_json_error( [ 'message' => 'Fehlende Angaben.' ] );

        $erlaubt = self::erlaubte_mannschaft_ids();
        if ( empty( $erlaubt ) ) wp_send_json_error( [ 'message' => 'Dir ist keine Mannschaft zugeordnet.' ] );

        $sportler = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, team_id, first_name, last_name FROM {$p}mv_swimmers WHERE id = %d AND active = 1 LIMIT 1", $id
        ), ARRAY_A );
        if ( ! $sportler ) wp_send_json_error( [ 'message' => 'Sportler nicht gefunden.' ] );

        // Quelle muss eine eigene Mannschaft sein — Heimat-Mannschaft ODER
        // eine der Zusatz-Mannschaften des Sportlers.
        $ist_eigene = in_array( (int) $sportler['team_id'], $erlaubt, true );
        if ( ! $ist_eigene ) {
            $zusatz = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
                "SELECT gruppe_id FROM {$p}lsv07i_swimmer_gruppen WHERE swimmer_id = %d", $id
            ) ) );
            $ist_eigene = (bool) array_intersect( $zusatz, $erlaubt );
        }
        if ( ! $ist_eigene ) {
            wp_send_json_error( [ 'message' => 'Dieser Sportler gehört zu keiner deiner Mannschaften.' ], 403 );
        }

        $ziel_existiert = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07_gruppen WHERE id = %d", $ziel
        ) );
        if ( ! $ziel_existiert ) wp_send_json_error( [ 'message' => 'Zielmannschaft nicht gefunden.' ] );

        // Verschieben = vollständige Neuzuordnung: der Sportler gehört
        // danach ausschließlich zur Zielmannschaft (nicht zusätzlich).
        $wpdb->update( $p . 'mv_swimmers', [ 'team_id' => $ziel ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_swimmer_gruppen', [ 'swimmer_id' => $id ], [ '%d' ] );
        $wpdb->insert( $p . 'lsv07i_swimmer_gruppen', [ 'swimmer_id' => $id, 'gruppe_id' => $ziel ], [ '%d', '%d' ] );
        $wpdb->update( $p . 'lsv07i_bestzeiten', [ 'mannschaft_id' => $ziel ], [ 'swimmer_id' => $id ], [ '%d' ], [ '%d' ] );

        LSV07I_Log::write( 'schwimmer.verschieben', [
            'bereich'   => 'Trainer',
            'ziel_typ'  => 'schwimmer',
            'ziel_id'   => $id,
            'ziel_name' => trim( $sportler['first_name'] . ' ' . $sportler['last_name'] ),
            'details'   => 'nach Mannschaft #' . $ziel,
        ] );

        wp_send_json_success( [ 'sportler' => self::sportler_in( self::erlaubte_mannschaft_ids() ) ] );
    }
}
