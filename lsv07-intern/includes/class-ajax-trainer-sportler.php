<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * LSV07I_Ajax_Trainer_Sportler
 * -----------------------------
 * Trainer-Bereich: eigene Sportler anlegen, bearbeiten und in eine andere
 * Mannschaft verschieben. Nutzt dieselbe mv_swimmers-Datenbasis wie die
 * Admin-Sportlerverwaltung (class-ajax-admin.php), aber serverseitig auf
 * die Mannschaften des jeweiligen Trainers beschränkt:
 *   - Anlegen/Bearbeiten: Sportler dürfen nur den eigenen Mannschaften
 *     zugeordnet werden — nicht dem Client vertraut, sondern gegen die
 *     tatsächliche Trainer-Mannschaft-Zuordnung geprüft. Zusätzliche
 *     Mannschafts-Zuordnungen AUSSERHALB der eigenen Mannschaften (falls ein
 *     Sportler noch bei einer fremden Mannschaft mittrainiert) werden beim
 *     Speichern nicht angezeigt, aber auch nicht gelöscht — nur explizit per
 *     "Verschieben" entfernt.
 *   - Verschieben: nur Sportler, die zu einer eigenen Mannschaft gehören,
 *     dürfen bewegt werden. Das Ziel darf jede beliebige Mannschaft sein
 *     (kein Fremdvertrauen nötig, da "verschieben" den Sportler ohnehin nur
 *     an einen anderen Trainer übergibt) und ersetzt — anders als
 *     Bearbeiten — alle bisherigen Mannschaften vollständig.
 * Zugriff ausschließlich über das eigene Recht SCHWIMMEN_TR_SPORTLER_MANAGE
 * (kein Rollen-Fallback, siehe class-access.php). Admin/Schwimmwart sehen
 * und bearbeiten wie gewohnt uneingeschränkt alle Mannschaften
 * (Sicherheitsventil, siehe erlaubte_mannschaft_ids()).
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

    /** Alle Mannschafts-IDs eines Sportlers (Heimat-Mannschaft + Zusatz-Mannschaften). */
    private static function gruppen_ids_von( $swimmer_id, $heim_team_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT gruppe_id FROM {$p}lsv07i_swimmer_gruppen WHERE swimmer_id = %d", $swimmer_id
        ) ) );
        if ( $heim_team_id ) $ids[] = (int) $heim_team_id;
        return array_values( array_unique( $ids ) );
    }

    /** Aktive Sportler, die zu (mindestens) einer der erlaubten Mannschaften gehören — mit allen Detaildaten fürs Bearbeiten-Formular. */
    private static function sportler_in( array $mannschaft_ids ) {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( empty( $mannschaft_ids ) ) return [];
        $in = implode( ',', $mannschaft_ids );
        $rows = $wpdb->get_results(
            "SELECT DISTINCT s.id, s.first_name, s.last_name, s.birth_date, s.team_id,
                    s.attest_expires, s.dsv_id, s.datenschutz, s.notes,
                    g.name AS mannschaft_name
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
        if ( empty( $rows ) ) return $rows;

        $sw_ids = array_map( fn( $r ) => (int) $r['id'], $rows );
        $ids_in = implode( ',', $sw_ids );

        $k_rows = $wpdb->get_results(
            "SELECT * FROM {$p}lsv07i_kontakte WHERE swimmer_id IN ($ids_in) ORDER BY sort_order ASC", ARRAY_A
        );
        $k_map = [];
        foreach ( $k_rows as $k ) $k_map[ (int) $k['swimmer_id'] ][] = $k;

        $g_rows = $wpdb->get_results(
            "SELECT sg.swimmer_id, g.id, g.name
               FROM {$p}lsv07_gruppen g
               JOIN (
                   SELECT id AS swimmer_id, team_id AS gid FROM {$p}mv_swimmers
                    WHERE id IN ($ids_in) AND team_id IS NOT NULL
                   UNION
                   SELECT swimmer_id, gruppe_id AS gid FROM {$p}lsv07i_swimmer_gruppen
                    WHERE swimmer_id IN ($ids_in)
               ) sg ON sg.gid = g.id", ARRAY_A
        );
        $g_map = [];
        foreach ( $g_rows as $g ) $g_map[ (int) $g['swimmer_id'] ][] = (int) $g['id'];

        foreach ( $rows as &$row ) {
            $sid = (int) $row['id'];
            $row['kontakte']        = $k_map[ $sid ] ?? [];
            $row['alle_gruppen_ids'] = $g_map[ $sid ] ?? [];
        }
        unset( $row );
        return $rows;
    }

    /** Prüft, ob ein Sportler (über Heimat- oder Zusatz-Mannschaft) zu einer der erlaubten Mannschaften gehört. */
    private static function ist_eigener_sportler( $swimmer_id, $team_id, array $erlaubt ) {
        if ( in_array( (int) $team_id, $erlaubt, true ) ) return true;
        global $wpdb;
        $p = $wpdb->prefix;
        $zusatz = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT gruppe_id FROM {$p}lsv07i_swimmer_gruppen WHERE swimmer_id = %d", $swimmer_id
        ) ) );
        return (bool) array_intersect( $zusatz, $erlaubt );
    }

    public static function liste() {
        LSV07I_Access::check( 'tr_sportler_manage' );
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

    /**
     * Sportler anlegen ODER bearbeiten (beides innerhalb der eigenen
     * Mannschaften). Beim Bearbeiten bleiben Mannschafts-Zuordnungen
     * AUSSERHALB der eigenen Mannschaften unangetastet erhalten — der
     * Trainer sieht und ändert nur seinen eigenen Ausschnitt.
     */
    public static function save() {
        LSV07I_Access::check( 'tr_sportler_manage' );
        global $wpdb;
        $p = $wpdb->prefix;

        $id      = absint( $_POST['id'] ?? 0 );
        $erlaubt = self::erlaubte_mannschaft_ids();
        if ( empty( $erlaubt ) ) {
            wp_send_json_error( [ 'message' => 'Dir ist keine Mannschaft zugeordnet.' ] );
        }

        $bestehend = null;
        if ( $id ) {
            $bestehend = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, team_id FROM {$p}mv_swimmers WHERE id = %d AND active = 1 LIMIT 1", $id
            ), ARRAY_A );
            if ( ! $bestehend ) wp_send_json_error( [ 'message' => 'Sportler nicht gefunden.' ] );
            if ( ! self::ist_eigener_sportler( $id, $bestehend['team_id'], $erlaubt ) ) {
                wp_send_json_error( [ 'message' => 'Dieser Sportler gehört zu keiner deiner Mannschaften.' ], 403 );
            }
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

        // Beim Bearbeiten: Mannschafts-Zuordnungen außerhalb der eigenen
        // Mannschaften bleiben erhalten, statt beim Speichern verloren zu gehen.
        $fremde_gruppen = [];
        if ( $id ) {
            $vorher = self::gruppen_ids_von( $id, $bestehend['team_id'] );
            $fremde_gruppen = array_diff( $vorher, $erlaubt );
        }
        $finale_gruppen = array_values( array_unique( array_merge( $gruppe_ids, $fremde_gruppen ) ) );

        $team_id = $gruppe_ids[0];
        $jahr    = $birth_date ? date( 'Y', strtotime( $birth_date ) ) : '';

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
        ];

        if ( $id ) {
            $wpdb->update( $p . 'mv_swimmers', $data, [ 'id' => $id ],
                [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ], [ '%d' ] );
        } else {
            $data['active'] = 1;
            $wpdb->insert( $p . 'mv_swimmers', $data, [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d' ] );
            $id = $wpdb->insert_id;
        }

        $wpdb->delete( $p . 'lsv07i_swimmer_gruppen', [ 'swimmer_id' => $id ], [ '%d' ] );
        foreach ( $finale_gruppen as $gid ) {
            $wpdb->insert( $p . 'lsv07i_swimmer_gruppen', [ 'swimmer_id' => $id, 'gruppe_id' => $gid ], [ '%d', '%d' ] );
        }
        $wpdb->update( $p . 'lsv07i_bestzeiten', [ 'mannschaft_id' => $team_id ], [ 'swimmer_id' => $id ], [ '%d' ], [ '%d' ] );
        LSV07I_DB::save_kontakte( $id, $kontakte );

        LSV07I_Log::write( $bestehend ? 'schwimmer.update' : 'schwimmer.create', [
            'bereich'   => 'Trainer',
            'ziel_typ'  => 'schwimmer',
            'ziel_id'   => $id,
            'ziel_name' => trim( $first_name . ' ' . $last_name ),
        ] );

        wp_send_json_success( [ 'id' => $id, 'sportler' => self::sportler_in( $erlaubt ) ] );
    }

    /** Sportler in eine andere Mannschaft verschieben. Quelle muss eigene Mannschaft sein, Ziel beliebig. */
    public static function verschieben() {
        LSV07I_Access::check( 'tr_sportler_manage' );
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

        if ( ! self::ist_eigener_sportler( $id, $sportler['team_id'], $erlaubt ) ) {
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
