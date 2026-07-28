<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Ajax_Schwimmen {

    public static function init() {
        $actions = [
            'lsv07i_schwimmen_get_data',
            'lsv07i_schwimmen_get_schwimmer',
            'lsv07i_schwimmen_get_profil',
            'lsv07i_schwimmen_update_schwimmer',
            'lsv07i_schwimmen_get_trainer',
        ];
        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, str_replace( 'lsv07i_schwimmen_', '', $action ) ] );
        }
    }

    public static function get_data() {
        LSV07I_Access::check( 'schwimmen_read' );
        global $wpdb;
        $p          = $wpdb->prefix;
        $trainer_id = LSV07I_Access::get_trainer_id();
        $is_admin   = LSV07I_Access::is_admin();
        $is_sw      = LSV07I_Access::is_schwimmwart();

        if ( $trainer_id && ! $is_admin && ! $is_sw ) {
            // Eigene Mannschaften des Trainers
            $mannschaft_ids = LSV07I_DB::get_trainer_mannschaften( $trainer_id );
            if ( empty( $mannschaft_ids ) ) {
                $mannschaften = [];
                $slots        = [];
            } else {
                $in = implode( ',', array_map( 'absint', $mannschaft_ids ) );
                $mannschaften = $wpdb->get_results(
                    "SELECT * FROM {$p}lsv07_gruppen WHERE id IN ($in) ORDER BY sort_order ASC",
                    ARRAY_A
                );
                // Slots: eigene Mannschaften
                $slots = $wpdb->get_results(
                    "SELECT s.*, g.name AS mannschaft_name
                       FROM {$p}lsv07i_training_slots s
                  LEFT JOIN {$p}lsv07_gruppen g ON g.id = s.mannschaft_id
                      WHERE s.mannschaft_id IN ($in)
                   ORDER BY s.wochentag ASC, s.zeit_von ASC",
                    ARRAY_A
                );
            }

            // Zusätzlich: Slots wo der Trainer gerade als Springer eingetragen ist
            // (nur zukünftige / heutige Trainings)
            $springer_slots = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT s.*, g.name AS mannschaft_name, 1 AS ist_springer_slot
                   FROM {$p}lsv07i_training_slots s
              LEFT JOIN {$p}lsv07_gruppen g ON g.id = s.mannschaft_id
                   JOIN {$p}lsv07i_springer sp ON sp.slot_id = s.id
                        AND sp.trainer_id = %d AND sp.training_datum >= CURDATE() - INTERVAL 1 DAY
                  WHERE s.mannschaft_id NOT IN ($in)
               ORDER BY s.wochentag ASC, s.zeit_von ASC",
                $trainer_id
            ), ARRAY_A );

            // Springer-Slots zusammenführen
            $slot_ids_seen = array_column( $slots, 'id' );
            foreach ( $springer_slots as $ss ) {
                if ( ! in_array( $ss['id'], $slot_ids_seen, false ) ) {
                    $ss['ist_springer_slot'] = true;
                    $slots[] = $ss;
                }
            }
        } else {
            $mannschaften = LSV07I_DB::get_mannschaften();
            $slots        = LSV07I_DB::get_slots();
        }

        wp_send_json_success( [
            'mannschaften' => $mannschaften,
            'slots'        => $slots,
        ] );
    }

    public static function get_schwimmer() {
        LSV07I_Access::check( 'schwimmen_read' );
        $mannschaft_id = absint( $_POST['mannschaft_id'] ?? 0 );
        wp_send_json_success( LSV07I_DB::get_schwimmer( $mannschaft_id ) );
    }

    /**
     * Vollständiges Schwimmer-Profil: Stammdaten + Anwesenheit + Bestzeiten
     */
    public static function get_profil() {
        LSV07I_Access::check( 'schwimmen_read' );
        global $wpdb;
        $p          = $wpdb->prefix;
        $swimmer_id = absint( $_POST['swimmer_id'] ?? 0 );
        if ( ! $swimmer_id ) wp_send_json_error( [ 'message' => 'Fehlende ID.' ] );

        // ── Stammdaten ──────────────────────────────────────────────────────
        $swimmer = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, g.name AS mannschaft_name
               FROM {$p}mv_swimmers s
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = s.team_id
              WHERE s.id = %d LIMIT 1",
            $swimmer_id
        ), ARRAY_A );

        if ( ! $swimmer ) wp_send_json_error( [ 'message' => 'Schwimmer nicht gefunden.' ] );

        $swimmer['attest_status'] = LSV07I_DB::attest_status( $swimmer['attest_expires'] );
        $swimmer['kontakte'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_kontakte WHERE swimmer_id = %d ORDER BY sort_order ASC",
            $swimmer_id
        ), ARRAY_A );

        // ── Anwesenheitsstatistik ────────────────────────────────────────────
        // Eigene Mannschafts-Sessions als Nenner
        $team_id = (int) $swimmer['team_id'];
        $sessions_mann = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}lsv07i_anwesenheit
              WHERE mannschaft_id = %d AND ausgefallen = 0",
            $team_id
        ) );

        $anw_stats = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.status, COUNT(*) AS anzahl
               FROM {$p}lsv07i_anwesenheit_eintraege e
               JOIN {$p}lsv07i_anwesenheit a ON a.id = e.anwesenheit_id
              WHERE e.teilnehmer_id = %d AND e.teilnehmer_typ = 'schwimmer'
                AND a.ausgefallen = 0 AND a.mannschaft_id = %d
           GROUP BY e.status",
            $swimmer_id, $team_id
        ), ARRAY_A );

        $anwesend = 0; $abwesend = 0; $entschuldigt = 0;
        foreach ( $anw_stats as $row ) {
            if ( $row['status'] === 'anwesend' )     $anwesend     = (int) $row['anzahl'];
            if ( $row['status'] === 'abwesend' )     $abwesend     = (int) $row['anzahl'];
            if ( $row['status'] === 'entschuldigt' ) $entschuldigt = (int) $row['anzahl'];
        }

        // Letzte 5 Trainings
        $letzte_trainings = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.training_datum, a.ausgefallen, e.status
               FROM {$p}lsv07i_anwesenheit a
          LEFT JOIN {$p}lsv07i_anwesenheit_eintraege e
                 ON e.anwesenheit_id = a.id AND e.teilnehmer_id = %d AND e.teilnehmer_typ = 'schwimmer'
              WHERE a.mannschaft_id = %d AND a.training_datum <= CURDATE()
           ORDER BY a.training_datum DESC LIMIT 8",
            $swimmer_id, $team_id
        ), ARRAY_A );

        // ── Bestzeiten ───────────────────────────────────────────────────────
        // Bestzeiten: primär über swimmer_id, mit Fallback über den Namen.
        // Der Fallback fängt Altdaten ab, bei denen die swimmer_id nicht
        // (mehr) mit der mv_swimmers-ID übereinstimmt, der Name aber passt.
        $fn = trim( (string) $swimmer['first_name'] );
        $ln = trim( (string) $swimmer['last_name'] );
        if ( $fn !== '' && $ln !== '' ) {
            $bestzeiten = $wpdb->get_results( $wpdb->prepare(
                "SELECT strecke, zeit_raw, zeit_sek, importiert_am
                   FROM {$p}lsv07i_bestzeiten
                  WHERE swimmer_id = %d
                     OR swimmer_name = %s
                     OR swimmer_name = %s
               ORDER BY strecke ASC",
                $swimmer_id, $ln . ', ' . $fn, $fn . ' ' . $ln
            ), ARRAY_A );
        } else {
            $bestzeiten = $wpdb->get_results( $wpdb->prepare(
                "SELECT strecke, zeit_raw, zeit_sek, importiert_am
                   FROM {$p}lsv07i_bestzeiten
                  WHERE swimmer_id = %d
               ORDER BY strecke ASC",
                $swimmer_id
            ), ARRAY_A );
        }

        // Doppelte Strecken entfernen (falls id- und name-Match überlappen):
        // pro Strecke nur den ersten (besten) Eintrag behalten.
        $seen = [];
        $bestzeiten = array_values( array_filter( $bestzeiten, function( $b ) use ( &$seen ) {
            if ( isset( $seen[ $b['strecke'] ] ) ) return false;
            $seen[ $b['strecke'] ] = true;
            return true;
        } ) );

        wp_send_json_success( [
            'swimmer'         => $swimmer,
            'anwesenheit'     => [
                'sessions_mann' => $sessions_mann,
                'anwesend'      => $anwesend,
                'abwesend'      => $abwesend,
                'entschuldigt'  => $entschuldigt,
                'letzte'        => $letzte_trainings,
            ],
            'bestzeiten'      => $bestzeiten,
        ] );
    }

    /**
     * Trainer (seiner eigenen Mannschaft) darf Schwimmer-Daten ändern:
     * Attest, DSV-ID, Notizen, Kontaktpersonen, Datenschutz.
     * Admin/Schwimmwart dürfen auch ohne Mannschafts-Check ändern.
     */
    public static function update_schwimmer() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'Keine Schwimmer-ID.' ] );

        $swimmer = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}mv_swimmers WHERE id = %d AND active = 1 LIMIT 1", $id
        ), ARRAY_A );
        if ( ! $swimmer ) wp_send_json_error( [ 'message' => 'Schwimmer nicht gefunden.' ] );

        // Zugriffsprüfung: Admin/Schwimmwart dürfen alles, Trainer nur ihre Mannschaft
        $user = wp_get_current_user();
        $is_privileged = in_array( 'administrator', (array) $user->roles, true )
                      || in_array( LSV07I_ROLE_SCHWIMMWART, (array) $user->roles, true );

        if ( ! $is_privileged ) {
            $trainer_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07i_trainer WHERE wp_user_id = %d AND aktiv = 1 LIMIT 1",
                $user->ID
            ) );
            if ( ! $trainer_id ) wp_send_json_error( [ 'message' => 'Kein Trainer-Profil.' ] );

            // Mannschaften des Trainers
            $tm_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT mannschaft_id FROM {$p}lsv07i_trainer_mannschaft WHERE trainer_id = %d",
                $trainer_id
            ) );
            // Mannschaften des Schwimmers (M:N + team_id)
            $sw_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT gruppe_id FROM {$p}lsv07i_swimmer_gruppen WHERE swimmer_id = %d", $id
            ) );
            if ( $swimmer['team_id'] ) $sw_ids[] = (int) $swimmer['team_id'];

            $overlap = array_intersect( array_map( 'intval', $tm_ids ), array_map( 'intval', $sw_ids ) );
            if ( empty( $overlap ) ) {
                wp_send_json_error( [ 'message' => 'Du bist nicht Trainer dieser Mannschaft.' ] );
            }
        }

        // Erlaubte Felder aktualisieren (keine Namen/Geburt/Mannschaft)
        $attest      = sanitize_text_field( $_POST['attest_expires'] ?? '' );
        $dsv_id      = sanitize_text_field( $_POST['dsv_id']         ?? '' );
        $datenschutz = absint( $_POST['datenschutz'] ?? 0 );
        $notes       = sanitize_textarea_field( $_POST['notes']      ?? '' );

        $wpdb->update( $p . 'mv_swimmers', [
            'attest_expires' => $attest ?: null,
            'dsv_id'         => $dsv_id,
            'datenschutz'    => $datenschutz,
            'notes'          => $notes,
        ], [ 'id' => $id ], [ '%s', '%s', '%d', '%s' ], [ '%d' ] );

        // Kontaktpersonen (optional)
        if ( isset( $_POST['kontakte_json'] ) ) {
            $kontakte = json_decode( stripslashes( $_POST['kontakte_json'] ), true );
            if ( is_array( $kontakte ) ) {
                LSV07I_DB::save_kontakte( $id, $kontakte );
            }
        }

        wp_send_json_success( [ 'message' => 'Schwimmerdaten gespeichert.' ] );
    }

    /**
     * Trainer-Übersicht für ALLE mit Schwimmen-Zugang (nur 'intern' nötig,
     * keine Admin-/Schwimmwart-Rechte). Liefert bewusst nur die für die
     * Übersicht benötigten, unkritischen Felder – keine sensiblen Daten.
     */
    public static function get_trainer() {
        // Trainerübersicht ist rein lesend → 'schwimmen_read' lässt auch
        // Nutzer mit dem Mannschaft-Leserecht (ohne volle intern-Rolle) zu.
        LSV07I_Access::check( 'schwimmen_read' );
        $trainer = LSV07I_DB::get_all_trainer();
        $slim = array_map( function( $t ) {
            return [
                'id'            => $t['id'],
                'name'          => $t['name'] ?? '',
                'display_name'  => $t['display_name'] ?? ( $t['name'] ?? '' ),
                'telefon'       => $t['telefon'] ?? '',
                'email'         => $t['email'] ?? '',
                'email_privat'  => $t['email_privat'] ?? '',
                'mannschaft_ids'=> $t['mannschaft_ids'] ?? '',
            ];
        }, $trainer );
        wp_send_json_success( [
            'trainer'      => $slim,
            'mannschaften' => LSV07I_DB::get_mannschaften(),
        ] );
    }
}
